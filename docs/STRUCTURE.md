# Repository Architecture & Directory Structure

```
/
├── index.php                  Home page (only PHP script in web root)
├── .htaccess                  Apache routing, 301 legacy redirects, clean URLs & security headers
├── .env.example               Environment configuration template
├── README.md                  Project documentation and setup guide
├── DEPLOY.md                  Production deployment instructions (Apache & Nginx)
├── LICENSE                    MIT license
├── shop/                      Storefront catalog, categories, search, and product details
│   ├── index.php              Catalog browsing, search filtering, and sorting
│   ├── category.php           Category-specific product listing
│   └── product.php            Product detail page, variants, stock availability & reviews
├── cart/                      Shopping cart, checkout process, and order confirmation
│   ├── index.php              Interactive shopping cart management
│   ├── checkout.php           Atomic Cash-on-Delivery & payment checkout
│   └── confirmation.php       Post-purchase order confirmation & receipt
├── auth/                      Customer authentication workflows
│   ├── login.php              Customer sign-in with rate limiting and remember-me
│   ├── register.php           Customer registration with password validation
│   ├── logout.php             Customer session termination
│   ├── forgot-password.php    Password reset initiation with anti-enumeration
│   ├── reset-password.php     Cryptographic token verification and password update
│   ├── verify-email.php       Email verification callback handler
│   └── resend-verification.php Unverified email reactivation request
├── account/                   Customer self-service account portal
│   ├── _layout.php            Account dashboard layout wrapper
│   ├── index.php              Account overview & summary metrics
│   ├── orders.php             Order history listing with status badges
│   ├── order.php              Individual order details with timeline & items
│   ├── invoice.php            Printable invoice view
│   ├── addresses.php          Saved shipping and billing address book
│   ├── profile.php            Profile details and avatar management
│   ├── security.php           Password changing and session termination
│   ├── wishlist.php           Customer saved wishlist items
│   ├── export.php             GDPR personal data export (JSON format)
│   └── delete.php             Account deletion & anonymization request
├── track/                     Public order tracking
│   └── index.php              Order tracking lookup and timeline visualization
├── pages/                     CMS-managed dynamic content and support pages
│   ├── view.php               Generic content page viewer (About, FAQ, Terms, etc.)
│   ├── contact.php            Customer contact inquiry form with anti-spam
│   └── newsletter.php         Newsletter subscription and double-opt-in confirmation
├── errors/                    Standard HTTP error documents
│   ├── 404.php                Page not found with shop search and recommendations
│   ├── 500.php                Internal server error page
│   └── maintenance.php        Storefront maintenance mode notice
├── seo/                       Search Engine Optimization assets
│   └── sitemap.php            Dynamic XML sitemap generator
├── api/                       Asynchronous AJAX endpoints
│   ├── availability.php       Real-time stock and variant availability polling
│   ├── cart_ajax.php          AJAX cart modifications and mini-cart payloads
│   ├── search_suggest.php     Live instant search query suggestions
│   └── wishlist.php           AJAX wishlist toggle operations
├── admin/                     Secure administrative control panel
├── includes/                  Core backend engine, libraries and view partials
│   ├── partials/              Reusable view components (header, footer, cards, drawers)
│   ├── data/                  Static datasets (Bangladesh 64 districts)
│   ├── catalog.php            Product and category catalog query library
│   ├── customer_auth.php      Customer session, token, and credential helpers
│   ├── functions.php          Global helpers, route() registry, and escaping
│   ├── inventory.php          Atomic stock adjustments and reservation logic
│   ├── mailer.php             Background email queueing and template rendering
│   ├── polyfills.php          PHP 8 string helper fallbacks
│   ├── shop_bootstrap.php     Storefront initialization and maintenance gating
│   └── traffic.php            Request timing, bandwidth, and performance profiling
├── assets/                    Static frontend web assets (CSS, JS, Fonts, Images)
├── config/                    Application configuration and database connection (web-denied)
├── cron/                      Background queue worker and maintenance scripts (web-denied)
├── storage/                   Local file locks, cache, and state records (web-denied)
├── sql/                       Database schema definitions (web-denied)
├── tests/                     Test suites, verification scripts, and QA matrix
├── tools/                     CLI developer utilities and link checkers
└── docs/                      Architectural documentation and component manuals
```
