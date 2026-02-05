<?php
session_start();
include 'db.php';
@include_once __DIR__ . '/migrations_runner.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Sepet işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'remove') {
        $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        if ($item_id > 0) {
            $stmt = $conn->prepare("DELETE ci FROM Cart_Items ci JOIN Carts c ON ci.cart_id = c.cart_id WHERE ci.item_id = ? AND c.user_id = ?");
            $stmt->bind_param("ii", $item_id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: cart.php');
        exit;
    }
    
    if ($action === 'update_quantity') {
        $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        $quantity = isset($_POST['quantity']) ? max(1, (int)$_POST['quantity']) : 1;
        
        if ($item_id > 0) {
            // Ürün bilgisini al
            $stmt = $conn->prepare("SELECT ci.unit_price FROM Cart_Items ci JOIN Carts c ON ci.cart_id = c.cart_id WHERE ci.item_id = ? AND c.user_id = ? LIMIT 1");
            $stmt->bind_param("ii", $item_id, $user_id);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($item) {
                $total_price = $item['unit_price'] * $quantity;
                $stmt = $conn->prepare("UPDATE Cart_Items ci JOIN Carts c ON ci.cart_id = c.cart_id SET ci.quantity = ?, ci.total_price = ? WHERE ci.item_id = ? AND c.user_id = ?");
                $stmt->bind_param("idii", $quantity, $total_price, $item_id, $user_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        header('Location: cart.php');
        exit;
    }
    
    if ($action === 'clear') {
        // Aktif sepeti bul (önce dolu sepeti tercih et)
        $stmt = $conn->prepare("SELECT c.cart_id FROM Carts c JOIN Cart_Items ci ON ci.cart_id = c.cart_id WHERE c.user_id = ? ORDER BY c.created_at DESC LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $cart = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$cart) {
            $stmt = $conn->prepare("SELECT cart_id FROM Carts WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $cart = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        
        if ($cart) {
            $stmt = $conn->prepare("DELETE FROM Cart_Items WHERE cart_id = ?");
            $stmt->bind_param("i", $cart['cart_id']);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: cart.php');
        exit;
    }
}

// Aktif sepeti al (önce dolu sepeti tercih et)
$stmt = $conn->prepare("SELECT c.cart_id, c.merchant_id FROM Carts c JOIN Cart_Items ci ON ci.cart_id = c.cart_id WHERE c.user_id = ? ORDER BY c.created_at DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$cartRes = $stmt->get_result();
$cartRow = $cartRes->fetch_assoc();
$stmt->close();

if (!$cartRow) {
    $stmt = $conn->prepare("SELECT cart_id, merchant_id FROM Carts WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $cartRes = $stmt->get_result();
    $cartRow = $cartRes->fetch_assoc();
    $stmt->close();
}

$cartItems = [];
$merchant = null;
$totalPrice = 0;

if ($cartRow) {
    $cart_id = $cartRow['cart_id'];
    
    // Sepet öğelerini al
    $stmt = $conn->prepare("SELECT item_id, product_name, quantity, unit_price, total_price FROM Cart_Items WHERE cart_id = ? ORDER BY item_id DESC");
    $stmt->bind_param("i", $cart_id);
    $stmt->execute();
    $itemRes = $stmt->get_result();
    while ($row = $itemRes->fetch_assoc()) {
        $cartItems[] = $row;
        $totalPrice += $row['total_price'];
    }
    $stmt->close();
    
    // Mağaza bilgisini al
    if ($cartRow['merchant_id']) {
        $stmt = $conn->prepare("SELECT merchant_id, store_name FROM Merchants WHERE merchant_id = ? LIMIT 1");
        $stmt->bind_param("i", $cartRow['merchant_id']);
        $stmt->execute();
        $merchant = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sepet - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4">
    <a href="dashboard.php">&larr; Geri</a>
    <h3 class="mt-3">Sepetim</h3>

    <?php if (count($cartItems) > 0): ?>
        <div class="row">
            <div class="col-md-8">
                <div class="card mb-3">
                    <div class="card-body">
                        <?php if ($merchant): ?>
                            <h5><?php echo htmlspecialchars($merchant['store_name']); ?></h5>
                            <hr>
                        <?php endif; ?>
                        
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Ürün</th>
                                    <th>Birim Fiyat</th>
                                    <th>Miktar</th>
                                    <th>Toplam</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cartItems as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                        <td><?php echo number_format($item['unit_price'], 2); ?> ₺</td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="update_quantity">
                                                <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                                <input type="number" name="quantity" value="<?php echo $item['quantity']; ?>" min="1" class="form-control form-control-sm" style="width: 60px; display: inline-block;">
                                                <button type="submit" class="btn btn-sm btn-secondary">Güncelle</button>
                                            </form>
                                        </td>
                                        <td><?php echo number_format($item['total_price'], 2); ?> ₺</td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">Sil</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h5>Özet</h5>
                        <hr>
                        <div class="mb-3">
                            <strong>Ürün Toplamı:</strong> <br>
                            <?php echo number_format($totalPrice, 2); ?> ₺
                        </div>
                        <div class="mb-3">
                            <strong>Teslimat:</strong> <br>
                            Ücretsiz
                        </div>
                        <hr>
                        <div class="mb-3">
                            <h5><strong><?php echo number_format($totalPrice, 2); ?> ₺</strong></h5>
                        </div>
                        <a href="checkout.php" class="btn btn-primary w-100">Ödemeye Git</a>
                        <form method="POST" class="mt-2">
                            <input type="hidden" name="action" value="clear">
                            <button type="submit" class="btn btn-outline-danger w-100">Sepeti Boşalt</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info mt-3">
            Sepetiniz boş. <a href="dashboard.php">Alışverişe dönün</a>
        </div>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
