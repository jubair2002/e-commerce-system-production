# 09 — Admin analytics dashboard (products, sales, funnel, customers)

> Paste `00_MASTER_CONTEXT_and_ORDER.md` first. Prompts 01–08 done. SQL `005_analytics.sql` applied.

## Role
Senior PHP + data-visualisation engineer. Privacy-friendly, first-party analytics only (no Google Analytics, no cookies beyond the PHP session).

## Goal
Admin → **Analytics** gives the owner a clear picture of every product: how many people view it, add it to cart, buy it, how much it earns, which variants (size/color) sell, what is dying or running out — with date-range filters and CSV export. Same admin look; Chart.js.

## 1. Event collection (`includes/analytics.php`)
- `analytics_track(string $type, array $data)` inserts into `analytics_events`. Called from hook listeners (prompt 01) so pages stay clean:
  - `product.viewed` (product.php, after 404 check) · `cart.added` / `cart.removed` · `checkout.started` (first GET of checkout with items) · `order.placed` → one `purchase` event per line (product_id, variant_id, qty, value) · search (shop.php) → `search_log` + `search` event.
- **Privacy:** `visitor_hash = md5(REMOTE_ADDR . USER_AGENT . daily_salt)` where `daily_salt` = `hash('sha256', APP_KEY . date('Y-m-d'))`; raw IP/UA never stored. Honour Do-Not-Track header when the setting `analytics_respect_dnt` = 1.
- Skip: logged-in admins (`analytics_exclude_admin`), bots (basic UA regex → `device='bot'` and excluded when `analytics_exclude_bots`), prefetch requests, and duplicate `product_view` by same visitor+product within 30 min (session timestamp).
- Device from UA (desktop/mobile/tablet), referrer host (strip own host → "direct"), `utm_source`/`utm_campaign` from query string.
- Writes wrapped in try/catch, after response flush where possible; must add < 5 ms.

## 2. Roll-ups — `cron/analytics_rollup.php` (hourly; CLI only)
- Recompute **today and yesterday** into `product_stats_daily` and `site_stats_daily` (idempotent `INSERT … ON DUPLICATE KEY UPDATE` from events + real `orders/order_items`; revenue/orders always from the orders tables, **excluding cancelled**).
- Purge `analytics_events` older than `analytics_retention_days` in batches.
- Dashboard falls back to live queries on raw tables when roll-ups are empty (small shops) — implement one query layer `analytics_query($metric,$from,$to,$filters)` that chooses source automatically.

## 3. Admin UI — `admin/analytics.php` (+ `admin/ajax/analytics_data.php`, `admin/analytics_product.php`)
Sidebar: "Analytics" (admin + editor). Header controls: date range (Today, 7 d, 30 d, 90 d, This month, Custom), compare-to-previous-period toggle, category filter, CSV export, "Refresh roll-ups".

**A. KPI tiles** (with delta % vs previous period, arrows + colour + text):
Revenue · Orders · Units sold · Average order value · Visitors · Product views · Add-to-cart rate · Conversion rate (orders/visitors) · Cancel rate · COD pending amount (cash to collect).

**B. Charts**
1. Revenue & orders over time (line + bar, day/week/month toggle)
2. **Conversion funnel:** Product views → Add to cart → Checkout started → Purchased (counts + drop-off %)
3. Top 10 products by revenue (horizontal bar) and by units
4. Category share of revenue (donut)
5. Orders by status (donut) and by hour-of-day × weekday heatmap (when do people order)
6. Traffic sources (referrer/utm) and device split
7. New vs returning visitors only if cheap (skip otherwise — say so)

**C. Product analytics table** (sortable, searchable, paginated 25): Product, category, views, unique visitors, add-to-cart, **view→cart %**, **cart→purchase %**, orders, units, revenue, avg price, stock left, days of stock remaining (stock ÷ avg daily units over last 30 d), trend sparkline (inline SVG, last 14 d), status badge: 🔥 *Trending* (views +50 % vs prev), *Slow mover* (views but no sales), *Dead stock* (no views/sales 60 d), *Low stock*, *Out of stock*.

**D. Product detail page** `admin/analytics_product.php?id=`: same KPIs scoped to the product, daily chart, **variant breakdown** from `v_sales_by_variant` (which size/colour sells; stock vs sales so you know what to reorder), referrer split, last 20 orders containing it, stock movement mini-ledger.

**E. Insights panel** (plain-language rules, no ML):
- "Low stock & selling fast: *X (Size M)* has 3 left, ~2 days of stock."
- "High views, low add-to-cart: *Y* — check price/photos."
- "Added to cart but never bought: *Z*."
- Top searches with **zero results** (from `search_log`) → product ideas.
- Coupons usage and revenue impact.

**F. Exports:** CSV for every table (UTF-8 BOM). Print-friendly view.

## 4. Quality rules
- All queries parameterised, range limited to 366 days, indexes used (include `EXPLAIN` summary in your notes), pagination server-side.
- Timezone: group by admin timezone (convert in SQL with `CONVERT_TZ` only if tz tables loaded; otherwise offset arithmetic — implement a helper and test around midnight).
- Money formatting via `price()`; numbers localised; empty states with clear copy ("No data yet — views appear after visitors browse products").
- Numbers on the **existing admin dashboard** (`admin/dashboard.php`) must reconcile with Analytics for the same range (add a unit-style check script `tests/analytics_reconcile.php`).
- Seed script `tests/seed_demo_data.php` (CLI) to generate 60 days of fake events/orders so charts can be reviewed — clearly flagged as demo, with `--purge` option.

## Acceptance
1. View a product 3× from 2 browsers → views 3, unique 2 (after dedupe rules).
2. Add to cart then buy → funnel numbers increment each stage; revenue equals order total minus shipping (state the rule).
3. Cancel the order → revenue and units drop in the analytics for that day.
4. Variant breakdown shows sales per Size/Color combination.
5. 50 k events → dashboard renders < 500 ms using roll-ups.
