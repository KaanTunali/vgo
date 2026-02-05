<?php
session_start();
require_once 'db.php';
@include_once __DIR__ . '/migrations_runner.php';

if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 6], true)) {
    header('Location: login.php');
    exit;
}

function vgo_table_exists4(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function vgo_resolve_table_name4(mysqli $conn, array $candidates): string {
    foreach ($candidates as $cand) {
        $cand = (string)$cand;
        if ($cand !== '' && vgo_table_exists4($conn, $cand)) return $cand;
    }
    return (string)($candidates[0] ?? '');
}

function vgo_ident4(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

$tableOrders = vgo_resolve_table_name4($conn, ['orders', 'Orders']);
$tableMerchants = vgo_resolve_table_name4($conn, ['merchants', 'Merchants']);

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$orders = [];

if ($tableOrders !== '' && vgo_table_exists4($conn, $tableOrders)) {
    $limit = 200;

    $sql = 'SELECT'
        . ' o.order_id, o.customer_id, o.merchant_id, o.order_date, o.status, o.total_price, o.payment_method, o.coupon_code,'
        . ' m.store_name'
        . ' FROM ' . vgo_ident4($tableOrders) . ' o'
        . ' LEFT JOIN ' . vgo_ident4($tableMerchants) . ' m ON m.merchant_id = o.merchant_id'
        . ' WHERE 1=1';

    $types = '';
    $params = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $isNum = ctype_digit($q);

        $sql .= ' AND (';

        if ($isNum) {
            $sql .= ' o.order_id = ? OR o.customer_id = ? OR o.merchant_id = ? OR';
            $types .= 'iii';
            $n = (int)$q;
            $params[] = $n;
            $params[] = $n;
            $params[] = $n;
        }

        // Force consistent collation to avoid Illegal mix of collations errors.
        $sql .= ' o.status COLLATE utf8mb4_unicode_ci LIKE ? COLLATE utf8mb4_unicode_ci'
            . ' OR o.coupon_code COLLATE utf8mb4_unicode_ci LIKE ? COLLATE utf8mb4_unicode_ci'
            . ' OR m.store_name COLLATE utf8mb4_unicode_ci LIKE ? COLLATE utf8mb4_unicode_ci'
            . ' )';

        $types .= 'sss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY o.order_date DESC LIMIT ?';
    $types .= 'i';
    $params[] = $limit;

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $orders = $res ? ($res->fetch_all(MYSQLI_ASSOC) ?? []) : [];
        $stmt->close();
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>VGO - Sipariş Yönetimi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Sipariş Yönetimi</h4>
        <a class="btn btn-sm btn-outline-secondary" href="admin_dashboard.php">Admin Panel</a>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2" method="get">
                <div class="col-md-10">
                    <label class="form-label">Ara</label>
                    <input class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Order ID / Customer ID / Merchant ID / Status / Kupon / Mağaza">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100" type="submit">Ara</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Merchant</th>
                            <th>Tarih</th>
                            <th>Status</th>
                            <th>Total</th>
                            <th>Ödeme</th>
                            <th>Kupon</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="9" class="text-muted">Kayıt yok.</td></tr>
                    <?php else: foreach ($orders as $o): ?>
                        <?php $oid = (int)($o['order_id'] ?? 0); ?>
                        <tr>
                            <td>#<?php echo $oid; ?></td>
                            <td>#<?php echo (int)($o['customer_id'] ?? 0); ?></td>
                            <td>
                                #<?php echo (int)($o['merchant_id'] ?? 0); ?>
                                <div class="text-muted" style="font-size:12px;">
                                    <?php echo htmlspecialchars((string)($o['store_name'] ?? '')); ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars((string)($o['order_date'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($o['status'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($o['total_price'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($o['payment_method'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($o['coupon_code'] ?? '')); ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="admin_order_detail.php?order_id=<?php echo $oid; ?>">Detay</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
