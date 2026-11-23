# Verified Facts: Single Source of Truth

This document resolves every architectural and implementation ambiguity identified in `docs/MIGRATION_INTEL.md` by referencing the actual PHP application source code as the sole source of truth.

---

## 1. Cart Session Key Format & Contract

- **Authoritative PHP Implementation**: `includes/shop_bootstrap.php:156-174`
  ```php
  function cart_key(int $productId, ?int $variantId = null): string {
      $vid = ($variantId !== null && $variantId > 0) ? $variantId : 0;
      return "{$productId}:{$vid}";
  }

  function cart_parse_key(string $key): array {
      $parts = explode(':', (string)$key, 2);
      return [
          'product_id' => (int)($parts[0] ?? 0),
          'variant_id' => ((int)($parts[1] ?? 0) > 0 ? (int)$parts[1] : null),
      ];
  }
  ```
- **Cart Key Format**: `"{productId}:{variantId}"` where `$variantId` is `0` for simple products (e.g., `"12:0"` for simple product 12, `"12:45"` for variant 45 of product 12).
- **Frontend Contract (`assets/js/shop.js:239`, `api/cart_ajax.php:155-175`)**:
  The HTML markup sets `data-cart-key="<productId>:<variantId>"` on mini-cart items and quantity buttons, and `data-cart-remove="<productId>:<variantId>"` on remove buttons.
- **Legacy Migration (`includes/shop_bootstrap.php:179`)**:
  A backward compatibility migration routine `cart_migrate()` transforms legacy purely numeric keys `[pid => qty]` into `"pid:0" => qty`.
- **Verdict**: The format `p{id}/p{id}_v{id}` reported in `MIGRATION_INTEL.md` was incorrect. The only valid format is `{id}:{vid}`.

---

## 2. Remember-Me Cookie Name & Rotation Behavior

- **Authoritative PHP Implementation**: `includes/customer_auth.php:367-372, 420-431, 630-727`
- **Cookie Name**: Strictly `customer_remember` (NOT `c_remember` or `remember_me`).
- **Cookie Flags**: `HttpOnly = true`, `SameSite = 'Lax'`, `Path = '/'`, `Secure = FORCE_HTTPS`.
- **Token Composition**:
  - Raw token format: `"{selector}:{validator}"`
  - `selector`: 16 random bytes encoded as 32 hex characters (`bin2hex(random_bytes(16))`).
  - `validator`: 32 random bytes encoded as 64 hex characters (`bin2hex(random_bytes(32))`).
  - Storage in `customer_tokens` table: `type = 'remember_me'`, `selector = :sel`, `token_hash = hash('sha256', $validator)`.
- **Rotation Behavior (`includes/customer_auth.php:713-715`)**:
  Every time a customer session is restored from the cookie via `customer_remember_restore()`:
  1. The existing token is consumed/deleted.
  2. A new token pair is issued for the configured lifetime (`setting('customer_remember_me_days', '30') * 86400`).
  3. A new `customer_remember` cookie is sent in the HTTP response headers.
- **Revocation**:
  On logout, password reset, or account lockout, all active tokens of type `remember_me` are deleted via `DELETE FROM customer_tokens WHERE customer_id = :id AND type = 'remember_me'`.
- **Admin Authentication**:
  The admin panel (`admin/login.php`, `admin/includes/auth.php`) has **NO remember-me cookie**. Admin access is strictly ephemeral session-based (`$_SESSION['user_id']`).

---

## 3. Database Table Inventory & Runtime Alterations

### Schema Comparison: `sql/schema.sql` (39) vs Live Database (43)
1. Baseline Tables in `sql/schema.sql` (39):
   `activity_log`, `analytics_events`, `auth_attempts`, `categories`, `contact_messages`, `coupons`, `customer_addresses`, `customer_tokens`, `customers`, `email_queue`, `email_templates`, `inventory_movements`, `monitor_alert_rules`, `network_samples`, `newsletter_subscribers`, `option_preset_values`, `option_presets`, `order_items`, `order_status_history`, `orders`, `pages`, `product_images`, `product_option_values`, `product_options`, `product_reviews`, `product_stats_daily`, `product_variant_values`, `product_variants`, `products`, `search_log`, `settings`, `shipping_methods`, `site_stats_daily`, `slow_requests`, `storefront_banners`, `system_metrics`, `traffic_minute`, `users`, `wishlists`.
2. Storage Layer Table (`migrations/001_storage_layer.up.sql`):
   `files`.
3. Migration Metadata Table (`bin/migrate.php:21`):
   `schema_migrations`.
4. Runtime Missing Table:
   `product_sales_summary` (actively referenced across `index.php`, `cron/rebuild_sales_summary.php`, `includes/catalog.php`, and `admin/order_view.php`; added via `rust-backend/migrations/0002_product_sales_summary.sql`).
5. Rust Session Store Table:
   `tower_sessions` (added via `rust-backend/migrations/0001_tower_sessions.sql`).

### Discrepancy Resolutions:
- `activity_log` vs `activity_logs`: Table in `sql/schema.sql:16` and all PHP queries (`includes/functions.php:518`, `admin/activity.php:11`) is strictly `activity_log` (singular).
- `analytics_summary`: **Does NOT exist.** `cron/aggregate_analytics.php:42,75` rolls up `analytics_events` directly into `product_stats_daily` and `site_stats_daily`.
- Runtime Alterations: `cron/collect_metrics.php:150` performs dynamic `ALTER TABLE system_metrics ADD COLUMN ...` if columns are missing.

---

## 4. Authoritative Route Map & Clean Pretty URLs Contract

### Routing Engine Behavior (`includes/functions.php:79-176`, `.htaccess`)
- `pretty_urls_active()` returns true if `setting('pretty_urls', '0') == 1` OR file `storage/pretty_urls.on` exists.
- 301 Legacy Redirects: Requests to legacy `.php` endpoints are permanently redirected (301) to the clean route.

| Route Name | Target File | Clean Pretty Pattern | Legacy PHP File |
| :--- | :--- | :--- | :--- |
| `home` | `index.php` | `/` | `/index.php` |
| `shop` | `storefront/shop/index.php` | `/shop` | `/shop.php` |
| `category` | `storefront/shop/category.php` | `/category/{slug}` | `/category.php?slug={slug}` |
| `product` | `storefront/shop/product.php` | `/product/{slug}` | `/product.php?slug={slug}` |
| `cart` | `storefront/cart/index.php` | `/cart` | `/cart.php` |
| `checkout` | `storefront/cart/checkout.php` | `/checkout` | `/checkout.php` |
| `order_confirmation`| `storefront/cart/confirmation.php` | `/order/confirmation` | `/order_confirmation.php` |
| `track` | `storefront/track/index.php` | `/track` | `/track.php` |
| `login` | `storefront/auth/login.php` | `/login` | `/login.php` |
| `register` | `storefront/auth/register.php` | `/register` | `/register.php` |
| `logout` | `storefront/auth/logout.php` | `/logout` | `/logout.php` |
| `forgot_password` | `storefront/auth/forgot-password.php`| `/forgot-password` | `/forgot_password.php` |
| `reset_password` | `storefront/auth/reset-password.php` | `/reset-password` | `/reset_password.php` |
| `verify_email` | `storefront/auth/verify-email.php` | `/verify-email` | `/verify_email.php` |
| `resend_verification`|`storefront/auth/resend-verification.php`|`/resend-verification`| `/resend_verification.php`|
| `account` | `account/index.php` | `/account` | `/account/index.php` |
| `account_orders` | `account/orders.php` | `/account/orders` | `/account/orders.php` |
| `account_order` | `account/order.php` | `/account/order` | `/account/order.php` |
| `account_invoice` | `account/invoice.php` | `/account/invoice` | `/account/invoice.php` |
| `account_addresses`| `account/addresses.php` | `/account/addresses` | `/account/addresses.php` |
| `account_profile` | `account/profile.php` | `/account/profile` | `/account/profile.php` |
| `account_security` | `account/security.php` | `/account/security` | `/account/security.php` |
| `account_wishlist` | `account/wishlist.php` | `/account/wishlist` | `/account/wishlist.php` |
| `account_export` | `account/export.php` | `/account/export` | `/account/export.php` |
| `account_delete` | `account/delete.php` | `/account/delete` | `/account/delete.php` |
| `page` | `storefront/pages/view.php` | `/page/{slug}` | `/page.php?slug={slug}` |
| `contact` | `storefront/pages/contact.php` | `/contact` | `/contact.php` |
| `newsletter` | `storefront/pages/newsletter.php` | `/newsletter` | `/newsletter.php` |
| `sitemap` | `storefront/seo/sitemap.php` | `/sitemap.xml` | `/sitemap.php` |
| `maintenance` | `storefront/errors/maintenance.php` | `/maintenance` | `/maintenance.php` |
| `404` | `storefront/errors/404.php` | `/404` | `/404.php` |
| `500` | `storefront/errors/500.php` | `/500` | `/500.php` |

---

## 5. Cryptographic Specifications & Event Hooks

### 1. Symmetric Secret Encryption (`includes/crypto.php`)
- **Algorithm**: **AES-256-CBC** with PKCS#7 padding (NOT AES-256-GCM).
- **Key Derivation**: `hash('sha256', APP_KEY, true)` (32 bytes raw binary).
- **IV**: 16 cryptographically random bytes (`random_bytes(16)`).
- **Encoding**: `base64_encode(iv . ciphertext)`.
- **Decryption**: Decodes base64, splits first 16 bytes as IV, decrypts remainder with key and IV.

### 2. Order Confirmation HMAC Token (`storefront/cart/checkout.php:461`, `confirmation.php:9`)
- **Formula**: `hash_hmac('sha256', $orderNumber, $appKey)`
- **Key**: `APP_KEY` from environment.
- **Verification**: `hash_equals($expectedToken, $token)` or bypass if `$_SESSION['last_placed_order'] === $orderNumber`.

### 3. Anti-Replay Checkout Token (`storefront/cart/checkout.php:64, 178, 445`)
- Generated upon rendering checkout form: `$_SESSION['checkout_token'] = bin2hex(random_bytes(16))`.
- Submitted as hidden POST field `checkout_token`.
- Validated via `hash_equals($_SESSION['checkout_token'] ?? '', $submittedToken)`.
- Destroyed immediately after transaction commit: `unset($_SESSION['checkout_token'])`.

### 4. Registered Event Hooks (`includes/hooks.php`)
| Hook Event | Registered Listeners | Purpose |
| :--- | :--- | :--- |
| `order.placed` | `includes/mailer.php:507`, `includes/analytics.php:264` | Enqueues order confirmation email & logs purchase analytics |
| `order.status_changed` | `includes/mailer.php:573` | Enqueues order status update email to customer |
| `stock.low` | `includes/mailer.php:615` | Enqueues low stock warning email to store admin |
| `product.viewed` | `includes/analytics.php:228` | Logs product page view analytics event |
| `cart.added` | `includes/analytics.php:237` | Logs add-to-cart analytics event |
| `cart.removed` | `includes/analytics.php:248` | Logs remove-from-cart analytics event |
| `checkout.started`| `includes/analytics.php:258` | Logs checkout initiation analytics event |

---

## 6. HTML Emitted Outside Views

1. **AJAX Mini-Cart Drawer (`api/cart_ajax.php:140-180`)**:
   Returns JSON with an `html` property containing server-rendered DOM for `<div class="mini-cart-item">...</div>` with buttons and inputs.
2. **Dynamic Media Delivery (`uploads.php`)**:
   Emits binary image data or on-the-fly inline SVG fallback placeholders (`<svg ...>...</svg>`) with `Content-Type: image/svg+xml`.
3. **Availability SSE Stream (`api/availability.php:125-135`)**:
   Emits `text/event-stream` format (`data: {"product_id": ..., "in_stock": ...}\n\n`).
4. **Sitemap XML (`storefront/seo/sitemap.php`)**:
   Emits XML markup with `Content-Type: application/xml`.
5. **CSV Product Export (`admin/product_export.php`)**:
   Emits raw CSV lines with RFC-4180 escaping and `Content-Type: text/csv`.
6. **Prometheus Metrics (`metrics.php`)**:
   Emits standard Prometheus exposition text format with `Content-Type: text/plain`.
7. **HTTP Redirects (`includes/functions.php:216`)**:
   Emits clean `Location: <url>` headers with exit; does not emit HTML redirect bodies.

---

## 7. The 142-Key Settings Contract

| Setting Key | Type | Default Value (Seed) | Default Fallback (Code) | Primary Consumer |
| :--- | :--- | :--- | :--- | :--- |
| `analytics_enabled` | `bool (0/1)` | `-` | `1` | `includes/analytics.php` |
| `analytics_exclude_admin` | `bool (0/1)` | `-` | `1` | `includes/analytics.php` |
| `analytics_exclude_bots` | `bool (0/1)` | `-` | `1` | `includes/analytics.php` |
| `analytics_respect_dnt` | `bool (0/1)` | `-` | `0` | `includes/analytics.php` |
| `analytics_retention_days` | `int` | `-` | `180` | `cron/cleanup_logs.php` |
| `announcement_bar_enabled` | `bool (0/1)` | `-` | `0` | `includes/partials/header.legacy.php` |
| `announcement_bar_text` | `string` | `-` | `` | `includes/partials/header.legacy.php` |
| `antispam_orders_limit` | `int` | `-` | `5` | `storefront/cart/checkout.php` |
| `apache_access_log_path` | `string` | `-` | `null` | `cron/parse_access_log.php` |
| `backorder_text` | `string` | `-` | `Ships in 5-7 days` | `api/availability.php` |
| `bank_transfer_enabled` | `bool (0/1)` | `0` | `0` | `storefront/cart/checkout.php` |
| `bank_transfer_instructions` | `string` | `` | `Please transfer the total amount to our bank account. Use your order number as reference.` | `storefront/cart/confirmation.php` |
| `brand_story_text` | `string` | `-` | `We believe everyday objects should be both functional and beautiful. Every piece in our collection is curated with care and designed to last.` | `admin/settings.php` |
| `brand_story_title` | `string` | `-` | `Crafted with Intention` | `admin/settings.php` |
| `cart_reserve_minutes` | `int` | `0` | `-` | `sql/schema.sql` |
| `cod_enabled` | `bool (0/1)` | `1` | `1` | `includes/shop_bootstrap.php` |
| `cod_fee` | `decimal` | `0.00` | `0.00` | `includes/shop_bootstrap.php` |
| `cod_max_order` | `decimal` | `0` | `0` | `storefront/cart/checkout.php` |
| `cod_min_order` | `decimal` | `0` | `0` | `storefront/cart/checkout.php` |
| `contact_email` | `string` | `-` | `setting('site_email` | `api/review.php` |
| `contact_notify_admin` | `decimal` | `-` | `1` | `storefront/pages/contact.php` |
| `contact_phone` | `string` | `-` | `setting('store_phone` | `includes/partials/footer.legacy.php` |
| `currency_code` | `string` | `-` | `BDT` | `admin/settings.php` |
| `currency_symbol` | `string` | `-` | `$` | `includes/functions.php` |
| `customer_allow_account_delete` | `bool (0/1)` | `-` | `1` | `account/security.php` |
| `customer_block_login_unverified` | `string` | `-` | `setting('customer_block_login_until_verified', '0` | `includes/customer_auth.php` |
| `customer_can_cancel` | `bool (0/1)` | `-` | `1` | `account/order.php` |
| `customer_claim_guest_orders_on_verify` | `string` | `-` | `setting('customer_claim_guest_orders', '1` | `includes/customer_auth.php` |
| `customer_lockout_minutes` | `int` | `-` | `15` | `includes/customer_auth.php` |
| `customer_login_max_attempts` | `int` | `-` | `5` | `includes/customer_auth.php` |
| `customer_marketing_checkbox` | `string` | `-` | `1` | `storefront/auth/register.php` |
| `customer_marketing_checkbox_default` | `string` | `-` | `1` | `admin/settings.php` |
| `customer_password_min_length` | `decimal` | `-` | `8` | `includes/customer_auth.php` |
| `customer_password_reset_minutes` | `int` | `-` | `setting('customer_reset_token_minutes', '60` | `includes/customer_auth.php` |
| `customer_registration_enabled` | `string` | `-` | `1` | `includes/customer_auth.php` |
| `customer_remember_me_days` | `int` | `-` | `30` | `includes/customer_auth.php` |
| `customer_require_email_verification` | `bool (0/1)` | `-` | `1` | `includes/customer_auth.php` |
| `customer_session_idle_minutes` | `int` | `-` | `120` | `includes/customer_auth.php` |
| `customer_terms_url` | `string` | `-` | `` | `includes/customer_auth.php` |
| `customer_unverified_ttl_days` | `int` | `-` | `14` | `cron/customer_maintenance.php` |
| `customer_verify_token_hours` | `int` | `-` | `48` | `includes/customer_auth.php` |
| `customer_wishlist_enabled` | `bool (0/1)` | `-` | `1` | `api/wishlist.php` |
| `dark_mode_enabled` | `string` | `-` | `1` | `admin/settings.php` |
| `footer_text` | `string` | `-` | `` | `includes/partials/footer.legacy.php` |
| `guest_checkout` | `string` | `1` | `1` | `storefront/auth/login.php` |
| `home_featured_count` | `int` | `-` | `8` | `admin/settings.php` |
| `home_new_arrivals_title` | `string` | `-` | `New Arrivals` | `index.php` |
| `home_show_best_sellers` | `string` | `-` | `1` | `admin/settings.php` |
| `home_show_brand_story` | `string` | `-` | `1` | `admin/settings.php` |
| `home_show_categories` | `string` | `-` | `1` | `admin/settings.php` |
| `home_show_featured` | `string` | `-` | `1` | `admin/settings.php` |
| `home_show_hero` | `string` | `-` | `1` | `admin/settings.php` |
| `home_show_new_arrivals` | `string` | `-` | `1` | `admin/settings.php` |
| `home_show_newsletter` | `string` | `-` | `1` | `admin/settings.php` |
| `home_show_reviews` | `string` | `-` | `1` | `admin/settings.php` |
| `image_pipeline_enabled` | `string` | `-` | `1` | `includes/images.php` |
| `items_per_page` | `string` | `-` | `10` | `admin/settings.php` |
| `low_stock_threshold` | `decimal` | `-` | `5` | `admin/settings.php` |
| `mail_admin_notify` | `decimal` | `-` | `1` | `includes/mailer.php` |
| `mail_admin_recipients` | `decimal` | `-` | `setting('site_email', 'admin@example.com` | `cron/collect_metrics.php` |
| `mail_driver` | `string` | `-` | `smtp` | `includes/mailer.php` |
| `mail_enabled` | `bool (0/1)` | `-` | `0` | `cron/process_mail_queue.php` |
| `mail_encryption` | `string` | `-` | `tls` | `includes/mailer.php` |
| `mail_footer_text` | `string` | `-` | `` | `includes/mailer.php` |
| `mail_from_email` | `string` | `-` | `setting('site_email', 'noreply@example.com` | `includes/mailer.php` |
| `mail_from_name` | `string` | `-` | `setting('site_name', APP_NAME` | `includes/mailer.php` |
| `mail_host` | `string` | `-` | `null` | `cron/process_mail_queue.php` |
| `mail_last_run` | `string` | `-` | `0` | `includes/mailer.php` |
| `mail_max_attempts` | `int` | `-` | `3` | `includes/mailer.php` |
| `mail_notify_low_stock` | `string` | `-` | `1` | `includes/mailer.php` |
| `mail_password_enc` | `string` | `-` | `` | `includes/mailer.php` |
| `mail_port` | `int` | `-` | `587` | `includes/mailer.php` |
| `mail_reply_to` | `string` | `-` | `` | `includes/mailer.php` |
| `mail_send_mode` | `string` | `-` | `instant` | `includes/mailer.php` |
| `mail_timeout` | `string` | `-` | `8` | `includes/mailer.php` |
| `mail_username` | `string` | `-` | `null` | `cron/process_mail_queue.php` |
| `maintenance_mode` | `string` | `-` | `0` | `includes/shop_bootstrap.php` |
| `mobile_bottom_nav` | `string` | `-` | `1` | `includes/partials/footer.legacy.php` |
| `monitor_bandwidth_quota_gb` | `string` | `-` | `0` | `admin/ajax/monitor_data.php` |
| `monitor_external_embed` | `string` | `-` | `0` | `admin/settings.php` |
| `monitor_external_url` | `string` | `-` | `` | `admin/settings.php` |
| `monitor_retention_days` | `int` | `-` | `14` | `cron/collect_metrics.php` |
| `monitor_slow_request_ms` | `string` | `-` | `1500` | `admin/monitoring.php` |
| `newsletter_double_optin` | `bool (0/1)` | `-` | `1` | `api/newsletter.php` |
| `newsletter_enabled` | `bool (0/1)` | `-` | `1` | `api/newsletter.php` |
| `nginx_access_log_path` | `string` | `-` | `null` | `cron/parse_access_log.php` |
| `order_number_prefix` | `string` | `ORD` | `ORD` | `includes/shop_bootstrap.php` |
| `out_of_stock_behavior` | `string` | `show_disabled` | `show_disabled` | `admin/settings.php` |
| `pretty_urls` | `bool (0/1)` | `-` | `0` | `includes/functions.php` |
| `realtime_mode` | `string` | `-` | `polling` | `admin/settings.php` |
| `realtime_poll_seconds` | `string` | `10` | `10` | `admin/settings.php` |
| `require_email` | `bool (0/1)` | `-` | `0` | `storefront/cart/checkout.php` |
| `require_phone` | `bool (0/1)` | `1` | `1` | `storefront/cart/checkout.php` |
| `reviews_auto_approve` | `bool (0/1)` | `-` | `0` | `api/review.php` |
| `reviews_enabled` | `bool (0/1)` | `-` | `1` | `api/review.php` |
| `reviews_require_login` | `bool (0/1)` | `-` | `1` | `api/review.php` |
| `robots_allow` | `string` | `-` | `setting('allow_crawlers', '1` | `includes/layout/coza/head.php` |
| `sales_summary_last_run` | `string` | `-` | `date('Y-m-d H:i:s', strtotime('-1 day` | `cron/rebuild_sales_summary.php` |
| `seo_default_description` | `string` | `-` | `setting('site_about', 'Quality essentials delivered to your door.` | `includes/partials/header.legacy.php` |
| `seo_default_keywords` | `string` | `-` | `` | `includes/partials/header.legacy.php` |
| `seo_default_title` | `string` | `-` | `setting('meta_title',` | `includes/layout/coza/head.php` |
| `shipping_fee` | `decimal` | `-` | `0` | `includes/shop_bootstrap.php` |
| `shop_default_sort` | `string` | `-` | `newest` | `storefront/shop/category.php` |
| `shop_products_per_page` | `string` | `-` | `12` | `storefront/shop/category.php` |
| `shop_show_filters` | `string` | `-` | `1` | `admin/settings.php` |
| `shop_show_low_stock_badge` | `string` | `-` | `1` | `includes/partials/product_card.legacy.php` |
| `shop_show_sale_badge` | `string` | `-` | `1` | `includes/partials/product_card.legacy.php` |
| `site_about` | `string` | `-` | `` | `includes/partials/footer.legacy.php` |
| `site_email` | `string` | `-` | `null` | `includes/inventory.php` |
| `site_name` | `string` | `-` | `APP_NAME` | `api/newsletter.php` |
| `size_guide_page_slug` | `string` | `-` | `size-guide` | `admin/settings.php` |
| `social_facebook` | `string` | `-` | `null` | `includes/partials/footer.legacy.php` |
| `social_instagram` | `string` | `-` | `null` | `includes/partials/footer.legacy.php` |
| `social_whatsapp` | `string` | `-` | `null` | `includes/partials/footer.legacy.php` |
| `sticky_add_to_cart` | `string` | `-` | `1` | `admin/settings.php` |
| `stock_display_mode` | `bool (0/1)` | `count_when_low` | `count_when_low` | `api/availability.php` |
| `storage_auth_mode` | `string` | `-` | `null` | `admin/settings.php` |
| `storage_aws_access_key_id` | `string` | `-` | `null` | `src/Storage/StorageConfig.php` |
| `storage_aws_region` | `string` | `-` | `null` | `src/Storage/StorageConfig.php` |
| `storage_aws_secret_access_key` | `string` | `-` | `null` | `src/Storage/StorageConfig.php` |
| `storage_fallback` | `string` | `-` | `null` | `src/Storage/StorageConfig.php` |
| `storage_primary` | `string` | `-` | `null` | `src/Storage/StorageConfig.php` |
| `storage_s3_bucket` | `string` | `-` | `null` | `src/Storage/StorageConfig.php` |
| `storage_s3_delivery` | `string` | `-` | `null` | `src/Storage/StorageConfig.php` |
| `storage_s3_key_prefix` | `string` | `-` | `null` | `src/Storage/StorageConfig.php` |
| `storage_s3_public_base_url` | `string` | `-` | `null` | `src/Storage/StorageConfig.php` |
| `store_address` | `string` | `-` | `` | `account/invoice.php` |
| `store_email` | `string` | `-` | `` | `account/invoice.php` |
| `store_favicon` | `string` | `-` | `null` | `includes/functions.php` |
| `store_logo` | `string` | `-` | `` | `account/invoice.php` |
| `store_phone` | `string` | `-` | `` | `account/invoice.php` |
| `store_tagline` | `string` | `-` | `` | `includes/partials/footer.legacy.php` |
| `theme_accent_color` | `string` | `-` | `setting('theme_accent', '#111827` | `includes/partials/header.legacy.php` |
| `timezone` | `string` | `-` | `Asia/Dhaka` | `includes/functions.php` |
| `trust_item_1_desc` | `string` | `-` | `Cash on delivery across all 64 districts` | `includes/layout/coza/footer.php` |
| `trust_item_1_title` | `string` | `-` | `Nationwide COD` | `includes/layout/coza/footer.php` |
| `trust_item_2_desc` | `string` | `-` | `24h in Dhaka, 48-72h outside` | `includes/layout/coza/footer.php` |
| `trust_item_2_title` | `string` | `-` | `Fast Dispatch` | `includes/layout/coza/footer.php` |
| `trust_item_3_desc` | `string` | `-` | `Guaranteed genuine products direct from makers` | `includes/layout/coza/footer.php` |
| `trust_item_3_title` | `string` | `-` | `100% Authentic` | `includes/layout/coza/footer.php` |
| `trust_item_4_desc` | `string` | `-` | `Friendly phone & WhatsApp assistance` | `includes/layout/coza/footer.php` |
| `trust_item_4_title` | `string` | `-` | `Dedicated Support` | `includes/layout/coza/footer.php` |