<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// merchant kontrol (avg_preparation_time al)
$stmt = $conn->prepare("SELECT merchant_id, store_name, avg_preparation_time FROM Merchants WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$merchant = $res->fetch_assoc();
$stmt->close();
if (!$merchant) { echo "Mağaza bulunamadı."; exit; }
$merchant_id = $merchant['merchant_id'];
$avg_prep = (int)($merchant['avg_preparation_time'] ?? 0);

// sipariş + müşteri bilgisi
$stmt = $conn->prepare("SELECT o.*, c.user_id AS customer_user_id, u.full_name AS customer_name, u.phone AS customer_phone, u.email AS customer_email FROM Orders o JOIN Customers c ON o.customer_id = c.customer_id JOIN Users u ON c.user_id = u.user_id WHERE o.order_id = ? AND o.merchant_id = ? LIMIT 1");
$stmt->bind_param("ii", $order_id, $merchant_id);
$stmt->execute();
$res = $stmt->get_result();
$order = $res->fetch_assoc();
$stmt->close();
if (!$order) { echo "Sipariş bulunamadı veya yetkiniz yok."; exit; }

// order items
$stmt = $conn->prepare("SELECT product_name, quantity, unit_price, total_price FROM Order_Items WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$items = $stmt->get_result();
$stmt->close();

// Teslimat ve olaylar
$delivery = null;
$events = [];
$incidents = [];
$stmt = $conn->prepare("SELECT d.*, c.user_id AS courier_user_id, u.full_name AS courier_name, u.phone AS courier_phone FROM Deliveries d LEFT JOIN Couriers c ON d.courier_id = c.courier_id LEFT JOIN Users u ON c.user_id = u.user_id WHERE d.order_id = ? LIMIT 1");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$res = $stmt->get_result();
$delivery = $res->fetch_assoc();
$stmt->close();
if ($delivery) {
    $stmt = $conn->prepare("SELECT event_type, timestamp, notes FROM Delivery_Events WHERE delivery_id = ? ORDER BY timestamp ASC");
    $stmt->bind_param("i", $delivery['delivery_id']);
    $stmt->execute();
    $ev = $stmt->get_result();
    while ($r = $ev->fetch_assoc()) $events[] = $r;
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM Incidents WHERE delivery_id = ? ORDER BY reported_at DESC");
    $stmt->bind_param("i", $delivery['delivery_id']);
    $stmt->execute();
    $inc = $stmt->get_result();
    while ($r = $inc->fetch_assoc()) $incidents[] = $r;
    $stmt->close();
}

// Order notes table (if not exists)
$conn->query("CREATE TABLE IF NOT EXISTS Order_Notes (
    note_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    user_id INT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'merchant_note',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// load notes
$stmt = $conn->prepare("SELECT n.*, u.full_name FROM Order_Notes n LEFT JOIN Users u ON n.user_id = u.user_id WHERE n.order_id = ? ORDER BY n.created_at DESC");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$notes = $stmt->get_result();
$stmt->close();

// küçük action handler (hazır, iptal, not ekle)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if ($act === 'start_preparing') {
        $stmt = $conn->prepare("UPDATE Orders SET status = 'preparing' WHERE order_id = ? AND merchant_id = ?");
        $stmt->bind_param("ii", $order_id, $merchant_id);
        $stmt->execute();
        $stmt->close();

        // notify customer
        require_once __DIR__ . '/notifications_helper.php';
        $stmt = $conn->prepare("SELECT c.user_id FROM Orders o JOIN Customers c ON o.customer_id = c.customer_id WHERE o.order_id = ? LIMIT 1");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $cu = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($cu && !empty($cu['user_id'])) {
            $msgText = 'Siparişiniz hazırlanıyor.';
            notify_user($conn, $cu['user_id'], null, $order_id, null, 'order_preparing', $msgText);
        }
        header('Location: merchant_order_details.php?id=' . $order_id);
        exit;
    }
    if ($act === 'ready') {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE Orders SET status = 'ready' WHERE order_id = ? AND merchant_id = ?");
            $stmt->bind_param("ii", $order_id, $merchant_id);
            $stmt->execute();
            $stmt->close();

            // Ensure delivery exists and mark it available for couriers (delivery status != order status)
            $stmt = $conn->prepare("SELECT delivery_id FROM Deliveries WHERE order_id = ? LIMIT 1");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$r) {
                // Create delivery if missing
                $stmt = $conn->prepare("INSERT INTO Deliveries (order_id, courier_id, status, created_at) VALUES (?, NULL, 'pending', NOW())");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $delivery_id = $stmt->insert_id;
                $stmt->close();
            } else {
                // Update existing delivery status to ready
                $delivery_id = (int)$r['delivery_id'];
                // Only mark as pending if it's still unassigned and not already in progress
                $stmt = $conn->prepare("UPDATE Deliveries SET status = 'pending' WHERE delivery_id = ? AND courier_id IS NULL AND status NOT IN ('assigned','picked_up','on_the_way','delivering','delivered','cancelled')");
                if ($stmt) {
                    $stmt->bind_param("i", $delivery_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $conn->commit();

            // notify customer and merchant via helper
            require_once __DIR__ . '/notifications_helper.php';
            $stmt = $conn->prepare("SELECT c.user_id FROM Orders o JOIN Customers c ON o.customer_id = c.customer_id WHERE o.order_id = ? LIMIT 1");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $cu = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($cu && !empty($cu['user_id'])) {
                $msgText = 'Siparişiniz mağaza tarafından hazırlandı. Kurye ataması bekleniyor.';
                notify_user($conn, $cu['user_id'], null, $order_id, $delivery_id, 'order_ready', $msgText);
            }
        } catch (Exception $e) {
            $conn->rollback();
        }
        header('Location: merchant_order_details.php?id=' . $order_id);
        exit;
    }
    if ($act === 'cancel') {
        // Prevent cancelling already-cancelled/delivered orders, or deliveries already in progress.
        $terminalStatuses = ['cancelled', 'delivered'];
        $inProgressDeliveryStatuses = ['assigned', 'picked_up', 'on_the_way', 'delivering', 'delivered'];

        $currentOrderStatus = (string)($order['status'] ?? '');
        $currentDeliveryStatus = $delivery ? (string)($delivery['status'] ?? '') : '';

        if (in_array($currentOrderStatus, $terminalStatuses, true) || in_array($currentDeliveryStatus, $inProgressDeliveryStatuses, true)) {
            header('Location: merchant_order_details.php?id=' . $order_id . '&err=cancel_not_allowed');
            exit;
        }

        $stmt = $conn->prepare("UPDATE Orders SET status = 'cancelled' WHERE order_id = ? AND merchant_id = ?");
        $stmt->bind_param("ii", $order_id, $merchant_id);
        $stmt->execute();
        $stmt->close();

        // notify customer about cancellation
        require_once __DIR__ . '/notifications_helper.php';
        if (!empty($order['customer_user_id'])) {
            notify_user($conn, (int)$order['customer_user_id'], null, $order_id, $delivery ? (int)$delivery['delivery_id'] : null, 'order_cancelled', 'Siparişiniz mağaza tarafından iptal edildi.');
        }

        header('Location: merchant_order_details.php?id=' . $order_id);
        exit;
    }
    if ($act === 'add_note' && isset($_POST['message']) && trim($_POST['message']) !== '') {
        $msgText = trim($_POST['message']);
        $stmt = $conn->prepare("INSERT INTO Order_Notes (order_id, user_id, message, type) VALUES (?, ?, ?, 'merchant_note')");
        $stmt->bind_param("iis", $order_id, $user_id, $msgText);
        $stmt->execute();
        $stmt->close();

        // Send the note to customer as a notification so it is visible immediately.
        require_once __DIR__ . '/notifications_helper.php';
        if (!empty($order['customer_user_id'])) {
            $notifyText = 'Mağaza notu: ' . $msgText;
            notify_user($conn, (int)$order['customer_user_id'], null, $order_id, $delivery ? (int)$delivery['delivery_id'] : null, 'order_note', $notifyText);
        }
        header('Location: merchant_order_details.php?id=' . $order_id);
        exit;
    }
}

// time warnings
$orderTime = strtotime($order['order_date']);
$ageMinutes = floor((time() - $orderTime) / 60);
$prepExceeded = false;
if ($order['status'] === 'preparing' && $avg_prep > 0 && $ageMinutes > $avg_prep) {
    $prepExceeded = true;
}

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sipariş Detayı - <?php echo htmlspecialchars($order['order_id']); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4">
    <a href="merchant_orders.php">&larr; Geri</a>
    <div class="card mt-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h5>Sipariş #<?php echo $order['order_id']; ?> — <?php echo htmlspecialchars($order['customer_name']); ?></h5>
                    <p class="mb-1">Durum: <strong><?php echo htmlspecialchars($order['status']); ?></strong></p>
                    <p class="mb-1">Toplam: <strong><?php echo number_format($order['total_price'],2); ?> ₺</strong></p>
                    <p class="mb-1">Sipariş Süresi: <small><?php echo $ageMinutes; ?> dakika önce</small></p>
                    <?php if ($order['status'] === 'pending' && $ageMinutes > 15): ?>
                        <div class="alert alert-warning">Uyarı: Yeni sipariş <?php echo $ageMinutes; ?> dakikadır bekliyor.</div>
                    <?php endif; ?>
                    <?php if ($prepExceeded): ?>
                        <div class="alert alert-warning">Uyarı: Hazırlık süresi (<?php echo $avg_prep; ?> dk) aşılmış olabilir.</div>
                    <?php endif; ?>
                </div>

                <div class="text-end">
                    <p class="mb-1">Müşteri: <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong></p>
                    <p class="mb-1">Tel: <a href="tel:<?php echo htmlspecialchars($order['customer_phone']); ?>"><?php echo htmlspecialchars($order['customer_phone']); ?></a></p>
                    <?php if (!empty($order['customer_email'])): ?>
                        <p class="mb-1">E-posta: <a href="mailto:<?php echo htmlspecialchars($order['customer_email']); ?>"><?php echo htmlspecialchars($order['customer_email']); ?></a></p>
                    <?php endif; ?>
                    <?php if ($delivery): ?>
                        <p class="mb-0">Kurye: <strong><?php echo htmlspecialchars($delivery['courier_name'] ?? '—'); ?></strong></p>
                        <p class="mb-0">Kurye Tel: <?php echo htmlspecialchars($delivery['courier_phone'] ?? '—'); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-3">
                <form method="POST" class="d-inline">
                    <?php if ($order['status'] === 'pending'): ?>
                        <button name="action" value="start_preparing" class="btn btn-primary">Hazırlamaya Başla</button>
                    <?php endif; ?>
                    <?php if ($order['status'] === 'preparing'): ?>
                        <button name="action" value="ready" class="btn btn-success">Hazırlandı</button>
                    <?php endif; ?>
                    <?php
                        $isTerminal = in_array($order['status'], ['cancelled','delivered'], true);
                        $isDeliveryInProgress = $delivery && in_array(($delivery['status'] ?? ''), ['assigned','picked_up','on_the_way','delivering','delivered'], true);
                    ?>
                    <?php if (!$isTerminal && !$isDeliveryInProgress): ?>
                        <button name="action" value="cancel" class="btn btn-danger">İptal Et</button>
                    <?php endif; ?>
                </form>
                <a href="support.php?order_id=<?php echo $order_id; ?><?php echo $delivery ? '&delivery_id='.$delivery['delivery_id'] : ''; ?>" class="btn btn-warning">
                    <i class="bi bi-headset"></i> Destek Talebi
                </a>
            </div>

            <hr>

            <div class="row">
                <div class="col-md-6">
                    <h6>Zaman Çizelgesi</h6>
                    <ul class="list-group">
                        <li class="list-group-item">Sipariş verildi: <?php echo htmlspecialchars($order['order_date']); ?></li>
                        <?php foreach ($events as $e): ?>
                            <li class="list-group-item"><?php echo htmlspecialchars($e['timestamp']); ?> — <?php echo htmlspecialchars($e['event_type']); ?> <?php if (!empty($e['notes'])) echo '- ' . htmlspecialchars($e['notes']); ?></li>
                        <?php endforeach; ?>
                        <?php foreach ($incidents as $inc): ?>
                            <li class="list-group-item list-group-item-danger"><?php echo htmlspecialchars($inc['reported_at']); ?> — <?php echo htmlspecialchars($inc['type']); ?>: <?php echo htmlspecialchars($inc['description']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="col-md-6">
                    <h6>Notlar</h6>
                    <form method="POST" class="mb-3">
                        <input type="hidden" name="action" value="add_note">
                        <div class="mb-2">
                            <textarea name="message" class="form-control" rows="3" placeholder="Müşteri veya dahili not ekle..."></textarea>
                        </div>
                        <button class="btn btn-outline-primary">Not Ekle</button>
                    </form>

                    <?php if ($notes && $notes->num_rows > 0): ?>
                        <ul class="list-group">
                            <?php while ($n = $notes->fetch_assoc()): ?>
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong><?php echo htmlspecialchars($n['full_name'] ?? 'Siz'); ?></strong>
                                            <div><small><?php echo htmlspecialchars($n['created_at']); ?></small></div>
                                            <div><?php echo nl2br(htmlspecialchars($n['message'])); ?></div>
                                        </div>
                                    </div>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted">Henüz not yok.</p>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</div>
</body>
</html>