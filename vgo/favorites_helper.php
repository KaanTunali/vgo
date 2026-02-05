<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
@include_once __DIR__ . '/db.php';
@include_once __DIR__ . '/migrations_runner.php';

function is_favorite_merchant($conn, $user_id, $merchant_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM Favorites WHERE user_id = ? AND merchant_id = ?");
    $stmt->bind_param('ii', $user_id, $merchant_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($r['cnt'] ?? 0) > 0;
}

function toggle_favorite_merchant($conn, $user_id, $merchant_id) {
    if (is_favorite_merchant($conn, $user_id, $merchant_id)) {
        $stmt = $conn->prepare("DELETE FROM Favorites WHERE user_id = ? AND merchant_id = ?");
        $stmt->bind_param('ii', $user_id, $merchant_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok ? 'removed' : false;
    } else {
        $stmt = $conn->prepare("INSERT INTO Favorites (user_id, merchant_id) VALUES (?, ?)");
        $stmt->bind_param('ii', $user_id, $merchant_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok ? 'added' : false;
    }
}

function list_favorite_merchants($conn, $user_id) {
    $stmt = $conn->prepare("SELECT merchant_id FROM Favorites WHERE user_id = ? AND merchant_id IS NOT NULL");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $arr = [];
    while ($r = $res->fetch_assoc()) $arr[] = (int)$r['merchant_id'];
    $stmt->close();
    return $arr;
}
