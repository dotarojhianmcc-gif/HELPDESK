<?php
// delete_file.php
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

if (isset($_SESSION['role']) && $_SESSION['role'] === 'viewer') {
    die(json_encode(['success' => false, 'message' => 'Unauthorized: viewer account is view/download only']));
}

$data = json_decode(file_get_contents('php://input'), true);
$fileId = $data['file_id'] ?? null;

if (!$fileId) {
    die(json_encode(['success' => false, 'message' => 'Invalid document ID']));
}

$fileStmt = $pdo->prepare("SELECT file_path, user_id, status FROM files WHERE id = ?");
$fileStmt->execute([$fileId]);
$file = $fileStmt->fetch();

if (!$file) {
    die(json_encode(['success' => false, 'message' => 'Document not found']));
}

$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$isOwner = (int) $file['user_id'] === (int) $_SESSION['user_id'];
$isApproved = normalizeFileStatus((string) ($file['status'] ?? '')) === 'approved';

if (!$isAdmin && !$isOwner) {
    die(json_encode(['success' => false, 'message' => 'Unauthorized: you can only delete your own files']));
}

if ($isAdmin && !$isApproved) {
    die(json_encode(['success' => false, 'message' => 'Admin can only delete approved documents']));
}

$filePath = resolveStoredFilePath($file['file_path'] ?? '');
if ($filePath && file_exists($filePath)) {
    unlink($filePath);
}

$stmt = $pdo->prepare("DELETE FROM files WHERE id = ?");
if ($stmt->execute([$fileId])) {
    echo json_encode(['success' => true, 'message' => 'Document deleted']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error deleting document']);
}
?>