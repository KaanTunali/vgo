<?php
session_start();
require_once 'db.php';
@include_once __DIR__ . '/migrations_runner.php';

if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 6], true)) {
    header('Location: login.php');
    exit;
}

function vgo_table_exists6(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function vgo_resolve_table_name6(mysqli $conn, array $candidates): string {
    foreach ($candidates as $cand) {
        $cand = (string)$cand;
        if ($cand !== '' && vgo_table_exists6($conn, $cand)) return $cand;
    }
    return (string)($candidates[0] ?? '');
}

function vgo_ident6(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function vgo_scalar(mysqli $conn, string $sql, string $types = '', array $params = []): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?? [];
    $stmt->close();
    foreach ($row as $v) return (int)$v;
    return 0;
}

$tableUsers = vgo_resolve_table_name6($conn, ['users', 'Users']);
$tableOrders = vgo_resolve_table_name6($conn, ['orders', 'Orders']);
$tableDeliveries = vgo_resolve_table_name6($conn, ['deliveries', 'Deliveries']);
$tableSupportTickets = vgo_resolve_table_name6($conn, ['SupportTickets', 'supporttickets']);
$tableMerchants = vgo_resolve_table_name6($conn, ['merchants', 'Merchants']);

$counts = [
    'users' => 0,
    'orders' => 0,
    'deliveries' => 0,
    'tickets' => 0,
];

if ($tableUsers !== '' && vgo_table_exists6($conn, $tableUsers)) {
    $counts['users'] = vgo_scalar($conn, 'SELECT COUNT(*) AS c FROM ' . vgo_ident6($tableUsers));
}
if ($tableOrders !== '' && vgo_table_exists6($conn, $tableOrders)) {
    $counts['orders'] = vgo_scalar($conn, 'SELECT COUNT(*) AS c FROM ' . vgo_ident6($tableOrders));
}
if ($tableDeliveries !== '' && vgo_table_exists6($conn, $tableDeliveries)) {
    $counts['deliveries'] = vgo_scalar($conn, 'SELECT COUNT(*) AS c FROM ' . vgo_ident6($tableDeliveries));
}
if ($tableSupportTickets !== '' && vgo_table_exists6($conn, $tableSupportTickets)) {
    $counts['tickets'] = vgo_scalar($conn, 'SELECT COUNT(*) AS c FROM ' . vgo_ident6($tableSupportTickets));
}

$ordersByStatus = [];
if ($tableOrders !== '' && vgo_table_exists6($conn, $tableOrders)) {
    $sql = 'SELECT COALESCE(status, "") AS status, COUNT(*) AS cnt FROM ' . vgo_ident6($tableOrders) . ' GROUP BY COALESCE(status, "") ORDER BY cnt DESC';
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $ordersByStatus[] = $r;
        }
        $res->free();
    }
}

$topMerchants = [];
if ($tableOrders !== '' && vgo_table_exists6($conn, $tableOrders) && $tableMerchants !== '' && vgo_table_exists6($conn, $tableMerchants)) {
    $sql = 'SELECT o.merchant_id, m.store_name, COUNT(*) AS cnt '
        . 'FROM ' . vgo_ident6($tableOrders) . ' o '
        . 'JOIN ' . vgo_ident6($tableMerchants) . ' m ON m.merchant_id = o.merchant_id '
        . 'GROUP BY o.merchant_id, m.store_name '
        . 'ORDER BY cnt DESC '
        . 'LIMIT 10';
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $topMerchants[] = $r;
        }
        $res->free();
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>VGO - Raporlar</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Raporlar</h4>
        <a class="btn btn-sm btn-outline-secondary" href="admin_dashboard.php">Admin Panel</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted">Users</div>
                <div class="fs-4"><?php echo (int)$counts['users']; ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted">Orders</div>
                <div class="fs-4"><?php echo (int)$counts['orders']; ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted">Deliveries</div>
                <div class="fs-4"><?php echo (int)$counts['deliveries']; ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted">Support Tickets</div>
                <div class="fs-4"><?php echo (int)$counts['tickets']; ?></div>
            </div></div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Siparişler (Status Bazlı)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead><tr><th>Status</th><th>Adet</th></tr></thead>
                            <tbody>
                            <?php if (empty($ordersByStatus)): ?>
                                <tr><td colspan="2" class="text-muted">Kayıt yok.</td></tr>
                            <?php else: foreach ($ordersByStatus as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($r['status'] ?? '')); ?></td>
                                    <td><?php echo (int)($r['cnt'] ?? 0); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Top Merchants (Order Adedi)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead><tr><th>Merchant</th><th>Mağaza</th><th>Adet</th></tr></thead>
                            <tbody>
                            <?php if (empty($topMerchants)): ?>
                                <tr><td colspan="3" class="text-muted">Kayıt yok.</td></tr>
                            <?php else: foreach ($topMerchants as $r): ?>
                                <tr>
                                    <td>#<?php echo (int)($r['merchant_id'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['store_name'] ?? '')); ?></td>
                                    <td><?php echo (int)($r['cnt'] ?? 0); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="text-muted mt-2" style="font-size:12px;">Not: Bu sayfa sadece özet rapor verir (read-only).</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
