# 01 — Core refactor & shared helper layer

> Paste `00_MASTER_CONTEXT_and_ORDER.md` first. SQL 001–007 must already be applied.

## Role
You are a senior PHP engineer extending an existing flat PHP 7.4 + PDO shop. Keep the current style; add a small, well-named helper layer so later features (variants, live stock, mail, analytics) share one code path.

## Goal
Create the foundation only — **no UI changes yet**. Everything later builds on these helpers.

## Tasks

### 1. New include files (all under `/includes/`)
Create these and `require_once` them from `includes/shop_bootstrap.php` (storefront) and `admin/includes/auth.php` (admin):

**`includes/catalog.php`**
- `product_get(int $id): ?array`, `product_get_by_slug(string $slug): ?array` (active only for storefront).
- `product_options_get(int $productId): array` → `[ ['id','name','display_type','values'=>[['id','value','swatch_hex']]] ]` ordered by position.
- `variants_get(int $productId, bool $onlyActive=true): array` → each variant with `option_value_ids` map `{option_id: value_id}` and `label` ("Size: M / Color: Red").
- `variant_get(int $variantId): ?array`.
- `variant_effective_price(array $variant, array $product): float` → variant sale_price → variant price → product sale_price → product price.
- `variant_available_qty(array $variant, array $product): int` → honours `track_inventory`, `allow_backorder`.
- `product_available_qty(int $productId): int` using `v_product_stock`.
- `variant_hash(array $optionValueIds): string` → `md5(implode('-', sorted ids))` (same rule as `product_variants.option_hash`).

**`includes/inventory.php`**
- `stock_adjust(int $productId, ?int $variantId, int $delta, string $reason, ?int $orderId=null, ?string $note=null): bool`
  - Must run inside caller's transaction OR open its own.
  - For negative delta use **atomic conditional update**:
    `UPDATE product_variants SET stock = stock + :d WHERE id=:id AND (stock + :d) >= 0` (or products.stock for simple); return false if `rowCount()===0` (insufficient stock).
  - Respect `allow_backorder` and `track_inventory`.
  - Insert row into `inventory_movements` with `stock_after`.
  - After success call `stock_check_low($productId,$variantId)` which queues `low_stock_admin` mail via `mail_queue_template()` (stub it if mail layer not built yet: `function_exists` guard).
- `stock_restore_for_order(int $orderId)`: add back stock for a cancelled order exactly once (use `orders.stock_restored` flag, in a transaction).

**`includes/hooks.php`** (tiny event bus so features stay decoupled)
- `hook_on(string $event, callable $fn)` and `hook_fire(string $event, array $payload=[])`.
- Events to define now (fired later by other prompts): `order.placed`, `order.status_changed`, `stock.low`, `product.viewed`, `cart.added`, `cart.removed`, `checkout.started`.
- Every listener runs inside try/catch — a failing listener must never break checkout.

**`includes/crypto.php`**
- `app_encrypt(string $plain): string` / `app_decrypt(string $cipher): string` using `openssl_encrypt('aes-256-cbc')` with random IV, base64 output, key derived from a new constant `APP_KEY` in `config/config.php`.
- Add to `config/config.php`: `define('APP_KEY', 'CHANGE_ME_TO_64_RANDOM_CHARS');` plus a comment explaining how to generate one. In `admin/settings.php` show a red warning while the key is still the default.

### 2. Config additions (`config/config.php`)
- `date_default_timezone_set(setting('timezone','Asia/Dhaka'))` — but guard so it works before DB exists (installer). Keep DB timestamps consistent: after PDO connect run `SET time_zone = '+06:00'` **only if** setting timezone is Asia/Dhaka (otherwise compute offset with `DateTime`).
- `define('SITE_URL', ...)` computed from `$_SERVER['HTTP_HOST']` + `BASE_URL` for absolute links in emails.

### 3. Safe schema check
Add `function schema_ready(): bool` that checks `information_schema` once per request for `product_variants` and `email_templates`. If false, admin dashboard shows a one-line warning "Run sql/upgrade_all.sql"; storefront falls back to legacy behaviour (no fatal errors).

### 4. Backwards compatibility
Existing simple products (`product_type='simple'`) must keep working exactly as before: cart, checkout, stock.

## Acceptance tests (write them as a CLI script `tests/core_smoke.php`, run with `php tests/core_smoke.php`)
- `variant_hash([3,1,2]) === variant_hash([1,2,3])`.
- `stock_adjust()` on stock 2 with delta -3 returns false and leaves stock at 2.
- Two sequential `stock_adjust(-1)` on stock 1: first true, second false.
- `app_decrypt(app_encrypt('x')) === 'x'` and ciphertext differs on each call.
- Cancelled order restores stock only once.

## Output format
Full code for each new file, diffs for modified files, and the exact commands to run the smoke test.
