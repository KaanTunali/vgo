<?php
session_start();
@include_once __DIR__ . '/db.php';
@include_once __DIR__ . '/migrations_runner.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || ($_SESSION['role_id'] ?? 0) != 4) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'login_required']); exit; }
$user_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("SELECT c.cart_id, c.merchant_id FROM Carts c JOIN Cart_Items ci ON ci.cart_id = c.cart_id WHERE c.user_id = ? ORDER BY c.created_at DESC LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$c) {
	// fallback: latest cart even if empty
	$stmt = $conn->prepare("SELECT cart_id, merchant_id FROM Carts WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
	$stmt->bind_param('i', $user_id);
	$stmt->execute();
	$c = $stmt->get_result()->fetch_assoc();
	$stmt->close();
}
if (!$c) { echo json_encode(['ok'=>true,'items'=>[],'total'=>0]); exit; }
$cart_id = (int)$c['cart_id'];

$stmt = $conn->prepare("SELECT item_id, product_name, quantity, unit_price, total_price FROM Cart_Items WHERE cart_id = ?");
$stmt->bind_param('i', $cart_id);
$stmt->execute();
$res = $stmt->get_result();
$items = []; $total = 0.0;
while ($r = $res->fetch_assoc()) { $items[] = $r; $total += (float)$r['total_price']; }
$stmt->close();

echo json_encode(['ok'=>true,'cart_id'=>$cart_id,'merchant_id'=>$c['merchant_id'],'items'=>$items,'total'=>round($total,2)]);
