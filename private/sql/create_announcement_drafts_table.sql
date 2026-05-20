-- Draft state for the Announcements composer
-- Run: mysql -u wedding_user -p wedding_stephens_page < private/sql/create_announcement_drafts_table.sql

USE wedding_stephens_page;

CREATE TABLE IF NOT EXISTS announcement_drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    audience VARCHAR(64) NOT NULL,
    subject VARCHAR(500) NOT NULL DEFAULT '',
    body MEDIUMTEXT NOT NULL,
    from_name VARCHAR(255) DEFAULT NULL,
    reply_to VARCHAR(255) DEFAULT NULL,
    included_emails MEDIUMTEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
