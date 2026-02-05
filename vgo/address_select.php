<?php
session_start();
include 'db.php';
@include_once __DIR__ . '/address_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    header('Location: login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$address_id = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;

if (!$address_id) {
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
    exit;
}

$stmt = $conn->prepare("SELECT ca.address_id, ca.title, ca.address_line, ca.city, ca.postal_code, ca.zone_id, z.zone_name FROM Customer_Addresses ca LEFT JOIN Zones z ON ca.zone_id = z.zone_id WHERE ca.address_id = ? AND ca.user_id = ? LIMIT 1");
$stmt->bind_param('ii', $address_id, $user_id);
$stmt->execute();
$addr = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($addr) {
    set_active_address($addr);
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
exit;
