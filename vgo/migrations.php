<?php
session_start();
require_once __DIR__ . '/db.php';

// Admin-only (and superadmin=6) screen to manually trigger idempotent migrations.
if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 6], true)) {
	header('Location: login.php');
	exit;
}

@include_once __DIR__ . '/migrations_runner.php';

$dbName = '';
try {
	$res = $conn->query('SELECT DATABASE() db');
	$row = $res ? $res->fetch_assoc() : null;
	$dbName = (string)($row['db'] ?? '');
} catch (Throwable $e) {
	$dbName = '';
}
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<title>VGO - Migrations</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="container mt-4">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<h4>Migrations</h4>
		<a class="btn btn-sm btn-outline-secondary" href="admin_dashboard.php">Admin Panel</a>
	</div>

	<div class="alert alert-success">
		<div><strong>OK:</strong> `migrations_runner.php` çalıştırıldı (idempotent).</div>
		<?php if ($dbName !== ''): ?><div class="text-muted" style="font-size:12px;">DB: <?php echo htmlspecialchars($dbName); ?></div><?php endif; ?>
		<div class="text-muted" style="font-size:12px;">Tarih: <?php echo htmlspecialchars(date('Y-m-d H:i:s')); ?></div>
	</div>

	<div class="text-muted" style="font-size:12px;">
		Not: Bu sayfa sadece admin içindir ve her yenilemede migrations runner tekrar çalışır.
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
