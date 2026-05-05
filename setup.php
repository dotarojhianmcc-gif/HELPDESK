<?php
// setup.php - Initialize the HelpDesk database and seed default users

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'helpdesk');

try {
    $pdo = new PDO('mysql:host=' . DB_HOST, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");

    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','user','viewer') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT NOT NULL,
    category VARCHAR(100) DEFAULT NULL,
    status ENUM('pending','approved','rejected','archived','disapproved') NOT NULL DEFAULT 'pending',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    approved_by INT NULL DEFAULT NULL,
    rejection_reason TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) DEFAULT 'info',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS topics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

    $pdo->exec($sql);

    $defaultTopics = ['Working Instructions', 'Standard Operating Procedures', 'Functional Specifications', 'Templates'];
    $topicStmt = $pdo->prepare('INSERT IGNORE INTO topics (name) VALUES (?)');
    foreach ($defaultTopics as $topic) {
        $topicStmt->execute([$topic]);
    }

    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $userPassword = password_hash('user123', PASSWORD_DEFAULT);
    $viewerPassword = password_hash('viewer123', PASSWORD_DEFAULT);

    $userStmt = $pdo->prepare('INSERT IGNORE INTO users (username, email, password, role) VALUES (?, ?, ?, ?)');
    $userStmt->execute(['admin', 'admin@example.com', $adminPassword, 'admin']);
    $userStmt->execute(['user', 'user@example.com', $userPassword, 'user']);
    $userStmt->execute(['viewer', 'viewer@example.com', $viewerPassword, 'viewer']);

    $uploadsDir = __DIR__ . '/uploads';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }

    echo "Setup complete.\n";
    echo "Admin account: admin / admin123\n";
    echo "User account: user / user123\n";
    echo "Viewer account: viewer / viewer123\n";
    echo "If you want to change the default passwords, edit setup.php or use your own admin/user accounts.";

} catch (PDOException $e) {
    echo "Database setup failed: " . $e->getMessage();
    exit(1);
}
?>