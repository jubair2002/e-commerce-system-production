-- Migration: 001_storage_layer.up.sql
-- Description: Create files table and add file_id foreign keys to existing tables

CREATE TABLE IF NOT EXISTS `files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `storage_key` VARCHAR(512) NOT NULL,
  `provider` ENUM('local','s3') NOT NULL DEFAULT 'local',
  `bucket` VARCHAR(128) NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(127) NOT NULL,
  `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `checksum_sha256` CHAR(64) NULL,
  `width` INT NULL,
  `height` INT NULL,
  `visibility` ENUM('public','private') NOT NULL DEFAULT 'public',
  `purpose` VARCHAR(32) NOT NULL,
  `owner_type` VARCHAR(32) NULL,
  `owner_id` BIGINT UNSIGNED NULL,
  `status` ENUM('pending','ready','failed','deleted') NOT NULL DEFAULT 'ready',
  `sync_status` ENUM('synced','pending','failed') NOT NULL DEFAULT 'synced',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_provider_storage_key` (`provider`, `storage_key`(250)),
  KEY `idx_files_owner` (`owner_type`, `owner_id`),
  KEY `idx_files_purpose` (`purpose`),
  KEY `idx_files_status_sync` (`status`, `sync_status`),
  KEY `idx_files_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add file_id to products
ALTER TABLE `products`
  ADD COLUMN `file_id` BIGINT UNSIGNED NULL AFTER `image`,
  ADD CONSTRAINT `fk_products_file_id` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL;

-- Add file_id to product_images
ALTER TABLE `product_images`
  ADD COLUMN `file_id` BIGINT UNSIGNED NULL AFTER `filename`,
  ADD CONSTRAINT `fk_product_images_file_id` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE;

-- Add file_id to product_variants
ALTER TABLE `product_variants`
  ADD COLUMN `file_id` BIGINT UNSIGNED NULL AFTER `image`,
  ADD CONSTRAINT `fk_product_variants_file_id` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL;

-- Add avatar_file_id to customers
ALTER TABLE `customers`
  ADD COLUMN `avatar_file_id` BIGINT UNSIGNED NULL AFTER `avatar`,
  ADD CONSTRAINT `fk_customers_avatar_file_id` FOREIGN KEY (`avatar_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL;

-- Add avatar_file_id to users
ALTER TABLE `users`
  ADD COLUMN `avatar_file_id` BIGINT UNSIGNED NULL AFTER `avatar`,
  ADD CONSTRAINT `fk_users_avatar_file_id` FOREIGN KEY (`avatar_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL;

-- Add file_id to storefront_banners
ALTER TABLE `storefront_banners`
  ADD COLUMN `file_id` BIGINT UNSIGNED NULL AFTER `image`,
  ADD CONSTRAINT `fk_storefront_banners_file_id` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL;

-- Add file_id to order_items
ALTER TABLE `order_items`
  ADD COLUMN `file_id` BIGINT UNSIGNED NULL AFTER `product_image`,
  ADD CONSTRAINT `fk_order_items_file_id` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL;

