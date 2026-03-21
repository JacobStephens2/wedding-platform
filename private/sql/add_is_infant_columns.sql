ALTER TABLE guests
    ADD COLUMN is_infant TINYINT(1) NOT NULL DEFAULT 0 AFTER is_child,
    ADD COLUMN plus_one_is_infant TINYINT(1) NOT NULL DEFAULT 0 AFTER plus_one_is_child;
