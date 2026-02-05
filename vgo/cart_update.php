<?php
session_start();
@include_once __DIR__ . '/db.php';
@include_once __DIR__ . '/migrations_runner.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || ($_SESSION['role_id'] ?? 0) != 4) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'login_required']); exit; }
$user_id = (int)$_SESSION['user_id'];

$item_id = (int)($_POST['item_id'] ?? 0);
$quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : null;
$unit_price = isset($_POST['unit_price']) ? (float)$_POST['unit_price'] : null;
$remove = isset($_POST['remove']) ? (bool)$_POST['remove'] : false;
if (!$item_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_item']); exit; }

// ensure item belongs to user's cart
$stmt = $conn->prepare("SELECT ci.item_id, ci.quantity, ci.unit_price, ci.total_price FROM Cart_Items ci JOIN Carts c ON ci.cart_id=c.cart_id WHERE ci.item_id=? AND c.user_id=? LIMIT 1");
$stmt->bind_param('ii', $item_id, $user_id);
$stmt->execute();
$it = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$it) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

if ($remove) {
    $stmt = $conn->prepare("DELETE FROM Cart_Items WHERE item_id = ?");
    $stmt->bind_param('i', $item_id);
    $ok = $stmt->execute(); $stmt->close();
    echo json_encode(['ok'=>$ok,'action'=>'removed']); exit;
}

if ($quantity !== null || $unit_price !== null) {
    $quantity = ($quantity !== null) ? max(1,$quantity) : (int)$it['quantity'];
    $unit_price = ($unit_price !== null) ? max(0,$unit_price) : (float)$it['unit_price'];
    $total = $quantity * $unit_price;
    $stmt = $conn->prepare("UPDATE Cart_Items SET quantity=?, unit_price=?, total_price=? WHERE item_id=?");
    $stmt->bind_param('iddi', $quantity, $unit_price, $total, $item_id);
    $ok = $stmt->execute(); $stmt->close();
    echo json_encode(['ok'=>$ok,'action'=>'updated']); exit;
}

echo json_encode(['ok'=>false,'error'=>'no_change']);
