<?php
session_start();
include 'db.php';
@include_once __DIR__ . '/favorites_helper.php';

// Yetki kontrolü: giriş yapmış müşteri (role_id = 4) değilse login sayfasına yönlendir
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];

// Müşteri bilgilerini al
$stmt = $conn->prepare("SELECT customer_id, zone_id FROM Customers WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$customer = $res->fetch_assoc();
$customer_id = $customer ? $customer['customer_id'] : null;
$customer_zone_id = $customer ? $customer['zone_id'] : null;
$stmt->close();

// Favori mağaza id'lerini al
$favMerchantIds = list_favorite_merchants($conn, $user_id);

// Favori mağazaların detaylarını çek
$favoriteMerchants = [];
if (!empty($favMerchantIds)) {
    $ids = implode(',', array_map('intval', $favMerchantIds));
    $query = "SELECT m.merchant_id, m.store_name, m.address, m.city, m.rating_avg, m.opening_time, m.closing_time, 
              z.zone_name, z.city as zone_city
              FROM Merchants m
              LEFT JOIN Zones z ON m.zone_id = z.zone_id
              WHERE m.merchant_id IN ($ids) AND m.is_active = 1
              ORDER BY m.store_name ASC";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $favoriteMerchants[] = $row;
        }
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Favorilerim - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.heart { color: red; font-size: 1.2em; }
.favorite-card { transition: transform 0.2s; }
.favorite-card:hover { transform: translateY(-5px); }
</style>
</head>
<body class="bg-light">
<?php include 'header.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>💝 Favori Mağazalarım</h4>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">← Anasayfa</a>
    </div>

    <?php if (empty($favoriteMerchants)): ?>
        <div class="alert alert-info">
            <h5>Henüz favori mağazanız yok</h5>
            <p>Anasayfadaki mağazalara kalp ❤️ butonuna tıklayarak favorilerinize ekleyebilirsiniz.</p>
            <a href="dashboard.php" class="btn btn-primary">Mağazaları Keşfet</a>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($favoriteMerchants as $m): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card favorite-card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0"><?php echo htmlspecialchars($m['store_name']); ?></h5>
                                <button class="btn btn-sm btn-outline-danger favorite-toggle" data-merchant-id="<?php echo $m['merchant_id']; ?>" title="Favorilerden çıkar">
                                    <span class="heart">♥</span>
                                </button>
                            </div>
                            
                            <p class="text-muted mb-2">
                                <small>📍 <?php echo htmlspecialchars($m['address'] ?? $m['city']); ?></small>
                            </p>
                            
                            <?php if (!empty($m['zone_name'])): ?>
                                <p class="mb-2">
                                    <span class="badge bg-info"><?php echo htmlspecialchars($m['zone_name']); ?></span>
                                </p>
                            <?php endif; ?>
                            
                            <div class="mb-2">
                                <strong>⭐ <?php echo number_format($m['rating_avg'], 1); ?></strong>
                                <span class="text-muted">/ 5.0</span>
                            </div>
                            
                            <?php if (!empty($m['opening_time']) && !empty($m['closing_time'])): ?>
                                <p class="mb-3">
                                    <small class="text-muted">
                                        🕐 <?php echo substr($m['opening_time'], 0, 5); ?> - <?php echo substr($m['closing_time'], 0, 5); ?>
                                    </small>
                                </p>
                            <?php endif; ?>
                            
                            <div class="d-grid gap-2">
                                <a href="merchant.php?id=<?php echo $m['merchant_id']; ?>" class="btn btn-primary">
                                    Menüyü Gör
                                </a>
                                <a href="merchant_menu.php?id=<?php echo $m['merchant_id']; ?>" class="btn btn-outline-secondary btn-sm">
                                    Sipariş Ver
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="mt-4 text-center">
            <p class="text-muted">Toplam <?php echo count($favoriteMerchants); ?> favori mağazanız var</p>
        </div>
    <?php endif; ?>
</div>

<script>
// Favori toggle işlemi
document.querySelectorAll('.favorite-toggle').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const merchantId = this.getAttribute('data-merchant-id');
        const heartSpan = this.querySelector('.heart');
        
        fetch('toggle_favorite.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'merchant_id=' + merchantId
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                if (data.action === 'removed') {
                    // Favorilerden çıkarıldı, kartı kaldır
                    const card = this.closest('.col-md-6, .col-lg-4');
                    if (card) {
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.8)';
                        setTimeout(() => {
                            card.remove();
                            // Eğer hiç favori kalmadıysa sayfayı yenile
                            if (document.querySelectorAll('.favorite-card').length === 0) {
                                location.reload();
                            }
                        }, 300);
                    }
                } else {
                    // Eklendi (bu sayfada olmamalı ama olursa)
                    heartSpan.textContent = '♥';
                }
            } else {
                alert('Bir hata oluştu: ' + (data.error || 'bilinmeyen'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Bağlantı hatası');
        });
    });
});
</script>
</body>
</html>
