<?php
session_start();
include 'db.php';

function vgo_resolve_table_name(mysqli $conn, string $preferred): string {
    $stmt = $conn->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) LIMIT 1");
    if (!$stmt) return $preferred;
    $stmt->bind_param('s', $preferred);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row['TABLE_NAME'] ?? $preferred;
}

function vgo_column_exists_ci(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) AND LOWER(COLUMN_NAME) = LOWER(?) LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function vgo_table_exists_ci(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];

$tableMerchants = vgo_resolve_table_name($conn, 'merchants');
$tableZones = vgo_resolve_table_name($conn, 'zones');
$tableRatings = vgo_resolve_table_name($conn, 'ratings');
$tableCustomers = vgo_resolve_table_name($conn, 'customers');
$tableUsers = vgo_resolve_table_name($conn, 'users');

$hasPhone = vgo_column_exists_ci($conn, $tableMerchants, 'phone');
$hasAvgPrep = vgo_column_exists_ci($conn, $tableMerchants, 'avg_preparation_time');
$hasIsActive = vgo_column_exists_ci($conn, $tableMerchants, 'is_active');
$hasRatingAvg = vgo_column_exists_ci($conn, $tableMerchants, 'rating_avg');
$hasTotalReviews = vgo_column_exists_ci($conn, $tableMerchants, 'total_reviews');
$hasLogoUrl = vgo_column_exists_ci($conn, $tableMerchants, 'logo_url');
$hasTaxNumber = vgo_column_exists_ci($conn, $tableMerchants, 'tax_number');
$hasZoneId = vgo_column_exists_ci($conn, $tableMerchants, 'zone_id');

// merchant
$stmt = $conn->prepare("SELECT * FROM {$tableMerchants} WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$merchant = $res->fetch_assoc();
$stmt->close();
if (!$merchant) { echo "Mağaza bulunamadı."; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $store = trim($_POST['store_name'] ?? '');
    $addr = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $open = $_POST['opening_time'] ?? null;
    $close = $_POST['closing_time'] ?? null;
    $phone = trim($_POST['phone'] ?? '');
    $avgPrep = max(0, (int)($_POST['avg_preparation_time'] ?? 0));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $fields = [];
    $types = '';
    $values = [];

    $fields[] = 'store_name = ?';
    $types .= 's';
    $values[] = $store;

    $fields[] = 'address = ?';
    $types .= 's';
    $values[] = $addr;

    $fields[] = 'city = ?';
    $types .= 's';
    $values[] = $city;

    $fields[] = 'opening_time = ?';
    $types .= 's';
    $values[] = $open;

    $fields[] = 'closing_time = ?';
    $types .= 's';
    $values[] = $close;

    if ($hasPhone) {
        $fields[] = 'phone = ?';
        $types .= 's';
        $values[] = $phone;
    }
    if ($hasAvgPrep) {
        $fields[] = 'avg_preparation_time = ?';
        $types .= 'i';
        $values[] = $avgPrep;
    }
    if ($hasIsActive) {
        $fields[] = 'is_active = ?';
        $types .= 'i';
        $values[] = $isActive;
    }

    $types .= 'i';
    $values[] = (int)$merchant['merchant_id'];

    $sql = "UPDATE {$tableMerchants} SET " . implode(', ', $fields) . " WHERE merchant_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $bind = [];
        $bind[] = $types;
        foreach ($values as $k => $v) {
            $bind[] = &$values[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
        if ($stmt->execute()) { $msg = 'Güncellendi.'; }
        $stmt->close();
    }

    // reload
    $stmt = $conn->prepare("SELECT * FROM {$tableMerchants} WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $merchant = $res->fetch_assoc();
    $stmt->close();
}

// Zone name (optional)
$zoneName = '';
if ($hasZoneId && !empty($merchant['zone_id']) && vgo_table_exists_ci($conn, 'zones')) {
    $stmt = $conn->prepare("SELECT zone_name FROM {$tableZones} WHERE zone_id = ? LIMIT 1");
    if ($stmt) {
        $zid = (int)$merchant['zone_id'];
        $stmt->bind_param('i', $zid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $zoneName = (string)($row['zone_name'] ?? '');
    }
}

// Recent reviews (optional)
$reviews = null;
if (vgo_table_exists_ci($conn, 'ratings')) {
    $stmt = $conn->prepare("SELECT r.merchant_rating, r.review_text, r.created_at, u.full_name
        FROM {$tableRatings} r
        JOIN {$tableCustomers} c ON r.customer_id = c.customer_id
        JOIN {$tableUsers} u ON c.user_id = u.user_id
        WHERE r.merchant_id = ? AND r.review_text IS NOT NULL AND r.review_text != ''
        ORDER BY r.created_at DESC LIMIT 10");
    if ($stmt) {
        $mid = (int)$merchant['merchant_id'];
        $stmt->bind_param('i', $mid);
        $stmt->execute();
        $reviews = $stmt->get_result();
        $stmt->close();
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mağaza Ayarları - <?php echo htmlspecialchars($merchant['store_name']); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-4">
    <a href="merchant_dashboard.php">&larr; Geri</a>
    <h4 class="mt-3">Mağaza Ayarları</h4>
    <?php if(isset($msg)): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <?php if ($hasRatingAvg): ?>
                                    <div class="text-muted">Puan</div>
                                    <div><strong><?php echo number_format((float)($merchant['rating_avg'] ?? 0), 1); ?></strong> / 5.0
                                        <?php if ($hasTotalReviews): ?>
                                            <span class="text-muted ms-1">(<?php echo (int)($merchant['total_reviews'] ?? 0); ?> değerlendirme)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($zoneName)): ?>
                                    <div class="text-muted mt-1">Bölge</div>
                                    <div><?php echo htmlspecialchars($zoneName); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <?php if ($hasIsActive): ?>
                                    <?php $isActiveNow = ((int)($merchant['is_active'] ?? 1)) === 1; ?>
                                    <span class="badge <?php echo $isActiveNow ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $isActiveNow ? 'Açık' : 'Kapalı'; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                <div class="mb-3">
                    <label class="form-label">Mağaza Adı</label>
                    <input name="store_name" class="form-control" value="<?php echo htmlspecialchars($merchant['store_name']); ?>">
                </div>

                <?php if ($hasPhone): ?>
                <div class="mb-3">
                    <label class="form-label">Telefon</label>
                    <input name="phone" class="form-control" value="<?php echo htmlspecialchars($merchant['phone'] ?? ''); ?>">
                </div>
                <?php endif; ?>

                <?php if ($hasTaxNumber): ?>
                <div class="mb-3">
                    <label class="form-label">Vergi No</label>
                    <input class="form-control" value="<?php echo htmlspecialchars($merchant['tax_number'] ?? ''); ?>" disabled>
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">Adres</label>
                    <input name="address" class="form-control" value="<?php echo htmlspecialchars($merchant['address']); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Şehir</label>
                    <input name="city" class="form-control" value="<?php echo htmlspecialchars($merchant['city']); ?>">
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Açılış</label>
                        <input type="time" name="opening_time" class="form-control" value="<?php echo htmlspecialchars($merchant['opening_time']); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Kapanış</label>
                        <input type="time" name="closing_time" class="form-control" value="<?php echo htmlspecialchars($merchant['closing_time']); ?>">
                    </div>
                </div>

                <?php if ($hasAvgPrep): ?>
                <div class="mb-3">
                    <label class="form-label">Ortalama Hazırlık Süresi (dk)</label>
                    <input type="number" min="0" step="1" name="avg_preparation_time" class="form-control" value="<?php echo (int)($merchant['avg_preparation_time'] ?? 0); ?>">
                </div>
                <?php endif; ?>

                <?php if ($hasIsActive): ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?php echo (((int)($merchant['is_active'] ?? 1)) === 1) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="is_active">Mağaza açık (müşteriler listede görsün)</label>
                </div>
                <?php endif; ?>

                <button class="btn btn-primary">Kaydet</button>
                <a href="upload_logo.php" class="btn btn-secondary">Logo Yükle</a>
                    </form>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">Müşteri Yorumları</div>
                <div class="card-body">
                    <?php if ($reviews && $reviews->num_rows > 0): ?>
                        <?php while ($r = $reviews->fetch_assoc()): ?>
                            <div class="mb-3 pb-3 border-bottom">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?php echo htmlspecialchars($r['full_name']); ?></strong>
                                        <div class="mt-1">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <span class="text-warning"><?php echo ($i <= (int)$r['merchant_rating']) ? '★' : '☆'; ?></span>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                    <small class="text-muted"><?php echo date('d.m.Y', strtotime($r['created_at'])); ?></small>
                                </div>
                                <div class="mt-2"><?php echo htmlspecialchars($r['review_text']); ?></div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-muted">Henüz yorum yok.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-3">Logo</h6>
                    <?php if ($hasLogoUrl && !empty($merchant['logo_url'])): ?>
                        <img src="<?php echo htmlspecialchars($merchant['logo_url']); ?>" class="img-fluid rounded mb-2" alt="Logo">
                    <?php else: ?>
                        <div class="text-muted">Logo yüklenmemiş.</div>
                    <?php endif; ?>
                    <a href="upload_logo.php" class="btn btn-outline-secondary w-100 mt-2">Logo Yükle / Değiştir</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>