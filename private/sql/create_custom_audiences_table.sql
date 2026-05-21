-- Named, reusable lists of admin-provided email addresses.
-- Each audience appears alongside the built-in ones (RSVP'd Yes, etc.) on the
-- announcements composer. Selecting one pre-fills the recipients textarea.
-- Run: mysql -u wedding_user -p wedding_stephens_page < private/sql/create_custom_audiences_table.sql

USE wedding_stephens_page;

CREATE TABLE IF NOT EXISTS custom_audiences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    custom_recipients TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
