<?php
session_start();
require_once 'db.php';
@include_once __DIR__ . '/migrations_runner.php';

if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 6], true)) {
    header('Location: login.php');
    exit;
}

function vgo_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function vgo_count(mysqli $conn, string $table): ?int {
    if (!vgo_table_exists($conn, $table)) return null;
    $res = $conn->query('SELECT COUNT(*) c FROM `' . $conn->real_escape_string($table) . '`');
    if (!$res) return null;
    $row = $res->fetch_assoc();
    return isset($row['c']) ? (int)$row['c'] : null;
}

$checks = [];

// DB basic check
$dbOk = false;
$dbName = '';
try {
    $res = $conn->query('SELECT DATABASE() as dbname');
    $row = $res ? $res->fetch_assoc() : null;
    $dbName = (string)($row['dbname'] ?? '');
    $dbOk = true;
} catch (Throwable $e) {
    $dbOk = false;
}

$tables = [
    'Users', 'Roles', 'Admins', 'Operators',
    'Orders', 'Deliveries', 'Merchants', 'Couriers', 'Customers',
    'SupportTickets', 'SupportMessages', 'SupportCategories',
    'Notifications', 'Coupons', 'Carts', 'Cart_Items',
    'AuditLogs'
];

$tableRows = [];
foreach ($tables as $t) {
    $exists = vgo_table_exists($conn, $t);
    $cnt = $exists ? vgo_count($conn, $t) : null;
    $tableRows[] = ['name' => $t, 'exists' => $exists, 'count' => $cnt];
}

// Audit last event
$lastAuditAt = '';
try {
    if (vgo_table_exists($conn, 'AuditLogs')) {
        $res = $conn->query('SELECT created_at FROM AuditLogs ORDER BY log_id DESC LIMIT 1');
        $row = $res ? $res->fetch_assoc() : null;
        $lastAuditAt = (string)($row['created_at'] ?? '');
    }
} catch (Throwable $e) {}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>VGO - Sistem Sağlığı</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<?php include 'header.php'; ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Sistem Sağlığı</h4>
        <div class="d-flex gap-2">
            <!-- migrations UI intentionally disabled -->
            <a class="btn btn-sm btn-outline-secondary" href="system_check.php" target="_blank" rel="noopener">System Check</a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h6>Genel</h6>
                    <div class="mb-1"><strong>DB:</strong> <?php echo $dbOk ? '<span class="text-success">OK</span>' : '<span class="text-danger">HATA</span>'; ?></div>
                    <div class="mb-1"><strong>Database:</strong> <?php echo htmlspecialchars($dbName); ?></div>
                    <div class="mb-1"><strong>PHP:</strong> <?php echo htmlspecialchars(PHP_VERSION); ?></div>
                    <div class="mb-1"><strong>Server Time:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i:s')); ?></div>
                    <div class="mb-1"><strong>Son Audit:</strong> <?php echo htmlspecialchars($lastAuditAt !== '' ? $lastAuditAt : '-'); ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h6>Hızlı Özet</h6>
                    <div class="row">
                        <div class="col-6"><div class="text-muted">Users</div><div class="fs-5"><?php echo (int)(vgo_count($conn, 'Users') ?? 0); ?></div></div>
                        <div class="col-6"><div class="text-muted">Orders</div><div class="fs-5"><?php echo (int)(vgo_count($conn, 'Orders') ?? 0); ?></div></div>
                        <div class="col-6 mt-2"><div class="text-muted">SupportTickets</div><div class="fs-5"><?php echo (int)(vgo_count($conn, 'SupportTickets') ?? 0); ?></div></div>
                        <div class="col-6 mt-2"><div class="text-muted">AuditLogs</div><div class="fs-5"><?php echo (int)(vgo_count($conn, 'AuditLogs') ?? 0); ?></div></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h6>Tablolar</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Tablo</th>
                                    <th>Durum</th>
                                    <th>Satır</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tableRows as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['name']); ?></td>
                                        <td>
                                            <?php echo $r['exists'] ? '<span class="badge bg-success">VAR</span>' : '<span class="badge bg-danger">YOK</span>'; ?>
                                        </td>
                                        <td><?php echo $r['exists'] ? (int)($r['count'] ?? 0) : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted" style="font-size:12px;">Not: Şema düzeltmeleri sistem tarafından sessizce uygulanır.</div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
