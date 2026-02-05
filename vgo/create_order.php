<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
include 'db.php';
@include_once __DIR__ . '/address_helper.php';
@include_once __DIR__ . '/notifications_helper.php';
@include_once __DIR__ . '/coupons_helper.php';

function render_order_error(string $message, int $httpCode = 400): void {
    http_response_code($httpCode);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Hata - VGO</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '</head><body class="bg-light"><div class="container mt-5" style="max-width:720px">';
    echo '<div class="alert alert-danger">' . htmlspecialchars($message) . '</div>';
    echo '<a class="btn btn-primary" href="checkout.php">Checkout\'a Dön</a> ';
    echo '<a class="btn btn-outline-secondary" href="dashboard.php">Dashboard\'a Dön</a>';
    echo '</div></body></html>';
    exit;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    render_order_error('Giriş yapmanız gerekiyor.', 403);
}
$user_id = (int)$_SESSION['user_id'];
$merchant_id = isset($_POST['merchant_id']) ? (int)$_POST['merchant_id'] : 0;
$payment_method = isset($_POST['payment_method']) ? (string)$_POST['payment_method'] : 'card';
$coupon_code = isset($_POST['coupon_code']) ? trim((string)$_POST['coupon_code']) : '';
$allowedPaymentMethods = ['card', 'cash'];
if (!in_array($payment_method, $allowedPaymentMethods, true)) {
    $payment_method = 'card';
}
$product_names = $_POST['product_name'] ?? [];
$qtys = $_POST['quantity'] ?? [];
$unit_prices = $_POST['unit_price'] ?? [];

// If no posted items, try to load from cart
if (!$merchant_id || count($product_names) == 0) {
    // try cart
    $stmt = $conn->prepare("SELECT c.cart_id, c.merchant_id FROM Carts c JOIN Cart_Items ci ON ci.cart_id = c.cart_id WHERE c.user_id=? ORDER BY c.created_at DESC LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$c) {
        // fallback: latest cart even if empty
        $stmt = $conn->prepare("SELECT cart_id, merchant_id FROM Carts WHERE user_id=? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $c = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if ($c) {
        if (!$merchant_id) $merchant_id = (int)$c['merchant_id'];
        $stmt = $conn->prepare("SELECT product_name, quantity, unit_price, total_price FROM Cart_Items WHERE cart_id=?");
        $stmt->bind_param('i', $c['cart_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $product_names[] = $r['product_name'];
            $qtys[] = (int)$r['quantity'];
            $unit_prices[] = (float)$r['unit_price'];
        }
        $stmt->close();
    }
    if (!$merchant_id || count($product_names) == 0) {
        render_order_error('Sepetiniz boş görünüyor. Lütfen ürün ekleyip tekrar deneyin.', 400);
    }
}

// Get customer info including zone
$stmt = $conn->prepare("SELECT customer_id, zone_id FROM Customers WHERE user_id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$c) { http_response_code(400); echo "no_customer"; exit; }
$customer_id = $c['customer_id'];
$customer_zone_id = $c['zone_id'];

// Resolve delivery address
ensure_default_address($conn, $user_id);
$address_id = isset($_POST['address_id']) ? (int)$_POST['address_id'] : (int)($_SESSION['selected_address_id'] ?? 0);
$address = null;
if ($address_id) {
    $stmt = $conn->prepare("SELECT ca.address_id, ca.address_line, ca.zone_id FROM Customer_Addresses ca WHERE ca.address_id = ? AND ca.user_id = ? LIMIT 1");
    $stmt->bind_param('ii', $address_id, $user_id);
    $stmt->execute();
    $address = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if (!$address) {
    $address = resolve_active_address($conn, $user_id);
}
if (!$address) { http_response_code(400); echo "no_address"; exit; }
$address_zone_id = $address['zone_id'] ?? null;

// Validate merchant exists and get merchant zone
$stmt = $conn->prepare("SELECT merchant_id, user_id, zone_id FROM Merchants WHERE merchant_id = ? LIMIT 1");
$stmt->bind_param('i', $merchant_id);
$stmt->execute();
$m = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$m) { http_response_code(404); echo "merchant_not_found"; exit; }
$merchant_user = $m['user_id'];
$merchant_zone_id = $m['zone_id'];

// Resolve merchant zone identity (to handle duplicate zone IDs with same city/zone_name)
$merchant_zone_city = null;
$merchant_zone_name = null;
if (!empty($merchant_zone_id)) {
    $stmtZ = $conn->prepare("SELECT city, zone_name FROM Zones WHERE zone_id = ? LIMIT 1");
    if ($stmtZ) {
        $stmtZ->bind_param('i', $merchant_zone_id);
        $stmtZ->execute();
        $z = $stmtZ->get_result()->fetch_assoc();
        $stmtZ->close();
        if ($z) {
            $merchant_zone_city = $z['city'] ?? null;
            $merchant_zone_name = $z['zone_name'] ?? null;
        }
    }
}

// Zone validation: selected address zone must match merchant zone
if ($address_zone_id && $merchant_zone_id && $address_zone_id != $merchant_zone_id) {
    render_order_error('Seçili adres bölgesi ile sepetinizdeki mağaza bölgesi uyuşmuyor. Lütfen adresi değiştirin veya sepeti temizleyip tekrar deneyin.', 403);
}

// Use address zone primarily, fallback to merchant/customer
$zone_id = $address_zone_id ?: ($merchant_zone_id ?: ($customer_zone_id ?: 1));

// compute total
$total = 0.0;
$items = [];
for ($i=0;$i<count($product_names);$i++) {
    $name = trim($product_names[$i]);
    $q = (int)$qtys[$i]; if ($q < 1) $q = 1;
    $p = (float)$unit_prices[$i]; if ($p < 0) $p = 0;
    $line = $q * $p;
    $total += $line;
    $items[] = ['product_name'=>$name,'quantity'=>$q,'unit_price'=>$p,'total_price'=>$line];
}

// Apply coupon (optional)
$discount_amount = 0.0;
$applied_coupon_code = null;
if ($coupon_code !== '') {
    if (!function_exists('get_coupon_by_code') || !function_exists('validate_coupon') || !function_exists('calculate_discount')) {
        render_order_error('Kupon sistemi yüklenemedi. Lütfen daha sonra tekrar deneyin.', 500);
    }
    $coupon = get_coupon_by_code($conn, $coupon_code);
    $validation = validate_coupon($conn, $coupon, (float)$total);
    if (!$validation['ok']) {
        $reason = $validation['reason'] ?? 'invalid';
        $msg = 'Kupon geçersiz.';
        if ($reason === 'not_found') $msg = 'Kupon bulunamadı.';
        elseif ($reason === 'inactive') $msg = 'Kupon aktif değil.';
        elseif ($reason === 'expired') $msg = 'Kuponun süresi dolmuş.';
        elseif ($reason === 'min_total') $msg = 'Kupon için minimum sepet tutarı sağlanmıyor.';
        render_order_error($msg, 400);
    }
    $discount_amount = (float)calculate_discount($coupon, (float)$total);
    if ($discount_amount < 0) $discount_amount = 0.0;
    if ($discount_amount > $total) $discount_amount = (float)$total;
    $applied_coupon_code = $coupon_code;
}

$delivery_address = $address['address_line'] ?? '';
$stmt = $conn->prepare("INSERT INTO Orders (customer_id, merchant_id, zone_id, address_id, delivery_address, order_date, status, total_price, discount_amount, coupon_code, payment_method, created_at) VALUES (?, ?, ?, ?, ?, NOW(), 'pending', ?, ?, ?, ?, NOW())");
$stmt->bind_param('iiiisddss', $customer_id, $merchant_id, $zone_id, $address['address_id'], $delivery_address, $total, $discount_amount, $applied_coupon_code, $payment_method);
$ok = $stmt->execute();
if (!$ok) { render_order_error('Sipariş oluşturulamadı. Lütfen daha sonra tekrar deneyin.', 500); }
$order_id = $conn->insert_id;
$stmt->close();

// Create delivery record so couriers can pick it up
$stmt = $conn->prepare("INSERT INTO Deliveries (order_id, courier_id, status, created_at) VALUES (?, NULL, 'pending', NOW())");
if ($stmt) {
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $delivery_id = (int)$conn->insert_id;
    $stmt->close();

    // Add initial timeline event (best-effort)
    if ($delivery_id > 0) {
        $stmtE = $conn->prepare("INSERT INTO Delivery_Events (delivery_id, event_type, timestamp, notes) VALUES (?, 'created', NOW(), ?)");
        if ($stmtE) {
            $note = 'Teslimat kaydı oluşturuldu.';
            $stmtE->bind_param('is', $delivery_id, $note);
            $stmtE->execute();
            $stmtE->close();
        }

        // Best-effort auto assignment: pick an available courier in the same zone
        $pickedCourier = null;
        if (!empty($merchant_zone_id)) {
                        // Prefer least-loaded courier: minimum active deliveries, then least recently assigned.
                        $stmtPick = $conn->prepare(
                                "SELECT c.courier_id, c.user_id
                                 FROM Couriers c
                                 LEFT JOIN Zones cz ON cz.zone_id = c.current_zone_id
                                 LEFT JOIN Deliveries d
                                     ON d.courier_id = c.courier_id
                                    AND d.status IN ('assigned','picked_up','on_the_way','delivering')
                                 WHERE c.is_available = 1
                                     AND (
                                                c.current_zone_id = ?
                                                OR (
                                                        ? IS NOT NULL AND ? IS NOT NULL
                                                        AND cz.city = ?
                                                        AND cz.zone_name = ?
                                                )
                                     )
                                 GROUP BY c.courier_id, c.user_id
                                 ORDER BY COUNT(d.delivery_id) ASC,
                                                    COALESCE(MAX(d.assigned_at), '1970-01-01 00:00:00') ASC,
                                                    c.courier_id ASC
                                 LIMIT 1"
                        );
            if ($stmtPick) {
                                // params: zone_id, city/name null checks + comparisons
                                $stmtPick->bind_param('issss', $merchant_zone_id, $merchant_zone_city, $merchant_zone_name, $merchant_zone_city, $merchant_zone_name);
                $stmtPick->execute();
                $pickedCourier = $stmtPick->get_result()->fetch_assoc();
                $stmtPick->close();
            }
        }

        if ($pickedCourier && !empty($pickedCourier['courier_id'])) {
            $pickedCourierId = (int)$pickedCourier['courier_id'];
            $stmtAssign = $conn->prepare("UPDATE Deliveries SET courier_id = ?, status = 'assigned', assigned_at = NOW() WHERE delivery_id = ? AND courier_id IS NULL");
            if ($stmtAssign) {
                $stmtAssign->bind_param('ii', $pickedCourierId, $delivery_id);
                $stmtAssign->execute();
                $assigned = $stmtAssign->affected_rows > 0;
                $stmtAssign->close();

                if ($assigned) {
                    $stmtEv2 = $conn->prepare("INSERT INTO Delivery_Events (delivery_id, event_type, timestamp, notes) VALUES (?, 'assigned', NOW(), ?)");
                    if ($stmtEv2) {
                        $note2 = 'Sistem uygun kurye atadı.';
                        $stmtEv2->bind_param('is', $delivery_id, $note2);
                        $stmtEv2->execute();
                        $stmtEv2->close();
                    }

                    // Notify courier (best-effort)
                    if (!empty($pickedCourier['user_id'])) {
                        notify_user($conn, (int)$pickedCourier['user_id'], null, $order_id, $delivery_id, 'delivery_assigned', 'Yeni bir teslimat size atandı.');
                    }
                }
            }
        }
    }
}

// Insert items
$stmt = $conn->prepare("INSERT INTO Order_Items (order_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
foreach ($items as $it) {
    $stmt->bind_param('isidd', $order_id, $it['product_name'], $it['quantity'], $it['unit_price'], $it['total_price']);
    $stmt->execute();
}
$stmt->close();

// Clear cart after order
$conn->query("DELETE ci FROM Cart_Items ci JOIN Carts c ON ci.cart_id=c.cart_id WHERE c.user_id=".(int)$user_id);

// Notify merchant
if (!empty($merchant_user)) {
    notify_user($conn, $merchant_user, null, $order_id, null, 'new_order', 'Yeni bir siparişiniz var.');
}

// Notify customer
notify_user($conn, $user_id, null, $order_id, null, 'order_confirmed', 'Siparişiniz alındı.');

// redirect to order details
header('Location: order_details.php?id=' . $order_id);
exit;