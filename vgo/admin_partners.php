<?php
session_start();
require_once 'db.php';
@include_once __DIR__ . '/migrations_runner.php';
require_once __DIR__ . '/audit_helper.php';

if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 6], true)) {
    header('Location: login.php');
    exit;
}

$tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : 'merchants';
if (!in_array($tab, ['merchants', 'couriers'], true)) $tab = 'merchants';

function vgo_table_exists2(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function vgo_resolve_table_name2(mysqli $conn, array $candidates): string {
    foreach ($candidates as $cand) {
        $cand = (string)$cand;
        if ($cand !== '' && vgo_table_exists2($conn, $cand)) return $cand;
    }
    return (string)($candidates[0] ?? '');
}

function vgo_ident2(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function vgo_column_exists2(mysqli $conn, string $table, string $col): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

$tableMerchants = vgo_resolve_table_name2($conn, ['Merchants', 'merchants']);
$tableUsers = vgo_resolve_table_name2($conn, ['Users', 'users']);
$tableCouriers = vgo_resolve_table_name2($conn, ['Couriers', 'couriers']);

$merchantsHasActive = ($tableMerchants !== '') && vgo_column_exists2($conn, $tableMerchants, 'is_active');
$usersHasStatus = ($tableUsers !== '') && vgo_column_exists2($conn, $tableUsers, 'status');
$couriersHasAvailable = ($tableCouriers !== '') && vgo_column_exists2($conn, $tableCouriers, 'is_available');

$flash = (string)($_SESSION['flash_msg'] ?? '');
$flashType = (string)($_SESSION['flash_type'] ?? 'success');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    $tabPost = isset($_POST['tab']) ? trim((string)$_POST['tab']) : 'merchants';
    if (!in_array($tabPost, ['merchants', 'couriers'], true)) $tabPost = 'merchants';
    $qPost = isset($_POST['q']) ? trim((string)$_POST['q']) : '';

    $flash2 = '';
    $flashType2 = 'success';

    if ($action === 'toggle_merchant_active') {
        $merchantId = isset($_POST['merchant_id']) ? (int)$_POST['merchant_id'] : 0;
        if ($merchantId <= 0) {
            $flashType2 = 'danger';
            $flash2 = 'Geçersiz merchant.';
        } elseif (!$merchantsHasActive) {
            $flashType2 = 'warning';
            $flash2 = 'Merchants.is_active yok; işlem kapalı.';
        } else {
            $row = null;
            $stmt0 = $conn->prepare('SELECT merchant_id, user_id, is_active FROM ' . vgo_ident2($tableMerchants) . ' WHERE merchant_id = ? LIMIT 1');
            if ($stmt0) {
                $stmt0->bind_param('i', $merchantId);
                $stmt0->execute();
                $row = $stmt0->get_result()->fetch_assoc();
                $stmt0->close();
            }
            $old = (int)($row['is_active'] ?? 0);
            $new = $old ? 0 : 1;
            $uid = (int)($row['user_id'] ?? 0);

            $stmt = $conn->prepare('UPDATE ' . vgo_ident2($tableMerchants) . ' SET is_active = ? WHERE merchant_id = ?');
            if ($stmt) {
                $stmt->bind_param('ii', $new, $merchantId);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    vgo_audit_log($conn, 'admin.merchant.toggle_active', 'Merchant', $merchantId, [
                        'user_id' => $uid,
                        'old_is_active' => $old,
                        'new_is_active' => $new,
                    ]);
                    $flashType2 = 'success';
                    $flash2 = 'Restoran durumu güncellendi.';
                } else {
                    $flashType2 = 'danger';
                    $flash2 = 'Restoran durumu güncellenemedi.';
                }
            }
        }
    }

    if ($action === 'toggle_merchant_status') {
        $merchantId = isset($_POST['merchant_id']) ? (int)$_POST['merchant_id'] : 0;
        if ($merchantId <= 0) {
            $flashType2 = 'danger';
            $flash2 = 'Geçersiz merchant.';
        } elseif (!$usersHasStatus) {
            $flashType2 = 'warning';
            $flash2 = 'Users.status yok; işlem kapalı.';
        } else {
            $row = null;
            $stmt0 = $conn->prepare('SELECT merchant_id, user_id FROM ' . vgo_ident2($tableMerchants) . ' WHERE merchant_id = ? LIMIT 1');
            if ($stmt0) {
                $stmt0->bind_param('i', $merchantId);
                $stmt0->execute();
                $row = $stmt0->get_result()->fetch_assoc();
                $stmt0->close();
            }
            $uid = (int)($row['user_id'] ?? 0);
            if ($uid <= 0) {
                $flashType2 = 'danger';
                $flash2 = 'Merchant user_id bulunamadı.';
            } else {
                $oldStatus = '';
                $stmtS = $conn->prepare('SELECT status FROM ' . vgo_ident2($tableUsers) . ' WHERE user_id = ? LIMIT 1');
                if ($stmtS) {
                    $stmtS->bind_param('i', $uid);
                    $stmtS->execute();
                    $rowS = $stmtS->get_result()->fetch_assoc() ?? [];
                    $oldStatus = (string)($rowS['status'] ?? '');
                    $stmtS->close();
                }
                $newStatus = (strtolower($oldStatus) === 'active') ? 'inactive' : 'active';

                $stmtU = $conn->prepare('UPDATE ' . vgo_ident2($tableUsers) . ' SET status = ? WHERE user_id = ?');
                if ($stmtU) {
                    $stmtU->bind_param('si', $newStatus, $uid);
                    $ok = $stmtU->execute();
                    $stmtU->close();

                    if ($ok) {
                        vgo_audit_log($conn, 'admin.merchant.toggle_status', 'Merchant', $merchantId, [
                            'user_id' => $uid,
                            'old_status' => $oldStatus,
                            'new_status' => $newStatus,
                        ]);
                        $flashType2 = 'success';
                        $flash2 = 'Merchant kullanıcı durumu güncellendi.';
                    } else {
                        $flashType2 = 'danger';
                        $flash2 = 'Merchant kullanıcı durumu güncellenemedi.';
                    }
                }
            }
        }
    }

    if ($action === 'toggle_courier_status') {
        $courierUserId = isset($_POST['courier_user_id']) ? (int)$_POST['courier_user_id'] : 0;
        if ($courierUserId <= 0) {
            $flashType2 = 'danger';
            $flash2 = 'Geçersiz kurye.';
        } elseif (!$usersHasStatus) {
            $flashType2 = 'warning';
            $flash2 = 'Users.status yok; işlem kapalı.';
        } else {
            $oldStatus = '';
            $stmt0 = $conn->prepare('SELECT status FROM ' . vgo_ident2($tableUsers) . ' WHERE user_id = ? LIMIT 1');
            if ($stmt0) {
                $stmt0->bind_param('i', $courierUserId);
                $stmt0->execute();
                $row0 = $stmt0->get_result()->fetch_assoc() ?? [];
                $oldStatus = (string)($row0['status'] ?? '');
                $stmt0->close();
            }
            $newStatus = (strtolower($oldStatus) === 'active') ? 'inactive' : 'active';

            $stmt = $conn->prepare('UPDATE ' . vgo_ident2($tableUsers) . ' SET status = ? WHERE user_id = ?');
            if ($stmt) {
                $stmt->bind_param('si', $newStatus, $courierUserId);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    vgo_audit_log($conn, 'admin.courier.toggle_status', 'Courier', $courierUserId, [
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                    ]);
                    $flashType2 = 'success';
                    $flash2 = 'Kurye kullanıcı durumu güncellendi.';
                } else {
                    $flashType2 = 'danger';
                    $flash2 = 'Kurye kullanıcı durumu güncellenemedi.';
                }
            }
        }
    }

    if ($action === 'toggle_courier_available') {
        $courierId = isset($_POST['courier_id']) ? (int)$_POST['courier_id'] : 0;
        if ($courierId <= 0) {
            $flashType2 = 'danger';
            $flash2 = 'Geçersiz kurye.';
        } elseif (!$couriersHasAvailable) {
            $flashType2 = 'warning';
            $flash2 = 'Couriers.is_available yok; işlem kapalı.';
        } else {
            $row = null;
            $stmt0 = $conn->prepare('SELECT courier_id, user_id, is_available FROM ' . vgo_ident2($tableCouriers) . ' WHERE courier_id = ? LIMIT 1');
            if ($stmt0) {
                $stmt0->bind_param('i', $courierId);
                $stmt0->execute();
                $row = $stmt0->get_result()->fetch_assoc();
                $stmt0->close();
            }
            $old = (int)($row['is_available'] ?? 0);
            $new = $old ? 0 : 1;
            $uid = (int)($row['user_id'] ?? 0);

            $stmt = $conn->prepare('UPDATE ' . vgo_ident2($tableCouriers) . ' SET is_available = ? WHERE courier_id = ?');
            if ($stmt) {
                $stmt->bind_param('ii', $new, $courierId);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    vgo_audit_log($conn, 'admin.courier.toggle_available', 'Courier', $courierId, [
                        'user_id' => $uid,
                        'old_is_available' => $old,
                        'new_is_available' => $new,
                    ]);
                    $flashType2 = 'success';
                    $flash2 = 'Kurye müsaitlik durumu güncellendi.';
                } else {
                    $flashType2 = 'danger';
                    $flash2 = 'Kurye müsaitlik durumu güncellenemedi.';
                }
            }
        }
    }

    $_SESSION['flash_msg'] = $flash2;
    $_SESSION['flash_type'] = $flashType2;
    $qs = 'tab=' . urlencode($tabPost);
    if ($qPost !== '') $qs .= '&q=' . urlencode($qPost);
    header('Location: admin_partners.php?' . $qs);
    exit;
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$merchants = [];
$couriers = [];

if ($tab === 'merchants') {
    $where = [];
    $params = [];
    $types = '';
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(m.store_name LIKE ? OR m.city LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
        $params = [$like, $like, $like, $like, $like];
        $types = 'sssss';
    }

        $sql = 'SELECT m.merchant_id, m.user_id, m.store_name, m.city, m.is_active, m.rating_avg, m.zone_id, u.full_name, u.email, u.phone, ' . ($usersHasStatus ? 'u.status' : "'active' as status") . "\n"
            . 'FROM ' . vgo_ident2($tableMerchants) . ' m JOIN ' . vgo_ident2($tableUsers) . ' u ON m.user_id = u.user_id';
    if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY m.merchant_id DESC LIMIT 100';

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $merchants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmt->close();
    }
} else {
    $where = [];
    $params = [];
    $types = '';
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR c.vehicle_plate LIKE ? OR c.license_number LIKE ?)';
        $params = [$like, $like, $like, $like, $like];
        $types = 'sssss';
    }

        $sql = 'SELECT c.courier_id, c.user_id, c.vehicle_type, c.vehicle_plate, c.license_number, c.is_available, c.rating_avg, c.total_deliveries, u.full_name, u.email, u.phone, ' . ($usersHasStatus ? 'u.status' : "'active' as status") . "\n"
            . 'FROM ' . vgo_ident2($tableCouriers) . ' c JOIN ' . vgo_ident2($tableUsers) . ' u ON c.user_id = u.user_id';
    if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY c.courier_id DESC LIMIT 100';

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $couriers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmt->close();
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>VGO - Restoran & Kurye Yönetimi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Restoran & Kurye Yönetimi</h4>
        <a class="btn btn-sm btn-outline-secondary" href="admin_dashboard.php">Admin Panel</a>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>"><?php echo htmlspecialchars($flash); ?></div>
    <?php endif; ?>

    <ul class="nav nav-pills mb-3">
        <li class="nav-item"><a class="nav-link <?php echo $tab==='merchants'?'active':''; ?>" href="admin_partners.php?tab=merchants">Restoranlar</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $tab==='couriers'?'active':''; ?>" href="admin_partners.php?tab=couriers">Kuryeler</a></li>
    </ul>

    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2" method="get">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                <div class="col-md-10">
                    <label class="form-label">Ara</label>
                    <input class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="İsim / Email / Telefon / Plaka / Şehir">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100" type="submit">Ara</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($tab === 'merchants'): ?>
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Mağaza</th>
                                <th>Şehir</th>
                                <th>Merchant Aktif</th>
                                <th>User Status</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($merchants)): ?>
                            <tr><td colspan="6" class="text-muted">Kayıt yok.</td></tr>
                        <?php else: foreach ($merchants as $m): ?>
                            <?php
                                $mid = (int)($m['merchant_id'] ?? 0);
                                $isActive = ((int)($m['is_active'] ?? 0)) === 1;
                                $ust = (string)($m['status'] ?? '');
                            ?>
                            <tr>
                                <td><?php echo $mid; ?></td>
                                <td>
                                    <div><strong><?php echo htmlspecialchars((string)($m['store_name'] ?? '')); ?></strong></div>
                                    <div class="text-muted" style="font-size:12px;">User: #<?php echo (int)($m['user_id'] ?? 0); ?> <?php echo htmlspecialchars((string)($m['email'] ?? '')); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars((string)($m['city'] ?? '')); ?></td>
                                <td>
                                    <?php if ($merchantsHasActive): ?>
                                        <span class="badge bg-<?php echo $isActive ? 'success' : 'secondary'; ?>"><?php echo $isActive ? 'active' : 'inactive'; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-<?php echo (strtolower($ust)==='active') ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars($ust); ?></span></td>
                                <td>
                                    <?php
                                        $back = 'admin_partners.php?tab=merchants';
                                        if ($q !== '') $back .= '&q=' . urlencode($q);
                                    ?>
                                    <a class="btn btn-sm btn-outline-primary" href="admin_merchant_detail.php?merchant_id=<?php echo $mid; ?>&back=<?php echo urlencode($back); ?>">Detay</a>

                                    <?php if ($merchantsHasActive): ?>
                                        <form method="post" onsubmit="return confirm('Restoran aktifliğini değiştirmek istiyor musunuz?');" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_merchant_active">
                                            <input type="hidden" name="merchant_id" value="<?php echo $mid; ?>">
                                            <input type="hidden" name="tab" value="merchants">
                                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>">
                                            <button class="btn btn-sm btn-outline-<?php echo $isActive ? 'danger' : 'success'; ?>" type="submit"><?php echo $isActive ? 'Pasifleştir' : 'Aktifleştir'; ?></button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($usersHasStatus): ?>
                                        <?php $mUActive = (strtolower((string)$ust) === 'active'); ?>
                                        <form method="post" onsubmit="return confirm('Merchant kullanıcı durumunu değiştirmek istiyor musunuz?');" style="display:inline; margin-left:6px;">
                                            <input type="hidden" name="action" value="toggle_merchant_status">
                                            <input type="hidden" name="merchant_id" value="<?php echo $mid; ?>">
                                            <input type="hidden" name="tab" value="merchants">
                                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>">
                                            <button class="btn btn-sm btn-outline-<?php echo $mUActive ? 'danger' : 'success'; ?>" type="submit"><?php echo $mUActive ? 'User Pasif' : 'User Aktif'; ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Kurye</th>
                                <th>Araç</th>
                                <th>Available</th>
                                <th>User Status</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($couriers)): ?>
                            <tr><td colspan="6" class="text-muted">Kayıt yok.</td></tr>
                        <?php else: foreach ($couriers as $c): ?>
                            <?php
                                $cid = (int)($c['courier_id'] ?? 0);
                                $uid = (int)($c['user_id'] ?? 0);
                                $available = ((int)($c['is_available'] ?? 0)) === 1;
                                $ust = (string)($c['status'] ?? '');
                                $uActive = (strtolower($ust) === 'active');
                            ?>
                            <tr>
                                <td><?php echo $cid; ?></td>
                                <td>
                                    <div><strong><?php echo htmlspecialchars((string)($c['full_name'] ?? '')); ?></strong></div>
                                    <div class="text-muted" style="font-size:12px;">User: #<?php echo $uid; ?> <?php echo htmlspecialchars((string)($c['email'] ?? '')); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars(trim((string)($c['vehicle_type'] ?? '') . ' ' . (string)($c['vehicle_plate'] ?? ''))); ?></td>
                                <td><span class="badge bg-<?php echo $available ? 'success' : 'secondary'; ?>"><?php echo $available ? 'yes' : 'no'; ?></span></td>
                                <td><span class="badge bg-<?php echo $uActive ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars($ust); ?></span></td>
                                <td>
                                    <?php
                                        $back = 'admin_partners.php?tab=couriers';
                                        if ($q !== '') $back .= '&q=' . urlencode($q);
                                    ?>
                                    <a class="btn btn-sm btn-outline-primary" href="admin_courier_detail.php?courier_id=<?php echo $cid; ?>&back=<?php echo urlencode($back); ?>">Detay</a>

                                    <?php if ($couriersHasAvailable): ?>
                                        <form method="post" onsubmit="return confirm('Kurye müsaitlik durumunu değiştirmek istiyor musunuz?');" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_courier_available">
                                            <input type="hidden" name="courier_id" value="<?php echo $cid; ?>">
                                            <input type="hidden" name="tab" value="couriers">
                                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>">
                                            <button class="btn btn-sm btn-outline-<?php echo $available ? 'danger' : 'success'; ?>" type="submit"><?php echo $available ? 'Available No' : 'Available Yes'; ?></button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($usersHasStatus): ?>
                                        <form method="post" onsubmit="return confirm('Kurye kullanıcı durumunu değiştirmek istiyor musunuz?');" style="display:inline; margin-left:6px;">
                                            <input type="hidden" name="action" value="toggle_courier_status">
                                            <input type="hidden" name="courier_user_id" value="<?php echo $uid; ?>">
                                            <input type="hidden" name="tab" value="couriers">
                                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>">
                                            <button class="btn btn-sm btn-outline-<?php echo $uActive ? 'danger' : 'success'; ?>" type="submit"><?php echo $uActive ? 'Pasifleştir' : 'Aktifleştir'; ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="text-muted mt-2" style="font-size:12px;">Not: Kurye aktif/pasif Users.status ile, müsaitlik (is_available) ayrı yönetilir.</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
