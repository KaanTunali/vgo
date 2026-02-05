<?php
// notifications_helper.php
// Usage: require_once 'notifications_helper.php';

function notify_user($conn, $user_id = null, $role_id = null, $order_id = null, $delivery_id = null, $type = '', $message = '') {
    // Insert into Notifications
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, role_id, order_id, delivery_id, type, message, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        return false;
    }
    if (!$stmt->bind_param("iiiiss", $user_id, $role_id, $order_id, $delivery_id, $type, $message)) {
        $stmt->close();
        return false;
    }
    $ok = $stmt->execute();
    $stmt->close();

    // Email stub / log for now
    $logLine = date('Y-m-d H:i:s') . " | user:" . ($user_id ?? 'NULL') . " | order:" . ($order_id ?? 'NULL') . " | delivery:" . ($delivery_id ?? 'NULL') . " | type:" . $type . " | " . $message . "\n";
    $logDir = __DIR__ . '/assets';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logDir . '/notification_emails.log', $logLine, FILE_APPEND | LOCK_EX);

    return $ok;
}
