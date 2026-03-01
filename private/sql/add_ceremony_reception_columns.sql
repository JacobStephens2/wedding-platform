-- Add per-event ceremony and reception RSVP columns
-- Existing RSVPs are assumed to apply to both events (migration below)
-- Run: mysql -u wedding_user -p wedding_stephens_page < private/sql/add_ceremony_reception_columns.sql

USE wedding_stephens_page;

ALTER TABLE guests
    ADD COLUMN ceremony_attending ENUM('yes', 'no') DEFAULT NULL AFTER attending,
    ADD COLUMN reception_attending ENUM('yes', 'no') DEFAULT NULL AFTER ceremony_attending,
    ADD COLUMN plus_one_ceremony_attending ENUM('yes', 'no') DEFAULT NULL AFTER plus_one_attending,
    ADD COLUMN plus_one_reception_attending ENUM('yes', 'no') DEFAULT NULL AFTER plus_one_ceremony_attending;

-- Migrate existing RSVPs: all current RSVPs assumed to apply to both events
UPDATE guests
SET ceremony_attending = attending,
    reception_attending = attending
WHERE attending IS NOT NULL;

UPDATE guests
SET plus_one_ceremony_attending = plus_one_attending,
    plus_one_reception_attending = plus_one_attending
WHERE plus_one_attending IS NOT NULL;
