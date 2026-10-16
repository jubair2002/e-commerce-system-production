# 03 — Real-time availability, synced with the DB

> Paste `00_MASTER_CONTEXT_and_ORDER.md` first. Prompts 01–02 done.

## Role
Senior PHP engineer. Shared hosting / XAMPP friendly — **no WebSocket server, no Redis**.

## Goal
On the storefront the customer must always see the true stock of the exact combination they pick (Size M + Red), updated without reloading, and it must be **impossible to oversell** even when two people buy the last item at the same second.

## Design (implement exactly this)
**Transport = short polling with ETag + optional SSE.** Polling is the default (works everywhere); SSE is an opt-in if the host allows long requests.

### 1. API: `api/availability.php` (GET, JSON, no session lock)
- `?product_id=12` → returns all active variants:
```json
{
  "product_id": 12, "type": "variable", "server_time": 1760000000,
  "options": [{"id":1,"name":"Size","values":[{"id":10,"value":"S"}]}],
  "variants": [
    {"id": 55, "key": "10-20", "sku": "TEE-S-BLK", "price": 500.00, "sale_price": null,
     "stock": 7, "status": "in_stock|low_stock|out_of_stock|backorder",
     "label_text": "Only 3 left", "image": "p_123.jpg"}
  ]
}
```
- `key` = option value ids joined by `-` in option order, so JS finds a variant in O(1).
- Respect admin settings: `stock_display_mode` (`always` shows exact count, `count_when_low` shows "Only N left" when ≤ threshold, `in_out_only`, `hidden`), `show_stock_count` per product, `out_of_stock_behavior`.
- **Never expose exact stock** unless setting allows; return only `status` + optional `label_text` otherwise.
- Add `ETag` = md5 of payload and honour `If-None-Match` → `304` (saves bandwidth, feeds monitoring bytes).
- Call `session_write_close()` first (don't block other requests), send `Cache-Control: no-store`.
- Rate limit: 60 req/min per IP using a tiny file/APCu/DB counter; return 429 with `Retry-After`.
- Also `api/availability.php?ids=1,2,3` (max 24) for product-grid badges.

### 2. Frontend module: `assets/js/availability.js` (vanilla)
- On product page load: fetch once, then poll every `realtime_poll_seconds` (setting, default 10). Pause when `document.hidden`; resume + immediate fetch on `visibilitychange`.
- Exponential backoff on errors (max 60 s) and a subtle "offline" state.
- When option buttons change: find variant by key; update price, strike-through sale price, SKU, stock label, main image, Add-to-cart button state.
- Option buttons whose every combination is out of stock get a disabled/struck style (compute "is any variant with this value still available given current selections").
- If the selected variant sells out while the user is looking: show inline notice "Just sold out" and disable the button without a full reload.
- Announce changes to screen readers via `aria-live="polite"`.

### 3. Server-side truth at every step (the real anti-oversell)
- **Add to cart** (`cart.php` action `add`): re-read variant from DB, clamp qty to available, flash message if clamped.
- **Cart page load** and **checkout page load**: revalidate every line; show "price changed" / "only N left" / "no longer available" banners and auto-fix quantities.
- **Place order** (inside one transaction): for each line call `stock_adjust($pid,$vid,-qty,'order',$orderId)` which uses `UPDATE ... SET stock = stock - :q WHERE id=:id AND stock >= :q`. If any line fails → rollback whole order, return to checkout with a clear message naming the item. Never `SELECT then UPDATE`. Lock order: always process lines sorted by variant id to avoid deadlocks; retry once on deadlock (SQLSTATE 40001).
- Optional soft reservation: if `cart_reserve_minutes` > 0, ignore (keep simple) — document as future work.

### 4. Admin ↔ storefront sync
- Admin stock edits, order cancellation restocks and order placement all go through `stock_adjust()`, so the polling API always reflects DB truth within `realtime_poll_seconds`.
- Admin product list "Stock" column auto-refreshes every 30 s (same API in admin mode, authenticated, returns exact numbers).
- Cancel order → status change calls `stock_restore_for_order()` once.

### 5. Optional SSE (feature flag `realtime_mode=sse`)
`api/availability_stream.php`: `text/event-stream`, loop max 25 s, check `MAX(updated_at)` of variants for the product every 2 s, emit `event: stock` only on change, `session_write_close()` first, and tell the client to reconnect. Document the PHP-FPM/Apache worker cost and keep polling as default.

## Edge cases
- Variable product with `track_inventory=0` → always "In stock".
- `allow_backorder=1` → status `backorder` ("Ships in 5-7 days" text from a setting).
- Product deleted/inactive while viewed → API returns 404; JS shows "No longer available".
- Clock skew: use server_time only for display; never trust client time.

## Acceptance tests (output a runnable `tests/oversell_test.php`)
1. Stock 1, run 20 parallel POST orders (use `curl_multi` or `pcntl_fork` if available) → exactly 1 succeeds, stock = 0, no negative stock.
2. Change stock in admin → product page badge updates within poll interval without reload.
3. 304 path returns empty body and still counts bytes in monitoring.
4. Hidden tab stops polling (verify in DevTools Network).
