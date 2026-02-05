<?php
session_start();
include 'db.php';
@include_once __DIR__ . '/address_helper.php';

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

// Giriş yapmamış veya müşteri değilse yönlendir
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$merchant_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($merchant_id <= 0) {
    echo "Geçersiz mağaza.";
    exit;
}

// Mağaza bilgisi
$stmt = $conn->prepare("SELECT merchant_id, store_name, address, is_active, zone_id FROM Merchants WHERE merchant_id = ? LIMIT 1");
$stmt->bind_param("i", $merchant_id);
$stmt->execute();
$res = $stmt->get_result();
$merchant = $res->fetch_assoc();
$stmt->close();

if (!$merchant) {
    echo "Mağaza bulunamadı.";
    exit;
}

// Aktif adres ve zone kontrolü
$activeAddress = null;
$active_zone_id = null;
$zone_mismatch = false;
if (isset($_SESSION['user_id']) && $_SESSION['role_id'] == 4) {
    ensure_default_address($conn, $user_id);
    $activeAddress = resolve_active_address($conn, $user_id);
    if ($activeAddress) {
        set_active_address($activeAddress);
        $active_zone_id = $activeAddress['zone_id'] ?? null;
    }
    if ($active_zone_id && $merchant['zone_id'] && $active_zone_id != $merchant['zone_id']) {
        $zone_mismatch = true;
    }
}

// Ürünleri al
$stmt = $conn->prepare("SELECT product_id, name, description, price, category, image, is_active FROM Products WHERE merchant_id = ? AND is_active = 1 ORDER BY name ASC");
$stmt->bind_param("i", $merchant_id);
$stmt->execute();
$products = $stmt->get_result();
$stmt->close();

// Mağaza yorumları (müşteri görebilsin)
$reviews = null;
if (vgo_table_exists($conn, 'ratings')) {
    $ratingsTable = vgo_resolve_table_name($conn, 'ratings');
    $customersTable = vgo_resolve_table_name($conn, 'customers');
    $usersTable = vgo_resolve_table_name($conn, 'users');

    $reviews_stmt = $conn->prepare("SELECT r.merchant_rating, r.review_text, r.created_at, u.full_name
        FROM {$ratingsTable} r
        JOIN {$customersTable} c ON r.customer_id = c.customer_id
        JOIN {$usersTable} u ON c.user_id = u.user_id
        WHERE r.merchant_id = ? AND r.review_text IS NOT NULL AND r.review_text != ''
        ORDER BY r.created_at DESC LIMIT 10");
    if ($reviews_stmt) {
        $reviews_stmt->bind_param('i', $merchant_id);
        $reviews_stmt->execute();
        $reviews = $reviews_stmt->get_result();
        $reviews_stmt->close();
    }
}

// Sepete ürün ekleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $quantity = isset($_POST['quantity']) ? max(1, (int)$_POST['quantity']) : 1;
    
    if ($product_id > 0) {
        // Ürün bilgisini al
        $stmt = $conn->prepare("SELECT product_id, name, price FROM Products WHERE product_id = ? AND merchant_id = ? LIMIT 1");
        $stmt->bind_param("ii", $product_id, $merchant_id);
        $stmt->execute();
        $prod = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($prod) {
            // Aktif sepet al veya oluştur
            $stmt = $conn->prepare("SELECT cart_id FROM Carts WHERE user_id = ? AND merchant_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->bind_param("ii", $user_id, $merchant_id);
            $stmt->execute();
            $cartRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $cart_id = null;
            if ($cartRow) {
                $cart_id = $cartRow['cart_id'];
            } else {
                // Yeni sepet oluştur
                $stmt = $conn->prepare("INSERT INTO Carts (user_id, merchant_id, created_at) VALUES (?, ?, NOW())");
                $stmt->bind_param("ii", $user_id, $merchant_id);
                $stmt->execute();
                $cart_id = $stmt->insert_id;
                $stmt->close();
            }
            
            // Sepette aynı ürün var mı?
            $stmt = $conn->prepare("SELECT item_id, quantity FROM Cart_Items WHERE cart_id = ? AND product_name = ? LIMIT 1");
            $stmt->bind_param("is", $cart_id, $prod['name']);
            $stmt->execute();
            $itemRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($itemRow) {
                // Miktarı artır
                $newQty = $itemRow['quantity'] + $quantity;
                $total = $prod['price'] * $newQty;
                $stmt = $conn->prepare("UPDATE Cart_Items SET quantity = ?, total_price = ? WHERE item_id = ?");
                $stmt->bind_param("idi", $newQty, $total, $itemRow['item_id']);
                $stmt->execute();
                $stmt->close();
            } else {
                // Yeni ürün ekle
                $total_price = $prod['price'] * $quantity;
                $stmt = $conn->prepare("INSERT INTO Cart_Items (cart_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("isidd", $cart_id, $prod['name'], $quantity, $prod['price'], $total_price);
                $stmt->execute();
                $stmt->close();
            }
            
            $success_msg = "'" . $prod['name'] . "' sepete eklendi!";
        }
    }
}
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
<title><?php echo htmlspecialchars($merchant['store_name']); ?> - Sipariş Ver</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    .order-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px 0;
        margin-bottom: 30px;
        position: sticky;
        top: 0;
        z-index: 10;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .category-title {
        font-size: 1.2rem;
        font-weight: 600;
        margin-top: 25px;
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 3px solid #667eea;
    }
    .product-card {
        transition: transform 0.2s, box-shadow 0.2s;
        border: none;
        border-radius: 10px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
    .product-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 12px rgba(0,0,0,0.15);
    }
    .product-image {
        height: 180px;
        object-fit: cover;
        border-radius: 10px 10px 0 0;
    }
    .cart-summary {
        position: sticky;
        bottom: 0;
        background: white;
        border-top: 2px solid #e9ecef;
        padding: 15px 0;
        box-shadow: 0 -2px 8px rgba(0,0,0,0.1);
    }
    .success-message {
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 1050;
        animation: slideIn 0.3s ease-out;
    }
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
</style>
</head>
<body class="bg-light">
<?php include 'header.php'; ?>

<div class="order-header">
    <div class="container">
        <a href="javascript:history.back()" class="btn btn-light btn-sm mb-2">&larr; Geri Dön</a>
        <h2 class="mb-0"><?php echo htmlspecialchars($merchant['store_name']); ?></h2>
        <p class="lead text-white-50 mt-2 mb-0">
            📍 <?php echo htmlspecialchars($merchant['address']); ?>
        </p>
    </div>
</div>

<div class="container mb-5">
    <?php if ($zone_mismatch): ?>
        <div class="alert alert-warning">
            Seçili teslimat adresinizin bölgesi bu mağazayla eşleşmiyor. Sipariş vermek için lütfen adresinizi mağazanın bölgesine uygun bir adresle değiştirin.
        </div>
    <?php endif; ?>

    <?php if(isset($success_msg)): ?>
        <div class="alert alert-success success-message" role="alert">
            <strong>✅ Başarılı!</strong><br>
            <?php echo htmlspecialchars($success_msg); ?>
        </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header">
            <strong>Müşteri Yorumları</strong>
        </div>
        <div class="card-body">
            <?php if ($reviews && $reviews->num_rows > 0): ?>
                <?php while ($review = $reviews->fetch_assoc()): ?>
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($review['full_name']); ?></div>
                                <div class="text-warning" aria-label="Puan">
                                    <?php
                                        $rating = (int)($review['merchant_rating'] ?? 0);
                                        for ($i = 1; $i <= 5; $i++) {
                                            echo ($i <= $rating) ? '★' : '☆';
                                        }
                                    ?>
                                </div>
                            </div>
                            <small class="text-muted"><?php echo !empty($review['created_at']) ? date('d.m.Y', strtotime($review['created_at'])) : ''; ?></small>
                        </div>
                        <div class="mt-2"><?php echo htmlspecialchars($review['review_text']); ?></div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="text-muted text-center">Henüz yorum yapılmamış.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($grouped_products)): ?>
        <div class="alert alert-info text-center">
            <h5>Henüz Menü Yok</h5>
            <p>Bu mağazanın menüsü şimdilik hazırlanmıyor.</p>
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
                                    <?php echo htmlspecialchars(substr($p['description'] ?? '', 0, 90)); ?>
                                    <?php if (strlen($p['description'] ?? '') > 90): ?>...<?php endif; ?>
                                </p>
                                <div class="d-flex justify-content-between align-items-center mt-auto">
                                    <span class="h5 mb-0 text-success fw-bold"><?php echo number_format($p['price'], 2); ?> ₺</span>
                                </div>
                            </div>
                            <div class="card-footer bg-white border-0">
                                <form method="POST" class="d-flex gap-2">
                                    <input type="hidden" name="action" value="add_to_cart">
                                    <input type="hidden" name="product_id" value="<?php echo $p['product_id']; ?>">
                                    <input type="number" name="quantity" value="1" min="1" max="99" class="form-control form-control-sm" style="width: 60px;">
                                    <button type="submit" class="btn btn-success flex-grow-1" <?php echo $zone_mismatch ? 'disabled' : ''; ?>>Ekle</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="cart-summary">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <small class="text-muted">Sipariş vermek için</small><br>
            <strong>Sepetinize Kontrol Edin</strong>
        </div>
        <a href="cart.php" class="btn btn-success btn-lg">
            🛒 Sepete Git
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Success mesajını 4 saniye sonra kapat
    document.addEventListener('DOMContentLoaded', function() {
        const successMsg = document.querySelector('.success-message');
        if (successMsg) {
            setTimeout(function() {
                successMsg.style.display = 'none';
            }, 4000);
        }
    });
</script>
</body>
</html>
