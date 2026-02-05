<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

// Check if user is logged in and is a customer
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek']);
    exit;
}

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

$order_id = $_POST['order_id'] ?? null;
$merchant_rating = $_POST['merchant_rating'] ?? null;
$courier_rating = $_POST['courier_rating'] ?? null;
$review_text = trim($_POST['review_text'] ?? '');

$order_id = (int)$order_id;
$merchant_rating = (int)$merchant_rating;

// courier_rating is optional
$courier_rating = ($courier_rating === null || $courier_rating === '') ? null : (int)$courier_rating;

if ($merchant_rating < 1 || $merchant_rating > 5) {
    echo json_encode(['success' => false, 'message' => 'Restoran puanı 1 ile 5 arasında olmalı']);
    exit;
}
if ($courier_rating !== null && ($courier_rating < 1 || $courier_rating > 5)) {
    echo json_encode(['success' => false, 'message' => 'Kurye puanı 1 ile 5 arasında olmalı']);
    exit;
}

if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Sipariş ID gerekli']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$customer_id = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : 0;
if ($customer_id <= 0) {
    $customersTable = vgo_resolve_table_name($conn, 'customers');
    $stmt = $conn->prepare("SELECT customer_id FROM {$customersTable} WHERE user_id = ? LIMIT 1");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Müşteri bilgisi alınamadı']);
        exit;
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $customer_id = (int)($row['customer_id'] ?? 0);
}
if ($customer_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Müşteri kaydı bulunamadı']);
    exit;
}

// Verify the order belongs to this customer and is delivered
$ordersTable = vgo_resolve_table_name($conn, 'orders');
$deliveriesTable = vgo_resolve_table_name($conn, 'deliveries');
$stmt = $conn->prepare("SELECT o.order_id, o.customer_id, o.merchant_id, d.courier_id 
    FROM {$ordersTable} o
    JOIN {$deliveriesTable} d ON o.order_id = d.order_id
    WHERE o.order_id = ? AND o.customer_id = ? AND d.status = 'delivered'");
$stmtOk = (bool)$stmt;
if (!$stmtOk) {
    echo json_encode(['success' => false, 'message' => 'Teslimat bilgisi alınamadı (veritabanı şeması eksik olabilir)']);
    exit;
}
$stmt->bind_param("ii", $order_id, $customer_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if ($stmt) {
    $stmt->close();
}

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Sipariş bulunamadı veya henüz teslim edilmedi']);
    exit;
}

// Check if already rated
$check = $conn->prepare("SELECT rating_id FROM ratings WHERE order_id = ?");
$checkOk = (bool)$check;
if (!$checkOk) {
    echo json_encode(['success' => false, 'message' => 'Değerlendirme sistemi aktif değil (Ratings tablosu yok)']);
    exit;
}
$check->bind_param("i", $order_id);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Bu sipariş zaten değerlendirildi']);
    exit;
}
$check->close();

// Insert rating
$stmt = $conn->prepare("INSERT INTO ratings (order_id, customer_id, merchant_id, courier_id, 
    merchant_rating, courier_rating, review_text, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
$stmtInsOk = (bool)$stmt;
if (!$stmtInsOk) {
    echo json_encode(['success' => false, 'message' => 'Değerlendirme kaydedilemedi']);
    exit;
}
$stmt->bind_param("iiiiiis", 
    $order_id, 
    $customer_id, 
    $order['merchant_id'], 
    $order['courier_id'],
    $merchant_rating,
    $courier_rating,
    $review_text
);

if ($stmt->execute()) {
    $stmt->close();

    // Update merchant aggregates
    $merchantsTable = vgo_resolve_table_name($conn, 'merchants');
    $stmt = $conn->prepare("UPDATE {$merchantsTable} SET 
        total_reviews = (SELECT COUNT(*) FROM ratings WHERE merchant_id = ?),
        rating_avg = (SELECT IFNULL(AVG(merchant_rating), 0) FROM ratings WHERE merchant_id = ?)
        WHERE merchant_id = ?");
    if ($stmt) {
        $mid = (int)$order['merchant_id'];
        $stmt->bind_param('iii', $mid, $mid, $mid);
        $stmt->execute();
        $stmt->close();
    }

    // Update courier aggregates
    if (!empty($order['courier_id'])) {
        $couriersTable = vgo_resolve_table_name($conn, 'couriers');
        $stmt = $conn->prepare("UPDATE {$couriersTable} SET 
            rating_avg = (SELECT IFNULL(AVG(courier_rating), 0) FROM ratings WHERE courier_id = ?)
            WHERE courier_id = ?");
        if ($stmt) {
            $cid = (int)$order['courier_id'];
            $stmt->bind_param('ii', $cid, $cid);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    echo json_encode(['success' => true, 'message' => 'Değerlendirmeniz kaydedildi']);
} else {
    echo json_encode(['success' => false, 'message' => 'Bir hata oluştu: ' . $conn->error]);
}
?>
