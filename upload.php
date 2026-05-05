<?php
// upload.php
include 'db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ob_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['user', 'admin'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$response = ['success' => false, 'message' => 'Unknown error'];
$uploadDir = getUploadsDirectory();

$selectedTopic = trim($_POST['topic'] ?? '');
$resubmitFileId = (int) ($_POST['resubmit_file_id'] ?? 0);
$editFileId = (int) ($_POST['edit_file_id'] ?? 0);

if (!$selectedTopic) {
    $response = ['success' => false, 'message' => 'Please select a section before uploading.'];
    ob_end_clean();
    echo json_encode($response);
    exit;
}

$updateMode = $resubmitFileId > 0 ? 'resubmit' : ($editFileId > 0 ? 'edit' : '');
$updateTargetId = $resubmitFileId > 0 ? $resubmitFileId : $editFileId;
$updateTarget = null;
if ($updateTargetId > 0) {
    $updateStmt = $pdo->prepare("SELECT id, user_id, file_path, status, rejection_reason FROM files WHERE id = ? AND user_id = ?");
    $updateStmt->execute([$updateTargetId, $_SESSION['user_id']]);
    $updateTarget = $updateStmt->fetch(PDO::FETCH_ASSOC);

    $targetStatus = normalizeFileStatus($updateTarget['status'] ?? '');
    $hasFeedback = trim((string) ($updateTarget['rejection_reason'] ?? '')) !== '';
    $canResubmit = $updateTarget && $updateMode === 'resubmit'
        && $_SESSION['role'] !== 'admin'
        && ($targetStatus === 'rejected' || ($targetStatus === 'archived' && $hasFeedback));
    $canEdit = $updateTarget && $updateMode === 'edit'
        && ($targetStatus !== 'archived' || $_SESSION['role'] === 'admin');

    if (($updateMode === 'resubmit' && !$canResubmit) || ($updateMode === 'edit' && !$canEdit)) {
        $response = ['success' => false, 'message' => $updateMode === 'edit' ? 'Only your active file can be edited.' : 'Only your rejected file can be resubmitted.'];
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    if (count(array_filter($_FILES['files']['name'] ?? [])) !== 1) {
        $response = ['success' => false, 'message' => $updateMode === 'edit' ? 'Please attach exactly one replacement file when editing.' : 'Please attach exactly one corrected file when resubmitting.'];
        ob_end_clean();
        echo json_encode($response);
        exit;
    }
}

if (!empty($_FILES['files']['name'][0])) {
    $uploadedCount = 0;
    
    foreach ($_FILES['files']['name'] as $key => $fileName) {
        if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
            $fileSize = $_FILES['files']['size'][$key];
            $tmpName = $_FILES['files']['tmp_name'][$key];
            $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
            
            $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt', 'zip'];
            if (!in_array(strtolower($fileExtension), $allowedExt)) {
                continue;
            }

            if ($fileSize > 10 * 1024 * 1024) {
                continue;
            }

            $newFileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $fileName);
            $storedFilePath = 'uploads/' . $newFileName;
            $filePath = $uploadDir . DIRECTORY_SEPARATOR . $newFileName;

            if (move_uploaded_file($tmpName, $filePath)) {
                ensureCategoryColumn($pdo);
                $hasTopicColumn = tableColumnExists($pdo, 'files', 'category');
                $hasApprovalColumns = tableColumnExists($pdo, 'files', 'approved_at') && tableColumnExists($pdo, 'files', 'approved_by');
                $hasUploadedAtColumn = tableColumnExists($pdo, 'files', 'uploaded_at');
                $hasRejectionReasonColumn = tableColumnExists($pdo, 'files', 'rejection_reason');
                $isAdminUpload = $_SESSION['role'] === 'admin';
                $initialStatus = $isAdminUpload ? 'approved' : 'pending';

                if ($updateTarget) {
                    $updateFields = [
                        'file_name = ?',
                        'file_path = ?',
                        'file_size = ?',
                        'status = ?'
                    ];
                    $updateStatus = $isAdminUpload ? 'approved' : 'pending';
                    $updateData = [$fileName, $storedFilePath, $fileSize, $updateStatus];

                    if ($hasTopicColumn) {
                        $updateFields[] = 'category = ?';
                        $updateData[] = $selectedTopic;
                    }

                    if ($hasUploadedAtColumn) {
                        $updateFields[] = 'uploaded_at = NOW()';
                    }

                    if ($hasApprovalColumns) {
                        if ($isAdminUpload) {
                            $updateFields[] = 'approved_at = NOW()';
                            $updateFields[] = 'approved_by = ?';
                            $updateData[] = $_SESSION['user_id'];
                        } else {
                            $updateFields[] = 'approved_at = NULL';
                            $updateFields[] = 'approved_by = NULL';
                        }
                    }

                    if ($hasRejectionReasonColumn) {
                        $updateFields[] = 'rejection_reason = NULL';
                    }

                    $updateData[] = $updateTarget['id'];
                    $updateData[] = $_SESSION['user_id'];

                    $stmt = $pdo->prepare('UPDATE files SET ' . implode(', ', $updateFields) . ' WHERE id = ? AND user_id = ?');
                    $insertData = $updateData;
                } else {
                    if ($hasTopicColumn) {
                        if ($isAdminUpload && $hasApprovalColumns) {
                            $stmt = $pdo->prepare("
                                INSERT INTO files (user_id, file_name, file_path, file_size, category, status, approved_at, approved_by) 
                                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                            ");
                            $insertData = [$_SESSION['user_id'], $fileName, $storedFilePath, $fileSize, $selectedTopic, $initialStatus, $_SESSION['user_id']];
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO files (user_id, file_name, file_path, file_size, category, status) 
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $insertData = [$_SESSION['user_id'], $fileName, $storedFilePath, $fileSize, $selectedTopic, $initialStatus];
                        }
                    } else {
                        if ($isAdminUpload && $hasApprovalColumns) {
                            $stmt = $pdo->prepare("
                                INSERT INTO files (user_id, file_name, file_path, file_size, status, approved_at, approved_by) 
                                VALUES (?, ?, ?, ?, ?, NOW(), ?)
                            ");
                            $insertData = [$_SESSION['user_id'], $fileName, $storedFilePath, $fileSize, $initialStatus, $_SESSION['user_id']];
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO files (user_id, file_name, file_path, file_size, status) 
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $insertData = [$_SESSION['user_id'], $fileName, $storedFilePath, $fileSize, $initialStatus];
                        }
                    }
                }
                
                if ($stmt->execute($insertData)) {
                    if ($updateTarget) {
                        $oldPath = resolveStoredFilePath($updateTarget['file_path'] ?? '');
                        if ($oldPath && $oldPath !== $filePath && is_file($oldPath)) {
                            @unlink($oldPath);
                        }
                    }

                    $notifStmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, type) 
                        VALUES (?, ?, ?)
                    ");

                    if ($isAdminUpload) {
                        $userStmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('user', 'viewer') AND id <> ?");
                        $userStmt->execute([$_SESSION['user_id']]);
                        $adminDisplayName = trim((string) ($_SESSION['username'] ?? 'admin'));
                        $userMessage = $updateMode === 'edit'
                            ? "Admin {$adminDisplayName} updated '$fileName' in '$selectedTopic' in the Help Center."
                            : "Admin {$adminDisplayName} shared '$fileName' in '$selectedTopic' in the Help Center.";
                        while ($user = $userStmt->fetch(PDO::FETCH_ASSOC)) {
                            $notifStmt->execute([$user['id'], $userMessage, 'admin_update']);
                        }
                    } else {
                        $adminStmt = $pdo->prepare("SELECT id, username FROM users WHERE role = 'admin' AND id <> ?");
                        $adminStmt->execute([$_SESSION['user_id']]);
                        $uploaderName = trim((string) ($_SESSION['username'] ?? 'user'));
                        if ($updateMode === 'resubmit') {
                            $adminMessage = "{$uploaderName} resubmitted file '$fileName' in '$selectedTopic' for admin review.";
                        } elseif ($updateMode === 'edit') {
                            $adminMessage = "{$uploaderName} updated file '$fileName' in '$selectedTopic' for admin review.";
                        } else {
                            $adminMessage = "{$uploaderName} uploaded file '$fileName' in '$selectedTopic' for admin review.";
                        }
                        while ($admin = $adminStmt->fetch(PDO::FETCH_ASSOC)) {
                            $notifStmt->execute([$admin['id'], $adminMessage, 'upload']);
                        }
                    }

                    $uploadedCount++;
                    if ($updateTarget) {
                        $updateTarget = null;
                    }
                }
            }
        }
    }
    
    if ($uploadedCount > 0) {
        $actionLabel = $_SESSION['role'] === 'admin'
            ? ($updateMode === 'edit' ? 'updated' : 'published')
            : ($updateMode === 'resubmit' ? 'resubmitted' : ($updateMode === 'edit' ? 'updated' : 'uploaded'));
        $response = ['success' => true, 'message' => $uploadedCount . ' document(s) ' . $actionLabel . ' successfully under "' . ($selectedTopic ?: 'No section') . '".'];
    } else {
        $response = ['success' => false, 'message' => 'No valid files were uploaded. Please attach supported documents and try again.'];
    }
} else {
    $response = ['success' => false, 'message' => 'No files were received. Please attach a file before uploading.'];
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response);
?>