<?php
session_start();
include 'db.php';
@include_once __DIR__ . '/address_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    header('Location: login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];

function vgo_addr_ident(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function vgo_addr_resolve_table(mysqli $conn, string $preferred): ?string {
    $stmt = $conn->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $preferred);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? (string)$row['TABLE_NAME'] : null;
}

ensure_default_address($conn, $user_id);
$addresses = get_user_addresses($conn, $user_id);
$activeAddress = resolve_active_address($conn, $user_id);

// Tüm şehirleri ve zone'ları yükle
$zonesTable = vgo_addr_resolve_table($conn, 'Zones') ?? vgo_addr_resolve_table($conn, 'zones');

$cities = [];
$zonesByCity = [];

if ($zonesTable) {
  $citiesRes = $conn->query("SELECT city FROM " . vgo_addr_ident($zonesTable) . " WHERE is_active = 1 GROUP BY city ORDER BY city ASC");
  while ($citiesRes && ($cr = $citiesRes->fetch_assoc())) { $cities[] = $cr['city']; }

  // Her şehir için zone'ları yükle
  foreach ($cities as $city) {
    $zonesRes = $conn->query(
      "SELECT zone_id, zone_name FROM " . vgo_addr_ident($zonesTable)
      . " WHERE is_active = 1 AND city='" . mysqli_real_escape_string($conn, $city) . "' ORDER BY zone_name ASC"
    );
    $zonesByCity[$city] = [];
    while ($zonesRes && ($zr = $zonesRes->fetch_assoc())) { $zonesByCity[$city][] = $zr; }
  }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Adreslerim - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.zone-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 8px;
    max-height: 300px;
    overflow-y: auto;
    padding: 10px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
}
.zone-btn-wrapper {
    display: flex;
}
.zone-grid .btn {
    font-size: 14px;
    padding: 10px 8px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.zone-selector {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.zone-selector .btn {
    font-size: 13px;
    padding: 6px 12px;
}
</style>
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4">
    <a href="dashboard.php" class="text-decoration-none">&larr; Geri</a>
    <h3 class="mt-2">Adreslerim</h3>

    <div class="card mt-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="text-muted">Toplam adres: <?php echo count($addresses); ?></div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNew">+ Yeni Adres</button>
            </div>
            <?php if (empty($addresses)): ?>
                <p class="text-muted">Henüz adres eklemediniz.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Etiket</th>
                                <th>Adres</th>
                                <th>Bölge</th>
                                <th>Durum</th>
                                <th class="text-end">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($addresses as $addr): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($addr['title']); ?></td>
                                <td><?php echo htmlspecialchars($addr['address_line']); ?></td>
                                <td><?php echo htmlspecialchars($addr['zone_name'] ?: '—'); ?></td>
                                <td><?php echo ($activeAddress && $activeAddress['address_id'] == $addr['address_id']) ? '<span class="badge bg-success">Aktif</span>' : '—'; ?></td>
                                <td class="text-end">
                                    <form action="address_select.php" method="POST" class="d-inline">
                                        <input type="hidden" name="address_id" value="<?php echo $addr['address_id']; ?>">
                                        <button class="btn btn-outline-primary btn-sm">Aktif Yap</button>
                                    </form>
                                    <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalEdit<?php echo $addr['address_id']; ?>">Düzenle</button>
                                    <form action="address_delete.php" method="POST" class="d-inline" onsubmit="return confirm('Silinsin mi?');">
                                        <input type="hidden" name="address_id" value="<?php echo $addr['address_id']; ?>">
                                        <button class="btn btn-outline-danger btn-sm">Sil</button>
                                    </form>
                                </td>
                            </tr>
                            <!-- Edit Modal -->
                            <div class="modal fade" id="modalEdit<?php echo $addr['address_id']; ?>" tabindex="-1" aria-hidden="true">
                              <div class="modal-dialog modal-xl">
                                <div class="modal-content">
                                  <form action="address_update.php" method="POST">
                                    <div class="modal-header">
                                      <h5 class="modal-title">Adresi Düzenle</h5>
                                      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                      <input type="hidden" name="address_id" value="<?php echo $addr['address_id']; ?>">
                                      <input type="hidden" name="zone_id" id="editSelectedZoneId<?php echo $addr['address_id']; ?>" value="<?php echo $addr['zone_id']; ?>">
                                      
                                      <div class="row">
                                        <div class="col-md-4 mb-2">
                                          <label class="form-label">Şehir</label>
                                          <select class="form-select editCitySelect" id="editCitySelect<?php echo $addr['address_id']; ?>" data-address-id="<?php echo $addr['address_id']; ?>" required>
                                            <option value="">Şehir Seçin</option>
                                            <?php foreach ($cities as $city): ?>
                                              <option value="<?php echo htmlspecialchars($city); ?>" <?php echo ($addr['city'] == $city) ? 'selected' : ''; ?>><?php echo htmlspecialchars($city); ?></option>
                                            <?php endforeach; ?>
                                          </select>
                                        </div>
                                        
                                        <div class="col-md-8 mb-2" id="editZoneSection<?php echo $addr['address_id']; ?>">
                                          <label class="form-label">Bölge</label>
                                          <div class="zone-grid" id="editZoneGrid<?php echo $addr['address_id']; ?>">
                                            <!-- Zones will be loaded dynamically -->
                                          </div>
                                        </div>
                                      </div>
                                      
                                      <div class="mb-2">
                                        <label class="form-label">Açık Adres</label>
                                        <textarea name="address_line" class="form-control" rows="2" required><?php echo htmlspecialchars($addr['address_line']); ?></textarea>
                                      </div>
                                      
                                      <div class="mb-2">
                                        <label class="form-label">Adres Etiketi</label>
                                        <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($addr['title']); ?>" required>
                                      </div>
                                    </div>
                                    <div class="modal-footer">
                                      <button type="button" class="btn btn-link" data-bs-dismiss="modal">Vazgeç</button>
                                      <button type="submit" class="btn btn-success">Kaydet</button>
                                    </div>
                                  </form>
                                </div>
                              </div>
                            </div>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Address Modal -->
<div class="modal fade" id="modalNew" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form action="address_add.php" method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Yeni Adres Ekle</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-3 mb-3">
              <label class="form-label">Şehir Seçin</label>
              <select id="citySelect" name="city" class="form-select" size="10" style="height: 300px; font-size: 14px;" required>
                <option value="">Şehir Seçin</option>
                <?php foreach ($cities as $city): ?>
                  <option value="<?php echo htmlspecialchars($city); ?>"><?php echo htmlspecialchars($city); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-9 mb-3" id="zoneSection" style="display:none;">
              <label class="form-label">Bölge Seçin</label>
              <div class="zone-grid" id="zoneGrid" style="max-height: 300px; overflow-y: auto;">
                <!-- JavaScript ile doldurulacak -->
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Açık Adres</label>
            <textarea name="address_line" class="form-control" rows="3" placeholder="Mahalle, Sokak, Bina No, Daire No" required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Adres Etiketi</label>
            <input type="text" name="title" class="form-control" placeholder="Ev, İş, Okul, Diğer" required>
          </div>
          <input type="hidden" name="zone_id" id="selectedZoneId">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-link" data-bs-dismiss="modal">Vazgeç</button>
          <button type="submit" class="btn btn-success">Kaydet ve Kullan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Şehir bazlı zone verisi (PHP önbelleği + canlı API çağrısı)
const zonesByCity = <?php echo json_encode($zonesByCity); ?>;

function fetchZones(city) {
  // Eğer PHP'den gelen cache varsa onu kullan, yoksa API'den çek
  if (zonesByCity[city]) {
    return Promise.resolve(zonesByCity[city]);
  }
  return fetch('api/get_zones.php?city=' + encodeURIComponent(city))
    .then(res => res.ok ? res.json() : [])
    .then(data => {
      zonesByCity[city] = Array.isArray(data) ? data : [];
      return zonesByCity[city];
    })
    .catch(() => zonesByCity[city] || []);
}

function renderZones(zones, grid, hiddenInput, radioName, currentZoneId) {
  grid.innerHTML = '';
  zones.forEach(function(zone) {
    const wrapper = document.createElement('div');
    wrapper.className = 'zone-btn-wrapper';

    const input = document.createElement('input');
    input.type = 'radio';
    input.name = radioName;
    input.id = radioName + '_' + zone.zone_id;
    input.value = zone.zone_id;
    input.className = 'btn-check';
    if (currentZoneId && zone.zone_id == currentZoneId) {
      input.checked = true;
    }
    input.addEventListener('change', function() {
      hiddenInput.value = this.value;
    });

    const label = document.createElement('label');
    label.className = 'btn btn-outline-primary w-100';
    label.htmlFor = radioName + '_' + zone.zone_id;
    label.textContent = zone.zone_name;

    wrapper.appendChild(input);
    wrapper.appendChild(label);
    grid.appendChild(wrapper);
  });
}

// Yeni adres modali
document.getElementById('citySelect').addEventListener('change', function() {
  const city = this.value;
  const zoneSection = document.getElementById('zoneSection');
  const zoneGrid = document.getElementById('zoneGrid');
  const selectedZoneId = document.getElementById('selectedZoneId');

  if (!city) {
    zoneSection.style.display = 'none';
    selectedZoneId.value = '';
    return;
  }

  fetchZones(city).then(function(zones) {
    if (!zones.length) {
      zoneSection.style.display = 'none';
      selectedZoneId.value = '';
      return;
    }
    renderZones(zones, zoneGrid, selectedZoneId, 'zone_radio', null);
    zoneSection.style.display = 'block';
  });
});

// Edit modal handlers
document.querySelectorAll('.editCitySelect').forEach(function(select) {
  const addressId = select.dataset.addressId;
  const zoneSection = document.getElementById('editZoneSection' + addressId);
  const zoneGrid = document.getElementById('editZoneGrid' + addressId);
  const selectedZoneId = document.getElementById('editSelectedZoneId' + addressId);

  function loadEditZones(city, currentZoneId) {
    if (!city) {
      zoneSection.style.display = 'none';
      selectedZoneId.value = '';
      return;
    }
    fetchZones(city).then(function(zones) {
      if (!zones.length) {
        zoneSection.style.display = 'none';
        selectedZoneId.value = '';
        return;
      }
      renderZones(zones, zoneGrid, selectedZoneId, 'edit_zone_radio_' + addressId, currentZoneId);
      zoneSection.style.display = 'block';
    });
  }

  // Modal açıldığında mevcut şehir için yükle
  if (select.value) {
    loadEditZones(select.value, selectedZoneId.value);
  }

  // Şehir değişince yükle
  select.addEventListener('change', function() {
    loadEditZones(this.value, selectedZoneId.value);
  });
});
</script>

</body>
</html>
