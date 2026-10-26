ALTER TABLE gallery_photos
    ADD COLUMN story_section VARCHAR(50) DEFAULT NULL,
    ADD COLUMN story_position INT DEFAULT NULL;
