<?php
session_start();
require_once 'db.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Get admin info
$stmt = $conn->prepare("SELECT a.*, u.full_name, u.email FROM Admins a JOIN Users u ON a.user_id = u.user_id WHERE a.user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

// Get system statistics
$total_users = $conn->query("SELECT COUNT(*) as count FROM Users")->fetch_assoc()['count'];
$total_orders = $conn->query("SELECT COUNT(*) as count FROM Orders")->fetch_assoc()['count'];
$total_merchants = $conn->query("SELECT COUNT(*) as count FROM Merchants")->fetch_assoc()['count'];
$total_couriers = $conn->query("SELECT COUNT(*) as count FROM Couriers")->fetch_assoc()['count'];
$total_customers = $conn->query("SELECT COUNT(*) as count FROM Customers")->fetch_assoc()['count'];
$pending_orders = $conn->query("SELECT COUNT(*) as count FROM Orders WHERE status = 'pending'")->fetch_assoc()['count'];

// Get recent orders
$recent_orders = $conn->query("SELECT o.order_id, o.order_date, o.status, o.total_price, 
    u.full_name as customer_name, m.store_name
    FROM Orders o
    JOIN Customers c ON o.customer_id = c.customer_id
    JOIN Users u ON c.user_id = u.user_id
    JOIN Merchants m ON o.merchant_id = m.merchant_id
    ORDER BY o.order_date DESC LIMIT 10");

// Get recent users
$recent_users = $conn->query("SELECT u.user_id, u.full_name, u.email, r.role_name, u.created_at
    FROM Users u
    JOIN Roles r ON u.role_id = r.role_id
    ORDER BY u.created_at DESC LIMIT 10");

?>
<!DOCTYPE html>
<html>
<head>
    <title>VGO - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'header.php'; ?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2><i class="fas fa-shield-alt"></i> Admin Panel</h2>
            <p class="text-muted">Hoş geldiniz, <?php echo htmlspecialchars($admin['full_name']); ?> (<?php echo htmlspecialchars($admin['access_level']); ?> seviye)</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5><i class="fas fa-users"></i> Kullanıcılar</h5>
                    <h2><?php echo $total_users; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5><i class="fas fa-shopping-cart"></i> Siparişler</h5>
                    <h2><?php echo $total_orders; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5><i class="fas fa-store"></i> Restoranlar</h5>
                    <h2><?php echo $total_merchants; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5><i class="fas fa-motorcycle"></i> Kuryeler</h5>
                    <h2><?php echo $total_couriers; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-secondary text-white">
                <div class="card-body">
                    <h5><i class="fas fa-user-friends"></i> Müşteriler</h5>
                    <h2><?php echo $total_customers; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5><i class="fas fa-clock"></i> Bekleyen</h5>
                    <h2><?php echo $pending_orders; ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Recent Orders -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-list"></i> Son Siparişler</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Müşteri</th>
                                    <th>Restoran</th>
                                    <th>Tutar</th>
                                    <th>Durum</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($order = $recent_orders->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $order['order_id']; ?></td>
                                    <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($order['store_name']); ?></td>
                                    <td>₺<?php echo number_format($order['total_price'], 2); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo $order['status']; ?></span></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Users -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-user-plus"></i> Yeni Kullanıcılar</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Ad</th>
                                    <th>Email</th>
                                    <th>Rol</th>
                                    <th>Tarih</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($user = $recent_users->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><span class="badge bg-info"><?php echo $user['role_name']; ?></span></td>
                                    <td><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-tools"></i> Hızlı İşlemler</h5>
                </div>
                <div class="card-body">
                    <a href="admin_users.php" class="btn btn-primary"><i class="fas fa-users"></i> Kullanıcı Yönetimi</a>
                    <a href="admin_merchants.php" class="btn btn-info"><i class="fas fa-store"></i> Restoran Yönetimi</a>
                    <a href="admin_couriers.php" class="btn btn-warning"><i class="fas fa-motorcycle"></i> Kurye Yönetimi</a>
                    <a href="admin_orders.php" class="btn btn-success"><i class="fas fa-shopping-cart"></i> Sipariş Yönetimi</a>
                    <a href="admin_reports.php" class="btn btn-secondary"><i class="fas fa-chart-bar"></i> Raporlar</a>
                    <a href="support_manager.php" class="btn btn-danger"><i class="fas fa-headset"></i> Destek Sistemi</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
