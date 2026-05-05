-- Database schema for HelpDesk system

CREATE DATABASE IF NOT EXISTS `helpdesk` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `helpdesk`;

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

INSERT IGNORE INTO topics (name) VALUES
('Working Instructions'),
('Standard Operating Procedures'),
('Functional Specifications'),
('Templates');

INSERT IGNORE INTO users (username, email, password, role) VALUES
('admin', 'admin@example.com', '$2y$10$KCSOU83M9kOOFiR5huoWleJUbB5ZYrCpOjFSTEbVe5S3F5PgyOCKq', 'admin'),
('user', 'user@example.com', '$2y$10$tVrdht6lkrbDS5OvopCXJuH4473QDTneejwhchwUS7DA6DzX3xd1m', 'user'),
('viewer', 'viewer@example.com', '$2y$10$KCSOU83M9kOOFiR5huoWleJUbB5ZYrCpOjFSTEbVe5S3F5PgyOCKq', 'viewer');
