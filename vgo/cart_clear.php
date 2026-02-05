<?php
session_start();
@include_once __DIR__ . '/db.php';
@include_once __DIR__ . '/migrations_runner.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || ($_SESSION['role_id'] ?? 0) != 4) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'login_required']); exit; }
$user_id = (int)$_SESSION['user_id'];

// find active cart (prefer one with items)
$stmt = $conn->prepare("SELECT c.cart_id FROM Carts c JOIN Cart_Items ci ON ci.cart_id = c.cart_id WHERE c.user_id = ? ORDER BY c.created_at DESC LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$c) {
	$stmt = $conn->prepare("SELECT cart_id FROM Carts WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
	$stmt->bind_param('i', $user_id);
	$stmt->execute();
	$c = $stmt->get_result()->fetch_assoc();
	$stmt->close();
}
if (!$c) { echo json_encode(['ok'=>true,'message'=>'no_cart']); exit; }
$cart_id = (int)$c['cart_id'];

$conn->query("DELETE FROM Cart_Items WHERE cart_id = " . (int)$cart_id);
$ok = true;

echo json_encode(['ok'=>$ok]);
