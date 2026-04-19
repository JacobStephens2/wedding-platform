-- Thank-you tracking columns for house and honeymoon fund contributions,
-- so fund contributors can be represented in the gift manager alongside
-- registry and off-registry gifts.
USE wedding_stephens_page;

ALTER TABLE house_fund_contributions
    ADD COLUMN thank_you_written BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN thank_you_written_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN thank_you_sent BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN thank_you_sent_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE honeymoon_fund_contributions
    ADD COLUMN thank_you_written BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN thank_you_written_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN thank_you_sent BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN thank_you_sent_at TIMESTAMP NULL DEFAULT NULL;
