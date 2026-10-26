-- Track whether a purchased registry item has physically arrived

ALTER TABLE registry_items
    ADD COLUMN received BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN received_at TIMESTAMP NULL DEFAULT NULL;
