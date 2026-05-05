<?php
// mark_notifications_read.php
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$notificationId = isset($payload['notification_id']) ? (int) $payload['notification_id'] : 0;

if ($notificationId > 0) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $result = $stmt->execute([$notificationId, $userId]);
} else {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $result = $stmt->execute([$userId]);
}

if ($result) {
    echo json_encode(['success' => true, 'message' => 'Notifications marked as read']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update notifications']);
}
?>