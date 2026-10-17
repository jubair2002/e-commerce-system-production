# Storefront QA & Verification Matrix (Phase E)

This document contains step-by-step verification flows and expected outcomes for the modernized Jinispotro storefront, covering routing, customer flows, e-commerce transactions, security controls, accessibility, and administrative moderation.

---

## 1. Route Relocation & Legacy 301 Redirects

| Route Name | Physical Path | Legacy URL | Pretty URL | Expected Result |
|---|---|---|---|---|
| `home` | `index.php` | `/index.php` | `/` | 200 OK; renders curated homepage |
| `shop` | `storefront/shop/index.php` | `/shop.php` | `/shop` | 301 Redirect to clean URL or 200 OK |
| `category` | `storefront/shop/category.php` | `/category.php?slug={s}` | `/category/{slug}` | 301 Redirect or 200 OK; displays filtered category items |
| `product` | `storefront/shop/product.php` | `/product.php?slug={s}` | `/product/{slug}` | 301 Redirect or 200 OK; renders gallery, buy box, reviews |
| `cart` | `storefront/cart/index.php` | `/cart.php` | `/cart` | 301 Redirect or 200 OK; shows line items and shipping bar |
| `checkout` | `storefront/cart/checkout.php` | `/checkout.php` | `/checkout` | 301 Redirect or 200 OK; renders 3-step progress and 64 BD districts |
| `order_confirmation`| `storefront/cart/confirmation.php` | `/order_confirmation.php` | `/order/confirmation` | 200 OK; token verified order summary |
| `track` | `storefront/track/index.php` | `/track.php` | `/track` | 200 OK; rate-limited order status lookup |
| `login` | `storefront/auth/login.php` | `/login.php` | `/login` | 200 OK; split brand & login layout |
| `register` | `storefront/auth/register.php` | `/register.php` | `/register` | 200 OK; split layout with password meter |
| `forgot_password` | `storefront/auth/forgot-password.php`| `/forgot_password.php` | `/forgot-password` | 200 OK; uniform response anti-enumeration |
| `reset_password` | `storefront/auth/reset-password.php` | `/reset_password.php` | `/reset-password` | 200 OK; single-use token verification |
| `verify_email` | `storefront/auth/verify-email.php` | `/verify_email.php` | `/verify-email` | 200 OK / 302; marks account verified |
| `resend_verification`|`storefront/auth/resend-verification.php`| `/resend_verification.php` | `/resend-verification` | 200 OK; rate-limited link sender |
| `contact` | `storefront/pages/contact.php` | `/contact.php` | `/contact` | 200 OK; anti-spam honeypot contact form |
| `newsletter` | `storefront/pages/newsletter.php` | `/newsletter.php` | `/newsletter` | 200 OK; double opt-in confirmation handler |
| `page` | `storefront/pages/view.php` | `/page.php?slug={s}` | `/page/{slug}` | 200 OK; CMS readable typography column |
| `sitemap` | `storefront/seo/sitemap.php` | `/sitemap.php` | `/sitemap.xml` | 200 OK; XML sitemap with dynamic products & pages |
| `maintenance` | `storefront/errors/maintenance.php` | `/maintenance.php` | `/maintenance` | 503 Service Unavailable when enabled |
| `404` | `storefront/errors/404.php` | `/404.php` | `/404` | 404 Not Found with helpful search & links |
| `500` | `storefront/errors/500.php` | `/500.php` | `/500` | 500 Internal Error sanitized view |

---

## 2. Customer Purchase Flows

### Flow A: Guest Checkout (COD)
1. Navigate to `/shop` and choose an active in-stock product.
2. If the product has options (Size/Color), select options until an active variant is active.
3. Click **Add to Cart** or **Buy Now**.
4. On `/cart`, observe free delivery progress bar. Click **Proceed to Checkout**.
5. Enter customer name, 11-digit BD phone number, street address.
6. Select **City / District** from the 64-district dropdown (e.g. `Dhaka` or `Chattogram`).
   - Expected: Shipping rate automatically updates between *Inside Dhaka* and *Outside Dhaka*.
7. Select **Cash on Delivery (COD)**.
8. Click **Place Order Now**.
   - Expected: Button immediately disables and displays spinner to prevent duplicate orders.
   - Redirects to `/order/confirmation?o=ORD-XXXX&t=YYYY` with order summary and exact cash amount to prepare.

### Flow B: Logged-in Customer with Saved Addresses
1. Sign in via `/login`.
2. Navigate to product and checkout.
3. Observe saved address cards populated from `customer_addresses`.
4. Switch between saved address radio cards.
   - Expected: Form inputs populate instantly; shipping rate auto-adjusts to the saved address district.
5. Submit order.
   - Expected: Order is immediately linked to customer ID in `orders` table.

---

## 3. Stock, Variants & Discount Validations

### Variant & Live Stock
- **Step**: Browse a product with 0 stock on one variant (e.g., Size M: 0, Size L: 5).
- **Expected**: Size M shows *Out of Stock* badge and cannot be selected/added; live availability text displays accurate count for Size L. Plain HTML POST to `/cart` verifies variant stock server-side.

### Coupons & Free Shipping Thresholds
- **Step**: Apply valid coupon code in cart (e.g., `SAVE10`).
- **Expected**: Discount appears in totals breakdown.
- **Step**: Reach `shipping_methods.free_over` threshold.
- **Expected**: Shipping fee becomes `0.00` / `FREE`, and the progress bar displays 100% green with *"You unlocked FREE Delivery!"*.

---

## 4. Reviews & Ratings Moderation Pipeline

1. **Submission**:
   - On any product page (`/product/{slug}`), scroll to the Customer Reviews section.
   - Click **Write a Review**. Select a star rating (1-5), enter title, comment, and submit.
   - Expected: AJAX submission validates CSRF, rate limits, and displays *"Thank you! Your review has been submitted for moderation."*
2. **Moderation Queue**:
   - Open `/admin/reviews.php`.
   - Expected: Pending review shows with badge indicator in sidebar.
   - Click **Approve**.
   - Expected: Product rating (`avg_rating`, `review_count`) is automatically recalculated in `products` table.
   - Refresh product page: Review appears publicly.

---

## 5. Newsletter & Contact Form Inboxes

### Double Opt-In Newsletter
1. Submit an email address via footer newsletter form.
2. Expected: Subscriber recorded in `newsletter_subscribers` with `status = 'pending'`, and double opt-in confirmation email dispatched.
3. Click verification link: `/newsletter?token=HASH`.
4. Expected: Status changes to `active`, confirmation message displays.
5. Verify in Admin: `/admin/subscribers.php` displays active subscriber with CSV export capability.

### Contact Us Form
1. Submit message at `/contact`.
2. Expected: Stored in `contact_messages` table.
3. Verify in Admin: `/admin/messages.php` reflects new message with red count badge in sidebar.
4. Mark as Replied: Message status changes to `replied`.

---

## 6. Accessibility & Visual Quality (WCAG AA)

- **Contrast Ratios**: Verified via `tests/tools/contrast_check.php` (all 14 token pairs pass minimum 4.5:1 ratio).
- **Keyboard Navigation**:
  - `Tab` navigates through header, search, filters, cards, and footer.
  - Active elements display explicit 2px focus ring (`:focus-visible`).
  - `Escape` key closes mini-cart drawer and modals.
- **Aesthetics & Performance**:
  - CSS bundle `assets/css/shop.css` is 31.85 KB minified (budget: <= 70 KB).
  - SVG icon sprite `assets/img/icons.svg` provides crisp vector rendering without external font overhead.
  - Dark mode persists across sessions via `data-theme="dark"` attribute on `<html>`.

---

## 7. Plain HTML / No-JS Fallback Verification

- When JavaScript is disabled in browser:
  1. Product filter sidebar submits via plain `<form method="get">`.
  2. Variant selection and **Add to Cart** executes via standard POST.
  3. Cart quantity updates and item removal submit via standard POST.
  4. Multi-step checkout processes atomically with server-side validation.
