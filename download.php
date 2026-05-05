<?php
// download.php
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Unauthorized');
}

$fileId = isset($_GET['file_id']) ? (int) $_GET['file_id'] : 0;
if (!$fileId) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid file ID');
}

$stmt = $pdo->prepare("
    SELECT f.user_id, f.file_name, f.file_path, f.status, f.rejection_reason, u.role AS owner_role
    FROM files f
    JOIN users u ON f.user_id = u.id
    WHERE f.id = ?
");
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    header('HTTP/1.1 404 Not Found');
    exit('File not found');
}

$isOwnFile = (int) $file['user_id'] === (int) $_SESSION['user_id'];
$normalizedStatus = normalizeFileStatus($file['status'] ?? '');
$hasRejectionFeedback = $normalizedStatus === 'rejected'
    || trim((string) ($file['rejection_reason'] ?? '')) !== '';
$isSharedApprovedDocument = in_array($_SESSION['role'] ?? '', ['user', 'viewer'], true)
    && !$isOwnFile
    && in_array($normalizedStatus, ['approved', 'archived'], true)
    && !$hasRejectionFeedback;

if ($_SESSION['role'] !== 'admin' && !$isOwnFile && !$isSharedApprovedDocument) {
    header('HTTP/1.1 403 Forbidden');
    exit('Unauthorized access');
}

$uploadsDir = realpath(getUploadsDirectory());
$filePath = resolveStoredFilePath($file['file_path'] ?? '');

if (!$filePath || strpos($filePath, $uploadsDir) !== 0 || !file_exists($filePath) || !is_file($filePath)) {
    header('HTTP/1.1 404 Not Found');
    exit('File not found on server. Please re-upload or resubmit this document.');
}

$downloadName = basename($file['file_name']);
$extension = strtolower(pathinfo($downloadName, PATHINFO_EXTENSION));
$isViewMode = isset($_GET['view']) && $_GET['view'] === '1';

$mimeTypes = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'txt' => 'text/plain; charset=utf-8',
    'csv' => 'text/csv; charset=utf-8',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'zip' => 'application/zip'
];

$contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
$contentDisposition = $isViewMode ? 'inline' : 'attachment';

header('Content-Description: File Transfer');
header('Content-Type: ' . $contentType);
header('Content-Disposition: ' . $contentDisposition . '; filename="' . $downloadName . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
?>