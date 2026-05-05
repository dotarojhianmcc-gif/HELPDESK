<?php
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$fileId = isset($_GET['file_id']) ? (int) $_GET['file_id'] : 0;
if (!$fileId) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid file ID');
}

$stmt = $pdo->prepare("
    SELECT f.id, f.user_id, f.file_name, f.file_path, f.file_size, f.status, f.rejection_reason, u.role AS owner_role, u.username
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

$filePath = resolveStoredFilePath($file['file_path'] ?? '');
if (!$filePath || !is_file($filePath)) {
    if (($_SESSION['role'] ?? '') === 'user' && $isOwnFile) {
        header('Location: user.php?replace_file_id=' . $fileId . '&replace_missing=1');
        exit;
    }
    header('HTTP/1.1 404 Not Found');
    exit('File not found on server. Please re-upload or resubmit this document.');
}

function normalizePreviewText(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = str_replace("\0", ' ', $text);

    if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
        $encoding = mb_detect_encoding($text, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $text = mb_convert_encoding($text, 'UTF-8', $encoding);
        }
    }

    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/u', ' ', (string) $text);
    $text = preg_replace('/[ \t]{3,}/u', '  ', (string) $text);
    $text = preg_replace('/\n{4,}/u', "\n\n\n", (string) $text);

    return trim((string) $text);
}

function extractPlainTextPreview(string $filePath, int $maxBytes = 250000): string
{
    $content = @file_get_contents($filePath, false, null, 0, $maxBytes);
    if ($content === false || $content === '') {
        return '';
    }

    return normalizePreviewText((string) $content);
}

function extractLegacyOfficePreviewText(string $filePath, int $maxBytes = 350000): string
{
    $content = @file_get_contents($filePath, false, null, 0, $maxBytes);
    if ($content === false || $content === '') {
        return '';
    }

    $content = str_replace("\0", ' ', (string) $content);
    preg_match_all('/[A-Za-z0-9][A-Za-z0-9\s\.,:\/\\\-_()\[\]"\']{3,}/', $content, $matches);

    $parts = [];
    foreach (($matches[0] ?? []) as $match) {
        $line = trim((string) $match);
        if ($line !== '' && strlen($line) >= 4) {
            $parts[] = $line;
        }
        if (count($parts) >= 120) {
            break;
        }
    }

    if (empty($parts)) {
        return '';
    }

    return normalizePreviewText(implode("\n", array_unique($parts)));
}

function extractDocxPreviewText(string $filePath): string
{
    if (!class_exists('ZipArchive')) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return '';
    }

    $xmlContent = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xmlContent === false || trim($xmlContent) === '') {
        return '';
    }

    $xmlContent = strtr($xmlContent, [
        '</w:p>' => "\n\n",
        '</w:tr>' => "\n",
        '</w:tc>' => "\t",
        '<w:tab/>' => "\t",
        '<w:tab />' => "\t",
        '<w:br/>' => "\n",
        '<w:br />' => "\n",
        '<w:cr/>' => "\n"
    ]);

    $text = strip_tags($xmlContent);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

    return normalizePreviewText((string) $text);
}

function extractXlsxPreviewHtml(string $filePath): string
{
    if (!class_exists('ZipArchive')) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return '';
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sharedData = @simplexml_load_string($sharedXml);
        if ($sharedData && isset($sharedData->si)) {
            foreach ($sharedData->si as $item) {
                $textParts = [];
                if (isset($item->t)) {
                    $textParts[] = (string) $item->t;
                }
                if (isset($item->r)) {
                    foreach ($item->r as $run) {
                        $textParts[] = (string) ($run->t ?? '');
                    }
                }
                $sharedStrings[] = trim(implode('', $textParts));
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheetXml === false || trim($sheetXml) === '') {
        return '';
    }

    $sheetData = @simplexml_load_string($sheetXml);
    if (!$sheetData || !isset($sheetData->sheetData)) {
        return '';
    }

    $rows = [];
    foreach ($sheetData->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            $value = (string) ($cell->v ?? '');
            $type = (string) ($cell['t'] ?? '');

            if ($type === 's') {
                $value = $sharedStrings[(int) $value] ?? $value;
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
            }

            $cells[] = '<td>' . htmlspecialchars(trim((string) $value), ENT_QUOTES, 'UTF-8') . '</td>';
        }

        if (!empty($cells)) {
            $rows[] = '<tr>' . implode('', $cells) . '</tr>';
        }

        if (count($rows) >= 25) {
            break;
        }
    }

    if (empty($rows)) {
        return '';
    }

    return '<div class="preview-table-shell"><table class="preview-table"><tbody>' . implode('', $rows) . '</tbody></table></div>';
}

function extractZipListingHtml(string $filePath): string
{
    if (!class_exists('ZipArchive')) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return '';
    }

    $items = [];
    $limit = min($zip->numFiles, 100);
    for ($index = 0; $index < $limit; $index++) {
        $stat = $zip->statIndex($index);
        if (!$stat || !isset($stat['name'])) {
            continue;
        }

        $name = (string) $stat['name'];
        $isDirectory = substr($name, -1) === '/';
        $sizeLabel = $isDirectory ? 'Folder' : number_format(((int) ($stat['size'] ?? 0)) / 1024, 1) . ' KB';

        $items[] = '<li><span>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span><strong>' . htmlspecialchars($sizeLabel, ENT_QUOTES, 'UTF-8') . '</strong></li>';
    }

    $zip->close();

    if (empty($items)) {
        return '';
    }

    return '<div class="preview-note">ZIP archive contents preview.</div><ul class="archive-list">' . implode('', $items) . '</ul>';
}

$fileName = (string) ($file['file_name'] ?? 'Document');
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$inlineBrowserExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
$textExtensions = ['txt', 'csv', 'json', 'xml', 'md', 'log', 'ini', 'htm', 'html', 'css', 'js', 'sql'];
$isImagePreview = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
$isBrowserInline = in_array($extension, $inlineBrowserExtensions, true);
$inlineSource = 'download.php?file_id=' . $fileId . '&view=1';
$textPreview = in_array($extension, $textExtensions, true) ? extractPlainTextPreview($filePath) : '';
$docxPreviewText = $extension === 'docx' ? extractDocxPreviewText($filePath) : '';
$xlsxPreviewHtml = $extension === 'xlsx' ? extractXlsxPreviewHtml($filePath) : '';
$legacyOfficePreview = in_array($extension, ['doc', 'xls'], true) ? extractLegacyOfficePreviewText($filePath) : '';
$zipPreviewHtml = $extension === 'zip' ? extractZipListingHtml($filePath) : '';
$statusLabel = function_exists('formatFileStatusLabel')
    ? formatFileStatusLabel($file['status'] ?? '')
    : ucfirst((string) ($file['status'] ?? ''));
$fileSizeLabel = !empty($file['file_size']) ? number_format(((int) $file['file_size']) / 1024, 1) . ' KB' : 'Unknown size';
$extensionLabel = $extension !== '' ? strtoupper($extension) : 'FILE';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Document - Support HelpDesk</title>
    <link rel="icon" type="image/svg+xml" href="logo.svg?v=20260408e">
    <link rel="shortcut icon" href="logo.svg?v=20260408e">
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #22324a;
        }
        .viewer-shell {
            max-width: 1100px;
            margin: 24px auto;
            padding: 0 16px 24px;
        }
        .viewer-card {
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 14px 40px rgba(31, 45, 61, 0.12);
            overflow: hidden;
        }
        .viewer-header {
            padding: 22px 24px;
            border-bottom: 1px solid #e6edf6;
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .viewer-header h1 {
            margin: 0 0 8px;
            font-size: 24px;
        }
        .file-meta {
            display: flex;
            gap: 8px 12px;
            flex-wrap: wrap;
            color: #5e6c84;
            font-size: 14px;
        }
        .file-meta span {
            background: #f3f7fb;
            border-radius: 999px;
            padding: 6px 10px;
        }
        .viewer-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 600;
        }
        .btn-primary {
            background: #2563eb;
            color: #fff;
        }
        .btn-secondary {
            background: #eef2ff;
            color: #22324a;
        }
        .preview-area {
            padding: 24px;
        }
        .preview-frame {
            width: 100%;
            min-height: 72vh;
            border: 1px solid #dfe7f2;
            border-radius: 12px;
            background: #fff;
        }
        .preview-image {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
            border-radius: 12px;
            border: 1px solid #dfe7f2;
            background: #fff;
        }
        .preview-rich {
            white-space: pre-wrap;
            line-height: 1.65;
            padding: 18px;
            border: 1px solid #dfe7f2;
            border-radius: 12px;
            background: #fbfdff;
            max-height: 72vh;
            overflow: auto;
        }
        .preview-note {
            margin-bottom: 12px;
            color: #5e6c84;
            font-size: 13px;
        }
        .preview-table-shell {
            overflow: auto;
            border: 1px solid #dfe7f2;
            border-radius: 12px;
            background: #fff;
        }
        .preview-table {
            width: 100%;
            border-collapse: collapse;
        }
        .preview-table td {
            border: 1px solid #e6edf6;
            padding: 8px 10px;
            font-size: 14px;
        }
        .archive-list {
            list-style: none;
            margin: 0;
            padding: 0;
            border: 1px solid #dfe7f2;
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }
        .archive-list li {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            border-bottom: 1px solid #edf2f8;
            font-size: 14px;
        }
        .archive-list li:last-child {
            border-bottom: none;
        }
        .archive-list span {
            word-break: break-word;
        }
        .empty-preview {
            padding: 32px 20px;
            text-align: center;
            background: #f9fbff;
            border: 1px dashed #c8d7ea;
            border-radius: 12px;
        }
        .empty-preview p {
            margin: 0 0 14px;
            color: #5e6c84;
        }
    </style>
</head>
<body>
    <div class="viewer-shell">
        <div class="viewer-card">
            <div class="viewer-header">
                <div>
                    <h1><?php echo htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <div class="file-meta">
                        <span>Uploaded by <?php echo htmlspecialchars((string) ($file['username'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>Status: <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>Type: <?php echo htmlspecialchars($extensionLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>Size: <?php echo htmlspecialchars($fileSizeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div class="viewer-actions">
                    <a class="btn btn-secondary" href="javascript:window.close()">✖ Close</a>
                    <a class="btn btn-primary" href="download.php?file_id=<?php echo $fileId; ?>" target="_blank">⬇️ Download</a>
                </div>
            </div>

            <div class="preview-area">
                <?php if ($isImagePreview): ?>
                    <img class="preview-image" src="<?php echo htmlspecialchars($inlineSource, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8'); ?>">
                <?php elseif ($isBrowserInline): ?>
                    <iframe class="preview-frame" src="<?php echo htmlspecialchars($inlineSource, ENT_QUOTES, 'UTF-8'); ?>" title="Preview of <?php echo htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8'); ?>"></iframe>
                <?php elseif ($textPreview !== ''): ?>
                    <div class="preview-note">Text preview loaded for your uploaded file.</div>
                    <div class="preview-rich"><?php echo nl2br(htmlspecialchars($textPreview, ENT_QUOTES, 'UTF-8')); ?></div>
                <?php elseif ($docxPreviewText !== ''): ?>
                    <div class="preview-note">DOCX preview extracted from your uploaded file.</div>
                    <div class="preview-rich"><?php echo nl2br(htmlspecialchars($docxPreviewText, ENT_QUOTES, 'UTF-8')); ?></div>
                <?php elseif ($xlsxPreviewHtml !== ''): ?>
                    <div class="preview-note">Spreadsheet preview showing the first available rows.</div>
                    <?php echo $xlsxPreviewHtml; ?>
                <?php elseif ($legacyOfficePreview !== ''): ?>
                    <div class="preview-note">Legacy Office preview extracted from your uploaded file.</div>
                    <div class="preview-rich"><?php echo nl2br(htmlspecialchars($legacyOfficePreview, ENT_QUOTES, 'UTF-8')); ?></div>
                <?php elseif ($zipPreviewHtml !== ''): ?>
                    <?php echo $zipPreviewHtml; ?>
                <?php else: ?>
                    <div class="empty-preview">
                        <p>This file type cannot be fully rendered in the browser, but it is still accessible here for download.</p>
                        <a class="btn btn-primary" href="download.php?file_id=<?php echo $fileId; ?>" target="_blank">⬇️ Download File</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
