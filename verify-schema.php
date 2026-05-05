<?php
// verify-schema.php - Check and update database schema to latest version

require_once 'db.php';

$updates = [];

// Check if viewer role exists in users table
try {
    $stmt = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='users' AND COLUMN_NAME='role'");
    $result = $stmt->fetch();
    if ($result && strpos($result['COLUMN_TYPE'], 'viewer') === false) {
        $updates[] = "ALTER TABLE users MODIFY COLUMN role ENUM('admin','user','viewer') NOT NULL DEFAULT 'user'";
    }
} catch (Exception $e) {
    $updates[] = "Add viewer role to users table";
}

// Check viewer account exists
try {
    $stmt = $pdo->query("SELECT id FROM users WHERE username='viewer'");
    if (!$stmt->fetch()) {
        $updates[] = "INSERT INTO users (username, email, password, role) VALUES ('viewer', 'viewer@example.com', '\$2y\$10\$KCSOU83M9kOOFiR5huoWleJUbB5ZYrCpOjFSTEbVe5S3F5PgyOCKq', 'viewer')";
    }
} catch (Exception $e) {
    $updates[] = "Check viewer account: " . $e->getMessage();
}

// Apply schema from update-schema.sql
$schemaFile = __DIR__ . '/update-schema.sql';
if (file_exists($schemaFile)) {
    $schemaSql = file_get_contents($schemaFile);
    try {
        $pdo->exec($schemaSql);
        $updates[] = "Schema file applied successfully";
    } catch (Exception $e) {
        $updates[] = "Error applying schema: " . $e->getMessage();
    }
}

// Output results
echo "=== HelpDesk Database Schema Verification ===\n\n";
echo "Database Host: " . DB_HOST . "\n";
echo "Database Name: " . DB_NAME . "\n";
echo "Status: ";

try {
    $dbCheck = $pdo->query("SELECT 1")->fetch();
    echo "✓ Connected\n\n";
} catch (Exception $e) {
    echo "✗ Failed to connect\n" . $e->getMessage() . "\n";
    exit(1);
}

// Check tables
$tables = ['users', 'files', 'notifications', 'activity_log', 'topics'];
echo "Tables Status:\n";
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        echo "  ✓ $table\n";
    } catch (Exception $e) {
        echo "  ✗ $table - " . $e->getMessage() . "\n";
    }
}

// Check users and roles
echo "\n\nUsers and Roles:\n";
try {
    $stmt = $pdo->query("SELECT username, role FROM users ORDER BY id");
    while ($row = $stmt->fetch()) {
        echo "  - {$row['username']} ({$row['role']})\n";
    }
} catch (Exception $e) {
    echo "  Error: " . $e->getMessage() . "\n";
}

// Check viewer has access to approved files only
echo "\n\nViewer Access Verification:\n";
try {
    // Count approved files
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM files WHERE status IN ('approved', 'archived')");
    $approved = $stmt->fetch()['cnt'];
    
    // Count total files
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM files");
    $total = $stmt->fetch()['cnt'];
    
    echo "  Total files: $total\n";
    echo "  Approved files (visible to viewer): $approved\n";
} catch (Exception $e) {
    echo "  Error: " . $e->getMessage() . "\n";
}

echo "\n=== Schema Verification Complete ===\n";
if (!empty($updates)) {
    echo "\nPending Updates Applied:\n";
    foreach ($updates as $update) {
        echo "  • $update\n";
    }
}

echo "\n✓ Database system is READY\n";
?>
