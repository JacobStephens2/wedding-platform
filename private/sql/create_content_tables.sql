-- Couple-specific content moved out of the page templates.
--
-- site_content : short scalar values (names, date, venues, emails, branding).
-- content_blocks : long-form page prose, one row per page section.
--
-- Both are seeded from private/content_defaults.php via private/seed_content.php
-- and edited from the admin Content page. The code falls back to the defaults
-- file when a row (or the whole table) is absent.

USE wedding_stephens_page;

CREATE TABLE IF NOT EXISTS site_content (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_blocks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    page        VARCHAR(50)  NOT NULL,            -- 'story', 'about', 'travel', 'blessing'
    section_key VARCHAR(100) NOT NULL,            -- for story, matches gallery_photos.story_section
    heading     VARCHAR(255) NULL,
    body        MEDIUMTEXT   NULL,                -- trusted admin HTML; may embed {{carousel:KEY}} placeholders
    sort_order  INT          NOT NULL DEFAULT 0,
    published   TINYINT(1)   NOT NULL DEFAULT 1,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_page_section (page, section_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
