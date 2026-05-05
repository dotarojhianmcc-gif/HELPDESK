<?php
// approve_file.php
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$data = json_decode(file_get_contents('php://input'), true);
$fileId = $data['file_id'] ?? null;
$status = normalizeFileStatus($data['status'] ?? null);
$reason = trim((string) ($data['reason'] ?? ''));

if (!$fileId || !in_array($status, ['approved', 'rejected', 'archived'], true)) {
    die(json_encode(['success' => false, 'message' => 'Invalid parameters']));
}

$fileStmt = $pdo->prepare("SELECT user_id, file_name, status FROM files WHERE id = ?");
$fileStmt->execute([$fileId]);
$file = $fileStmt->fetch();

if (!$file) {
    die(json_encode(['success' => false, 'message' => 'Document not found']));
}

$currentStatus = normalizeFileStatus($file['status'] ?? 'pending');
if ($status === 'archived' && !in_array($currentStatus, ['approved', 'rejected', 'archived'], true)) {
    die(json_encode(['success' => false, 'message' => 'Only approved or rejected documents can be archived']));
}
if ($status === 'approved' && $currentStatus !== 'archived') {
    // Allow re-approve only if currently archived (unarchive action)
    die(json_encode(['success' => false, 'message' => 'Use the Approve button for pending documents']));
}

$reasonToSave = $status === 'rejected'
    ? ($reason !== '' ? $reason : 'No reason provided')
    : null;

$stmt = $pdo->prepare("
    UPDATE files 
    SET status = ?, approved_at = NOW(), approved_by = ?,
        rejection_reason = CASE WHEN ? = 'archived' THEN rejection_reason ELSE ? END
    WHERE id = ?
");

if ($stmt->execute([$status, $_SESSION['user_id'], $status, $reasonToSave, $fileId])) {
    $message = null;

    if ($status === 'approved') {
        $message = 'Your document "' . $file['file_name'] . '" has been approved! ✅';
    } elseif ($status === 'rejected') {
        $message = 'Your document "' . $file['file_name'] . '" has been rejected. Reason: ' . $reasonToSave . ' ❌';
    }

    if ($message !== null && (int) $file['user_id'] !== (int) $_SESSION['user_id']) {
        $notifStmt = $pdo->prepare("
            INSERT INTO notifications (user_id, message, type) 
            VALUES (?, ?, ?)
        ");
        $notifStmt->execute([$file['user_id'], $message, 'approval']);
    }

    $logStmt = $pdo->prepare("
        INSERT INTO activity_log (user_id, action, details) 
        VALUES (?, ?, ?)
    ");
    $logStmt->execute([$_SESSION['user_id'], 'document_' . $status, 'Document ID: ' . $fileId]);

    $responseMessage = $status === 'archived'
        ? 'Document archived successfully'
        : 'Document updated to ' . ucfirst($status);

    echo json_encode(['success' => true, 'message' => $responseMessage]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error updating document']);
}
?>