<?php
/**
 * SupportOperator Class
 * Operatör destek yönetimi
 */
class SupportOperator {
    private $conn;
    private $schema = null;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    private function hasTable(string $tableName): bool {
        $stmt = $this->conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $stmt->bind_param('s', $tableName);
        $stmt->execute();
        $res = $stmt->get_result();
        $has = ($res && $res->num_rows > 0);
        $stmt->close();
        return $has;
    }

    private function hasColumn(string $tableName, string $columnName): bool {
        $stmt = $this->conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->bind_param('ss', $tableName, $columnName);
        $stmt->execute();
        $res = $stmt->get_result();
        $has = ($res && $res->num_rows > 0);
        $stmt->close();
        return $has;
    }

    private function usersTable(): string {
        if ($this->hasTable('users')) return 'users';
        if ($this->hasTable('Users')) return 'Users';
        return 'users';
    }

    private function detectSchema(): array {
        if ($this->schema !== null) {
            return $this->schema;
        }
        $schema = [
            'has_ticket_assignments' => $this->hasTable('TicketAssignments') && $this->hasColumn('TicketAssignments', 'ticket_id'),
            'tickets_has_assigned_to' => $this->hasColumn('SupportTickets', 'assigned_to'),
            'tickets_has_updated_at' => $this->hasColumn('SupportTickets', 'updated_at'),
            'tickets_has_closed_at' => $this->hasColumn('SupportTickets', 'closed_at'),
            'tickets_has_resolved_at' => $this->hasColumn('SupportTickets', 'resolved_at'),
            'msgs_has_message' => $this->hasColumn('SupportMessages', 'message'),
            'msgs_has_message_text' => $this->hasColumn('SupportMessages', 'message_text'),
            'msgs_has_is_operator' => $this->hasColumn('SupportMessages', 'is_operator'),
            'msgs_has_is_read' => $this->hasColumn('SupportMessages', 'is_read'),
        ];
        $this->schema = $schema;
        return $schema;
    }
    
    /**
     * Operatörün durumunu güncelle (online/offline, available/busy)
     */
    public function updateStatus($operator_id, $is_online, $is_available) {
        // Önce mevcut kaydı kontrol et
        $check = $this->conn->prepare("SELECT operator_id FROM OperatorStatus WHERE operator_id = ?");
        $check->bind_param("i", $operator_id);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        
        if ($exists) {
            // Kayıt varsa UPDATE
            $stmt = $this->conn->prepare("UPDATE OperatorStatus SET is_online = ?, is_available = ?, last_activity = NOW() WHERE operator_id = ?");
            $stmt->bind_param("iii", $is_online, $is_available, $operator_id);
        } else {
            // Kayıt yoksa INSERT
            $stmt = $this->conn->prepare("INSERT INTO OperatorStatus (operator_id, is_online, is_available, last_activity) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iii", $operator_id, $is_online, $is_available);
        }
        
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
    
    /**
     * Ticket'ı operatöre ata
     */
    public function assignTicket($ticket_id, $operator_id, $assigned_by = null) {
        $s = $this->detectSchema();

        if ($s['has_ticket_assignments']) {
            $check = $this->conn->query("SELECT 1 FROM TicketAssignments WHERE ticket_id = " . (int)$ticket_id . " LIMIT 1");
            if ($check && $check->num_rows > 0) {
                return false;
            }

            $stmt = $this->conn->prepare("INSERT INTO TicketAssignments (ticket_id, operator_id, assigned_by) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $ticket_id, $operator_id, $assigned_by);
            $result = $stmt->execute();
            $stmt->close();
        } elseif ($s['tickets_has_assigned_to']) {
            // Old schema fallback
            $stmt = $this->conn->prepare("UPDATE SupportTickets SET assigned_to = ?, status = 'in_progress'" . ($s['tickets_has_updated_at'] ? ", updated_at = NOW()" : "") . " WHERE ticket_id = ? AND (assigned_to IS NULL OR assigned_to = 0)");
            $stmt->bind_param('ii', $operator_id, $ticket_id);
            $stmt->execute();
            $result = $stmt->affected_rows > 0;
            $stmt->close();
        } else {
            return false;
        }

        if ($result) {
            if ($s['tickets_has_updated_at']) {
                $this->conn->query("UPDATE SupportTickets SET updated_at = NOW() WHERE ticket_id = " . (int)$ticket_id);
            }
            // Operatörün ticket sayısını artır
            $this->conn->query("UPDATE OperatorStatus SET current_tickets = current_tickets + 1 WHERE operator_id = " . (int)$operator_id);
        }
        return $result;
    }
    
    /**
     * Operatöre atanmış ticket'ları getir
     */
    public function getAssignedTickets($operator_id, $statusOrFilters = null, $limit = 50) {
        $s = $this->detectSchema();
        $usersTable = $this->usersTable();
        $filters = [];
        if (is_array($statusOrFilters)) {
            $filters = $statusOrFilters;
        } elseif (is_string($statusOrFilters) && $statusOrFilters !== '') {
            $filters['status'] = $statusOrFilters;
        }

        $orderCol = $s['tickets_has_updated_at'] ? 't.updated_at' : 't.created_at';
        $where = [];
        $params = [];
        $types = '';

        $hasStatusFilter = (isset($filters['status']) && $filters['status'] !== '');
        if ($hasStatusFilter) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
            $types .= 's';
        } else {
            // Default list should show active tickets only.
            $where[] = "t.status NOT IN ('resolved','closed')";
        }
        if (isset($filters['priority']) && $filters['priority'] !== '') {
            $where[] = 't.priority = ?';
            $params[] = $filters['priority'];
            $types .= 's';
        }
        if (isset($filters['role_id']) && (int)$filters['role_id'] > 0) {
            $where[] = 'u.role_id = ?';
            $params[] = (int)$filters['role_id'];
            $types .= 'i';
        }
        if (isset($filters['q']) && trim((string)$filters['q']) !== '') {
            $where[] = '(t.subject LIKE ? OR u.full_name LIKE ?)';
            $q = '%' . trim((string)$filters['q']) . '%';
            $params[] = $q;
            $params[] = $q;
            $types .= 'ss';
        }

        if ($s['has_ticket_assignments']) {
            $sql = "SELECT t.*, u.full_name as user_name, u.email as user_email, u.role_id as user_role_id, ta.assigned_at,
                        (SELECT COUNT(*) FROM SupportMessages m WHERE m.ticket_id = t.ticket_id";
            if ($s['msgs_has_is_read']) {
                $sql .= " AND m.is_read = FALSE";
            }
            if ($s['msgs_has_is_operator']) {
                $sql .= " AND m.is_operator = FALSE";
            } else {
                $sql .= " AND m.sender_id <> ?";
            }
            $sql .= ") as unread_count
                    FROM SupportTickets t
                    JOIN TicketAssignments ta ON t.ticket_id = ta.ticket_id
                    LEFT JOIN `{$usersTable}` u ON t.user_id = u.user_id
                    WHERE ta.operator_id = ?";

            $params2 = [];
            $types2 = '';
            if (!$s['msgs_has_is_operator']) {
                $params2[] = (int)$operator_id;
                $types2 .= 'i';
            }
            $params2[] = (int)$operator_id;
            $types2 .= 'i';

            if (!empty($where)) {
                $sql .= ' AND ' . implode(' AND ', $where);
            }
            $sql .= " ORDER BY {$orderCol} DESC LIMIT ?";
            $params2 = array_merge($params2, $params);
            $types2 .= $types;
            $params2[] = (int)$limit;
            $types2 .= 'i';

            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types2, ...$params2);
        } elseif ($s['tickets_has_assigned_to']) {
            $sql = "SELECT t.*, u.full_name as user_name, u.email as user_email, u.role_id as user_role_id,
                        0 as unread_count
                    FROM SupportTickets t
                    LEFT JOIN `{$usersTable}` u ON t.user_id = u.user_id
                    WHERE t.assigned_to = ?";
            $params2 = [(int)$operator_id];
            $types2 = 'i';
            if (!empty($where)) {
                $sql .= ' AND ' . implode(' AND ', $where);
            }
            $sql .= " ORDER BY {$orderCol} DESC LIMIT ?";
            $params2 = array_merge($params2, $params);
            $types2 .= $types;
            $params2[] = (int)$limit;
            $types2 .= 'i';
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types2, ...$params2);
        } else {
            return [];
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $tickets = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $tickets;
    }
    
    /**
     * Atanmamış (bekleyen) ticket'ları getir
     */
    public function getUnassignedTickets($filters = null, $limit = 30) {
        $s = $this->detectSchema();
        $usersTable = $this->usersTable();
        $f = is_array($filters) ? $filters : [];
        $where = ["t.status = 'open'"];
        $params = [];
        $types = '';

        if (isset($f['priority']) && $f['priority'] !== '') {
            $where[] = 't.priority = ?';
            $params[] = $f['priority'];
            $types .= 's';
        }
        if (isset($f['role_id']) && (int)$f['role_id'] > 0) {
            $where[] = 'u.role_id = ?';
            $params[] = (int)$f['role_id'];
            $types .= 'i';
        }
        if (isset($f['q']) && trim((string)$f['q']) !== '') {
            $where[] = '(t.subject LIKE ? OR u.full_name LIKE ?)';
            $q = '%' . trim((string)$f['q']) . '%';
            $params[] = $q;
            $params[] = $q;
            $types .= 'ss';
        }

        if ($s['has_ticket_assignments']) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM TicketAssignments WHERE ticket_id = t.ticket_id)';
        } elseif ($s['tickets_has_assigned_to']) {
            $where[] = '(t.assigned_to IS NULL OR t.assigned_to = 0)';
        }

        $sql = "SELECT t.*, u.full_name as user_name, u.email as user_email, u.role_id as user_role_id
                FROM SupportTickets t
            LEFT JOIN `{$usersTable}` u ON t.user_id = u.user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY t.priority DESC, t.created_at ASC
                LIMIT ?";

        $params[] = (int)$limit;
        $types .= 'i';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $tickets = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $tickets;
    }
    
    /**
     * Ticket'a operatör olarak cevap gönder
     */
    public function sendMessage($ticket_id, $operator_id, $message) {
        $s = $this->detectSchema();

        // Block sending to finalized tickets
        $st = $this->conn->prepare('SELECT status FROM SupportTickets WHERE ticket_id = ? LIMIT 1');
        if ($st) {
            $tid = (int)$ticket_id;
            $st->bind_param('i', $tid);
            $st->execute();
            $row = $st->get_result()->fetch_assoc() ?? [];
            $st->close();
            $status = (string)($row['status'] ?? '');
            if ($status === 'closed' || $status === 'resolved') {
                return false;
            }
        }

        $msgCol = $s['msgs_has_message'] ? 'message' : ($s['msgs_has_message_text'] ? 'message_text' : null);
        if ($msgCol === null) {
            return false;
        }

        if ($s['msgs_has_is_operator']) {
            $stmt = $this->conn->prepare("INSERT INTO SupportMessages (ticket_id, sender_id, {$msgCol}, is_operator) VALUES (?, ?, ?, TRUE)");
            $stmt->bind_param("iis", $ticket_id, $operator_id, $message);
        } else {
            $stmt = $this->conn->prepare("INSERT INTO SupportMessages (ticket_id, sender_id, {$msgCol}) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $ticket_id, $operator_id, $message);
        }
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            // Ticket durumunu waiting_response yap
            $set = "status = 'waiting_response'";
            if ($s['tickets_has_updated_at']) {
                $set .= ', updated_at = NOW()';
            }
            $this->conn->query("UPDATE SupportTickets SET {$set} WHERE ticket_id = " . (int)$ticket_id);
        }
        
        return $result;
    }

    public function setTicketStatus($ticket_id, $operator_id, $status) {
        $s = $this->detectSchema();
        if (!$this->isTicketAssignedToOperator($ticket_id, $operator_id)) {
            return false;
        }
        $sets = ["status = ?"]; 
        if ($s['tickets_has_updated_at']) {
            $sets[] = 'updated_at = NOW()';
        }
        if ($status === 'resolved' || $status === 'closed') {
            if ($s['tickets_has_resolved_at']) {
                $sets[] = 'resolved_at = NOW()';
            } elseif ($s['tickets_has_closed_at']) {
                $sets[] = 'closed_at = NOW()';
            }
        }
        $stmt = $this->conn->prepare('UPDATE SupportTickets SET ' . implode(', ', $sets) . ' WHERE ticket_id = ?');
        $stmt->bind_param('si', $status, $ticket_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function setTicketPriority($ticket_id, $operator_id, $priority) {
        $s = $this->detectSchema();
        if (!$this->hasColumn('SupportTickets', 'priority')) {
            return false;
        }
        if (!$this->isTicketAssignedToOperator($ticket_id, $operator_id)) {
            return false;
        }
        $sql = 'UPDATE SupportTickets SET priority = ?';
        if ($s['tickets_has_updated_at']) {
            $sql .= ', updated_at = NOW()';
        }
        $sql .= ' WHERE ticket_id = ?';
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('si', $priority, $ticket_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    private function isTicketAssignedToOperator($ticket_id, $operator_id): bool {
        $s = $this->detectSchema();
        if ($s['has_ticket_assignments']) {
            $stmt = $this->conn->prepare('SELECT 1 FROM TicketAssignments WHERE ticket_id = ? AND operator_id = ? LIMIT 1');
            $stmt->bind_param('ii', $ticket_id, $operator_id);
        } elseif ($s['tickets_has_assigned_to']) {
            $stmt = $this->conn->prepare('SELECT 1 FROM SupportTickets WHERE ticket_id = ? AND assigned_to = ? LIMIT 1');
            $stmt->bind_param('ii', $ticket_id, $operator_id);
        } else {
            return false;
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows > 0);
        $stmt->close();
        return $ok;
    }
    
    /**
     * Müsait operatörleri getir
     */
    public function getAvailableOperators() {
        $usersTable = $this->usersTable();
        $stmt = $this->conn->prepare("SELECT u.user_id, u.full_name, u.email, os.current_tickets, os.last_activity FROM `{$usersTable}` u JOIN OperatorStatus os ON u.user_id = os.operator_id WHERE u.role_id = 2 AND os.is_online = TRUE AND os.is_available = TRUE ORDER BY os.current_tickets ASC, os.last_activity DESC");
        if (!$stmt) {
            return [];
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $operators = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $operators;
    }
    
    /**
     * Operatör istatistikleri
     */
    public function getOperatorStats($operator_id) {
        $s = $this->detectSchema();
        $stats = [];

        // Toplam atanmış ticket
        if ($s['has_ticket_assignments']) {
            $result = $this->conn->query("SELECT COUNT(*) as total FROM TicketAssignments WHERE operator_id = " . (int)$operator_id);
            $stats['total_assigned'] = (int)($result ? ($result->fetch_assoc()['total'] ?? 0) : 0);

            // Çözülmüş ticket
            $result = $this->conn->query("SELECT COUNT(*) as total FROM SupportTickets t JOIN TicketAssignments ta ON t.ticket_id = ta.ticket_id WHERE ta.operator_id = " . (int)$operator_id . " AND t.status IN ('resolved', 'closed')");
            $stats['resolved'] = (int)($result ? ($result->fetch_assoc()['total'] ?? 0) : 0);

            // Ortalama cevap süresi (dakika)
            if ($s['msgs_has_is_operator']) {
                $result = $this->conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, (SELECT MIN(created_at) FROM SupportMessages WHERE ticket_id = t.ticket_id AND is_operator = TRUE AND sender_id = " . (int)$operator_id . "))) as avg_response FROM SupportTickets t JOIN TicketAssignments ta ON t.ticket_id = ta.ticket_id WHERE ta.operator_id = " . (int)$operator_id);
            } else {
                // Fallback: infer operator messages by sender_id = operator_id
                $result = $this->conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, (SELECT MIN(created_at) FROM SupportMessages WHERE ticket_id = t.ticket_id AND sender_id = " . (int)$operator_id . "))) as avg_response FROM SupportTickets t JOIN TicketAssignments ta ON t.ticket_id = ta.ticket_id WHERE ta.operator_id = " . (int)$operator_id);
            }
            $row = $result ? $result->fetch_assoc() : null;
            $stats['avg_response_time'] = ($row && $row['avg_response']) ? (int)round($row['avg_response']) : 0;
        } elseif ($s['tickets_has_assigned_to']) {
            $result = $this->conn->query("SELECT COUNT(*) as total FROM SupportTickets WHERE assigned_to = " . (int)$operator_id);
            $stats['total_assigned'] = (int)($result ? ($result->fetch_assoc()['total'] ?? 0) : 0);

            $result = $this->conn->query("SELECT COUNT(*) as total FROM SupportTickets WHERE assigned_to = " . (int)$operator_id . " AND status IN ('resolved', 'closed')");
            $stats['resolved'] = (int)($result ? ($result->fetch_assoc()['total'] ?? 0) : 0);

            if ($s['msgs_has_is_operator']) {
                $result = $this->conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, (SELECT MIN(created_at) FROM SupportMessages WHERE ticket_id = t.ticket_id AND is_operator = TRUE AND sender_id = " . (int)$operator_id . "))) as avg_response FROM SupportTickets t WHERE t.assigned_to = " . (int)$operator_id);
            } else {
                $result = $this->conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, (SELECT MIN(created_at) FROM SupportMessages WHERE ticket_id = t.ticket_id AND sender_id = " . (int)$operator_id . "))) as avg_response FROM SupportTickets t WHERE t.assigned_to = " . (int)$operator_id);
            }
            $row = $result ? $result->fetch_assoc() : null;
            $stats['avg_response_time'] = ($row && $row['avg_response']) ? (int)round($row['avg_response']) : 0;
        } else {
            $stats['total_assigned'] = 0;
            $stats['resolved'] = 0;
            $stats['avg_response_time'] = 0;
        }

        // Aktif ticket: status'a göre canlı hesapla (OperatorStatus.current_tickets stale kalabiliyor)
        if ($s['has_ticket_assignments']) {
            $result = $this->conn->query("SELECT COUNT(*) as total FROM SupportTickets t JOIN TicketAssignments ta ON t.ticket_id = ta.ticket_id WHERE ta.operator_id = " . (int)$operator_id . " AND t.status NOT IN ('resolved','closed')");
            $stats['active'] = (int)($result ? ($result->fetch_assoc()['total'] ?? 0) : 0);
        } elseif ($s['tickets_has_assigned_to']) {
            $result = $this->conn->query("SELECT COUNT(*) as total FROM SupportTickets WHERE assigned_to = " . (int)$operator_id . " AND status NOT IN ('resolved','closed')");
            $stats['active'] = (int)($result ? ($result->fetch_assoc()['total'] ?? 0) : 0);
        } else {
            $result = $this->conn->query("SELECT current_tickets FROM OperatorStatus WHERE operator_id = " . (int)$operator_id);
            $row = $result ? $result->fetch_assoc() : null;
            $stats['active'] = (int)($row ? ($row['current_tickets'] ?? 0) : 0);
        }
        
        return $stats;
    }
    
    /**
     * Ticket'ı kapat
     */
    public function closeTicket($ticket_id, $operator_id) {
        $s = $this->detectSchema();
        if (!$this->isTicketAssignedToOperator($ticket_id, $operator_id)) {
            return false;
        }

        $sets = ["status = 'resolved'"];
        if ($s['tickets_has_resolved_at']) {
            $sets[] = 'resolved_at = NOW()';
        } elseif ($s['tickets_has_closed_at']) {
            $sets[] = 'closed_at = NOW()';
        }
        if ($s['tickets_has_updated_at']) {
            $sets[] = 'updated_at = NOW()';
        }
        $result = $this->conn->query("UPDATE SupportTickets SET " . implode(', ', $sets) . " WHERE ticket_id = " . (int)$ticket_id);
        
        if ($result) {
            // Operatörün aktif ticket sayısını azalt
            $this->conn->query("UPDATE OperatorStatus SET current_tickets = GREATEST(0, current_tickets - 1) WHERE operator_id = $operator_id");
        }
        
        return $result;
    }
}
?>
