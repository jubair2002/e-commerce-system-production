# 00 — Master context (paste this FIRST in every new AI session)

## How to use this pack
1. Back up DB: `mysqldump -u root php_admin_panel > backup.sql`
2. Run `sql/upgrade_all.sql` (or 001 → 007 one by one) in phpMyAdmin. Safe to re-run.
3. Give the AI **this file first**, then ONE prompt file at a time, in this order:

| Order | File | Result |
|---|---|---|
| 1 | `01_core_refactor_helpers.md` | Shared helpers: variants, stock, cart keys, mailer hooks, tracking hooks |
| 2 | `02_admin_product_variants.md` | Admin: one product → many options (size/color/any), per-variant price/stock/SKU/image |
| 3 | `03_realtime_availability_stock.md` | Live availability from DB, atomic stock, no overselling |
| 4 | `04_storefront_redesign.md` | New clean minimal storefront (admin design untouched) |
| 5 | `05_cart_checkout_cod.md` | Variant-aware cart, COD checkout, order lifecycle |
| 6 | `06_admin_store_settings_cms.md` | Admin controls storefront behaviour (stock display, banners, shipping, COD…) |
| 7 | `07_admin_mail_system.md` | SMTP config + templates + queue + "Send test" button |
| 8 | `08_admin_system_monitoring.md` | Built-in CPU/RAM/disk/network/bandwidth dashboard |
| 9 | `09_admin_analytics_dashboard.md` | Product & sales analytics inside admin |
| 10 | `10_security_qa_deploy.md` | Hardening, test checklist, deploy |

After each step: run it, test it, commit to git, then continue. Do not run two prompts in one session.

---

## Project context (what exists today)

**Repo:** `MuhammadBilal012/php-ecommerce-admin-panel` ("Bilal Store"). Plain PHP 7.4+ / PDO / MySQL, **no Composer, no framework, no JS build step**. Bootstrap 5.3 + Bootstrap Icons + Chart.js via CDN. About 5k lines PHP, flat page-per-file style.

```
/                    storefront pages: index, shop, category, product, cart, checkout,
                     order_confirmation, track, _product_card.php
/includes/           functions.php (helpers, settings, csrf, flash, slugify, price)
                     shop_bootstrap.php (cart_* helpers in $_SESSION['cart'], product_effective_price)
                     shop_header.php / shop_footer.php
/config/             config.php (DB constants, BASE_URL auto-detect, session start)  database.php (PDO singleton db())
/admin/              login, dashboard, products, product_form, categories, category_form,
                     orders, order_view, activity, profile, settings, install.php, migrate.php
/admin/includes/     auth.php (require_role, has_role, attempt_login), header, sidebar, footer
/assets/css/         style.css (ADMIN look: indigo --brand #4f46e5, dark mode)  shop.css (storefront)
/assets/js/script.js
/assets/uploads/products/
/sql/                schema.sql, migrations.sql  (+ the new 001..007 files from this pack)
```

**Existing DB tables:** `users, activity_log, settings(key_name,value), categories, products, orders, order_items`.
`products` has `stock`, `price`, `sale_price`, `status(active|inactive|draft)`, `featured`.
`orders.status` = `pending|processing|shipped|completed|cancelled`. Payments: `cod`, `bank_transfer`.

**Existing conventions you MUST keep**
- Helpers: `db()`, `e()`, `url()`, `admin_url()`, `redirect()`, `admin_redirect()`, `csrf_field()`, `require_csrf()`, `flash()`, `setting()`, `update_setting()`, `log_activity()`, `price()`, `slugify()`, `unique_slug()`, `product_image_url()`.
- Admin pages start with `require_once __DIR__.'/includes/auth.php'; require_role('admin','editor');`
- Every POST uses `require_csrf()`. All SQL uses PDO prepared statements. All output uses `e()`.
- Settings are key/value in `settings`; read via `setting('key','default')`.
- Admin UI = Bootstrap 5 cards, indigo brand, light/dark toggle. **Do not redesign admin; new admin pages must look identical** (copy structure from `admin/products.php`, `admin/settings.php`).

**New DB objects available (from `sql/`)**
`product_options, product_option_values, product_variants, product_variant_values, product_images, option_presets(+values), inventory_movements, shipping_methods, coupons, order_status_history, email_templates, email_queue, system_metrics, network_samples, traffic_minute, slow_requests, monitor_alert_rules, analytics_events, product_stats_daily, site_stats_daily, search_log, storefront_banners`, views `v_product_stock`, `v_sales_by_variant`, plus new columns on `products`, `orders`, `order_items` and many new `settings` keys. **Read the SQL files before coding; never invent column names.**

## Global engineering rules (apply to every prompt)
1. PHP 7.4 compatible syntax only (no `match`, no enums, no readonly, no named args). Works on XAMPP (Windows) **and** Linux VPS.
2. No Composer. If a library is needed (PHPMailer), vendor the plain files into `includes/lib/` and `require` them manually.
3. No new framework, no build tools. Vanilla JS (fetch) only on the storefront; Bootstrap + Chart.js on admin.
4. Money: store DECIMAL, compute with integers (paisa/cents) or `round(x,2)`; never trust prices from the browser — always re-read from DB.
5. Stock changes ONLY through one function, inside a transaction, writing `inventory_movements`.
6. Every new admin page: add sidebar link (inside the correct role check), `log_activity()` on every change.
7. Every new `.php` under `includes/` or `config/` is protected by the existing `.htaccess` deny rule — keep it that way.
8. Output: complete files (no "..." placeholders), plus a short list of files created/changed and manual test steps.

## Definition of done (whole project)
- Admin can create ONE product with any options (Size, Color, anything), generate all combinations, set price/stock/SKU/image per combination.
- Storefront product page shows live availability per combination; out-of-stock combinations are disabled; stock updates without page reload.
- COD checkout works end to end with variants, atomic stock decrement, confirmation + emails.
- Admin sets SMTP values, clicks **Send test email**, and emails flow.
- Admin → Monitoring shows CPU, RAM, disk, network in/out, bandwidth, DB stats.
- Admin → Analytics shows views, add-to-cart, conversion, revenue, top/bottom products, funnel.
- Storefront is clean/minimal/professional; admin look unchanged.
