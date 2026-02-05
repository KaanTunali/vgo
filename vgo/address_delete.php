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

if ($address_id <= 0) {
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
    exit;
}

// Check ownership
$stmt = $conn->prepare("SELECT address_id FROM Customer_Addresses WHERE address_id = ? AND user_id = ? LIMIT 1");
$stmt->bind_param('ii', $address_id, $user_id);
$stmt->execute();
$own = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$own) { header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php')); exit; }

// Prevent deleting last address
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM Customer_Addresses WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();
if ($cnt <= 1) { header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php')); exit; }

// Delete
$stmt = $conn->prepare("DELETE FROM Customer_Addresses WHERE address_id = ? AND user_id = ?");
$stmt->bind_param('ii', $address_id, $user_id);
$stmt->execute();
$stmt->close();

// Reset active address to default/first
$addr = resolve_active_address($conn, $user_id);
if ($addr) set_active_address($addr);

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
exit;
