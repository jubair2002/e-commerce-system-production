-- Migration: 001_storage_layer.down.sql
-- Description: Revert files table and remove file_id foreign keys

ALTER TABLE `order_items`
  DROP FOREIGN KEY `fk_order_items_file_id`,
  DROP COLUMN `file_id`;

ALTER TABLE `storefront_banners`
  DROP FOREIGN KEY `fk_storefront_banners_file_id`,
  DROP COLUMN `file_id`;

ALTER TABLE `users`
  DROP FOREIGN KEY `fk_users_avatar_file_id`,
  DROP COLUMN `avatar_file_id`;

ALTER TABLE `customers`
  DROP FOREIGN KEY `fk_customers_avatar_file_id`,
  DROP COLUMN `avatar_file_id`;

ALTER TABLE `product_variants`
  DROP FOREIGN KEY `fk_product_variants_file_id`,
  DROP COLUMN `file_id`;

ALTER TABLE `product_images`
  DROP FOREIGN KEY `fk_product_images_file_id`,
  DROP COLUMN `file_id`;

ALTER TABLE `products`
  DROP FOREIGN KEY `fk_products_file_id`,
  DROP COLUMN `file_id`;

DROP TABLE IF EXISTS `files`;

