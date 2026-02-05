<?php
session_start();
include 'db.php';

$merchant_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($merchant_id <= 0) {
    echo "Geçersiz mağaza.";
    exit;
}

// Mağaza bilgisi
$stmt = $conn->prepare("SELECT m.merchant_id, m.store_name, m.address, m.is_active, m.rating_avg, m.opening_time, m.closing_time, z.zone_name FROM Merchants m LEFT JOIN Zones z ON m.zone_id = z.zone_id WHERE m.merchant_id = ? AND m.is_active = 1 LIMIT 1");
$stmt->bind_param("i", $merchant_id);
$stmt->execute();
$res = $stmt->get_result();
$merchant = $res->fetch_assoc();
$stmt->close();

if (!$merchant) {
    echo "Mağaza bulunamadı.";
    exit;
}

// Ürünleri al
$stmt = $conn->prepare("SELECT product_id, name, description, price, category, image, is_active FROM Products WHERE merchant_id = ? AND is_active = 1 ORDER BY category, name ASC");
$stmt->bind_param("i", $merchant_id);
$stmt->execute();
$products = $stmt->get_result();
$stmt->close();

// Kategori gruplandırması
$grouped_products = [];
while ($p = $products->fetch_assoc()) {
    $category = $p['category'] ?? 'Diğer';
    if (!isset($grouped_products[$category])) {
        $grouped_products[$category] = [];
    }
    $grouped_products[$category][] = $p;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($merchant['store_name']); ?> - Menü</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    .menu-hero {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 40px 0;
        margin-bottom: 40px;
    }
    .category-title {
        font-size: 1.3rem;
        font-weight: 600;
        margin-top: 30px;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 3px solid #667eea;
    }
    .product-card {
        transition: transform 0.3s, box-shadow 0.3s;
        border: none;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .product-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 16px rgba(0,0,0,0.15);
    }
    .product-image {
        height: 200px;
        object-fit: cover;
        border-radius: 12px 12px 0 0;
    }
    .order-cta {
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 1000;
    }
    .star-rating {
        color: #ffc107;
        font-size: 0.9rem;
    }
    .hours-badge {
        display: inline-block;
        background: #28a745;
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
    }
    .hours-badge.closed {
        background: #dc3545;
    }
</style>
</head>
<body class="bg-light">
<?php include 'header.php'; ?>

<div class="menu-hero">
    <div class="container">
        <a href="javascript:history.back()" class="btn btn-light btn-sm mb-3">&larr; Geri Dön</a>
        <div class="row align-items-end">
            <div class="col-md-8">
                <h1 class="mb-0"><?php echo htmlspecialchars($merchant['store_name']); ?></h1>
                <p class="lead mt-2 mb-0">
                    <?php if ($merchant['rating_avg']): ?>
                        <span class="star-rating">⭐ <?php echo number_format($merchant['rating_avg'], 1); ?></span>
                    <?php endif; ?>
                    <?php if ($merchant['zone_name']): ?>
                        <span class="badge bg-white text-dark ms-2"><?php echo htmlspecialchars($merchant['zone_name']); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-md-4 text-md-end">
                <?php 
                    $openingStr = $merchant['opening_time'] ?? '';
                    $closingStr = $merchant['closing_time'] ?? '';
                    $hasHours = !empty($openingStr) && !empty($closingStr);
                    $is_open = false;
                    if ($hasHours) {
                        $opening = strtotime($openingStr);
                        $closing = strtotime($closingStr);
                        $now = strtotime(date('H:i'));
                        $is_open = ($now >= $opening && $now < $closing);
                    }
                ?>
                <span class="hours-badge <?php echo (!$hasHours || !$is_open) ? 'closed' : ''; ?>">
                    <?php echo $hasHours ? ($is_open ? '🟢 Açık' : '🔴 Kapalı') : '⏰ Saat Bilgisi Yok'; ?>
                </span>
                <p class="mt-2 mb-0 text-white-50">
                    <?php echo $hasHours ? (date('H:i', $opening) . ' - ' . date('H:i', $closing)) : 'Saat belirtilmedi'; ?>
                </p>
            </div>
        </div>
        <p class="text-white-50 mt-3 mb-0">
            📍 <?php echo htmlspecialchars($merchant['address']); ?>
        </p>
    </div>
</div>

<div class="container mb-5">
    <?php if (empty($grouped_products)): ?>
        <div class="alert alert-info text-center">
            <h5>Henüz Menü Yok</h5>
            <p>Bu mağazanın menüsü şimdilik hazırlanmıyor. Lütfen daha sonra tekrar kontrol edin.</p>
        </div>
    <?php else: ?>
        <?php foreach ($grouped_products as $category => $items): ?>
            <div class="category-title"><?php echo htmlspecialchars($category); ?></div>
            <div class="row">
                <?php foreach ($items as $p): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card product-card h-100">
                            <?php if (!empty($p['image'])): ?>
                                <img src="<?php echo htmlspecialchars($p['image']); ?>" class="product-image" alt="<?php echo htmlspecialchars($p['name']); ?>">
                            <?php else: ?>
                                <div class="product-image bg-secondary d-flex align-items-center justify-content-center text-white">
                                    🍽️
                                </div>
                            <?php endif; ?>
                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title"><?php echo htmlspecialchars($p['name']); ?></h5>
                                <p class="card-text text-muted small flex-grow-1">
                                    <?php echo htmlspecialchars(substr($p['description'] ?? '', 0, 100)); ?>
                                    <?php if (strlen($p['description'] ?? '') > 100): ?>...<?php endif; ?>
                                </p>
                                <div class="d-flex justify-content-between align-items-center mt-auto">
                                    <span class="h5 mb-0 text-primary"><?php echo number_format($p['price'], 2); ?> ₺</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Sipariş Verme Butonu (Sadece Login Yapan Müşteriler İçin) -->
<?php if (isset($_SESSION['user_id']) && $_SESSION['role_id'] == 4): ?>
    <div class="order-cta">
        <a href="merchant_menu.php?id=<?php echo $merchant_id; ?>" class="btn btn-success btn-lg rounded-circle" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; font-size: 24px;" title="Sipariş Ver">
            🛒
        </a>
    </div>
<?php else: ?>
    <!-- Login Olmayan Ziyaretçiler İçin CTA -->
    <div class="container mb-4">
        <div class="alert alert-primary" role="alert">
            <h5>📦 Sipariş Vermek İstiyorsanız</h5>
            <p class="mb-0">Sipariş vermek için <a href="login.php" class="alert-link">giriş yapınız</a> veya <a href="register.php" class="alert-link">yeni hesap oluşturunuz</a></p>
        </div>
    </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
