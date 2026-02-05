<?php
session_start();
include 'db.php';
include 'classes/SupportOperator.php';
include 'classes/SupportTicket.php';
include 'classes/SupportAI.php';
require_once __DIR__ . '/audit_helper.php';

// Operatör kontrolü (role_id = 2)
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) {
    header('Location: login.php');
    exit;
}

$operator_id = $_SESSION['user_id'];
$supportOperator = new SupportOperator($conn);
$supportTicket = new SupportTicket($conn);
$supportAI = new SupportAI($conn);

function vgo_role_name_for_support($roleId) {
    $roleId = (int)$roleId;
    if ($roleId === 5) return 'merchant';
    if ($roleId === 3) return 'courier';
    return 'customer';
}

function vgo_role_label_tr($roleId) {
    $roleId = (int)$roleId;
    if ($roleId === 5) return 'Restoran';
    if ($roleId === 3) return 'Kurye';
    if ($roleId === 4) return 'Müşteri';
    return 'Kullanıcı';
}

function vgo_try_get_table_columns(mysqli $conn, string $tableName): array {
    $cols = [];
    try {
        $res = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($tableName) . "`");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                if (!empty($r['Field'])) {
                    $cols[strtolower((string)$r['Field'])] = true;
                }
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return $cols;
}

function vgo_fetch_order_context(mysqli $conn, int $orderId): array {
    if ($orderId <= 0) return [];
    $cols = vgo_try_get_table_columns($conn, 'Orders');
    if (empty($cols)) return [];

    $select = ['o.order_id'];
    if (isset($cols['status'])) $select[] = 'o.status';
    if (isset($cols['order_date'])) $select[] = 'o.order_date';
    if (isset($cols['created_at'])) $select[] = 'o.created_at';
    if (isset($cols['total_price'])) $select[] = 'o.total_price';
    if (isset($cols['total_amount'])) $select[] = 'o.total_amount';
    if (isset($cols['delivery_fee'])) $select[] = 'o.delivery_fee';

    // Optional joins if present
    if (isset($cols['merchant_id'])) {
        $mcols = vgo_try_get_table_columns($conn, 'Merchants');
        if (!empty($mcols)) {
            if (isset($mcols['store_name'])) $select[] = 'm.store_name';
        }
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM Orders o ';
    if (in_array('m.store_name', $select, true)) {
        $sql .= 'LEFT JOIN Merchants m ON o.merchant_id = m.merchant_id ';
    }
    $sql .= 'WHERE o.order_id = ? LIMIT 1';

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?? [];
    $stmt->close();
    return $row;
}

function vgo_fetch_delivery_context(mysqli $conn, int $deliveryId, int $orderId): array {
    $dcols = vgo_try_get_table_columns($conn, 'Deliveries');
    if (empty($dcols)) return [];

    $select = ['d.delivery_id'];
    if (isset($dcols['status'])) $select[] = 'd.status';
    if (isset($dcols['estimated_arrival_time'])) $select[] = 'd.estimated_arrival_time';
    if (isset($dcols['picked_up_at'])) $select[] = 'd.picked_up_at';
    if (isset($dcols['delivered_at'])) $select[] = 'd.delivered_at';

    $where = '';
    $types = '';
    $params = [];
    if ($deliveryId > 0 && isset($dcols['delivery_id'])) {
        $where = 'WHERE d.delivery_id = ?';
        $types = 'i';
        $params[] = $deliveryId;
    } elseif ($orderId > 0 && isset($dcols['order_id'])) {
        $where = 'WHERE d.order_id = ?';
        $types = 'i';
        $params[] = $orderId;
    } else {
        return [];
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM Deliveries d ' . $where . ' LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?? [];
    $stmt->close();
    return $row;
}

// Operatör durumunu online yap
$supportOperator->updateStatus($operator_id, true, true);

// Ticket kabul etme
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'accept') {
    $ticket_id = intval($_POST['ticket_id']);
    if ($supportOperator->assignTicket($ticket_id, $operator_id)) {
        vgo_audit_log($conn, 'support.ticket.accept', 'SupportTicket', $ticket_id);
        header('Location: operator_dashboard.php?ticket=' . $ticket_id . '&accepted=1');
        exit;
    } else {
        $error = "Ticket kabul edilemedi.";
    }
}

// Ticket durum güncelleme
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'set_status') {
    $ticket_id = intval($_POST['ticket_id']);
    $status = trim($_POST['status']);
    if ($supportOperator->setTicketStatus($ticket_id, $operator_id, $status)) {
        vgo_audit_log($conn, 'support.ticket.set_status', 'SupportTicket', $ticket_id, ['status' => $status]);
        header('Location: operator_dashboard.php?ticket=' . $ticket_id . '&updated=1');
        exit;
    }
}

// Ticket öncelik güncelleme
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'set_priority') {
    $ticket_id = intval($_POST['ticket_id']);
    $priority = trim($_POST['priority']);
    if ($supportOperator->setTicketPriority($ticket_id, $operator_id, $priority)) {
        vgo_audit_log($conn, 'support.ticket.set_priority', 'SupportTicket', $ticket_id, ['priority' => $priority]);
        header('Location: operator_dashboard.php?ticket=' . $ticket_id . '&updated=1');
        exit;
    }
}

// Mesaj gönderme
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'reply') {
    $ticket_id = intval($_POST['ticket_id']);
    $message = trim($_POST['message']);
    
    if (!empty($message)) {
        if ($supportOperator->sendMessage($ticket_id, $operator_id, $message)) {
            vgo_audit_log($conn, 'support.ticket.reply_operator', 'SupportTicket', $ticket_id, [
                'message_len' => strlen($message),
            ]);
            header('Location: operator_dashboard.php?ticket=' . $ticket_id . '&sent=1');
            exit;
        } else {
            $error = 'Ticket kapalı olduğu için mesaj gönderilemez.';
        }
    }
}

// Ticket kapatma
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'close') {
    $ticket_id = intval($_POST['ticket_id']);
    if ($supportOperator->closeTicket($ticket_id, $operator_id)) {
        vgo_audit_log($conn, 'support.ticket.close_operator', 'SupportTicket', $ticket_id);
        header('Location: operator_dashboard.php?closed=1');
        exit;
    }
}

// Tek ticket görünümü
if (isset($_GET['ticket'])) {
    $ticket_id = intval($_GET['ticket']);
    $ticket = $supportTicket->getTicketById($ticket_id);
    
    if (!$ticket) {
        header('Location: operator_dashboard.php');
        exit;
    }
    
    $messages = $supportTicket->getTicketMessages($ticket_id);

    // Quick replies: requester's role determines tone/content
    $requesterRoleName = vgo_role_name_for_support($ticket['user_role_id'] ?? 4);
    $quick_replies_operator = $supportAI->getQuickReplies($requesterRoleName);

    $orderContext = [];
    $deliveryContext = [];
    $ticketOrderId = (int)($ticket['order_id'] ?? 0);
    $ticketDeliveryId = (int)($ticket['delivery_id'] ?? 0);
    if ($ticketOrderId > 0) {
        $orderContext = vgo_fetch_order_context($conn, $ticketOrderId);
    }
    if ($ticketDeliveryId > 0 || $ticketOrderId > 0) {
        $deliveryContext = vgo_fetch_delivery_context($conn, $ticketDeliveryId, $ticketOrderId);
    }
} else {
    // Liste görünümü
    $filters = [
        'status' => isset($_GET['status']) ? trim((string)$_GET['status']) : '',
        'priority' => isset($_GET['priority']) ? trim((string)$_GET['priority']) : '',
        'role_id' => isset($_GET['role']) ? (int)$_GET['role'] : 0,
        'q' => isset($_GET['q']) ? trim((string)$_GET['q']) : '',
    ];
    $assigned_tickets = $supportOperator->getAssignedTickets($operator_id, $filters);
    $unassigned_tickets = $supportOperator->getUnassignedTickets($filters, 20);
    $stats = $supportOperator->getOperatorStats($operator_id);
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Operatör Paneli - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.chat-container {
    height: 500px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    background: #f8f9fa;
}
.message {
    margin-bottom: 15px;
    padding: 10px 15px;
    border-radius: 12px;
    max-width: 70%;
}
.message.user {
    background: #e9ecef;
    border: 1px solid #ddd;
}
.message.operator {
    background: #007bff;
    color: white;
    margin-left: auto;
    text-align: right;
}
.message-time {
    font-size: 0.75rem;
    opacity: 0.7;
    margin-top: 5px;
}
</style>
</head>
<body class="bg-light">
<?php include 'header.php'; ?>

<div class="container mt-4">
    <?php if (isset($ticket)): ?>
        <!-- Ticket Detay Görünümü -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <a href="operator_dashboard.php" class="btn btn-sm btn-outline-secondary">← Geri</a>
                <h5 class="d-inline ms-3">Ticket #<?php echo $ticket['ticket_id']; ?></h5>
            </div>
            <span class="badge bg-<?php 
                echo $ticket['status'] == 'resolved' || $ticket['status'] == 'closed' ? 'success' : 
                    ($ticket['status'] == 'in_progress' ? 'primary' : 
                    ($ticket['status'] == 'waiting_response' ? 'warning' : 'secondary')); 
            ?>"><?php echo htmlspecialchars($ticket['status']); ?></span>
        </div>

        <?php if (isset($_GET['accepted'])): ?>
            <div class="alert alert-success">Ticket kabul edildi!</div>
        <?php endif; ?>
        <?php if (isset($_GET['sent'])): ?>
            <div class="alert alert-success">Mesaj gönderildi!</div>
        <?php endif; ?>
        <?php if (isset($_GET['updated'])): ?>
            <div class="alert alert-success">Ticket güncellendi!</div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <h6><?php echo htmlspecialchars($ticket['subject']); ?></h6>
                <p class="text-muted mb-2">
                    <small><?php echo htmlspecialchars(vgo_role_label_tr($ticket['user_role_id'] ?? 4)); ?>: <?php echo htmlspecialchars($ticket['user_name']); ?> (<?php echo htmlspecialchars($ticket['user_email']); ?>)</small><br>
                    <small>Kategori: <?php echo htmlspecialchars($ticket['category_name']); ?> | Oluşturma: <?php echo htmlspecialchars($ticket['created_at']); ?></small>
                </p>
            </div>
        </div>

        <?php if (!empty($orderContext) || !empty($deliveryContext)): ?>
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="mb-2">Sipariş / Teslimat Bilgisi</h6>
                <?php if (!empty($orderContext)): ?>
                    <div class="mb-1"><strong>Sipariş #<?php echo (int)($orderContext['order_id'] ?? 0); ?></strong></div>
                    <?php if (!empty($orderContext['store_name'])): ?>
                        <div class="text-muted"><small>Restoran: <?php echo htmlspecialchars($orderContext['store_name']); ?></small></div>
                    <?php endif; ?>
                    <?php if (!empty($orderContext['status'])): ?>
                        <div><small>Durum: <strong><?php echo htmlspecialchars($orderContext['status']); ?></strong></small></div>
                    <?php endif; ?>
                    <?php if (!empty($orderContext['order_date']) || !empty($orderContext['created_at'])): ?>
                        <div><small>Tarih: <?php echo htmlspecialchars($orderContext['order_date'] ?? $orderContext['created_at']); ?></small></div>
                    <?php endif; ?>
                    <?php if (isset($orderContext['total_price']) || isset($orderContext['total_amount'])): ?>
                        <div><small>Tutar: <?php echo htmlspecialchars((string)($orderContext['total_price'] ?? $orderContext['total_amount'])); ?></small></div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($deliveryContext)): ?>
                    <hr class="my-2">
                    <div class="mb-1"><strong>Teslimat #<?php echo (int)($deliveryContext['delivery_id'] ?? 0); ?></strong></div>
                    <?php if (!empty($deliveryContext['status'])): ?>
                        <div><small>Durum: <strong><?php echo htmlspecialchars($deliveryContext['status']); ?></strong></small></div>
                    <?php endif; ?>
                    <?php if (!empty($deliveryContext['estimated_arrival_time'])): ?>
                        <div><small>Tahmini Varış: <?php echo htmlspecialchars($deliveryContext['estimated_arrival_time']); ?></small></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Chat Mesajları -->
        <div class="chat-container mb-3">
            <?php foreach ($messages as $msg): ?>
                <div class="message <?php echo $msg['is_operator'] ? 'operator' : 'user'; ?>">
                    <div><strong><?php echo htmlspecialchars($msg['sender_name']); ?></strong></div>
                    <div><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                    <div class="message-time"><?php echo htmlspecialchars($msg['created_at']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Cevap ve Kapatma -->
        <?php if ($ticket['status'] != 'closed' && $ticket['status'] != 'resolved'): ?>
            <div class="row">
                <div class="col-md-9">
                    <div class="card">
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="reply">
                                <input type="hidden" name="ticket_id" value="<?php echo $ticket['ticket_id']; ?>">
                                <div class="mb-3">
                                    <label class="form-label">Cevabınız</label>
                                    <textarea class="form-control" name="message" id="operatorMessage" rows="4" required></textarea>
                                </div>
                                
                                <?php if (count($quick_replies_operator) > 0): ?>
                                <div class="mb-3">
                                    <label class="form-label">Hızlı Yanıtlar</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($quick_replies_operator as $qr): ?>
                                            <button type="button" class="btn btn-sm btn-outline-info quick-insert-btn" 
                                                data-text="<?php echo htmlspecialchars($qr['message']); ?>">
                                                <?php echo htmlspecialchars($qr['title']); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <button type="submit" class="btn btn-primary">Gönder</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <form method="post" class="mb-2">
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="ticket_id" value="<?php echo $ticket['ticket_id']; ?>">
                                <label class="form-label">Durum</label>
                                <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                                    <?php
                                        $statusOptions = ['open'=>'open','in_progress'=>'in_progress','waiting_response'=>'waiting_response','resolved'=>'resolved','closed'=>'closed'];
                                        foreach ($statusOptions as $sv => $sl) {
                                            $sel = ($ticket['status'] === $sv) ? 'selected' : '';
                                            echo '<option value="' . htmlspecialchars($sv) . '" ' . $sel . '>' . htmlspecialchars($sl) . '</option>';
                                        }
                                    ?>
                                </select>
                            </form>

                            <form method="post" class="mb-2">
                                <input type="hidden" name="action" value="set_priority">
                                <input type="hidden" name="ticket_id" value="<?php echo $ticket['ticket_id']; ?>">
                                <label class="form-label">Öncelik</label>
                                <select class="form-select form-select-sm" name="priority" onchange="this.form.submit()">
                                    <?php
                                        $prioOptions = ['urgent','high','medium','low','normal'];
                                        $curP = $ticket['priority'] ?? 'normal';
                                        foreach ($prioOptions as $pv) {
                                            $sel = ($curP === $pv) ? 'selected' : '';
                                            echo '<option value="' . htmlspecialchars($pv) . '" ' . $sel . '>' . htmlspecialchars($pv) . '</option>';
                                        }
                                    ?>
                                </select>
                            </form>

                            <form method="post" onsubmit="return confirm('Bu ticket\'ı çözüldü olarak işaretlemek istediğinize emin misiniz?')">
                                <input type="hidden" name="action" value="close">
                                <input type="hidden" name="ticket_id" value="<?php echo $ticket['ticket_id']; ?>">
                                <button type="submit" class="btn btn-success w-100">Çözüldü Olarak İşaretle</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">Bu ticket çözülmüş/kapatılmıştır.</div>
        <?php endif; ?>

    <?php else: ?>
        <!-- Dashboard Görünümü -->
        <h4 class="mb-4">Operatör Paneli - <?php echo htmlspecialchars($_SESSION['full_name']); ?></h4>

        <?php if (isset($_GET['closed'])): ?>
            <div class="alert alert-success">Ticket başarıyla kapatıldı!</div>
        <?php endif; ?>

        <!-- İstatistikler -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h2 class="text-primary"><?php echo $stats['active']; ?></h2>
                        <p class="text-muted mb-0">Aktif Ticket</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h2 class="text-success"><?php echo $stats['resolved']; ?></h2>
                        <p class="text-muted mb-0">Çözülen</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h2 class="text-info"><?php echo $stats['total_assigned']; ?></h2>
                        <p class="text-muted mb-0">Toplam</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h2 class="text-warning"><?php echo $stats['avg_response_time']; ?> dk</h2>
                        <p class="text-muted mb-0">Ort. Cevap</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtreler -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Durum</label>
                        <select class="form-select" name="status">
                            <option value="">Tümü</option>
                            <?php
                                $st = isset($_GET['status']) ? (string)$_GET['status'] : '';
                                foreach (['open','in_progress','waiting_response','resolved','closed'] as $sv) {
                                    $sel = ($st === $sv) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($sv) . '" ' . $sel . '>' . htmlspecialchars($sv) . '</option>';
                                }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Öncelik</label>
                        <select class="form-select" name="priority">
                            <option value="">Tümü</option>
                            <?php
                                $pr = isset($_GET['priority']) ? (string)$_GET['priority'] : '';
                                foreach (['urgent','high','medium','low','normal'] as $pv) {
                                    $sel = ($pr === $pv) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($pv) . '" ' . $sel . '>' . htmlspecialchars($pv) . '</option>';
                                }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kullanıcı Rolü</label>
                        <select class="form-select" name="role">
                            <option value="0">Tümü</option>
                            <?php
                                $rr = isset($_GET['role']) ? (int)$_GET['role'] : 0;
                                $roleMap = [4=>'Müşteri',5=>'Restoran',3=>'Kurye'];
                                foreach ($roleMap as $rid => $rname) {
                                    $sel = ($rr === (int)$rid) ? 'selected' : '';
                                    echo '<option value="' . (int)$rid . '" ' . $sel . '>' . htmlspecialchars($rname) . '</option>';
                                }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Ara</label>
                        <input class="form-control" name="q" value="<?php echo htmlspecialchars(isset($_GET['q']) ? (string)$_GET['q'] : ''); ?>" placeholder="Konu / Kullanıcı">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Uygula</button>
                        <a href="operator_dashboard.php" class="btn btn-outline-secondary">Sıfırla</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">
            <!-- Atanmış Ticketlar -->
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-body">
                        <h6>Atanmış Ticketlar</h6>
                        <?php if (count($assigned_tickets) > 0): ?>
                            <div class="list-group">
                                <?php foreach ($assigned_tickets as $t): ?>
                                    <a href="operator_dashboard.php?ticket=<?php echo $t['ticket_id']; ?>" class="list-group-item list-group-item-action">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1">#<?php echo $t['ticket_id']; ?> - <?php echo htmlspecialchars($t['subject']); ?></h6>
                                                <?php $catLabel = (string)($t['category_name'] ?? ($t['category'] ?? '')); ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($t['user_name']); ?> (<?php echo htmlspecialchars(vgo_role_label_tr($t['user_role_id'] ?? 0)); ?>)<?php if ($catLabel !== ''): ?> | <?php echo htmlspecialchars($catLabel); ?><?php endif; ?></small>
                                            </div>
                                            <div>
                                                <span class="badge bg-<?php 
                                                    echo ($t['status'] == 'resolved' || $t['status'] == 'closed') ? 'success' :
                                                        ($t['status'] == 'in_progress' ? 'primary' : 
                                                        ($t['status'] == 'waiting_response' ? 'warning' : 'secondary')); 
                                                ?>"><?php echo htmlspecialchars($t['status']); ?></span>
                                                <?php if (((int)($t['unread_count'] ?? 0)) > 0): ?>
                                                    <span class="badge bg-danger"><?php echo (int)$t['unread_count']; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">Atanmış ticket yok.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Bekleyen Ticketlar -->
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-body">
                        <h6>Bekleyen Ticketlar</h6>
                        <?php if (count($unassigned_tickets) > 0): ?>
                            <div class="list-group">
                                <?php foreach ($unassigned_tickets as $t): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1">#<?php echo $t['ticket_id']; ?> - <?php echo htmlspecialchars($t['subject']); ?></h6>
                                                <?php $catLabel = (string)($t['category_name'] ?? ($t['category'] ?? '')); ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($t['user_name']); ?> (<?php echo htmlspecialchars(vgo_role_label_tr($t['user_role_id'] ?? 0)); ?>)<?php if ($catLabel !== ''): ?> | <?php echo htmlspecialchars($catLabel); ?><?php endif; ?></small>
                                            </div>
                                            <div>
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="action" value="accept">
                                                    <input type="hidden" name="ticket_id" value="<?php echo $t['ticket_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-primary">Kabul Et</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">Bekleyen ticket yok.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Chat container'ı en alta kaydır
if (document.querySelector('.chat-container')) {
    const chatContainer = document.querySelector('.chat-container');
    chatContainer.scrollTop = chatContainer.scrollHeight;
}

// Hızlı yanıt butonları - mesaj kutusuna ekle
document.querySelectorAll('.quick-insert-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const textarea = document.getElementById('operatorMessage');
        const text = this.dataset.text;
        textarea.value = text;
        textarea.focus();
    });
});

// Her 30 saniyede bir sayfayı yenile (yeni ticket kontrolü)
setTimeout(function() {
    if (!window.location.search.includes('ticket=')) {
        window.location.reload();
    }
}, 30000);
</script>
</body>
</html>
