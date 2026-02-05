<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$address_id = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;
$title = trim($_POST['title'] ?? '');
$address_line = trim($_POST['address_line'] ?? '');
$zone_id = isset($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;

if ($address_id <= 0 || $title === '' || $address_line === '' || !$zone_id) {
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'addresses.php'));
    exit;
}

// Ownership check
$stmt = $conn->prepare("SELECT address_id FROM Customer_Addresses WHERE address_id = ? AND user_id = ? LIMIT 1");
$stmt->bind_param('ii', $address_id, $user_id);
$stmt->execute();
$own = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$own) { header('Location: addresses.php'); exit; }

// Get city from zone
$stmt = $conn->prepare("SELECT city FROM Zones WHERE zone_id = ? LIMIT 1");
$stmt->bind_param('i', $zone_id);
$stmt->execute();
$zoneRes = $stmt->get_result()->fetch_assoc();
$stmt->close();
$city = $zoneRes ? $zoneRes['city'] : '';

if (!$city) {
    header('Location: addresses.php');
    exit;
}

$stmt = $conn->prepare("UPDATE Customer_Addresses SET title=?, address_line=?, zone_id=?, city=? WHERE address_id=? AND user_id=?");
$stmt->bind_param('ssissi', $title, $address_line, $zone_id, $city, $address_id, $user_id);
$stmt->execute();
$stmt->close();

// Set active to edited address
$_SESSION['selected_address_id'] = $address_id;
$_SESSION['selected_zone_id'] = $zone_id;
$_SESSION['selected_address_title'] = $title;

header('Location: addresses.php');
exit;
