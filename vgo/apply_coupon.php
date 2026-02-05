<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

include_once __DIR__ . '/db.php';
include_once __DIR__ . '/coupons_helper.php';

$order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$code = isset($_POST['coupon_code']) ? trim($_POST['coupon_code']) : '';

if (!$order_id || !$code) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing']);
    exit;
}

// Fetch order and compute current subtotal/total (simplified)
$stmt = $conn->prepare("SELECT order_id, user_id, total_price FROM Orders WHERE order_id = ? LIMIT 1");
$stmt->bind_param('i', $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$order) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'order_not_found']); exit; }

// Only allow owner to apply coupon
if (empty($_SESSION['user_id']) || $_SESSION['user_id'] != $order['user_id']) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
}

$coupon = get_coupon_by_code($conn, $code);
$validation = validate_coupon($conn, $coupon, (float)$order['total_price']);
if (!$validation['ok']) {
    echo json_encode(['ok'=>false,'valid'=>false,'reason'=>$validation['reason']]); exit;
}
$discount = calculate_discount($coupon, (float)$order['total_price']);

// perform DB update
$upd = $conn->prepare("UPDATE Orders SET discount_amount = ?, coupon_code = ? WHERE order_id = ?");

// return new totals
$new_total = max(0, (float)$order['total_price'] - $discount);
$upd->bind_param('dsi', $discount, $code, $order_id);
$ok = $upd->execute();
$upd->close();
if (!$ok) { echo json_encode(['ok'=>false,'error'=>'db']); exit; }

// send notification to user
include_once __DIR__ . '/notifications_helper.php';
notify_user($conn, $order['user_id'], "Kupon uygulandı", "Kupon {$code} başarıyla uygulandı. İndirim: {$discount} TL");

echo json_encode(['ok'=>true,'discount'=>round($discount,2),'new_total'=>round($new_total,2),'coupon_code'=>$code]);
