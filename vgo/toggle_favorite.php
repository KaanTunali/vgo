<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }
include_once __DIR__ . '/db.php';
@include_once __DIR__ . '/migrations_runner.php';
include_once __DIR__ . '/favorites_helper.php';

if (empty($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'login_required']); exit; }
$user_id = (int)$_SESSION['user_id'];

$merchant_id = isset($_POST['merchant_id']) ? (int)$_POST['merchant_id'] : 0;
$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

if ($merchant_id) {
    $res = toggle_favorite_merchant($conn, $user_id, $merchant_id);
    if ($res === false) { echo json_encode(['ok'=>false,'error'=>'db']); exit; }
    // optional: notify
    @include_once __DIR__ . '/notifications_helper.php';
    if ($res === 'added' && function_exists('notify_user')) {
        notify_user($conn, $user_id, null, null, null, 'favorite_added', 'Mağaza favorilere eklendi.');
    }
    echo json_encode(['ok'=>true,'action'=>$res,'merchant_id'=>$merchant_id]);
    exit;
}

// product favorites not yet implemented in UI
if ($product_id) { echo json_encode(['ok'=>false,'error'=>'not_implemented']); exit; }

http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing']);
