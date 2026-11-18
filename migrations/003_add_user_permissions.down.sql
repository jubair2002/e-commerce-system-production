-- Migration Rollback: 003_add_user_permissions

ALTER TABLE `users`
  DROP COLUMN `permissions`,
  MODIFY COLUMN `role` ENUM('admin','editor','user') NOT NULL DEFAULT 'user';
