<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== 5) {
    header('Location: login.php');
    exit;
}

function vgo_table_exists_logo(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function vgo_resolve_table_logo(mysqli $conn, array $candidates): string {
    foreach ($candidates as $cand) {
        $cand = (string)$cand;
        if ($cand !== '' && vgo_table_exists_logo($conn, $cand)) return $cand;
    }
    return (string)($candidates[0] ?? '');
}

function vgo_ident_logo(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

$userId = (int)$_SESSION['user_id'];
$tableMerchants = vgo_resolve_table_logo($conn, ['merchants', 'Merchants']);

if ($tableMerchants === '') {
    http_response_code(500);
    echo 'Merchants tablosu bulunamadı.';
    exit;
}

$merchant = null;
$stmt = $conn->prepare('SELECT merchant_id, store_name, logo_url FROM ' . vgo_ident_logo($tableMerchants) . ' WHERE user_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $merchant = $stmt->get_result()->fetch_assoc() ?? null;
    $stmt->close();
}

if (!$merchant) {
    http_response_code(404);
    echo 'Mağaza bulunamadı.';
    exit;
}

$merchantId = (int)($merchant['merchant_id'] ?? 0);
$storeName = (string)($merchant['store_name'] ?? '');
$currentLogo = (string)($merchant['logo_url'] ?? '');

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['logo']) || !is_array($_FILES['logo'])) {
        $error = 'Lütfen bir dosya seçin.';
    } else {
        $file = $_FILES['logo'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Dosya yüklenemedi. (Hata kodu: ' . (int)($file['error'] ?? -1) . ')';
        } else {
            $tmp = (string)($file['tmp_name'] ?? '');
            $orig = (string)($file['name'] ?? 'logo');
            $size = (int)($file['size'] ?? 0);

            if ($size <= 0) {
                $error = 'Dosya boş görünüyor.';
            } elseif ($size > 3 * 1024 * 1024) {
                $error = 'Dosya çok büyük. Maksimum 3MB.';
            } else {
                $imgInfo = @getimagesize($tmp);
                if ($imgInfo === false) {
                    $error = 'Lütfen geçerli bir resim dosyası yükleyin (JPG/PNG/WebP).';
                } else {
                    $mime = (string)($imgInfo['mime'] ?? '');
                    $ext = '';
                    if ($mime === 'image/jpeg') $ext = 'jpg';
                    elseif ($mime === 'image/png') $ext = 'png';
                    elseif ($mime === 'image/webp') $ext = 'webp';

                    if ($ext === '') {
                        $error = 'Desteklenmeyen resim formatı: ' . htmlspecialchars($mime);
                    } else {
                        $uploadDirFs = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'merchant_logos';
                        if (!is_dir($uploadDirFs)) {
                            @mkdir($uploadDirFs, 0775, true);
                        }
                        if (!is_dir($uploadDirFs) || !is_writable($uploadDirFs)) {
                            $error = 'Yükleme klasörüne yazılamıyor: assets/uploads/merchant_logos';
                        } else {
                            $safeBase = preg_replace('/[^a-zA-Z0-9_-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
                            if ($safeBase === '' || $safeBase === null) $safeBase = 'logo';
                            $filename = 'merchant_' . $merchantId . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '_' . $safeBase . '.' . $ext;
                            $destFs = $uploadDirFs . DIRECTORY_SEPARATOR . $filename;
                            $destRel = 'assets/uploads/merchant_logos/' . $filename;

                            if (!@move_uploaded_file($tmp, $destFs)) {
                                $error = 'Dosya sunucuya taşınamadı.';
                            } else {
                                $upd = $conn->prepare('UPDATE ' . vgo_ident_logo($tableMerchants) . ' SET logo_url = ? WHERE merchant_id = ?');
                                if (!$upd) {
                                    $error = 'Logo kaydı güncellenemedi.';
                                } else {
                                    $upd->bind_param('si', $destRel, $merchantId);
                                    if ($upd->execute()) {
                                        $success = 'Logo başarıyla yüklendi.';
                                        $currentLogo = $destRel;
                                    } else {
                                        $error = 'Logo kaydı güncellenemedi.';
                                    }
                                    $upd->close();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Logo Yükle - <?php echo htmlspecialchars($storeName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4" style="max-width: 720px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Logo Yükle</h4>
        <a class="btn btn-sm btn-outline-secondary" href="merchant_profile.php">Geri</a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <div class="mb-3">
                <div class="text-muted">Mağaza: <strong><?php echo htmlspecialchars($storeName); ?></strong></div>
            </div>

            <?php if (!empty($currentLogo)): ?>
                <div class="mb-3">
                    <label class="form-label">Mevcut Logo</label>
                    <div>
                        <img src="<?php echo htmlspecialchars($currentLogo); ?>" alt="Logo" style="max-height:140px;object-fit:contain;border-radius:8px;" onerror="this.style.display='none'">
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">Yeni Logo (JPG/PNG/WebP, max 3MB)</label>
                    <input class="form-control" type="file" name="logo" accept="image/jpeg,image/png,image/webp" required>
                </div>
                <button class="btn btn-primary" type="submit">Yükle</button>
            </form>
        </div>
    </div>

    <div class="text-muted"><small>Not: Yüklenen dosyalar <code>assets/uploads/merchant_logos</code> altında saklanır.</small></div>
</div>
</body>
</html>
