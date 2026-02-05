<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
include 'db.php';
include 'classes/SupportTicket.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role_id'] ?? 4;

// Role'e göre for_role belirle
$role_name = 'customer';
if ($user_role == 5) $role_name = 'merchant';
elseif ($user_role == 3) $role_name = 'courier';

// Check if SupportFAQ table exists
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
        $stmt = $conn->prepare("SELECT * FROM SupportFAQ WHERE for_role = ? OR for_role = 'all' $order_clause");
        $stmt->bind_param("s", $role_name);
        $stmt->execute();
        $faqs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmt->close();
    } else {
        $stmt = $conn->prepare("SELECT * FROM SupportFAQ $order_clause");
        $stmt->execute();
        $faqs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmt->close();
    }
}

// Specific FAQ if id is provided
$selected_faq = null;
if (isset($_GET['id'])) {
    $faq_id = intval($_GET['id']);
    foreach ($faqs as $faq) {
        if ($faq['faq_id'] == $faq_id) {
            $selected_faq = $faq;
            break;
        }
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sık Sorulan Sorular - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>
.faq-container {
    max-width: 900px;
    margin: 0 auto;
}
.faq-item {
    border-left: 4px solid #007bff;
    padding: 15px;
    margin-bottom: 15px;
    background: #f8f9fa;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.3s;
}
.faq-item:hover {
    background: #e7f1ff;
    border-left-color: #0056b3;
}
.faq-item h5 {
    margin: 0;
    color: #333;
}
.faq-item p {
    margin: 10px 0 0 0;
    color: #666;
    font-size: 0.9rem;
}
.faq-detail {
    border: 1px solid #ddd;
    padding: 20px;
    border-radius: 8px;
    background: white;
}
.faq-detail h4 {
    color: #007bff;
    margin-bottom: 15px;
}
</style>
</head>
<body class="bg-light">
<?php include 'header.php'; ?>

<div class="container mt-4 faq-container">
    <a href="support.php" class="btn btn-outline-secondary mb-3">&larr; Destek Sayfasına Dön</a>
    
    <h2 class="mb-4">Sık Sorulan Sorular</h2>

    <?php if (!$faq_table_exists): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Sık sorulan sorular şu anda kullanılamıyor.
        </div>
    <?php elseif (empty($faqs)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Henüz sık sorulan soru yok.
        </div>
    <?php else: ?>
        <?php if ($selected_faq): ?>
            <!-- Detay Görünümü -->
            <div class="faq-detail mb-4">
                <button class="btn btn-outline-secondary btn-sm mb-3" onclick="window.location.href='support_faq.php'">
                    &larr; FAQ Listesine Dön
                </button>
                <h4><?php echo htmlspecialchars($selected_faq['question']); ?></h4>
                <p><?php echo nl2br(htmlspecialchars($selected_faq['answer'])); ?></p>
                <hr>
                <small class="text-muted">Son güncelleme: <?php echo htmlspecialchars($selected_faq['updated_at'] ?? 'Belirtilmedi'); ?></small>
            </div>
        <?php else: ?>
            <!-- Liste Görünümü -->
            <div class="row">
                <div class="col-md-8">
                    <?php foreach ($faqs as $faq): ?>
                        <div class="faq-item" onclick="window.location.href='support_faq.php?id=<?php echo $faq['faq_id']; ?>'">
                            <h5><i class="bi bi-question-circle-fill"></i> <?php echo htmlspecialchars($faq['question']); ?></h5>
                            <p><?php echo htmlspecialchars(substr($faq['answer'] ?? '', 0, 100)); ?><?php if (strlen($faq['answer'] ?? '') > 100): ?>...<?php endif; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title"><i class="bi bi-headset"></i> Daha Fazla Yardıma İhtiyacın Var mı?</h6>
                            <p class="small text-muted">Cevabını bulamadığın sorun için bir destek talebi oluştur.</p>
                            <a href="support.php" class="btn btn-primary btn-sm w-100">
                                Destek Talebi Oluştur
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
