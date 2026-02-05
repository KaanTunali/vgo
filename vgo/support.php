<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
include 'db.php';
include 'classes/SupportTicket.php';
include 'classes/SupportAI.php';
require_once __DIR__ . '/audit_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$supportTicket = new SupportTicket($conn);
$supportAI = new SupportAI($conn);
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role_id'] ?? 4;

$can_create_ticket = in_array((int)$user_role, [3, 4, 5], true);
$can_close_ticket = ((int)$user_role === 4);

// Make sure categories exist so ticket creation cannot fail on empty SupportCategories.
$supportTicket->ensureDefaultCategories();

function vgo_table_has_column(mysqli $conn, string $table, string $col): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function vgo_find_delivery_id_for_order(mysqli $conn, int $orderId): ?int {
    if ($orderId <= 0) return null;

    // Prefer Orders.delivery_id if present
    if (vgo_table_has_column($conn, 'Orders', 'delivery_id')) {
        $stmt = $conn->prepare('SELECT delivery_id FROM Orders WHERE order_id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $did = isset($row['delivery_id']) ? (int)$row['delivery_id'] : 0;
            if ($did > 0) return $did;
        }
    }

    // Fallback: Deliveries.order_id -> delivery_id
    if (vgo_table_has_column($conn, 'Deliveries', 'order_id') && vgo_table_has_column($conn, 'Deliveries', 'delivery_id')) {
        $stmt = $conn->prepare('SELECT delivery_id FROM Deliveries WHERE order_id = ? ORDER BY delivery_id DESC LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $did = isset($row['delivery_id']) ? (int)$row['delivery_id'] : 0;
            if ($did > 0) return $did;
        }
    }

    return null;
}

// URL parametrelerinden order_id ve delivery_id al
$prefill_order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
$prefill_delivery_id = isset($_GET['delivery_id']) ? intval($_GET['delivery_id']) : null;

// Eğer sadece order_id geldiyse teslimatı otomatik bul
if ($prefill_order_id && !$prefill_delivery_id) {
    $autoDelivery = vgo_find_delivery_id_for_order($conn, (int)$prefill_order_id);
    if ($autoDelivery) {
        $prefill_delivery_id = (int)$autoDelivery;
    }
}

// Widget'tan gelen prefill
$prefill_category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
$prefill_subject = isset($_GET['subject']) ? trim((string)$_GET['subject']) : '';
$open_new_modal = (isset($_GET['new']) && $_GET['new'] == '1') || $prefill_order_id || $prefill_delivery_id || $prefill_subject !== '';

// Role'e göre for_role belirle
$role_name = 'customer';
if ($user_role == 5) $role_name = 'merchant';
elseif ($user_role == 3) $role_name = 'courier';

// Sipariş/Teslimat link'i ile gelindiyse ve ticket belirtilmediyse: mevcut ticket varsa direkt onu aç.
if (!isset($_GET['ticket']) && ($prefill_order_id || $prefill_delivery_id)) {
    $maybeTid = $supportTicket->findLatestTicketIdForOrderDelivery((int)$user_id, (int)$user_role, $prefill_order_id, $prefill_delivery_id);
    if ($maybeTid) {
        header('Location: support.php?ticket=' . (int)$maybeTid);
        exit;
    }
}

// Yeni ticket oluşturma
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'create') {
    if (!$can_create_ticket) {
        $error = 'Bu hesap ile yeni destek talebi oluşturamazsınız.';
    } else {
    // category_id hem sayı hem metin olabilir (schema uyumluluğu)
    $category_id = isset($_POST['category_id']) ? $_POST['category_id'] : '';
    if (is_string($category_id) && ctype_digit($category_id)) {
        $category_id = (int)$category_id;
    }
    $subject = trim($_POST['subject']);
    $description = trim($_POST['description']);
    $order_id = !empty($_POST['order_id']) ? intval($_POST['order_id']) : null;
    $delivery_id = !empty($_POST['delivery_id']) ? intval($_POST['delivery_id']) : null;
    $priority = 'medium';

    // Merchant/Courier ticket'ları mutlaka bir sipariş/teslimata bağlı olmalı.
    if (in_array((int)$user_role, [3, 5], true) && empty($order_id) && empty($delivery_id)) {
        $error = 'Kurye/Mağaza için destek talebi bir sipariş/teslimata bağlı olmalıdır.';
    }
    
    if (!isset($error) && !empty($subject) && !empty($description)) {
        if ($order_id && !$delivery_id) {
            $autoDelivery = vgo_find_delivery_id_for_order($conn, (int)$order_id);
            if ($autoDelivery) {
                $delivery_id = (int)$autoDelivery;
            }
        }
        $ticket_id = $supportTicket->createTicket($user_id, $category_id, $subject, $description, $order_id, $priority);
        if ($ticket_id) {
            vgo_audit_log($conn, 'support.ticket.create', 'SupportTicket', $ticket_id, [
                'category_id' => $category_id,
                'subject' => $subject,
                'order_id' => $order_id,
                'delivery_id' => $delivery_id,
            ]);
            // Delivery ID varsa ekle
            if ($delivery_id) {
                // Only update if column exists
                try {
                    $colCheck = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'SupportTickets' AND COLUMN_NAME = 'delivery_id' LIMIT 1");
                    if ($colCheck) {
                        $colCheck->execute();
                        $hasCol = ($colCheck->get_result()->num_rows > 0);
                        $colCheck->close();
                        if ($hasCol) {
                            $upd = $conn->prepare("UPDATE SupportTickets SET delivery_id = ? WHERE ticket_id = ?");
                            if ($upd) {
                                $upd->bind_param('ii', $delivery_id, $ticket_id);
                                $upd->execute();
                                $upd->close();
                            }
                        }
                    }
                } catch (Throwable $e) {
                    // ignore in dev
                }
            }
            
            // Auto-response dene
            $supportAI->handleInitialMessage($ticket_id, $description, $role_name);
            
            header('Location: support.php?ticket=' . $ticket_id . '&success=1');
            exit;
        } else {
            $error = "Destek talebi oluşturulamadı.";
        }
    } else {
        if (!isset($error)) {
            $error = "Lütfen tüm alanları doldurun.";
        }
    }
    }
}

// Ticket'a mesaj gönderme
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'reply') {
    $ticket_id = intval($_POST['ticket_id']);
    $message = trim($_POST['message']);
    
    // Ticket'a erişim kontrolü (siparişin tarafları da erişebilir)
    $ticket = $supportTicket->getTicketByIdForViewer($ticket_id, (int)$user_id, (int)$user_role);
    
    if ($ticket && !empty($message)) {
        if (($ticket['status'] ?? '') === 'closed' || ($ticket['status'] ?? '') === 'resolved') {
            $error = 'Bu destek talebi kapatılmış/çözülmüştür. Mesaj gönderemezsiniz.';
        } else {
            $is_staff_message = ((int)$user_role !== 4);
            if ($supportTicket->addMessage($ticket_id, $user_id, $message, $is_staff_message)) {
            vgo_audit_log($conn, 'support.ticket.reply', 'SupportTicket', $ticket_id, [
                'message_len' => strlen($message),
            ]);
            header('Location: support.php?ticket=' . $ticket_id . '&reply=1');
            exit;
            } else {
                $error = "Mesaj gönderilemedi.";
            }
        }
    }
}

// Kullanıcı ticket kapatma
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'close_user') {
    $ticket_id = intval($_POST['ticket_id']);
    $ticket = $supportTicket->getTicketByIdForViewer($ticket_id, (int)$user_id, (int)$user_role);
    if ($ticket && $can_close_ticket && (int)($ticket['user_id'] ?? 0) === (int)$user_id) {
        $supportTicket->updateTicketStatus($ticket_id, 'closed');
        vgo_audit_log($conn, 'support.ticket.close_user', 'SupportTicket', $ticket_id);
        header('Location: support.php?ticket=' . $ticket_id . '&closed=1');
        exit;
    }
}

$categories = $supportTicket->getCategories();
$unread_count = $supportTicket->getUnreadMessageCount($user_id);

// Prefill category'yi categories listesine göre eşle
$prefill_category_id = '';
if ($prefill_category !== '') {
    // sayı geldiyse direkt
    if (ctype_digit($prefill_category)) {
        $prefill_category_id = $prefill_category;
    } else {
        $prefillKey = strtolower($prefill_category);
        $aliases = [];
        // Accept canonical keys from widget/order links (Delivery/Payment/etc.) and map to TR category names.
        switch ($prefillKey) {
            case 'order':
            case 'order_issue':
                $aliases = ['sipariş'];
                break;
            case 'delivery':
            case 'delivery_issue':
            case 'delivery_problem':
            case 'courier_issue':
            case 'pickup_issue':
            case 'customer_unreachable':
                $aliases = ['teslimat', 'kurye'];
                break;
            case 'payment':
            case 'payment_issue':
            case 'payment_settlement':
            case 'payment_earnings':
                $aliases = ['ödeme', 'kazanç', 'mutabakat'];
                break;
            case 'refund':
            case 'refund_issue':
                $aliases = ['iade'];
                break;
            case 'campaign':
            case 'coupon':
            case 'coupon_issue':
                $aliases = ['kampanya', 'kupon'];
                break;
            case 'account':
            case 'account_issue':
                $aliases = ['hesap', 'giriş', 'profil', 'yetki'];
                break;
            case 'technical':
            case 'technical_issue':
                $aliases = ['teknik', 'uygulama', 'gps'];
                break;
            case 'quality':
            case 'product_issue':
                $aliases = ['ürün', 'menü', 'kalite'];
                break;
            case 'general':
                $aliases = ['genel'];
                break;
        }

        foreach ($categories as $cat) {
            $cid = (string)($cat['category_id'] ?? '');
            $cname = (string)($cat['category_name'] ?? '');
            if ($cname === '') continue;

            if (strcasecmp($cname, $prefill_category) === 0 || strcasecmp($cid, $prefill_category) === 0) {
                $prefill_category_id = $cid;
                break;
            }

            $lc = strtolower($cname);
            foreach ($aliases as $a) {
                if ($a !== '' && strpos($lc, $a) !== false) {
                    $prefill_category_id = $cid;
                    break 2;
                }
            }
        }
        if ($prefill_category_id === '' && !empty($categories)) {
            $prefill_category_id = (string)($categories[0]['category_id'] ?? '');
        }
    }
}

// FAQs ve Quick Replies çek
$quick_replies = $supportAI->getQuickReplies($role_name);

// Check if SupportFAQ table exists and has for_role/display_order columns
$faq_table_exists = false;
$faq_for_role_exists = false;
$faq_display_order_exists = false;
$result = $conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'SupportFAQ' LIMIT 1");
if ($result && $result->num_rows > 0) {
    $faq_table_exists = true;
    $result = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'SupportFAQ' AND COLUMN_NAME = 'for_role' LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $faq_for_role_exists = true;
    }
    $result = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'SupportFAQ' AND COLUMN_NAME = 'display_order' LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $faq_display_order_exists = true;
    }
}

$faqs = [];
if ($faq_table_exists) {
    $order_clause = $faq_display_order_exists ? "ORDER BY display_order ASC" : "";
    if ($faq_for_role_exists) {
        $stmt = $conn->prepare("SELECT * FROM SupportFAQ WHERE for_role = ? OR for_role = 'all' $order_clause LIMIT 5");
        $stmt->bind_param("s", $role_name);
        $stmt->execute();
        $faqs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmt->close();
    } else {
        // Fallback: just get all FAQs
        $stmt = $conn->prepare("SELECT * FROM SupportFAQ $order_clause LIMIT 5");
        $stmt->execute();
        $faqs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmt->close();
    }
}

// Tek ticket görünümü
if (isset($_GET['ticket'])) {
    $ticket_id = intval($_GET['ticket']);
    $ticket = $supportTicket->getTicketByIdForViewer($ticket_id, (int)$user_id, (int)$user_role);
    
    if (!$ticket) {
        header('Location: support.php');
        exit;
    }
    
    $messages = $supportTicket->getTicketMessages($ticket_id);
} else {
    // Liste görünümü
    $active_tickets = $supportTicket->getTicketsForViewer((int)$user_id, (int)$user_role, null, 20);
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Destek - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
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
    background: #007bff;
    color: white;
    margin-left: auto;
    text-align: right;
}
.message.operator {
    background: white;
    border: 1px solid #ddd;
}
.message-time {
    font-size: 0.75rem;
    opacity: 0.7;
    margin-top: 5px;
}
.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
}
.priority-high { color: #dc3545; font-weight: bold; }
.priority-medium { color: #fd7e14; }
.priority-low { color: #6c757d; }
</style>
</head>
<body class="bg-light">
<?php include 'header.php'; ?>

<div class="container mt-4">
    <?php if (isset($ticket)): ?>
        <!-- Ticket Detay Görünümü -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <a href="support.php" class="btn btn-sm btn-outline-secondary">← Geri</a>
                <h5 class="d-inline ms-3">Destek Talebi #<?php echo $ticket['ticket_id']; ?></h5>
            </div>
            <span class="badge bg-<?php 
                echo $ticket['status'] == 'resolved' || $ticket['status'] == 'closed' ? 'success' : 
                    ($ticket['status'] == 'in_progress' ? 'primary' : 
                    ($ticket['status'] == 'waiting_response' ? 'warning' : 'secondary')); 
            ?>"><?php echo htmlspecialchars($ticket['status']); ?></span>
        </div>

        <?php if (isset($_GET['reply'])): ?>
            <div class="alert alert-success">Mesajınız gönderildi!</div>
        <?php endif; ?>
        <?php if (isset($_GET['closed'])): ?>
            <div class="alert alert-success">Destek talebi kapatıldı.</div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <h6><?php echo htmlspecialchars($ticket['subject']); ?></h6>
                <p class="text-muted mb-2">
                    <small>Kategori: <?php echo htmlspecialchars($ticket['category_name']); ?> | 
                    Oluşturma: <?php echo htmlspecialchars($ticket['created_at']); ?></small>
                </p>
            </div>
        </div>

        <!-- Chat Mesajları -->
        <div class="chat-container mb-3">
            <?php foreach ($messages as $msg): ?>
                <div class="message <?php echo ((int)($msg['sender_id'] ?? 0) === (int)$user_id) ? 'user' : 'operator'; ?>">
                    <div><strong><?php echo htmlspecialchars($msg['sender_name']); ?></strong></div>
                    <div><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                    <div class="message-time"><?php echo htmlspecialchars($msg['created_at']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Cevap Formu -->
        <?php if ($ticket['status'] != 'closed' && $ticket['status'] != 'resolved'): ?>
            <div class="card">
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="reply">
                        <input type="hidden" name="ticket_id" value="<?php echo $ticket['ticket_id']; ?>">
                        <div class="mb-3">
                            <label class="form-label">Mesajınız</label>
                            <textarea class="form-control" name="message" rows="4" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Gönder</button>
                    </form>
                    <?php if ($can_close_ticket && (int)($ticket['user_id'] ?? 0) === (int)$user_id): ?>
                        <form method="post" class="mt-2" onsubmit="return confirm('Bu destek talebini kapatmak istiyor musunuz?');">
                            <input type="hidden" name="action" value="close_user">
                            <input type="hidden" name="ticket_id" value="<?php echo $ticket['ticket_id']; ?>">
                            <button type="submit" class="btn btn-outline-secondary">Talebi Kapat</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">Bu destek talebi kapatılmıştır.</div>
        <?php endif; ?>

    <?php else: ?>
        <!-- Liste Görünümü -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4>Destek <?php if ($unread_count > 0): ?><span class="badge bg-danger"><?php echo $unread_count; ?></span><?php endif; ?></h4>
            <?php if ($can_create_ticket): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTicketModal">Yeni Destek Talebi</button>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">Destek talebiniz oluşturuldu! En kısa sürede size dönüş yapılacaktır.</div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (count($faqs) > 0): ?>
        <div class="card mb-3">
            <div class="card-body">
                <h6><i class="bi bi-question-circle"></i> Sık Sorulan Sorular</h6>
                <div class="accordion" id="faqAccordion">
                    <?php foreach ($faqs as $i => $faq): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="faqHeading<?php echo $i; ?>">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse<?php echo $i; ?>">
                                    <?php echo htmlspecialchars($faq['question']); ?>
                                </button>
                            </h2>
                            <div id="faqCollapse<?php echo $i; ?>" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    <?php echo nl2br(htmlspecialchars($faq['answer'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <h6>Destek Talepleriniz</h6>
                <?php if (count($active_tickets) > 0): ?>
                    <div class="list-group">
                        <?php foreach ($active_tickets as $t): ?>
                            <a href="support.php?ticket=<?php echo $t['ticket_id']; ?>" class="list-group-item list-group-item-action">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">#<?php echo $t['ticket_id']; ?> - <?php echo htmlspecialchars($t['subject']); ?></h6>
                                        <small class="text-muted"><?php echo htmlspecialchars($t['category_name']); ?> | Son güncelleme: <?php echo htmlspecialchars($t['updated_at']); ?></small>
                                    </div>
                                    <div>
                                        <span class="badge bg-<?php 
                                            echo $t['status'] == 'resolved' || $t['status'] == 'closed' ? 'success' : 
                                                ($t['status'] == 'in_progress' ? 'primary' : 
                                                ($t['status'] == 'waiting_response' ? 'warning' : 'secondary')); 
                                        ?>"><?php echo htmlspecialchars($t['status']); ?></span>
                                        <span class="badge bg-light text-dark"><?php echo $t['message_count']; ?> mesaj</span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted">Henüz destek talebiniz bulunmuyor.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Yeni Ticket Modal -->
<div class="modal fade" id="newTicketModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Yeni Destek Talebi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Kategori</label>
                        <select class="form-select" name="category_id" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars((string)$cat['category_id']); ?>" <?php echo ($prefill_category_id !== '' && (string)$cat['category_id'] === (string)$prefill_category_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sipariş No (Opsiyonel)</label>
                        <input type="number" class="form-control" name="order_id" placeholder="Sipariş numarası varsa giriniz" value="<?php echo $prefill_order_id ?? ''; ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Teslimat No (Opsiyonel)</label>
                        <input type="number" class="form-control" name="delivery_id" placeholder="Teslimat numarası varsa giriniz" value="<?php echo $prefill_delivery_id ?? ''; ?>">
                    </div>
                    
                    <?php if (count($quick_replies) > 0): ?>
                    <div class="mb-3">
                        <label class="form-label">Hızlı Sorular</label>
                        <div class="btn-group-vertical w-100" role="group">
                            <?php foreach ($quick_replies as $qr): ?>
                                <button type="button" class="btn btn-outline-secondary text-start quick-reply-btn" 
                                    data-subject="<?php echo htmlspecialchars($qr['title']); ?>"
                                    data-description="<?php echo htmlspecialchars($qr['title']); ?>">
                                    <?php echo htmlspecialchars($qr['title']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Konu</label>
                        <input type="text" class="form-control" name="subject" required value="<?php echo htmlspecialchars($prefill_subject); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Açıklama</label>
                        <textarea class="form-control" name="description" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Gönder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Chat container'ı en alta kaydır
if (document.querySelector('.chat-container')) {
    const chatContainer = document.querySelector('.chat-container');
    chatContainer.scrollTop = chatContainer.scrollHeight;
}

// Hızlı soru butonları - formu doldur
document.querySelectorAll('.quick-reply-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const subject = this.dataset.subject;
        const description = this.dataset.description;
        document.querySelector('input[name="subject"]').value = subject;
        document.querySelector('textarea[name="description"]').value = description;
    });
});

// URL parametreleriyle modal otomatik aç
<?php if ($open_new_modal): ?>
window.addEventListener('load', function() {
    const modal = new bootstrap.Modal(document.getElementById('newTicketModal'));
    modal.show();
});
<?php endif; ?>
</script>
</body>
</html>
