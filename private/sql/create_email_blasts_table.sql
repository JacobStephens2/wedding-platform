-- Email blast history log
-- Run: mysql -u wedding_user -p wedding_stephens_page < private/sql/create_email_blasts_table.sql

USE wedding_stephens_page;

CREATE TABLE IF NOT EXISTS email_blasts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    audience VARCHAR(64) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    body_is_html TINYINT(1) NOT NULL DEFAULT 0,
    reply_to VARCHAR(255) DEFAULT NULL,
    recipient_count INT NOT NULL DEFAULT 0,
    sent_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    failed_recipients MEDIUMTEXT DEFAULT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
