<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];

// courier id
$stmt = $conn->prepare("SELECT courier_id, current_zone_id, is_available FROM Couriers WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$courier = $res->fetch_assoc();
$stmt->close();
if (!$courier) { echo "Kurye bulunamadı."; exit; }
$courier_id = $courier['courier_id'];

$delivery_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($delivery_id <= 0) { echo "Geçersiz teslimat."; exit; }

// load delivery
$stmt = $conn->prepare("SELECT d.*, o.order_id, o.total_price, o.order_date,
    m.store_name, m.address AS merchant_address, m.user_id AS merchant_user_id,
    m.zone_id AS merchant_zone_id,
    mz.city AS merchant_zone_city,
    mz.zone_name AS merchant_zone_name,
    c.user_id AS customer_user_id,
    u.full_name AS customer_name, u.phone AS customer_phone
FROM Deliveries d
JOIN Orders o ON d.order_id = o.order_id
JOIN Merchants m ON o.merchant_id = m.merchant_id
LEFT JOIN Zones mz ON m.zone_id = mz.zone_id
JOIN Customers c ON o.customer_id = c.customer_id
JOIN Users u ON c.user_id = u.user_id
WHERE d.delivery_id = ?
LIMIT 1");
$stmt->bind_param("i", $delivery_id);
$stmt->execute();
$res = $stmt->get_result();
$delivery = $res->fetch_assoc();
$stmt->close();
if (!$delivery) { echo "Teslimat bulunamadı."; exit; }

$order_id = $delivery['order_id'];

// load items
$stmt = $conn->prepare("SELECT product_name, quantity, total_price FROM Order_Items WHERE order_id = ?");
$stmt->bind_param("i", $delivery['order_id']);
$stmt->execute();
$items = $stmt->get_result();
$stmt->close();

// events
$stmt = $conn->prepare("SELECT event_type, timestamp, notes FROM Delivery_Events WHERE delivery_id = ? ORDER BY timestamp ASC");
$stmt->bind_param("i", $delivery_id);
$stmt->execute();
$events = $stmt->get_result();
$stmt->close();

// actions: accept, pick_up, deliver, report_incident
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'accept') {
        if (empty($courier['is_available'])) {
            $error = 'Meşgul durumdasınız; yeni teslimat kabul edemezsiniz.';
        } else {
        // atomic assign: only succeed if courier_id IS NULL
        $stmt = $conn->prepare("UPDATE Deliveries SET courier_id = ?, status = 'assigned', assigned_at = NOW(), accepted_at = NOW() WHERE delivery_id = ? AND courier_id IS NULL");
        $stmt->bind_param("ii", $courier_id, $delivery_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $stmt->close();
            // add event
            $stmt = $conn->prepare("INSERT INTO Delivery_Events (delivery_id, event_type, timestamp, notes) VALUES (?, 'assigned', NOW(), ?)");
            $note = 'Kurye tarafından kabul edildi.';
            $stmt->bind_param("is", $delivery_id, $note);
            $stmt->execute();
            $stmt->close();
            // notify customer and merchant via helper
            require_once __DIR__ . '/notifications_helper.php';
            $stmt = $conn->prepare("SELECT u.full_name FROM Couriers c JOIN Users u ON c.user_id = u.user_id WHERE c.courier_id = ? LIMIT 1");
            $stmt->bind_param("i", $courier_id);
            $stmt->execute();
            $cn = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $courier_name = $cn['full_name'] ?? 'Kurye';

            if (!empty($delivery['customer_user_id'])) {
                $msgCust = 'Kurye ' . $courier_name . ' siparişinizi almaya kabul etti.';
                notify_user($conn, $delivery['customer_user_id'], null, $delivery['order_id'], $delivery_id, 'delivery_assigned', $msgCust);
            }
            if (!empty($delivery['merchant_user_id'])) {
                $msgMer = 'Kurye ' . $courier_name . ' siparişinizi almaya gidiyor.';
                notify_user($conn, $delivery['merchant_user_id'], null, $delivery['order_id'], $delivery_id, 'delivery_assigned', $msgMer);
            }

            header('Location: courier_delivery.php?id=' . $delivery_id);
            exit;
        } else {
            $stmt->close();
            $error = 'Teslimat zaten atanmış.';
        }
        }
    }

    if ($act === 'reject') {
        // Courier can reject only before pickup.
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $reason = 'Kurye teslimatı reddetti.';
        }

        if ((int)($delivery['courier_id'] ?? 0) !== (int)$courier_id) {
            $error = 'Bu teslimatı reddetme yetkiniz yok.';
        } elseif (in_array($delivery['status'], ['picked_up', 'on_the_way', 'delivering', 'delivered'], true)) {
            $error = 'Paket alındıktan sonra reddedilemez.';
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE Deliveries
                    SET courier_id = NULL,
                        status = 'pending',
                        assigned_at = NULL,
                        accepted_at = NULL
                    WHERE delivery_id = ?
                      AND courier_id = ?
                      AND status IN ('assigned','accepted','ready','pending','unassigned')");
                $stmt->bind_param('ii', $delivery_id, $courier_id);
                $stmt->execute();
                $changed = ($stmt->affected_rows > 0);
                $stmt->close();

                if (!$changed) {
                    throw new Exception('Teslimat reddedilemedi (durum değişmiş olabilir).');
                }

                $stmt = $conn->prepare("INSERT INTO Delivery_Events (delivery_id, event_type, timestamp, notes)
                    VALUES (?, 'rejected', NOW(), ?)");
                $stmt->bind_param('is', $delivery_id, $reason);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Reddetme işlemi başarısız: ' . $e->getMessage();
            }

            if (!isset($error)) {
                // Try to reassign to another courier in the same zone (zone_id OR city+zone_name).
                $merchant_zone_id = (int)($delivery['merchant_zone_id'] ?? 0);
                $merchant_zone_city = $delivery['merchant_zone_city'] ?? null;
                $merchant_zone_name = $delivery['merchant_zone_name'] ?? null;

                $pickedCourier = null;
                if ($merchant_zone_id > 0) {
                    $stmtPick = $conn->prepare(
                        "SELECT c.courier_id, c.user_id
                         FROM Couriers c
                         LEFT JOIN Zones cz ON cz.zone_id = c.current_zone_id
                         LEFT JOIN Deliveries d
                           ON d.courier_id = c.courier_id
                          AND d.status IN ('assigned','picked_up','on_the_way','delivering')
                         WHERE c.is_available = 1
                           AND c.courier_id <> ?
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
                        $stmtPick->bind_param(
                            'iissss',
                            $courier_id,
                            $merchant_zone_id,
                            $merchant_zone_city,
                            $merchant_zone_name,
                            $merchant_zone_city,
                            $merchant_zone_name
                        );
                        $stmtPick->execute();
                        $pickedCourier = $stmtPick->get_result()->fetch_assoc();
                        $stmtPick->close();
                    }
                }

                require_once __DIR__ . '/notifications_helper.php';

                if ($pickedCourier && !empty($pickedCourier['courier_id'])) {
                    $newCourierId = (int)$pickedCourier['courier_id'];

                    $conn->begin_transaction();
                    try {
                        $stmt = $conn->prepare("UPDATE Deliveries
                            SET courier_id = ?, status = 'assigned', assigned_at = NOW()
                            WHERE delivery_id = ? AND courier_id IS NULL AND status IN ('pending','unassigned')");
                        $stmt->bind_param('ii', $newCourierId, $delivery_id);
                        $stmt->execute();
                        $okAssign = ($stmt->affected_rows > 0);
                        $stmt->close();

                        if (!$okAssign) {
                            throw new Exception('Yeni kurye atanamadı (durum değişmiş olabilir).');
                        }

                        $stmt = $conn->prepare("INSERT INTO Delivery_Events (delivery_id, event_type, timestamp, notes)
                            VALUES (?, 'assigned', NOW(), ?)");
                        $note = 'Sistem yeni kurye atadı.';
                        $stmt->bind_param('is', $delivery_id, $note);
                        $stmt->execute();
                        $stmt->close();

                        $conn->commit();
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = 'Yeniden atama başarısız: ' . $e->getMessage();
                    }

                    if (!isset($error)) {
                        // notify new courier
                        if (!empty($pickedCourier['user_id'])) {
                            notify_user($conn, (int)$pickedCourier['user_id'], null, $delivery['order_id'], $delivery_id, 'delivery_assigned', 'Size yeni bir teslimat atandı.');
                        }
                        // notify customer + merchant
                        if (!empty($delivery['customer_user_id'])) {
                            notify_user($conn, (int)$delivery['customer_user_id'], null, $delivery['order_id'], $delivery_id, 'courier_reassigned', 'Kurye teslimatı reddetti, yeni kurye atandı.');
                        }
                        if (!empty($delivery['merchant_user_id'])) {
                            notify_user($conn, (int)$delivery['merchant_user_id'], null, $delivery['order_id'], $delivery_id, 'courier_reassigned', 'Kurye teslimatı reddetti, yeni kurye atandı.');
                        }
                    }
                } else {
                    // no courier found; keep pending
                    if (!empty($delivery['customer_user_id'])) {
                        notify_user($conn, (int)$delivery['customer_user_id'], null, $delivery['order_id'], $delivery_id, 'courier_reassigned', 'Kurye teslimatı reddetti, yeni kurye aranıyor.');
                    }
                    if (!empty($delivery['merchant_user_id'])) {
                        notify_user($conn, (int)$delivery['merchant_user_id'], null, $delivery['order_id'], $delivery_id, 'courier_reassigned', 'Kurye teslimatı reddetti, yeni kurye aranıyor.');
                    }
                }

                header('Location: courier_dashboard.php');
                exit;
            }
        }
    }
    if ($act === 'pick_up') {
        $conn->begin_transaction();
        try {
            // Calculate estimated arrival time: current time + (distance_km * 3 minutes)
            $estimated_minutes = ceil(floatval($delivery['distance_km'] ?? 5) * 3); // 3 minutes per km, default 5km
            $estimated_arrival = date('Y-m-d H:i:s', strtotime("+$estimated_minutes minutes"));
            
            // Check if columns exist before updating
            $updateFields = ["status = 'picked_up'"];
            $types = '';
            $params = [];
            
            $hasPickupTime = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Deliveries' AND COLUMN_NAME = 'pickup_time' LIMIT 1")->num_rows > 0;
            $hasEstimated = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Deliveries' AND COLUMN_NAME = 'estimated_arrival_time' LIMIT 1")->num_rows > 0;
            
            if ($hasPickupTime) {
                $updateFields[] = 'pickup_time = NOW()';
            }
            if ($hasEstimated) {
                $updateFields[] = 'estimated_arrival_time = ?';
                $types .= 's';
                $params[] = $estimated_arrival;
            }
            
            $types .= 'ii';
            $params[] = $delivery_id;
            $params[] = $courier_id;
            
            $stmt = $conn->prepare("UPDATE Deliveries SET " . implode(', ', $updateFields) . " WHERE delivery_id = ? AND courier_id = ?");
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
            
            // update order status to delivering
            $stmt = $conn->prepare("UPDATE Orders SET status = 'delivering' WHERE order_id = ?");
            $stmt->bind_param("i", $delivery['order_id']);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Teslimat güncellenemedi: ' . $e->getMessage();
        }
        
        if (!isset($error)) {
            $stmt = $conn->prepare("INSERT INTO Delivery_Events (delivery_id, event_type, timestamp, notes) VALUES (?, 'picked_up', NOW(), ?)");
            $note = 'Kurye paketi aldı. Tahmini varış: ' . date('H:i', strtotime($estimated_arrival));
            $stmt->bind_param("is", $delivery_id, $note);
            $stmt->execute();
            $stmt->close();
            // notify customer and merchant via helper
            require_once __DIR__ . '/notifications_helper.php';
            if (!empty($delivery['customer_user_id'])) {
                $msg = 'Kurye paketinizi aldı ve teslimata çıkıldı.';
                notify_user($conn, $delivery['customer_user_id'], null, $delivery['order_id'], $delivery_id, 'picked_up', $msg);
            }
            if (!empty($delivery['merchant_user_id'])) {
                $msg = 'Kurye paketi aldı ve teslimata çıktı.';
                notify_user($conn, $delivery['merchant_user_id'], null, $delivery['order_id'], $delivery_id, 'picked_up', $msg);
            }
        }

        header('Location: courier_delivery.php?id=' . $delivery_id);
        exit;
    }
    if ($act === 'deliver') {
        $conn->begin_transaction();
        try {
            // Check if delivery_time column exists
            $hasDeliveryTime = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Deliveries' AND COLUMN_NAME = 'delivery_time' LIMIT 1")->num_rows > 0;
            $hasDeliveredAt = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Deliveries' AND COLUMN_NAME = 'delivered_at' LIMIT 1")->num_rows > 0;
            
            $updateFields = ["status = 'delivered'"];
            if ($hasDeliveryTime) {
                $updateFields[] = 'delivery_time = NOW()';
            }
            if ($hasDeliveredAt) {
                $updateFields[] = 'delivered_at = NOW()';
            }
            
            $stmt = $conn->prepare("UPDATE Deliveries SET " . implode(', ', $updateFields) . " WHERE delivery_id = ? AND courier_id = ?");
            $stmt->bind_param("ii", $delivery_id, $courier_id);
            $stmt->execute();
            $stmt->close();
            
            // update order status
            $stmt = $conn->prepare("UPDATE Orders SET status = 'delivered' WHERE order_id = ?");
            $stmt->bind_param("i", $delivery['order_id']);
            $stmt->execute();
            $stmt->close();

            // increment courier stats
            $stmt = $conn->prepare("UPDATE Couriers SET total_deliveries = COALESCE(total_deliveries, 0) + 1 WHERE courier_id = ?");
            $stmt->bind_param("i", $courier_id);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Teslimat tamamlanamadı: ' . $e->getMessage();
        }
        
        if (!isset($error)) {
            $stmt = $conn->prepare("INSERT INTO Delivery_Events (delivery_id, event_type, timestamp, notes) VALUES (?, 'delivered', NOW(), ?)");
            $note = 'Kurye teslimatı tamamladı.';
            $stmt->bind_param("is", $delivery_id, $note);
            $stmt->execute();
            $stmt->close();
            // notify customer and merchant via helper
            require_once __DIR__ . '/notifications_helper.php';
            if (!empty($delivery['customer_user_id'])) {
                $msg = 'Siparişiniz teslim edildi. Afiyet olsun!';
                notify_user($conn, $delivery['customer_user_id'], null, $delivery['order_id'], $delivery_id, 'delivered', $msg);
            }
            if (!empty($delivery['merchant_user_id'])) {
                $msg = 'Kurye siparişi teslim etti.';
                notify_user($conn, $delivery['merchant_user_id'], null, $delivery['order_id'], $delivery_id, 'delivered', $msg);
            }
        }
        
        header('Location: courier_delivery.php?id=' . $delivery_id);
        exit;
    }
    if ($act === 'report_incident' && !empty($_POST['incident_type'])) {
        $type = $_POST['incident_type'];
        $desc = trim($_POST['description'] ?? '');
        $stmt = $conn->prepare("INSERT INTO Incidents (delivery_id, type, description, reported_at, resolved) VALUES (?, ?, ?, NOW(), 0)");
        $stmt->bind_param("iss", $delivery_id, $type, $desc);
        $stmt->execute();
        $stmt->close();
        header('Location: courier_delivery.php?id=' . $delivery_id);
        exit;
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Teslimat - Kurye</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4">
    <a href="courier_dashboard.php">&larr; Geri</a>
    <h4 class="mt-3">Teslimat #<?php echo $delivery['delivery_id']; ?></h4>

    <?php if(isset($error)): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <p>Mağaza: <strong><?php echo htmlspecialchars($delivery['store_name']); ?></strong></p>
            <p>Adres: <?php echo htmlspecialchars($delivery['merchant_address']); ?></p>
            <p>Müşteri: <?php echo htmlspecialchars($delivery['customer_name']); ?> — <a href="tel:<?php echo htmlspecialchars($delivery['customer_phone']); ?>"><?php echo htmlspecialchars($delivery['customer_phone']); ?></a></p>
            <p>Mesafe: <strong><?php echo number_format($delivery['distance_km'], 2); ?> km</strong></p>
            <p>Durum: <strong><?php echo htmlspecialchars($delivery['status']); ?></strong></p>
            
            <?php
            $showEta = !empty($delivery['estimated_arrival_time'])
                && !in_array($delivery['status'], ['delivered', 'cancelled'], true);
            ?>
            <?php if ($showEta): ?>
            <p class="text-success">
                <i class="bi bi-clock"></i> Tahmini Varış:
                <strong><?php echo date('H:i', strtotime($delivery['estimated_arrival_time'])); ?></strong>
                (<?php echo max(0, (int)ceil((strtotime($delivery['estimated_arrival_time']) - time()) / 60)); ?> dakika)
            </p>
            <?php endif; ?>

            <h6>Ürünler</h6>
            <ul class="list-group mb-3">
                <?php while($it = $items->fetch_assoc()): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <div><?php echo htmlspecialchars($it['product_name']); ?> x<?php echo (int)$it['quantity']; ?></div>
                        <div><?php echo number_format($it['total_price'],2); ?> ₺</div>
                    </li>
                <?php endwhile; ?>
            </ul>

            <div class="mb-3">
                <?php if ($delivery['courier_id'] == null): ?>
                    <?php if (!empty($courier['is_available'])): ?>
                        <form method="POST"><button name="action" value="accept" class="btn btn-primary">Kabul Et</button></form>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-0">Meşgul durumdasınız; yeni teslimat kabul edemezsiniz.</div>
                    <?php endif; ?>
                <?php elseif (($delivery['status'] === 'assigned' || $delivery['status'] === 'accepted') && (int)$delivery['courier_id'] === (int)$courier_id): ?>
                    <form method="POST" class="d-inline"><button name="action" value="pick_up" class="btn btn-warning">Paketi Alındı Yap</button></form>
                    <form method="POST" class="d-inline ms-2" onsubmit="return confirm('Teslimatı reddetmek istiyor musunuz?');">
                        <input type="hidden" name="reason" value="Kurye teslimatı reddetti.">
                        <button name="action" value="reject" class="btn btn-outline-danger">Reddet</button>
                    </form>
                <?php endif; ?>
                <?php if ($delivery['status'] === 'picked_up' || $delivery['status'] === 'on_the_way'): ?>
                    <form method="POST" class="d-inline"><button name="action" value="deliver" class="btn btn-success">Teslim Edildi</button></form>
                <?php endif; ?>
                <button class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#incidentArea">Sorun Bildir</button>
                <a href="support.php?order_id=<?php echo $order_id; ?>&delivery_id=<?php echo $delivery_id; ?>" class="btn btn-warning">
                    <i class="bi bi-headset"></i> Destek Talebi
                </a>
            </div>

            <div id="incidentArea" class="collapse">
                <form method="POST" class="mt-3">
                    <input type="hidden" name="action" value="report_incident">
                    <div class="mb-2">
                        <label>Tip</label>
                        <select name="incident_type" class="form-select">
                            <option value="delay">Gecikme</option>
                            <option value="address_issue">Adres Sorunu</option>
                            <option value="customer_unavailable">Müşteri Yok</option>
                            <option value="accident">Kaza</option>
                            <option value="other">Diğer</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label>Açıklama</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                    <button class="btn btn-danger">Raporla</button>
                </form>
            </div>

            <hr>
            <h6>Zaman Çizelgesi</h6>
            <ul class="list-group">
                <?php while($e = $events->fetch_assoc()): ?>
                    <li class="list-group-item"><?php echo htmlspecialchars($e['timestamp']); ?> — <?php echo htmlspecialchars($e['event_type']); ?> <?php if (!empty($e['notes'])) echo '- ' . htmlspecialchars($e['notes']); ?></li>
                <?php endwhile; ?>
            </ul>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>