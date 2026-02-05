<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['unread' => 0]);
    exit;
}

include '../db.php';
include '../classes/SupportTicket.php';

$supportTicket = new SupportTicket($conn);
$unread = $supportTicket->getUnreadMessageCount($_SESSION['user_id']);

echo json_encode(['unread' => $unread]);
?>
