-- Track non-registry gifts and thank-you card status
USE wedding_stephens_page;

-- Thank-you tracking for registry items (for purchased items)
ALTER TABLE registry_items
    ADD COLUMN thank_you_sent BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN thank_you_sent_at TIMESTAMP NULL DEFAULT NULL;

-- Non-registry gifts entered manually by admins
CREATE TABLE IF NOT EXISTS gifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    purchaser_name VARCHAR(255),
    notes TEXT,
    received_on DATE NULL DEFAULT NULL,
    thank_you_sent BOOLEAN NOT NULL DEFAULT FALSE,
    thank_you_sent_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_thank_you_sent (thank_you_sent),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
