-- Migration script to add purchase_message column to registry_items table
-- Run this as MySQL root user: mysql -u root -p wedding_stephens_page < private/sql/add_purchase_message_column.sql

USE wedding_stephens_page;

SET @dbname = DATABASE();
SET @tablename = 'registry_items';
SET @columnname = 'purchase_message';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TEXT NULL AFTER purchased_by')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
