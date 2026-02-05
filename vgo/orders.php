<?php
session_start();
include 'db.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];

function status_label($status){
    switch ($status) {
        case 'pending': return 'Beklemede';
        case 'accepted': return 'Onaylandı';
        case 'preparing': return 'Hazırlanıyor';
        case 'ready': return 'Hazır';
        case 'delivering': return 'Yolda';
        case 'delivered': return 'Teslim edildi';
        case 'cancelled': return 'İptal edildi';
        default: return $status;
    }
}

// Müşteri id
$stmt = $conn->prepare("SELECT customer_id FROM Customers WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$customer = $res->fetch_assoc();
$customer_id = $customer ? $customer['customer_id'] : null;
$stmt->close();

$orders = [];
if ($customer_id) {
    $limit = 100;
    $stmt = $conn->prepare('CALL GetCustomerOrders(?, ?)');
    $stmt->bind_param('ii', $customer_id, $limit);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();

    while ($conn->more_results() && $conn->next_result()) {
        $r = $conn->store_result();
        if ($r) $r->free();
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Siparişlerim - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4">
    <a href="dashboard.php">&larr; Geri</a>
    <h4 class="mt-3">Tüm Siparişlerim</h4>
    <?php if ($orders && $orders->num_rows > 0): ?>
        <ul class="list-group mt-3">
            <?php while ($o = $orders->fetch_assoc()): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?php echo htmlspecialchars($o['store_name']); ?></strong>
                        <div><small><?php echo htmlspecialchars($o['order_date']); ?></small></div>
                        <?php $status = $o['order_status'] ?? ($o['status'] ?? ($o['delivery_status'] ?? '')); ?>
                        <div><small>Durum: <?php echo htmlspecialchars(status_label($status)); ?></small></div>
                    </div>
                    <div class="text-end">
                        <div><?php echo number_format($o['total_price'],2); ?> ₺</div>
                        <a href="order_details.php?id=<?php echo $o['order_id']; ?>">Detay</a>
                    </div>
                </li>
            <?php endwhile; ?>
        </ul>
    <?php else: ?>
        <p class="text-muted mt-3">Hiç siparişiniz bulunmuyor.</p>
    <?php endif; ?>
</div>
</body>
</html>