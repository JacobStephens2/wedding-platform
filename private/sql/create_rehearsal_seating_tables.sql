USE wedding_stephens_page;

-- Rehearsal dinner seating tables (separate from reception seating)
CREATE TABLE IF NOT EXISTS rehearsal_seating_tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_number INT NOT NULL,
    table_name VARCHAR(100) NOT NULL,
    capacity INT DEFAULT 10,
    notes TEXT,
    pos_x FLOAT DEFAULT NULL,
    pos_y FLOAT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (table_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add rehearsal seating columns to guests
ALTER TABLE guests ADD COLUMN rehearsal_table_id INT DEFAULT NULL;
ALTER TABLE guests ADD COLUMN rehearsal_seat_number INT DEFAULT NULL;
ALTER TABLE guests ADD COLUMN rehearsal_plus_one_seat_before TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE guests ADD COLUMN rehearsal_plus_one_seat_number INT DEFAULT NULL;
ALTER TABLE guests ADD CONSTRAINT fk_guest_rehearsal_table FOREIGN KEY (rehearsal_table_id) REFERENCES rehearsal_seating_tables(id) ON DELETE SET NULL;
