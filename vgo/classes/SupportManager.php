<?php
/**
 * SupportManager Class
 * Destek sistemi yönetimi ve otomatik atama
 */
class SupportManager {
    private $conn;
    private $tables = null;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    private function resolveTable(string $preferred): string {
        if ($this->tables !== null && isset($this->tables[$preferred])) {
            return $this->tables[$preferred];
        }
        $stmt = $this->conn->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) LIMIT 1");
        if (!$stmt) {
            return $preferred;
        }
        $stmt->bind_param('s', $preferred);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($this->tables === null) {
            $this->tables = [];
        }
        $this->tables[$preferred] = $row['TABLE_NAME'] ?? $preferred;
        return $this->tables[$preferred];
    }

    private function ident(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function hasColumn(string $table, string $column): bool {
        $stmt = $this->conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) AND LOWER(COLUMN_NAME) = LOWER(?) LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows > 0);
        $stmt->close();
        return $ok;
    }

    private function reopenTicketOnAssign(int $ticketId, int $operatorUserId): void {
        $tTickets = $this->resolveTable('SupportTickets');
        $tOperators = $this->resolveTable('operators');

        $sets = ["status = 'in_progress'"];
        if ($this->hasColumn($tTickets, 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }
        if ($this->hasColumn($tTickets, 'resolved_at')) {
            $sets[] = 'resolved_at = NULL';
        }
        if ($this->hasColumn($tTickets, 'closed_at')) {
            $sets[] = 'closed_at = NULL';
        }
        if ($this->hasColumn($tTickets, 'assigned_operator_id') && $this->hasColumn($tOperators, 'operator_id')) {
            $sets[] = 'assigned_operator_id = (SELECT operator_id FROM ' . $this->ident($tOperators) . ' WHERE user_id = ' . (int)$operatorUserId . ' LIMIT 1)';
        }

        $this->conn->query('UPDATE ' . $this->ident($tTickets) . ' SET ' . implode(', ', $sets) . ' WHERE ticket_id = ' . (int)$ticketId);
    }

    private function recomputeOperatorCurrentTickets(int $operatorUserId): void {
        $tAssignments = $this->resolveTable('TicketAssignments');
        $tTickets = $this->resolveTable('SupportTickets');
        $tStatus = $this->resolveTable('OperatorStatus');

        // count only active tickets
        $sql = "UPDATE {$this->ident($tStatus)} os
                SET os.current_tickets = (
                    SELECT COUNT(*)
                    FROM {$this->ident($tAssignments)} ta
                    JOIN {$this->ident($tTickets)} t ON t.ticket_id = ta.ticket_id
                    WHERE ta.operator_id = os.operator_id AND t.status NOT IN ('resolved','closed')
                )
                WHERE os.operator_id = ?";
        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $operatorUserId);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    /**
     * Otomatik ticket atama sistemi
     * En az yüklü ve müsait operatöre atar
     */
    public function autoAssignTicket($ticket_id) {
        $ticket_id = (int)$ticket_id;
        $tUsers = $this->resolveTable('users');
        $tStatus = $this->resolveTable('OperatorStatus');
        $tAssignments = $this->resolveTable('TicketAssignments');
        $tTickets = $this->resolveTable('SupportTickets');
        $tOperators = $this->resolveTable('operators');

        // Müsait operatörleri getir (en az ticket'ı olan)
        $stmt = $this->conn->prepare("SELECT u.user_id FROM {$this->ident($tUsers)} u JOIN {$this->ident($tStatus)} os ON u.user_id = os.operator_id WHERE u.role_id = 2 AND os.is_online = TRUE AND os.is_available = TRUE ORDER BY os.current_tickets ASC, os.last_activity DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        $operator = $result->fetch_assoc();
        $stmt->close();
        
        if (!$operator) {
            return false; // Müsait operatör yok
        }
        
        $operator_id = $operator['user_id'];
        
        // Atama yap
        $stmt = $this->conn->prepare("INSERT INTO {$this->ident($tAssignments)} (ticket_id, operator_id, assigned_by) VALUES (?, ?, NULL)");
        $stmt->bind_param("ii", $ticket_id, $operator_id);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            // Ensure it becomes active again and appears in operator inbox.
            $this->reopenTicketOnAssign((int)$ticket_id, (int)$operator_id);
            $this->recomputeOperatorCurrentTickets((int)$operator_id);
        }
        
        return $operator_id;
    }
    
    /**
     * Tüm ticket'ları getir (yönetici görünümü)
     */
    public function getAllTickets($filters = [], $limit = 100) {
        $where = [];
        $params = [];
        $types = "";
        
        if (isset($filters['status'])) {
            $where[] = "t.status = ?";
            $params[] = $filters['status'];
            $types .= "s";
        }
        
        if (isset($filters['priority'])) {
            $where[] = "t.priority = ?";
            $params[] = $filters['priority'];
            $types .= "s";
        }
        
        if (isset($filters['category_id'])) {
            $where[] = "t.category_id = ?";
            $params[] = $filters['category_id'];
            $types .= "i";
        }
        
        if (isset($filters['operator_id'])) {
            $where[] = "ta.operator_id = ?";
            $params[] = $filters['operator_id'];
            $types .= "i";
        }
        
        $where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";
        
        $sql = "SELECT t.*, c.category_name, u.full_name as user_name, u.email as user_email, 
                       op.full_name as operator_name, ta.assigned_at,
                       (SELECT COUNT(*) FROM SupportMessages WHERE ticket_id = t.ticket_id) as message_count
                FROM SupportTickets t 
                LEFT JOIN SupportCategories c ON t.category_id = c.category_id 
                LEFT JOIN Users u ON t.user_id = u.user_id
                LEFT JOIN TicketAssignments ta ON t.ticket_id = ta.ticket_id
                LEFT JOIN Users op ON ta.operator_id = op.user_id
                $where_sql
                ORDER BY t.priority DESC, t.updated_at DESC LIMIT ?";
        
        $stmt = $this->conn->prepare($sql);
        
        if (count($params) > 0) {
            $types .= "i";
            $params[] = $limit;
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param("i", $limit);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $tickets = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $tickets;
    }
    
    /**
     * Dashboard istatistikleri
     */
    public function getDashboardStats() {
        $stats = [];
        
        // Açık ticket'lar
        $result = $this->conn->query("SELECT COUNT(*) as count FROM SupportTickets WHERE status = 'open'");
        $stats['open_tickets'] = $result->fetch_assoc()['count'];
        
        // İşleniyor
        $result = $this->conn->query("SELECT COUNT(*) as count FROM SupportTickets WHERE status = 'in_progress'");
        $stats['in_progress'] = $result->fetch_assoc()['count'];
        
        // Müşteri cevabı bekleniyor
        $result = $this->conn->query("SELECT COUNT(*) as count FROM SupportTickets WHERE status = 'waiting_response'");
        $stats['waiting_response'] = $result->fetch_assoc()['count'];
        
        // Bugün çözülen
        $result = $this->conn->query("SELECT COUNT(*) as count FROM SupportTickets WHERE status IN ('resolved', 'closed') AND DATE(resolved_at) = CURDATE()");
        $stats['resolved_today'] = $result->fetch_assoc()['count'];
        
        // Toplam çözülen
        $result = $this->conn->query("SELECT COUNT(*) as count FROM SupportTickets WHERE status IN ('resolved', 'closed')");
        $stats['total_resolved'] = $result->fetch_assoc()['count'];
        
        // Online operatör sayısı
        $result = $this->conn->query("SELECT COUNT(*) as count FROM OperatorStatus WHERE is_online = TRUE");
        $stats['online_operators'] = $result->fetch_assoc()['count'];
        
        // Ortalama cevap süresi (dakika)
        $result = $this->conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, (SELECT MIN(created_at) FROM SupportMessages WHERE ticket_id = t.ticket_id AND is_operator = TRUE))) as avg_response FROM SupportTickets t WHERE EXISTS (SELECT 1 FROM SupportMessages WHERE ticket_id = t.ticket_id AND is_operator = TRUE)");
        $row = $result->fetch_assoc();
        $stats['avg_response_time'] = $row['avg_response'] ? round($row['avg_response']) : 0;
        
        // Ortalama çözüm süresi (saat)
        $result = $this->conn->query("SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_resolution FROM SupportTickets WHERE status IN ('resolved', 'closed') AND resolved_at IS NOT NULL");
        $row = $result->fetch_assoc();
        $stats['avg_resolution_time'] = $row['avg_resolution'] ? round($row['avg_resolution'], 1) : 0;
        
        return $stats;
    }
    
    /**
     * Kategoriye göre ticket dağılımı
     */
    public function getTicketsByCategory($days = 30) {
        $stmt = $this->conn->prepare("SELECT c.category_name, COUNT(t.ticket_id) as count FROM SupportTickets t LEFT JOIN SupportCategories c ON t.category_id = c.category_id WHERE t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY t.category_id ORDER BY count DESC");
        $stmt->bind_param("i", $days);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $data;
    }
    
    /**
     * Operatör performans raporu
     */
    public function getOperatorPerformance($days = 30) {
        $stmt = $this->conn->prepare("
            SELECT u.full_name, u.user_id,
                   COUNT(DISTINCT ta.ticket_id) as total_tickets,
                   SUM(CASE WHEN t.status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as resolved,
                   AVG(TIMESTAMPDIFF(MINUTE, t.created_at, (SELECT MIN(created_at) FROM SupportMessages WHERE ticket_id = t.ticket_id AND is_operator = TRUE AND sender_id = u.user_id))) as avg_response_time,
                   AVG(CASE WHEN t.status IN ('resolved', 'closed') THEN TIMESTAMPDIFF(HOUR, t.created_at, t.resolved_at) END) as avg_resolution_time
            FROM Users u
            JOIN TicketAssignments ta ON u.user_id = ta.operator_id
            JOIN SupportTickets t ON ta.ticket_id = t.ticket_id
            WHERE u.role_id = 2 AND ta.assigned_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY u.user_id
            ORDER BY resolved DESC
        ");
        $stmt->bind_param("i", $days);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $data;
    }
    
    /**
     * Yeniden atama (transfer)
     */
    public function reassignTicket($ticket_id, $new_operator_id, $assigned_by) {
        $ticket_id = (int)$ticket_id;
        $new_operator_id = (int)$new_operator_id;
        $assigned_by = (int)$assigned_by;

        $tAssignments = $this->resolveTable('TicketAssignments');
        $tStatus = $this->resolveTable('OperatorStatus');
        $tTickets = $this->resolveTable('SupportTickets');

        // Eski atamayi sil
        $old = $this->conn->query("SELECT operator_id FROM {$this->ident($tAssignments)} WHERE ticket_id = " . (int)$ticket_id);
        if ($old && $old->num_rows > 0) {
            $old_op = $old->fetch_assoc();
            $oldOpId = (int)($old_op['operator_id'] ?? 0);
            $this->conn->query("DELETE FROM {$this->ident($tAssignments)} WHERE ticket_id = " . (int)$ticket_id);
            if ($oldOpId > 0) {
                $this->recomputeOperatorCurrentTickets($oldOpId);
            }
        }
        
        // Yeni atama
        $stmt = $this->conn->prepare("INSERT INTO {$this->ident($tAssignments)} (ticket_id, operator_id, assigned_by) VALUES (?, ?, ?)");
        $stmt->bind_param("iii", $ticket_id, $new_operator_id, $assigned_by);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            // If a resolved/closed ticket is reassigned, it must become active again so it shows up for the operator.
            $this->reopenTicketOnAssign((int)$ticket_id, (int)$new_operator_id);
            $this->recomputeOperatorCurrentTickets($new_operator_id);
        }
        
        return $result;
    }
}
?>
