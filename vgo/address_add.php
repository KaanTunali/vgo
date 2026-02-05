<?php
session_start();
include 'db.php';
@include_once __DIR__ . '/address_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$title = trim($_POST['title'] ?? 'Adres');
$address_line = trim($_POST['address_line'] ?? '');
$city = trim((string)($_POST['city'] ?? ''));
$postal = ''; // Posta kodu artık gerekli değil
$zone_id = null;
if (isset($_POST['zone_id'])) {
    $raw = trim((string)$_POST['zone_id']);
    if ($raw !== '' && ctype_digit($raw)) {
        $zi = (int)$raw;
        if ($zi > 0) {
            $zone_id = $zi;
        }
    }
}

// City must match the chosen zone. Previously this was hard-coded to Istanbul,
// which caused merchants in other cities (e.g. İzmir/Balçova) to appear empty.
if (!empty($zone_id)) {
    $stmt = $conn->prepare("SELECT city FROM Zones WHERE zone_id = ? LIMIT 1");
    $stmt->bind_param('i', $zone_id);
    $stmt->execute();
    $zr = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($zr && !empty($zr['city'])) {
        $city = $zr['city'];
    } else {
        // Invalid zone id (deleted/mismatch) -> treat as no-zone
        $zone_id = null;
    }
}

if ($city === '') {
    $city = 'Istanbul';
}

if ($address_line === '') {
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
    exit;
}

$newId = add_address($conn, $user_id, $title, $address_line, $zone_id, $city, $postal);

$stmt = $conn->prepare("SELECT ca.address_id, ca.title, ca.address_line, ca.city, ca.postal_code, ca.zone_id, z.zone_name FROM Customer_Addresses ca LEFT JOIN Zones z ON ca.zone_id = z.zone_id WHERE ca.address_id = ? LIMIT 1");
$stmt->bind_param('i', $newId);
$stmt->execute();
$addr = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($addr) {
    set_active_address($addr);
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
exit;
