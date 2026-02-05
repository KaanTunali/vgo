<?php
session_start();
include 'db.php';
@include_once __DIR__ . '/favorites_helper.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo "Geçersiz mağaza ID.";
    exit;
}

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

function vgo_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

$merchantsTable = vgo_resolve_table_name($conn, 'merchants');
$ratingsTable = vgo_resolve_table_name($conn, 'ratings');
$customersTable = vgo_resolve_table_name($conn, 'customers');
$usersTable = vgo_resolve_table_name($conn, 'users');

$stmt = $conn->prepare("SELECT merchant_id, store_name, address, city, opening_time, closing_time, rating_avg, logo_url, total_reviews FROM {$merchantsTable} WHERE merchant_id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$m = $res->fetch_assoc();
$stmt->close();

if (!$m) {
    echo "Mağaza bulunamadı.";
    exit;
}

// Get recent reviews for this merchant
$reviews = null;
if (vgo_table_exists($conn, 'ratings')) {
    $reviews_stmt = $conn->prepare("SELECT r.merchant_rating, r.review_text, r.created_at, u.full_name 
        FROM {$ratingsTable} r 
        JOIN {$customersTable} c ON r.customer_id = c.customer_id
        JOIN {$usersTable} u ON c.user_id = u.user_id
        WHERE r.merchant_id = ? AND r.review_text IS NOT NULL AND r.review_text != ''
        ORDER BY r.created_at DESC LIMIT 10");
    if ($reviews_stmt) {
        $reviews_stmt->bind_param("i", $id);
        $reviews_stmt->execute();
        $reviews = $reviews_stmt->get_result();
        $reviews_stmt->close();
    }
}

// Not: Bu örnekte mağaza menüsü yok, sadece bilgi gösteriyoruz.
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($m['store_name']); ?> - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4">
    <a href="dashboard.php">&larr; Geri</a>
    <div class="card mt-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 text-center">
                    <?php if (!empty($m['logo_url'])): ?>
                        <img src="<?php echo htmlspecialchars($m['logo_url']); ?>" alt="Logo" class="img-fluid rounded mb-3" style="max-height: 150px;">
                    <?php else: ?>
                        <div class="bg-secondary rounded d-flex align-items-center justify-content-center" style="height: 150px;">
                            <i class="bi bi-shop" style="font-size: 4rem; color: white;"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-9">
                    <h4><?php echo htmlspecialchars($m['store_name']); ?></h4>
                    <p><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($m['address']); ?> — <?php echo htmlspecialchars($m['city']); ?></p>
                    <p><i class="bi bi-clock"></i> Çalışma Saatleri:
                        <?php if (!empty($m['opening_time']) && !empty($m['closing_time'])): ?>
                            <?php echo htmlspecialchars(substr($m['opening_time'], 0, 5)); ?> - <?php echo htmlspecialchars(substr($m['closing_time'], 0, 5)); ?>
                        <?php else: ?>
                            Saat belirtilmedi
                        <?php endif; ?>
                    </p>
                    <p>
                        <i class="bi bi-star-fill text-warning"></i> 
                        <strong><?php echo number_format($m['rating_avg'],1); ?></strong> / 5.0 
                        <span class="text-muted">(<?php echo $m['total_reviews']; ?> değerlendirme)</span>
                    </p>
                    <hr>
                    <button id="merchant_fav_btn" class="btn btn-outline-danger" data-merchant-id="<?php echo $m['merchant_id']; ?>">
                        <span id="merchant_heart"><?php echo (is_file(__DIR__.'/favorites_helper.php') && is_favorite_merchant($conn, (int)($_SESSION['user_id'] ?? 0), $m['merchant_id'])) ? '♥' : '♡'; ?></span>
                        <span class="ms-1">Favori</span>
                    </button>
                    <a href="merchant_menu.php?id=<?php echo $m['merchant_id']; ?>" class="btn btn-success">Hemen Sipariş Ver</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Reviews Section -->
    <div class="card mt-3">
        <div class="card-header">
            <h5><i class="bi bi-chat-left-text"></i> Müşteri Yorumları</h5>
        </div>
        <div class="card-body">
            <?php if ($reviews && $reviews->num_rows > 0): ?>
                <?php while ($review = $reviews->fetch_assoc()): ?>
                <div class="mb-3 pb-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1"><?php echo htmlspecialchars($review['full_name']); ?></h6>
                            <div class="mb-2">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="bi bi-star<?php echo $i <= $review['merchant_rating'] ? '-fill' : ''; ?> text-warning"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <small class="text-muted"><?php echo date('d.m.Y', strtotime($review['created_at'])); ?></small>
                    </div>
                    <p class="mb-0"><?php echo htmlspecialchars($review['review_text']); ?></p>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p class="text-muted text-center">Henüz yorum yapılmamış.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.getElementById('merchant_fav_btn').addEventListener('click', function(){
    var id = this.dataset.merchantId;
    var heart = document.getElementById('merchant_heart');
    fetch('toggle_favorite.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'merchant_id=' + encodeURIComponent(id)
    }).then(r=>r.json()).then(function(json){
        if (!json.ok) return alert('Hata: ' + (json.error||''));
        heart.textContent = (json.action === 'added') ? '♥' : '♡';
    }).catch(function(){ alert('İstek başarısız.'); });
});
</script>

</body>
</html>