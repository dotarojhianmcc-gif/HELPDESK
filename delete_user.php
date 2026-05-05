<?php
// delete_user.php
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$data = json_decode(file_get_contents('php://input'), true);
$userId = $data['user_id'] ?? null;

if (!$userId) {
    die(json_encode(['success' => false, 'message' => 'Invalid user ID']));
}

if ($userId == $_SESSION['user_id']) {
    die(json_encode(['success' => false, 'message' => 'Cannot delete yourself']));
}

$stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
if ($stmt->execute([$userId])) {
    echo json_encode(['success' => true, 'message' => 'User deleted']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error deleting user']);
}
?>