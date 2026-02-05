<?php
session_start();
require_once 'db.php';

// Check if user is courier
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Get courier detailed info
$stmt = $conn->prepare("SELECT c.*, u.full_name, u.email, u.phone, u.profile_image, z.zone_name, z.city
    FROM Couriers c
    JOIN Users u ON c.user_id = u.user_id
    LEFT JOIN Zones z ON c.current_zone_id = z.zone_id
    WHERE c.user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$courier = $stmt->get_result()->fetch_assoc();

// Get delivery statistics
$stmt = $conn->prepare("SELECT 
    COUNT(*) as total_deliveries,
    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status IN ('assigned', 'picked_up') THEN 1 ELSE 0 END) as ongoing,
    AVG(distance_km) as avg_distance,
    SUM(distance_km) as total_distance
    FROM Deliveries WHERE courier_id = ?");
$stmt->bind_param("i", $courier['courier_id']);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

// Get recent deliveries with ratings
$stmt = $conn->prepare("SELECT d.delivery_id, d.order_id, d.status, d.pickup_time, d.delivery_time, 
    d.estimated_arrival_time, d.distance_km, o.total_price, m.store_name,
    r.courier_rating, r.comment, r.review_text
    FROM Deliveries d
    JOIN Orders o ON d.order_id = o.order_id
    JOIN Merchants m ON o.merchant_id = m.merchant_id
    LEFT JOIN ratings r ON d.order_id = r.order_id
    WHERE d.courier_id = ?
    ORDER BY d.created_at DESC LIMIT 20");
$stmt->bind_param("i", $courier['courier_id']);
$stmt->execute();
$recent_deliveries = $stmt->get_result();

// Get courier ratings summary
$stmt = $conn->prepare("SELECT 
    AVG(courier_rating) as avg_rating,
    COUNT(*) as total_ratings,
    SUM(CASE WHEN courier_rating = 5 THEN 1 ELSE 0 END) as five_star,
    SUM(CASE WHEN courier_rating = 4 THEN 1 ELSE 0 END) as four_star,
    SUM(CASE WHEN courier_rating = 3 THEN 1 ELSE 0 END) as three_star,
    SUM(CASE WHEN courier_rating = 2 THEN 1 ELSE 0 END) as two_star,
    SUM(CASE WHEN courier_rating = 1 THEN 1 ELSE 0 END) as one_star
    FROM ratings WHERE courier_id = ? AND courier_rating IS NOT NULL");
$stmt->bind_param("i", $courier['courier_id']);
$stmt->execute();
$rating_stats = $stmt->get_result()->fetch_assoc();

?>
<!DOCTYPE html>
<html>
<head>
    <title>VGO - Kurye Profil Detayı</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'header.php'; ?>

<div class="container mt-4">
    <div class="row">
        <!-- Profile Info -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <?php if ($courier['profile_image']): ?>
                        <img src="<?php echo htmlspecialchars($courier['profile_image']); ?>" class="rounded-circle mb-3" width="150" height="150">
                    <?php else: ?>
                        <div class="rounded-circle bg-secondary d-inline-block mb-3" style="width:150px;height:150px;line-height:150px;">
                            <i class="fas fa-user fa-5x text-white"></i>
                        </div>
                    <?php endif; ?>
                    <h4><?php echo htmlspecialchars($courier['full_name']); ?></h4>
                    <p class="text-muted">
                        <i class="fas fa-star text-warning"></i> 
                        <?php echo number_format($rating_stats['avg_rating'] ?? 0, 2); ?>/5.00
                        (<?php echo $rating_stats['total_ratings']; ?> değerlendirme)
                    </p>
                    <hr>
                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($courier['email']); ?></p>
                    <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($courier['phone']); ?></p>
                    <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($courier['city'] . ' - ' . $courier['zone_name']); ?></p>
                    <hr>
                    <p><strong>Araç Tipi:</strong> <?php echo strtoupper($courier['vehicle_type']); ?></p>
                    <p><strong>Plaka:</strong> <?php echo htmlspecialchars($courier['vehicle_plate'] ?? 'N/A'); ?></p>
                    <p><strong>Ehliyet No:</strong> <?php echo htmlspecialchars($courier['license_number'] ?? 'N/A'); ?></p>
                    <p>
                        <strong>Durum:</strong> 
                        <?php if ($courier['is_available']): ?>
                            <span class="badge bg-success">Müsait</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Meşgul</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Rating Distribution -->
            <div class="card mt-3">
                <div class="card-header">
                    <h5><i class="fas fa-star"></i> Puan Dağılımı</h5>
                </div>
                <div class="card-body">
                    <?php
                    $total = $rating_stats['total_ratings'] ?: 1;
                    for ($i = 5; $i >= 1; $i--):
                        $count = $rating_stats[$i == 5 ? 'five_star' : ($i == 4 ? 'four_star' : ($i == 3 ? 'three_star' : ($i == 2 ? 'two_star' : 'one_star')))];
                        $percentage = ($count / $total) * 100;
                    ?>
                    <div class="mb-2">
                        <span><?php echo $i; ?> <i class="fas fa-star text-warning"></i></span>
                        <div class="progress" style="height: 20px;">
                            <div class="progress-bar bg-warning" style="width: <?php echo $percentage; ?>%">
                                <?php echo $count; ?>
                            </div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- Statistics & Deliveries -->
        <div class="col-md-8">
            <!-- Statistics Cards -->
            <div class="row mb-3">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h6>Toplam Teslimat</h6>
                            <h3><?php echo $stats['total_deliveries']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h6>Tamamlanan</h6>
                            <h3><?php echo $stats['completed']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <h6>Devam Eden</h6>
                            <h3><?php echo $stats['ongoing']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h6>Ortalama Mesafe</h6>
                            <h3><?php echo number_format($stats['avg_distance'] ?? 0, 1); ?> km</h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Deliveries -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-truck"></i> Son Teslimatlar & Yorumlar</h5>
                </div>
                <div class="card-body">
                    <?php while ($delivery = $recent_deliveries->fetch_assoc()): ?>
                    <div class="card mb-3 border-start border-4 <?php echo $delivery['status'] == 'delivered' ? 'border-success' : 'border-warning'; ?>">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <h6>Sipariş #<?php echo $delivery['order_id']; ?> - <?php echo htmlspecialchars($delivery['store_name']); ?></h6>
                                    <p class="mb-1">
                                        <small class="text-muted">
                                            Mesafe: <?php echo number_format($delivery['distance_km'], 2); ?> km | 
                                            Tutar: ₺<?php echo number_format($delivery['total_price'], 2); ?>
                                        </small>
                                    </p>
                                    <?php if ($delivery['estimated_arrival_time']): ?>
                                    <p class="mb-1">
                                        <small><i class="fas fa-clock"></i> Tahmini Varış: <?php echo date('H:i', strtotime($delivery['estimated_arrival_time'])); ?></small>
                                    </p>
                                    <?php endif; ?>
                                    <p class="mb-0"><span class="badge bg-<?php echo $delivery['status'] == 'delivered' ? 'success' : 'warning'; ?>"><?php echo $delivery['status']; ?></span></p>
                                </div>
                                <div class="col-md-4 text-end">
                                    <?php if ($delivery['courier_rating']): ?>
                                    <div class="rating-display">
                                        <div class="mb-1">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star <?php echo $i <= $delivery['courier_rating'] ? 'text-warning' : 'text-muted'; ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <?php if ($delivery['review_text']): ?>
                                        <small class="text-muted">"<?php echo htmlspecialchars(substr($delivery['review_text'], 0, 50)); ?>..."</small>
                                        <?php endif; ?>
                                    </div>
                                    <?php else: ?>
                                        <small class="text-muted">Henüz değerlendirilmedi</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
