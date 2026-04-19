-- Optional monetary value for an off-registry gift, so the gift manager
-- can total how much was received alongside registry prices.
USE wedding_stephens_page;

ALTER TABLE gifts
    ADD COLUMN value DECIMAL(10,2) NULL DEFAULT NULL;
