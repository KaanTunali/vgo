<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
@include_once __DIR__ . '/db.php';

function get_coupon_by_code($conn, $code) {
    $stmt = $conn->prepare("SELECT * FROM Coupons WHERE code = ? LIMIT 1");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $r ?: null;
}

function validate_coupon($conn, $coupon, $order_total) {
    // coupon: assoc array or null
    if (!$coupon) return ['ok' => false, 'reason' => 'not_found'];
    if (!$coupon['is_active']) return ['ok' => false, 'reason' => 'inactive'];
    if (!empty($coupon['expires_at']) && strtotime($coupon['expires_at']) < time()) return ['ok' => false, 'reason' => 'expired'];
    if (!empty($coupon['min_order_total']) && $order_total < (float)$coupon['min_order_total']) return ['ok' => false, 'reason' => 'min_total'];
    return ['ok' => true];
}

function calculate_discount($coupon, $order_total) {
    if (!$coupon) return 0.0;
    $type = $coupon['discount_type'];
    $val = (float)$coupon['discount_value'];
    if ($type === 'fixed') {
        return min($val, $order_total);
    }
    if ($type === 'percent') {
        return round($order_total * ($val / 100.0), 2);
    }
    return 0.0;
}
