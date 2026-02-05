<?php
session_start();
include 'db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php'); exit;
}
$user_id = $_SESSION['user_id'];

function vgo_clear_call_results(mysqli $conn): void {
    while ($conn->more_results() && $conn->next_result()) {
        $r = $conn->store_result();
        if ($r) $r->free();
    }
}

function notification_label($type){
    switch ($type) {
        case 'order_confirmed': return 'Sipariş alındı';
        case 'order_preparing': return 'Sipariş hazırlanıyor';
        case 'order_ready': return 'Sipariş hazır';
        case 'order_cancelled': return 'Sipariş iptal edildi';
        case 'order_delivering': return 'Sipariş yolda';
        case 'order_delivered': return 'Sipariş teslim edildi';
        case 'new_order': return 'Yeni sipariş';
        case 'delivery_assigned': return 'Kurye atandı';
        case 'delivery_picked': return 'Kurye teslim aldı';
        case 'delivery_delivered': return 'Kurye teslim etti';
        case 'delivered': return 'Sipariş teslim edildi';
        case 'merchant_delivery_created': return 'Teslimat oluşturuldu';
        case 'order_note': return 'Mağaza notu';
        default: return $type;
    }
}

// mark as read actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_all'])) {
        $stmt = $conn->prepare('CALL MarkAllNotificationsRead(?)');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
        vgo_clear_call_results($conn);
        header('Location: notifications.php'); exit;
    }
    if (isset($_POST['mark_id'])) {
        $nid = (int)$_POST['mark_id'];
        $stmt = $conn->prepare('CALL MarkNotificationRead(?, ?)');
        $stmt->bind_param('ii', $nid, $user_id);
        $stmt->execute();
        $stmt->close();
        vgo_clear_call_results($conn);
        header('Location: notifications.php'); exit;
    }
}

$limit = 200;
$stmt = $conn->prepare('CALL GetNotificationsForUser(?, ?)');
$stmt->bind_param('ii', $user_id, $limit);
$stmt->execute();
$notes = $stmt->get_result();
$stmt->close();
vgo_clear_call_results($conn);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bildirimler</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4">
    <h5>Bildirimler</h5>
    <form method="post" class="mb-3">
        <button name="mark_all" class="btn btn-sm btn-outline-secondary">Tümünü Okundu Yap</button>
    </form>
    <?php if ($notes && $notes->num_rows>0): ?>
        <ul class="list-group">
            <?php while ($n = $notes->fetch_assoc()): ?>
                <li class="list-group-item d-flex justify-content-between align-items-start <?php if(!$n['is_read']) echo 'bg-white'; ?>">
                    <div>
                        <div><strong><?php echo htmlspecialchars(notification_label($n['type'])); ?></strong> — <?php echo htmlspecialchars($n['message']); ?></div>
                        <div><small class="text-muted"><?php echo htmlspecialchars($n['created_at']); ?></small></div>
                        <?php if ($n['order_id']): ?>
                            <div><a href="order_details.php?id=<?php echo (int)$n['order_id']; ?>">Sipariş detayına git</a></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if (!$n['is_read']): ?>
                            <form method="post"><button name="mark_id" value="<?php echo (int)$n['notification_id']; ?>" class="btn btn-sm btn-primary">Okundu</button></form>
                        <?php else: ?>
                            <span class="text-muted">Okundu</span>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endwhile; ?>
        </ul>
    <?php else: ?>
        <p class="text-muted">Yeni bildirim yok.</p>
    <?php endif; ?>
</div>
</body>
</html>