<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];

// merchant_id al
$stmt = $conn->prepare("SELECT merchant_id FROM Merchants WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$merchant = $res->fetch_assoc();
$stmt->close();
if (!$merchant) { echo "Mağaza bulunamadı."; exit; }
$merchant_id = $merchant['merchant_id'];

// POST eylemleri (start_preparing / hazır yap / iptal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['order_id'])) {
    $action = $_POST['action'];
    $order_id = (int)$_POST['order_id'];

    // order merchant kontrol
    $stmt = $conn->prepare("SELECT order_id FROM Orders WHERE order_id = ? AND merchant_id = ? LIMIT 1");
    $stmt->bind_param("ii", $order_id, $merchant_id);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->fetch_assoc()) { $stmt->close(); $error = 'Sipariş bulunamadı veya yetkiniz yok.'; }
    else {
        $stmt->close();
        if ($action === 'start_preparing') {
            $stmt = $conn->prepare("UPDATE Orders SET status = 'preparing' WHERE order_id = ?");
            $stmt->bind_param("i", $order_id);
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
            $msg = 'Sipariş hazırlanıyor.';
        } elseif ($action === 'ready') {
            $stmt = $conn->prepare("UPDATE Orders SET status = 'ready' WHERE order_id = ?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $stmt->close();
            // create delivery for this order if not exists
            $stmt = $conn->prepare("SELECT delivery_id FROM Deliveries WHERE order_id = ? LIMIT 1");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$r) {
                // order zone'undan uygun kurye bul (önce aynı current_zone, yoksa aktif Courier_Zones kaydı)
                $delivery_id = null;
                $zoneId = null;
                $stmt = $conn->prepare("SELECT zone_id FROM Orders WHERE order_id = ? LIMIT 1");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $zoneRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($zoneRow) { $zoneId = (int)$zoneRow['zone_id']; }

                $courierId = null;
                if ($zoneId) {
                    // Öncelik: current_zone_id eşleşen ve uygun görünen kurye
                                        $stmt = $conn->prepare("SELECT c.courier_id
                                                FROM Couriers c
                                                WHERE c.current_zone_id = ? AND c.is_available = 1
                                                ORDER BY
                                                    (SELECT COUNT(*) FROM Deliveries d WHERE d.courier_id = c.courier_id AND d.status IN ('assigned','picked_up','on_the_way','delivering')) ASC,
                                                    c.total_deliveries ASC,
                                                    RAND()
                                                LIMIT 1");
                    $stmt->bind_param("i", $zoneId);
                    $stmt->execute();
                    $courierRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($courierRow) {
                        $courierId = (int)$courierRow['courier_id'];
                    } else {
                        // Yedek: Courier_Zones üzerinden atanmış aktif kurye
                                                $stmt = $conn->prepare("SELECT cz.courier_id
                                                        FROM Courier_Zones cz
                                                        JOIN Couriers c ON cz.courier_id = c.courier_id
                                                        WHERE cz.zone_id = ? AND cz.is_active = 1 AND c.is_available = 1
                                                        ORDER BY
                                                            (SELECT COUNT(*) FROM Deliveries d WHERE d.courier_id = c.courier_id AND d.status IN ('assigned','picked_up','on_the_way','delivering')) ASC,
                                                            c.total_deliveries ASC,
                                                            RAND()
                                                        LIMIT 1");
                        $stmt->bind_param("i", $zoneId);
                        $stmt->execute();
                        $courierRow = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($courierRow) { $courierId = (int)$courierRow['courier_id']; }
                    }
                }

                if ($courierId) {
                    // Kurye bulunduysa doğrudan ata; aksi halde null nedeniyle hata alınmasın diye pas geç.
                    $stmt = $conn->prepare("INSERT INTO Deliveries (order_id, courier_id, status, created_at) VALUES (?, ?, 'assigned', NOW())");
                    $stmt->bind_param("ii", $order_id, $courierId);
                    $stmt->execute();
                    $delivery_id = $stmt->insert_id;
                    $stmt->close();

                    // notify customer and merchant via helper
                    require_once __DIR__ . '/notifications_helper.php';
                    $stmt = $conn->prepare("SELECT c.user_id FROM Orders o JOIN Customers c ON o.customer_id = c.customer_id WHERE o.order_id = ? LIMIT 1");
                    $stmt->bind_param("i", $order_id);
                    $stmt->execute();
                    $cu = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($cu && !empty($cu['user_id'])) {
                        $msgText = 'Siparişiniz mağaza tarafından hazırlandı. Kurye atandı.';
                        notify_user($conn, $cu['user_id'], null, $order_id, $delivery_id, 'order_ready', $msgText);
                    }
                    // notify merchant (you)
                    $msgText = 'Sipariş #' . $order_id . ' için kurye (' . $courierId . ') atandı.';
                    notify_user($conn, $user_id, null, $order_id, $delivery_id, 'merchant_delivery_created', $msgText);
                } else {
                    // Kurye yoksa kaydı oluşturma; kullanıcıyı bilgilendir.
                    $msg = 'Sipariş hazırlandı ancak uygun kurye bulunamadı. Lütfen kurye ata.';
                }
            }
            if (!isset($msg)) {
                $msg = 'Sipariş hazırlandı.';
            }
        } elseif ($action === 'cancel') {
            $stmt = $conn->prepare("UPDATE Orders SET status = 'cancelled' WHERE order_id = ?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $stmt->close();
            $msg = 'Sipariş iptal edildi.';
        }
    }
}

// Siparişleri listeler - Direct SQL query
$filter = $_GET['status'] ?? '';
if ($filter) {
    // Address table may vary; prefer Customer_Addresses with fallbacks
    $addr_table_exists = false;
    $r = $conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Customer_Addresses' LIMIT 1");
    if ($r && $r->num_rows > 0) { $addr_table_exists = true; }
    $address_select = $addr_table_exists
        ? "COALESCE(ca.address_line, o.delivery_address, m.address) AS delivery_address, COALESCE(ca.city, m.city, 'N/A') AS city"
        : "COALESCE(o.delivery_address, m.address) AS delivery_address, COALESCE(m.city, 'N/A') AS city";
    $join_addresses = $addr_table_exists ? "LEFT JOIN Customer_Addresses ca ON o.address_id = ca.address_id" : "";
    $sql = "SELECT 
        o.order_id,
        o.order_date,
        o.status,
        o.total_price,
        u.full_name AS customer_name,
        u.phone AS customer_phone,
        $address_select,
        d.status AS delivery_status,
        d.courier_id
    FROM Orders o
    JOIN Customers c ON o.customer_id = c.customer_id
    JOIN Users u ON c.user_id = u.user_id
    JOIN Merchants m ON o.merchant_id = m.merchant_id
    " . $join_addresses . "
    LEFT JOIN Deliveries d ON o.order_id = d.order_id
    WHERE o.merchant_id = ? AND o.status = ?
    ORDER BY o.order_date DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $merchant_id, $filter);
} else {
    // Address table may vary; prefer Customer_Addresses with fallbacks
    $addr_table_exists = false;
    $r = $conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Customer_Addresses' LIMIT 1");
    if ($r && $r->num_rows > 0) { $addr_table_exists = true; }
    $address_select = $addr_table_exists
        ? "COALESCE(ca.address_line, o.delivery_address, m.address) AS delivery_address, COALESCE(ca.city, m.city, 'N/A') AS city"
        : "COALESCE(o.delivery_address, m.address) AS delivery_address, COALESCE(m.city, 'N/A') AS city";
    $join_addresses = $addr_table_exists ? "LEFT JOIN Customer_Addresses ca ON o.address_id = ca.address_id" : "";
    $sql = "SELECT 
        o.order_id,
        o.order_date,
        o.status,
        o.total_price,
        u.full_name AS customer_name,
        u.phone AS customer_phone,
        $address_select,
        d.status AS delivery_status,
        d.courier_id
    FROM Orders o
    JOIN Customers c ON o.customer_id = c.customer_id
    JOIN Users u ON c.user_id = u.user_id
    JOIN Merchants m ON o.merchant_id = m.merchant_id
    " . $join_addresses . "
    LEFT JOIN Deliveries d ON o.order_id = d.order_id
    WHERE o.merchant_id = ?
    ORDER BY o.order_date DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $merchant_id);
}
$stmt->execute();
$orders = $stmt->get_result();
$stmt->close();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Satıcı - Siparişler</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4">
    <a href="merchant_dashboard.php">&larr; Geri</a>
    <h4 class="mt-3">Siparişler</h4>

    <?php if(isset($msg)): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
    <?php if(isset($error)): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="mb-3">
        <a href="merchant_orders.php" class="btn btn-sm btn-outline-secondary">Hepsi</a>
        <a href="merchant_orders.php?status=pending" class="btn btn-sm btn-outline-secondary">Yeni</a>
        <a href="merchant_orders.php?status=preparing" class="btn btn-sm btn-outline-secondary">Hazırlık</a>
        <a href="merchant_orders.php?status=ready" class="btn btn-sm btn-outline-secondary">Hazır</a>
        <a href="merchant_orders.php?status=on_delivery" class="btn btn-sm btn-outline-secondary">Dağıtıma Verildi</a>
    </div>

    <?php if ($orders && $orders->num_rows > 0): ?>
        <ul class="list-group">
            <?php while ($o = $orders->fetch_assoc()): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>#<?php echo $o['order_id']; ?></strong> — <?php echo htmlspecialchars($o['customer_name']); ?>
                        <div><small><?php echo htmlspecialchars($o['order_date']); ?></small></div>
                        <div><small>Durum: <?php echo htmlspecialchars($o['status']); ?></small></div>
                    </div>
                    <div class="text-end">
                        <form method="POST" style="display:inline-block;">
                            <input type="hidden" name="order_id" value="<?php echo $o['order_id']; ?>">
                            <?php if ($o['status'] === 'pending'): ?>
                                <button name="action" value="start_preparing" class="btn btn-sm btn-primary">Hazırlamaya Başla</button>
                            <?php endif; ?>
                            <?php if ($o['status'] === 'preparing'): ?>
                                <button name="action" value="ready" class="btn btn-sm btn-success">Hazırlandı</button>
                            <?php endif; ?>
                            <button name="action" value="cancel" class="btn btn-sm btn-danger">İptal Et</button>
                        </form>
                        <a href="merchant_order_details.php?id=<?php echo $o['order_id']; ?>" class="btn btn-sm btn-outline-primary mt-1">Detay</a>
                    </div>
                </li>
            <?php endwhile; ?>
        </ul>
    <?php else: ?>
        <p class="text-muted">Hiç sipariş yok.</p>
    <?php endif; ?>

</div>
</body>
</html>