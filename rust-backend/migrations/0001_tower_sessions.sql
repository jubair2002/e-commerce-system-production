CREATE TABLE IF NOT EXISTS `tower_sessions` (
    id CHAR(22) PRIMARY KEY NOT NULL,
    data BLOB NOT NULL,
    expiry_date TIMESTAMP(6) NOT NULL
);
