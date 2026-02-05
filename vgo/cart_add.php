<?php
session_start();
@include_once __DIR__ . '/db.php';
@include_once __DIR__ . '/migrations_runner.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || ($_SESSION['role_id'] ?? 0) != 4) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'login_required']); exit; }
$user_id = (int)$_SESSION['user_id'];

$merchant_id = isset($_POST['merchant_id']) ? (int)$_POST['merchant_id'] : null;
$name = trim($_POST['product_name'] ?? '');
$q = (int)($_POST['quantity'] ?? 1); if ($q < 1) $q = 1;
$p = (float)($_POST['unit_price'] ?? 0); if ($p < 0) $p = 0;
if ($name === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_product']); exit; }

// Validate merchant_id exists if provided
if ($merchant_id) {
    $stmt = $conn->prepare("SELECT merchant_id FROM Merchants WHERE merchant_id = ? LIMIT 1");
    $stmt->bind_param('i', $merchant_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $stmt->close();
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'invalid_merchant']);
        exit;
    }
    $stmt->close();
}

// find or create cart
$stmt = $conn->prepare("SELECT cart_id, merchant_id FROM Carts WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();

$cart_id = null;
if (!$c) {
    // no cart yet
    $stmt = $conn->prepare("INSERT INTO Carts (user_id, merchant_id) VALUES (?, ?)");
    $stmt->bind_param('ii', $user_id, $merchant_id);
    $stmt->execute();
    $cart_id = $conn->insert_id; $stmt->close();
} else {
    // if mevcut cart başka mağazaya aitse yeni cart aç ve eski cart'ı temizle
    if ($merchant_id && !empty($c['merchant_id']) && (int)$c['merchant_id'] !== $merchant_id) {
        // Clear old cart items first
        $stmt = $conn->prepare("DELETE FROM Cart_Items WHERE cart_id = ?");
        $stmt->bind_param('i', $c['cart_id']);
        $stmt->execute();
        $stmt->close();
        
        // Create new cart for new merchant
        $stmt = $conn->prepare("INSERT INTO Carts (user_id, merchant_id) VALUES (?, ?)");
        $stmt->bind_param('ii', $user_id, $merchant_id);
        $stmt->execute();
        $cart_id = $conn->insert_id; $stmt->close();
    } else {
        $cart_id = (int)$c['cart_id'];
        if ($merchant_id && empty($c['merchant_id'])) {
            $stmt = $conn->prepare("UPDATE Carts SET merchant_id = ? WHERE cart_id = ?");
            $stmt->bind_param('ii', $merchant_id, $cart_id);
            $stmt->execute(); $stmt->close();
        }
    }
}

// varsa aynı ürün: miktarı artır, fiyatı güncelle
$stmt = $conn->prepare("SELECT item_id, quantity, unit_price FROM Cart_Items WHERE cart_id = ? AND product_name = ? LIMIT 1");
$stmt->bind_param('is', $cart_id, $name);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();

$ok = false;
if ($it) {
    $newQty = (int)$it['quantity'] + $q;
    $unitPrice = ($p > 0) ? $p : (float)$it['unit_price'];
    $total = $unitPrice * $newQty;
    $stmt = $conn->prepare("UPDATE Cart_Items SET quantity = ?, unit_price = ?, total_price = ? WHERE item_id = ?");
    $stmt->bind_param('iddi', $newQty, $unitPrice, $total, $it['item_id']);
    $ok = $stmt->execute();
    $stmt->close();
} else {
    $total = $q * $p;
    $stmt = $conn->prepare("INSERT INTO Cart_Items (cart_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('isidd', $cart_id, $name, $q, $p, $total);
    $ok = $stmt->execute();
    $stmt->close();
}

echo json_encode(['ok'=>$ok,'cart_id'=>$cart_id]);
