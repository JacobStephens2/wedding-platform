-- Flag an off-registry gift as cash so the gift manager can total how
-- much was received specifically as cash.

ALTER TABLE gifts
    ADD COLUMN is_cash BOOLEAN NOT NULL DEFAULT FALSE;
