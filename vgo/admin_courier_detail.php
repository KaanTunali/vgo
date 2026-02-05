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

function vgo_column_exists3(mysqli $conn, string $table, string $col): bool {
    if ($table === '' || $col === '') return false;
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) AND LOWER(COLUMN_NAME) = LOWER(?) LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

$courierId = isset($_GET['courier_id']) ? (int)$_GET['courier_id'] : 0;
$back = isset($_GET['back']) ? (string)$_GET['back'] : 'admin_partners.php?tab=couriers';

if ($courierId <= 0) {
    header('Location: ' . $back);
    exit;
}

$tableCouriers = vgo_resolve_table_name3($conn, ['couriers', 'Couriers']);
$tableUsers = vgo_resolve_table_name3($conn, ['users', 'Users']);
$tableDeliveries = vgo_resolve_table_name3($conn, ['deliveries', 'Deliveries']);
$tableOrders = vgo_resolve_table_name3($conn, ['orders', 'Orders']);
$tableSupportTickets = vgo_resolve_table_name3($conn, ['SupportTickets', 'supporttickets']);

$courier = null;
$userId = 0;

$couriersHasLicense = vgo_column_exists3($conn, $tableCouriers, 'license_number');
$couriersHasAvailable = vgo_column_exists3($conn, $tableCouriers, 'is_available');
$couriersHasRating = vgo_column_exists3($conn, $tableCouriers, 'rating_avg');
$couriersHasTotalDeliveries = vgo_column_exists3($conn, $tableCouriers, 'total_deliveries');
$usersHasStatus = vgo_column_exists3($conn, $tableUsers, 'status');
$usersHasEmail = vgo_column_exists3($conn, $tableUsers, 'email');
$usersHasPhone = vgo_column_exists3($conn, $tableUsers, 'phone');

$selectParts = [
    'c.courier_id',
    'c.user_id',
    'c.vehicle_type',
    'c.vehicle_plate',
    ($couriersHasLicense ? 'c.license_number' : 'NULL AS license_number'),
    ($couriersHasAvailable ? 'c.is_available' : '0 AS is_available'),
    ($couriersHasRating ? 'c.rating_avg' : 'NULL AS rating_avg'),
    ($couriersHasTotalDeliveries ? 'c.total_deliveries' : '0 AS total_deliveries'),
    'u.full_name',
    ($usersHasEmail ? 'u.email' : 'NULL AS email'),
    ($usersHasPhone ? 'u.phone' : 'NULL AS phone'),
    ($usersHasStatus ? 'u.status' : 'NULL AS status'),
];

$stmt = $conn->prepare('SELECT ' . implode(', ', $selectParts)
    . ' FROM ' . vgo_ident3($tableCouriers) . ' c JOIN ' . vgo_ident3($tableUsers) . ' u ON c.user_id = u.user_id'
    . ' WHERE c.courier_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $courierId);
    $stmt->execute();
    $courier = $stmt->get_result()->fetch_assoc() ?? null;
    $stmt->close();
}

if (!$courier) {
    header('Location: ' . $back);
    exit;
}

$userId = (int)($courier['user_id'] ?? 0);

// Ticket counts
$ticketsTotal = 0;
$ticketsOpen = 0;
if ($tableSupportTickets !== '' && vgo_table_exists3($conn, $tableSupportTickets) && $userId > 0) {
    $ticketsHasStatus = vgo_column_exists3($conn, $tableSupportTickets, 'status');
    $ticketsHasUserId = vgo_column_exists3($conn, $tableSupportTickets, 'user_id');

    if ($ticketsHasUserId) {
        $sqlT = 'SELECT COUNT(*) AS total_cnt,'
            . ($ticketsHasStatus
                ? " SUM(CASE WHEN status IN ('open','in_progress') THEN 1 ELSE 0 END) AS open_cnt"
                : ' 0 AS open_cnt')
            . ' FROM ' . vgo_ident3($tableSupportTickets)
            . ' WHERE user_id = ?';
        $stmtT = $conn->prepare($sqlT);
    } else {
        $stmtT = null;
    }
    if ($stmtT) {
        $stmtT->bind_param('i', $userId);
        $stmtT->execute();
        $rowT = $stmtT->get_result()->fetch_assoc() ?? [];
        $ticketsTotal = (int)($rowT['total_cnt'] ?? 0);
        $ticketsOpen = (int)($rowT['open_cnt'] ?? 0);
        $stmtT->close();
    }
}

// Recent deliveries
$deliveries = [];
if ($tableDeliveries !== '' && vgo_table_exists3($conn, $tableDeliveries) && $tableOrders !== '' && vgo_table_exists3($conn, $tableOrders)) {
    $delHasAssigned = vgo_column_exists3($conn, $tableDeliveries, 'assigned_at');
    $delHasAccepted = vgo_column_exists3($conn, $tableDeliveries, 'accepted_at');
    $delHasDelivered = vgo_column_exists3($conn, $tableDeliveries, 'delivered_at');
    $delHasStatus = vgo_column_exists3($conn, $tableDeliveries, 'status');
    $ordHasMerchant = vgo_column_exists3($conn, $tableOrders, 'merchant_id');
    $ordHasTotal = vgo_column_exists3($conn, $tableOrders, 'total_price');
    $ordHasStatus = vgo_column_exists3($conn, $tableOrders, 'status');

    $stmtD = $conn->prepare('SELECT'
        . ' d.delivery_id,'
        . ' d.order_id,'
        . ($delHasStatus ? ' d.status AS delivery_status,' : ' NULL AS delivery_status,')
        . ($delHasAssigned ? ' d.assigned_at,' : ' NULL AS assigned_at,')
        . ($delHasAccepted ? ' d.accepted_at,' : ' NULL AS accepted_at,')
        . ($delHasDelivered ? ' d.delivered_at,' : ' NULL AS delivered_at,')
        . ($ordHasMerchant ? ' o.merchant_id,' : ' 0 AS merchant_id,')
        . ($ordHasTotal ? ' o.total_price,' : ' 0 AS total_price,')
        . ($ordHasStatus ? ' o.status AS order_status' : ' NULL AS order_status')
        . ' FROM ' . vgo_ident3($tableDeliveries) . ' d JOIN ' . vgo_ident3($tableOrders) . ' o ON o.order_id = d.order_id'
        . ' WHERE d.courier_id = ?'
        . ' ORDER BY d.delivery_id DESC LIMIT 20');
    if ($stmtD) {
        $stmtD->bind_param('i', $courierId);
        $stmtD->execute();
        $deliveries = $stmtD->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmtD->close();
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>VGO - Kurye Detay</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Kurye Detay</h4>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars($back); ?>">Geri</a>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-2"><?php echo htmlspecialchars((string)($courier['full_name'] ?? '')); ?></h5>
                    <div class="text-muted mb-2">Courier #<?php echo (int)$courierId; ?> • User #<?php echo (int)$userId; ?></div>

                    <div class="mb-1"><strong>Araç:</strong> <?php echo htmlspecialchars(trim((string)($courier['vehicle_type'] ?? '') . ' ' . (string)($courier['vehicle_plate'] ?? ''))); ?></div>
                    <div class="mb-1"><strong>Ehliyet:</strong> <?php echo htmlspecialchars((string)($courier['license_number'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Müsaitlik:</strong> <?php echo ((int)($courier['is_available'] ?? 0) === 1) ? 'yes' : 'no'; ?></div>
                    <div class="mb-1"><strong>Rating:</strong> <?php echo htmlspecialchars((string)($courier['rating_avg'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Toplam Teslimat:</strong> <?php echo htmlspecialchars((string)($courier['total_deliveries'] ?? '')); ?></div>

                    <hr>

                    <div class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars((string)($courier['email'] ?? '')); ?></div>
                    <div class="mb-1"><strong>Telefon:</strong> <?php echo htmlspecialchars((string)($courier['phone'] ?? '')); ?></div>
                    <div class="mb-1"><strong>User Status:</strong> <?php echo htmlspecialchars((string)($courier['status'] ?? '')); ?></div>
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
                    <h6 class="card-title">Son Teslimatlar (20)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Delivery ID</th>
                                    <th>Order ID</th>
                                    <th>Delivery Status</th>
                                    <th>Order Status</th>
                                    <th>Merchant ID</th>
                                    <th>Total</th>
                                    <th>Assigned</th>
                                    <th>Accepted</th>
                                    <th>Delivered</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($deliveries)): ?>
                                <tr><td colspan="9" class="text-muted">Kayıt yok.</td></tr>
                            <?php else: foreach ($deliveries as $d): ?>
                                <tr>
                                    <td><?php echo (int)($d['delivery_id'] ?? 0); ?></td>
                                    <td><?php echo (int)($d['order_id'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)($d['delivery_status'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($d['order_status'] ?? '')); ?></td>
                                    <td><?php echo (int)($d['merchant_id'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)($d['total_price'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($d['assigned_at'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($d['accepted_at'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($d['delivered_at'] ?? '')); ?></td>
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
