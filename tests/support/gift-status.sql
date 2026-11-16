-- Four-table fixture matching the gift tracking migrations, including timestamp policies.
DROP TABLE IF EXISTS registry_items, gifts, house_fund_contributions, honeymoon_fund_contributions;
CREATE TABLE registry_items (
    id INT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    price DECIMAL(10,2),
    purchased BOOLEAN DEFAULT FALSE,
    purchased_by VARCHAR(255),
    purchase_message TEXT,
    admin_note TEXT,
    received BOOLEAN NOT NULL DEFAULT FALSE,
    received_at TIMESTAMP NULL DEFAULT NULL,
    thank_you_written BOOLEAN NOT NULL DEFAULT FALSE,
    thank_you_written_at TIMESTAMP NULL DEFAULT NULL,
    thank_you_sent BOOLEAN NOT NULL DEFAULT FALSE,
    thank_you_sent_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE TABLE gifts (
    id INT PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    purchaser_name VARCHAR(255),
    notes TEXT,
    received_on DATE NULL DEFAULT NULL,
    value DECIMAL(10,2),
    is_cash BOOLEAN NOT NULL DEFAULT FALSE,
    thank_you_written BOOLEAN NOT NULL DEFAULT FALSE,
    thank_you_written_at TIMESTAMP NULL DEFAULT NULL,
    thank_you_sent BOOLEAN NOT NULL DEFAULT FALSE,
    thank_you_sent_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE TABLE house_fund_contributions (
    id INT PRIMARY KEY,
    amount DECIMAL(10,2) NOT NULL,
    contributor_name VARCHAR(255),
    thank_you_written BOOLEAN NOT NULL DEFAULT FALSE,
    thank_you_written_at TIMESTAMP NULL DEFAULT NULL,
    thank_you_sent BOOLEAN NOT NULL DEFAULT FALSE,
    thank_you_sent_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE honeymoon_fund_contributions LIKE house_fund_contributions;
INSERT INTO registry_items
    (id,title,price,purchased,purchased_by,purchase_message,admin_note,created_at,updated_at)
    VALUES (1,'A kettle',42.50,1,'Registry guest','Congratulations','A private note','2020-01-01','2021-02-03'),
           (2,'Unpurchased item',25,0,NULL,NULL,NULL,'2020-01-01','2021-02-03');
INSERT INTO gifts
    (id,description,purchaser_name,notes,received_on,value,is_cash,created_at,updated_at)
    VALUES (1,'A vase','Manual guest','Keep this note','2022-03-04',50,0,'2020-01-01','2021-02-03'),
           (2,'Cash','Cash guest','An envelope','2022-03-04',100,1,'2020-01-01','2021-02-03');
INSERT INTO house_fund_contributions (id,amount,contributor_name,created_at)
    VALUES (1,125,'House guest','2020-01-01');
INSERT INTO honeymoon_fund_contributions (id,amount,contributor_name,created_at)
    VALUES (1,150,'Honeymoon guest','2020-01-01');
