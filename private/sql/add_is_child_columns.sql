ALTER TABLE guests
    ADD COLUMN is_child TINYINT(1) NOT NULL DEFAULT 0 AFTER rehearsal_invited,
    ADD COLUMN plus_one_is_child TINYINT(1) NOT NULL DEFAULT 0 AFTER plus_one_rehearsal_invited;
