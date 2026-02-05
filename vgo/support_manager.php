<?php
session_start();
include 'db.php';
include 'classes/SupportManager.php';
include 'classes/SupportOperator.php';

// Yönetici kontrolü (role_id = 1 Admin veya role_id = 6 Manager)
if (!isset($_SESSION['user_id']) || ($_SESSION['role_id'] != 1 && $_SESSION['role_id'] != 6)) {
    header('Location: login.php');
    exit;
}

$supportManager = new SupportManager($conn);
$supportOperator = new SupportOperator($conn);

// Filtreler
$filters = [];
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $filters['status'] = $_GET['status'];
}
if (isset($_GET['priority']) && !empty($_GET['priority'])) {
    $filters['priority'] = $_GET['priority'];
}
if (isset($_GET['operator']) && !empty($_GET['operator'])) {
    $filters['operator_id'] = intval($_GET['operator']);
}

// Ticket yeniden atama
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'reassign') {
    $ticket_id = intval($_POST['ticket_id']);
    $new_operator_id = intval($_POST['operator_id']);
    
    if ($supportManager->reassignTicket($ticket_id, $new_operator_id, $_SESSION['user_id'])) {
        header('Location: support_manager.php?reassigned=1');
        exit;
    } else {
        $error = "Yeniden atama başarısız.";
    }
}

// Otomatik atama
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'auto_assign') {
    $ticket_id = intval($_POST['ticket_id']);
    
    if ($supportManager->autoAssignTicket($ticket_id)) {
        header('Location: support_manager.php?assigned=1');
        exit;
    } else {
        $error = "Otomatik atama başarısız. Müsait operatör yok.";
    }
}

$stats = $supportManager->getDashboardStats();
$tickets = $supportManager->getAllTickets($filters, 50);
$category_stats = $supportManager->getTicketsByCategory(30);
$operator_performance = $supportManager->getOperatorPerformance(30);
$available_operators = $supportOperator->getAvailableOperators();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Destek Yönetim Paneli - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>

<div class="container-fluid mt-4">
    <h4 class="mb-4">Destek Yönetim Paneli</h4>

    <?php if (isset($_GET['reassigned'])): ?>
        <div class="alert alert-success">Ticket başarıyla yeniden atandı!</div>
    <?php endif; ?>
    <?php if (isset($_GET['assigned'])): ?>
        <div class="alert alert-success">Ticket otomatik olarak atandı!</div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- İstatistikler -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-danger"><?php echo $stats['open_tickets']; ?></h3>
                    <p class="text-muted mb-0">Açık</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-primary"><?php echo $stats['in_progress']; ?></h3>
                    <p class="text-muted mb-0">İşleniyor</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-warning"><?php echo $stats['waiting_response']; ?></h3>
                    <p class="text-muted mb-0">Cevap Bekliyor</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-success"><?php echo $stats['resolved_today']; ?></h3>
                    <p class="text-muted mb-0">Bugün Çözülen</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-info"><?php echo $stats['online_operators']; ?></h3>
                    <p class="text-muted mb-0">Online Operatör</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-secondary"><?php echo $stats['avg_response_time']; ?> dk</h3>
                    <p class="text-muted mb-0">Ort. Cevap</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtreler -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Durum</label>
                    <select class="form-select" name="status">
                        <option value="">Tümü</option>
                        <option value="open" <?php echo (isset($_GET['status']) && $_GET['status'] == 'open') ? 'selected' : ''; ?>>Açık</option>
                        <option value="in_progress" <?php echo (isset($_GET['status']) && $_GET['status'] == 'in_progress') ? 'selected' : ''; ?>>İşleniyor</option>
                        <option value="waiting_response" <?php echo (isset($_GET['status']) && $_GET['status'] == 'waiting_response') ? 'selected' : ''; ?>>Cevap Bekliyor</option>
                        <option value="resolved" <?php echo (isset($_GET['status']) && $_GET['status'] == 'resolved') ? 'selected' : ''; ?>>Çözüldü</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Öncelik</label>
                    <select class="form-select" name="priority">
                        <option value="">Tümü</option>
                        <option value="urgent" <?php echo (isset($_GET['priority']) && $_GET['priority'] == 'urgent') ? 'selected' : ''; ?>>Acil</option>
                        <option value="high" <?php echo (isset($_GET['priority']) && $_GET['priority'] == 'high') ? 'selected' : ''; ?>>Yüksek</option>
                        <option value="medium" <?php echo (isset($_GET['priority']) && $_GET['priority'] == 'medium') ? 'selected' : ''; ?>>Orta</option>
                        <option value="low" <?php echo (isset($_GET['priority']) && $_GET['priority'] == 'low') ? 'selected' : ''; ?>>Düşük</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Operatör</label>
                    <select class="form-select" name="operator">
                        <option value="">Tümü</option>
                        <?php foreach ($available_operators as $op): ?>
                            <option value="<?php echo $op['user_id']; ?>" <?php echo (isset($_GET['operator']) && $_GET['operator'] == $op['user_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($op['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">Filtrele</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Ticket Listesi -->
    <div class="card mb-4">
        <div class="card-body">
            <h6>Destek Talepleri</h6>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Konu</th>
                            <th>Müşteri</th>
                            <th>Kategori</th>
                            <th>Durum</th>
                            <th>Öncelik</th>
                            <th>Operatör</th>
                            <th>Güncelleme</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $t): ?>
                            <tr>
                                <td><?php echo $t['ticket_id']; ?></td>
                                <td><?php echo htmlspecialchars($t['subject']); ?></td>
                                <td><small><?php echo htmlspecialchars($t['user_name']); ?></small></td>
                                <td><small><?php echo htmlspecialchars($t['category_name']); ?></small></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $t['status'] == 'resolved' || $t['status'] == 'closed' ? 'success' : 
                                            ($t['status'] == 'in_progress' ? 'primary' : 
                                            ($t['status'] == 'waiting_response' ? 'warning' : 'secondary')); 
                                    ?>"><?php echo $t['status']; ?></span>
                                </td>
                                <td>
                                    <span class="text-<?php 
                                        echo $t['priority'] == 'urgent' ? 'danger' : 
                                            ($t['priority'] == 'high' ? 'warning' : 
                                            ($t['priority'] == 'medium' ? 'info' : 'secondary')); 
                                    ?>"><?php echo $t['priority']; ?></span>
                                </td>
                                <td><small><?php echo $t['operator_name'] ? htmlspecialchars($t['operator_name']) : '<span class="text-muted">-</span>'; ?></small></td>
                                <td><small><?php echo htmlspecialchars($t['updated_at']); ?></small></td>
                                <td>
                                    <?php if (!$t['operator_name']): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="auto_assign">
                                            <input type="hidden" name="ticket_id" value="<?php echo $t['ticket_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success">Otomatik Ata</button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#reassignModal<?php echo $t['ticket_id']; ?>">Yeniden Ata</button>
                                    <?php endif; ?>
                                    
                                    <!-- Yeniden Atama Modal -->
                                    <div class="modal fade" id="reassignModal<?php echo $t['ticket_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-sm">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <input type="hidden" name="action" value="reassign">
                                                    <input type="hidden" name="ticket_id" value="<?php echo $t['ticket_id']; ?>">
                                                    <div class="modal-header">
                                                        <h6 class="modal-title">Yeniden Ata</h6>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <select class="form-select" name="operator_id" required>
                                                            <option value="">Operatör Seç...</option>
                                                            <?php foreach ($available_operators as $op): ?>
                                                                <option value="<?php echo $op['user_id']; ?>">
                                                                    <?php echo htmlspecialchars($op['full_name']); ?> (<?php echo $op['current_tickets']; ?> aktif)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="submit" class="btn btn-primary btn-sm">Ata</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- İstatistik Grafikleri -->
    <div class="row">
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-body">
                    <h6>Kategoriye Göre Dağılım (Son 30 Gün)</h6>
                    <table class="table table-sm">
                        <tbody>
                            <?php foreach ($category_stats as $cat): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                    <td class="text-end"><strong><?php echo $cat['count']; ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-body">
                    <h6>Operatör Performansı (Son 30 Gün)</h6>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Operatör</th>
                                <th>Atanan</th>
                                <th>Çözülen</th>
                                <th>Ort. Cevap</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($operator_performance as $op): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($op['full_name']); ?></td>
                                    <td><?php echo $op['total_tickets']; ?></td>
                                    <td><?php echo $op['resolved']; ?></td>
                                    <td><?php echo $op['avg_response_time'] ? round($op['avg_response_time']) . ' dk' : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
