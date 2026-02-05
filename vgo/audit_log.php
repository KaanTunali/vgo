<?php
session_start();
require_once 'db.php';
@include_once __DIR__ . '/migrations_runner.php';
require_once __DIR__ . '/audit_helper.php';

if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 6], true)) {
    header('Location: login.php');
    exit;
}

$actionFilter = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
$actorFilter = isset($_GET['actor']) ? (int)$_GET['actor'] : 0;
$entityFilter = isset($_GET['entity']) ? trim((string)$_GET['entity']) : '';
$limit = 200;

$where = [];
$params = [];
$types = '';

if ($actionFilter !== '') {
    $where[] = 'action = ?';
    $params[] = $actionFilter;
    $types .= 's';
}
if ($actorFilter > 0) {
    $where[] = 'actor_user_id = ?';
    $params[] = $actorFilter;
    $types .= 'i';
}
if ($entityFilter !== '') {
    $where[] = 'entity_type = ?';
    $params[] = $entityFilter;
    $types .= 's';
}

$sql = "SELECT log_id, actor_user_id, actor_role_id, action, entity_type, entity_id, details_json, ip_address, created_at
        FROM AuditLogs";
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY log_id DESC LIMIT ?';
$params[] = $limit;
$types .= 'i';

$rows = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
    $stmt->close();
}

// actions/entities for dropdowns
$actions = [];
$entities = [];
try {
    $res = $conn->query("SELECT action, COUNT(*) cnt FROM AuditLogs GROUP BY action ORDER BY cnt DESC, action ASC LIMIT 50");
    if ($res) $actions = $res->fetch_all(MYSQLI_ASSOC) ?? [];
    $res2 = $conn->query("SELECT entity_type, COUNT(*) cnt FROM AuditLogs WHERE entity_type IS NOT NULL AND entity_type <> '' GROUP BY entity_type ORDER BY cnt DESC, entity_type ASC LIMIT 50");
    if ($res2) $entities = $res2->fetch_all(MYSQLI_ASSOC) ?? [];
} catch (Throwable $e) {
}

function vgo_role_label_short($rid): string {
    $rid = (int)$rid;
    if ($rid === 1) return 'Admin';
    if ($rid === 2) return 'Operator';
    if ($rid === 3) return 'Courier';
    if ($rid === 4) return 'Customer';
    if ($rid === 5) return 'Merchant';
    if ($rid === 6) return 'Manager';
    return 'User';
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>VGO - Audit Log</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Audit Log</h4>
        <a class="btn btn-sm btn-outline-secondary" href="admin_dashboard.php">Admin Panel</a>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-2" method="get">
                <div class="col-md-4">
                    <label class="form-label">Action</label>
                    <select class="form-select" name="action">
                        <option value="">(Tümü)</option>
                        <?php foreach ($actions as $a): $v = (string)($a['action'] ?? ''); if ($v==='') continue; ?>
                            <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($v === $actionFilter) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Entity</label>
                    <select class="form-select" name="entity">
                        <option value="">(Tümü)</option>
                        <?php foreach ($entities as $e): $v = (string)($e['entity_type'] ?? ''); if ($v==='') continue; ?>
                            <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($v === $entityFilter) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Actor User ID</label>
                    <input class="form-control" name="actor" value="<?php echo $actorFilter > 0 ? (int)$actorFilter : ''; ?>" placeholder="örn: 1">
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
                            <th>Zaman</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>IP</th>
                            <th>Detay</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="6" class="text-muted">Kayıt yok.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <?php
                            $detail = (string)($r['details_json'] ?? '');
                            $detailShort = $detail;
                            if (strlen($detailShort) > 160) $detailShort = substr($detailShort, 0, 160) . '…';
                            $actorId = (int)($r['actor_user_id'] ?? 0);
                            $actorRole = vgo_role_label_short($r['actor_role_id'] ?? 0);
                            $entity = trim((string)($r['entity_type'] ?? ''));
                            $entityId = trim((string)($r['entity_id'] ?? ''));
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($r['created_at'] ?? '')); ?></td>
                            <td><?php echo $actorId > 0 ? ('#' . $actorId . ' (' . htmlspecialchars($actorRole) . ')') : htmlspecialchars($actorRole); ?></td>
                            <td><code><?php echo htmlspecialchars((string)($r['action'] ?? '')); ?></code></td>
                            <td><?php echo htmlspecialchars($entity . ($entityId !== '' ? (':' . $entityId) : '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($r['ip_address'] ?? '')); ?></td>
                            <td>
                                <?php if ($detail !== ''): ?>
                                    <details>
                                        <summary class="text-muted"><?php echo htmlspecialchars($detailShort); ?></summary>
                                        <pre class="mb-0" style="white-space:pre-wrap;"><?php echo htmlspecialchars($detail); ?></pre>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
