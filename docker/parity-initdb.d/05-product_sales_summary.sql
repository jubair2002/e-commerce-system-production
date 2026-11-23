USE `php_admin_panel`;

CREATE TABLE IF NOT EXISTS `product_sales_summary` (
  `product_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `variant_label` VARCHAR(255) DEFAULT NULL,
  `units` INT UNSIGNED NOT NULL DEFAULT 0,
  `revenue` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `orders_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_sale` DATETIME DEFAULT NULL,
  PRIMARY KEY (`product_id`, `variant_id`),
  KEY `idx_pss_units` (`units`),
  KEY `idx_pss_revenue` (`revenue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
