USE wedding_stephens_page;

ALTER TABLE guests ADD COLUMN phone VARCHAR(30) DEFAULT NULL AFTER email;
