-- Private admin notes on a registry item, editable from the gift manager
USE wedding_stephens_page;

ALTER TABLE registry_items
    ADD COLUMN admin_note TEXT NULL DEFAULT NULL;
