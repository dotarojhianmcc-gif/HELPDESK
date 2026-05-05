<?php
// import-schema.php - Auto-import the database schema

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'helpdesk');

try {
    // Connect without specifying database to create it
    $pdo = new PDO(
        'mysql:host=' . DB_HOST,
        DB_USER,
        DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );

    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`");
    echo "Database 'helpdesk' created or already exists.\n";

    // Now connect to the database
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );

    $sql = file_get_contents(__DIR__ . '/update-schema.sql');

    if (!$sql) {
        die("Error: Could not read update-schema.sql\n");
    }

    // Split SQL into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
                echo "Executed: " . substr($statement, 0, 50) . "...\n";
            } catch (Exception $e) {
                echo "Warning: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\nSchema import completed!\n";

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}
?>