<?php
session_start();
require_once 'db.php';
@include_once __DIR__ . '/migrations_runner.php';

if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 6], true)) {
    header('Location: login.php');
    exit;
}

function vgo_table_exists5(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function vgo_resolve_table_name5(mysqli $conn, array $candidates): string {
    foreach ($candidates as $cand) {
        $cand = (string)$cand;
        if ($cand !== '' && vgo_table_exists5($conn, $cand)) return $cand;
    }
    return (string)($candidates[0] ?? '');
}

function vgo_ident5(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    header('Location: admin_orders.php');
    exit;
}

$tableOrders = vgo_resolve_table_name5($conn, ['orders', 'Orders']);
$tableOrderItems = vgo_resolve_table_name5($conn, ['order_items', 'Order_Items']);
$tableDeliveries = vgo_resolve_table_name5($conn, ['deliveries', 'Deliveries']);

$order = null;
$items = [];
$delivery = null;

if ($tableOrders !== '' && vgo_table_exists5($conn, $tableOrders)) {
    $stmt = $conn->prepare('SELECT * FROM ' . vgo_ident5($tableOrders) . ' WHERE order_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc() ?? null;
        $stmt->close();
    }
}

if (!$order) {
    header('Location: admin_orders.php');
    exit;
}

if ($tableOrderItems !== '' && vgo_table_exists5($conn, $tableOrderItems)) {
    $stmt = $conn->prepare('SELECT product_name, quantity, unit_price, total_price FROM ' . vgo_ident5($tableOrderItems) . ' WHERE order_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmt->close();
    }
}

if ($tableDeliveries !== '' && vgo_table_exists5($conn, $tableDeliveries)) {
    $stmt = $conn->prepare('SELECT * FROM ' . vgo_ident5($tableDeliveries) . ' WHERE order_id = ? ORDER BY delivery_id DESC LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $delivery = $stmt->get_result()->fetch_assoc() ?? null;
        $stmt->close();
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>VGO - Sipariş Detay</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Sipariş Detay #<?php echo (int)$orderId; ?></h4>
        <a class="btn btn-sm btn-outline-secondary" href="admin_orders.php">Geri</a>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Sipariş</h6>
                    <div class="mb-1"><strong>Customer ID:</strong> <?php echo (int)($order['customer_id'] ?? 0); ?></div>
                    <div class="mb-1"><strong>Merchant ID:</strong> <?php echo (int)($order['merchant_id'] ?? 0); ?></div>
                    <div class="mb-1"><strong>Status:</strong> <?php echo htmlspecialchars((string)($order['status'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Total:</strong> <?php echo htmlspecialchars((string)($order['total_price'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Ödeme:</strong> <?php echo htmlspecialchars((string)($order['payment_method'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Kupon:</strong> <?php echo htmlspecialchars((string)($order['coupon_code'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Tarih:</strong> <?php echo htmlspecialchars((string)($order['order_date'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Adres:</strong> <?php echo htmlspecialchars((string)($order['delivery_address'] ?? '')); ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Teslimat</h6>
                    <?php if (!$delivery): ?>
                        <div class="text-muted">Teslimat kaydı yok.</div>
                    <?php else: ?>
                        <div class="mb-1"><strong>Delivery ID:</strong> <?php echo (int)($delivery['delivery_id'] ?? 0); ?></div>
                        <div class="mb-1"><strong>Courier ID:</strong> <?php echo (int)($delivery['courier_id'] ?? 0); ?></div>
                        <div class="mb-1"><strong>Status:</strong> <?php echo htmlspecialchars((string)($delivery['status'] ?? '')); ?></div>
                        <div class="mb-1"><strong>Assigned:</strong> <?php echo htmlspecialchars((string)($delivery['assigned_at'] ?? '')); ?></div>
                        <div class="mb-1"><strong>Accepted:</strong> <?php echo htmlspecialchars((string)($delivery['accepted_at'] ?? '')); ?></div>
                        <div class="mb-1"><strong>Delivered:</strong> <?php echo htmlspecialchars((string)($delivery['delivered_at'] ?? '')); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Ürünler</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Ürün</th>
                                    <th>Adet</th>
                                    <th>Birim</th>
                                    <th>Toplam</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($items)): ?>
                                <tr><td colspan="4" class="text-muted">Kayıt yok.</td></tr>
                            <?php else: foreach ($items as $it): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($it['product_name'] ?? '')); ?></td>
                                    <td><?php echo (int)($it['quantity'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)($it['unit_price'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($it['total_price'] ?? '')); ?></td>
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
