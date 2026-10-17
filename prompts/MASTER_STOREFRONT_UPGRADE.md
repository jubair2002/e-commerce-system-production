# MASTER PROMPT — Modern, professional, clean & minimal storefront (with accounts, structured folders)

> Give this ONE file to your IDE assistant. It works in **phases with stop-gates**: finish a phase, run the checks, commit, print a short report, then **wait for me to say "continue"**. Do not start the next phase on your own.
> Before Phase A: run `sql/009_storefront_content_reviews.sql` in phpMyAdmin (safe to re-run) and create a git tag `before-storefront-v3`.

---

## 0. Role, scope, rules

You are a senior PHP engineer and product designer. You are upgrading the **customer-facing storefront** of `jubair2002/e-commerce-system-production` (brand shown in the UI: *Jinispotro*). Plain PHP + PDO + MySQL/MariaDB, no framework, no Composer, no Node build step. Runs on XAMPP (Apache, `/htdocs/<folder>/` sub-folder install) **and** a Linux VPS (Apache or Nginx), at a domain root **or** a sub-folder.

**In scope:** everything a shopper sees — layout, design system, pages, accounts UX, new content features, folder structure, URLs, performance, SEO, accessibility.
**Out of scope:** admin visual design (it is finished — keep `assets/css/style.css` and admin look unchanged). You may add the few admin pages listed in §9, built in the same admin style. Analytics (prompt 09) and final security pass (prompt 10) will be done later by me — don't build them, but don't block them (keep hook events `product.viewed`, `cart.added`, `checkout.started`, `order.placed` firing).

**Hard rules**
1. Keep every existing behaviour working: variants, live availability, atomic stock (`stock_adjust()`), COD checkout, coupons, shipping methods, mail queue, customer accounts, wishlist, monitoring hooks, `app_finish_response()` performance pattern.
2. Never break a URL that has been emailed or bookmarked (verify, reset, track, order-confirmation links). Add 301 redirects for every moved page and keep query strings.
3. Every POST: CSRF. Every query: prepared statements. Every output: `e()`. No inline `onclick`. No new third-party CDNs (self-host everything in `assets/vendor/`).
4. PHP syntax level: the code already uses `str_contains/str_starts_with/str_ends_with` (PHP 8). Either declare **PHP ≥ 8.0** in README/DEPLOY and `.env.example`, or add `includes/polyfills.php`. Pick PHP ≥ 8.0 + polyfill file for safety.
5. Small, reviewable commits per step: `git commit -m "A1: ..."`. After each phase run `php -l` on every `.php` file and the checks listed for that phase.
6. No placeholder code ("...") — full files. When you rename/move, update **every** reference (PHP, JS, CSS, emails, sitemap, `.htaccess`, docs). Prove it with the link-checker (Phase A).
7. Ask me nothing unless a step is destructive and ambiguous; choose the sensible default and note it in the phase report.

---

## 1. What I found in the repo (verified — start from this)

**Structure today**
- **19 PHP pages sit loose in the web root:** `index, shop, category, product, cart, checkout, order_confirmation, track, login, register, logout, forgot_password, reset_password, verify_email, resend_verification, maintenance, 404, sitemap, _product_card`.
- Already foldered: `admin/`, `account/` (11 files), `api/` (5), `includes/` (+ `lib/PHPMailer`), `config/`, `cron/`, `assets/{css,js,vendor,uploads}`, `storage/`, `sql/`.
- `sql/schema.sql` is now the all-in-one schema (1099 lines, 35 tables). **`sql/schemal.sql` is a byte-identical typo duplicate** → delete it.
- `storage/collect_metrics.lock` and `storage/monitor_state.json` are tracked by git (runtime files) → untrack + `.gitignore`.
- `README.md` still describes the upgrade pack, not the project. `DEPLOY.md` is good.
- `includes/` mixes libraries and view partials (`shop_header.php`, `shop_footer.php`) and `_product_card.php` lives in the root.
- ~110 hard-coded page references like `shop_url('shop.php')`, `shop_url('login.php')`, `redirect('cart.php')` across the code → need a central route map.

**Design/code state**
- `assets/css/shop.css` is one 2,417-line file (editorial-minimal tokens already defined: ink `#111`, line `#e8e8e6`, Inter, radius 2/4/6). Good base, but components, pages and utilities are mixed together.
- Templates lean on **Bootstrap 5 utility classes + inline `style=""`** (checkout 13, track 10, cart 8, header 8 …) and **Bootstrap JS** for dropdown/offcanvas. Result: inconsistent spacing, hard to theme, extra JS weight.
- Header has a mini-cart drawer, live search, account dropdown, mobile off-canvas — functional but visually generic (Bootstrap list-group in the mobile menu, `bg-light` panels).
- No content pages (footer links "About" → `index.php#about`, "Cash on Delivery Policy" → `checkout.php`), no contact form, no newsletter, no reviews/ratings, no size guide, no 404 design beyond a stub.
- Images: uploaded originals are served as-is (no resized/WebP variants) → heavy product grids.
- Admin `login.php`, `order_print.php`, `install.php` still load Bootstrap from `cdn.jsdelivr.net` (self-host them too).
- Storefront already has: variants + live availability, wishlist, customer auth/account area (register, login, reset, verify, profile, addresses, orders, security, export/delete), COD checkout with shipping methods + coupons.

---

## PHASE A — Folder restructure + clean URLs (do this first, mechanical, fully verified)

### A1. Target structure (web root keeps ONE page: `index.php`)
```
/
├── index.php                  home
├── .htaccess  robots.txt  .env.example  README.md  DEPLOY.md  LICENSE
├── shop/        index.php (catalog+search)  category.php  product.php
├── cart/        index.php  checkout.php  confirmation.php
├── auth/        login.php  register.php  logout.php  forgot-password.php
│                reset-password.php  verify-email.php  resend-verification.php
├── account/     (existing, unchanged names)
├── track/       index.php
├── pages/       view.php (content pages)  contact.php  newsletter.php
├── errors/      404.php  500.php  maintenance.php
├── seo/         sitemap.php
├── api/         (existing + newsletter.php, review.php)
├── admin/       (existing + pages, reviews, messages, subscribers — see §9)
├── includes/
│   ├── partials/   header.php  footer.php  product_card.php  mini_cart.php  breadcrumb.php  pagination.php  empty_state.php  review_stars.php
│   ├── data/       bd_districts.php
│   └── (existing libraries: functions, catalog, inventory, customer_auth, mailer, traffic, hooks, crypto, shop_bootstrap, polyfills)
├── assets/
│   ├── css/  style.css (admin, untouched)  shop/ (see Phase B)  shop.css (built file)
│   ├── js/   shop/ (modules)  + existing admin files
│   ├── img/  (icons sprite, placeholders, og-default)
│   ├── vendor/ uploads/ (existing)
├── config/ cron/ storage/ sql/ tests/ docs/ tools/
```
Rules: no PHP page except `index.php` in the root; `includes/` stays web-denied (`.htaccess` already); `storage/`, `config/`, `sql/`, `tests/`, `tools/`, `cron/` must be **denied from the web** (add/verify `.htaccess` with `Require all denied` in each).

### A2. Move map (use `git mv` to keep history) and legacy redirects
| Old | New | Clean URL (when `pretty_urls=1`) |
|---|---|---|
| `shop.php` | `shop/index.php` | `/shop` |
| `category.php?slug=x` | `shop/category.php?slug=x` | `/category/x` |
| `product.php?slug=x` | `shop/product.php?slug=x` | `/product/x` |
| `cart.php` | `cart/index.php` | `/cart` |
| `checkout.php` | `cart/checkout.php` | `/checkout` |
| `order_confirmation.php` | `cart/confirmation.php` | `/order/confirmation` |
| `track.php` | `track/index.php` | `/track` |
| `login.php` `register.php` `logout.php` | `auth/login.php` … | `/login` `/register` `/logout` |
| `forgot_password.php` `reset_password.php` | `auth/forgot-password.php` `auth/reset-password.php` | `/forgot-password` `/reset-password` |
| `verify_email.php` `resend_verification.php` | `auth/verify-email.php` `auth/resend-verification.php` | `/verify-email` `/resend-verification` |
| `maintenance.php` `404.php` | `errors/maintenance.php` `errors/404.php` | via `ErrorDocument` |
| `sitemap.php` | `seo/sitemap.php` | `/sitemap.xml` |
| `_product_card.php` | `includes/partials/product_card.php` | – |
| `includes/shop_header.php` `shop_footer.php` | `includes/partials/header.php` `footer.php` | – |
| *(new)* | `pages/view.php?slug=x` | `/page/x` |
| *(new)* | `pages/contact.php` | `/contact` |

### A3. Central route helper (replace ALL hard-coded page strings)
In `includes/functions.php` add `route(string $name, array $params = []): string` with one array map `name → [file path, pretty pattern]`. Behaviour:
- `pretty_urls=0` (default; works on any server): returns file URLs, e.g. `route('product',['slug'=>'tee'])` → `BASE_URL.'shop/product.php?slug=tee'`.
- `pretty_urls=1` (needs `mod_rewrite`/Nginx rules): returns `BASE_URL.'product/tee'`.
- Also `route_is(string $name): bool` for active-nav highlighting (replaces `basename($_SERVER['PHP_SELF'])` checks).
- Keep `shop_url()/url()/admin_url()` for assets and non-page paths; keep `shop_url('shop.php')` working as a **deprecated shim** that maps old filenames through `route()` during the migration, then remove all uses.
- Replace every `shop_url('x.php')`, `redirect('x.php')`, `href="…php"`, JS `location`/`fetch` page URLs, email link builders (`track_url`, verify/reset/confirm URLs in `includes/customer_auth.php`, `includes/mailer.php`), `seo/sitemap.php`, `admin/*` links to the storefront, and the `?next=` allow-list in `customer_auth.php`.
- Update path-dependent code: `require_once __DIR__ . '/includes/…'` → `__DIR__ . '/../includes/…'` in moved files; BASE_URL detection in `config/config.php` must treat `shop|cart|auth|track|pages|errors|seo` like it treats `admin|account|api`; `check_storefront_maintenance()` and `traffic.php` area detection must use the new paths (`/api/`, `/admin/` stay).
- Active-state, canonical URL and breadcrumb helpers must use `route()`.

### A4. `.htaccess` (root) — works at domain root and in a sub-folder
- `ErrorDocument 404 …/errors/404.php`, `500 …/errors/500.php`.
- **301 redirects from every old URL to its new location, preserving the query string** (`QSA`), especially `verify_email.php?t=…`, `reset_password.php?t=…`, `track.php?…`, `order_confirmation.php?o=…&t=…`, `product.php?slug=…`, `category.php?slug=…`, `shop.php?…`. Emails already sent must still work.
- Pretty-URL rewrites (only active when the file `storage/pretty_urls.on` exists — the admin Settings toggle writes/removes it — so a server without mod_rewrite never breaks).
- Keep compression/cache headers already present. Add `Header always set X-Content-Type-Options "nosniff"` and `Referrer-Policy`.
- Provide the equivalent **Nginx** block in `DEPLOY.md`.

### A5. Repo hygiene (same phase)
- `git rm sql/schemal.sql`; `git rm --cached storage/collect_metrics.lock storage/monitor_state.json` and add `storage/*` (except `.htaccess`/`.gitkeep`) to `.gitignore`; add `tests/`, `tools/`, `docs/` with `.gitkeep` where needed.
- Move `DEPLOY.md` stays at root; add `docs/STRUCTURE.md` (the tree above + one line per folder).
- Rewrite `README.md` for the **project** (what it is, features, requirements PHP ≥ 8.0/MariaDB 10.4+, install steps `schema.sql` → create admin → configure `.env`, cron lines, folder map, URL map, screenshots placeholder).
- Self-host Bootstrap + icons in `admin/login.php`, `admin/order_print.php`, `admin/install.php` (no CDN anywhere).

### A6. Verification (must pass before Phase B)
Create `tools/link_check.php` (CLI) that: (1) runs `php -l` on every PHP file; (2) greps for any remaining reference to an old filename; (3) boots the app with a fake request for every route and reports non-200/301 responses; (4) checks every `href`/`src` in rendered storefront HTML resolves (file exists or route known). Also `tests/redirects.md` listing 15 old URLs and their expected 301 targets. **Test the site both at `http://localhost/` and at `http://localhost/jinispotro/` (sub-folder).**
**STOP. Report: files moved, redirects verified, link-check output.**

---

## PHASE B — Design System v2 (tokens, components, no inline styles)

### B1. Direction
Calm, premium, editorial minimal — more **modern & airy** than today: generous whitespace, large clear type, thin hairlines, one restrained accent, soft 12 px radius on cards/inputs/buttons (update tokens: `--radius-sm:6px; --radius-md:10px; --radius-lg:16px; --radius-pill:999px`), subtle layered shadows only on floating surfaces (drawers, dropdowns, sticky bars), **no gradients, no emoji**. Photography leads; UI recedes. Brand name from Settings, accent from `theme_accent` (validated hex), optional **dark mode** (`dark_mode_enabled`, respects `prefers-color-scheme`, toggle in footer/account menu, stored in `localStorage` with try/catch, no flash of wrong theme: tiny inline script in `<head>` sets `data-theme`).

### B2. Tokens (CSS custom properties in `assets/css/shop/00-tokens.css`)
Colour (light + dark sets): `--bg, --bg-soft, --surface, --ink, --ink-2, --muted, --line, --accent, --accent-contrast, --sale, --ok, --warn, --danger, --focus`. All text/background pairs must meet **WCAG AA**; verify with a script `tools/contrast_check.php` listing the pairs.
Type: self-hosted **Inter** (400/500/600/700 already in `assets/vendor/fonts/`) for UI + one self-hosted display serif for headlines (e.g. *Fraunces* or *Playfair Display*, `woff2`, 2 weights, `font-display: swap`, `size-adjust` fallback to avoid layout shift). Fluid scale with `clamp()`: `--fs-xs … --fs-hero`. Spacing scale `--space-1 … --space-12` on an 8 px grid. Motion: `--ease-out`, durations 150/220/320 ms, **disabled under `prefers-reduced-motion`**. Z-index scale. Container `--max-w:1240px`, gutters 16/24/32.

### B3. File organisation (no build tools; a PHP CLI script concatenates)
`assets/css/shop/`: `00-tokens.css · 01-base.css · 02-layout.css · 03-utilities.css · components/{button,form,card,badge,chip,alert,toast,modal,drawer,dropdown,tabs,accordion,table,pagination,breadcrumb,skeleton,rating,price,swatch,stepper}.css · pages/{home,shop,product,cart,checkout,auth,account,content,track}.css`.
`tools/build_css.php` concatenates + minifies (strip comments/whitespace) into `assets/css/shop.css` (kept as the single file the header links, with `?v=filemtime`). Header must load only that one stylesheet + font css. Target: **≤ 70 KB minified CSS, ≤ 60 KB JS** (gzip much less).

### B4. Components (build once, reuse everywhere; each documented in `docs/UI_COMPONENTS.md` with the HTML snippet)
Buttons (primary, secondary, ghost, danger, icon, loading state with spinner, full-width) · inputs/select/textarea/checkbox/radio/switch with floating or top labels, inline errors (`aria-describedby`), password toggle · price block (sale + strike + % off) · badges/chips · rating stars (SVG, half stars) · product card · swatch + size pickers · quantity stepper · alerts + **toast** system (replaces flash banners for AJAX results; `aria-live="polite"`) · modal · slide drawer (mini-cart, filters, mobile menu) · dropdown menu · tabs · accordion · pagination · breadcrumb · skeleton loaders · empty states (icon + title + text + CTA) · table (responsive → cards on mobile).

### B5. JavaScript without Bootstrap JS
Create `assets/js/shop/ui.js` (≈ 3–4 KB) implementing dropdown, drawer/offcanvas, modal, tabs, accordion, toast, focus-trap, ESC/outside-click close, `aria-expanded`, scroll-lock — all keyboard accessible. Then **remove `bootstrap.bundle.min.js` from the storefront** (admin keeps it). Keep Bootstrap CSS only if still needed for the grid; preferred: replace it with your own 12-col CSS grid + utility classes and drop Bootstrap CSS from the storefront too (saves ~160 KB). Modules: `ui.js, cart.js (mini-cart), search.js, availability.js (existing), gallery.js, wishlist.js, filters.js, reviews.js, newsletter.js, theme.js` — `defer`, ES2018, no framework. `assets/js/shop.js` becomes a tiny loader or is removed.

### B6. Icons
Replace Bootstrap Icons font with an **inline SVG sprite** (`assets/img/icons.svg`, ~30 icons used) referenced via `<svg class="i"><use href="…#bag"/></svg>`; helper `icon('bag')` in PHP. Remove `assets/vendor/bootstrap-icons` from the storefront.

### B7. Cleanup rule
Zero `style="…"` attributes in storefront templates (dynamic values via CSS variables set on the element: `style="--w:60%"` is the only allowed pattern, or data attributes + JS), zero `<style>` blocks, zero inline `<script>` except the theme-flash snippet and JSON config (`<script type="application/json" id="shop-config">`). Replace every `!important` with proper specificity.
**STOP. Report: CSS/JS sizes before/after, components list, contrast check output, screenshot checklist.**

---

## PHASE C — Page-by-page redesign (mobile-first: 360 / 768 / 1280)

**Global shell**
- **Announcement bar** (admin text, dismissible, remembers dismissal). **Header:** logo/wordmark left · centre nav (Shop, Categories mega-menu with category images, New, Sale, About) · right: search, wishlist heart (count), account, cart (count badge). Sticky with hairline; shrinks slightly on scroll. Active link via `route_is()`.
- **Search:** full-width overlay on mobile, inline expand on desktop; live suggestions (existing `api/search_suggest.php`) showing image, name, price; recent searches (localStorage); "no results" suggestions; keyboard navigation.
- **Mini-cart drawer:** image, name, variant label, stepper, remove, free-shipping progress bar ("Add ৳X more for free delivery" from `shipping_methods.free_over`), subtotal, Checkout/View cart. Content fetched **only when opened** (performance rule).
- **Mobile bottom tab bar** (setting `mobile_bottom_nav`): Home · Shop · Search · Wishlist · Account (cart stays in header). Hidden on checkout.
- **Mobile menu drawer:** large tap targets, categories accordion, account section, language/theme toggle.
- **Footer:** newsletter strip (if enabled), 4 columns driven by Settings + `pages` (footer pages), payment/COD note, social icons (SVG), small print, theme toggle, "Back to top".
- Breadcrumbs on category/product/content pages. Consistent page titles/H1.

**Home (`index.php`)** — sections toggled by `home_show_*`: 1) **Hero** (banner from `storefront_banners`, large image, headline in display serif, one CTA; text-only fallback; if several banners → fade slider with dots, pause on hover, no layout shift) 2) **Trust bar** (4 items from `trust_item_*`) 3) **Shop by category** (image tiles, 2/3/4 columns) 4) **New arrivals** (grid) 5) **Editorial split banner** (image + text + CTA, uses a `promo_strip`/`category_row` banner) 6) **Best sellers** (horizontal scroll-snap row on mobile) 7) **Featured / on sale** 8) **Customer reviews** (latest approved 5-star reviews, `home_show_reviews`) 9) **Brand story** (`home_brand_story_*`) 10) **Newsletter**. Every section has a heading, optional "View all" link and skeleton fallback. Max ~12 queries total for the page.

**Shop / Category (`shop/index.php`, `shop/category.php`)** — page header with title + result count · sort dropdown · **grid density toggle** (2/3/4 columns, remembered) · left filter sidebar on desktop (categories, price range slider with min/max inputs, availability, on sale, option filters with swatches), **filter drawer** on mobile with "Show N results" button · removable filter chips + "Clear all" · server-side pagination plus optional "Load more" (progressive enhancement: real `<a rel="next">` links) · skeleton cards while loading · friendly empty state.

**Product (`shop/product.php`)** — gallery (large image + thumbnails; swipe on mobile; click-to-zoom lightbox with keyboard arrows; image changes with colour variant) · sticky buy box on desktop · title, rating summary (stars + count → scrolls to reviews), price block, short description · **option pickers** (size buttons with disabled/struck sold-out, colour swatches with name label, dropdown for others), live availability line (existing realtime module), quantity stepper, **Add to cart** + **Buy now**, wishlist heart · **size guide** link (modal showing `pages` slug from `size_guide_page_slug`) · delivery estimate with **district selector** (uses `includes/data/bd_districts.php` → inside/outside Dhaka → ETA + cost from `shipping_methods`) · trust mini-list (COD, returns) · accordions (Description, Details, Shipping & returns, Care) · **reviews section** (summary bars 5→1, list, "Write a review" form for logged-in buyers per settings, helpful sort, verified-purchase badge, admin reply) · related products · **recently viewed** (localStorage ids → `api/availability.php?ids=`) · **sticky mobile add-to-cart bar** (price + button) when `sticky_add_to_cart` · JSON-LD `Product` + `AggregateRating` + `Offer`, OG/Twitter tags, canonical.

**Cart (`cart/index.php`)** — two-column (items | summary); per-line image, name, variant, stock warning, stepper, remove, save-to-wishlist; coupon field with inline result; shipping estimate; free-shipping progress; empty state with suggested products; continue shopping.

**Checkout (`cart/checkout.php`)** — **3-step visual progress** (Cart → Details → Confirm) on one page, no distraction (simplified header, no bottom bar) · logged-in: address picker cards + "use a new address"; guest: inline "Have an account? Sign in" · fields with proper `autocomplete`, BD phone validation + mask, **district dropdown** (64 districts) driving shipping method, area/thana text · payment: COD selected card (+ bank transfer when enabled) with the exact amount to prepare · order summary sticky on desktop, collapsible on mobile · terms checkbox · submit button shows loading + disables to prevent double submit · trust badges · inline field errors + top summary with anchor links. Keep every server-side validation and the atomic transaction untouched.

**Order confirmation (`cart/confirmation.php`)** — success mark, order number with copy button, COD amount, delivery estimate, items, address, "Track order", "Create account to track" (existing), WhatsApp/phone contact, continue shopping. Print-friendly CSS.

**Track (`track/index.php`)** — clean form (order number + phone/email), result as vertical **timeline** with icons, courier + tracking number, items; rate-limited (existing).

**Auth pages (`auth/*`)** — **split layout** on desktop (left: calm brand image/panel with a short value statement, right: form card), single column on mobile. Fields: password show/hide, live strength meter + rules, inline errors, remember-me, "Continue as guest" link where guest checkout is enabled. Same polish for register, forgot/reset, verify-email result pages (success/expired states with clear next action). Do not change the security logic of `includes/customer_auth.php` (token hashing, throttling, no enumeration).

**Account area (`account/*`)** — new shell: sidebar (desktop) / scrollable tabs (mobile); **dashboard cards** (next/last order status with progress, saved addresses, wishlist preview, profile completeness meter, verified-email banner); orders list as cards with status chips and "Buy again"; order detail with timeline and invoice; addresses as selectable cards with default badge; profile with avatar upload preview (client-side) ; security page with device list; wishlist grid with availability and "Move to cart". Consistent empty states and toasts.

**Content & utility pages** — `pages/view.php?slug=` renders `pages` rows (readable 720 px column, typography styles for h2/h3/lists/tables/blockquote, TOC for long pages, `Article`/breadcrumb JSON-LD, 404 if draft); **Contact** (`pages/contact.php`: form name/email/phone/order no./message + store details + map link; honeypot + time-trap + rate-limit; saves to `contact_messages`, queues `contact_received_admin` mail, success toast); **404** (search box + popular categories); **500** (no stack trace); **Maintenance** (brand + ETA text); **Empty/error states** everywhere use the shared partial.

**Micro-interactions (restrained):** button press feedback, add-to-cart morphs to a ✓ then opens/pulses the cart badge, heart pop, image fade-in on load (blur-up placeholder using average colour), skeletons, smooth drawer, hover image swap on cards (desktop only), scroll-reveal ≤ 8 px translate **only** if `prefers-reduced-motion: no-preference`. No parallax, no autoplay video, no carousels that hijack scroll.
**STOP. Report with a screenshot checklist per page at 360/768/1280, light + dark.**

---

## PHASE D — New storefront features (backend + small admin screens)

1. **Image pipeline** (`includes/images.php`, setting `image_pipeline_enabled`): on upload (admin product/banner/category/avatar) and lazily on first request, create resized **WebP + JPEG** variants (e.g., 320, 640, 960, 1440 px) into `assets/uploads/cache/` using GD (`imagecreatefrom*` → `imagewebp`), keeping originals. Helper `img_tag($file, $alt, $sizes, $opts)` outputs `<picture>` with `srcset`/`sizes`, explicit `width/height`, `loading="lazy"` (first hero image `fetchpriority="high"`, never lazy), and dominant-colour background placeholder. Cache folder denied PHP execution; add `cron/purge_image_cache.php`. Fall back gracefully if GD/WebP is missing.
2. **Reviews & ratings** (tables from 009): `api/review.php` (POST, CSRF, login per `reviews_require_login`, verified-purchase detection via `order_items` + `orders.customer_id`, one review per customer per product, 1–5 stars, title ≤ 120, body 10–2000 chars, spam honeypot, rate-limit, auto-approve per setting); recalculates `products.rating_avg/rating_count` on approve/reject/delete; queues `review_received_admin`. Output is escaped; admin replies shown.
3. **Content pages CMS** (table `pages`): sanitise saved HTML with an allow-list (no `<script>`, no `on*` attributes, no `javascript:` URLs) both on save and on render.
4. **Contact + newsletter**: `api/newsletter.php` double-opt-in (`newsletter_enabled`, `newsletter_double_optin`; confirm link token hashed; unsubscribe link in every mail), dedupe, rate-limit. Admin can export subscribers CSV.
5. **Size guide modal**, **recently viewed**, **wishlist heart everywhere**, **back-to-top**, **share buttons** (Web Share API with copy-link fallback, no third-party widgets).
6. **BD districts dataset** (`includes/data/bd_districts.php`: 64 districts with `zone` = inside_dhaka / outside_dhaka) used by checkout, product delivery estimator and account addresses; `shipping_methods` stay the source of cost/ETA.
7. **SEO:** unique `<title>`/meta description per page type, canonical, OG/Twitter, JSON-LD (`Organization`, `WebSite` + SearchAction, `BreadcrumbList`, `Product`, `Article`), `seo/sitemap.php` (products, categories, content pages, lastmod), `robots.txt` (disallow `/admin/ /account/ /cart/ /auth/ /api/ /track/`), `noindex` on cart/checkout/account/auth/track pages, hreflang not needed.

## PHASE E — Performance, accessibility, QA (budgets are pass/fail)

- **Budgets (mobile Lighthouse, local build):** Performance ≥ 90, Accessibility ≥ 95, Best Practices ≥ 95, SEO ≥ 95 on home, shop, product. LCP < 2.5 s on throttled "Fast 3G"-like profile, CLS < 0.05, TBT < 150 ms. Home page ≤ 15 SQL queries, product page ≤ 20, TTFB < 150 ms locally (use the `Server-Timing` header already added).
- **Assets:** preload the hero image + main font; defer all JS; `Cache-Control: immutable` + `?v=filemtime` versioning; gzip/deflate (already); no layout shift (width/height on every image, reserved space for banners/skeletons).
- **A11y:** semantic landmarks, skip-to-content link, focus-visible ring, focus trap in drawers/modals, label every control, `aria-live` for toasts and stock changes, colour-independent states (icons + text), touch targets ≥ 44 px, `lang` attribute, reduced-motion respected, keyboard-only purchase possible end to end.
- **QA file** `tests/QA_STOREFRONT.md` with step lists + expected results for: every route in the move map (and its old URL redirect), guest purchase, logged-in purchase, variant out-of-stock, coupon, COD min/max, wishlist, review submit/moderate, newsletter confirm, contact form, pretty-URL on/off, sub-folder install, dark mode, 360/768/1280 screenshots, keyboard-only, JS disabled (catalog, product, cart, checkout must still work via plain forms), slow-network test, 404/500/maintenance pages.
- **Automated:** `tools/link_check.php`, `tools/contrast_check.php`, `tests/smoke_routes.php` (HTTP status per route), plus the existing CLI tests; `tests/run_all.php` prints PASS/FAIL.

---

## 9. Admin additions required by the new features (same admin look, `require_role`, CSRF, `log_activity`)

- `admin/pages.php` + `page_form.php` — CRUD for `pages` (title, slug auto, HTML editor = plain textarea with a small toolbar **or** simple contenteditable with sanitiser, SEO fields, footer/header toggles, order, draft/published, preview link).
- `admin/reviews.php` — moderation queue (pending/approved/rejected), filters, bulk approve/reject/delete, reply, link to product + order; recalculates product rating.
- `admin/messages.php` — contact inbox (new/read/replied/spam), view, mark, delete, reply-by-email link.
- `admin/subscribers.php` — list, status filter, CSV export (admin only), delete/unsubscribe.
- `admin/settings.php` — new tab **"Storefront design"**: `pretty_urls` (writes/removes `storage/pretty_urls.on`), `dark_mode_enabled`, `mobile_bottom_nav`, `sticky_add_to_cart`, `image_pipeline_enabled` (+ "Clear image cache" button), trust items 1–4, brand-story title/text, `home_show_reviews/brand_story/newsletter`, reviews settings, newsletter settings, size-guide slug. Validate via existing `settings_save()`.
- Sidebar links: Pages, Reviews (badge = pending), Messages (badge = new), Subscribers.
- `admin/product_form.php`: use the image pipeline helper on upload (no visual change).

---

## 10. Definition of done
1. Web root contains only `index.php` (+ config/static files); folder map = §A1; `docs/STRUCTURE.md` written; old URLs 301 correctly (including token links); works at domain root and in a sub-folder; pretty URLs on/off both work.
2. Storefront CSS/JS under budget; no Bootstrap JS on storefront; no inline styles; no CDN requests anywhere (storefront + admin login/print/install).
3. Every page in Phase C implemented, responsive 360–1280, light + dark, accessible, with empty/error states.
4. Accounts UX polished end to end (auth split layout, dashboard, orders, addresses, wishlist, security) without changing security logic.
5. Content pages, contact, newsletter, reviews, image pipeline, SEO all working, with the admin screens in §9.
6. Lighthouse and query budgets met; `tests/run_all.php` green; `tools/link_check.php` clean; `QA_STOREFRONT.md` filled in with results.
7. README rewritten; `.gitignore` cleaned; `sql/schemal.sql` removed; runtime files untracked.

## 11. Final report format (after the last phase)
1. Tree of the final structure. 2. Table of old → new file paths. 3. Before/after metrics (CSS KB, JS KB, queries per page, Lighthouse). 4. List of new settings keys. 5. Anything deferred and why. 6. Reminder of what I still run myself: prompt 09 (analytics) and prompt 10 (security/QA/deploy).
