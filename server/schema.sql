-- ============================================================
-- DOCPA Tracker — Database Schema
-- Run this on your VPS MySQL database (via HestiaCP phpMyAdmin or CLI)
-- ============================================================

CREATE DATABASE IF NOT EXISTS docpa_tracker
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE docpa_tracker;

-- -----------------------------------------------------------
-- Users
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(255) NOT NULL DEFAULT '',
    api_key VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_api_key (api_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Sessions (one per login/start per day)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME DEFAULT NULL,
    total_active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    total_idle_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active', 'idle', 'ended') NOT NULL DEFAULT 'active',
    ip_address VARCHAR(45) DEFAULT NULL,
    machine_name VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_start_time (start_time),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Screenshots
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS screenshots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    thumbnail_path VARCHAR(500) DEFAULT NULL,
    captured_at DATETIME NOT NULL,
    is_idle TINYINT(1) NOT NULL DEFAULT 0,
    file_size_bytes INT UNSIGNED DEFAULT 0,
    width SMALLINT UNSIGNED DEFAULT 0,
    height SMALLINT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_id (session_id),
    INDEX idx_user_id (user_id),
    INDEX idx_captured_at (captured_at),
    INDEX idx_is_idle (is_idle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Activity Logs (heartbeat / idle / lock / unlock events)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    session_id INT UNSIGNED DEFAULT NULL,
    event_type ENUM('active', 'idle', 'resume', 'lock', 'unlock', 'session_start', 'session_end') NOT NULL,
    event_data JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_event_type (event_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Default seed user (password/api_key will be set via install script)
-- -----------------------------------------------------------
INSERT INTO users (username, display_name, api_key, email, is_active)
VALUES ('admin', 'Administrator', SHA2(CONCAT('admin', NOW(), RAND()), 256), 'admin@localhost', 1)
ON DUPLICATE KEY UPDATE username=username;