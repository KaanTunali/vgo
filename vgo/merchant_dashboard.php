<?php
session_start();
include 'db.php';

function vgo_resolve_table_name(mysqli $conn, string $preferred): string {
    $stmt = $conn->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) LIMIT 1");
    if (!$stmt) return $preferred;
    $stmt->bind_param('s', $preferred);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row['TABLE_NAME'] ?? $preferred;
}

function vgo_table_exists_ci(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

// Sadece merchant (role_id = 5) erişebilir
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

$tableMerchants = vgo_resolve_table_name($conn, 'merchants');
$tableOrders = vgo_resolve_table_name($conn, 'orders');
$tableDeliveries = vgo_resolve_table_name($conn, 'deliveries');
$tableCustomers = vgo_resolve_table_name($conn, 'customers');
$tableUsers = vgo_resolve_table_name($conn, 'users');
$tableCustomerAddresses = vgo_resolve_table_name($conn, 'customer_addresses');

// Merchant id
$stmt = $conn->prepare("SELECT merchant_id, store_name, avg_preparation_time, is_active FROM {$tableMerchants} WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$merchant = $res->fetch_assoc();
$stmt->close();

if (!$merchant) {
    echo "Mağaza bilgisi bulunamadı.";
    exit;
}
$merchant_id = $merchant['merchant_id'];

// Bugünkü sipariş sayısı ve hazır/ hazırlık vs
$stmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM {$tableOrders} WHERE merchant_id = ? AND DATE(order_date) = CURDATE() GROUP BY status");
$stmt->bind_param("i", $merchant_id);
$stmt->execute();
$todayStats = $stmt->get_result();
$stmt->close();

// Stats: today's orders and revenue
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(total_price),0) AS revenue FROM {$tableOrders} WHERE merchant_id = ? AND DATE(order_date) = CURDATE()");
$stmt->bind_param("i", $merchant_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$today_order_count = (int)($row['cnt'] ?? 0);
$today_revenue = (float)($row['revenue'] ?? 0);

// Preparing count
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM {$tableOrders} WHERE merchant_id = ? AND status = 'preparing' AND DATE(order_date) = CURDATE()");
$stmt->bind_param("i", $merchant_id);
$stmt->execute();
$prep_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$preparing_count = (int)($prep_row['cnt'] ?? 0);

// Week revenue
$week_start = date('Y-m-d 00:00:00', strtotime('monday this week'));
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_price),0) AS revenue FROM {$tableOrders} WHERE merchant_id = ? AND order_date >= ?");
$stmt->bind_param("is", $merchant_id, $week_start);
$stmt->execute();
$week_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$week_revenue = (float)($week_row['revenue'] ?? 0);

// Last 7 days revenue for chart
$stmt = $conn->prepare("SELECT DATE(order_date) AS d, COALESCE(SUM(total_price),0) AS total FROM {$tableOrders} WHERE merchant_id = ? AND order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(order_date) ORDER BY d");
$stmt->bind_param("i", $merchant_id);
$stmt->execute();
$res7 = $stmt->get_result();
$stmt->close();
$chart_labels = [];
$chart_values = [];
// Initialize last 7 days to 0
for ($i=6; $i>=0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} day"));
    $chart_labels[$day] = date('d.m', strtotime($day));
    $chart_values[$day] = 0.0;
}
while ($r = $res7->fetch_assoc()) {
    $day = $r['d'];
    if (isset($chart_values[$day])) { $chart_values[$day] = (float)$r['total']; }
}

// Handle average preparation time update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_prep_time'])) {
    $new_prep = max(0, (int)($_POST['avg_preparation_time'] ?? 0));
    $stmt = $conn->prepare("UPDATE {$tableMerchants} SET avg_preparation_time = ? WHERE merchant_id = ?");
    $stmt->bind_param("ii", $new_prep, $merchant_id);
    $stmt->execute();
    $stmt->close();
    header('Location: merchant_dashboard.php?updated=1');
    exit;
}

// Handle store active toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_store_active'])) {
    $newActive = isset($_POST['is_active']) ? 1 : 0;
    $stmt = $conn->prepare("UPDATE {$tableMerchants} SET is_active = ? WHERE merchant_id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $newActive, $merchant_id);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: merchant_dashboard.php?active_updated=1');
    exit;
}

// Yeni siparişler - Direct SQL query
// Address table may vary by environment. Prefer Customer_Addresses; fall back to order/merchant address.
$addr_table_exists = false;
$addr_table_exists = vgo_table_exists_ci($conn, 'customer_addresses');

$address_select = $addr_table_exists
    ? "COALESCE(ca.address_line, o.delivery_address, m.address) AS delivery_address, COALESCE(ca.city, m.city, 'N/A') AS city"
    : "COALESCE(o.delivery_address, m.address) AS delivery_address, COALESCE(m.city, 'N/A') AS city";

$join_addresses = $addr_table_exists ? "LEFT JOIN {$tableCustomerAddresses} ca ON o.address_id = ca.address_id" : "";

$sql = "SELECT 
    o.order_id,
    o.order_date,
    o.status,
    o.total_price,
    u.full_name AS customer_name,
    u.phone AS customer_phone,
    $address_select,
    d.status AS delivery_status,
    d.courier_id
FROM {$tableOrders} o
JOIN {$tableCustomers} c ON o.customer_id = c.customer_id
JOIN {$tableUsers} u ON c.user_id = u.user_id
JOIN {$tableMerchants} m ON o.merchant_id = m.merchant_id
" . $join_addresses . "
LEFT JOIN {$tableDeliveries} d ON o.order_id = d.order_id
WHERE o.merchant_id = ?
ORDER BY o.order_date DESC
LIMIT 20";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $merchant_id);
$stmt->execute();
$recentOrders = $stmt->get_result();
$stmt->close();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Satıcı Paneli - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
.stat-card { border-left: 4px solid; }
.stat-card.primary { border-color: #0d6efd; }
.stat-card.success { border-color: #198754; }
.stat-card.warning { border-color: #ffc107; }
.stat-card.info { border-color: #0dcaf0; }
</style>
</head>
<body class="bg-light">
<?php include 'header.php'; ?>

<div class="container mt-4">
    <h4>Hoş geldiniz, <?php echo htmlspecialchars($merchant['store_name']); ?></h4>
    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success alert-dismissible fade show mt-2" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>Hazırlık süresi güncellendi.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['active_updated'])): ?>
        <div class="alert alert-success alert-dismissible fade show mt-2" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>Mağaza durumu güncellendi.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats Row -->
    <div class="row mt-3">
        <div class="col-md-3">
            <div class="card stat-card primary">
                <div class="card-body">
                    <h6 class="text-muted mb-2"><i class="bi bi-calendar-day me-1"></i>Bugün Sipariş</h6>
                    <h3 class="mb-0"><?php echo $today_order_count; ?></h3>
                    <small class="text-success"><?php echo number_format($today_revenue, 2); ?> TL</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card success">
                <div class="card-body">
                    <h6 class="text-muted mb-2"><i class="bi bi-hourglass-split me-1"></i>Hazırlanıyor</h6>
                    <h3 class="mb-0"><?php echo $preparing_count; ?></h3>
                    <small class="text-muted">Aktif hazırlık</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card warning">
                <div class="card-body">
                    <h6 class="text-muted mb-2"><i class="bi bi-calendar-week me-1"></i>Bu Hafta</h6>
                    <h3 class="mb-0"><?php echo number_format($week_revenue, 2); ?> TL</h3>
                    <small class="text-muted">Toplam gelir</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card info">
                <div class="card-body">
                    <h6 class="text-muted mb-2"><i class="bi bi-stopwatch me-1"></i>Ortalama Hazırlık</h6>
                    <h3 class="mb-0"><?php echo (int)($merchant['avg_preparation_time'] ?? 0); ?> dk</h3>
                    <small class="text-muted">Menü ayarlı</small>
                </div>
            </div>
        </div>
    </div>
    <div class="row mt-3">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6><i class="bi bi-clipboard-data me-1"></i>Bugünün Durumu</h6>
                    <?php if ($todayStats && $todayStats->num_rows > 0): ?>
                        <ul class="list-group list-group-flush">
                            <?php while ($s = $todayStats->fetch_assoc()): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span><?php echo htmlspecialchars($s['status']); ?></span>
                                    <span><strong><?php echo $s['cnt']; ?></strong></span>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted">Bugün sipariş yok.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <h6><i class="bi bi-lightning-charge me-1"></i>Hızlı İşlemler</h6>

                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div>
                            <span class="text-muted">Mağaza Durumu:</span>
                            <?php $isActive = ((int)($merchant['is_active'] ?? 1)) === 1; ?>
                            <span class="badge <?php echo $isActive ? 'bg-success' : 'bg-secondary'; ?> ms-1">
                                <?php echo $isActive ? 'Açık' : 'Kapalı'; ?>
                            </span>
                        </div>
                        <form method="POST" class="m-0">
                            <input type="hidden" name="toggle_store_active" value="1">
                            <?php if ($isActive): ?>
                                <input type="hidden" name="is_active" value="0">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Kapalı Yap</button>
                            <?php else: ?>
                                <input type="hidden" name="is_active" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-success">Açık Yap</button>
                            <?php endif; ?>
                        </form>
                    </div>

                    <form method="POST" class="mb-2">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-stopwatch"></i></span>
                            <input type="number" name="avg_preparation_time" class="form-control" min="0" step="1" placeholder="Hazırlık süresi (dk)" value="<?php echo (int)($merchant['avg_preparation_time'] ?? 0); ?>">
                            <button type="submit" name="update_prep_time" class="btn btn-success">Güncelle</button>
                        </div>
                    </form>
                    <a href="merchant_profile.php" class="btn btn-outline-dark w-100 mb-2">Mağaza Profilini Düzenle</a>
                    <a href="merchant_orders.php" class="btn btn-primary w-100">Tüm Siparişlere Git</a>
                    <a href="merchant_products.php" class="btn btn-outline-secondary w-100 mt-2">Ürün Yönetimi</a>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <!-- Revenue Chart -->
            <div class="card mb-3">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Son 7 Gün Gelir</h6>
                </div>
                <div class="card-body">
                    <canvas id="revenueChart" height="100"></canvas>
                </div>
            </div>
            <h6>Son Siparişler</h6>
            <?php if ($recentOrders && $recentOrders->num_rows > 0): ?>
                <ul class="list-group">
                    <?php while ($o = $recentOrders->fetch_assoc()): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong>#<?php echo $o['order_id']; ?></strong> — <?php echo htmlspecialchars($o['customer_name']); ?>
                                <div><small><?php echo htmlspecialchars($o['order_date']); ?></small></div>
                                <div><small>Durum: <?php echo htmlspecialchars($o['status']); ?></small></div>
                            </div>
                            <div class="text-end">
                                <div><?php echo number_format($o['total_price'],2); ?> ₺</div>
                                <a href="merchant_order_details.php?id=<?php echo $o['order_id']; ?>" class="btn btn-sm btn-outline-primary mt-1">Detay</a>
                            </div>
                        </li>
                    <?php endwhile; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted">Hiç sipariş yok.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Revenue chart data
const labels = <?php echo json_encode(array_values($chart_labels)); ?>;
const dataVals = <?php echo json_encode(array_values($chart_values)); ?>;
const ctx = document.getElementById('revenueChart');
if (ctx) {
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'TL',
        data: dataVals,
        backgroundColor: '#0d6efd'
      }]
    },
    options: {
      scales: { y: { beginAtZero: true } }
    }
  });
}
</script>
</body>
</html>