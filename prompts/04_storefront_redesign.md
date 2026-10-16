# 04 — Storefront redesign: professional, clean, minimal

> Paste `00_MASTER_CONTEXT_and_ORDER.md` first. Prompts 01–03 done. **Admin design must NOT change.**

## Role
Senior front-end engineer with a strong product-design eye (think Everlane / Aesop / Muji: restrained, editorial, lots of whitespace). Stack stays Bootstrap 5.3 grid utilities + a fully custom `assets/css/shop.css` rewrite (the current storefront is the generic indigo gradient look — replace it).

## Design direction
- **Mood:** calm, premium, minimal. Content (product photos) is the hero; UI recedes.
- **Palette (CSS variables, overridable from admin settings later):**
  `--ink:#111111; --ink-2:#4b4b4b; --muted:#8a8a8a; --line:#e8e8e6; --bg:#ffffff; --bg-soft:#f7f7f5; --accent:#111111; --accent-contrast:#ffffff; --sale:#b42318; --ok:#067647; --warn:#b54708;`
  One accent only. No gradients, no heavy shadows, no emoji, radius 2–6 px, 1 px hairlines.
- **Type:** system stack or a single web font (Inter via Google Fonts, `display=swap`) + optional serif for hero headings. Scale 14/16/18/24/32/48, tight tracking on headings, 1.6 line-height for body.
- **Spacing:** 8-px grid; sections 72–96 px vertical on desktop, 48 px mobile; max content width 1200 px.
- **Motion:** 150–200 ms opacity/transform only; respect `prefers-reduced-motion`.
- **Imagery:** product cards 4:5 aspect ratio, `object-fit:cover`, `loading="lazy"`, `width/height` attributes (no layout shift), hover shows 2nd gallery image if present.
- **Accessibility:** WCAG AA contrast, visible focus rings, semantic landmarks, labels on all inputs, `alt` text from `product_images.alt_text`, keyboard-operable option pickers.

## Layout & pages (rewrite templates, keep URLs and PHP logic)
Files: `includes/shop_header.php`, `includes/shop_footer.php`, `index.php`, `shop.php`, `category.php`, `product.php`, `_product_card.php`, `assets/css/shop.css`, new `assets/js/shop.js`.

1. **Header:** slim announcement bar (admin-controlled text, dismissible) · logo/wordmark left · primary nav (Shop, categories dropdown) · search icon (expands inline field, live suggestions via `api/search_suggest.php`, logs to `search_log`) · cart icon with count badge and mini-cart drawer (slides from right, shows lines with variant label, qty +/-, subtotal, Checkout button). Sticky, hairline bottom border, transparent→solid not needed (keep simple).
2. **Home:** (sections toggle from admin settings `home_show_*`)
   - Hero: one large banner from `storefront_banners` (placement `hero`), headline + single CTA; fallback text hero if none.
   - Category tiles (3–4 across, image + name).
   - "New arrivals" and "Best sellers" (by `sold_count`) grids, 4 across desktop / 2 mobile.
   - Featured strip, trust row (COD available · Fast delivery · Easy returns — text editable via settings), simple newsletter (store emails in new table or skip if not requested — **skip** unless trivial).
3. **Shop / Category listing:** left filter sidebar on desktop, bottom-sheet filter on mobile: categories, price range (min/max inputs), availability (in stock only), on sale, **option filters** (Size/Color values collected from active variants). Sort: newest, price ↑↓, best selling. Pagination numbered. Active filter chips with clear-all. Show result count. Empty state with friendly copy.
4. **Product card:** image, name (2-line clamp), price (sale in `--sale` with struck regular price), tiny swatch dots for color options (max 5 + "+n"), badges (Sale −20%, "Only 2 left" when `shop_show_low_stock_badge`, "Sold out" greyed). Entire card is a link; quick-add only for simple products.
5. **Product detail (most important page):**
   - Gallery: large image + thumbnails (vertical on desktop, horizontal swipe on mobile), click-to-zoom lightbox (vanilla), image switches when a color variant with image is chosen.
   - Info column: breadcrumb, title, price block, short description, **option pickers** (buttons for size, round swatches for color, dropdown for others — driven by `display_type`), selected-variant summary, **live availability line** (from prompt 03 `availability.js`), quantity stepper (min 1, max = available), **Add to cart** (primary, full-width) and **Buy now** (secondary → goes to checkout), SKU, delivery estimate (from `shipping_methods` min/max days), accordion tabs: Description · Details · Shipping & returns.
   - If an option isn't chosen, Add to cart shows "Select size" (disabled with helper text) — never fail silently.
   - Related products (same category), recently viewed (localStorage ids → `api/availability.php?ids=`).
   - JSON-LD `Product` + `Offer` (availability from live status) and OpenGraph tags using `meta_title/meta_description`.
6. **Footer:** 3–4 columns (about, shop links, help/track order, contact from settings), payment note "Cash on Delivery", social icons from settings, copyright. Simple.
7. **404 / empty / error pages** match the style. Maintenance mode page when `maintenance_mode=1` (admins bypass).

## Technical requirements
- Mobile-first, breakpoints 576/768/992/1200. Lighthouse targets: Performance ≥ 90, Accessibility ≥ 95 on product page.
- Remove unused CSS/JS; defer scripts; only load Bootstrap Icons subset you use (or inline SVG sprite for ~15 icons).
- Theme tokens read from settings: output a `<style>:root{--accent: <?= e(setting('theme_accent','#111111')) ?>}</style>` block in header (validate hex with regex).
- Keep all existing PHP behaviours (flash messages, CSRF, 404 handling). Escape everything with `e()`.
- No inline `onclick`; progressive enhancement: product page must still work (add to cart with default variant select) if JS fails.

## Deliverables
Full rewritten files, a one-page style guide comment block at the top of `shop.css` (tokens, components), and screenshots checklist for: home, listing, product (desktop + mobile), mini-cart open.

## Acceptance
- Visual diff: nothing in `admin/` or `assets/css/style.css` changed.
- All storefront pages render with 0 PHP notices at `E_ALL`.
- Product page: choose Size + Color → price, image, stock label update instantly; sold-out combos disabled.
