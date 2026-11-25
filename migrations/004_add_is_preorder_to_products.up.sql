SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'is_preorder'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `is_preorder` TINYINT(1) NOT NULL DEFAULT 0 AFTER `featured`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
