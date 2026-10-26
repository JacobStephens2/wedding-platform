-- Track when a thank-you card has been written, in addition to when it is sent

ALTER TABLE registry_items
    ADD COLUMN thank_you_written BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN thank_you_written_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE gifts
    ADD COLUMN thank_you_written BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN thank_you_written_at TIMESTAMP NULL DEFAULT NULL;
