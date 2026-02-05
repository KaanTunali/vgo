<?php
/**
 * SupportTicket Class
 * Müşteri destek talebi yönetimi
 */
class SupportTicket {
    private $conn;
    private $schema = null;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
        // Türkçe karakterler için charset ayarla
        $this->conn->set_charset("utf8mb4");
    }

    /**
     * Ensure app-required default categories exist.
     * This prevents ticket creation failing when SupportCategories is empty.
     */
    public function ensureDefaultCategories(): void {
        $s = $this->detectSchema();
        if (empty($s['has_categories_table'])) {
            return;
        }

        // If there is at least one non-blank category, don't touch anything.
        try {
            $res = $this->conn->query("SELECT COUNT(*) AS c FROM SupportCategories WHERE category_name IS NOT NULL AND category_name <> ''");
            $row = $res ? ($res->fetch_assoc() ?? []) : [];
            $count = (int)($row['c'] ?? 0);
            if ($count > 0) {
                return;
            }
        } catch (Throwable $e) {
            return;
        }

        $defaults = [
            ['Delivery', 'Delivery / courier / address issues'],
            ['Payment', 'Payment / settlement / earnings issues'],
            ['Account', 'Login / profile / authorization issues'],
            ['Campaign', 'Coupon / campaign issues'],
            ['Quality', 'Product / menu / quality issues'],
            ['Technical', 'System / application technical issues'],
            ['General', 'General support requests'],
        ];

        $check = $this->conn->prepare('SELECT category_id FROM SupportCategories WHERE category_name = ? LIMIT 1');
        $ins = $this->conn->prepare('INSERT INTO SupportCategories (category_name, description, is_active) VALUES (?, ?, 1)');
        if (!$check || !$ins) {
            if ($check) $check->close();
            if ($ins) $ins->close();
            return;
        }

        foreach ($defaults as $d) {
            $name = (string)$d[0];
            $desc = (string)$d[1];

            $check->bind_param('s', $name);
            $check->execute();
            $r = $check->get_result();
            $exists = ($r && $r->num_rows > 0);
            if ($exists) {
                continue;
            }

            $ins->bind_param('ss', $name, $desc);
            $ins->execute();
        }

        $check->close();
        $ins->close();
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

    private function isPrivilegedRole(int $roleId): bool {
        // Admin(1), Operator(2), Manager(6) can see all tickets.
        return in_array($roleId, [1, 2, 6], true);
    }

    private function buildViewerAccessWhere(int $viewerUserId, int $viewerRoleId, string $ticketAlias, array &$params, string &$types): string {
        $s = $this->detectSchema();
        $params = [];
        $types = '';

        if ($this->isPrivilegedRole($viewerRoleId)) {
            return '1=1';
        }

        // Always allow the ticket owner.
        $conds = ["{$ticketAlias}.user_id = ?"];
        $params[] = $viewerUserId;
        $types .= 'i';

        // If the schema supports linking to orders/deliveries, allow all parties of that order.
        $canOrder = !empty($s['tickets_has_order_id']);
        $canDelivery = !empty($s['tickets_has_delivery_id']);

        if ($canOrder) {
            // Customer is linked via Orders -> Customers(user_id)
            $conds[] = "EXISTS (SELECT 1 FROM Orders o JOIN Customers c ON o.customer_id = c.customer_id WHERE o.order_id = {$ticketAlias}.order_id AND c.user_id = ?)";
            $params[] = $viewerUserId;
            $types .= 'i';

            // Merchant is linked via Orders -> Merchants(user_id)
            $conds[] = "EXISTS (SELECT 1 FROM Orders o JOIN Merchants m ON o.merchant_id = m.merchant_id WHERE o.order_id = {$ticketAlias}.order_id AND m.user_id = ?)";
            $params[] = $viewerUserId;
            $types .= 'i';

            // Courier is linked via Deliveries(order_id) -> Couriers(user_id)
            $conds[] = "EXISTS (SELECT 1 FROM Deliveries d JOIN Couriers cr ON d.courier_id = cr.courier_id WHERE d.order_id = {$ticketAlias}.order_id AND cr.user_id = ?)";
            $params[] = $viewerUserId;
            $types .= 'i';
        }

        if ($canDelivery) {
            // Customer via Deliveries -> Orders -> Customers
            $conds[] = "EXISTS (SELECT 1 FROM Deliveries d JOIN Orders o ON d.order_id = o.order_id JOIN Customers c ON o.customer_id = c.customer_id WHERE d.delivery_id = {$ticketAlias}.delivery_id AND c.user_id = ?)";
            $params[] = $viewerUserId;
            $types .= 'i';

            // Merchant via Deliveries -> Orders -> Merchants
            $conds[] = "EXISTS (SELECT 1 FROM Deliveries d JOIN Orders o ON d.order_id = o.order_id JOIN Merchants m ON o.merchant_id = m.merchant_id WHERE d.delivery_id = {$ticketAlias}.delivery_id AND m.user_id = ?)";
            $params[] = $viewerUserId;
            $types .= 'i';

            // Courier via Deliveries -> Couriers
            $conds[] = "EXISTS (SELECT 1 FROM Deliveries d JOIN Couriers cr ON d.courier_id = cr.courier_id WHERE d.delivery_id = {$ticketAlias}.delivery_id AND cr.user_id = ?)";
            $params[] = $viewerUserId;
            $types .= 'i';
        }

        return '(' . implode(' OR ', $conds) . ')';
    }

    private function detectSchema(): array {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $tickets = 'SupportTickets';
        $msgs = 'SupportMessages';

        $schema = [
            'tickets_has_category_id' => $this->hasColumn($tickets, 'category_id'),
            'tickets_has_category' => $this->hasColumn($tickets, 'category'),
            'tickets_has_description' => $this->hasColumn($tickets, 'description'),
            'tickets_has_order_id' => $this->hasColumn($tickets, 'order_id'),
            'tickets_has_delivery_id' => $this->hasColumn($tickets, 'delivery_id'),
            'tickets_has_updated_at' => $this->hasColumn($tickets, 'updated_at'),
            'tickets_has_resolved_at' => $this->hasColumn($tickets, 'resolved_at'),
            'tickets_has_closed_at' => $this->hasColumn($tickets, 'closed_at'),
            'tickets_has_assigned_to' => $this->hasColumn($tickets, 'assigned_to'),
            'tickets_has_status' => $this->hasColumn($tickets, 'status'),

            'msgs_has_message' => $this->hasColumn($msgs, 'message'),
            'msgs_has_message_text' => $this->hasColumn($msgs, 'message_text'),
            'msgs_has_is_operator' => $this->hasColumn($msgs, 'is_operator'),
            'msgs_has_is_read' => $this->hasColumn($msgs, 'is_read'),

            'has_categories_table' => $this->hasTable('SupportCategories') && $this->hasColumn('SupportCategories', 'category_id'),
        ];

        $this->schema = $schema;
        return $schema;
    }
    
    /**
     * Yeni destek talebi oluştur
     */
    public function createTicket($user_id, $category_id, $subject, $description, $order_id = null, $priority = 'medium') {
        $s = $this->detectSchema();

        // Newer schema: category_id + optional description/order_id
        if ($s['tickets_has_category_id']) {
            $cols = ['user_id', 'category_id', 'subject'];
            $ph = '?,?,?';
            $types = 'iis';
            $params = [$user_id, (int)$category_id, $subject];

            if ($s['tickets_has_description']) {
                $cols[] = 'description';
                $ph .= ',?';
                $types .= 's';
                $params[] = $description;
            }
            if ($order_id !== null && $order_id !== 0 && $s['tickets_has_order_id']) {
                $cols[] = 'order_id';
                $ph .= ',?';
                $types .= 'i';
                $params[] = (int)$order_id;
            }
            $cols[] = 'priority';
            $ph .= ',?';
            $types .= 's';
            $params[] = $priority;
            $cols[] = 'status';
            $ph .= ',\'open\'';

            $sql = 'INSERT INTO SupportTickets (' . implode(',', $cols) . ') VALUES (' . $ph . ')';
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $ok = $stmt->execute();
            $ticket_id = (int)$stmt->insert_id;
            $stmt->close();

            if ($ok) {
                // İlk mesajı ekle (description zaten ayrı kolonda olsa bile chat için de ekliyoruz)
                $this->addMessage($ticket_id, $user_id, $description, false);
                return $ticket_id;
            }
            return false;
        }

        // Older schema: category (varchar) + no description/order_id
        $category = is_string($category_id) ? $category_id : 'General';
        $stmt = $this->conn->prepare("INSERT INTO SupportTickets (user_id, category, subject, status, priority, created_at) VALUES (?, ?, ?, 'open', ?, NOW())");
        $stmt->bind_param('isss', $user_id, $category, $subject, $priority);
        $ok = $stmt->execute();
        $ticket_id = (int)$stmt->insert_id;
        $stmt->close();

        if ($ok) {
            $this->addMessage($ticket_id, $user_id, $description, false);
            return $ticket_id;
        }
        return false;
    }
    
    /**
     * Ticket'a mesaj ekle
     */
    public function addMessage($ticket_id, $sender_id, $message, $is_operator = false) {
        $s = $this->detectSchema();

        // If ticket is finalized, prevent any further chat messages.
        if (!empty($s['tickets_has_status'])) {
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
        }

        $cols = ['ticket_id', 'sender_id'];
        $ph = '?,?';
        $types = 'ii';
        $params = [(int)$ticket_id, (int)$sender_id];

        if ($s['msgs_has_message']) {
            $cols[] = 'message';
        } elseif ($s['msgs_has_message_text']) {
            $cols[] = 'message_text';
        } else {
            // Unknown schema
            return false;
        }
        $ph .= ',?';
        $types .= 's';
        $params[] = $message;

        if ($s['msgs_has_is_operator']) {
            $cols[] = 'is_operator';
            $ph .= ',?';
            $types .= 'i';
            $params[] = $is_operator ? 1 : 0;
        }

        $stmt = $this->conn->prepare('INSERT INTO SupportMessages (' . implode(',', $cols) . ') VALUES (' . $ph . ')');
        $stmt->bind_param($types, ...$params);
        $result = $stmt->execute();
        $stmt->close();

        // Ticket'ı güncelle
        if ($result) {
            if ($s['tickets_has_updated_at']) {
                $this->conn->query("UPDATE SupportTickets SET updated_at = NOW() WHERE ticket_id = " . (int)$ticket_id);
            }
        }

        return $result;
    }
    
    /**
     * Kullanıcının ticket'larını getir
     */
    public function getUserTickets($user_id, $status = null, $limit = 20) {
        $s = $this->detectSchema();

        $orderCol = $s['tickets_has_updated_at'] ? 't.updated_at' : 't.created_at';

        if ($s['tickets_has_category_id'] && $s['has_categories_table']) {
            if ($status) {
                $stmt = $this->conn->prepare("SELECT t.*, c.category_name, (SELECT COUNT(*) FROM SupportMessages WHERE ticket_id = t.ticket_id) as message_count FROM SupportTickets t LEFT JOIN SupportCategories c ON t.category_id = c.category_id WHERE t.user_id = ? AND t.status = ? ORDER BY {$orderCol} DESC LIMIT ?");
                $stmt->bind_param("isi", $user_id, $status, $limit);
            } else {
                $stmt = $this->conn->prepare("SELECT t.*, c.category_name, (SELECT COUNT(*) FROM SupportMessages WHERE ticket_id = t.ticket_id) as message_count FROM SupportTickets t LEFT JOIN SupportCategories c ON t.category_id = c.category_id WHERE t.user_id = ? ORDER BY {$orderCol} DESC LIMIT ?");
                $stmt->bind_param("ii", $user_id, $limit);
            }
        } else {
            // Old schema: category is a string
            if ($status) {
                $stmt = $this->conn->prepare("SELECT t.*, t.category as category_name, (SELECT COUNT(*) FROM SupportMessages WHERE ticket_id = t.ticket_id) as message_count FROM SupportTickets t WHERE t.user_id = ? AND t.status = ? ORDER BY {$orderCol} DESC LIMIT ?");
                $stmt->bind_param("isi", $user_id, $status, $limit);
            } else {
                $stmt = $this->conn->prepare("SELECT t.*, t.category as category_name, (SELECT COUNT(*) FROM SupportMessages WHERE ticket_id = t.ticket_id) as message_count FROM SupportTickets t WHERE t.user_id = ? ORDER BY {$orderCol} DESC LIMIT ?");
                $stmt->bind_param("ii", $user_id, $limit);
            }
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $tickets = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $tickets;
    }
    
    /**
     * Ticket detaylarını getir
     */
    public function getTicketById($ticket_id, $user_id = null) {
        $s = $this->detectSchema();
        $usersTable = $this->usersTable();
        $whereUser = $user_id ? ' AND t.user_id = ?' : '';

        if ($s['tickets_has_category_id'] && $s['has_categories_table']) {
            $sql = "SELECT t.*, c.category_name, u.full_name as user_name, u.email as user_email FROM SupportTickets t LEFT JOIN SupportCategories c ON t.category_id = c.category_id LEFT JOIN `{$usersTable}` u ON t.user_id = u.user_id WHERE t.ticket_id = ?{$whereUser}";
        } else {
            $sql = "SELECT t.*, t.category as category_name, u.full_name as user_name, u.email as user_email FROM SupportTickets t LEFT JOIN `{$usersTable}` u ON t.user_id = u.user_id WHERE t.ticket_id = ?{$whereUser}";
        }

        $stmt = $this->conn->prepare($sql);
        if ($user_id) {
            $stmt->bind_param('ii', $ticket_id, $user_id);
        } else {
            $stmt->bind_param('i', $ticket_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $ticket = $result->fetch_assoc();
        $stmt->close();
        return $ticket;
    }

    /**
     * Ticket detaylarını getir (role-aware / order-aware erişim).
     * Admin/Operator/Manager: tüm ticket'lar
     * Diğer roller: ticket sahibi veya aynı order/delivery'nin tarafıysa
     */
    public function getTicketByIdForViewer(int $ticketId, int $viewerUserId, int $viewerRoleId) {
        if ($this->isPrivilegedRole($viewerRoleId)) {
            return $this->getTicketById($ticketId, null);
        }

        $s = $this->detectSchema();
        $usersTable = $this->usersTable();
        $params = [];
        $types = '';
        $accessWhere = $this->buildViewerAccessWhere($viewerUserId, $viewerRoleId, 't', $params, $types);

        if ($s['tickets_has_category_id'] && $s['has_categories_table']) {
            $sql = "SELECT t.*, c.category_name, u.full_name as user_name, u.email as user_email
                    FROM SupportTickets t
                    LEFT JOIN SupportCategories c ON t.category_id = c.category_id
                    LEFT JOIN `{$usersTable}` u ON t.user_id = u.user_id
                    WHERE t.ticket_id = ? AND {$accessWhere}";
        } else {
            $sql = "SELECT t.*, t.category as category_name, u.full_name as user_name, u.email as user_email
                    FROM SupportTickets t
                    LEFT JOIN `{$usersTable}` u ON t.user_id = u.user_id
                    WHERE t.ticket_id = ? AND {$accessWhere}";
        }

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return null;
        $types2 = 'i' . $types;
        $params2 = array_merge([(int)$ticketId], $params);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $result = $stmt->get_result();
        $ticket = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $ticket;
    }

    /**
     * Viewer'a göre ticket listesi (owner veya order/delivery tarafı).
     */
    public function getTicketsForViewer(int $viewerUserId, int $viewerRoleId, ?string $status = null, int $limit = 20): array {
        $s = $this->detectSchema();
        $orderCol = $s['tickets_has_updated_at'] ? 't.updated_at' : 't.created_at';

        $params = [];
        $types = '';
        $accessWhere = $this->buildViewerAccessWhere($viewerUserId, $viewerRoleId, 't', $params, $types);

        $where = ["{$accessWhere}"];
        if ($status) {
            $where[] = 't.status = ?';
            $params[] = $status;
            $types .= 's';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        if ($s['tickets_has_category_id'] && $s['has_categories_table']) {
            $sql = "SELECT t.*, c.category_name,
                           (SELECT COUNT(*) FROM SupportMessages WHERE ticket_id = t.ticket_id) as message_count
                    FROM SupportTickets t
                    LEFT JOIN SupportCategories c ON t.category_id = c.category_id
                    {$whereSql}
                    ORDER BY {$orderCol} DESC
                    LIMIT ?";
        } else {
            $sql = "SELECT t.*, t.category as category_name,
                           (SELECT COUNT(*) FROM SupportMessages WHERE ticket_id = t.ticket_id) as message_count
                    FROM SupportTickets t
                    {$whereSql}
                    ORDER BY {$orderCol} DESC
                    LIMIT ?";
        }

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];
        $types .= 'i';
        $params[] = $limit;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $tickets = $result ? ($result->fetch_all(MYSQLI_ASSOC) ?? []) : [];
        $stmt->close();
        return $tickets;
    }

    /**
     * order_id/delivery_id üzerinden viewer'ın erişebileceği son ticket_id'yi bulur.
     */
    public function findLatestTicketIdForOrderDelivery(int $viewerUserId, int $viewerRoleId, ?int $orderId, ?int $deliveryId): ?int {
        $s = $this->detectSchema();
        $orderId = $orderId ? (int)$orderId : 0;
        $deliveryId = $deliveryId ? (int)$deliveryId : 0;

        $filters = [];
        if ($orderId > 0 && !empty($s['tickets_has_order_id'])) {
            $filters[] = 't.order_id = ' . $orderId;
        }
        if ($deliveryId > 0 && !empty($s['tickets_has_delivery_id'])) {
            $filters[] = 't.delivery_id = ' . $deliveryId;
        }
        if (empty($filters)) {
            return null;
        }

        $params = [];
        $types = '';
        $accessWhere = $this->buildViewerAccessWhere($viewerUserId, $viewerRoleId, 't', $params, $types);
        $whereSql = 'WHERE (' . implode(' OR ', $filters) . ') AND ' . $accessWhere;

        $orderCol = $s['tickets_has_updated_at'] ? 't.updated_at' : 't.created_at';
        $sql = "SELECT t.ticket_id FROM SupportTickets t {$whereSql} ORDER BY {$orderCol} DESC LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return null;
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?? null;
        $stmt->close();
        $tid = isset($row['ticket_id']) ? (int)$row['ticket_id'] : 0;
        return $tid > 0 ? $tid : null;
    }
    
    /**
     * Ticket mesajlarını getir
     */
    public function getTicketMessages($ticket_id) {
        $s = $this->detectSchema();
        $usersTable = $this->usersTable();
        $stmt = $this->conn->prepare("SELECT m.*, u.full_name as sender_name, u.role_id as sender_role_id FROM SupportMessages m LEFT JOIN `{$usersTable}` u ON m.sender_id = u.user_id WHERE m.ticket_id = ? ORDER BY m.created_at ASC");
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $messages = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // normalize message field for UI
        foreach ($messages as &$m) {
            if (!isset($m['message']) && isset($m['message_text'])) {
                $m['message'] = $m['message_text'];
            }
            if (!isset($m['is_operator'])) {
                $m['is_operator'] = (isset($m['sender_role_id']) && (int)$m['sender_role_id'] === 2) ? 1 : 0;
            }
        }
        unset($m);

        // Mesajları okundu olarak işaretle
        if ($s['msgs_has_is_read']) {
            $this->conn->query("UPDATE SupportMessages SET is_read = TRUE WHERE ticket_id = " . (int)$ticket_id . " AND is_read = FALSE");
        }

        return $messages;
    }
    
    /**
     * Ticket durumunu güncelle
     */
    public function updateTicketStatus($ticket_id, $status) {
        $s = $this->detectSchema();
        $sets = ['status = ?'];
        if ($s['tickets_has_updated_at']) {
            $sets[] = 'updated_at = NOW()';
        }
        if (($status === 'resolved' || $status === 'closed')) {
            if ($s['tickets_has_resolved_at']) {
                $sets[] = 'resolved_at = NOW()';
            } elseif ($s['tickets_has_closed_at']) {
                $sets[] = 'closed_at = NOW()';
            }
        }

        $stmt = $this->conn->prepare('UPDATE SupportTickets SET ' . implode(', ', $sets) . ' WHERE ticket_id = ?');
        $stmt->bind_param('si', $status, $ticket_id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public function updateTicketPriority($ticket_id, $priority) {
        if (!$this->hasColumn('SupportTickets', 'priority')) {
            return false;
        }
        $s = $this->detectSchema();
        $sets = ['priority = ?'];
        if ($s['tickets_has_updated_at']) {
            $sets[] = 'updated_at = NOW()';
        }
        $stmt = $this->conn->prepare('UPDATE SupportTickets SET ' . implode(', ', $sets) . ' WHERE ticket_id = ?');
        $stmt->bind_param('si', $priority, $ticket_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
    
    /**
     * Tüm kategorileri getir
     */
    public function getCategories() {
        $s = $this->detectSchema();
        if ($s['has_categories_table']) {
            // Some dev DBs may contain blank category rows; filter them out so the UI stays usable.
            $result = $this->conn->query("SELECT * FROM SupportCategories WHERE category_name IS NOT NULL AND category_name <> '' ORDER BY category_name");
            return $result ? ($result->fetch_all(MYSQLI_ASSOC) ?? []) : [];
        }

        // Fallback static categories (old schema)
        return [
            ['category_id' => 'Delivery', 'category_name' => 'Delivery'],
            ['category_id' => 'Payment', 'category_name' => 'Payment'],
            ['category_id' => 'Refund', 'category_name' => 'Refund'],
            ['category_id' => 'Quality', 'category_name' => 'Quality'],
            ['category_id' => 'Campaign', 'category_name' => 'Campaign'],
            ['category_id' => 'Account', 'category_name' => 'Account'],
            ['category_id' => 'Technical', 'category_name' => 'Technical'],
            ['category_id' => 'General', 'category_name' => 'General'],
        ];
    }
    
    /**
     * Okunmamış mesaj sayısı
     */
    public function getUnreadMessageCount($user_id) {
        $s = $this->detectSchema();

        if (!$s['msgs_has_is_read']) {
            return 0;
        }

        if ($s['msgs_has_is_operator']) {
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM SupportMessages m JOIN SupportTickets t ON m.ticket_id = t.ticket_id WHERE t.user_id = ? AND m.is_operator = TRUE AND m.is_read = FALSE");
            $stmt->bind_param("i", $user_id);
        } else {
            // Old schema: infer operator messages by sender role_id = 2
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM SupportMessages m JOIN SupportTickets t ON m.ticket_id = t.ticket_id JOIN Users u ON m.sender_id = u.user_id WHERE t.user_id = ? AND u.role_id = 2 AND m.is_read = FALSE");
            $stmt->bind_param("i", $user_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return (int)($row['count'] ?? 0);
    }
}
?>
