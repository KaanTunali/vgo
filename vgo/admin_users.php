<?php
session_start();
require_once 'db.php';
@include_once __DIR__ . '/migrations_runner.php';
require_once __DIR__ . '/audit_helper.php';

if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 6], true)) {
    header('Location: login.php');
    exit;
}

$actorUserId = (int)($_SESSION['user_id'] ?? 0);

function vgo_column_exists(mysqli $conn, string $table, string $col): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

$usersHasStatus = vgo_column_exists($conn, 'Users', 'status');

// roles list
$roles = [];
try {
    $res = $conn->query('SELECT role_id, role_name FROM Roles ORDER BY role_id ASC');
    if ($res) $roles = $res->fetch_all(MYSQLI_ASSOC) ?? [];
} catch (Throwable $e) {}
$roleIds = [];
foreach ($roles as $r) {
    $roleIds[(int)($r['role_id'] ?? 0)] = (string)($r['role_name'] ?? '');
}

$flash = '';
$flashType = 'success';

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    $targetUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

    if ($targetUserId <= 0) {
        $flashType = 'danger';
        $flash = 'Geçersiz kullanıcı.';
    } elseif ($targetUserId === $actorUserId) {
        $flashType = 'warning';
        $flash = 'Kendi hesabınız üzerinde bu işlem kapalı.';
    } elseif ($action === 'set_role') {
        $newRoleId = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
        if ($newRoleId <= 0 || !isset($roleIds[$newRoleId])) {
            $flashType = 'danger';
            $flash = 'Geçersiz rol.';
        } else {
            // fetch old role for audit
            $oldRoleId = 0;
            $stmt0 = $conn->prepare('SELECT role_id FROM Users WHERE user_id = ? LIMIT 1');
            if ($stmt0) {
                $stmt0->bind_param('i', $targetUserId);
                $stmt0->execute();
                $row0 = $stmt0->get_result()->fetch_assoc() ?? [];
                $oldRoleId = (int)($row0['role_id'] ?? 0);
                $stmt0->close();
            }

            $stmt = $conn->prepare('UPDATE Users SET role_id = ? WHERE user_id = ?');
            if ($stmt) {
                $stmt->bind_param('ii', $newRoleId, $targetUserId);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    // Ensure profile rows for admin/operator so panels work.
                    if ($newRoleId === 1 && vgo_column_exists($conn, 'Admins', 'user_id')) {
                        $ins = $conn->prepare('INSERT IGNORE INTO Admins (user_id, full_name, email, access_level) SELECT user_id, full_name, email, 1 FROM Users WHERE user_id = ? LIMIT 1');
                        if ($ins) {
                            $ins->bind_param('i', $targetUserId);
                            $ins->execute();
                            $ins->close();
                        }
                    }
                    if ($newRoleId === 2 && vgo_column_exists($conn, 'Operators', 'user_id')) {
                        $ins = $conn->prepare('INSERT IGNORE INTO Operators (user_id) VALUES (?)');
                        if ($ins) {
                            $ins->bind_param('i', $targetUserId);
                            $ins->execute();
                            $ins->close();
                        }
                        if (vgo_column_exists($conn, 'OperatorStatus', 'operator_id')) {
                            $ins2 = $conn->prepare('INSERT IGNORE INTO OperatorStatus (operator_id, is_online, is_available, current_tickets) VALUES (?, 0, 1, 0)');
                            if ($ins2) {
                                $ins2->bind_param('i', $targetUserId);
                                $ins2->execute();
                                $ins2->close();
                            }
                        }
                    }

                    vgo_audit_log($conn, 'admin.user.set_role', 'User', $targetUserId, [
                        'old_role_id' => $oldRoleId,
                        'new_role_id' => $newRoleId,
                    ]);
                    $flashType = 'success';
                    $flash = 'Rol güncellendi.';
                } else {
                    $flashType = 'danger';
                    $flash = 'Rol güncellenemedi.';
                }
            }
        }
    } elseif ($action === 'toggle_status') {
        if (!$usersHasStatus) {
            $flashType = 'warning';
            $flash = 'Users.status kolonu yok; bu işlem kapalı.';
        } else {
            $oldStatus = '';
            $stmt0 = $conn->prepare('SELECT status FROM Users WHERE user_id = ? LIMIT 1');
            if ($stmt0) {
                $stmt0->bind_param('i', $targetUserId);
                $stmt0->execute();
                $row0 = $stmt0->get_result()->fetch_assoc() ?? [];
                $oldStatus = (string)($row0['status'] ?? '');
                $stmt0->close();
            }
            $newStatus = (strtolower($oldStatus) === 'active') ? 'inactive' : 'active';

            $stmt = $conn->prepare('UPDATE Users SET status = ? WHERE user_id = ?');
            if ($stmt) {
                $stmt->bind_param('si', $newStatus, $targetUserId);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    vgo_audit_log($conn, 'admin.user.set_status', 'User', $targetUserId, [
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                    ]);
                    $flashType = 'success';
                    $flash = 'Durum güncellendi.';
                } else {
                    $flashType = 'danger';
                    $flash = 'Durum güncellenemedi.';
                }
            }
        }
    }
}

// Filters
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$roleFilter = isset($_GET['role']) ? (int)$_GET['role'] : 0;
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';

$where = [];
$params = [];
$types = '';

if ($q !== '') {
    $like = '%' . $q . '%';
    if (ctype_digit($q)) {
        $where[] = '(u.user_id = ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
        $params[] = (int)$q;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'isss';
    } else {
        $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }
}
if ($roleFilter > 0) {
    $where[] = 'u.role_id = ?';
    $params[] = $roleFilter;
    $types .= 'i';
}
if ($usersHasStatus && $statusFilter !== '') {
    $where[] = 'u.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

$sql = 'SELECT u.user_id, u.full_name, u.email, u.phone, u.role_id, ' . ($usersHasStatus ? 'u.status,' : "'active' as status,") . ' u.created_at, r.role_name '
     . 'FROM Users u LEFT JOIN Roles r ON u.role_id = r.role_id';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY u.created_at DESC, u.user_id DESC LIMIT 100';

$users = [];
$usedProcedure = false;
if ($usersHasStatus) {
    $limit = 100;
    $qParam = $q;
    $roleParam = $roleFilter;
    $statusParam = $statusFilter;

    $stmt = $conn->prepare('CALL AdminUsersSearch(?, ?, ?, ?)');
    if ($stmt) {
        $stmt->bind_param('sisi', $qParam, $roleParam, $statusParam, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $users = $res ? ($res->fetch_all(MYSQLI_ASSOC) ?? []) : [];
        $stmt->close();

        // Clear any remaining results from CALL
        while ($conn->more_results() && $conn->next_result()) {
            $r = $conn->store_result();
            if ($r) $r->free();
        }

        $usedProcedure = true;
    }
}

if (!$usedProcedure) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        $stmt->close();
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>VGO - Kullanıcılar</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Kullanıcılar & Roller</h4>
        <a class="btn btn-sm btn-outline-secondary" href="admin_dashboard.php">Admin Panel</a>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>"><?php echo htmlspecialchars($flash); ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2" method="get">
                <div class="col-md-5">
                    <label class="form-label">Ara</label>
                    <input class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="ID / Ad / Email / Telefon">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Rol</label>
                    <select class="form-select" name="role">
                        <option value="0">(Tümü)</option>
                        <?php foreach ($roles as $r): $rid = (int)($r['role_id'] ?? 0); $rn = (string)($r['role_name'] ?? ''); if ($rid<=0) continue; ?>
                            <option value="<?php echo $rid; ?>" <?php echo ($rid === $roleFilter) ? 'selected' : ''; ?>><?php echo htmlspecialchars($rn); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Durum</label>
                    <select class="form-select" name="status" <?php echo $usersHasStatus ? '' : 'disabled'; ?> >
                        <option value="">(Tümü)</option>
                        <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>active</option>
                        <option value="inactive" <?php echo ($statusFilter === 'inactive') ? 'selected' : ''; ?>>inactive</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100" type="submit">Filtrele</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Ad</th>
                            <th>Email</th>
                            <th>Telefon</th>
                            <th>Rol</th>
                            <th>Durum</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="7" class="text-muted">Kullanıcı bulunamadı.</td></tr>
                    <?php else: foreach ($users as $u): ?>
                        <?php
                            $uid = (int)($u['user_id'] ?? 0);
                            $rid = (int)($u['role_id'] ?? 0);
                            $status = (string)($u['status'] ?? '');
                            $isActive = (strtolower($status) === 'active');
                        ?>
                        <tr>
                            <td><?php echo $uid; ?></td>
                            <td><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($u['email'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($u['phone'] ?? '')); ?></td>
                            <td>
                                <form method="post" class="d-flex gap-2" style="min-width:240px;">
                                    <input type="hidden" name="action" value="set_role">
                                    <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                                    <select class="form-select form-select-sm" name="role_id">
                                        <?php foreach ($roles as $r): $rrid = (int)($r['role_id'] ?? 0); $rn = (string)($r['role_name'] ?? ''); if ($rrid<=0) continue; ?>
                                            <option value="<?php echo $rrid; ?>" <?php echo ($rrid === $rid) ? 'selected' : ''; ?>><?php echo htmlspecialchars($rn); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-sm btn-outline-primary" type="submit">Kaydet</button>
                                </form>
                            </td>
                            <td>
                                <?php if ($usersHasStatus): ?>
                                    <span class="badge bg-<?php echo $isActive ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars($status); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($usersHasStatus): ?>
                                    <form method="post" onsubmit="return confirm('Durumu değiştirmek istiyor musunuz?');" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                                        <button class="btn btn-sm btn-outline-<?php echo $isActive ? 'danger' : 'success'; ?>" type="submit">
                                            <?php echo $isActive ? 'Pasifleştir' : 'Aktifleştir'; ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-muted" style="font-size:12px;">Not: Rolü Admin/Operator yaptığınızda gerekli tablolar (Admins/Operators/OperatorStatus) otomatik tamamlanır.</div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
