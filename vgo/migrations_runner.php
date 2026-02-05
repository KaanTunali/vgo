<?php
// Idempotent schema adjustments for development convenience.
// Safe to include from any page: produces NO output.

if (!isset($conn)) {
    @include_once __DIR__ . '/db.php';
}
if (!isset($conn)) {
    return;
}

if (!function_exists('vgo_mig_table_exists')) {
    function vgo_mig_table_exists(mysqli $conn, string $table): bool {
        $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows > 0);
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('vgo_mig_column_exists')) {
    function vgo_mig_column_exists(mysqli $conn, string $table, string $column): bool {
        $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) AND LOWER(COLUMN_NAME) = LOWER(?) LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows > 0);
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('vgo_mig_resolve_table')) {
    function vgo_mig_resolve_table(mysqli $conn, string $preferred): string {
        $stmt = $conn->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) LIMIT 1");
        if (!$stmt) return $preferred;
        $stmt->bind_param('s', $preferred);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row['TABLE_NAME'] ?? $preferred;
    }
}

if (!function_exists('vgo_mig_ident')) {
    function vgo_mig_ident(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}

// Orders columns
$ordersTable = vgo_mig_resolve_table($conn, 'orders');
if (!vgo_mig_column_exists($conn, $ordersTable, 'coupon_code')) {
    @$conn->query('ALTER TABLE ' . vgo_mig_ident($ordersTable) . ' ADD COLUMN coupon_code VARCHAR(50) NULL');
}
if (!vgo_mig_column_exists($conn, $ordersTable, 'discount_amount')) {
    @$conn->query('ALTER TABLE ' . vgo_mig_ident($ordersTable) . ' ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00');
}

// Coupons table
if (!vgo_mig_table_exists($conn, 'Coupons')) {
    @$conn->query("CREATE TABLE Coupons (
        coupon_id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        discount_type VARCHAR(10) NOT NULL,
        discount_value DECIMAL(10,2) NOT NULL,
        min_order_total DECIMAL(10,2) NULL,
        expires_at DATETIME NULL,
        is_active BOOLEAN NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Favorites table
if (!vgo_mig_table_exists($conn, 'Favorites')) {
    @$conn->query("CREATE TABLE Favorites (
        favorite_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        merchant_id INT NULL,
        product_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Notifications table
if (!vgo_mig_table_exists($conn, 'Notifications')) {
    $usersTable = vgo_mig_resolve_table($conn, 'users');
    @$conn->query("CREATE TABLE Notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        role_id INT NULL,
        order_id INT NULL,
        delivery_id INT NULL,
        type VARCHAR(50) NOT NULL,
        message VARCHAR(500) NOT NULL,
        is_read BOOLEAN NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT FK_Notifications_Users FOREIGN KEY (user_id) REFERENCES " . vgo_mig_ident($usersTable) . "(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Cart tables
if (!vgo_mig_table_exists($conn, 'Carts')) {
    @$conn->query("CREATE TABLE Carts (
        cart_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        merchant_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
if (!vgo_mig_table_exists($conn, 'Cart_Items')) {
    @$conn->query("CREATE TABLE Cart_Items (
        item_id INT AUTO_INCREMENT PRIMARY KEY,
        cart_id INT NOT NULL,
        product_name VARCHAR(100) NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        total_price DECIMAL(10,2) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// SupportCategories + seed
if (!vgo_mig_table_exists($conn, 'SupportCategories')) {
    @$conn->query("CREATE TABLE SupportCategories (
        category_id INT PRIMARY KEY AUTO_INCREMENT,
        category_name VARCHAR(100) NOT NULL,
        description VARCHAR(500) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

if (vgo_mig_table_exists($conn, 'SupportCategories')) {
    $seedCats = [
        ['Sipariş', 'Sipariş süreci ve gecikmeler'],
        ['Teslimat', 'Kurye / teslimat süreci'],
        ['Ödeme', 'Ödeme ve ücretlendirme'],
        ['İade', 'İade / ücret iadesi'],
        ['Kampanya', 'Kupon / kampanya sorunları'],
        ['Hesap', 'Giriş / profil / yetki'],
        ['Teknik', 'Uygulama / GPS / teknik problemler'],
        ['Ürün / Menü', 'Ürün, menü, kalite sorunları'],
        ['Genel', 'Diğer konular'],
    ];

    foreach ($seedCats as $cat) {
        $name = $cat[0];
        $desc = $cat[1];
        $stmt = $conn->prepare("INSERT INTO SupportCategories (category_name, description, is_active)
            SELECT ?, ?, 1
            WHERE NOT EXISTS (SELECT 1 FROM SupportCategories WHERE category_name = ?)");
        if ($stmt) {
            $stmt->bind_param('sss', $name, $desc, $name);
            @$stmt->execute();
            $stmt->close();
        }
    }
}

// AuditLogs
if (!vgo_mig_table_exists($conn, 'AuditLogs')) {
    @$conn->query("CREATE TABLE AuditLogs (
        log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
        actor_user_id INT NULL,
        actor_role_id INT NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(50) NULL,
        entity_id VARCHAR(64) NULL,
        details_json TEXT NULL,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_created_at (created_at),
        INDEX idx_audit_actor (actor_user_id, created_at),
        INDEX idx_audit_action (action, created_at),
        INDEX idx_audit_entity (entity_type, entity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
