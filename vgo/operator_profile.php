<?php
session_start();
require_once 'db.php';

// Check if user is operator
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Get operator detailed info
$stmt = $conn->prepare("SELECT o.*, u.full_name, u.email, u.phone, u.profile_image
    FROM Operators o
    JOIN Users u ON o.user_id = u.user_id
    WHERE o.user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$operator = $stmt->get_result()->fetch_assoc();

// Get ticket statistics
$stmt = $conn->prepare("SELECT 
    COUNT(*) as total_tickets,
    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as resolved,
    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_tickets,
    AVG(CASE WHEN status = 'closed' AND resolved_at IS NOT NULL 
        THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END) as avg_resolution_time
    FROM SupportTickets WHERE assigned_operator_id = ?");
$stmt->bind_param("i", $operator['operator_id']);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

// Get recent tickets with customer info
$stmt = $conn->prepare("SELECT t.ticket_id, t.subject, t.priority, t.status, t.category_id,
    t.created_at, t.resolved_at, c.category_name as category_name, u.full_name as customer_name, u.email
    FROM SupportTickets t
    LEFT JOIN SupportCategories c ON t.category_id = c.category_id
    JOIN Users u ON t.user_id = u.user_id
    WHERE t.assigned_operator_id = ?
    ORDER BY t.created_at DESC LIMIT 20");
$stmt->bind_param("i", $operator['operator_id']);
$stmt->execute();
$recent_tickets = $stmt->get_result();

// Get quick replies for this operator
$stmt = $conn->prepare("SELECT * FROM SupportQuickReplies 
    WHERE operator_id = ? OR operator_id IS NULL 
    ORDER BY usage_count DESC LIMIT 5");
$stmt->bind_param("i", $operator['operator_id']);
$stmt->execute();
$quick_replies = $stmt->get_result();

?>
<!DOCTYPE html>
<html>
<head>
    <title>VGO - Operatör Profil Detayı</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'header.php'; ?>

<div class="container mt-4">
    <div class="row">
        <!-- Profile Info -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <?php if ($operator['profile_image']): ?>
                        <img src="<?php echo htmlspecialchars($operator['profile_image']); ?>" class="rounded-circle mb-3" width="150" height="150">
                    <?php else: ?>
                        <div class="rounded-circle bg-primary d-inline-block mb-3" style="width:150px;height:150px;line-height:150px;">
                            <i class="fas fa-headset fa-5x text-white"></i>
                        </div>
                    <?php endif; ?>
                    <h4><?php echo htmlspecialchars($operator['full_name']); ?></h4>
                    <p class="text-muted">Destek Operatörü</p>
                    <hr>
                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($operator['email']); ?></p>
                    <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($operator['phone']); ?></p>
                    <hr>
                    <p><strong>Departman:</strong> <?php echo htmlspecialchars($operator['department'] ?? 'Genel Destek'); ?></p>
                    <p><strong>Vardiya Saati:</strong> <?php echo htmlspecialchars($operator['shift_time'] ?? '09:00 - 18:00'); ?></p>
                    <p>
                        <strong>Durum:</strong> 
                        <?php if ($operator['is_active']): ?>
                            <span class="badge bg-success">Aktif</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Pasif</span>
                        <?php endif; ?>
                    </p>
                    <p><strong>Kayıt Tarihi:</strong> <?php echo date('d.m.Y', strtotime($operator['created_at'])); ?></p>
                </div>
            </div>

            <!-- Quick Replies -->
            <div class="card mt-3">
                <div class="card-header">
                    <h5><i class="fas fa-bolt"></i> Hızlı Yanıtlar</h5>
                </div>
                <div class="card-body">
                    <?php if ($quick_replies->num_rows > 0): ?>
                        <?php while ($reply = $quick_replies->fetch_assoc()): ?>
                        <div class="mb-3 p-2 border-start border-4 border-primary">
                            <h6><?php echo htmlspecialchars($reply['title']); ?></h6>
                            <small class="text-muted"><?php echo substr($reply['message'], 0, 80); ?>...</small>
                            <div><small class="badge bg-info"><?php echo $reply['usage_count']; ?> kullanım</small></div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-muted">Henüz hızlı yanıt tanımlanmamış</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Statistics & Tickets -->
        <div class="col-md-8">
            <!-- Statistics Cards -->
            <div class="row mb-3">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h6>Toplam Ticket</h6>
                            <h3><?php echo $stats['total_tickets']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h6>Çözülmüş</h6>
                            <h3><?php echo $stats['resolved']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <h6>Devam Eden</h6>
                            <h3><?php echo $stats['in_progress']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h6>Ort. Çözüm</h6>
                            <h3><?php echo round($stats['avg_resolution_time'] ?? 0); ?> dk</h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Performance Chart -->
            <div class="card mb-3">
                <div class="card-header">
                    <h5><i class="fas fa-chart-line"></i> Performans Özeti</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Çözüm Oranı</label>
                                <div class="progress" style="height: 25px;">
                                    <?php 
                                    $resolution_rate = $stats['total_tickets'] > 0 ? ($stats['resolved'] / $stats['total_tickets']) * 100 : 0;
                                    ?>
                                    <div class="progress-bar bg-success" style="width: <?php echo $resolution_rate; ?>%">
                                        <?php echo round($resolution_rate); ?>%
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>İş Yükü</label>
                                <div class="progress" style="height: 25px;">
                                    <?php 
                                    $workload = $stats['total_tickets'] > 0 ? (($stats['in_progress'] + $stats['open_tickets']) / $stats['total_tickets']) * 100 : 0;
                                    ?>
                                    <div class="progress-bar bg-warning" style="width: <?php echo $workload; ?>%">
                                        <?php echo round($workload); ?>%
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Tickets -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-ticket-alt"></i> Son Destek Talepleri</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Konu</th>
                                    <th>Müşteri</th>
                                    <th>Kategori</th>
                                    <th>Öncelik</th>
                                    <th>Durum</th>
                                    <th>Tarih</th>
                                    <th>Çözüm Süresi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($ticket = $recent_tickets->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $ticket['ticket_id']; ?></td>
                                    <td><?php echo htmlspecialchars(substr($ticket['subject'], 0, 30)); ?></td>
                                    <td>
                                        <small><?php echo htmlspecialchars($ticket['customer_name']); ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($ticket['category_name']); ?></span></td>
                                    <td>
                                        <?php 
                                        $priority_colors = ['low' => 'info', 'medium' => 'warning', 'high' => 'danger'];
                                        $color = $priority_colors[$ticket['priority']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>"><?php echo $ticket['priority']; ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                        $status_colors = ['open' => 'primary', 'in_progress' => 'warning', 'closed' => 'success'];
                                        $color = $status_colors[$ticket['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>"><?php echo $ticket['status']; ?></span>
                                    </td>
                                    <td><small><?php echo date('d.m.Y H:i', strtotime($ticket['created_at'])); ?></small></td>
                                    <td>
                                        <?php if ($ticket['resolved_at']): ?>
                                            <?php 
                                            $duration = (strtotime($ticket['resolved_at']) - strtotime($ticket['created_at'])) / 60;
                                            echo round($duration) . ' dk';
                                            ?>
                                        <?php else: ?>
                                            <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
