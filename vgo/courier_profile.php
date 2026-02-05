<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];

// courier
$stmt = $conn->prepare("SELECT c.*, u.full_name, u.phone, u.email, z.city AS zone_city, z.zone_name AS zone_name
    FROM Couriers c
    JOIN Users u ON c.user_id = u.user_id
    LEFT JOIN Zones z ON c.current_zone_id = z.zone_id
    WHERE c.user_id = ?
    LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$c = $res->fetch_assoc();
$stmt->close();
if (!$c) { echo "Kurye bulunamadı."; exit; }

// quick stats
$stats = ['delivered_count' => 0];
$stmt = $conn->prepare("SELECT COUNT(*) AS delivered_count FROM Deliveries WHERE courier_id = ? AND status = 'delivered'");
if ($stmt) {
    $stmt->bind_param('i', $c['courier_id']);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc() ?: $stats;
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['toggle_availability'])) {
        $new = isset($_POST['is_available']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE Couriers SET is_available = ? WHERE courier_id = ?");
        $stmt->bind_param("ii", $new, $c['courier_id']);
        $stmt->execute();
        $stmt->close();
        header('Location: courier_profile.php');
        exit;
    }
    if (isset($_POST['update_vehicle'])) {
        $vt = $_POST['vehicle_type'];
        $plate = $_POST['vehicle_plate'];
        $stmt = $conn->prepare("UPDATE Couriers SET vehicle_type = ?, vehicle_plate = ? WHERE courier_id = ?");
        $stmt->bind_param("ssi", $vt, $plate, $c['courier_id']);
        $stmt->execute();
        $stmt->close();
        header('Location: courier_profile.php');
        exit;
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kurye Profil - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4">
    <a href="courier_dashboard.php">&larr; Geri</a>
    <h4 class="mt-3">Profil</h4>
    <div class="card">
        <div class="card-body">
            <p><strong><?php echo htmlspecialchars($c['full_name']); ?></strong></p>
            <p>Telefon: <?php echo htmlspecialchars($c['phone']); ?></p>
            <p>E-posta: <?php echo htmlspecialchars($c['email']); ?></p>
            <p>Bölge: <?php echo htmlspecialchars(trim(($c['zone_city'] ?? '') . ' / ' . ($c['zone_name'] ?? 'Belirsiz'))); ?></p>
            <p>Ortalama Puan: <?php echo htmlspecialchars((string)($c['rating_avg'] ?? '0')); ?> / 5</p>
            <p>Teslimat Sayısı: <?php echo (int)($stats['delivered_count'] ?? 0); ?></p>
            <a class="btn btn-sm btn-outline-secondary mb-3" href="courier_profile_detail.php">Detaylı Profil</a>
            <form method="POST">
                <div class="form-check mb-3">
                    <input type="checkbox" name="is_available" class="form-check-input" id="is_available" <?php echo $c['is_available'] ? 'checked' : ''; ?> value="1">
                    <label class="form-check-label" for="is_available">Uygun</label>
                </div>
                <button name="toggle_availability" class="btn btn-outline-primary">Güncelle</button>
            </form>

            <hr>
            <h6>Araç</h6>
            <form method="POST">
                <div class="mb-2">
                    <label>Araç Tipi</label>
                    <input name="vehicle_type" class="form-control" value="<?php echo htmlspecialchars($c['vehicle_type']); ?>">
                </div>
                <div class="mb-2">
                    <label>Plaka</label>
                    <input name="vehicle_plate" class="form-control" value="<?php echo htmlspecialchars($c['vehicle_plate']); ?>">
                </div>
                <button name="update_vehicle" class="btn btn-primary">Güncelle</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>