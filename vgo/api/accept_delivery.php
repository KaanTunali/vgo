<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

// Simple API to attempt an atomic accept of a delivery by a courier
$input = $_POST ?: $_GET ?: json_decode(file_get_contents('php://input'), true) ?: [];
$delivery_id = isset($input['delivery_id']) ? (int)$input['delivery_id'] : 0;
$courier_id = isset($input['courier_id']) ? (int)$input['courier_id'] : 0;

if (!$delivery_id || !$courier_id) {
    echo json_encode(['success' => false, 'message' => 'delivery_id and courier_id required']);
    exit;
}

// Atomic update: only set courier_id if it is currently NULL
$stmt = $conn->prepare("UPDATE Deliveries SET courier_id = ?, status = 'assigned', assigned_at = NOW(), accepted_at = NOW() WHERE delivery_id = ? AND courier_id IS NULL");
$stmt->bind_param('ii', $courier_id, $delivery_id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected > 0) {
    // insert event
    $note = 'Assigned via API by courier ' . $courier_id;
    $stmt = $conn->prepare("INSERT INTO Delivery_Events (delivery_id, event_type, timestamp, notes) VALUES (?, 'assigned', NOW(), ?)");
    $stmt->bind_param('is', $delivery_id, $note);
    $stmt->execute();
    $stmt->close();
    // notify via helper
    require_once __DIR__ . '/../notifications_helper.php';
    // fetch customer and merchant user ids
    $stmt = $conn->prepare("SELECT o.order_id, c.user_id AS customer_user_id, m.user_id AS merchant_user_id FROM Deliveries d JOIN Orders o ON d.order_id = o.order_id JOIN Customers c ON o.customer_id = c.customer_id JOIN Merchants m ON o.merchant_id = m.merchant_id WHERE d.delivery_id = ? LIMIT 1");
    $stmt->bind_param('i', $delivery_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $courierName = 'Kurye';
    $stmt = $conn->prepare("SELECT u.full_name FROM Couriers c JOIN Users u ON c.user_id = u.user_id WHERE c.courier_id = ? LIMIT 1");
    $stmt->bind_param('i', $courier_id);
    $stmt->execute();
    $cn = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($cn) $courierName = $cn['full_name'];
    if (!empty($row['customer_user_id'])) {
        notify_user($conn, $row['customer_user_id'], null, $row['order_id'], $delivery_id, 'delivery_assigned', 'Kurye ' . $courierName . ' siparişinizi almaya kabul etti.');
    }
    if (!empty($row['merchant_user_id'])) {
        notify_user($conn, $row['merchant_user_id'], null, $row['order_id'], $delivery_id, 'delivery_assigned', 'Kurye ' . $courierName . ' siparişinizi almaya gidiyor.');
    }

    echo json_encode(['success' => true, 'message' => 'accepted', 'courier_id' => $courier_id]);
    exit;
} else {    // fetch current courier
    $stmt = $conn->prepare("SELECT courier_id, status FROM Deliveries WHERE delivery_id = ? LIMIT 1");
    $stmt->bind_param('i', $delivery_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode(['success' => false, 'message' => 'already assigned', 'current' => $row]);
    exit;
}
