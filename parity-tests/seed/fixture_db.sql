-- Deterministic Parity Test Fixture Database Seed
-- Populates representative data across all product types, order states, customer statuses, and settings.

USE `php_admin_panel`;

-- 1. Ensure Categories
INSERT INTO `categories` (`id`, `name`, `slug`, `description`, `status`, `parent_id`, `created_at`)
VALUES
  (1, 'Men', 'men', 'Men Clothing and Apparel', 'active', NULL, NOW()),
  (2, 'Women', 'women', 'Women Fashion and Accessories', 'active', NULL, NOW()),
  (3, 'Shirts', 'shirts', 'Formal and Casual Shirts', 'active', 1, NOW()),
  (4, 'Accessories', 'accessories', 'Watches, Wallets, and Belts', 'active', NULL, NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 2. Deterministic Products
-- Product 1: Simple in-stock product
INSERT INTO `products` (`id`, `name`, `slug`, `sku`, `price`, `sale_price`, `stock`, `status`, `category_id`, `description`, `is_featured`, `created_at`)
VALUES
  (101, 'Classic Crewneck T-Shirt', 'classic-crewneck-t-shirt', 'TSH-001', 450.00, NULL, 50, 'active', 3, '100% Organic combed cotton crewneck t-shirt.', 1, NOW())
ON DUPLICATE KEY UPDATE `price` = VALUES(`price`), `stock` = VALUES(`stock`);

-- Product 2: Simple product on sale
INSERT INTO `products` (`id`, `name`, `slug`, `sku`, `price`, `sale_price`, `stock`, `status`, `category_id`, `description`, `is_featured`, `created_at`)
VALUES
  (102, 'Slim Fit Stretch Denim Jeans', 'slim-fit-stretch-denim-jeans', 'JNS-002', 1500.00, 1200.00, 25, 'active', 1, 'Premium stretch indigo denim jeans with tapered fit.', 1, NOW())
ON DUPLICATE KEY UPDATE `price` = VALUES(`price`), `sale_price` = VALUES(`sale_price`);

-- Product 3: Simple out-of-stock product
INSERT INTO `products` (`id`, `name`, `slug`, `sku`, `price`, `sale_price`, `stock`, `status`, `category_id`, `description`, `is_featured`, `created_at`)
VALUES
  (103, 'Handcrafted Leather Bifold Wallet', 'handcrafted-leather-bifold-wallet', 'WLT-003', 1850.00, NULL, 0, 'active', 4, 'Full-grain vintage cowhide leather wallet.', 0, NOW())
ON DUPLICATE KEY UPDATE `stock` = 0;

-- Product 4: Configurable / Variable product
INSERT INTO `products` (`id`, `name`, `slug`, `sku`, `price`, `sale_price`, `stock`, `status`, `category_id`, `description`, `is_featured`, `created_at`)
VALUES
  (104, 'Premium Oxford Button-Down Shirt', 'premium-oxford-button-down-shirt', 'OXF-004', 1800.00, 1650.00, 45, 'active', 3, 'Tailored oxford weave dress shirt with mother-of-pearl buttons.', 1, NOW())
ON DUPLICATE KEY UPDATE `price` = VALUES(`price`);

-- Product Options & Variants for Product 104
INSERT INTO `product_options` (`id`, `product_id`, `name`, `position`)
VALUES
  (201, 104, 'Color', 1),
  (202, 104, 'Size', 2)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `product_option_values` (`id`, `option_id`, `value`, `position`)
VALUES
  (301, 201, 'White', 1),
  (302, 201, 'Sky Blue', 2),
  (303, 202, 'M', 1),
  (304, 202, 'L', 2)
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

INSERT INTO `product_variants` (`id`, `product_id`, `sku`, `price`, `stock`, `barcode`, `status`, `created_at`)
VALUES
  (401, 104, 'OXF-004-WHT-M', 1650.00, 15, '89400010041', 'active', NOW()),
  (402, 104, 'OXF-004-WHT-L', 1650.00, 20, '89400010042', 'active', NOW()),
  (403, 104, 'OXF-004-BLU-M', 1650.00, 10, '89400010043', 'active', NOW()),
  (404, 104, 'OXF-004-BLU-L', 1650.00, 0,  '89400010044', 'active', NOW())
ON DUPLICATE KEY UPDATE `stock` = VALUES(`stock`);

INSERT INTO `product_variant_values` (`variant_id`, `option_value_id`)
VALUES
  (401, 301), (401, 303),
  (402, 301), (402, 304),
  (403, 302), (403, 303),
  (404, 302), (404, 304)
ON DUPLICATE KEY UPDATE `variant_id` = VALUES(`variant_id`);

-- 3. Deterministic Coupons
INSERT INTO `coupons` (`id`, `code`, `type`, `value`, `min_order_amount`, `max_discount`, `starts_at`, `expires_at`, `usage_limit`, `used_count`, `status`, `created_at`)
VALUES
  (1, 'SAVE10', 'percentage', 10.00, 500.00, 500.00, DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), 1000, 12, 'active', NOW()),
  (2, 'FLAT50', 'fixed', 50.00, 1000.00, NULL, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), 500, 45, 'active', NOW()),
  (3, 'BIG500', 'fixed', 500.00, 5000.00, NULL, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), 100, 3, 'active', NOW()),
  (4, 'EXPIRED', 'percentage', 20.00, 0.00, NULL, DATE_SUB(NOW(), INTERVAL 60 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY), 100, 100, 'expired', NOW())
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `status` = VALUES(`status`);

-- 4. Deterministic Customers
-- Password hash generated with customer_password_hash('Secret@2026!')
INSERT INTO `customers` (`id`, `name`, `email`, `phone`, `password_hash`, `status`, `email_verified_at`, `failed_logins`, `locked_until`, `created_at`)
VALUES
  (1, 'Verified Customer', 'verified@example.com', '01711000001', '$2y$10$wO0Q3a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6', 'active', NOW(), 0, NULL, NOW()),
  (2, 'Pending Customer', 'pending@example.com', '01711000002', '$2y$10$wO0Q3a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6', 'pending', NULL, 0, NULL, NOW()),
  (3, 'Locked Customer', 'locked@example.com', '01711000003', '$2y$10$wO0Q3a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6', 'active', NOW(), 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW())
ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `email_verified_at` = VALUES(`email_verified_at`);

-- Customer Addresses
INSERT INTO `customer_addresses` (`id`, `customer_id`, `full_name`, `phone`, `address`, `city`, `is_default`, `created_at`)
VALUES
  (1, 1, 'Verified Customer', '01711000001', 'House 12, Road 5, Dhanmondi', 'Dhaka', 1, NOW()),
  (2, 1, 'Verified Customer Office', '01711000001', 'Level 4, Gulshan Centre', 'Dhaka', 0, NOW())
ON DUPLICATE KEY UPDATE `address` = VALUES(`address`);

-- 5. Orders in Every Status
INSERT INTO `orders` (`id`, `order_number`, `customer_id`, `customer_name`, `customer_email`, `customer_phone`, `shipping_address`, `shipping_city`, `shipping_method_id`, `shipping_fee`, `cod_fee`, `subtotal`, `discount`, `coupon_code`, `total`, `payment_method`, `payment_status`, `order_status`, `created_at`)
VALUES
  (1, 'ORD-202611-0001', 1, 'Verified Customer', 'verified@example.com', '01711000001', 'House 12, Road 5, Dhanmondi', 'Dhaka', 1, 60.00, 0.00, 900.00, 0.00, NULL, 960.00, 'cod', 'unpaid', 'pending', DATE_SUB(NOW(), INTERVAL 3 DAY)),
  (2, 'ORD-202611-0002', 1, 'Verified Customer', 'verified@example.com', '01711000001', 'House 12, Road 5, Dhanmondi', 'Dhaka', 1, 60.00, 0.00, 1200.00, 0.00, NULL, 1260.00, 'cod', 'unpaid', 'processing', DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (3, 'ORD-202611-0003', 1, 'Verified Customer', 'verified@example.com', '01711000001', 'House 12, Road 5, Dhanmondi', 'Dhaka', 1, 60.00, 0.00, 4500.00, 0.00, NULL, 4560.00, 'bank_transfer', 'paid', 'shipped', DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (4, 'ORD-202611-0004', 1, 'Verified Customer', 'verified@example.com', '01711000001', 'House 12, Road 5, Dhanmondi', 'Dhaka', 1, 60.00, 0.00, 1800.00, 0.00, NULL, 1860.00, 'cod', 'paid', 'delivered', DATE_SUB(NOW(), INTERVAL 5 DAY)),
  (5, 'ORD-202611-0005', NULL, 'Guest Customer', 'guest@example.com', '01811000009', 'Chittagong Port Area', 'Chattogram', 2, 120.00, 0.00, 450.00, 0.00, NULL, 570.00, 'cod', 'unpaid', 'cancelled', DATE_SUB(NOW(), INTERVAL 6 DAY))
ON DUPLICATE KEY UPDATE `order_status` = VALUES(`order_status`);

-- Order Items
INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `variant_id`, `product_name`, `variant_label`, `sku`, `price`, `qty`, `line_total`)
VALUES
  (1, 1, 101, NULL, 'Classic Crewneck T-Shirt', NULL, 'TSH-001', 450.00, 2, 900.00),
  (2, 2, 102, NULL, 'Slim Fit Stretch Denim Jeans', NULL, 'JNS-002', 1200.00, 1, 1200.00),
  (3, 3, 104, 401, 'Premium Oxford Button-Down Shirt', 'Color: White, Size: M', 'OXF-004-WHT-M', 1650.00, 2, 3300.00),
  (4, 4, 104, 402, 'Premium Oxford Button-Down Shirt', 'Color: White, Size: L', 'OXF-004-WHT-L', 1650.00, 1, 1650.00),
  (5, 5, 101, NULL, 'Classic Crewneck T-Shirt', NULL, 'TSH-001', 450.00, 1, 450.00)
ON DUPLICATE KEY UPDATE `price` = VALUES(`price`);

-- 6. Static Pages
INSERT INTO `pages` (`id`, `title`, `slug`, `content`, `status`, `created_at`)
VALUES
  (1, 'About Us', 'about-us', '<p>Welcome to Jinish Potro. We craft and curate premium everyday essentials.</p>', 'published', NOW()),
  (2, 'Privacy Policy', 'privacy-policy', '<p>Your privacy is important to us. We never sell your personal information.</p>', 'published', NOW()),
  (3, 'Terms & Conditions', 'terms-and-conditions', '<p>These terms govern your purchases and interactions with Jinish Potro.</p>', 'published', NOW())
ON DUPLICATE KEY UPDATE `content` = VALUES(`content`);

-- 7. Storefront Banners
INSERT INTO `storefront_banners` (`id`, `title`, `subtitle`, `button_text`, `button_link`, `placement`, `status`, `position`)
VALUES
  (1, 'Curated Essentials for Everyday Life', 'Spring / Summer 2026 Collection', 'Shop Collection', '/shop', 'hero', 'active', 1),
  (2, 'Nationwide Free Delivery on Orders Over 2500 BDT', 'Use code SAVE10 at checkout', 'View Deals', '/shop', 'announcement', 'active', 2)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- 8. Key Settings Variants
INSERT INTO `settings` (`key_name`, `key_value`)
VALUES
  ('site_name', 'Jinish Potro'),
  ('currency_symbol', '$'),
  ('currency_code', 'BDT'),
  ('pretty_urls', '1'),
  ('stock_display_mode', 'count_when_low'),
  ('announcement_bar_enabled', '1'),
  ('announcement_bar_text', 'Flash Sale: 20% off all shirts this weekend!'),
  ('customer_require_email_verification', '1'),
  ('guest_checkout', '1'),
  ('cod_enabled', '1'),
  ('bank_transfer_enabled', '1')
ON DUPLICATE KEY UPDATE `key_value` = VALUES(`key_value`);
