-- =======================================================
--  upgrade_all.sql  -  Complete All-in-One Database Schema & Upgrades
--  Includes Base Schema + Migrations + All Modules (001..007)
--  Safe to re-run (idempotent). Works on MySQL 5.7+ / MariaDB 10.x.
-- =======================================================

CREATE DATABASE IF NOT EXISTS `php_admin_panel`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `php_admin_panel`;
SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- Base Schema (Users, Activity Log, Settings)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `email`      VARCHAR(150) NOT NULL UNIQUE,
  `password`   VARCHAR(255) NOT NULL,
  `role`       ENUM('admin','editor','user') NOT NULL DEFAULT 'user',
  `status`     ENUM('active','inactive')     NOT NULL DEFAULT 'active',
  `avatar`     VARCHAR(255) DEFAULT NULL,
  `phone`      VARCHAR(30)  DEFAULT NULL,
  `bio`        TEXT         DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_users_role`   (`role`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(100) NOT NULL,
  `description` TEXT        DEFAULT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_user`   (`user_id`),
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_name`   VARCHAR(100) NOT NULL UNIQUE,
  `value`      TEXT         DEFAULT NULL,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Base Migrations (Categories, Products, Orders, Order Items)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150) NOT NULL,
  `slug`        VARCHAR(160) NOT NULL UNIQUE,
  `description` TEXT         DEFAULT NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_categories_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `category_id`      INT UNSIGNED  DEFAULT NULL,
  `name`             VARCHAR(200)  NOT NULL,
  `slug`             VARCHAR(220)  NOT NULL UNIQUE,
  `sku`              VARCHAR(80)   DEFAULT NULL,
  `short_description` VARCHAR(500) DEFAULT NULL,
  `description`      TEXT          DEFAULT NULL,
  `price`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sale_price`       DECIMAL(10,2) DEFAULT NULL,
  `stock`            INT           NOT NULL DEFAULT 0,
  `image`            VARCHAR(255)  DEFAULT NULL,
  `status`           ENUM('active','inactive','draft') NOT NULL DEFAULT 'active',
  `featured`         TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_status`   (`status`),
  KEY `idx_products_featured` (`featured`),
  CONSTRAINT `fk_products_category`
      FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `order_number`      VARCHAR(40)   NOT NULL UNIQUE,
  `customer_name`     VARCHAR(150)  NOT NULL,
  `customer_email`    VARCHAR(150)  NOT NULL,
  `customer_phone`    VARCHAR(40)   DEFAULT NULL,
  `shipping_address`  TEXT          NOT NULL,
  `shipping_city`     VARCHAR(100)  DEFAULT NULL,
  `shipping_zip`      VARCHAR(20)   DEFAULT NULL,
  `shipping_country`  VARCHAR(100)  DEFAULT NULL,
  `subtotal`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `shipping_fee`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method`    VARCHAR(40)   NOT NULL DEFAULT 'cod',
  `status`            ENUM('pending','processing','shipped','completed','cancelled')
                      NOT NULL DEFAULT 'pending',
  `notes`             TEXT          DEFAULT NULL,
  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orders_status`     (`status`),
  KEY `idx_orders_email`      (`customer_email`),
  KEY `idx_orders_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `order_id`       INT UNSIGNED  NOT NULL,
  `product_id`     INT UNSIGNED  DEFAULT NULL,
  `product_name`   VARCHAR(200)  NOT NULL,
  `product_sku`    VARCHAR(80)   DEFAULT NULL,
  `product_image`  VARCHAR(255)  DEFAULT NULL,
  `unit_price`     DECIMAL(10,2) NOT NULL,
  `quantity`       INT           NOT NULL,
  `line_total`     DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_oi_order`   (`order_id`),
  KEY `idx_oi_product` (`product_id`),
  CONSTRAINT `fk_oi_order`   FOREIGN KEY (`order_id`)   REFERENCES `orders`(`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_oi_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reusable option presets (admin can pick "Size" or "Color" when building a product)
CREATE TABLE IF NOT EXISTS `option_presets` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(80)  NOT NULL,
  `display_type` ENUM('button','dropdown','swatch','radio') NOT NULL DEFAULT 'button',
  `position`  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_preset_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `option_preset_values` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `preset_id`  INT UNSIGNED NOT NULL,
  `value`      VARCHAR(80)  NOT NULL,
  `swatch_hex` CHAR(7)      DEFAULT NULL,
  `position`   INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_preset_value` (`preset_id`,`value`),
  CONSTRAINT `fk_opv_preset` FOREIGN KEY (`preset_id`) REFERENCES `option_presets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Options belong to one product (Size, Color, Material, Storage...)
CREATE TABLE IF NOT EXISTS `product_options` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`   INT UNSIGNED NOT NULL,
  `name`         VARCHAR(80)  NOT NULL,
  `display_type` ENUM('button','dropdown','swatch','radio') NOT NULL DEFAULT 'button',
  `position`     INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_po_product_name` (`product_id`,`name`),
  CONSTRAINT `fk_po_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_option_values` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `option_id`  INT UNSIGNED NOT NULL,
  `value`      VARCHAR(80)  NOT NULL,
  `swatch_hex` CHAR(7)      DEFAULT NULL,
  `position`   INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pov_option_value` (`option_id`,`value`),
  CONSTRAINT `fk_pov_option` FOREIGN KEY (`option_id`) REFERENCES `product_options`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per purchasable combination (M / Red). Stock lives HERE for variant products.
CREATE TABLE IF NOT EXISTS `product_variants` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `product_id`  INT UNSIGNED  NOT NULL,
  `option_hash` CHAR(32)      NOT NULL COMMENT 'md5 of sorted option_value ids; blocks duplicate combos',
  `sku`         VARCHAR(80)   DEFAULT NULL,
  `barcode`     VARCHAR(80)   DEFAULT NULL,
  `price`       DECIMAL(10,2) DEFAULT NULL COMMENT 'NULL = use product price',
  `sale_price`  DECIMAL(10,2) DEFAULT NULL,
  `cost_price`  DECIMAL(10,2) DEFAULT NULL,
  `stock`       INT           NOT NULL DEFAULT 0,
  `weight_g`    INT           DEFAULT NULL,
  `image`       VARCHAR(255)  DEFAULT NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `position`    INT NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_variant_combo` (`product_id`,`option_hash`),
  KEY `idx_variant_sku` (`sku`),
  KEY `idx_variant_status_stock` (`product_id`,`status`,`stock`),
  CONSTRAINT `fk_variant_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_variant_values` (
  `variant_id`      INT UNSIGNED NOT NULL,
  `option_id`       INT UNSIGNED NOT NULL,
  `option_value_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`variant_id`,`option_id`),
  KEY `idx_pvv_value` (`option_value_id`),
  CONSTRAINT `fk_pvv_variant` FOREIGN KEY (`variant_id`)      REFERENCES `product_variants`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_pvv_option`  FOREIGN KEY (`option_id`)       REFERENCES `product_options`(`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_pvv_value`   FOREIGN KEY (`option_value_id`) REFERENCES `product_option_values`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Image gallery (variant_id set = image shown when that variant is picked)
CREATE TABLE IF NOT EXISTS `product_images` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED DEFAULT NULL,
  `filename`   VARCHAR(255) NOT NULL,
  `alt_text`   VARCHAR(200) DEFAULT NULL,
  `position`   INT NOT NULL DEFAULT 0,
  `is_primary` TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_pi_product` (`product_id`,`position`),
  CONSTRAINT `fk_pi_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pi_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New product-level columns
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `product_type` ENUM(''simple'',''variable'') NOT NULL DEFAULT ''simple'' AFTER `slug`',
  'SELECT ''products.product_type exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'product_type');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `track_inventory` TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT ''products.track_inventory exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'track_inventory');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `allow_backorder` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''products.allow_backorder exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'allow_backorder');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `low_stock_threshold` INT NOT NULL DEFAULT 5',
  'SELECT ''products.low_stock_threshold exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'low_stock_threshold');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `show_stock_count` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''show Only N left on storefront''',
  'SELECT ''products.show_stock_count exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'show_stock_count');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `weight_g` INT DEFAULT NULL',
  'SELECT ''products.weight_g exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'weight_g');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `brand` VARCHAR(100) DEFAULT NULL',
  'SELECT ''products.brand exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'brand');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `tags` VARCHAR(255) DEFAULT NULL',
  'SELECT ''products.tags exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'tags');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `meta_title` VARCHAR(160) DEFAULT NULL',
  'SELECT ''products.meta_title exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'meta_title');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `meta_description` VARCHAR(255) DEFAULT NULL',
  'SELECT ''products.meta_description exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'meta_description');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `view_count` INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT ''products.view_count exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'view_count');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD COLUMN `sold_count` INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT ''products.sold_count exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'sold_count');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD INDEX `idx_products_type` (`product_type`)',
  'SELECT ''products.idx_products_type exists'' AS info')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_type');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Aggregate stock per product (works for simple AND variable products)
CREATE OR REPLACE VIEW `v_product_stock` AS
SELECT p.id AS product_id,
       p.product_type,
       CASE WHEN p.product_type = 'variable'
            THEN COALESCE((SELECT SUM(v.stock) FROM product_variants v
                           WHERE v.product_id = p.id AND v.status = 'active'), 0)
            ELSE p.stock END AS total_stock,
       CASE WHEN p.product_type = 'variable'
            THEN (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.status = 'active')
            ELSE 1 END AS variant_count
FROM products p;

-- Starter presets
INSERT INTO `option_presets` (`name`,`display_type`,`position`) VALUES
  ('Size','button',1),('Color','swatch',2),('Material','dropdown',3),('Storage','button',4)
ON DUPLICATE KEY UPDATE `name` = `name`;

INSERT IGNORE INTO `option_preset_values` (`preset_id`,`value`,`swatch_hex`,`position`)
SELECT p.id, v.val, v.hex, v.pos FROM `option_presets` p JOIN (
  SELECT 'Size' n,'XS' val,NULL hex,1 pos UNION ALL SELECT 'Size','S',NULL,2 UNION ALL SELECT 'Size','M',NULL,3
  UNION ALL SELECT 'Size','L',NULL,4 UNION ALL SELECT 'Size','XL',NULL,5 UNION ALL SELECT 'Size','XXL',NULL,6
  UNION ALL SELECT 'Color','Black','#111111',1 UNION ALL SELECT 'Color','White','#FFFFFF',2
  UNION ALL SELECT 'Color','Red','#DC2626',3 UNION ALL SELECT 'Color','Blue','#2563EB',4
  UNION ALL SELECT 'Color','Green','#16A34A',5 UNION ALL SELECT 'Color','Grey','#6B7280',6
  UNION ALL SELECT 'Material','Cotton',NULL,1 UNION ALL SELECT 'Material','Leather',NULL,2 UNION ALL SELECT 'Material','Polyester',NULL,3
  UNION ALL SELECT 'Storage','64GB',NULL,1 UNION ALL SELECT 'Storage','128GB',NULL,2 UNION ALL SELECT 'Storage','256GB',NULL,3
) v ON v.n = p.name;


-- ################ 002_inventory_orders_cod.sql ################
-- =======================================================
--  002 - Inventory ledger, orders/COD upgrade, shipping, coupons
--  Safe to re-run (idempotent). Works on MySQL 5.7+ / MariaDB 10.x.
--  Run AFTER schema.sql and migrations.sql.
--  phpMyAdmin: select the database first, then Import / SQL tab.
-- =======================================================
USE `php_admin_panel`;
SET NAMES utf8mb4;

-- Every stock change is recorded (audit + analytics + undo-safe)
CREATE TABLE IF NOT EXISTS `inventory_movements` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED DEFAULT NULL,
  `change_qty` INT NOT NULL COMMENT 'negative = stock out',
  `stock_after` INT DEFAULT NULL,
  `reason`     ENUM('order','cancel_restock','manual_adjust','restock','return','correction') NOT NULL,
  `order_id`   INT UNSIGNED DEFAULT NULL,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `note`       VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_im_product` (`product_id`,`created_at`),
  KEY `idx_im_variant` (`variant_id`),
  KEY `idx_im_order`   (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipping_methods` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `zone`        VARCHAR(100) NOT NULL DEFAULT 'Everywhere' COMMENT 'e.g. Inside Dhaka / Outside Dhaka',
  `cost`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `free_over`   DECIMAL(10,2) DEFAULT NULL COMMENT 'free shipping when subtotal >= this',
  `min_days`    TINYINT UNSIGNED DEFAULT NULL,
  `max_days`    TINYINT UNSIGNED DEFAULT NULL,
  `cod_allowed` TINYINT(1) NOT NULL DEFAULT 1,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `position`    INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupons` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(40)  NOT NULL,
  `type`        ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `value`       DECIMAL(10,2) NOT NULL,
  `min_subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `max_discount` DECIMAL(10,2) DEFAULT NULL,
  `usage_limit` INT DEFAULT NULL,
  `used_count`  INT NOT NULL DEFAULT 0,
  `starts_at`   DATETIME DEFAULT NULL,
  `expires_at`  DATETIME DEFAULT NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupon_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_status_history` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `status`     VARCHAR(30)  NOT NULL,
  `note`       VARCHAR(255) DEFAULT NULL,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_osh_order` (`order_id`),
  CONSTRAINT `fk_osh_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- order_items: remember WHICH variant was bought
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `order_items` ADD COLUMN `variant_id` INT UNSIGNED DEFAULT NULL AFTER `product_id`',
  'SELECT ''order_items.variant_id exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'variant_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `order_items` ADD COLUMN `variant_sku` VARCHAR(80) DEFAULT NULL',
  'SELECT ''order_items.variant_sku exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'variant_sku');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `order_items` ADD COLUMN `variant_label` VARCHAR(200) DEFAULT NULL COMMENT ''e.g. Size: M / Color: Red''',
  'SELECT ''order_items.variant_label exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'variant_label');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `order_items` ADD INDEX `idx_oi_variant` (`variant_id`)',
  'SELECT ''order_items.idx_oi_variant exists'' AS info')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND INDEX_NAME = 'idx_oi_variant');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- orders: payment, COD, shipping, coupon, tracking
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD COLUMN `payment_status` ENUM(''unpaid'',''paid'',''refunded'') NOT NULL DEFAULT ''unpaid''',
  'SELECT ''orders.payment_status exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD COLUMN `cod_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00',
  'SELECT ''orders.cod_fee exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'cod_fee');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD COLUMN `discount_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
  'SELECT ''orders.discount_total exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'discount_total');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD COLUMN `coupon_code` VARCHAR(40) DEFAULT NULL',
  'SELECT ''orders.coupon_code exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'coupon_code');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD COLUMN `shipping_method_id` INT UNSIGNED DEFAULT NULL',
  'SELECT ''orders.shipping_method_id exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'shipping_method_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD COLUMN `shipping_method_name` VARCHAR(100) DEFAULT NULL',
  'SELECT ''orders.shipping_method_name exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'shipping_method_name');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD COLUMN `courier` VARCHAR(80) DEFAULT NULL',
  'SELECT ''orders.courier exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'courier');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD COLUMN `tracking_number` VARCHAR(80) DEFAULT NULL',
  'SELECT ''orders.tracking_number exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'tracking_number');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD COLUMN `stock_restored` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''set when a cancelled order returned its stock''',
  'SELECT ''orders.stock_restored exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'stock_restored');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD COLUMN `ip_address` VARCHAR(45) DEFAULT NULL',
  'SELECT ''orders.ip_address exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'ip_address');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD INDEX `idx_orders_payment_status` (`payment_status`)',
  'SELECT ''orders.idx_orders_payment_status exists'' AS info')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_payment_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

INSERT INTO `shipping_methods` (`name`,`zone`,`cost`,`free_over`,`min_days`,`max_days`,`cod_allowed`,`position`)
SELECT 'Inside Dhaka','Dhaka',60.00,3000.00,1,2,1,1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM shipping_methods WHERE name='Inside Dhaka');
INSERT INTO `shipping_methods` (`name`,`zone`,`cost`,`free_over`,`min_days`,`max_days`,`cod_allowed`,`position`)
SELECT 'Outside Dhaka','Rest of country',120.00,5000.00,2,5,1,2 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM shipping_methods WHERE name='Outside Dhaka');

-- Settings (admin-editable from Settings pages)
INSERT INTO `settings` (`key_name`,`value`) VALUES
  ('cod_enabled','1'),('cod_fee','0.00'),('cod_min_order','0'),('cod_max_order','0'),
  ('bank_transfer_enabled','0'),('bank_transfer_instructions',''),
  ('guest_checkout','1'),('require_phone','1'),
  ('stock_display_mode','count_when_low'),   -- always | count_when_low | in_out_only | hidden
  ('out_of_stock_behavior','show_disabled'), -- show_disabled | hide
  ('cart_reserve_minutes','0'),
  ('realtime_poll_seconds','10'),
  ('order_number_prefix','ORD')
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;


-- ################ 003_mail_system.sql ################
-- =======================================================
--  003 - Mail configuration, templates & queue
--  Safe to re-run (idempotent). Works on MySQL 5.7+ / MariaDB 10.x.
--  Run AFTER schema.sql and migrations.sql.
--  phpMyAdmin: select the database first, then Import / SQL tab.
-- =======================================================
USE `php_admin_panel`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `email_templates` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`       VARCHAR(60)  NOT NULL,
  `name`       VARCHAR(120) NOT NULL,
  `audience`   ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  `subject`    VARCHAR(200) NOT NULL,
  `body_html`  MEDIUMTEXT   NOT NULL,
  `body_text`  MEDIUMTEXT   DEFAULT NULL,
  `variables`  VARCHAR(500) DEFAULT NULL COMMENT 'help text: placeholders usable in this template',
  `enabled`    TINYINT(1)   NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_template_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_queue` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_slug` VARCHAR(60)  DEFAULT NULL,
  `to_email`     VARCHAR(190)  NOT NULL,
  `to_name`      VARCHAR(150)  DEFAULT NULL,
  `subject`      VARCHAR(255)  NOT NULL,
  `body_html`    MEDIUMTEXT    NOT NULL,
  `body_text`    MEDIUMTEXT    DEFAULT NULL,
  `related_type` VARCHAR(30)   DEFAULT NULL COMMENT 'order, product, test ...',
  `related_id`   INT UNSIGNED  DEFAULT NULL,
  `status`       ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error`   VARCHAR(500)  DEFAULT NULL,
  `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`      DATETIME DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eq_status_sched` (`status`,`scheduled_at`),
  KEY `idx_eq_related` (`related_type`,`related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mail settings live in the existing `settings` table (key/value).
-- mail_password is stored ENCRYPTED by the app (openssl, key from config) - never plain text.
INSERT INTO `settings` (`key_name`,`value`) VALUES
  ('mail_enabled','0'),
  ('mail_driver','smtp'),               -- smtp | phpmail | log (log = write to DB only, for dev)
  ('mail_host',''),
  ('mail_port','587'),
  ('mail_encryption','tls'),            -- tls | ssl | none
  ('mail_username',''),
  ('mail_password_enc',''),
  ('mail_from_email',''),
  ('mail_from_name',''),
  ('mail_reply_to',''),
  ('mail_admin_notify','1'),
  ('mail_admin_recipients',''),         -- comma separated
  ('mail_timeout','15'),
  ('mail_max_attempts','3'),
  ('mail_send_mode','queue'),           -- queue (cron) | instant
  ('mail_notify_low_stock','1'),
  ('mail_footer_text','')
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;

-- Default templates. Placeholders use {{double_braces}}.
INSERT INTO `email_templates` (`slug`,`name`,`audience`,`subject`,`body_html`,`variables`)
VALUES
 ('order_placed_customer','Order confirmation','customer',
  'Your order {{order_number}} is confirmed',
  '<h2>Thanks for your order, {{customer_name}}!</h2><p>We received order <strong>{{order_number}}</strong> and will contact you before delivery.</p>{{items_table}}<p>Subtotal: {{subtotal}}<br>Shipping: {{shipping_fee}}<br><strong>Total (Cash on Delivery): {{total}}</strong></p><p>Deliver to:<br>{{shipping_address}}</p><p>Track your order: <a href=\"{{track_url}}\">{{track_url}}</a></p><p><a href=\"{{account_order_url}}\">View in my account</a></p>',
  'order_number, customer_name, items_table, subtotal, shipping_fee, discount, total, payment_method, shipping_address, track_url, account_order_url, site_name'),
 ('order_placed_admin','New order (admin alert)','admin',
  'New order {{order_number}} - {{total}}',
  '<p>New order <strong>{{order_number}}</strong> from {{customer_name}} ({{customer_phone}}).</p>{{items_table}}<p>Total: {{total}} - {{payment_method}}</p><p><a href=\"{{admin_order_url}}\">Open in admin</a></p>',
  'order_number, customer_name, customer_phone, customer_email, items_table, total, payment_method, admin_order_url'),
 ('order_processing','Order processing','customer',
  'We are preparing order {{order_number}}',
  '<p>Hi {{customer_name}}, your order <strong>{{order_number}}</strong> is now being prepared.</p>',
  'order_number, customer_name, track_url'),
 ('order_shipped','Order shipped','customer',
  'Order {{order_number}} is on the way',
  '<p>Hi {{customer_name}}, your order <strong>{{order_number}}</strong> has shipped via {{courier}}.</p><p>Tracking: {{tracking_number}}</p><p>Please keep {{total}} ready for Cash on Delivery.</p>',
  'order_number, customer_name, courier, tracking_number, total, track_url'),
 ('order_completed','Order delivered','customer',
  'Order {{order_number}} delivered',
  '<p>Hi {{customer_name}}, your order <strong>{{order_number}}</strong> was delivered. Thank you for shopping with {{site_name}}!</p>',
  'order_number, customer_name, site_name'),
 ('order_cancelled','Order cancelled','customer',
  'Order {{order_number}} cancelled',
  '<p>Hi {{customer_name}}, your order <strong>{{order_number}}</strong> has been cancelled. Contact us if this is unexpected.</p>',
  'order_number, customer_name, site_name'),
 ('low_stock_admin','Low stock alert','admin',
  'Low stock: {{product_name}} {{variant_label}}',
  '<p><strong>{{product_name}}</strong> {{variant_label}} has only <strong>{{stock}}</strong> left (threshold {{threshold}}).</p><p><a href=\"{{admin_product_url}}\">Restock now</a></p>',
  'product_name, variant_label, stock, threshold, admin_product_url'),
 ('test_email','Test email','admin',
  'Test email from {{site_name}}',
  '<p>If you can read this, your mail settings work.</p><p>Sent at {{sent_at}}.</p>',
  'site_name, sent_at')
ON DUPLICATE KEY UPDATE `slug` = `slug`;


-- ################ 004_monitoring.sql ################
-- =======================================================
--  004 - Built-in monitoring (system resources, network, bandwidth)
--  Safe to re-run (idempotent). Works on MySQL 5.7+ / MariaDB 10.x.
--  Run AFTER schema.sql and migrations.sql.
--  phpMyAdmin: select the database first, then Import / SQL tab.
-- =======================================================
USE `php_admin_panel`;
SET NAMES utf8mb4;

-- One row per collector run (cron every minute). Counters are cumulative; rates are derived.
CREATE TABLE IF NOT EXISTS `system_metrics` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `collected_at`  DATETIME NOT NULL,
  `load_1`        DECIMAL(6,2) DEFAULT NULL,
  `load_5`        DECIMAL(6,2) DEFAULT NULL,
  `load_15`       DECIMAL(6,2) DEFAULT NULL,
  `cpu_percent`   DECIMAL(5,2) DEFAULT NULL,
  `cpu_cores`     SMALLINT UNSIGNED DEFAULT NULL,
  `mem_total`     BIGINT UNSIGNED DEFAULT NULL,
  `mem_used`      BIGINT UNSIGNED DEFAULT NULL,
  `swap_total`    BIGINT UNSIGNED DEFAULT NULL,
  `swap_used`     BIGINT UNSIGNED DEFAULT NULL,
  `disk_total`    BIGINT UNSIGNED DEFAULT NULL,
  `disk_used`     BIGINT UNSIGNED DEFAULT NULL,
  `net_rx_bytes`  BIGINT UNSIGNED DEFAULT NULL COMMENT 'cumulative since boot',
  `net_tx_bytes`  BIGINT UNSIGNED DEFAULT NULL,
  `net_rx_rate`   BIGINT UNSIGNED DEFAULT NULL COMMENT 'bytes/sec since previous sample',
  `net_tx_rate`   BIGINT UNSIGNED DEFAULT NULL,
  `db_threads`    INT UNSIGNED DEFAULT NULL,
  `db_qps`        DECIMAL(10,2) DEFAULT NULL,
  `db_slow_queries` BIGINT UNSIGNED DEFAULT NULL,
  `db_size_bytes` BIGINT UNSIGNED DEFAULT NULL,
  `php_version`   VARCHAR(20) DEFAULT NULL,
  `uptime_sec`    BIGINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sm_time` (`collected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-interface counters (eth0, ens3...) so admin can pick an interface
CREATE TABLE IF NOT EXISTS `network_samples` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `collected_at` DATETIME NOT NULL,
  `iface`        VARCHAR(40) NOT NULL,
  `rx_bytes`     BIGINT UNSIGNED NOT NULL,
  `tx_bytes`     BIGINT UNSIGNED NOT NULL,
  `rx_rate`      BIGINT UNSIGNED DEFAULT NULL,
  `tx_rate`      BIGINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ns_time_iface` (`collected_at`,`iface`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Application-level traffic/bandwidth, aggregated per minute by a shutdown hook (cheap: 1 upsert/request)
CREATE TABLE IF NOT EXISTS `traffic_minute` (
  `bucket`      DATETIME NOT NULL,
  `area`        ENUM('storefront','admin','api') NOT NULL DEFAULT 'storefront',
  `requests`    INT UNSIGNED NOT NULL DEFAULT 0,
  `bytes_out`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `bytes_in`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `total_ms`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `max_ms`      INT UNSIGNED NOT NULL DEFAULT 0,
  `s2xx`        INT UNSIGNED NOT NULL DEFAULT 0,
  `s3xx`        INT UNSIGNED NOT NULL DEFAULT 0,
  `s4xx`        INT UNSIGNED NOT NULL DEFAULT 0,
  `s5xx`        INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`bucket`,`area`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Last N slow requests / PHP errors for the admin to inspect
CREATE TABLE IF NOT EXISTS `slow_requests` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `path`        VARCHAR(255) NOT NULL,
  `method`      VARCHAR(10)  NOT NULL,
  `status_code` SMALLINT UNSIGNED DEFAULT NULL,
  `duration_ms` INT UNSIGNED NOT NULL,
  `peak_mem`    BIGINT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sr_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitor_alert_rules` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `metric`        ENUM('cpu_percent','mem_percent','disk_percent','load_1','db_threads','error_rate') NOT NULL,
  `operator`      ENUM('>','<') NOT NULL DEFAULT '>',
  `threshold`     DECIMAL(10,2) NOT NULL,
  `for_minutes`   SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  `notify_email`  TINYINT(1) NOT NULL DEFAULT 1,
  `enabled`       TINYINT(1) NOT NULL DEFAULT 1,
  `last_fired_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `monitor_alert_rules` (`metric`,`operator`,`threshold`,`for_minutes`)
SELECT * FROM (
  SELECT 'cpu_percent' m,'>' o,85.00 t,5 f UNION ALL
  SELECT 'mem_percent','>',90.00,5 UNION ALL
  SELECT 'disk_percent','>',90.00,15
) x WHERE NOT EXISTS (SELECT 1 FROM monitor_alert_rules);

INSERT INTO `settings` (`key_name`,`value`) VALUES
  ('monitor_enabled','1'),
  ('monitor_collect_interval_min','1'),
  ('monitor_retention_days','14'),
  ('monitor_default_iface','auto'),
  ('monitor_slow_request_ms','1500'),
  ('monitor_bandwidth_quota_gb','0'),   -- 0 = no quota; else show % of monthly quota used
  ('monitor_external_url','')           -- optional: link/iframe to Netdata / Glances / Grafana
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;


-- ################ 005_analytics.sql ################
-- =======================================================
--  005 - Analytics (events, daily rollups, search log)
--  Safe to re-run (idempotent). Works on MySQL 5.7+ / MariaDB 10.x.
--  Run AFTER schema.sql and migrations.sql.
--  phpMyAdmin: select the database first, then Import / SQL tab.
-- =======================================================
USE `php_admin_panel`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `analytics_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type`  ENUM('product_view','add_to_cart','remove_from_cart','checkout_start','purchase','search','page_view') NOT NULL,
  `product_id`  INT UNSIGNED DEFAULT NULL,
  `variant_id`  INT UNSIGNED DEFAULT NULL,
  `order_id`    INT UNSIGNED DEFAULT NULL,
  `visitor_hash` CHAR(32)    NOT NULL COMMENT 'md5(ip+ua+daily salt) - no raw IP stored',
  `qty`         INT          DEFAULT NULL,
  `value`       DECIMAL(12,2) DEFAULT NULL,
  `referrer_host` VARCHAR(120) DEFAULT NULL,
  `utm_source`  VARCHAR(60)  DEFAULT NULL,
  `utm_campaign` VARCHAR(80) DEFAULT NULL,
  `device`      ENUM('desktop','mobile','tablet','bot') NOT NULL DEFAULT 'desktop',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ae_type_time`   (`event_type`,`created_at`),
  KEY `idx_ae_product_time`(`product_id`,`created_at`),
  KEY `idx_ae_visitor`     (`visitor_hash`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_stats_daily` (
  `stat_date`    DATE NOT NULL,
  `product_id`   INT UNSIGNED NOT NULL,
  `views`        INT UNSIGNED NOT NULL DEFAULT 0,
  `unique_views` INT UNSIGNED NOT NULL DEFAULT 0,
  `add_to_cart`  INT UNSIGNED NOT NULL DEFAULT 0,
  `orders`       INT UNSIGNED NOT NULL DEFAULT 0,
  `units_sold`   INT UNSIGNED NOT NULL DEFAULT 0,
  `revenue`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`stat_date`,`product_id`),
  KEY `idx_psd_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_stats_daily` (
  `stat_date`     DATE NOT NULL,
  `visitors`      INT UNSIGNED NOT NULL DEFAULT 0,
  `product_views` INT UNSIGNED NOT NULL DEFAULT 0,
  `add_to_cart`   INT UNSIGNED NOT NULL DEFAULT 0,
  `checkout_starts` INT UNSIGNED NOT NULL DEFAULT 0,
  `orders`        INT UNSIGNED NOT NULL DEFAULT 0,
  `cancelled_orders` INT UNSIGNED NOT NULL DEFAULT 0,
  `revenue`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `avg_order_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `search_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `term`        VARCHAR(120) NOT NULL,
  `results`     INT UNSIGNED NOT NULL DEFAULT 0,
  `visitor_hash` CHAR(32) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sl_term` (`term`),
  KEY `idx_sl_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sales per product / per variant straight from real orders (excludes cancelled)
CREATE OR REPLACE VIEW `v_sales_by_variant` AS
SELECT oi.product_id, oi.variant_id, oi.product_name, oi.variant_label,
       SUM(oi.quantity)   AS units,
       SUM(oi.line_total) AS revenue,
       COUNT(DISTINCT oi.order_id) AS orders_count,
       MIN(o.created_at)  AS first_sale,
       MAX(o.created_at)  AS last_sale
FROM order_items oi
JOIN orders o ON o.id = oi.order_id AND o.status <> 'cancelled'
GROUP BY oi.product_id, oi.variant_id, oi.product_name, oi.variant_label;

INSERT INTO `settings` (`key_name`,`value`) VALUES
  ('analytics_enabled','1'),
  ('analytics_retention_days','180'),
  ('analytics_exclude_admin','1'),
  ('analytics_exclude_bots','1')
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;


-- ################ 006_storefront_settings.sql ################
-- =======================================================
--  006 - Storefront CMS settings (controlled from admin) & indexes
--  Safe to re-run (idempotent). Works on MySQL 5.7+ / MariaDB 10.x.
--  Run AFTER schema.sql and migrations.sql.
--  phpMyAdmin: select the database first, then Import / SQL tab.
-- =======================================================
USE `php_admin_panel`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `storefront_banners` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(160) NOT NULL,
  `subtitle`   VARCHAR(255) DEFAULT NULL,
  `button_text` VARCHAR(60) DEFAULT NULL,
  `button_url` VARCHAR(255) DEFAULT NULL,
  `image`      VARCHAR(255) DEFAULT NULL,
  `placement`  ENUM('hero','promo_strip','category_row') NOT NULL DEFAULT 'hero',
  `position`   INT NOT NULL DEFAULT 0,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `starts_at`  DATETIME DEFAULT NULL,
  `ends_at`    DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sb_place` (`placement`,`status`,`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key_name`,`value`) VALUES
  ('store_logo',''),('store_favicon',''),
  ('store_tagline','Quality products, delivered to your door'),
  ('store_phone',''),('store_address',''),
  ('social_facebook',''),('social_instagram',''),('social_whatsapp',''),
  ('home_show_hero','1'),('home_show_categories','1'),('home_show_featured','1'),
  ('home_show_new_arrivals','1'),('home_show_best_sellers','1'),
  ('home_featured_count','8'),
  ('shop_products_per_page','12'),
  ('shop_default_sort','newest'),
  ('shop_show_filters','1'),
  ('shop_show_sale_badge','1'),
  ('shop_show_low_stock_badge','1'),
  ('announcement_bar_text',''),('announcement_bar_enabled','0'),
  ('footer_text',''),
  ('seo_default_title',''),('seo_default_description',''),
  ('maintenance_mode','0'),
  ('timezone','Asia/Dhaka')
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;

-- Helpful indexes for storefront/analytics queries
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD INDEX `idx_products_status_created` (`status`,`created_at`)',
  'SELECT ''products.idx_products_status_created exists'' AS info')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_status_created');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `products` ADD INDEX `idx_products_sold` (`sold_count`)',
  'SELECT ''products.idx_products_sold exists'' AS info')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_sold');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD INDEX `idx_orders_status_created` (`status`,`created_at`)',
  'SELECT ''orders.idx_orders_status_created exists'' AS info')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_status_created');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ################ 007_backfill_existing_data.sql ################
-- =======================================================
--  007 - Backfill existing data (run LAST, optional but recommended)
--  Safe to re-run (idempotent). Works on MySQL 5.7+ / MariaDB 10.x.
--  Run AFTER schema.sql and migrations.sql.
--  phpMyAdmin: select the database first, then Import / SQL tab.
-- =======================================================
USE `php_admin_panel`;
SET NAMES utf8mb4;

-- Existing products stay 'simple' (their old stock column keeps working).
UPDATE `products` SET `product_type` = 'simple' WHERE `product_type` IS NULL;

-- Seed order status history for already-existing orders
INSERT INTO `order_status_history` (`order_id`,`status`,`note`,`created_at`)
SELECT o.id, o.status, 'Imported from existing order', o.created_at
FROM `orders` o
WHERE NOT EXISTS (SELECT 1 FROM `order_status_history` h WHERE h.order_id = o.id);

-- Use the old single shipping_fee setting as the starting cost of the default methods (only if still default)
UPDATE `shipping_methods` sm
JOIN `settings` s ON s.key_name = 'shipping_fee' AND CAST(s.value AS DECIMAL(10,2)) > 0
SET sm.cost = CAST(s.value AS DECIMAL(10,2))
WHERE sm.name IN ('Inside Dhaka','Outside Dhaka') AND sm.cost IN (60.00,120.00);

-- Opening stock snapshot into the ledger, so reports start from a known point
INSERT INTO `inventory_movements` (`product_id`,`change_qty`,`stock_after`,`reason`,`note`)
SELECT p.id, p.stock, p.stock, 'correction', 'Opening balance at upgrade'
FROM `products` p
WHERE p.stock > 0
  AND NOT EXISTS (SELECT 1 FROM `inventory_movements` m WHERE m.product_id = p.id);

-- Recompute sold_count from real orders
UPDATE `products` p
LEFT JOIN (
  SELECT oi.product_id, SUM(oi.quantity) q
  FROM order_items oi JOIN orders o ON o.id = oi.order_id AND o.status <> 'cancelled'
  GROUP BY oi.product_id
) s ON s.product_id = p.id
SET p.sold_count = COALESCE(s.q, 0);

-- ================================================================
-- Migration 009: Performance Tuning & Monitoring Enhancements
-- ================================================================
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `slow_requests` ADD COLUMN `query_count` INT UNSIGNED DEFAULT NULL AFTER `duration_ms`',
  'SELECT ''slow_requests.query_count exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slow_requests' AND COLUMN_NAME = 'query_count');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- =======================================================
--  008 - Customer accounts (register, login, profile, addresses, wishlist, reset)
--  Safe to re-run. MySQL 5.7+ / MariaDB 10.x. Run AFTER 001..007.
--  Customers are a SEPARATE table from admin `users` on purpose.
-- =======================================================
USE `php_admin_panel`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `customers` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(120) NOT NULL,
  `email`            VARCHAR(190) NOT NULL,
  `phone`            VARCHAR(30)  DEFAULT NULL,
  `password_hash`    VARCHAR(255) NOT NULL,
  `status`           ENUM('active','pending','blocked') NOT NULL DEFAULT 'active',
  `email_verified_at` DATETIME DEFAULT NULL,
  `avatar`           VARCHAR(255) DEFAULT NULL,
  `marketing_opt_in` TINYINT(1) NOT NULL DEFAULT 0,
  `failed_logins`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`     DATETIME DEFAULT NULL,
  `last_login_at`    DATETIME DEFAULT NULL,
  `last_login_ip`    VARCHAR(45) DEFAULT NULL,
  `admin_note`       VARCHAR(500) DEFAULT NULL,
  `deleted_at`       DATETIME DEFAULT NULL COMMENT 'soft delete / anonymised',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_email` (`email`),
  KEY `idx_customer_status` (`status`),
  KEY `idx_customer_phone` (`phone`),
  KEY `idx_customer_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_addresses` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `label`       VARCHAR(40)  NOT NULL DEFAULT 'Home',
  `full_name`   VARCHAR(120) NOT NULL,
  `phone`       VARCHAR(30)  NOT NULL,
  `address`     VARCHAR(255) NOT NULL,
  `city`        VARCHAR(100) NOT NULL,
  `area`        VARCHAR(100) DEFAULT NULL,
  `zip`         VARCHAR(20)  DEFAULT NULL,
  `country`     VARCHAR(100) NOT NULL DEFAULT 'Bangladesh',
  `is_default`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ca_customer` (`customer_id`,`is_default`),
  CONSTRAINT `fk_ca_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One table for every single-use token. Only a SHA-256 HASH of the secret is stored.
CREATE TABLE IF NOT EXISTS `customer_tokens` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `type`        ENUM('verify_email','password_reset','remember_me','email_change') NOT NULL,
  `selector`    CHAR(24)  NOT NULL COMMENT 'public lookup part of the token',
  `token_hash`  CHAR(64)  NOT NULL COMMENT 'sha256 of secret part',
  `payload`     VARCHAR(190) DEFAULT NULL COMMENT 'e.g. the NEW email for email_change',
  `expires_at`  DATETIME NOT NULL,
  `used_at`     DATETIME DEFAULT NULL,
  `ip`          VARCHAR(45) DEFAULT NULL,
  `user_agent`  VARCHAR(200) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct_selector` (`selector`),
  KEY `idx_ct_customer_type` (`customer_id`,`type`),
  KEY `idx_ct_expires` (`expires_at`),
  CONSTRAINT `fk_ct_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting / brute-force log (no raw email: hashed)
CREATE TABLE IF NOT EXISTS `auth_attempts` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`       ENUM('login','register','forgot','reset','resend') NOT NULL,
  `identifier_hash` CHAR(64) NOT NULL COMMENT 'sha256(lowercase email) or sha256(ip)',
  `ip`          VARCHAR(45) NOT NULL,
  `success`     TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aa_scope_ident` (`scope`,`identifier_hash`,`created_at`),
  KEY `idx_aa_scope_ip`    (`scope`,`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wishlists` (
  `customer_id` INT UNSIGNED NOT NULL,
  `product_id`  INT UNSIGNED NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`customer_id`,`product_id`),
  KEY `idx_wl_product` (`product_id`),
  CONSTRAINT `fk_wl_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wl_product`  FOREIGN KEY (`product_id`)  REFERENCES `products`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link orders to a customer (guest orders keep customer_id NULL)
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD COLUMN `customer_id` INT UNSIGNED DEFAULT NULL AFTER `order_number`',
  'SELECT ''orders.customer_id exists'' AS info')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'customer_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `orders` ADD INDEX `idx_orders_customer` (`customer_id`,`created_at`)',
  'SELECT ''orders.idx_orders_customer exists'' AS info')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_customer');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

INSERT INTO `settings` (`key_name`,`value`) VALUES
  ('customer_registration_enabled','1'),
  ('customer_require_email_verification','1'),
  ('customer_block_login_until_verified','0'),
  ('customer_block_login_unverified','0'),
  ('customer_password_min_length','8'),
  ('customer_login_max_attempts','5'),
  ('customer_lockout_minutes','15'),
  ('customer_remember_me_days','30'),
  ('customer_reset_token_minutes','60'),
  ('customer_password_reset_minutes','60'),
  ('customer_verify_token_hours','24'),
  ('customer_session_idle_minutes','120'),
  ('customer_claim_guest_orders','1'),
  ('customer_claim_guest_orders_on_verify','1'),
  ('customer_allow_account_delete','1'),
  ('customer_can_cancel','1'),
  ('customer_wishlist_enabled','1'),
  ('customer_marketing_checkbox','1'),
  ('customer_marketing_checkbox_default','1'),
  ('customer_unverified_ttl_days','14'),
  ('customer_terms_url','')
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;

INSERT INTO `email_templates` (`slug`,`name`,`audience`,`subject`,`body_html`,`variables`) VALUES
 ('customer_welcome','Welcome (new account)','customer',
  'Welcome to {{site_name}}, {{customer_name}}!',
  '<h2>Welcome, {{customer_name}}!</h2><p>Your account is ready. You can now track orders, save addresses and keep a wishlist.</p><p><a href="{{account_url}}">Go to my account</a></p>',
  'customer_name, site_name, account_url'),
 ('customer_verify_email','Verify email address','customer',
  'Confirm your email for {{site_name}}',
  '<p>Hi {{customer_name}}, please confirm your email address:</p><p><a href="{{verify_url}}">Verify my email</a></p><p>This link expires in {{expires_in}}. If you did not create an account, ignore this email.</p>',
  'customer_name, verify_url, expires_in, site_name'),
 ('customer_password_reset','Password reset link','customer',
  'Reset your {{site_name}} password',
  '<p>Hi {{customer_name}}, we received a request to reset your password.</p><p><a href="{{reset_url}}">Choose a new password</a></p><p>This link expires in {{expires_in}} and works once. If you did not ask for this, you can ignore this email - your password stays the same.</p>',
  'customer_name, reset_url, expires_in, site_name'),
 ('customer_password_changed','Password changed notice','customer',
  'Your {{site_name}} password was changed',
  '<p>Hi {{customer_name}}, your password was just changed (IP {{ip}}, {{changed_at}}).</p><p>If this was not you, reset it immediately: <a href="{{reset_url}}">reset password</a> and contact us.</p>',
  'customer_name, ip, changed_at, reset_url, site_name'),
 ('customer_email_change','Confirm new email','customer',
  'Confirm your new email address',
  '<p>Hi {{customer_name}}, confirm this new email address for your account:</p><p><a href="{{verify_url}}">Confirm new email</a></p>',
  'customer_name, verify_url, site_name')
ON DUPLICATE KEY UPDATE `slug` = `slug`;
