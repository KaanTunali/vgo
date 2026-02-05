<?php
session_start();
include 'db.php';

// Yetki kontrolü: müşteri olması gerekiyorsa role_id = 4
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$messages = [];
$errors = [];

// DB'nin gerekli sütunlara sahip olduğundan emin ol (varsa atla)
$needed = [
    'first_name' => "VARCHAR(50) NULL",
    'last_name' => "VARCHAR(50) NULL",
    'email_verified' => "TINYINT(1) NOT NULL DEFAULT 0",
    'birthdate' => "DATE NULL",
    'last_login' => "DATETIME NULL"
];
foreach ($needed as $col => $type) {
    $res = $conn->query("SHOW COLUMNS FROM Users LIKE '" . $conn->real_escape_string($col) . "'");
    if (!$res || $res->num_rows == 0) {
        $conn->query("ALTER TABLE Users ADD COLUMN {$col} {$type}");
    }
}

// Kullanıcı verisini çek
$stmt = $conn->prepare("SELECT u.*, c.default_address, c.city AS customer_city, c.postal_code FROM Users u LEFT JOIN Customers c ON u.user_id = c.user_id WHERE u.user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "Kullanıcı bulunamadı.";
    exit;
}

// Eğer first/last boşsa, full_name'den bölmeye çalış
$first_name = $user['first_name'] ?: (strpos($user['full_name'], ' ') !== false ? strstr($user['full_name'], ' ', true) : $user['full_name']);
$last_name = $user['last_name'] ?: (strpos($user['full_name'], ' ') !== false ? trim(strstr($user['full_name'], ' ')) : '');

// Profil güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'update_profile') {
        $fn = trim($_POST['first_name'] ?? '');
        $ln = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '') ?: null;
        $address = trim($_POST['default_address'] ?? '');

        // Basit validasyon
        if ($fn === '') $errors[] = 'Ad boş olamaz.';
        if ($ln === '') $errors[] = 'Soyad boş olamaz.';

        if (empty($errors)) {
            // Update Users
            $full = trim($fn . ' ' . $ln);
            $stmt = $conn->prepare("UPDATE Users SET first_name = ?, last_name = ?, full_name = ?, phone = ?, birthdate = ? WHERE user_id = ?");
            $stmt->bind_param("sssssi", $fn, $ln, $full, $phone, $birthdate, $user_id);
            if ($stmt->execute()) {
                $messages[] = 'Profiliniz başarıyla güncellendi.';
                $_SESSION['full_name'] = $full;
            } else {
                $errors[] = 'Profil güncellenirken hata oluştu.';
            }
            $stmt->close();

            // Update Customers (adres)
            $stmt = $conn->prepare("UPDATE Customers SET default_address = ? WHERE user_id = ?");
            $stmt->bind_param("si", $address, $user_id);
            $stmt->execute();
            $stmt->close();

            // Yeniden çek
            $stmt = $conn->prepare("SELECT u.*, c.default_address, c.city AS customer_city, c.postal_code FROM Users u LEFT JOIN Customers c ON u.user_id = c.user_id WHERE u.user_id = ? LIMIT 1");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $user = $res->fetch_assoc();
            $stmt->close();

            $first_name = $user['first_name'];
            $last_name = $user['last_name'];
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($current === '' || $new === '' || $confirm === '') {
            $errors[] = 'Lütfen tüm şifre alanlarını doldurun.';
        } elseif ($new !== $confirm) {
            $errors[] = 'Yeni şifreler uyuşmuyor.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'Yeni şifre en az 8 karakter olmalı.';
        } else {
            // doğrula
            if (password_verify($current, $user['password_hash'])) {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE Users SET password_hash = ? WHERE user_id = ?");
                $stmt->bind_param("si", $hash, $user_id);
                if ($stmt->execute()) {
                    $messages[] = 'Şifreniz başarıyla değiştirildi.';
                } else {
                    $errors[] = 'Şifre güncellenirken hata oluştu.';
                }
                $stmt->close();
            } else {
                $errors[] = 'Mevcut şifre yanlış.';
            }
        }
    }

    if ($action === 'verify_email') {
        // Demo amaçlı: e-posta doğrulama işaretlemesi (gerçekte mail gönderimi gerekir)
        $stmt = $conn->prepare("UPDATE Users SET email_verified = 1 WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $messages[] = 'E-posta doğrulandı (demo).' ;
            $user['email_verified'] = 1;
        } else {
            $errors[] = 'E-posta doğrulanırken hata oluştu.';
        }
        $stmt->close();
    }
}

// View (basit Bootstrap)
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hesabım - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>

<div class="container mt-4">
    <h4>Hesabım</h4>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($m); ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $e): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>

    <div class="row">
        <div class="col-md-7">
            <div class="card mb-3">
                <div class="card-body">
                    <h6>Bilgiler</h6>
                    <form method="POST" action="profile.php">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="mb-3">
                            <label class="form-label">Ad</label>
                            <input name="first_name" class="form-control" value="<?php echo htmlspecialchars($first_name); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Soyad</label>
                            <input name="last_name" class="form-control" value="<?php echo htmlspecialchars($last_name); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cep telefonu</label>
                            <input name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Doğum Tarihi</label>
                            <input name="birthdate" type="date" class="form-control" value="<?php echo htmlspecialchars($user['birthdate']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Adres (default)</label>
                            <input name="default_address" class="form-control" value="<?php echo htmlspecialchars($user['default_address']); ?>">
                        </div>
                        <button class="btn btn-primary">Kaydet</button>
                    </form>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <h6>E-posta</h6>
                    <p><strong><?php echo htmlspecialchars($user['email']); ?></strong>
                       <?php if ($user['email_verified']): ?>
                        <span class="badge bg-success">Onaylanmış</span>
                       <?php else: ?>
                        <span class="badge bg-secondary">Onaylanmamış</span>
                        <form class="d-inline" method="POST" action="profile.php">
                            <input type="hidden" name="action" value="verify_email">
                            <button class="btn btn-sm btn-outline-primary">E-postayı doğrula (demo)</button>
                        </form>
                       <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <h6>Şifre</h6>
                    <form method="POST" action="profile.php">
                        <input type="hidden" name="action" value="change_password">
                        <div class="mb-3">
                            <label class="form-label">Mevcut şifre</label>
                            <input type="password" name="current_password" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Yeni şifre</label>
                            <input type="password" name="new_password" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Yeni şifre (tekrar)</label>
                            <input type="password" name="confirm_password" class="form-control">
                        </div>
                        <button class="btn btn-warning">Şifreyi Değiştir</button>
                    </form>
                </div>
            </div>

        </div>

        <div class="col-md-5">
            <div class="card mb-3">
                <div class="card-body text-center">
                    <img src="<?php echo htmlspecialchars($user['profile_image'] ?: 'https://via.placeholder.com/120'); ?>" class="rounded-circle mb-2" width="120" height="120" alt="Profil">
                    <h6><?php echo htmlspecialchars($user['full_name']); ?></h6>
                    <p class="text-muted">Son giriş: <?php echo htmlspecialchars($user['last_login'] ?? '—'); ?></p>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger">Oturumu Kapat</a>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6>Hesap Bilgileri</h6>
                    <p><small>Durum: <strong><?php echo htmlspecialchars($user['status']); ?></strong></small></p>
                    <p><small>Telefon: <?php echo htmlspecialchars($user['phone']); ?></small></p>
                    <p><small>Adres: <?php echo htmlspecialchars($user['default_address']); ?></small></p>
                </div>
            </div>
        </div>

    </div>
</div>

</body>
</html>