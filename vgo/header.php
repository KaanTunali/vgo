<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$role = $_SESSION['role_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? '';
// Use PNG if exists, otherwise fall back to bundled SVG placeholder
$logo = (file_exists(__DIR__ . '/assets/logo.png')) ? 'assets/logo.png' : 'assets/logo.svg';

// Brand (VGO logo) target per role
$homeHref = 'login.php';
if ($role == 4) {
  $homeHref = 'dashboard.php';
} elseif ($role == 5) {
  $homeHref = 'merchant_dashboard.php';
} elseif ($role == 3) {
  $homeHref = 'courier_dashboard.php';
} elseif ($role == 2) {
  $homeHref = 'operator_dashboard.php';
} elseif ($role == 1 || $role == 6) {
  $homeHref = 'admin_dashboard.php';
}
// notification count
$notif_count = 0;
if (!empty($_SESSION['user_id'])) {
    @include_once __DIR__ . '/db.php';
  // run idempotent dev migrations if available (no output)
  @include_once __DIR__ . '/migrations_runner.php';
    // Safely attempt to count unread notifications. If the Notifications table is missing (dev DB), fail gracefully.
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS CNT FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $notif_count = (int)($row['CNT'] ?? 0);
        $stmt->close();
    } catch (Throwable $e) {
        // Table might not exist or another DB error occurred; ignore for now in dev
        $notif_count = 0;
    }
}
?>
<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom">
  <div class="container">
    <a class="navbar-brand" href="<?php echo htmlspecialchars($homeHref); ?>" style="display:flex;align-items:center;gap:8px;">
      <?php if ($logo): ?>
        <img src="<?php echo htmlspecialchars($logo); ?>" alt="VGO" style="height:44px;object-fit:contain;border-radius:6px;" loading="lazy">
      <?php endif; ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav ms-auto">
        <?php if (!$role): ?>
            <li class="nav-item"><a class="nav-link" href="login.php">Giriş</a></li>
            <li class="nav-item"><a class="nav-link" href="register.php">Kayıt</a></li>
        <?php else: ?>
            <li class="nav-item"><a class="nav-link position-relative" href="notifications.php">🔔
                <?php if ($notif_count > 0): ?><span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill"><?php echo $notif_count; ?></span><?php endif; ?>
            </a></li>

            <?php if ($role == 4): ?>
                <li class="nav-item"><a class="nav-link" href="dashboard.php">Anasayfa</a></li>
                <li class="nav-item"><a class="nav-link" href="orders.php">Siparişlerim</a></li>
              <li class="nav-item"><a class="nav-link" href="addresses.php">Adreslerim</a></li>
                <li class="nav-item"><a class="nav-link" href="favorites.php">💝 Favorilerim</a></li>
                <li class="nav-item"><a class="nav-link" href="cart.php">Sepetim</a></li>
                <li class="nav-item"><a class="nav-link" href="profile.php">Hesabım</a></li>
                <li class="nav-item"><a class="nav-link" href="logout.php">Çıkış Yap</a></li>
            <?php elseif ($role == 5): ?>
                <li class="nav-item"><a class="nav-link" href="merchant_dashboard.php">Anasayfa</a></li>
                <li class="nav-item"><a class="nav-link" href="merchant_orders.php">Siparişler</a></li>
                <li class="nav-item"><a class="nav-link" href="merchant_products.php">Ürünler</a></li>
                <li class="nav-item"><a class="nav-link" href="merchant_profile.php">Mağaza</a></li>
                <li class="nav-item"><a class="nav-link" href="logout.php">Çıkış</a></li>
            <?php elseif ($role == 3): ?>
                <li class="nav-item"><a class="nav-link" href="courier_dashboard.php">Anasayfa</a></li>
                <li class="nav-item"><a class="nav-link" href="courier_profile.php">Profil</a></li>
                <li class="nav-item"><a class="nav-link" href="logout.php">Çıkış</a></li>
            <?php elseif ($role == 2): ?>
                <li class="nav-item"><a class="nav-link" href="operator_dashboard.php">Anasayfa</a></li>
              <li class="nav-item"><a class="nav-link" href="operator_dashboard.php">Destek Talepleri</a></li>
                <li class="nav-item"><a class="nav-link" href="logout.php">Çıkış</a></li>
            <?php elseif ($role == 1 || $role == 6): ?>
              <li class="nav-item"><a class="nav-link" href="admin_dashboard.php">Admin</a></li>
              <li class="nav-item"><a class="nav-link" href="admin_users.php">Kullanıcılar</a></li>
              <li class="nav-item"><a class="nav-link" href="admin_partners.php">Restoran & Kurye</a></li>
              <li class="nav-item"><a class="nav-link" href="system_health.php">Sistem Sağlığı</a></li>
              <li class="nav-item"><a class="nav-link" href="audit_log.php">Audit Log</a></li>
              <li class="nav-item"><a class="nav-link" href="support_manager.php">Yönetim Paneli</a></li>
                <li class="nav-item"><a class="nav-link" href="logout.php">Çıkış</a></li>
            <?php else: ?>
                <li class="nav-item"><a class="nav-link" href="logout.php">Çıkış</a></li>
            <?php endif; ?>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<?php if (!empty($_SESSION['user_id']) && file_exists(__DIR__ . '/support_widget.php')) { include_once __DIR__ . '/support_widget.php'; } ?>
