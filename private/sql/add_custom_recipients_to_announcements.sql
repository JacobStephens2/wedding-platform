-- Allow admins to add ad-hoc email recipients to an announcement.
-- Stored as raw text (one per line, "email" or "Name <email>"); parsed at send time.
-- Run: mysql -u wedding_user -p wedding_stephens_page < private/sql/add_custom_recipients_to_announcements.sql

USE wedding_stephens_page;

ALTER TABLE announcement_drafts
    ADD COLUMN custom_recipients TEXT DEFAULT NULL AFTER included_emails;

ALTER TABLE email_blasts
    ADD COLUMN custom_recipients TEXT DEFAULT NULL AFTER failed_recipients;
