# Migration Discrepancies Log

This document records all discrepancies discovered between the machine-generated `docs/MIGRATION_INTEL.md` and the actual PHP application source code.
Per project rules, the PHP source is the single source of truth.

---

### Discrepancy 1: Application Cryptographic Algorithm (`includes/crypto.php`)
- **MIGRATION_INTEL.md claims (Section 2, 6, 11):**
  Claims that `includes/crypto.php` implements AES-256-GCM symmetric encryption returning `base64(iv . tag . ciphertext)` with a 12-byte IV and 16-byte authentication tag.
- **Actual PHP Source (`includes/crypto.php` lines 7-35):**
  Uses OpenSSL `aes-256-cbc`:
  ```php
  function app_encrypt(string $plain): string {
      $key = app_crypto_key(); // hash('sha256', APP_KEY, true)
      $iv  = random_bytes(16);
      $raw = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
      if ($raw === false) return '';
      return base64_encode($iv . $raw);
  }
  ```
  Ciphertext format is `base64(iv[16] . raw_ciphertext)` with PKCS#7 padding.
- **Resolution for Rust:**
  Rust backend implements AES-256-CBC with PKCS#7 padding using SHA-256 of `APP_KEY` and 16-byte IV to ensure 100% interoperability with existing encrypted secrets in the MySQL database (e.g., `settings.storage_aws_secret_access_key`, `settings.mail_password`).

---

### Discrepancy 2: Products Table Column Schema (`sql/schema.sql` vs DB)
- **MIGRATION_INTEL.md claims:**
  Implies `barcode` and `cost_price` exist on the `products` table.
- **Actual MySQL Schema (`DESCRIBE products;`):**
  `barcode` and `cost_price` only exist on `product_variants`, NOT on `products`.
  Furthermore, `rating_count`, `sold_count`, and `view_count` are defined as `int unsigned`, which requires Rust `u32` decoding in SQLx rather than signed `i32`.
- **Resolution for Rust:**
  Rust `Product` model has `barcode` and `cost_price` omitted, and `rating_count`/`sold_count` defined as `u32`. `ProductVariant` retains `barcode` and `cost_price`.

---

### Discrepancy 3: Cart Session Key Format (`includes/shop_bootstrap.php:156`)
- **MIGRATION_INTEL.md claims:**
  Claims conflicting formats: `p{id}/p{id}_v{id}` vs `{id}/{id}:{vid}`.
- **Actual PHP Source (`includes/shop_bootstrap.php:156`, `api/cart_ajax.php:60`, `assets/js/shop.js:239`):**
  The cart key format is strictly `"{productId}:{variantId}"` where simple products use `0` as the variant ID (e.g. `"42:0"` for simple product 42, `"42:10"` for variant 10).
- **Resolution for Rust:**
  Rust cart service uses `format!("{}:{}", product_id, variant_id.unwrap_or(0))` and frontend DOM expects `data-cart-key="{productId}:{variantId}"`.

---

### Discrepancy 4: Remember-Me Cookie Name & Rotation (`includes/customer_auth.php`)
- **MIGRATION_INTEL.md claims:**
  Claims cookie name is `c_remember` or `remember_me`.
- **Actual PHP Source (`includes/customer_auth.php:635`):**
  The cookie name is strictly `customer_remember`. Token format is `{selector}:{validator}` (16-byte hex selector, 32-byte hex validator). On each session restore, the token rotates (old token consumed, new token issued).
  The admin panel has **NO remember-me cookie**.
- **Resolution for Rust:**
  Rust implements `customer_remember` cookie handling with token rotation on customer restore and pure session for admin.

---

### Discrepancy 5: Table Name `activity_log` vs `activity_logs`
- **MIGRATION_INTEL.md claims:**
  Refers to the activity log table as `activity_logs`.
- **Actual MySQL Schema (`sql/schema.sql:16`) and Code (`includes/functions.php:518`):**
  The table is named `activity_log` (singular).
- **Resolution for Rust:**
  Rust queries query `activity_log` (singular).

---

### Discrepancy 6: Non-Existent `analytics_summary` Table
- **MIGRATION_INTEL.md claims:**
  Claims `cron/aggregate_analytics.php` writes into `analytics_summary`.
- **Actual PHP Source (`cron/aggregate_analytics.php:42, 75`):**
  `cron/aggregate_analytics.php` rolls up `analytics_events` directly into `product_stats_daily` and `site_stats_daily`. There is no `analytics_summary` table in MySQL or code.
- **Resolution for Rust:**
  Rust background jobs roll up into `product_stats_daily` and `site_stats_daily`.

---

### Discrepancy 7: Missing `product_sales_summary` DDL
- **MIGRATION_INTEL.md identifies:**
  Table `product_sales_summary` is queried in 8 PHP files (`index.php`, `cron/rebuild_sales_summary.php`, `includes/catalog.php`, etc.) but missing from `sql/schema.sql`.
- **Resolution for Rust:**
  Additive migration `rust-backend/migrations/0002_product_sales_summary.sql` adds the table DDL without touching existing schema.
