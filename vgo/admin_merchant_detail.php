<?php
session_start();
require_once 'db.php';
@include_once __DIR__ . '/migrations_runner.php';

if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 6], true)) {
    header('Location: login.php');
    exit;
}

function vgo_table_exists3(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function vgo_resolve_table_name3(mysqli $conn, array $candidates): string {
    foreach ($candidates as $cand) {
        $cand = (string)$cand;
        if ($cand !== '' && vgo_table_exists3($conn, $cand)) return $cand;
    }
    return (string)($candidates[0] ?? '');
}

function vgo_ident3(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

$merchantId = isset($_GET['merchant_id']) ? (int)$_GET['merchant_id'] : 0;
$back = isset($_GET['back']) ? (string)$_GET['back'] : 'admin_partners.php?tab=merchants';

if ($merchantId <= 0) {
    header('Location: ' . $back);
    exit;
}

$tableMerchants = vgo_resolve_table_name3($conn, ['merchants', 'Merchants']);
$tableUsers = vgo_resolve_table_name3($conn, ['users', 'Users']);
$tableOrders = vgo_resolve_table_name3($conn, ['orders', 'Orders']);
$tableSupportTickets = vgo_resolve_table_name3($conn, ['SupportTickets', 'supporttickets']);

$merchant = null;
$userId = 0;

$stmt = $conn->prepare(
    'SELECT m.merchant_id, m.user_id, m.store_name, m.city, m.is_active, m.rating_avg, m.zone_id, u.full_name, u.email, u.phone, u.status '
    . 'FROM ' . vgo_ident3($tableMerchants) . ' m JOIN ' . vgo_ident3($tableUsers) . ' u ON m.user_id = u.user_id '
    . 'WHERE m.merchant_id = ? LIMIT 1'
);
if ($stmt) {
    $stmt->bind_param('i', $merchantId);
    $stmt->execute();
    $merchant = $stmt->get_result()->fetch_assoc() ?? null;
    $stmt->close();
}

if (!$merchant) {
    header('Location: ' . $back);
    exit;
}

$userId = (int)($merchant['user_id'] ?? 0);

// Ticket counts
$ticketsTotal = 0;
$ticketsOpen = 0;
if ($tableSupportTickets !== '' && vgo_table_exists3($conn, $tableSupportTickets) && $userId > 0) {
    $stmtT = $conn->prepare(
        "SELECT COUNT(*) AS total_cnt, SUM(CASE WHEN status IN ('open','in_progress') THEN 1 ELSE 0 END) AS open_cnt "
        . 'FROM ' . vgo_ident3($tableSupportTickets) . ' WHERE user_id = ?'
    );
    if ($stmtT) {
        $stmtT->bind_param('i', $userId);
        $stmtT->execute();
        $rowT = $stmtT->get_result()->fetch_assoc() ?? [];
        $ticketsTotal = (int)($rowT['total_cnt'] ?? 0);
        $ticketsOpen = (int)($rowT['open_cnt'] ?? 0);
        $stmtT->close();
    }
}

// Recent orders
$orders = [];
if ($tableOrders !== '' && vgo_table_exists3($conn, $tableOrders)) {
    $stmtO = $conn->prepare(
        'SELECT order_id, customer_id, order_date, status, total_price '
        . 'FROM ' . vgo_ident3($tableOrders) . ' WHERE merchant_id = ? '
        . 'ORDER BY order_id DESC LIMIT 20'
    );
    if ($stmtO) {
        $stmtO->bind_param('i', $merchantId);
        $stmtO->execute();
        $orders = $stmtO->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmtO->close();
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>VGO - Merchant Detay</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Merchant Detay</h4>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars($back); ?>">Geri</a>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-2"><?php echo htmlspecialchars((string)($merchant['store_name'] ?? '')); ?></h5>
                    <div class="text-muted mb-2">Merchant #<?php echo (int)$merchantId; ?> • User #<?php echo (int)$userId; ?></div>

                    <div class="mb-1"><strong>Şehir:</strong> <?php echo htmlspecialchars((string)($merchant['city'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Zone:</strong> <?php echo htmlspecialchars((string)($merchant['zone_id'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Rating:</strong> <?php echo htmlspecialchars((string)($merchant['rating_avg'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Merchant Aktif:</strong> <?php echo ((int)($merchant['is_active'] ?? 0) === 1) ? 'active' : 'inactive'; ?></div>

                    <hr>

                    <div class="mb-1"><strong>İsim:</strong> <?php echo htmlspecialchars((string)($merchant['full_name'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars((string)($merchant['email'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Telefon:</strong> <?php echo htmlspecialchars((string)($merchant['phone'] ?? '')); ?></div>
                    <div class="mb-1"><strong>User Status:</strong> <?php echo htmlspecialchars((string)($merchant['status'] ?? '')); ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Destek İstatistikleri</h6>
                    <div class="mb-1"><strong>Toplam Ticket:</strong> <?php echo (int)$ticketsTotal; ?></div>
                    <div class="mb-1"><strong>Açık Ticket:</strong> <?php echo (int)$ticketsOpen; ?></div>
                    <div class="text-muted" style="font-size:12px;">Not: Ticket sayısı SupportTickets.user_id üzerinden hesaplanır.</div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Son Siparişler (20)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer ID</th>
                                    <th>Tarih</th>
                                    <th>Status</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($orders)): ?>
                                <tr><td colspan="5" class="text-muted">Kayıt yok.</td></tr>
                            <?php else: foreach ($orders as $o): ?>
                                <tr>
                                    <td><?php echo (int)($o['order_id'] ?? 0); ?></td>
                                    <td><?php echo (int)($o['customer_id'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)($o['order_date'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($o['status'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($o['total_price'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
