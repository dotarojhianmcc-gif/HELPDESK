<?php
// user.php
include 'db.php';
// Auto-create viewer session so the help centre is accessible without login
if (!isset($_SESSION['user_id'])) {
    $viewerStmt = $pdo->prepare("SELECT id, username, role FROM users WHERE role = 'viewer' ORDER BY id ASC LIMIT 1");
    $viewerStmt->execute();
    $viewerUser = $viewerStmt->fetch();
    if ($viewerUser) {
        $_SESSION['user_id'] = $viewerUser['id'];
        $_SESSION['username'] = 'user2';
        $_SESSION['role'] = 'viewer';
    }
}
checkLogin();

if (!in_array($_SESSION['role'], ['user', 'viewer'], true)) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

$canUpload = $_SESSION['role'] === 'user';
$canDelete = $_SESSION['role'] === 'user';
$isViewer = $_SESSION['role'] === 'viewer';
$showModalLoginError = isset($_GET['login_error']) && $_GET['login_error'] === '1';
$modalLoginUsername = trim((string) ($_GET['login_username'] ?? ''));

$hasCategory = tableColumnExists($pdo, 'files', 'category');
$selectFields = $hasCategory
    ? "id, file_name, file_path, file_size, category, CASE WHEN status = 'archived' AND rejection_reason IS NOT NULL AND rejection_reason != '' THEN 'rejected' ELSE status END AS status, uploaded_at, approved_at, rejection_reason"
    : "id, file_name, file_path, file_size, CASE WHEN status = 'archived' AND rejection_reason IS NOT NULL AND rejection_reason != '' THEN 'rejected' ELSE status END AS status, uploaded_at, approved_at, rejection_reason";

if ($isViewer) {
    $stmt = $pdo->prepare("SELECT $selectFields FROM files WHERE user_id = ? AND status IN ('approved', 'archived') ORDER BY COALESCE(approved_at, uploaded_at) DESC");
} else {
    $stmt = $pdo->prepare("SELECT $selectFields FROM files WHERE user_id = ? ORDER BY COALESCE(approved_at, uploaded_at) DESC");
}
$stmt->execute([$_SESSION['user_id']]);
$files = $stmt->fetchAll();

$autoReplaceFileId = isset($_GET['replace_file_id']) ? (int) $_GET['replace_file_id'] : 0;
$autoReplaceFile = null;
if ($canUpload && $autoReplaceFileId > 0) {
    foreach ($files as $candidateFile) {
        if ((int) ($candidateFile['id'] ?? 0) === $autoReplaceFileId) {
            $autoReplaceFile = $candidateFile;
            break;
        }
    }
}

$adminCategorySelect = $hasCategory
    ? "COALESCE(f.category, 'General') AS category,"
    : "'General' AS category,";
$adminUpdatesStmt = $pdo->prepare("
    SELECT 
        f.id,
        f.file_name,
        f.file_path,
        f.file_size,
        $adminCategorySelect
        CASE WHEN f.status = 'archived' AND f.rejection_reason IS NOT NULL AND f.rejection_reason != '' THEN 'rejected'
             ELSE f.status END AS status,
        f.uploaded_at,
                f.approved_at,
                f.rejection_reason,
        u.username
    FROM files f
    JOIN users u ON f.user_id = u.id
        WHERE f.user_id != ?
            AND (
                f.status = 'approved'
                OR (f.status = 'archived' AND (f.rejection_reason IS NULL OR f.rejection_reason = ''))
            )
        ORDER BY COALESCE(f.approved_at, f.uploaded_at) DESC
    LIMIT 200
");
$adminUpdatesStmt->execute([$_SESSION['user_id']]);
$adminSharedFiles = $adminUpdatesStmt->fetchAll(PDO::FETCH_ASSOC);

$statStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status IN ('approved', 'archived') AND (rejection_reason IS NULL OR rejection_reason = '') THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status IN ('rejected', 'disapproved') OR (status = 'archived' AND rejection_reason IS NOT NULL AND rejection_reason != '') THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived
    FROM files 
    WHERE user_id = ?
");
$statStmt->execute([$_SESSION['user_id']]);
$stats = $statStmt->fetch();

$totalDocuments = (int) ($stats['total'] ?? 0);
$pendingDocuments = (int) ($stats['pending'] ?? 0);
$approvedDocuments = (int) ($stats['approved'] ?? 0);
$rejectedDocuments = (int) ($stats['rejected'] ?? 0);
$archivedDocuments = (int) ($stats['archived'] ?? 0);
$safeTotalDocuments = max($totalDocuments, 1);
$approvedPercent = (int) round(($approvedDocuments / $safeTotalDocuments) * 100);
$pendingPercent = (int) round(($pendingDocuments / $safeTotalDocuments) * 100);
$rejectedPercent = (int) round(($rejectedDocuments / $safeTotalDocuments) * 100);
$archivedPercent = (int) round(($archivedDocuments / $safeTotalDocuments) * 100);

$notifStmt = $pdo->prepare("
    SELECT id, message, is_read, type, created_at FROM notifications 
    WHERE user_id = ? AND LOWER(message) NOT LIKE '%archived%'
    ORDER BY created_at DESC 
    LIMIT 20
");
$notifStmt->execute([$_SESSION['user_id']]);
$notifications = $notifStmt->fetchAll();

$reviewNotifications = array_values(array_filter($notifications, static function ($notice) {
    $message = strtolower((string) ($notice['message'] ?? ''));
    return strpos($message, 'approved') !== false
        || strpos($message, 'rejected') !== false
        || strpos($message, 'disapproved') !== false;
}));

$unreadNotifications = array_filter($notifications, fn($n) => !$n['is_read']);
$unreadReviewCount = count(array_filter($reviewNotifications, static fn($notice) => !$notice['is_read']));
$hasUnreadReviewUpdate = false;
foreach ($unreadNotifications as $notice) {
    $message = strtolower((string) ($notice['message'] ?? ''));
    if (strpos($message, 'approved') !== false || strpos($message, 'rejected') !== false || strpos($message, 'disapproved') !== false) {
        $hasUnreadReviewUpdate = true;
        break;
    }
}

function findNotificationDocument(array $notification, array $ownFiles, array $sharedFiles): ?array
{
    $message = strtolower((string) ($notification['message'] ?? ''));

    foreach ($sharedFiles as $document) {
        $name = strtolower((string) ($document['file_name'] ?? ''));
        if ($name !== '' && strpos($message, $name) !== false) {
            $document['access_scope'] = 'shared';
            return $document;
        }
    }

    foreach ($ownFiles as $document) {
        $name = strtolower((string) ($document['file_name'] ?? ''));
        if ($name !== '' && strpos($message, $name) !== false) {
            $document['access_scope'] = 'own';
            return $document;
        }
    }

    return null;
}

function isStoredDocumentAvailable(array $document): bool
{
    $resolvedPath = resolveStoredFilePath($document['file_path'] ?? '');
    return $resolvedPath !== null && is_file($resolvedPath);
}

$flashMessage = '';
if (!empty($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

$storedTopics = loadTopics();
$templateSections = [];
$displayTopics = [];

foreach ($storedTopics as $rawTopic) {
    $rawTopic = trim((string) $rawTopic);
    if ($rawTopic === '') {
        continue;
    }

    if (strpos($rawTopic, ' / ') !== false) {
        [$parentTopic, $subsection] = array_map('trim', explode(' / ', $rawTopic, 2));
        if ($parentTopic === '' || $subsection === '') {
            continue;
        }

        if (!isset($templateSections[$parentTopic])) {
            $templateSections[$parentTopic] = [];
        }

        if (!in_array($subsection, $templateSections[$parentTopic], true)) {
            $templateSections[$parentTopic][] = $subsection;
        }

        if (!in_array($parentTopic, $displayTopics, true)) {
            $displayTopics[] = $parentTopic;
        }
        continue;
    }

    if (!isset($templateSections[$rawTopic])) {
        $templateSections[$rawTopic] = [];
    }

    if (!in_array($rawTopic, $displayTopics, true)) {
        $displayTopics[] = $rawTopic;
    }
}

$topicHierarchy = [];
foreach ($storedTopics as $topicName) {
    $rawTopic = trim((string) $topicName);
    if ($rawTopic === '') {
        continue;
    }

    $parts = array_values(array_filter(array_map('trim', explode(' / ', $rawTopic)), fn($part) => $part !== ''));
    if (empty($parts)) {
        continue;
    }

    $currentBranch =& $topicHierarchy;
    foreach ($parts as $part) {
        if (!isset($currentBranch[$part])) {
            $currentBranch[$part] = [];
        }
        $currentBranch =& $currentBranch[$part];
    }
    unset($currentBranch);
}

function renderNestedTopicButtons(array $nodes, string $prefix, string $leafHandler): void
{
    foreach ($nodes as $label => $children) {
        $path = $prefix !== '' ? $prefix . ' / ' . $label : $label;
        if (!empty($children)) {
            ?>
            <div class="topic-subitem-group">
                <button type="button" class="topic-subitem topic-subitem-parent" aria-expanded="false" onclick="toggleNestedTopicBranch(this)">
                    <span class="topic-subitem-label"><?php echo htmlspecialchars($label); ?></span>
                    <span class="topic-subitem-arrow">▾</span>
                </button>
                <div class="topic-subitem-children">
                    <?php renderNestedTopicButtons($children, $path, $leafHandler); ?>
                </div>
            </div>
            <?php
            continue;
        }
        ?>
        <button type="button" class="topic-subitem topic-subitem-leaf" data-value="<?php echo htmlspecialchars($path); ?>" onclick='<?php echo $leafHandler; ?>(<?php echo json_encode($path); ?>)'>
            <span class="topic-subitem-label"><?php echo htmlspecialchars($label); ?></span>
        </button>
        <?php
    }
}

function renderNestedTopicOptions(array $nodes, string $prefix): void
{
    foreach ($nodes as $label => $children) {
        $path = $prefix !== '' ? $prefix . ' / ' . $label : $label;
        ?>
        <option value="<?php echo htmlspecialchars($path); ?>"><?php echo htmlspecialchars($path); ?></option>
        <?php
        if (!empty($children)) {
            renderNestedTopicOptions($children, $path);
        }
    }
}

function renderTopicPathContent(string $path, array $sharedMap, array $ownMap, array $feedbackMap, bool $canUpload, bool $canDelete, bool $showEmpty = false): void
{
    $ownTopicDocuments = $ownMap[$path] ?? [];
    $topicFeedbackItems = $feedbackMap[$path] ?? [];
    $topicDocuments = $sharedMap[$path] ?? [];

    if (empty($ownTopicDocuments) && empty($topicDocuments) && empty($topicFeedbackItems) && !$showEmpty) {
        return;
    }

    echo '<div class="topic-subitem-content" data-topic-path="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '">';

    foreach ($ownTopicDocuments as $topicDocument) {
        $hasStoredFile = isStoredDocumentAvailable($topicDocument);
        ?>
        <div class="approved-file-entry">
            <?php if ($hasStoredFile): ?>
                <a class="approved-file-link" href="view_file.php?file_id=<?php echo (int) $topicDocument['id']; ?>" target="_blank"><?php echo htmlspecialchars($topicDocument['file_name']); ?></a>
            <?php else: ?>
                <span class="approved-file-link" style="cursor:default; color:#9b1c1c;"><?php echo htmlspecialchars($topicDocument['file_name']); ?> (missing file)</span>
            <?php endif; ?>
            <div class="approved-file-actions">
                <?php if ($hasStoredFile): ?>
                    <a class="approved-file-action download" href="download.php?file_id=<?php echo (int) $topicDocument['id']; ?>" target="_blank">Download</a>
                <?php elseif ($canUpload): ?>
                    <button type="button" class="approved-file-action" onclick='openEditUpload(<?php echo (int) $topicDocument["id"]; ?>, <?php echo json_encode($topicDocument["category"] ?? $path); ?>, <?php echo json_encode($topicDocument["file_name"]); ?>)'>Upload replacement</button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    foreach ($topicDocuments as $topicDocument) {
        $hasStoredFile = isStoredDocumentAvailable($topicDocument);
        ?>
        <div class="approved-file-entry">
            <?php if ($hasStoredFile): ?>
                <a class="approved-file-link" href="view_file.php?file_id=<?php echo (int) $topicDocument['id']; ?>" target="_blank"><?php echo htmlspecialchars($topicDocument['file_name']); ?></a>
            <?php else: ?>
                <span class="approved-file-link" style="cursor:default; color:#9b1c1c;"><?php echo htmlspecialchars($topicDocument['file_name']); ?> (missing file)</span>
            <?php endif; ?>
            <div class="approved-file-actions">
                <?php if ($hasStoredFile): ?>
                    <a class="approved-file-action download" href="download.php?file_id=<?php echo (int) $topicDocument['id']; ?>" target="_blank">Download</a>
                <?php else: ?>
                    <span class="approved-file-action" style="opacity:.75; cursor:default;">Missing on server</span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    foreach ($topicFeedbackItems as $feedbackItem) {
        ?>
        <div class="upload-feedback-card">
            <strong>Admin feedback: <?php echo htmlspecialchars($feedbackItem['file_name']); ?></strong>
            <span><?php echo htmlspecialchars(trim((string) ($feedbackItem['rejection_reason'] ?? '')) ?: 'This file was rejected by admin review.'); ?></span>
            <?php if ($canUpload): ?>
                <div class="upload-feedback-actions">
                    <button type="button" class="upload-feedback-action" onclick='openResubmitUpload(<?php echo (int) $feedbackItem["id"]; ?>, <?php echo json_encode($feedbackItem["category"] ?? $path); ?>, <?php echo json_encode($feedbackItem["file_name"]); ?>)'>Resubmit file</button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    if (empty($ownTopicDocuments) && empty($topicDocuments) && empty($topicFeedbackItems) && $showEmpty) {
        echo '<p class="notification-empty">No files yet in this section.</p>';
    }

    echo '</div>';
}

function renderDashboardTopicButtons(array $nodes, string $prefix, array $sharedMap, array $ownMap, array $feedbackMap, bool $canUpload, bool $canDelete): void
{
    foreach ($nodes as $label => $children) {
        $path = $prefix !== '' ? $prefix . ' / ' . $label : $label;
        if (!empty($children)) {
            ?>
            <div class="topic-subitem-group">
                <button type="button" class="topic-subitem topic-subitem-parent" aria-expanded="false" onclick="toggleNestedTopicBranch(this)">
                    <span class="topic-subitem-label"><?php echo htmlspecialchars($label); ?></span>
                    <span class="topic-subitem-arrow">▾</span>
                </button>
                <div class="topic-subitem-children">
                    <?php renderDashboardTopicButtons($children, $path, $sharedMap, $ownMap, $feedbackMap, $canUpload, $canDelete); ?>
                    <?php renderTopicPathContent($path, $sharedMap, $ownMap, $feedbackMap, $canUpload, $canDelete); ?>
                </div>
            </div>
            <?php
            continue;
        }
        ?>
        <button type="button" class="topic-subitem topic-subitem-leaf" data-value="<?php echo htmlspecialchars($path); ?>" onclick='openUploadWithTopic(<?php echo json_encode($path); ?>)'>
            <span class="topic-subitem-label"><?php echo htmlspecialchars($label); ?></span>
        </button>
        <?php renderTopicPathContent($path, $sharedMap, $ownMap, $feedbackMap, $canUpload, $canDelete); ?>
        <?php
    }
}

function topicTreeHasContent(string $path, array $sharedMap, array $ownMap, array $feedbackMap): bool
{
    $pathPrefix = $path . ' / ';
    foreach ([$sharedMap, $ownMap, $feedbackMap] as $map) {
        foreach ($map as $itemPath => $items) {
            if (empty($items)) {
                continue;
            }

            if (strcasecmp($itemPath, $path) === 0 || stripos($itemPath, $pathPrefix) === 0) {
                return true;
            }
        }
    }

    return false;
}

$topicFileMap = [];
$userTopicFileMap = [];
$userTopicFeedbackMap = [];
$topicLookup = [];

foreach ($storedTopics as $topicName) {
    $normalizedTopicName = trim((string) $topicName);
    if ($normalizedTopicName === '') {
        continue;
    }

    $topicFileMap[$normalizedTopicName] = [];
    $userTopicFileMap[$normalizedTopicName] = [];
    $userTopicFeedbackMap[$normalizedTopicName] = [];
    $topicLookup[strtolower($normalizedTopicName)] = $normalizedTopicName;
}

$resolveTopicTargets = function (string $documentCategory) use ($topicLookup, $storedTopics, $displayTopics): array {
    $normalizeTopicKey = static function (string $value): string {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('&', ' and ', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized);
        return trim((string) preg_replace('/\s+/', ' ', (string) $normalized));
    };

    $rawCategory = trim($documentCategory);
    $normalizedCategory = strtolower($rawCategory);
    $normalizedCategoryKey = $normalizeTopicKey($rawCategory);

    $fallbackTopic = '';
    foreach ($displayTopics as $topicName) {
        if (strcasecmp(trim((string) $topicName), 'Memos') === 0) {
            $fallbackTopic = trim((string) $topicName);
            break;
        }
    }
    if ($fallbackTopic === '' && !empty($displayTopics)) {
        $fallbackTopic = trim((string) $displayTopics[0]);
    }

    if ($normalizedCategory === '' || in_array($normalizedCategory, ['general', 'uncategorized', 'n/a', 'none'], true)) {
        return $fallbackTopic !== '' ? [$fallbackTopic] : [];
    }

    if (isset($topicLookup[$normalizedCategory])) {
        $exactTopic = $topicLookup[$normalizedCategory];

        // If a file was saved on a parent topic (e.g. "Memos"), surface it in child sections too.
        $childMatches = [];
        $childPrefix = strtolower($exactTopic) . ' / ';
        foreach ($storedTopics as $topicName) {
            $candidate = trim((string) $topicName);
            if ($candidate === '') {
                continue;
            }

            if (stripos(strtolower($candidate), $childPrefix) === 0) {
                $childMatches[] = $candidate;
            }
        }

        if (!empty($childMatches)) {
            return array_values(array_unique($childMatches));
        }

        return [$exactTopic];
    }

    // Match canonicalized full paths (handles symbols/spacing variants such as '&' vs 'and').
    foreach ($storedTopics as $topicName) {
        $candidate = trim((string) $topicName);
        if ($candidate === '') {
            continue;
        }

        if ($normalizedCategoryKey !== '' && $normalizeTopicKey($candidate) === $normalizedCategoryKey) {
            return [$candidate];
        }
    }

    // Backward compatibility: map legacy category labels (e.g. "HR") to nested paths (e.g. "Memos / HR").
    $suffix = ' / ' . $normalizedCategory;
    $matches = [];
    $normalizedLeafCategory = $normalizedCategoryKey;
    foreach ($storedTopics as $topicName) {
        $candidate = trim((string) $topicName);
        if ($candidate === '') {
            continue;
        }

        $normalizedCandidate = strtolower($candidate);
        if (substr($normalizedCandidate, -strlen($suffix)) === $suffix) {
            $matches[] = $candidate;
            continue;
        }

        $parts = array_values(array_filter(array_map('trim', explode(' / ', $candidate)), static fn($part) => $part !== ''));
        if (empty($parts)) {
            continue;
        }

        $lastSegment = (string) end($parts);
        $normalizedLastSegment = $normalizeTopicKey($lastSegment);
        if ($normalizedLeafCategory !== '' && $normalizedLeafCategory === $normalizedLastSegment) {
            $matches[] = $candidate;
        }
    }

    if (!empty($matches)) {
        return array_values(array_unique($matches));
    }

    if ($fallbackTopic !== '') {
        return [$fallbackTopic];
    }

    return [$rawCategory];
};

foreach ($adminSharedFiles as $sharedDocument) {
    $documentCategory = trim((string) ($sharedDocument['category'] ?? ''));

    $matchedTopics = $resolveTopicTargets($documentCategory);
    foreach ($matchedTopics as $matchedTopic) {
        if (!isset($topicFileMap[$matchedTopic])) {
            $topicFileMap[$matchedTopic] = [];
        }
        $topicFileMap[$matchedTopic][] = $sharedDocument;
    }
}

foreach ($files as $ownDocument) {
    $documentCategory = trim((string) ($ownDocument['category'] ?? ''));

    $normalizedStatus = normalizeFileStatus($ownDocument['status'] ?? '');
    $hasRejectionFeedback = $normalizedStatus === 'rejected'
        || trim((string) ($ownDocument['rejection_reason'] ?? '')) !== '';
    $isVisibleInHelpCenter = $normalizedStatus === 'approved'
        || ($normalizedStatus === 'archived' && !$hasRejectionFeedback);

    $matchedTopics = $resolveTopicTargets($documentCategory);
    if ($isVisibleInHelpCenter) {
        foreach ($matchedTopics as $matchedTopic) {
            if (!isset($userTopicFileMap[$matchedTopic])) {
                $userTopicFileMap[$matchedTopic] = [];
            }
            $userTopicFileMap[$matchedTopic][] = $ownDocument;
        }
    } elseif ($hasRejectionFeedback) {
        foreach ($matchedTopics as $matchedTopic) {
            if (!isset($userTopicFeedbackMap[$matchedTopic])) {
                $userTopicFeedbackMap[$matchedTopic] = [];
            }
            $userTopicFeedbackMap[$matchedTopic][] = $ownDocument;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Help Center - Support HelpDesk</title>
    <link rel="icon" type="image/svg+xml" href="logo.svg?v=20260408e">
    <link rel="shortcut icon" href="logo.svg?v=20260408e">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__.'/style.css'); ?>">
    <style>
        .notification-banner {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid;
            animation: slideDown 0.3s ease-out;
        }
        .notification-banner.success {
            background-color: #d4edda;
            color: #155724;
            border-bottom-color: #28a745;
        }
        .notification-banner.error {
            background-color: #f8d7da;
            color: #721c24;
            border-bottom-color: #dc3545;
        }
        .notification-banner.warning {
            background-color: #fff3cd;
            color: #856404;
            border-bottom-color: #ffc107;
        }
        .notification-banner.info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-bottom-color: #17a2b8;
        }
        .notification-banner-close {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }
        .notification-banner-close:hover {
            opacity: 1;
        }
        @keyframes slideDown {
            from {
                transform: translateY(-100%);
            }
            to {
                transform: translateY(0);
            }
        }
        .container {
            margin-top: 0;
        }
        .admin-updates-note {
            color: #5e6c84;
            margin-bottom: 14px;
        }
        .admin-update-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border: 1px solid #e4ebf3;
            border-radius: 10px;
            background: #f9fbff;
            margin-bottom: 10px;
        }
        .admin-update-item strong {
            display: block;
            color: #032856;
        }
        .admin-update-meta {
            display: block;
            margin-top: 4px;
            color: #5e6c84;
            font-size: 12px;
        }
        .notification-item-actions {
            margin-top: 10px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .notification-link-note {
            margin-top: 8px;
            color: #5e6c84;
            font-size: 12px;
        }
        .notice-open-link {
            display: block;
            width: 100%;
            padding: 0;
            border: 0;
            background: transparent;
            color: inherit;
            text-align: left;
            text-decoration: none;
            cursor: pointer;
            font: inherit;
        }
        .notice-open-link:hover .notice-message {
            text-decoration: underline;
        }
        .notice-item-actions {
            margin-top: 8px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .approved-file-entry {
            margin-top: 10px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
            transition: transform 0.12s ease, box-shadow 0.12s ease;
            transform-origin: center;
        }
        .approved-file-entry.is-bouncing {
            animation: approvedFileBounce 0.18s ease-out;
        }
        .approved-file-entry:active {
            transform: scale(0.992);
        }
        .approved-file-link {
            min-width: 0;
            color: #1565d8;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.45;
            text-decoration: none;
            word-break: break-word;
        }
        .approved-file-link:hover {
            color: #0f4fb0;
            text-decoration: underline;
        }
        .approved-file-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .approved-file-action {
            border: none;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease;
        }
        .approved-file-action.download {
            background: #edf4ff;
            color: #1d4fa3;
        }
        .approved-file-action.download:hover {
            background: #dce9ff;
        }
        .approved-file-action.delete {
            background: #fdeaea;
            color: #b42318;
        }
        .approved-file-action.delete:hover {
            background: #f9d5d5;
        }
        @keyframes approvedFileBounce {
            0% {
                transform: scale(1);
            }
            40% {
                transform: scale(1.025);
            }
            72% {
                transform: scale(0.992);
            }
            100% {
                transform: scale(1);
            }
        }
        @media (max-width: 640px) {
            .approved-file-entry {
                align-items: flex-start;
                flex-direction: column;
            }
            .approved-file-actions {
                width: 100%;
                justify-content: flex-start;
                flex-wrap: wrap;
            }
        }
        .upload-feedback-card {
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid #f2d1d1;
            background: #fff5f5;
            color: #7b1f27;
        }
        .upload-feedback-card strong {
            display: block;
            margin-bottom: 4px;
            color: #64151c;
        }
        .upload-feedback-card span {
            display: block;
            font-size: 13px;
            line-height: 1.45;
        }
        .upload-feedback-actions {
            margin-top: 10px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .table-action-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .table-action-group .btn-small,
        .table-action-group .btn-primary,
        .table-action-group .btn-edit,
        .table-action-group .btn-delete {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 78px;
            padding: 8px 12px;
            border: none;
            border-radius: 10px;
            background: #eef4ff;
            color: #1c4ea1;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            text-decoration: none;
            box-shadow: none;
            cursor: pointer;
            transition: transform 0.18s ease, background-color 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
            transform-origin: center;
            will-change: transform;
        }
        .table-action-group .btn-small:hover,
        .table-action-group .btn-primary:hover,
        .table-action-group .btn-edit:hover,
        .table-action-group .btn-delete:hover {
            background: #dfe9ff;
            color: #163d82;
            box-shadow: 0 10px 22px rgba(28, 78, 161, 0.18);
        }
        .table-action-group .btn-small:active,
        .table-action-group .btn-primary:active,
        .table-action-group .btn-edit:active,
        .table-action-group .btn-delete:active {
            transform: none;
        }
        .table-action-group .btn-small.is-pressing,
        .table-action-group .btn-primary.is-pressing,
        .table-action-group .btn-edit.is-pressing,
        .table-action-group .btn-delete.is-pressing {
            animation: none;
        }
        .btn-small,
        .btn-primary,
        .btn-edit,
        .btn-delete,
        .btn-logout,
        .approved-file-action,
        .upload-feedback-action {
            transform-origin: center;
            will-change: transform;
        }
        .btn-small:active,
        .btn-primary:active,
        .btn-edit:active,
        .btn-delete:active,
        .btn-logout:active,
        .approved-file-action:active,
        .upload-feedback-action:active {
            transform: none;
        }
        .btn-small.is-pressing,
        .btn-primary.is-pressing,
        .btn-edit.is-pressing,
        .btn-delete.is-pressing,
        .btn-logout.is-pressing,
        .approved-file-action.is-pressing,
        .upload-feedback-action.is-pressing {
            animation: none;
        }
        @keyframes actionButtonZoom {
            0% {
                transform: scale(1);
                box-shadow: 0 0 0 rgba(28, 78, 161, 0);
            }
            35% {
                transform: scale(0.82);
                box-shadow: 0 4px 10px rgba(28, 78, 161, 0.1);
            }
            72% {
                transform: scale(1.08);
                box-shadow: 0 14px 28px rgba(28, 78, 161, 0.2);
            }
            100% {
                transform: scale(1);
                box-shadow: 0 0 0 rgba(28, 78, 161, 0);
            }
        }
        .upload-feedback-action {
            border: none;
            border-radius: 999px;
            padding: 8px 14px;
            background: #ffe4e1;
            color: #9f1f17;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .upload-feedback-action:hover {
            background: #ffd5d1;
        }
        .btn-edit {
            border: none;
            border-radius: 8px;
            padding: 8px 12px;
            background: #eef4ff;
            color: #1c4ea1;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-edit:hover {
            background: #dfe9ff;
        }
        .resubmit-banner {
            display: none;
            margin: 0 0 16px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid #c8daf7;
            background: #eef5ff;
            color: #17438a;
        }
        .resubmit-banner.active {
            display: block;
        }
        .resubmit-banner strong {
            display: block;
            margin-bottom: 4px;
        }
        .resubmit-banner p {
            margin: 0;
            font-size: 13px;
            line-height: 1.45;
        }
        .resubmit-banner button {
            margin-top: 10px;
        }
        .user-home-layout .container {
            display: block;
            min-height: 100vh;
        }
        .user-home-layout {
            font-family: "Segoe UI Variable", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #eef3f9;
        }
        .user-home-layout .sidebar {
            display: none;
        }
        .user-home-layout .main-content {
            margin-left: 0;
            width: 100%;
            flex: none;
            min-height: 100vh;
            background: #f4f7fb;
            overflow: visible;
        }
        .user-home-layout .top-header {
            width: 100%;
            min-height: auto;
            padding: 18px 28px;
            border-radius: 0;
            background: #234f78;
            box-shadow: none;
        }
        .user-home-layout .header-left {
            flex: 1;
        }
        .user-home-layout .header-left h1 {
            color: #ffffff;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 0.01em;
            line-height: 1.2;
            margin: 0;
        }
        .user-home-layout .content-area {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 24px 0 40px;
            overflow: visible;
        }
        .user-home-layout .top-header + .content-area {
            padding-top: 26px;
        }
        .user-home-layout .page-section {
            width: 100%;
            overflow: visible;
        }
        .user-home-layout .help-center-card {
            background: transparent;
            border: none;
            box-shadow: none;
            padding: 0;
            margin-bottom: 0;
        }
        .user-home-layout .header-right {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 20px;
            flex-wrap: nowrap;
            min-width: 0;
        }
        .user-home-layout .help-topic-list {
            width: 100%;
            overflow: visible;
        }
        .user-home-layout .topic-template-card {
            width: 100%;
            border-radius: 14px;
            transition: none;
            -webkit-tap-highlight-color: transparent;
            overscroll-behavior: contain;
        }
        .user-home-layout .topic-template-card:hover {
            transform: none;
            box-shadow: 0 10px 24px rgba(15, 43, 76, 0.05);
        }
        .user-home-layout .topic-template-header,
        .user-home-layout .topic-template-body,
        .user-home-layout .topic-template-body > div,
        .user-home-layout .topic-template-arrow {
            transition: none;
        }
        .user-home-layout .topic-template-header {
            outline: none;
            box-shadow: none;
            -webkit-tap-highlight-color: transparent;
        }
        .user-home-layout .topic-template-header:focus,
        .user-home-layout .topic-template-header:active,
        .user-home-layout .topic-template-header:focus-visible {
            outline: none;
            box-shadow: none;
        }
        .user-home-layout .topic-template-title,
        .user-home-layout .topbar-search-input,
        .user-home-layout .bottom-logout-btn {
            font-family: inherit;
        }
        .user-home-layout .topic-template-title {
            font-size: 14px;
            font-weight: 700;
            color: #16385f;
        }
        .user-home-layout .topic-template-header {
            padding: 12px 16px;
            gap: 12px;
        }
        .user-home-layout .topic-template-meta {
            gap: 10px;
        }
        .user-home-layout .topic-template-icon {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            font-size: 14px;
        }
        .user-home-layout .topic-template-arrow {
            font-size: 14px;
            line-height: 1;
        }
        .user-home-layout .bottom-logout-wrap {
            position: fixed;
            right: 24px;
            bottom: 16px;
            z-index: 1100;
        }
        .user-home-layout .bottom-logout-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 120px;
            height: 48px;
            padding: 0 18px;
            border-radius: 14px;
            background: #234f78;
            border: 1px solid rgba(18, 54, 92, 0.16);
            color: #ffffff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.01em;
            box-shadow: 0 14px 28px rgba(20, 52, 92, 0.18);
        }
        .user-home-layout .bottom-logout-btn:hover {
            background: #1c4265;
        }
        @media (max-width: 720px) {
            .user-home-layout .bottom-logout-wrap {
                left: 16px;
                right: 16px;
                bottom: 32px;
            }
            .user-home-layout .bottom-logout-btn {
                width: 100%;
            }
        }
        /* Viewer login button in sidebar */
        .btn-login-logo {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 10px 16px;
            border-radius: 10px;
            background: #1c6fb5;
            border: none;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-align: left;
            box-sizing: border-box;
        }
        .btn-login-logo img {
            width: 22px;
            height: 22px;
            filter: brightness(0) invert(1);
        }
        .btn-login-logo:hover {
            background: #1558a0;
        }
        /* Login modal overlay */
        .viewer-login-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9000;
            background: rgba(10, 30, 60, 0.45);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            align-items: center;
            justify-content: center;
        }
        .viewer-login-modal-overlay.open {
            display: flex;
        }
        .viewer-login-modal {
            background: #fff;
            border-radius: 18px;
            padding: 36px 32px 28px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 24px 64px rgba(10, 30, 80, 0.22);
            position: relative;
        }
        .viewer-login-modal h2 {
            margin: 0 0 20px;
            font-size: 20px;
            font-weight: 700;
            color: #14325e;
            text-align: center;
        }
        .viewer-login-modal .modal-logo {
            display: flex;
            justify-content: center;
            margin-bottom: 14px;
        }
        .viewer-login-modal .modal-logo img {
            width: 52px;
            height: 52px;
        }
        .viewer-login-modal .modal-field {
            margin-bottom: 14px;
        }
        .viewer-login-modal .modal-field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #2c4a6e;
            margin-bottom: 5px;
        }
        .viewer-login-modal .modal-field input {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid #c8d8ea;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .viewer-login-modal .modal-btn-login {
            width: 100%;
            padding: 12px;
            background: #1c6fb5;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 6px;
        }
        .viewer-login-modal .modal-btn-login:hover {
            background: #1558a0;
        }
        .viewer-login-modal .modal-close {
            position: absolute;
            top: 14px;
            right: 16px;
            background: none;
            border: none;
            font-size: 22px;
            cursor: pointer;
            color: #6b8aad;
            line-height: 1;
        }
        .viewer-login-modal .modal-error {
            color: #c0392b;
            font-size: 13px;
            margin-bottom: 10px;
            text-align: center;
        }
    </style>
</head>
<body class="user-home-layout" data-can-upload="<?php echo $canUpload ? '1' : '0'; ?>">
    <div class="container">
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="logo.svg?v=20260408e" alt="HelpDesk logo">
                </div>
                <div class="sidebar-profile">
                    <h2><?php echo htmlspecialchars(strtoupper($_SESSION['username'])); ?></h2>
                </div>
            </div>

            <nav class="sidebar-nav">
                <a href="#" class="nav-item active" data-page="dashboard">
                    🏠 Help Center
                </a>
            </nav>

            <div class="sidebar-footer">
                <div class="user-info">
                    <p>Logged in as:</p>
                    <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                </div>
                <?php if ($isViewer): ?>
                    <button type="button" class="btn-login-logo" data-login-trigger>
                        <img src="logo.svg" alt="Login"> Login
                    </button>
                <?php else: ?>
                    <a href="logout.php" class="btn-logout">🚪 Logout</a>
                <?php endif; ?>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <header class="top-header">
                <div class="header-left">
                    <h1>How can we help you today?</h1>
                </div>
                <div class="header-right">
                    <div class="topbar-search-shell" role="search">
                        <input type="text" id="dashboardTopicSearch" class="topbar-search-input" placeholder="Search...">
                        <button type="button" class="search-trigger search-trigger-icon" onclick="triggerHelpSearch()" aria-label="Search Help Center">
                            <svg class="search-svg" viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="11" cy="11" r="6.5"></circle>
                                <path d="M16 16L21 21"></path>
                            </svg>
                        </button>
                    </div>
                    <?php if (!$isViewer): ?>
                    <div class="notification-center">
                        <button type="button" class="notification-bell" data-notice-target="userNoticeMenu" aria-expanded="false" aria-label="User notifications">
                            <span class="bell-icon" aria-hidden="true">
                                <svg class="bell-svg" viewBox="0 0 24 24">
                                    <path d="M12 3a4 4 0 0 0-4 4v1.1c0 .7-.24 1.38-.68 1.92L5.6 12.2A2 2 0 0 0 7.16 15h9.68a2 2 0 0 0 1.56-2.8l-1.72-2.18A3.02 3.02 0 0 1 16 8.1V7a4 4 0 0 0-4-4Z"></path>
                                    <path d="M9.5 17a2.5 2.5 0 0 0 5 0"></path>
                                </svg>
                            </span>
                            <span class="badge" id="notif-count"><?php echo $unreadReviewCount; ?></span>
                        </button>
                        <div class="notification-menu" id="userNoticeMenu">
                            <div class="notification-menu-header">
                                <strong>Recent Notifications</strong>
                                <button type="button" class="notice-read-btn" onclick="markNotificationsRead()">Mark all read</button>
                            </div>
                            <div class="notification-menu-list">
                                <?php if (empty($reviewNotifications)): ?>
                                    <p class="notification-empty">No notices yet.</p>
                                <?php else: ?>
                                    <?php foreach ($reviewNotifications as $notif): ?>
                                        <?php $linkedDocument = findNotificationDocument($notif, $files, $adminSharedFiles); ?>
                                        <?php $linkedDocumentAvailable = $linkedDocument ? isStoredDocumentAvailable($linkedDocument) : false; ?>
                                        <div class="notice-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>" data-notification-id="<?php echo $notif['id']; ?>">
                                            <?php if ($linkedDocument && $linkedDocumentAvailable): ?>
                                                <a class="notice-open-link" href="view_file.php?file_id=<?php echo (int) $linkedDocument['id']; ?>" target="_blank">
                                                    <span class="notice-message"><?php echo htmlspecialchars($notif['message']); ?></span>
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="notice-open-link" onclick="openNotificationsPanel('dashboard'); markNotificationsRead(false, <?php echo (int) $notif['id']; ?>)">
                                                    <span class="notice-message"><?php echo htmlspecialchars($notif['message']); ?></span>
                                                </button>
                                            <?php endif; ?>
                                            <small><?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></small>

                                            <?php if ($linkedDocument && $linkedDocumentAvailable): ?>
                                                <div class="notice-item-actions">
                                                    <a class="btn-small" href="view_file.php?file_id=<?php echo (int) $linkedDocument['id']; ?>" target="_blank">View</a>
                                                    <a class="btn-primary btn-small" href="download.php?file_id=<?php echo (int) $linkedDocument['id']; ?>" target="_blank">Download</a>
                                                </div>
                                            <?php elseif ($linkedDocument): ?>
                                                <div class="notice-item-actions">
                                                    <span class="btn-small" style="opacity:.75; cursor:default;">Missing on server</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </header>

            <div class="content-area">
                <!-- DASHBOARD PAGE -->
                <section id="dashboard" class="page-section active">

                    <?php if ($canUpload): ?>

                    <!-- User Upload Modal (Main Topic / Section / Under Section) -->
                    <div id="userUploadModalOverlay" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.25);backdrop-filter:blur(2px);z-index:1000;justify-content:center;align-items:center;">
                        <div style="background:#fff;padding:36px 34px 26px;border-radius:16px;box-shadow:0 4px 32px rgba(0,0,0,0.18);min-width:720px;max-width:96vw;position:relative;">
                            <button id="closeUserUploadModal" type="button" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:28px;line-height:1;cursor:pointer;">&times;</button>
                            <form id="userModalUploadForm" enctype="multipart/form-data" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                                <input type="file" id="userModalUploadInput" name="files[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt,.zip" style="min-width:230px;">

                                <select id="userMainTopicSelect" style="min-width:230px;height:42px;padding:0 12px;border:1px solid #d2d6db;border-radius:6px;">
                                    <option value="">Select Main Topic</option>
                                </select>

                                <select id="userSectionSelect" style="min-width:170px;height:42px;padding:0 12px;border:1px solid #d2d6db;border-radius:6px;" disabled>
                                    <option value="">Select Section</option>
                                </select>

                                <select id="userUnderSectionSelect" style="min-width:190px;height:42px;padding:0 12px;border:1px solid #d2d6db;border-radius:6px;" disabled>
                                    <option value="">Select Under Section</option>
                                </select>

                                <button type="submit" style="background:#d53131;color:#fff;font-weight:700;padding:10px 30px;border:none;border-radius:9px;font-size:17px;line-height:1;box-shadow:0 2px 8px rgba(0,0,0,0.12);cursor:pointer;">Upload</button>
                                <input type="hidden" id="userModalTopic" name="topic" value="">
                                <input type="hidden" id="userModalResubmitId" name="resubmit_file_id" value="">
                                <input type="hidden" id="userModalEditId" name="edit_file_id" value="">
                            </form>
                        </div>
                    </div>

                    <script>
                    function initUserUploadModal() {
                        const canUpload = document.body && document.body.dataset && document.body.dataset.canUpload === '1';
                        if (!canUpload) {
                            return;
                        }

                        const openBtn = document.getElementById('openUserUploadModal');
                        const overlay = document.getElementById('userUploadModalOverlay');
                        const closeBtn = document.getElementById('closeUserUploadModal');
                        const form = document.getElementById('userModalUploadForm');
                        const fileInput = document.getElementById('userModalUploadInput');
                        const topicHiddenInput = document.getElementById('userModalTopic');
                        const resubmitIdInput = document.getElementById('userModalResubmitId');
                        const editIdInput = document.getElementById('userModalEditId');
                        const mainSelect = document.getElementById('userMainTopicSelect');
                        const sectionSelect = document.getElementById('userSectionSelect');
                        const underSelect = document.getElementById('userUnderSectionSelect');
                        const topicSelect = document.getElementById('topicSelect');
                        const phpTopicPaths = <?php echo json_encode(array_values($storedTopics)); ?>;

                        if (!openBtn || !overlay || !closeBtn || !form || !fileInput || !topicHiddenInput || !resubmitIdInput || !editIdInput || !mainSelect || !sectionSelect || !underSelect) {
                            return;
                        }

                        if (form.dataset.modalBound === '1') {
                            return;
                        }
                        form.dataset.modalBound = '1';

                        let modalMode = '';
                        let modalFileName = '';

                        const submitButton = form.querySelector('button[type="submit"]');

                        const applyModalModeUI = function () {
                            if (!submitButton) {
                                return;
                            }

                            if (modalMode === 'resubmit') {
                                submitButton.textContent = 'Resubmit File';
                            } else if (modalMode === 'edit') {
                                submitButton.textContent = 'Save File Changes';
                            } else {
                                submitButton.textContent = 'Upload';
                            }
                        };

                        const clearModalMode = function () {
                            modalMode = '';
                            modalFileName = '';
                            resubmitIdInput.value = '';
                            editIdInput.value = '';
                            applyModalModeUI();
                        };

                        window.configureUserUploadModalMode = function (mode, fileId, fileName) {
                            const normalizedMode = String(mode || '').toLowerCase();
                            if (normalizedMode !== 'resubmit' && normalizedMode !== 'edit') {
                                clearModalMode();
                                return;
                            }

                            modalMode = normalizedMode;
                            modalFileName = String(fileName || '').trim();
                            const normalizedId = parseInt(fileId, 10);
                            if (!Number.isFinite(normalizedId) || normalizedId <= 0) {
                                clearModalMode();
                                return;
                            }

                            if (modalMode === 'resubmit') {
                                resubmitIdInput.value = String(normalizedId);
                                editIdInput.value = '';
                            } else {
                                editIdInput.value = String(normalizedId);
                                resubmitIdInput.value = '';
                            }

                            applyModalModeUI();
                        };

                        const allPaths = (topicSelect
                            ? Array.from(topicSelect.options).map(option => option.value.trim()).filter(Boolean)
                            : Array.isArray(phpTopicPaths) ? phpTopicPaths.map(path => String(path || '').trim()).filter(Boolean) : []);

                        const tree = {};
                        allPaths.forEach(path => {
                            const parts = path.split(' / ').map(part => part.trim()).filter(Boolean);
                            if (!parts.length) {
                                return;
                            }

                            const main = parts[0];
                            const section = parts[1] || '';
                            const under = parts[2] || '';

                            if (!tree[main]) {
                                tree[main] = { sections: new Set(), under: {} };
                            }

                            if (section) {
                                tree[main].sections.add(section);
                                if (!tree[main].under[section]) {
                                    tree[main].under[section] = new Set();
                                }
                                if (under) {
                                    tree[main].under[section].add(under);
                                }
                            }
                        });

                        const setTopicPath = function () {
                            const main = mainSelect.value.trim();
                            const section = sectionSelect.value.trim();
                            const under = underSelect.value.trim();

                            if (!main) {
                                topicHiddenInput.value = '';
                                return;
                            }

                            if (under) {
                                topicHiddenInput.value = main + ' / ' + section + ' / ' + under;
                                return;
                            }

                            if (section && section.toLowerCase().indexOf('no section') === -1) {
                                topicHiddenInput.value = main + ' / ' + section;
                                return;
                            }

                            topicHiddenInput.value = main;
                        };

                        const fillMainOptions = function () {
                            mainSelect.innerHTML = '<option value="">Select Main Topic</option>';
                            Object.keys(tree).sort().forEach(main => {
                                const option = document.createElement('option');
                                option.value = main;
                                option.textContent = main;
                                mainSelect.appendChild(option);
                            });
                        };

                        const fillSectionOptions = function (main) {
                            sectionSelect.innerHTML = '<option value="">Select Section</option>';
                            underSelect.innerHTML = '<option value="">Select Under Section</option>';
                            underSelect.disabled = true;

                            if (!main || !tree[main]) {
                                sectionSelect.disabled = true;
                                setTopicPath();
                                return;
                            }

                            const sections = Array.from(tree[main].sections).sort();
                            if (!sections.length) {
                                sectionSelect.innerHTML = '<option value="">No section available</option>';
                                sectionSelect.disabled = false;
                                underSelect.innerHTML = '<option value="">No under section available</option>';
                                underSelect.disabled = false;
                                setTopicPath();
                                return;
                            }

                            sections.forEach(section => {
                                const option = document.createElement('option');
                                option.value = section;
                                option.textContent = section;
                                sectionSelect.appendChild(option);
                            });

                            sectionSelect.disabled = false;
                            setTopicPath();
                        };

                        const fillUnderOptions = function (main, section) {
                            underSelect.innerHTML = '<option value="">Select Under Section</option>';

                            if (!main || !section || !tree[main] || !tree[main].under[section]) {
                                underSelect.innerHTML = '<option value="">No under section available</option>';
                                underSelect.disabled = false;
                                setTopicPath();
                                return;
                            }

                            const underSections = Array.from(tree[main].under[section]).sort();
                            if (!underSections.length) {
                                underSelect.innerHTML = '<option value="">No under section available</option>';
                                underSelect.disabled = false;
                                setTopicPath();
                                return;
                            }

                            underSections.forEach(under => {
                                const option = document.createElement('option');
                                option.value = under;
                                option.textContent = under;
                                underSelect.appendChild(option);
                            });

                            underSelect.disabled = false;
                            setTopicPath();
                        };

                        const resetModalState = function () {
                            form.reset();
                            mainSelect.value = '';
                            fillSectionOptions('');
                            underSelect.innerHTML = '<option value="">Select Under Section</option>';
                            underSelect.disabled = true;
                            topicHiddenInput.value = '';
                            clearModalMode();
                        };

                        const detectActiveDashboardTopic = function () {
                            const activeLeaf = document.querySelector('#dashboardTopicList .topic-subitem-leaf.active');
                            if (activeLeaf && activeLeaf.dataset && activeLeaf.dataset.value) {
                                return String(activeLeaf.dataset.value).trim();
                            }

                            const activeContent = document.querySelector('#dashboardTopicList .topic-subitem-content.is-visible');
                            if (activeContent && activeContent.dataset && activeContent.dataset.topicPath) {
                                return String(activeContent.dataset.topicPath).trim();
                            }

                            const activeCard = document.querySelector('#dashboardTopicList .topic-template-card.active');
                            if (activeCard && activeCard.dataset && activeCard.dataset.topic) {
                                return String(activeCard.dataset.topic).trim();
                            }

                            return '';
                        };

                        fillMainOptions();
                        resetModalState();

                        window.openUserUploadModalWithTopic = function (topicPath) {
                            overlay.style.display = 'flex';

                            const resolvedTopicPath = String(topicPath || '').trim() || detectActiveDashboardTopic();
                            const parts = String(resolvedTopicPath || '')
                                .split(' / ')
                                .map(part => part.trim())
                                .filter(Boolean);

                            if (!parts.length) {
                                const firstMainOption = Array.from(mainSelect.options).find(option => option.value && option.value.trim() !== '');
                                if (firstMainOption) {
                                    mainSelect.value = firstMainOption.value;
                                    fillSectionOptions(mainSelect.value.trim());
                                }
                                setTopicPath();
                                return;
                            }

                            mainSelect.value = parts[0] || '';
                            fillSectionOptions(mainSelect.value.trim());

                            if (parts[1]) {
                                const sectionOption = Array.from(sectionSelect.options).find(option => option.value === parts[1]);
                                if (sectionOption) {
                                    sectionSelect.value = parts[1];
                                }
                            }

                            fillUnderOptions(mainSelect.value.trim(), sectionSelect.value.trim());

                            if (parts[2]) {
                                const underOption = Array.from(underSelect.options).find(option => option.value === parts[2]);
                                if (underOption) {
                                    underSelect.value = parts[2];
                                }
                            }

                            setTopicPath();
                        };

                        openBtn.addEventListener('click', function () {
                            clearModalMode();
                            window.openUserUploadModalWithTopic(detectActiveDashboardTopic());
                        });

                        closeBtn.addEventListener('click', function () {
                            overlay.style.display = 'none';
                            resetModalState();
                        });

                        overlay.addEventListener('click', function (event) {
                            if (event.target === overlay) {
                                overlay.style.display = 'none';
                                resetModalState();
                            }
                        });

                        mainSelect.addEventListener('change', function () {
                            fillSectionOptions(mainSelect.value.trim());
                            underSelect.value = '';
                            setTopicPath();
                        });

                        sectionSelect.addEventListener('change', function () {
                            fillUnderOptions(mainSelect.value.trim(), sectionSelect.value.trim());
                            underSelect.value = '';
                            setTopicPath();
                        });

                        underSelect.addEventListener('change', setTopicPath);

                        form.addEventListener('submit', function (event) {
                            event.preventDefault();
                            setTopicPath();

                            if (!fileInput.files.length) {
                                alert('Please choose a file to upload.');
                                return;
                            }

                            if (!topicHiddenInput.value.trim()) {
                                const firstMainOption = Array.from(mainSelect.options).find(option => option.value && option.value.trim() !== '');
                                if (firstMainOption) {
                                    mainSelect.value = firstMainOption.value;
                                    fillSectionOptions(mainSelect.value.trim());
                                    setTopicPath();
                                }
                            }

                            if (!topicHiddenInput.value.trim()) {
                                alert('Please choose Main Topic first.');
                                return;
                            }

                            const formData = new FormData();
                            formData.append('files[]', fileInput.files[0]);
                            formData.append('topic', topicHiddenInput.value.trim());
                            if (resubmitIdInput.value) {
                                formData.append('resubmit_file_id', resubmitIdInput.value);
                            }
                            if (editIdInput.value) {
                                formData.append('edit_file_id', editIdInput.value);
                            }

                            fetch('upload.php', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    if (modalMode === 'resubmit') {
                                        alert('File resubmitted successfully.');
                                    } else if (modalMode === 'edit') {
                                        alert('File updated successfully.');
                                    } else {
                                        alert('File uploaded successfully.');
                                    }
                                    overlay.style.display = 'none';
                                    resetModalState();
                                    location.reload();
                                } else {
                                    alert(data.message || 'Upload failed.');
                                }
                            })
                            .catch(function () {
                                alert('Upload error.');
                            });
                        });
                    }

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', initUserUploadModal);
                    } else {
                        initUserUploadModal();
                    }
                    </script>
                    <?php endif; ?>
                    <div class="help-center-card">
                        <?php if ($canUpload): ?>
                            <div class="dashboard-upload-bar">
                                <button id="openUserUploadModal" class="btn-primary">Upload File</button>
                            </div>
                        <?php endif; ?>
                        <div class="topic-template-list help-topic-list" id="dashboardTopicList">
                            <?php if (empty($displayTopics)): ?>
                                <div class="empty-help-state">
                                    <h3>No sections added yet</h3>
                                    <p>Add the exact main sections and subsections you need from the admin page.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($displayTopics as $topic): ?>
                                    <?php
                                        $topicIcon = '📁';
                                        if (stripos($topic, 'instruction') !== false) {
                                            $topicIcon = '📝';
                                        } elseif (stripos($topic, 'procedure') !== false) {
                                            $topicIcon = '📋';
                                        } elseif (stripos($topic, 'specification') !== false) {
                                            $topicIcon = '🧾';
                                        } elseif (stripos($topic, 'template') !== false) {
                                            $topicIcon = '📄';
                                        }
                                        $subsections = $topicHierarchy[$topic] ?? [];
                                    ?>
                                    <div class="topic-template-card help-topic-card" data-topic="<?php echo htmlspecialchars($topic); ?>">
                                        <button type="button" class="topic-template-header" aria-expanded="false" onclick='toggleTopicTemplate(this, <?php echo json_encode($topic); ?>)'>
                                            <span class="topic-template-meta">
                                                <span class="topic-template-icon"><?php echo $topicIcon; ?></span>
                                                <span class="topic-template-title"><?php echo htmlspecialchars($topic); ?></span>
                                            </span>
                                            <span class="topic-template-arrow">▾</span>
                                        </button>
                                        <div class="topic-template-body">
                                            <div>
                                                <?php if (!empty($subsections)): ?>
                                                    <?php renderDashboardTopicButtons($subsections, $topic, $topicFileMap, $userTopicFileMap, $userTopicFeedbackMap, $canUpload, $canDelete); ?>
                                                <?php endif; ?>

                                                <?php renderTopicPathContent($topic, $topicFileMap, $userTopicFileMap, $userTopicFeedbackMap, $canUpload, $canDelete); ?>

                                                <?php if (!topicTreeHasContent($topic, $topicFileMap, $userTopicFileMap, $userTopicFeedbackMap)): ?>
                                                    <p class="notification-empty">No files yet in this section.</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                </section>

                <!-- HELP CENTER UPLOAD PAGE -->
                <?php if ($canUpload): ?>
                <section id="upload" class="page-section">
                    <div class="card">
                        <h2>🗂️ Upload to Help Center</h2>
                        <form id="uploadForm" class="upload-form" enctype="multipart/form-data">
                            <div id="resubmitBanner" class="resubmit-banner" aria-live="polite">
                                <strong id="resubmitBannerTitle">Resubmitting file</strong>
                                <p id="resubmitBannerText">Choose the corrected file and upload it again for admin review.</p>
                                <button type="button" class="upload-feedback-action" onclick="clearResubmitState()">Cancel resubmit</button>
                            </div>
                            <input type="hidden" id="resubmitFileId" name="resubmit_file_id" value="">
                            <input type="hidden" id="editFileId" name="edit_file_id" value="">
                            <label class="form-label" for="topicSelect">Choose a section or subsection</label>
                            <div class="topic-template-list" id="topicTemplateList">
                                <?php if (empty($displayTopics)): ?>
                                    <div class="empty-help-state compact-empty-state">
                                        <h3>No upload sections yet</h3>
                                        <p>The admin can add a main section and subsection first.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($displayTopics as $topic): ?>
                                        <?php
                                            $topicIcon = '📁';
                                            if (stripos($topic, 'instruction') !== false) {
                                                $topicIcon = '📝';
                                            } elseif (stripos($topic, 'procedure') !== false) {
                                                $topicIcon = '📋';
                                            } elseif (stripos($topic, 'specification') !== false) {
                                                $topicIcon = '🧾';
                                            } elseif (stripos($topic, 'template') !== false) {
                                                $topicIcon = '📄';
                                            }
                                            $subsections = $topicHierarchy[$topic] ?? [];
                                        ?>
                                        <div class="topic-template-card" data-topic="<?php echo htmlspecialchars($topic); ?>">
                                            <button type="button" class="topic-template-header" aria-expanded="false" onclick='toggleTopicTemplate(this, <?php echo json_encode($topic); ?>)'>
                                                <span class="topic-template-meta">
                                                    <span class="topic-template-icon"><?php echo $topicIcon; ?></span>
                                                    <span class="topic-template-title"><?php echo htmlspecialchars($topic); ?></span>
                                                </span>
                                                <span class="topic-template-arrow">▾</span>
                                            </button>
                                            <div class="topic-template-body">
                                                <div>
                                                    <?php if (!empty($subsections)): ?>
                                                        <?php renderNestedTopicButtons($subsections, $topic, 'selectTopicTemplate'); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <select id="topicSelect" name="topic" required class="topic-hidden-select">
                                <option value="">Choose a section or subsection</option>
                                <?php foreach ($displayTopics as $topic): ?>
                                    <option value="<?php echo htmlspecialchars($topic); ?>"><?php echo htmlspecialchars($topic); ?></option>
                                    <?php renderNestedTopicOptions($topicHierarchy[$topic] ?? [], $topic); ?>
                                <?php endforeach; ?>
                            </select>
                            <div class="topic-location-panel" id="topicLocationPanel" aria-live="polite">
                                <div class="topic-location-item">
                                    <span class="topic-location-key">Main Topic</span>
                                    <strong id="mainTopicLabel">Not selected</strong>
                                </div>
                                <div class="topic-location-item">
                                    <span class="topic-location-key">Section</span>
                                    <strong id="sectionTopicLabel">Not selected</strong>
                                </div>
                                <div class="topic-location-item">
                                    <span class="topic-location-key">Under Section</span>
                                    <strong id="underSectionTopicLabel">Not selected</strong>
                                </div>
                            </div>
                            <div class="upload-area" id="uploadArea">
                                <div id="uploadPreview">
                                    <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                        <polyline points="17 8 12 3 7 8"></polyline>
                                        <line x1="12" y1="3" x2="12" y2="15"></line>
                                    </svg>
                                    <h3>Drag & Drop Files Here</h3>
                                    <p>or click to browse</p>
                                </div>
                            </div>
                            <input type="file" id="fileInput" name="files[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt,.zip" hidden>
                            <div class="upload-action-row">
                                <button type="submit" class="btn-primary">📤 Upload File</button>
                                <button type="button" id="cancelUploadButton" class="upload-cancel-btn" hidden>Cancel Upload</button>
                            </div>
                        </form>
                    </div>
                </section>
                <?php endif; ?>

                <!-- MY DOCUMENTS PAGE -->
                <?php if (!$isViewer): ?>
                <section id="myfiles" class="page-section">
                    <div class="card">
                        <h2>📋 My Documents</h2>
                        <div class="filter-bar">
                            <input type="text" id="searchFiles" placeholder="🔍 Search my documents...">
                            <?php if ($hasCategory): ?>
                                <select id="filterTopicUser">
                                    <option value="">All Topics</option>
                                    <?php foreach ($displayTopics as $topic): ?>
                                        <option value="<?php echo htmlspecialchars(strtolower($topic)); ?>"><?php echo htmlspecialchars($topic); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="table-container">
                            <table class="data-table" id="filesTable">
                                <thead>
                                    <tr>
                                        <th>Document Name</th>
                                        <?php if ($hasCategory): ?>
                                            <th>Topic</th>
                                        <?php endif; ?>
                                        <th>Size</th>
                                        <th>Updated</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($files)): ?>
                                        <tr>
                                            <td colspan="<?php echo $hasCategory ? 6 : 5; ?>" class="text-center">No documents submitted yet</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($files as $file): ?>
                                            <?php $hasStoredFile = isStoredDocumentAvailable($file); ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($file['file_name']); ?></td>
                                                <?php if ($hasCategory): ?>
                                                    <td><?php echo htmlspecialchars($file['category'] ?? '—'); ?></td>
                                                <?php endif; ?>
                                                <td><?php echo number_format($file['file_size'] / 1024, 2); ?> KB</td>
                                                <?php $updatedAt = !empty($file['approved_at']) ? $file['approved_at'] : $file['uploaded_at']; ?>
                                                <td><?php echo date('M d, Y H:i', strtotime($updatedAt)); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo formatFileStatusClass($file['status']); ?>">
                                                        <?php echo formatFileStatusLabel($file['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="table-action-group">
                                                        <?php if ($hasStoredFile): ?>
                                                            <a class="btn-small" href="view_file.php?file_id=<?php echo $file['id']; ?>" target="_blank">
                                                                👁️ View
                                                            </a>
                                                            <a class="btn-primary" href="download.php?file_id=<?php echo $file['id']; ?>" target="_blank">
                                                                ⬇️ Download
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="btn-small" style="opacity:.75; cursor:default;">Missing on server</span>
                                                        <?php endif; ?>
                                                        <?php if ($canUpload && normalizeFileStatus($file['status']) !== 'archived'): ?>
                                                        <button class="btn-edit" type="button" onclick='openEditUpload(<?php echo (int) $file["id"]; ?>, <?php echo json_encode($file["category"] ?? ""); ?>, <?php echo json_encode($file["file_name"]); ?>)'>✏️ Edit</button>
                                                        <?php endif; ?>
                                                        <?php if ($canDelete): ?>
                                                        <button class="btn-delete" onclick="deleteFile(<?php echo $file['id']; ?>)">
                                                            🗑️ Delete
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <div class="bottom-logout-wrap">
        <?php if ($isViewer): ?>
            <button type="button" class="bottom-logout-btn" data-login-trigger>Login</button>
        <?php else: ?>
            <a href="logout.php" class="bottom-logout-btn">Logout</a>
        <?php endif; ?>
    </div>

    <?php if ($isViewer): ?>
    <div class="viewer-login-modal-overlay" id="viewerLoginModal">
        <div class="viewer-login-modal">
            <button class="modal-close" id="modalClose" type="button" aria-label="Close">&times;</button>
            <div class="modal-logo">
                <img src="logo.svg" alt="HelpDesk">
            </div>
            <h2>Login</h2>
            <div class="modal-error" id="modalError" style="display:<?php echo $showModalLoginError ? 'block' : 'none'; ?>">Invalid username or password.</div>
            <form method="POST" action="index.php" id="viewerLoginForm">
                <input type="hidden" name="modal_login" value="1">
                <div class="modal-field">
                    <label for="modalUsername">Username</label>
                    <input type="text" id="modalUsername" name="username" value="<?php echo htmlspecialchars($modalLoginUsername); ?>" required autocomplete="username">
                </div>
                <div class="modal-field">
                    <label for="modalPassword">Password</label>
                    <input type="password" id="modalPassword" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="modal-btn-login">Login</button>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var overlay = document.getElementById('viewerLoginModal');
        var closeBtn = document.getElementById('modalClose');
        var triggers = document.querySelectorAll('[data-login-trigger]');
        var autoOpenWithError = <?php echo $showModalLoginError ? 'true' : 'false'; ?>;
        triggers.forEach(function (btn) {
            btn.addEventListener('click', function () {
                overlay.classList.add('open');
                document.getElementById('modalUsername').focus();
            });
        });
        closeBtn.addEventListener('click', function () {
            overlay.classList.remove('open');
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.classList.remove('open');
        });

        if (autoOpenWithError) {
            overlay.classList.add('open');
            document.getElementById('modalUsername').focus();

            var currentUrl = new URL(window.location.href);
            currentUrl.searchParams.delete('login_error');
            currentUrl.searchParams.delete('login_username');
            history.replaceState({}, document.title, currentUrl.pathname + currentUrl.search + currentUrl.hash);
        }
    })();
    </script>
    <?php endif; ?>
    <script src="script.js?v=<?php echo filemtime(__DIR__.'/script.js'); ?>"></script>
    <?php if ($autoReplaceFile): ?>
        <script>
            (function () {
                var replaceFileId = <?php echo (int) $autoReplaceFile['id']; ?>;
                var replaceTopic = <?php echo json_encode((string) ($autoReplaceFile['category'] ?? '')); ?>;
                var replaceFileName = <?php echo json_encode((string) ($autoReplaceFile['file_name'] ?? '')); ?>;

                var run = function () {
                    if (typeof openEditUpload === 'function') {
                        openEditUpload(replaceFileId, replaceTopic, replaceFileName);
                    }
                };

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', run);
                } else {
                    run();
                }
            })();
        </script>
    <?php endif; ?>
    <?php if (!empty($flashMessage)): ?>
        <script>
            alert('<?php echo addslashes($flashMessage); ?>');
        </script>
    <?php endif; ?>
</body>
</html>