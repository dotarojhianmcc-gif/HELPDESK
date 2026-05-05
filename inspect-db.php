<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=helpdesk', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query('SHOW TABLES');
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        echo $row[0] . "\n";
    }
} catch (Exception $e) {
    echo 'ERROR: ' . $e->getMessage();
}
?>