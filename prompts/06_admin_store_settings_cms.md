# 06 — Admin controls for the storefront (settings, banners, shipping, COD, stock display)

> Paste `00_MASTER_CONTEXT_and_ORDER.md` first. Prompts 01–05 done.

## Role
Senior PHP engineer. Admin pages must match the existing admin look exactly (copy markup patterns from `admin/settings.php`, `admin/products.php`).

## Goal
The shop owner controls how the storefront behaves **from admin only**, with no code edits: just fill values and save. All values already exist as keys in the `settings` table (see `sql/002`, `006`) or in `shipping_methods`, `coupons`, `storefront_banners`.

## Restructure `admin/settings.php` into tabs (Bootstrap nav-tabs, one form per tab, each saves independently)
1. **General** — site name, tagline, contact email/phone/address, logo + favicon upload (png/jpg/svg/ico, validated, stored in `assets/uploads/branding/`), timezone select (default Asia/Dhaka), currency code/symbol, items per page.
2. **Storefront** — `home_show_*` toggles, featured count, products per page, default sort, show filters / sale badge / low-stock badge, announcement bar (text + on/off + optional link), footer text, social links, theme accent colour (hex picker, validated; **live preview swatch**), maintenance mode (+ allow admins).
3. **Inventory & stock display** — `stock_display_mode` (always / only when low / in-out only / hidden), global low-stock threshold default, `out_of_stock_behavior` (show disabled / hide), backorder text, realtime poll seconds (5–60), realtime mode (polling / SSE).
4. **Checkout & payments** — guest checkout, require phone/email, COD enable + COD fee + min/max order, bank transfer enable + instructions, order number prefix, anti-spam limits (orders per IP / window), terms checkbox text.
5. **Shipping** — CRUD for `shipping_methods` (name, zone, cost, free-over, ETA days, COD allowed, status, drag order). Inline table editing with add/remove rows.
6. **Coupons** — CRUD for `coupons` with usage count, expiry, status; "generate code" button.
7. **SEO** — default title/description, robots toggle; auto-generated `sitemap.xml` (new `sitemap.php` listing active products + categories) and `robots.txt` link.
8. *(Links out)* Mail → prompt 07 page, Monitoring → prompt 08, Analytics → prompt 09.

## New admin pages
- `admin/banners.php` + `banner_form.php` — CRUD for `storefront_banners` (image upload, title, subtitle, button, placement, schedule start/end, order, active). Live preview card.
- `admin/inventory.php` — **stock overview**: table of every product/variant with stock, threshold, status, filter low/out, inline stock edit (uses `stock_adjust()`), and the **inventory_movements** ledger viewer with filters + CSV export. Add "Import stock CSV" (sku,stock) with dry-run preview.
- Add sidebar entries under "E-commerce": Inventory, Banners, Coupons, Shipping (or keep in Settings tabs — pick one and be consistent).

## Rules
- Single helper `settings_save(array $pairs, array $rules)` that validates by type (`bool`, `int` with min/max, `enum`, `hex`, `email`, `url`, `text`) and calls `update_setting()` only for whitelisted keys. Unknown keys from POST are ignored (mass-assignment protection).
- Sensitive keys never echoed back into HTML values (passwords show "•••• saved").
- Uploads: validate mime with `finfo`, max 2 MB, random filenames, delete old file on replace, `.htaccess` in upload dirs that disables PHP execution.
- Every save → `log_activity('settings.update', 'tab: storefront; changed keys: a,b,c')` (log key names only, never values of secrets).
- Provide **"Reset tab to defaults"** button with confirm.
- Storefront reads settings through ONE cached function (`setting()` already caches per request) — avoid per-row DB calls in loops.

## Acceptance
1. Change accent colour in admin → storefront updates on refresh.
2. Set stock mode "hidden" → product page shows only In stock / Out of stock; API doesn't leak counts.
3. Turn off COD → checkout refuses COD server-side (not just hides the radio).
4. Maintenance mode on → visitors see maintenance page, logged-in admin sees the shop.
5. Invalid hex / negative fee / unknown POST key are rejected.
