USE wedding_stephens_page;

ALTER TABLE guests ADD COLUMN rehearsal_invited TINYINT(1) NOT NULL DEFAULT 0 AFTER phone;
