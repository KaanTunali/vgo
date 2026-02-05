<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];

// courier info
$stmt = $conn->prepare("SELECT courier_id, vehicle_type, is_available, current_zone_id FROM Couriers WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$courier = $res->fetch_assoc();
$stmt->close();
if (!$courier) { echo "Kurye bulunamadı."; exit; }
$courier_id = $courier['courier_id'];

// Handle status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $new_status = $courier['is_available'] ? 0 : 1;
    $stmt = $conn->prepare("UPDATE Couriers SET is_available = ? WHERE courier_id = ?");
    $stmt->bind_param("ii", $new_status, $courier_id);
    $stmt->execute();
    $stmt->close();
    header('Location: courier_dashboard.php');
    exit;
}

// Handle zone change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_zone'])) {
    $new_zone_id = intval($_POST['new_zone_id']);
    if ($new_zone_id > 0) {
        $stmt = $conn->prepare("UPDATE Couriers SET current_zone_id = ? WHERE courier_id = ?");
        $stmt->bind_param("ii", $new_zone_id, $courier_id);
        $stmt->execute();
        $stmt->close();
        header('Location: courier_dashboard.php?zone_changed=1');
        exit;
    }
}

// Check if delivered_at column exists before using in statistics
$delivered_at_col_exists = false;
$result = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Deliveries' AND COLUMN_NAME = 'delivered_at' LIMIT 1");
if ($result && $result->num_rows > 0) {
    $delivered_at_col_exists = true;
}

// Statistics - Today's deliveries
$today_start = date('Y-m-d 00:00:00');
if ($delivered_at_col_exists) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt, SUM(o.total_price) as total FROM Deliveries d JOIN Orders o ON d.order_id = o.order_id WHERE d.courier_id = ? AND d.status = 'delivered' AND d.delivered_at >= ?");
    $stmt->bind_param("is", $courier_id, $today_start);
} else {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt, SUM(o.total_price) as total FROM Deliveries d JOIN Orders o ON d.order_id = o.order_id WHERE d.courier_id = ? AND d.status = 'delivered' AND DATE(o.order_date) = CURDATE()");
    $stmt->bind_param("i", $courier_id);
}
$stmt->execute();
$today_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// This week stats
$week_start = date('Y-m-d 00:00:00', strtotime('monday this week'));
if ($delivered_at_col_exists) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt, SUM(o.total_price) as total FROM Deliveries d JOIN Orders o ON d.order_id = o.order_id WHERE d.courier_id = ? AND d.status = 'delivered' AND d.delivered_at >= ?");
    $stmt->bind_param("is", $courier_id, $week_start);
} else {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt, SUM(o.total_price) as total FROM Deliveries d JOIN Orders o ON d.order_id = o.order_id WHERE d.courier_id = ? AND d.status = 'delivered' AND o.order_date >= ?");
    $stmt->bind_param("is", $courier_id, $week_start);
}
$stmt->execute();
$week_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Courier rating (check if Ratings table exists)
$ratings_exists = false;
$result = $conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'ratings' LIMIT 1");
if ($result && $result->num_rows > 0) {
    $ratings_exists = true;
}

// Check if courier_rating column exists in Ratings table
$courier_rating_col_exists = false;
if ($ratings_exists) {
    $result = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'ratings' AND LOWER(COLUMN_NAME) = 'courier_rating' LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $courier_rating_col_exists = true;
    }
}

$courier_rating = 0;
$recent_reviews = [];
if ($ratings_exists && $courier_rating_col_exists) {
    $stmt = $conn->prepare("SELECT AVG(courier_rating) as avg_rating FROM ratings WHERE courier_id = ?");
    $stmt->bind_param("i", $courier_id);
    $stmt->execute();
    $rating_result = $stmt->get_result()->fetch_assoc();
    $courier_rating = round($rating_result['avg_rating'] ?? 0, 1);
    $stmt->close();
    
    // Recent reviews
    $stmt = $conn->prepare("SELECT r.courier_rating, r.review_text, r.created_at, u.full_name FROM ratings r JOIN Customers c ON r.customer_id = c.customer_id JOIN Users u ON c.user_id = u.user_id WHERE r.courier_id = ? ORDER BY r.created_at DESC LIMIT 5");
    $stmt->bind_param("i", $courier_id);
    $stmt->execute();
    $recent_reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get zone name
$zone_name = 'Belirsiz';
if ($courier['current_zone_id']) {
    $stmt = $conn->prepare("SELECT zone_name, city FROM Zones WHERE zone_id = ?");
    $stmt->bind_param("i", $courier['current_zone_id']);
    $stmt->execute();
    $zone_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($zone_info) {
        $zone_name = $zone_info['city'] . ' / ' . $zone_info['zone_name'];
    }
}

// Get all available zones for zone change dropdown (group by city and zone_name to avoid duplicates)
$all_zones = [];
$result = $conn->query("SELECT MIN(zone_id) as zone_id, zone_name, city FROM Zones WHERE is_active = 1 GROUP BY city, zone_name ORDER BY city, zone_name");
if ($result) {
    $all_zones = $result->fetch_all(MYSQLI_ASSOC);
}

$courier_id = $courier['courier_id'];

// Ensure Deliveries table has assignment columns and compatible types (safe migration for dev)
$colsToEnsure = ['assigned_at','accepted_at','delivered_at'];
foreach ($colsToEnsure as $col) {
    $res = $conn->query("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Deliveries' AND COLUMN_NAME='" . $conn->real_escape_string($col) . "'");
    if ($res) {
        $row = $res->fetch_assoc();
        if ($row['cnt'] == 0) {
            $conn->query("ALTER TABLE Deliveries ADD COLUMN $col DATETIME NULL");
        }
    }
}
// Make courier_id nullable if currently NOT NULL
$res = $conn->query("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Deliveries' AND COLUMN_NAME='courier_id'");
if ($res && $info = $res->fetch_assoc()) {
    if ($info['IS_NULLABLE'] === 'NO') {
        $conn->query("ALTER TABLE Deliveries MODIFY courier_id INT NULL");
    }
}
// Ensure status supports broader values; fall back to VARCHAR(20) if enum is restrictive
$res = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Deliveries' AND COLUMN_NAME='status'");
if ($res && $r = $res->fetch_assoc()) {
    $ct = $r['COLUMN_TYPE'];
    if (strpos($ct,'unassigned') === false || strpos($ct,'accepted') === false || strpos($ct,'on_the_way') === false) {
        $conn->query("ALTER TABLE Deliveries MODIFY status VARCHAR(20) NOT NULL");
    }
}

// Assigned deliveries (active only) - Dynamic query based on schema
$addr_table_exists = false;
$result = $conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Customer_Addresses' LIMIT 1");
if ($result && $result->num_rows > 0) {
    $addr_table_exists = true;
}

// Check if estimated_arrival_time column exists in Deliveries
$eta_col_exists = false;
$result = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Deliveries' AND COLUMN_NAME = 'estimated_arrival_time' LIMIT 1");
if ($result && $result->num_rows > 0) {
    $eta_col_exists = true;
}

$eta_select = $eta_col_exists ? "d.estimated_arrival_time" : "NULL AS estimated_arrival_time";

$stmt = $conn->prepare("SELECT 
    d.delivery_id,
    d.order_id,
    d.status,
    $eta_select,
    o.order_date,
    o.total_price,
    m.store_name,
    m.address AS merchant_address,
    u.full_name AS customer_name,
    COALESCE(ca.address_line, o.delivery_address) AS delivery_address,
    COALESCE(ca.city, 'N/A') AS city
FROM Deliveries d
JOIN Orders o ON d.order_id = o.order_id
JOIN Merchants m ON o.merchant_id = m.merchant_id
JOIN Customers cu ON o.customer_id = cu.customer_id
JOIN Users u ON cu.user_id = u.user_id
" . ($addr_table_exists ? "LEFT JOIN Customer_Addresses ca ON o.address_id = ca.address_id" : "") . "
WHERE d.courier_id = ? 
AND d.status IN ('assigned', 'picked_up', 'on_the_way', 'delivering', 'ready')
AND o.status NOT IN ('delivered','cancelled')
ORDER BY o.order_date DESC");
$stmt->bind_param("i", $courier_id);
$stmt->execute();
$assigned = $stmt->get_result();
$stmt->close();

// Use the earlier check for delivered_at column
$delivered_select = $delivered_at_col_exists ? "d.delivered_at" : "NULL AS delivered_at";
$order_clause = $delivered_at_col_exists ? "ORDER BY d.delivered_at DESC" : "ORDER BY o.order_date DESC";

// Completed deliveries
$stmt = $conn->prepare("SELECT 
    d.delivery_id,
    d.order_id,
    d.status,
    $delivered_select,
    o.order_date,
    o.total_price,
    m.store_name
FROM Deliveries d
JOIN Orders o ON d.order_id = o.order_id
JOIN Merchants m ON o.merchant_id = m.merchant_id
WHERE d.courier_id = ? 
AND d.status = 'delivered'
$order_clause
LIMIT 10");
$stmt->bind_param("i", $courier_id);
$stmt->execute();
$completed = $stmt->get_result();
$stmt->close();

// Available nearby/unassigned deliveries
$available = [];
if ($courier['is_available'] && $courier['current_zone_id']) {
    $stmt = $conn->prepare("SELECT 
        d.delivery_id,
        d.order_id,
        o.order_date,
        o.total_price,
        m.store_name,
        m.address AS merchant_address,
        COALESCE(ca.address_line, o.delivery_address) AS delivery_address,
        COALESCE(ca.city, 'N/A') AS city
    FROM Deliveries d
    JOIN Orders o ON d.order_id = o.order_id
    JOIN Merchants m ON o.merchant_id = m.merchant_id
    LEFT JOIN Zones mz ON m.zone_id = mz.zone_id
    " . ($addr_table_exists ? "LEFT JOIN Customer_Addresses ca ON o.address_id = ca.address_id" : "") . "
    WHERE d.courier_id IS NULL 
    AND d.status IN ('unassigned', 'pending', 'ready')
    AND (
        m.zone_id = ?
        OR (
            mz.city IS NOT NULL
            AND mz.zone_name IS NOT NULL
            AND EXISTS (
                SELECT 1
                FROM Zones cz
                WHERE cz.zone_id = ?
                  AND cz.city = mz.city
                  AND cz.zone_name = mz.zone_name
            )
        )
    )
    ORDER BY o.order_date ASC
    LIMIT 20");
    $stmt->bind_param("ii", $courier['current_zone_id'], $courier['current_zone_id']);
    $stmt->execute();
    $available = $stmt->get_result();
    $stmt->close();
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kurye Paneli - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>
    .stat-card { border-left: 4px solid; transition: transform 0.2s; }
    .stat-card:hover { transform: translateY(-3px); }
    .stat-card.primary { border-color: #0d6efd; }
    .stat-card.success { border-color: #198754; }
    .stat-card.warning { border-color: #ffc107; }
    .stat-card.info { border-color: #0dcaf0; }
    .delivery-card { transition: all 0.3s; cursor: pointer; }
    .delivery-card:hover { transform: translateY(-5px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    .status-badge { font-size: 0.75rem; padding: 0.25rem 0.5rem; }
    .review-item { border-left: 3px solid #ffc107; padding-left: 10px; }
</style>
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container-fluid mt-4">
    
    <!-- Success Alert for Zone Change -->
    <?php if (isset($_GET['zone_changed'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>Bölge başarıyla değiştirildi!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Header Row -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-8">
            <h4><i class="bi bi-bicycle me-2"></i>Kurye Paneli</h4>
            <p class="mb-0">
                <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong> 
                <span class="badge bg-secondary"><?php echo htmlspecialchars($courier['vehicle_type']); ?></span>
                <span class="badge bg-info"><?php echo htmlspecialchars($zone_name); ?></span>
            </p>
        </div>
        <div class="col-md-4 text-end">
            <form method="POST" style="display:inline;">
                <button type="submit" name="toggle_status" class="btn btn-lg <?php echo $courier['is_available'] ? 'btn-success' : 'btn-secondary'; ?>">
                    <i class="bi bi-power me-2"></i>
                    <?php echo $courier['is_available'] ? 'Müsaitim' : 'Meşgulüm'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Statistics Row -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card stat-card primary">
                <div class="card-body">
                    <h6 class="text-muted mb-2"><i class="bi bi-calendar-day me-1"></i>Bugün</h6>
                    <h3 class="mb-0"><?php echo $today_stats['cnt'] ?? 0; ?> <small class="text-muted fs-6">teslimat</small></h3>
                    <small class="text-success"><?php echo number_format($today_stats['total'] ?? 0, 2); ?> TL</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card success">
                <div class="card-body">
                    <h6 class="text-muted mb-2"><i class="bi bi-calendar-week me-1"></i>Bu Hafta</h6>
                    <h3 class="mb-0"><?php echo $week_stats['cnt'] ?? 0; ?> <small class="text-muted fs-6">teslimat</small></h3>
                    <small class="text-success"><?php echo number_format($week_stats['total'] ?? 0, 2); ?> TL</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card warning">
                <div class="card-body">
                    <h6 class="text-muted mb-2"><i class="bi bi-star-fill me-1"></i>Ortalama Puan</h6>
                    <h3 class="mb-0"><?php echo $courier_rating; ?> <small class="text-muted fs-6">/ 5.0</small></h3>
                    <small class="text-warning">★★★★☆</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card info">
                <div class="card-body">
                    <h6 class="text-muted mb-2"><i class="bi bi-clock-history me-1"></i>Aktif Teslimat</h6>
                    <h3 class="mb-0"><?php echo ($assigned && $assigned->num_rows > 0) ? $assigned->num_rows : 0; ?></h3>
                    <small class="text-info">Devam ediyor</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Row -->
    <div class="row">
        <!-- Left Column: Assigned & Completed -->
        <div class="col-md-8">
            <!-- Assigned Deliveries -->
            <div class="card mb-3">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-box-seam me-2"></i>Atanmış Teslimatlar</h6>
                </div>
                <div class="card-body">
                    <?php if ($assigned && $assigned->num_rows>0): 
                        $assigned->data_seek(0); // Reset pointer
                    ?>
                        <div class="row">
                            <?php while($d = $assigned->fetch_assoc()): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card delivery-card border-primary" onclick="window.location='courier_delivery.php?id=<?php echo $d['delivery_id']; ?>'">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <span class="badge bg-primary">#<?php echo $d['delivery_id']; ?></span>
                                                <span class="badge status-badge bg-warning text-dark"><?php echo htmlspecialchars($d['status']); ?></span>
                                            </div>
                                            <h6 class="card-title"><?php echo htmlspecialchars($d['store_name']); ?></h6>
                                            <p class="card-text mb-1">
                                                <small><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($d['customer_name']); ?></small>
                                            </p>
                                            <p class="card-text mb-1">
                                                <small><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($d['customer_address'] ?? 'Adres bilgisi yok'); ?></small>
                                            </p>
                                            <p class="card-text">
                                                <small class="text-muted"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($d['assigned_at']); ?></small>
                                            </p>
                                            <?php if (!empty($d['estimated_arrival_time'])): ?>
                                                <small class="text-info"><i class="bi bi-alarm me-1"></i>Tahmini: <?php echo htmlspecialchars($d['estimated_arrival_time']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle me-2"></i>Atanmış teslimat yok.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Completed Deliveries -->
            <div class="card mb-3">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="bi bi-check-circle me-2"></i>Son Tamamlanan Teslimatlar</h6>
                </div>
                <div class="card-body">
                    <?php if ($completed && $completed->num_rows>0): ?>
                        <div class="list-group">
                            <?php while($c = $completed->fetch_assoc()): ?>
                                <a href="courier_delivery.php?id=<?php echo $c['delivery_id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge bg-success me-2">#<?php echo $c['delivery_id']; ?></span>
                                        <strong><?php echo htmlspecialchars($c['store_name']); ?></strong>
                                        <div><small class="text-muted"><i class="bi bi-clock-history me-1"></i><?php echo htmlspecialchars($c['delivered_at'] ?? $c['order_date']); ?></small></div>
                                    </div>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-0">
                            <i class="bi bi-inbox me-2"></i>Tamamlanmış teslimat yok.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Available Deliveries & Reviews -->
        <div class="col-md-4">
            <!-- Available Deliveries -->
            <div class="card mb-3">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Müsait Teslimatlar</h6>
                </div>
                <div class="card-body">
                    <?php if (!$courier['is_available']): ?>
                        <div class="alert alert-secondary mb-0">
                            <i class="bi bi-pause-circle me-2"></i>Meşgul durumdasınız; yeni teslimatlar gösterilmiyor.
                        </div>
                    <?php elseif ($available && $available->num_rows>0): ?>
                        <div class="list-group">
                            <?php while($a = $available->fetch_assoc()): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="badge bg-info">Sipariş #<?php echo $a['order_id']; ?></span>
                                        <span class="badge bg-light text-dark">~3 km</span>
                                    </div>
                                    <h6><?php echo htmlspecialchars($a['store_name']); ?></h6>
                                    <small class="text-muted d-block mb-2"><i class="bi bi-calendar me-1"></i><?php echo htmlspecialchars($a['order_date']); ?></small>
                                    <a href="courier_delivery.php?id=<?php echo $a['delivery_id']; ?>" class="btn btn-sm btn-primary w-100">
                                        <i class="bi bi-hand-thumbs-up me-1"></i>Kabul Et
                                    </a>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-light mb-0">
                            <i class="bi bi-hourglass me-2"></i>Yeni teslimat bekleniyor...
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Reviews -->
            <?php if (!empty($recent_reviews)): ?>
            <div class="card mb-3">
                <div class="card-header bg-warning">
                    <h6 class="mb-0"><i class="bi bi-star me-2"></i>Son Yorumlar</h6>
                </div>
                <div class="card-body">
                    <?php foreach ($recent_reviews as $review): ?>
                        <div class="review-item mb-3 pb-2 border-bottom">
                            <div class="d-flex justify-content-between mb-1">
                                <small class="fw-bold"><?php echo htmlspecialchars($review['full_name']); ?></small>
                                <span class="badge bg-warning text-dark"><?php echo $review['courier_rating']; ?>★</span>
                            </div>
                            <?php if (!empty($review['review_text'])): ?>
                                <small class="text-muted"><?php echo htmlspecialchars($review['review_text']); ?></small>
                            <?php endif; ?>
                            <div><small class="text-muted"><?php echo date('d.m.Y', strtotime($review['created_at'])); ?></small></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-lightning me-2"></i>Hızlı İşlemler</h6>
                </div>
                <div class="card-body">
                    <form method="POST" class="mb-2">
                        <button type="submit" name="toggle_status" class="btn btn-outline-<?php echo $courier['is_available'] ? 'warning' : 'success'; ?> w-100 mb-2">
                            <i class="bi bi-pause-circle me-1"></i><?php echo $courier['is_available'] ? 'Mola Başlat' : 'Molayı Bitir'; ?>
                        </button>
                    </form>
                    
                    <!-- Zone Change Button -->
                    <button type="button" class="btn btn-outline-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#zoneModal">
                        <i class="bi bi-geo-alt me-1"></i>Bölge Değiştir
                    </button>
                    
                    <a href="courier_profile.php" class="btn btn-outline-secondary w-100 mb-2">
                        <i class="bi bi-person-gear me-1"></i>Profil Ayarları
                    </a>
                    <a href="logout.php" class="btn btn-outline-danger w-100">
                        <i class="bi bi-box-arrow-right me-1"></i>Vardiya Sonu / Çıkış
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Zone Change Modal -->
<div class="modal fade" id="zoneModal" tabindex="-1" aria-labelledby="zoneModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="zoneModalLabel"><i class="bi bi-geo-alt me-2"></i>Çalışma Bölgesi Değiştir</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p class="text-muted mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Mevcut bölgenizde teslimat yoksa farklı bir bölgeye geçebilirsiniz. 
                        Seçtiğiniz bölgedeki müsait teslimatlar size gösterilecektir.
                    </p>
                    <div class="alert alert-info">
                        <strong>Mevcut Bölge:</strong> <?php echo htmlspecialchars($zone_name); ?>
                    </div>
                    <label for="new_zone_id" class="form-label"><strong>Yeni Bölge Seçin:</strong></label>
                    <select name="new_zone_id" id="new_zone_id" class="form-select" required>
                        <option value="">-- Bölge Seçin --</option>
                        <?php foreach ($all_zones as $zone): ?>
                            <option value="<?php echo $zone['zone_id']; ?>" <?php echo ($zone['zone_id'] == $courier['current_zone_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($zone['city'] . ' - ' . $zone['zone_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" name="change_zone" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>Bölgeyi Değiştir
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-refresh every 60 seconds
setTimeout(() => location.reload(), 60000);
</script>

</body>
</html>