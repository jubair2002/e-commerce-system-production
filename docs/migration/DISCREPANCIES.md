# Migration Discrepancies Log

This document records all discrepancies discovered between the machine-generated `docs/MIGRATION_INTEL.md` and the actual PHP application source code.
Per project rules, the PHP source is the single source of truth.

---

### Discrepancy 2: Products Table Column Schema (`sql/schema.sql` vs DB)
- **MIGRATION_INTEL.md claims:**
  Implies `barcode` and `cost_price` exist on the `products` table.
- **Actual MySQL Schema (`DESCRIBE products;`):**
  `barcode` and `cost_price` only exist on `product_variants`, NOT on `products`.
  Furthermore, `rating_count`, `sold_count`, and `view_count` are defined as `int unsigned`, which requires Rust `u32` decoding in SQLx rather than signed `i32`.
- **Resolution for Rust:**
  Rust `Product` model has `barcode` and `cost_price` omitted, and `rating_count`/`sold_count` defined as `u32`. `ProductVariant` retains `barcode` and `cost_price`.

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
  Rust backend must implement AES-256-CBC decryption/encryption with PKCS#7 padding using SHA-256 of `APP_KEY` and 16-byte IV to ensure 100% interoperability with existing encrypted secrets in the MySQL database (e.g., `settings.storage_aws_secret_access_key`, `settings.mail_password`).
