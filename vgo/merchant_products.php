<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];

// merchant_id al
$stmt = $conn->prepare("SELECT merchant_id, store_name FROM Merchants WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$merchant = $res->fetch_assoc();
$stmt->close();
if (!$merchant) { echo "Mağaza bulunamadı."; exit; }
$merchant_id = $merchant['merchant_id'];

// Eğer Products tablosu yoksa basit tablo oluştur (ilk kez kullanımda) ve gerekli sütunları ekle
$res = $conn->query("SHOW TABLES LIKE 'Products'");
if (!$res || $res->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS Products (
        product_id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        description VARCHAR(400) NULL,
        price DECIMAL(10,2) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        category VARCHAR(100) NULL,
        image VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT FK_Products_Merchants FOREIGN KEY (merchant_id) REFERENCES Merchants(merchant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} else {
    // varsa, sütunları kontrol et ve ekle (image, category)
    $cols = ['category', 'image'];
    foreach ($cols as $c) {
        $r = $conn->query("SHOW COLUMNS FROM Products LIKE '" . $conn->real_escape_string($c) . "'");
        if (!$r || $r->num_rows == 0) {
            if ($c === 'image') $conn->query("ALTER TABLE Products ADD COLUMN image VARCHAR(255) NULL");
            if ($c === 'category') $conn->query("ALTER TABLE Products ADD COLUMN category VARCHAR(100) NULL");
        }
    }
}

$uploadDir = __DIR__ . '/uploads/products/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// POST: ürün ekle, düzenle veya sil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_product'])) {
        $name = trim($_POST['name']);
        $desc = trim($_POST['description']);
        $price = (float)$_POST['price'];
        $category = trim($_POST['category'] ?? '');

        // insert first to get id
        $stmt = $conn->prepare("INSERT INTO Products (merchant_id, name, description, price, category) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issds", $merchant_id, $name, $desc, $price, $category);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();

        // handle image upload
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['image']['tmp_name'];
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $filename = time() . '_' . $newId . '.' . $ext;
            if (move_uploaded_file($tmp, $uploadDir . $filename)) {
                $stmt = $conn->prepare("UPDATE Products SET image = ? WHERE product_id = ?");
                $path = 'uploads/products/' . $filename;
                $stmt->bind_param("si", $path, $newId);
                $stmt->execute();
                $stmt->close();
            }
        }

        $msg = 'Ürün eklendi.';
    }

    if (isset($_POST['edit_product'])) {
        $pid = (int)$_POST['product_id'];
        $name = trim($_POST['name']);
        $desc = trim($_POST['description']);
        $price = (float)$_POST['price'];
        $category = trim($_POST['category'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // doğrula merchant
        $stmt = $conn->prepare("SELECT image FROM Products WHERE product_id = ? AND merchant_id = ? LIMIT 1");
        $stmt->bind_param("ii", $pid, $merchant_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $existing = $res->fetch_assoc();
        $stmt->close();

        if ($existing) {
            // handle image upload
            if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                // delete old
                if (!empty($existing['image']) && file_exists(__DIR__ . '/' . $existing['image'])) {
                    unlink(__DIR__ . '/' . $existing['image']);
                }
                $tmp = $_FILES['image']['tmp_name'];
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $filename = time() . '_' . $pid . '.' . $ext;
                if (move_uploaded_file($tmp, $uploadDir . $filename)) {
                    $imgPath = 'uploads/products/' . $filename;
                }
            } else {
                $imgPath = $existing['image'];
            }

            $stmt = $conn->prepare("UPDATE Products SET name = ?, description = ?, price = ?, category = ?, is_active = ?, image = ? WHERE product_id = ? AND merchant_id = ?");
            $stmt->bind_param("ssdsisii", $name, $desc, $price, $category, $is_active, $imgPath, $pid, $merchant_id);
            $stmt->execute();
            $stmt->close();
            $msg = 'Ürün güncellendi.';
        } else {
            $error = 'Ürün bulunamadı veya yetkiniz yok.';
        }
    }
}

if (isset($_GET['delete'])) {
    $pid = (int)$_GET['delete'];
    // silmeden önce resmi sil
    $stmt = $conn->prepare("SELECT image FROM Products WHERE product_id = ? AND merchant_id = ? LIMIT 1");
    $stmt->bind_param("ii", $pid, $merchant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $p = $res->fetch_assoc();
    $stmt->close();
    if ($p && !empty($p['image']) && file_exists(__DIR__ . '/' . $p['image'])) {
        unlink(__DIR__ . '/' . $p['image']);
    }

    $stmt = $conn->prepare("DELETE FROM Products WHERE product_id = ? AND merchant_id = ?");
    $stmt->bind_param("ii", $pid, $merchant_id);
    $stmt->execute();
    $stmt->close();
    $msg = 'Ürün silindi.';
}

if (isset($_GET['toggle'])) {
    $pid = (int)$_GET['toggle'];
    $stmt = $conn->prepare("SELECT is_active FROM Products WHERE product_id = ? AND merchant_id = ? LIMIT 1");
    $stmt->bind_param("ii", $pid, $merchant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $p = $res->fetch_assoc();
    $stmt->close();
    if ($p) {
        $new = $p['is_active'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE Products SET is_active = ? WHERE product_id = ? AND merchant_id = ?");
        $stmt->bind_param("iii", $new, $pid, $merchant_id);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: merchant_products.php');
    exit;
}

// edit view
$editing = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM Products WHERE product_id = ? AND merchant_id = ? LIMIT 1");
    $stmt->bind_param("ii", $eid, $merchant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $editing = $res->fetch_assoc();
    $stmt->close();
}

$stmt = $conn->prepare("SELECT product_id, name, price, is_active, category, image FROM Products WHERE merchant_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $merchant_id);
$stmt->execute();
$products = $stmt->get_result();
$stmt->close();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ürünler - <?php echo htmlspecialchars($merchant['store_name']); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4">
    <a href="merchant_dashboard.php">&larr; Geri</a>
    <h4 class="mt-3">Ürün Yönetimi</h4>
    <?php if(isset($msg)): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
    <?php if(isset($error)): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <h6><?php echo $editing ? 'Ürünü Düzenle' : 'Yeni Ürün Ekle'; ?></h6>
            <form method="POST" enctype="multipart/form-data">
                <?php if ($editing): ?><input type="hidden" name="product_id" value="<?php echo $editing['product_id']; ?>"><?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">Ürün Adı</label>
                    <input name="name" class="form-control" required value="<?php echo htmlspecialchars($editing['name'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Açıklama</label>
                    <textarea name="description" class="form-control"><?php echo htmlspecialchars($editing['description'] ?? ''); ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Fiyat</label>
                    <input name="price" type="number" step="0.01" class="form-control" required value="<?php echo htmlspecialchars($editing['price'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Kategori</label>
                    <input name="category" class="form-control" value="<?php echo htmlspecialchars($editing['category'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Resim</label>
                    <?php if (!empty($editing['image'])): ?>
                        <div><img src="<?php echo htmlspecialchars($editing['image']); ?>" width="120" class="mb-2" alt="product"></div>
                    <?php endif; ?>
                    <input name="image" type="file" accept="image/*" class="form-control">
                </div>
                <?php if ($editing): ?>
                    <div class="form-check mb-3">
                        <input type="checkbox" name="is_active" class="form-check-input" id="is_active" <?php echo $editing['is_active'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Aktif</label>
                    </div>
                    <button name="edit_product" class="btn btn-primary">Güncelle</button>
                    <a href="merchant_products.php" class="btn btn-outline-secondary">Vazgeç</a>
                <?php else: ?>
                    <button name="add_product" class="btn btn-primary">Ekle</button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <h6>Mevcut Ürünler</h6>
    <?php if($products && $products->num_rows>0): ?>
        <ul class="list-group">
            <?php while($p = $products->fetch_assoc()): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <?php if (!empty($p['image'])): ?>
                            <img src="<?php echo htmlspecialchars($p['image']); ?>" width="64" class="me-3 rounded" alt="img">
                        <?php endif; ?>
                        <div>
                            <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                            <div><small><?php echo number_format($p['price'],2); ?> ₺</small></div>
                            <?php if (!empty($p['category'])): ?><div><small>Kategori: <?php echo htmlspecialchars($p['category']); ?></small></div><?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <a href="merchant_products.php?edit=<?php echo $p['product_id']; ?>" class="btn btn-sm btn-outline-secondary">Düzenle</a>
                        <a href="merchant_products.php?toggle=<?php echo $p['product_id']; ?>" class="btn btn-sm btn-outline-info"><?php echo $p['is_active'] ? 'Pasif Yap' : 'Aktif Yap'; ?></a>
                        <a href="?delete=<?php echo $p['product_id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Silinsin mi?')">Sil</a>
                    </div>
                </li>
            <?php endwhile; ?>
        </ul>
    <?php else: ?>
        <p class="text-muted">Henüz ürün yok.</p>
    <?php endif; ?>

</div>
</body>
</html>