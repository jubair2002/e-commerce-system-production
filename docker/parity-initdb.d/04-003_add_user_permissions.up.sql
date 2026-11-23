USE `php_admin_panel`;

-- Migration: 003_add_user_permissions
-- Description: Add permissions JSON column and broaden role column on users table

ALTER TABLE `users`
  MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'editor',
  ADD COLUMN `permissions` JSON NULL AFTER `role`;

-- Grant full permission wildcard to existing admin users
UPDATE `users` SET `permissions` = JSON_ARRAY('*') WHERE `role` = 'admin';
