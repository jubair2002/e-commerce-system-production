# 05 — Variant-aware cart, Cash-on-Delivery checkout, order lifecycle

> Paste `00_MASTER_CONTEXT_and_ORDER.md` first. Prompts 01–04 done.

## Role
Senior PHP engineer focused on correctness of money and stock.

## Goal
Replace the single-product-id cart with a **variant-aware cart**, make checkout COD-first and robust, and give the admin a proper order workflow (status history, restock on cancel, tracking info, emails).

## 1. Cart (`includes/shop_bootstrap.php`, `cart.php`)
- **New cart shape:** `$_SESSION['cart'] = [ "12:55" => qty, "13:0" => qty ]` key = `productId:variantId` (variantId `0` for simple products). Write a one-time migration that converts old `[pid=>qty]` carts.
- Functions: `cart_add($pid,$vid,$qty)`, `cart_set($key,$qty)`, `cart_remove($key)`, `cart_load()` (hydrates product + variant + label + image + unit price + available qty + warnings), `cart_totals($cart,$shippingMethodId,$coupon)`.
- `cart_load()` must drop inactive/deleted lines, clamp quantities to live stock, and return per-line `warnings[]` (price changed, only N left, unavailable). Show them in a dismissible alert on cart and checkout.
- Server rejects Add-to-cart for variable products without a valid, active variant id that belongs to that product. Qty validated 1–99 and ≤ available.
- Support AJAX: `cart.php?ajax=1` returns JSON `{ok,count,subtotal,lines[],message}` for the mini-cart drawer; non-AJAX falls back to redirect + flash. CSRF token required for both.
- Cart UI: image, name, variant label, unit price, qty stepper, line total, remove, "Continue shopping", coupon box, shipping estimate selector.
- Fire hooks `cart.added` / `cart.removed` (analytics prompt consumes them).

## 2. Checkout (`checkout.php`) — Cash on Delivery first
- Single page, two columns: left = form, right = sticky order summary (lines with variant labels, subtotal, discount, shipping, COD fee, total).
- Fields: full name, phone (BD format validation `^(\+?88)?01[3-9]\d{8}$`, configurable via `require_phone`), email (optional if admin sets), address, city/district, area/zip, notes. Remember values with `set_old()`.
- **Shipping method** radio list from `shipping_methods` (name, cost, ETA); free shipping when subtotal ≥ `free_over`. Method must be active and `cod_allowed=1` when paying COD.
- **Payment:** COD (default, respects `cod_enabled`, `cod_min_order`, `cod_max_order`, `cod_fee`); Bank transfer only if `bank_transfer_enabled` (show instructions text).
- **Coupon** (optional): validate against `coupons` (active, dates, min subtotal, usage_limit, max_discount); increment `used_count` inside the order transaction; show discount line.
- **Guest checkout only** (`guest_checkout`), no accounts required.
- Honeypot field + time-trap (reject if submitted < 3 s after render) + per-IP limit (e.g., 5 orders / 30 min) to stop COD spam. Show friendly error.
- Double-submit protection: one-time `checkout_token` stored in session, consumed on success.

### Place-order transaction (strict order)
1. Recompute everything from DB (prices, shipping, coupon, COD fee). Ignore any price sent by the browser.
2. `BEGIN` → insert `orders` (status `pending`, `payment_status` `unpaid`, `ip_address`, `shipping_method_*`, `cod_fee`, `discount_total`, `coupon_code`) → generate order number using setting `order_number_prefix` **inside the transaction with a lock** (`SELECT ... FOR UPDATE` on a counter row or `INSERT` retry on duplicate key) to avoid duplicates under concurrency.
3. For each line (sorted by variant id): insert `order_items` with snapshots `product_name, product_sku, variant_id, variant_sku, variant_label, unit_price, quantity, line_total`, then `stock_adjust(-qty, 'order', orderId)`. Any failure → rollback, rebuild cart warnings, redirect to cart.
4. Insert `order_status_history` ('pending'), update `products.sold_count`, bump coupon usage.
5. `COMMIT` → `cart_clear()` → fire `order.placed` hook (mail + analytics listeners) → redirect to `order_confirmation.php?o=ORDER_NUMBER&t=SIGNED_TOKEN` (HMAC of order number with `APP_KEY`; confirmation page requires a valid token or matching session, so order numbers can't be enumerated).

## 3. Confirmation & tracking
- `order_confirmation.php`: summary, COD amount to prepare, ETA, "Track order" link, WhatsApp/phone contact from settings. Clear cart count in header.
- `track.php`: lookup by order number + phone **or** email (either), show status timeline from `order_status_history`, courier + tracking number, items with variant labels. Rate limit lookups.

## 4. Admin order workflow (`admin/orders.php`, `admin/order_view.php`)
- Status changes via POST only, inside a transaction: write `order_status_history` (+ optional note), set `payment_status='paid'` automatically when status becomes `completed` and method is COD (configurable), fire `order.status_changed`.
- **Cancel** → `stock_restore_for_order()` (once) + history + email.
- Edit courier + tracking number + internal notes on the order page; print-friendly **invoice / packing slip** (`order_print.php`, CSS `@media print`, shows variant labels and COD amount to collect, QR optional).
- Orders list: filters (status, payment status, date range, search by number/name/phone), bulk status update, CSV export (UTF-8 BOM, Excel-safe), highlight COD pending > 48 h.
- Valid transitions only: pending→processing→shipped→completed; any→cancelled (not from completed unless admin confirms "return"). Reject invalid jumps server-side.

## 5. Edge cases
- Item becomes unavailable between cart and submit → friendly per-line message, no partial order.
- Price changed → show banner, require re-confirm (don't silently charge more).
- Free shipping threshold crossing after coupon → compute on subtotal after discount (document the rule).
- Rounding: totals computed as sum of rounded line totals.
- Browser back after success must not re-submit.

## Acceptance tests (output `tests/checkout_flow.md` with exact steps + a `tests/checkout_smoke.php` that drives the functions)
1. Simple product COD order end-to-end.
2. Variant product: two different variants of same product in cart as two lines; stock of each decrements separately.
3. Last item race (see prompt 03 test) → one order only.
4. Cancel shipped order → stock restored exactly once; second cancel does nothing.
5. Coupon over limit rejected; COD min/max enforced; invalid phone rejected.
