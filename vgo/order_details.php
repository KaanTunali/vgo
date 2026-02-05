<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!function_exists('table_exists')) {
    function table_exists(mysqli $conn, string $table): bool {
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

if (!function_exists('column_exists')) {
    function column_exists(mysqli $conn, string $table, string $column): bool {
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

// Status label helper (TR)
function status_label($status){
    switch ($status) {
        case 'pending': return 'Beklemede';
        case 'accepted': return 'Onaylandı';
        case 'preparing': return 'Hazırlanıyor';
        case 'ready': return 'Hazır';
        case 'delivering': return 'Yolda';
        case 'on_delivery': return 'Yolda';
        case 'delivered': return 'Teslim edildi';
        case 'cancelled': return 'İptal edildi';
        default: return $status;
    }
}

if ($order_id <= 0) {
    echo "Geçersiz sipariş ID.";
    exit;
}

// Müşteriye ait sipariş mi kontrol et - Direct SQL query
$hasDeliveries = table_exists($conn, 'Deliveries');
$hasDeliveryEvents = table_exists($conn, 'Delivery_Events');
$hasRatings = table_exists($conn, 'Ratings');

$hasOrdersDeliveryCost = column_exists($conn, 'Orders', 'delivery_cost');
$hasOrdersDiscount = column_exists($conn, 'Orders', 'discount_amount');
$hasMerchantsPhone = column_exists($conn, 'Merchants', 'phone');

// Check Deliveries columns
$hasDeliveryPickedUpAt = $hasDeliveries ? column_exists($conn, 'Deliveries', 'picked_up_at') : false;
$hasDeliveryDeliveredAt = $hasDeliveries ? column_exists($conn, 'Deliveries', 'delivered_at') : false;
$hasDeliveryEstimatedArrival = $hasDeliveries ? column_exists($conn, 'Deliveries', 'estimated_arrival_time') : false;

$selectDiscount = $hasOrdersDiscount ? 'o.discount_amount' : '0 AS discount_amount';
$selectDeliveryCost = $hasOrdersDeliveryCost ? 'o.delivery_cost' : '0 AS delivery_cost';
$selectMerchantPhone = $hasMerchantsPhone ? 'm.phone AS merchant_phone' : "'' AS merchant_phone";

$selectDeliveryFields = '';
if ($hasDeliveries) {
    $deliveryFields = ['d.delivery_id', 'd.status AS delivery_status'];
    if ($hasDeliveryPickedUpAt) $deliveryFields[] = 'd.picked_up_at'; else $deliveryFields[] = 'NULL AS picked_up_at';
    if ($hasDeliveryDeliveredAt) $deliveryFields[] = 'd.delivered_at'; else $deliveryFields[] = 'NULL AS delivered_at';
    if ($hasDeliveryEstimatedArrival) $deliveryFields[] = 'd.estimated_arrival_time'; else $deliveryFields[] = 'NULL AS estimated_arrival_time';
    $selectDeliveryFields = implode(', ', $deliveryFields);
} else {
    $selectDeliveryFields = "NULL AS delivery_id, NULL AS delivery_status, NULL AS picked_up_at, NULL AS delivered_at, NULL AS estimated_arrival_time";
}

$joinDeliveries = $hasDeliveries ? "LEFT JOIN Deliveries d ON o.order_id = d.order_id" : "";

function vgo_clear_call_results(mysqli $conn): void {
    while ($conn->more_results() && $conn->next_result()) {
        $r = $conn->store_result();
        if ($r) $r->free();
    }
}

try {
    $stmt = $conn->prepare('CALL GetOrderSummaryForUser(?, ?)');
    if (!$stmt) {
        echo "Sipariş detayı alınamadı (sorgu hazırlanamadı).";
        exit;
    }
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $order = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    vgo_clear_call_results($conn);
} catch (mysqli_sql_exception $e) {
    echo "Sipariş detayı alınamadı: " . htmlspecialchars($e->getMessage());
    exit;
}

if (!$order) {
    echo "Bu sipariş size ait değil veya bulunamadı.";
    exit;
}

// Sipariş öğelerini al
$stmt = $conn->prepare('CALL GetOrderItemsByOrder(?)');
$stmt->bind_param('i', $order_id);
$stmt->execute();
$items = $stmt->get_result();
$stmt->close();
vgo_clear_call_results($conn);

// Eğer teslimat varsa, teslimat ve olayları al
$delivery = null;
$events = [];

if ($hasDeliveries) {
    try {
        $stmt = $conn->prepare('CALL GetDeliveryByOrderId(?)');
        if ($stmt) {
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $delivery = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            vgo_clear_call_results($conn);
        }
    } catch (mysqli_sql_exception $e) {
        $delivery = null;
    }
}

if ($delivery && $hasDeliveryEvents) {
    try {
        $stmt = $conn->prepare('CALL GetDeliveryEventsByDeliveryId(?)');
        if ($stmt) {
            $stmt->bind_param("i", $delivery['delivery_id']);
            $stmt->execute();
            $ev = $stmt->get_result();
            if ($ev) {
                while ($r = $ev->fetch_assoc()) {
                    $events[] = $r;
                }
            }
            $stmt->close();
            vgo_clear_call_results($conn);
        }
    } catch (mysqli_sql_exception $e) {
        $events = [];
    }
}

// Merchant notes (Order_Notes)
$orderNotes = [];
if (table_exists($conn, 'Order_Notes')) {
    try {
        $stmt = $conn->prepare('CALL GetOrderNotesByOrderId(?)');
        if ($stmt) {
            $stmt->bind_param('i', $order_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $orderNotes[] = $r;
                }
            }
            $stmt->close();
            vgo_clear_call_results($conn);
        }
    } catch (mysqli_sql_exception $e) {
        $orderNotes = [];
    }
}

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sipariş Detayı - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4">
    <a href="dashboard.php">&larr; Geri</a>
    <div class="card mt-3">
        <div class="card-body">
            <h5><?php echo htmlspecialchars($order['store_name']); ?> - Sipariş #<?php echo $order['order_id']; ?></h5>
            <p>Durum: <strong><?php echo htmlspecialchars(status_label($order['status'])); ?></strong></p>
            <p>Tutar: <strong><?php echo number_format($order['total_price'],2); ?> ₺</strong></p>
            <p>Tarih: <?php echo htmlspecialchars($order['order_date']); ?></p>
            <hr>
            <h6>Ürünler</h6>
            <ul class="list-group mb-3">
                <?php while ($it = $items->fetch_assoc()): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <div>
                            <?php echo htmlspecialchars($it['product_name']); ?> x<?php echo (int)$it['quantity']; ?>
                        </div>
                        <div><?php echo number_format($it['total_price'],2); ?> ₺</div>
                    </li>
                <?php endwhile; ?>
            </ul>

            <?php if (!empty($orderNotes)): ?>
                <hr>
                <h6>Mağaza Notları</h6>
                <ul class="list-group mb-3">
                    <?php foreach ($orderNotes as $n): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between">
                                <strong><?php echo htmlspecialchars($n['full_name'] ?? 'Mağaza'); ?></strong>
                                <small class="text-muted"><?php echo htmlspecialchars($n['created_at']); ?></small>
                            </div>
                            <div><?php echo nl2br(htmlspecialchars($n['message'])); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($delivery): ?>
                <hr>
                <h6>Teslimat Durumu: <small><?php echo htmlspecialchars($delivery['status']); ?></small></h6>
                <?php if (!empty($delivery['estimated_arrival_time'])): ?>
                    <p class="text-success mb-2">
                        <strong>Tahmini Varış:</strong>
                        <?php echo htmlspecialchars(date('H:i', strtotime($delivery['estimated_arrival_time']))); ?>
                        (<?php echo max(0, (int)ceil((strtotime($delivery['estimated_arrival_time']) - time()) / 60)); ?> dk)
                    </p>
                <?php endif; ?>
                <ul class="list-group mb-3">
                    <?php foreach ($events as $e): ?>
                        <li class="list-group-item"><?php echo htmlspecialchars($e['timestamp']); ?> — <?php echo htmlspecialchars($e['event_type']); ?> <?php if (!empty($e['notes'])) echo '- ' . htmlspecialchars($e['notes']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="mt-3">
                <p>İndirim: <strong id="discount_amount"><?php echo number_format($order['discount_amount'] ?? 0,2); ?> ₺</strong></p>
                <p>Toplam: <strong id="total_amount"><?php echo number_format($order['total_price'] - ($order['discount_amount'] ?? 0),2); ?> ₺</strong></p>
            </div>

            <a href="orders.php" class="btn btn-secondary">Tüm Siparişler</a>
            <a href="support.php?new=1&category=<?php echo urlencode('Delivery'); ?>&subject=<?php echo urlencode('Sipariş Sorunu'); ?>&order_id=<?php echo $order_id; ?><?php echo $delivery ? '&delivery_id='.$delivery['delivery_id'] : ''; ?>" class="btn btn-warning">
                <i class="bi bi-headset"></i> Yardım İste
            </a>
            
            <?php if ($delivery && $delivery['status'] == 'delivered' && $hasRatings): ?>
                <?php
                // Check if already rated
                $check = $conn->prepare("SELECT rating_id FROM ratings WHERE order_id = ?");
                $already_rated = false;
                if ($check) {
                    $check->bind_param("i", $order_id);
                    $check->execute();
                    $already_rated = $check->get_result()->num_rows > 0;
                }
                ?>
                
                <?php if (!$already_rated): ?>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#reviewModal">
                        <i class="bi bi-star"></i> Değerlendir
                    </button>
                <?php else: ?>
                    <span class="badge bg-success">Değerlendirildi</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Siparişi Değerlendir</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="reviewForm">
                    <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Restoran Puanı</label>
                        <div class="star-rating" data-rating-target="merchant_rating">
                            <i class="bi bi-star" data-value="1"></i>
                            <i class="bi bi-star" data-value="2"></i>
                            <i class="bi bi-star" data-value="3"></i>
                            <i class="bi bi-star" data-value="4"></i>
                            <i class="bi bi-star" data-value="5"></i>
                        </div>
                        <input type="hidden" name="merchant_rating" id="merchant_rating" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Kurye Puanı <span class="text-muted">(İsteğe Bağlı)</span></label>
                        <div class="star-rating" data-rating-target="courier_rating">
                            <i class="bi bi-star" data-value="1"></i>
                            <i class="bi bi-star" data-value="2"></i>
                            <i class="bi bi-star" data-value="3"></i>
                            <i class="bi bi-star" data-value="4"></i>
                            <i class="bi bi-star" data-value="5"></i>
                        </div>
                        <input type="hidden" name="courier_rating" id="courier_rating">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Yorumunuz (İsteğe Bağlı)</label>
                        <textarea name="review_text" class="form-control" rows="4" placeholder="Deneyiminizi paylaşın..."></textarea>
                    </div>
                    
                    <div class="alert alert-info" id="reviewMessage" style="display:none;"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-success" id="submitReview">Gönder</button>
            </div>
        </div>
    </div>
</div>

<style>
.star-rating i {
    font-size: 2rem;
    color: #ddd;
    cursor: pointer;
    transition: color 0.2s;
}
.star-rating i.active,
.star-rating i:hover {
    color: #ffc107;
}
</style>

<script>
// Star rating functionality
document.querySelectorAll('.star-rating').forEach(container => {
    const targetInput = document.getElementById(container.dataset.ratingTarget);
    const stars = container.querySelectorAll('i');
    
    stars.forEach(star => {
        star.addEventListener('click', () => {
            const value = star.dataset.value;
            targetInput.value = value;
            
            stars.forEach(s => {
                if (s.dataset.value <= value) {
                    s.classList.add('active');
                } else {
                    s.classList.remove('active');
                }
            });
        });
        
        star.addEventListener('mouseenter', () => {
            const value = star.dataset.value;
            stars.forEach(s => {
                if (s.dataset.value <= value) {
                    s.style.color = '#ffc107';
                } else {
                    s.style.color = '#ddd';
                }
            });
        });
    });
    
    container.addEventListener('mouseleave', () => {
        const currentValue = targetInput.value;
        stars.forEach(s => {
            if (currentValue && s.dataset.value <= currentValue) {
                s.style.color = '#ffc107';
            } else {
                s.style.color = '#ddd';
            }
        });
    });
});

// Submit review
document.getElementById('submitReview').addEventListener('click', () => {
    const form = document.getElementById('reviewForm');
    const formData = new FormData(form);
    
    if (!formData.get('merchant_rating')) {
        document.getElementById('reviewMessage').style.display = 'block';
        document.getElementById('reviewMessage').className = 'alert alert-danger';
        document.getElementById('reviewMessage').textContent = 'Lütfen restoran için puan verin.';
        return;
    }
    
    fetch('submit_review.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('reviewMessage').style.display = 'block';
        if (data.success) {
            document.getElementById('reviewMessage').className = 'alert alert-success';
            document.getElementById('reviewMessage').textContent = data.message;
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            document.getElementById('reviewMessage').className = 'alert alert-danger';
            document.getElementById('reviewMessage').textContent = data.message;
        }
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>