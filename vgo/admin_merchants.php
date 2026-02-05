<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 6], true)) {
    header('Location: login.php');
    exit;
}
header('Location: admin_partners.php?tab=merchants');
exit;
