<?php
// db.php

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'helpdesk');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (PDOException $e) {
    die('Database Connection Error: ' . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
}

function tableColumnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function ensureCategoryColumn(PDO $pdo): void {
    if (!tableColumnExists($pdo, 'files', 'category')) {
        try {
            $pdo->exec("ALTER TABLE files ADD COLUMN category VARCHAR(100) NULL AFTER file_size");
        } catch (Exception $e) {
            // Ignore if alter fails; category support remains optional.
        }
    }
}

function ensureFileStatusSupport(PDO $pdo): void {
    if (!tableExists($pdo, 'files') || !tableColumnExists($pdo, 'files', 'status')) {
        return;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM files LIKE 'status'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $columnType = strtolower((string) ($column['Type'] ?? ''));

        if (strpos($columnType, 'rejected') === false || strpos($columnType, 'archived') === false) {
            $pdo->exec("ALTER TABLE files MODIFY COLUMN status ENUM('pending','approved','rejected','archived','disapproved') NOT NULL DEFAULT 'pending'");
        }
    } catch (Exception $e) {
        // Ignore schema adjustment issues so the app can continue using existing statuses.
    }
}

function normalizeFileStatus(?string $status): string {
    $normalized = strtolower(trim((string) $status));

    if ($normalized === 'disapproved') {
        return 'rejected';
    }

    return in_array($normalized, ['pending', 'approved', 'rejected', 'archived'], true)
        ? $normalized
        : 'pending';
}

function formatFileStatusClass(?string $status): string {
    return normalizeFileStatus($status);
}

function getUploadsDirectory(): string {
    $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';

    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }

    return $uploadsDir;
}

function resolveStoredFilePath(?string $storedPath): ?string {
    $storedPath = trim((string) $storedPath);
    if ($storedPath === '') {
        return null;
    }

    $candidates = [];
    $isAbsoluteWindowsPath = (bool) preg_match('/^[A-Za-z]:[\\/]/', $storedPath);
    $isAbsoluteUnixPath = str_starts_with($storedPath, '/') || str_starts_with($storedPath, '\\');

    if ($isAbsoluteWindowsPath || $isAbsoluteUnixPath) {
        $candidates[] = $storedPath;
    } else {
        $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storedPath);
        $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . $relativePath;
        $candidates[] = getUploadsDirectory() . DIRECTORY_SEPARATOR . basename($relativePath);

        // Fallbacks for environments where runtime root differs from edit/deploy root.
        $workspaceRoot = realpath(__DIR__);
        $parentRoot = $workspaceRoot ? dirname($workspaceRoot) : null;
        if ($parentRoot) {
            $candidates[] = $parentRoot . DIRECTORY_SEPARATOR . 'htdocs' . DIRECTORY_SEPARATOR . 'helpdesk' . DIRECTORY_SEPARATOR . $relativePath;
            $candidates[] = $parentRoot . DIRECTORY_SEPARATOR . 'htdocs' . DIRECTORY_SEPARATOR . 'helpdesk' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($relativePath);
        }

        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            $candidates[] = 'C:\\xampp\\htdocs\\helpdesk\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
            $candidates[] = 'C:\\xampp\\htdocs\\helpdesk\\uploads\\' . basename($relativePath);
        }
    }

    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved && is_file($resolved)) {
            return $resolved;
        }
    }

    return null;
}

function formatFileStatusLabel(?string $status): string {
    switch (normalizeFileStatus($status)) {
        case 'approved':
            return '✅ Approved';
        case 'rejected':
            return '❌ Rejected';
        case 'archived':
            return '🗄️ Archived';
        default:
            return '⏳ Pending';
    }
}

function ensureTopicsTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS topics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // Ignore initialization errors.
    }
}

function loadTopics(): array {
    global $pdo;

    if (tableExists($pdo, 'topics')) {
        try {
            ensureTopicsTable($pdo);
            $stmt = $pdo->query("SELECT name FROM topics ORDER BY id ASC");
            $topics = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return is_array($topics) ? $topics : [];
        } catch (Exception $e) {
            // Fall back to JSON
        }
    }

    $file = __DIR__ . '/topics.json';
    if (!file_exists($file)) {
        $default = ['Working Instructions', 'Standard Operating Procedures', 'Functional Specifications', 'Templates'];
        file_put_contents($file, json_encode($default, JSON_PRETTY_PRINT));
        return $default;
    }

    $content = file_get_contents($file);
    $topics = json_decode($content, true);
    return is_array($topics) ? $topics : ['WI', 'SOP', 'FS', 'TEMP'];
}

function saveTopics(array $topics): bool {
    global $pdo;

    if (tableExists($pdo, 'topics')) {
        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM topics");
            $insert = $pdo->prepare("INSERT IGNORE INTO topics (name) VALUES (?)");
            foreach (array_values($topics) as $topic) {
                $insert->execute([$topic]);
            }
            $pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Fall back to JSON storage if DB save fails.
        }
    }

    $file = __DIR__ . '/topics.json';
    return file_put_contents($file, json_encode(array_values($topics), JSON_PRETTY_PRINT)) !== false;
}

ensureCategoryColumn($pdo);
ensureFileStatusSupport($pdo);
?>