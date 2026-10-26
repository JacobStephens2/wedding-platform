-- Add song_request column to rsvps table
-- Run this as MySQL root user: mysql -u root -p "$DB_NAME" < private/add_song_request_column.sql
-- Note: MySQL 8.0.19+ supports IF NOT EXISTS. If you get an error that the column already exists, that's fine.


ALTER TABLE rsvps 
ADD COLUMN song_request TEXT AFTER message;
