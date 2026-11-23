USE `php_admin_panel`;

CREATE TABLE IF NOT EXISTS `tower_sessions` (
    `id` CHAR(22) PRIMARY KEY NOT NULL,
    `data` BLOB NOT NULL,
    `expiry_date` TIMESTAMP(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
