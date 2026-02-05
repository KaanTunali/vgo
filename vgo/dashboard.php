<?php
session_start();
include 'db.php';
@include_once __DIR__ . '/address_helper.php';

// Yetki kontrolü: giriş yapmış müşteri (role_id = 4) değilse login sayfasına yönlendir
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];

// Müşteri id'sini ve zone'unu al
$stmt = $conn->prepare("SELECT customer_id, zone_id FROM Customers WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$customer = $res->fetch_assoc();
$customer_id = $customer ? $customer['customer_id'] : null;
$customer_zone_id = $customer ? $customer['zone_id'] : null;
$stmt->close();

// Adresleri yükle ve aktif adresi belirle
ensure_default_address($conn, $user_id);
$addresses = get_user_addresses($conn, $user_id);
$activeAddress = resolve_active_address($conn, $user_id);
if ($activeAddress) {
    set_active_address($activeAddress);
}
$active_zone_id = $activeAddress['zone_id'] ?? $customer_zone_id ?? null;

// Zone listesi (adres ekleme için)
$zonesRes = $conn->query("SELECT MIN(zone_id) AS zone_id, zone_name FROM Zones WHERE is_active = 1 AND city = 'Istanbul' GROUP BY zone_name ORDER BY zone_name ASC");
$zones = [];
while ($zr = $zonesRes->fetch_assoc()) { $zones[] = $zr; }

// Arama sorgusu varsa filtrele
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$searchSql = "";
$baseSql = "SELECT merchant_id, store_name, city, rating_avg, opening_time, closing_time FROM Merchants WHERE is_active = 1";

// Zone ve şehir filtresi: sadece müşterinin zone ve şehrindeki mağazaları göster
if ($active_zone_id) {
    $baseSql .= " AND zone_id = " . (int)$active_zone_id;
}
// Aktif adresin şehrine göre filtrele
// NOT: Zone seçiliyse ayrıca city filtresi uygulamak gereksiz ve geçmişte
// address_add.php sabit Istanbul yazdığı için şehirler arası yanlış filtrelemeye yol açıyordu.
if (!$active_zone_id && $activeAddress && !empty($activeAddress['city'])) {
    $city = mysqli_real_escape_string($conn, $activeAddress['city']);
    $baseSql .= " AND city = '$city'";
}

if ($search !== '') {
    $like = "%" . $search . "%";
    $stmt = $conn->prepare($baseSql . " AND (store_name LIKE ? OR city LIKE ?) LIMIT 50");
    $stmt->bind_param("ss", $like, $like);
} else {
    $stmt = $conn->prepare($baseSql . " LIMIT 50");
}
$stmt->execute();
$merchants = $stmt->get_result();
$stmt->close();

// Kullanıcının favori mağazalarını al
@include_once __DIR__ . '/favorites_helper.php';
$favMerchants = [];
if (!empty($customer_id)) {
    $favMerchants = list_favorite_merchants($conn, $user_id);
} 

// Son siparişleri al - Direkt SQL sorgusu (stored procedure yerine)
$recentOrders = [];
if ($customer_id) {
    $stmt = $conn->prepare("SELECT 
        o.order_id,
        o.order_date,
        o.status,
        o.total_price,
        m.store_name,
        m.city AS merchant_city,
        d.status AS delivery_status
    FROM Orders o
    JOIN Customers c ON o.customer_id = c.customer_id
    JOIN Merchants m ON o.merchant_id = m.merchant_id
    LEFT JOIN Deliveries d ON o.order_id = d.order_id
    WHERE c.user_id = ?
    ORDER BY o.order_date DESC
    LIMIT 5");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $recentOrders = $stmt->get_result();
    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Müşteri Paneli - VGO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .address-bar{background:#fff;border:1px solid #e9ecef;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.04)}
        .address-pill{display:inline-flex;align-items:center;gap:8px;background:#f6f8fa;border:1px solid #e9ecef;border-radius:999px;padding:6px 12px}
        .address-actions{display:flex;gap:10px}
        .section-title{margin:10px 0 6px;font-weight:600}
        .merchant-card .card-body{padding:14px}
        .stat{display:inline-flex;align-items:center;gap:6px;color:#6c757d}
        .btn-icon{display:inline-flex;align-items:center;gap:8px}
        .zone-grid{display:flex;flex-wrap:wrap;gap:8px}
        .zone-btn-wrapper{display:inline-block}
        .zone-grid .btn-sm{font-size:13px;padding:6px 12px}
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="container mt-4">
    <div class="address-bar mb-3">
        <div class="d-flex justify-content-between align-items-center">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="text-muted small">Aktif Teslimat Adresi</span>
                <?php if ($activeAddress): ?>
                    <span class="address-pill">
                        <strong><?php echo htmlspecialchars($activeAddress['title']); ?></strong>
                        <span class="text-muted">— <?php echo htmlspecialchars($activeAddress['address_line']); ?></span>
                        <?php if (!empty($activeAddress['zone_name'])): ?>
                            <span class="badge bg-primary"><?php echo htmlspecialchars($activeAddress['zone_name']); ?></span>
                        <?php endif; ?>
                    </span>
                <?php else: ?>
                    <span class="text-danger">Adres bulunamadı. Lütfen adres ekleyin.</span>
                <?php endif; ?>
            </div>
            <div class="address-actions">
                <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddressAdd">+ Yeni Adres</button>
                <form action="address_select.php" method="POST" class="d-flex" style="gap:8px;" id="formAddressSelect">
                    <select name="address_id" class="form-select form-select-sm" style="min-width:360px">
                        <?php foreach ($addresses as $addr): ?>
                            <option value="<?php echo $addr['address_id']; ?>" <?php echo ($activeAddress && $activeAddress['address_id'] == $addr['address_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($addr['title']); ?> — <?php echo htmlspecialchars($addr['address_line']); ?><?php echo $addr['zone_name'] ? ' (' . htmlspecialchars($addr['zone_name']) . ')' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary btn-sm" type="submit">Adresi Seç</button>
                </form>
                <form action="address_delete.php" method="POST" onsubmit="return confirm('Seçili adres silinsin mi?');">
                    <input type="hidden" name="address_id" id="deleteAddressId">
                    <button class="btn btn-outline-danger btn-sm" type="submit">Adresi Sil</button>
                </form>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-8">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h4>Hoş geldin, <?php echo htmlspecialchars($full_name); ?>!</h4>
                    <?php if ($customer_zone_id): 
                        $zstmt = $conn->prepare("SELECT zone_name, city FROM Zones WHERE zone_id = ?");
                        $zstmt->bind_param("i", $customer_zone_id);
                        $zstmt->execute();
                        $zinfo = $zstmt->get_result()->fetch_assoc();
                        $zstmt->close();
                        if ($zinfo):
                    ?>
                        <small class="text-muted">📍 Bölgeniz: <?php echo htmlspecialchars($zinfo['city'] . ' - ' . $zinfo['zone_name']); ?></small>
                    <?php endif; endif; ?>
                </div>
                <form class="d-flex" method="GET" action="dashboard.php">
                    <input class="form-control me-2" name="q" type="search" placeholder="Restoran veya şehir ara" value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">Ara</button>
                </form>
            </div>

            <hr>

            <h5 class="section-title">Mağazalar</h5>
            <div class="row">
                <?php while ($m = $merchants->fetch_assoc()): ?>
                    <div class="col-md-6 mb-3">
                        <div class="card merchant-card h-100">
                            <div class="card-body d-flex flex-column">
                                <div class="flex-grow-1">
                                    <h6 class="card-title"><?php echo htmlspecialchars($m['store_name']); ?></h6>
                                    <div class="d-flex align-items-center gap-3 mb-2">
                                        <span class="stat">📍 <?php echo htmlspecialchars($m['city']); ?></span>
                                        <span class="stat">⭐ <strong><?php echo number_format($m['rating_avg'],1); ?></strong></span>
                                        <span class="stat">⏰ <small><?php echo htmlspecialchars($m['opening_time']); ?> - <?php echo htmlspecialchars($m['closing_time']); ?></small></span>
                                    </div>
                                </div>
                                <div class="d-grid gap-2">
                                    <a href="menu.php?id=<?php echo $m['merchant_id']; ?>" class="btn btn-sm btn-primary btn-icon">📋 Menüyü Gör</a>
                                    <a href="merchant.php?id=<?php echo $m['merchant_id']; ?>" class="btn btn-sm btn-outline-primary btn-icon">ℹ️ Detay &amp; Yorumlar</a>
                                    <div class="d-flex gap-2">
                                        <a href="merchant_menu.php?id=<?php echo $m['merchant_id']; ?>" class="btn btn-sm btn-success btn-icon flex-grow-1">🛒 Sipariş Ver</a>
                                        <button class="btn btn-sm btn-outline-secondary favorite-toggle" data-merchant-id="<?php echo $m['merchant_id']; ?>" title="Favorilere ekle/çıkar">
                                            <span class="heart"><?php echo in_array($m['merchant_id'], $favMerchants) ? '♥' : '♡'; ?></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>

        </div>

        <div class="col-md-4">
            <div class="card mb-3">
                <div class="card-body">
                    <h6>Sık Kullanılanlar</h6>
                    <ul class="list-unstyled">
                        <li><a href="orders.php">Tüm Siparişlerim</a></li>
                        <li><a href="favorites.php">💝 Favorilerim</a></li>
                        <li><a href="profile.php">Hesap Ayarları</a></li>
                        <li><a href="cart.php">Sepetim</a></li>
                    </ul>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6>Son Siparişler</h6>
                    <?php if ($recentOrders && $recentOrders->num_rows > 0): ?>
                        <ul class="list-group list-group-flush">
                            <?php while ($o = $recentOrders->fetch_assoc()): ?>
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong><?php echo htmlspecialchars($o['store_name']); ?></strong>
                                            <div><small><?php echo htmlspecialchars($o['order_date']); ?></small></div>
                                            <div><small>Durum: <?php echo htmlspecialchars($o['status']); ?></small></div>
                                        </div>
                                        <div class="text-end">
                                            <div><strong><?php echo number_format($o['total_price'],2); ?> ₺</strong></div>
                                            <a href="order_details.php?id=<?php echo $o['order_id']; ?>">Detay</a>
                                        </div>
                                    </div>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted">Henüz sipariş yok.</p>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Yeni Adres Modal -->
<div class="modal fade" id="modalAddressAdd" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Yeni Adres Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="address_add.php" method="POST" id="formAddressAdd">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">🏙️ Şehir</label>
                            <select id="dashCitySelect" class="form-select" size="10" style="height: 300px;" required>
                                <option value="">Şehir Seçin</option>
                                <?php 
                                $cityQuery = $conn->query("SELECT city FROM Zones WHERE is_active = 1 GROUP BY city ORDER BY city ASC");
                                while ($c = $cityQuery->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($c['city']); ?>"><?php echo htmlspecialchars($c['city']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label">📍 Bölge Seçin</label>
                            <div id="dashZoneSection" style="display:none;">
                                <div class="zone-grid" id="dashZoneGrid" style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px;">
                                    <!-- JavaScript ile doldurulacak -->
                                </div>
                            </div>
                            <input type="hidden" name="zone_id" id="dashSelectedZoneId" required>
                        </div>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-md-8">
                            <label class="form-label">🏠 Açık Adres</label>
                            <textarea name="address_line" class="form-control" rows="2" placeholder="Mahalle, Sokak, Bina No, Daire No" required></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">🏷️ Adres Etiketi</label>
                            <input type="text" name="title" class="form-control" placeholder="Ev, İş, Okul" required>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Vazgeç</button>
                <button type="submit" form="formAddressAdd" class="btn btn-success">Kaydet ve Kullan</button>
            </div>
        </div>
    </div>
</div>

<script>
// Zone loading for dashboard modal
const dashZonesByCity = <?php 
$allCities = $conn->query("SELECT city FROM Zones WHERE is_active = 1 GROUP BY city ORDER BY city ASC");
$dashZonesData = [];
while ($ct = $allCities->fetch_assoc()) {
    $city = $ct['city'];
    $zonesRes = $conn->query("SELECT MIN(zone_id) AS zone_id, zone_name FROM Zones WHERE is_active = 1 AND city='".mysqli_real_escape_string($conn, $city)."' GROUP BY zone_name ORDER BY zone_name ASC");
    $dashZonesData[$city] = [];
    while ($zr = $zonesRes->fetch_assoc()) { $dashZonesData[$city][] = $zr; }
}
echo json_encode($dashZonesData);
?>;

document.getElementById('dashCitySelect')?.addEventListener('change', function() {
    const city = this.value;
    const zoneSection = document.getElementById('dashZoneSection');
    const zoneGrid = document.getElementById('dashZoneGrid');
    const selectedZoneId = document.getElementById('dashSelectedZoneId');
    
    if (!city) {
        zoneSection.style.display = 'none';
        selectedZoneId.value = '';
        return;
    }
    
    const zones = dashZonesByCity[city] || [];
    zoneGrid.innerHTML = '';
    
    if (zones.length === 0) {
        zoneGrid.innerHTML = '<p class="text-muted">Bu şehirde bölge yok</p>';
        zoneSection.style.display = 'block';
        return;
    }
    
    zones.forEach(function(zone) {
        const wrapper = document.createElement('div');
        wrapper.className = 'zone-btn-wrapper d-inline-block me-2 mb-2';
        
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = 'dash_zone_radio';
        input.id = 'dash_zone_' + zone.zone_id;
        input.value = zone.zone_id;
        input.className = 'btn-check';
        input.addEventListener('change', function() {
            selectedZoneId.value = this.value;
        });
        
        const label = document.createElement('label');
        label.className = 'btn btn-outline-primary btn-sm';
        label.htmlFor = 'dash_zone_' + zone.zone_id;
        label.textContent = zone.zone_name;
        
        wrapper.appendChild(input);
        wrapper.appendChild(label);
        zoneGrid.appendChild(wrapper);
    });
    
    zoneSection.style.display = 'block';
});

// Favorite toggle handler
// Keep delete form in sync with selected address
const sel = document.querySelector('#formAddressSelect select[name="address_id"]');
const delInput = document.getElementById('deleteAddressId');
function syncDeleteId(){ if (sel && delInput) delInput.value = sel.value; }
syncDeleteId();
if (sel) sel.addEventListener('change', syncDeleteId);
document.querySelectorAll('.favorite-toggle').forEach(function(btn){
    btn.addEventListener('click', function(e){
        var id = this.dataset.merchantId;
        var heart = this.querySelector('.heart');
        var that = this;
        fetch('toggle_favorite.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'merchant_id=' + encodeURIComponent(id)
        }).then(r=>r.json()).then(function(json){
            if (!json.ok) return alert('Hata: ' + (json.error||''));
            if (json.action === 'added') heart.textContent = '♥'; else heart.textContent = '♡';
        }).catch(function(){ alert('İstek başarısız.'); });
    });
});
</script>

</body>
</html>
