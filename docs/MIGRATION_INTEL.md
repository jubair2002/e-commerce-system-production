# Comprehensive Migration Intelligence: PHP to Rust (Axum + SQLx + MySQL)

> **Repository Analysis & Migration Specification**
> Target Architecture: Rust (Axum web framework + SQLx async SQL driver + MySQL 8.0)
> Migration Constraints: Zero UI change, Zero client-side regression, No destructive DB schema changes.

---

## 1. Project profile

| Metric | Total Count | Details & Notes |
| :--- | :--- | :--- |
| **PHP Files** | **146** | 143 application files + 3 vendored in `includes/lib/PHPMailer/` (`PHPMailer.php`, `SMTP.php`, `Exception.php`) |
| **PHP LOC** | **51,352** | 44,585 application LOC + 6,767 in `includes/lib/PHPMailer/` |
| **Database Tables** | **42** | 39 in `sql/schema.sql`, 1 (`files`) in `migrations/001_storage_layer.up.sql`, 1 (`schema_migrations`) in `bin/migrate.php`, plus 1 runtime table (`product_sales_summary`) actively queried in code |
| **SQL Views** | **2** | `v_product_stock`, `v_sales_by_variant` (defined in `sql/schema.sql`) |
| **Routes** | **58** | 24 storefront routes, 10 account portal routes, 34 admin panel routes, 13 API/media endpoints |
| **Views / Templates** | **65** | 31 storefront & account views/partials + 34 admin views/form screens |
| **JavaScript Files** | **9** | 6 application scripts in `assets/js/` + 3 Cozastore scripts in `assets/coza/js/` (excluding vendor libraries) |
| **CSS Files** | **26** | 17 stylesheets in `assets/css/` (including 15 modular CSS components in `assets/css/shop/`) + 9 in `assets/coza/css/` |
| **API Endpoints** | **13** | 7 storefront JSON/SSE in `api/`, 4 admin AJAX in `admin/ajax/`, 1 Prometheus metrics (`metrics.php`), 1 dynamic media delivery (`uploads.php`) |
| **Admin Pages** | **34** | Page controllers in `admin/` covering dashboard, catalog, inventory, orders, customers, marketing, monitoring, and settings |
| **Cron Jobs** | **9** | Background CLI jobs in `cron/` (mail queue, metrics collector, access log parser, customer maintenance, analytics rollup, sales summary rebuilder, image cache purge, log cleanup, analytics aggregation) |

---

## 2. File tree

### Root Directory
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `.env` | Environment configuration secrets and keys | 36 | config |
| `.env.example` | Template for environment configuration variables | 73 | config |
| `.gitignore` | Git version control exclusion rules | 64 | config |
| `.htaccess` | Apache rewrite rules, caching, compression, headers | 177 | config |
| `DEPLOY.md` | Deployment guide for Windows and Linux | 175 | config |
| `Deployment.txt` | Bash setup script for Ubuntu server | 69 | config |
| `Dockerfile` | Container build recipe for Apache PHP | 70 | config |
| `LICENSE` | MIT software distribution license | 21 | config |
| `README.md` | Project overview, feature breakdown, setup instructions | 372 | config |
| `composer.json` | Composer dependencies and autoloader specification | 20 | config |
| `composer.lock` | Locked composer package dependencies | 1,216 | config |
| `docker-compose.yml` | Multi-container setup for app, MySQL, Prometheus, Grafana | 123 | config |
| `index.php` | Storefront homepage entry point and controller | 539 | route / view |
| `metrics.php` | Prometheus text-format metrics exporter endpoint | 132 | api |
| `robots.txt` | Search engine crawler access control directives | 6 | config |
| `router.php` | Built-in PHP development server request router | 104 | route |
| `storage_link.php` | CLI tool provisioning uploads directory symlink | 99 | config |
| `uploads.php` | Media streaming controller with HTTP caching | 172 | route / api |

### Account Module (`account/`)
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `account/_layout.php` | Shared authenticated account layout wrapper | 133 | view / include |
| `account/addresses.php` | Customer address book management and editor | 428 | route / view |
| `account/delete.php` | Account deletion with anonymization and verification | 178 | route / view |
| `account/export.php` | GDPR JSON customer data export download | 144 | route / api |
| `account/index.php` | Customer account dashboard overview | 270 | route / view |
| `account/invoice.php` | Printable HTML customer order invoice view | 239 | route / view |
| `account/order.php` | Customer detailed order tracking view | 404 | route / view |
| `account/orders.php` | Customer order history listing with pagination | 219 | route / view |
| `account/profile.php` | Customer profile information and avatar update | 263 | route / view |
| `account/security.php` | Customer password change and sessions view | 403 | route / view |
| `account/wishlist.php` | Customer saved wishlist items management | 205 | route / view |

### Admin Module (`admin/` & `admin/ajax/` & `admin/includes/`)
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `admin/activity.php` | System audit trail activity log viewer | 63 | route / view |
| `admin/analytics.php` | Analytics dashboard with revenue and traffic | 738 | route / view |
| `admin/analytics_product.php` | Single product deep-dive performance analytics | 481 | route / view |
| `admin/banner_form.php` | Promotional banner create and edit form | 290 | route / view |
| `admin/banners.php` | Storefront banner management and sorting | 158 | route / view |
| `admin/categories.php` | Product category tree listing and deletion | 142 | route / view |
| `admin/category_form.php` | Category create and edit form | 116 | route / view |
| `admin/customer_view.php` | Detailed customer profile, addresses, and orders | 881 | route / view |
| `admin/customers.php` | Customer accounts listing, filter, and export | 888 | route / view |
| `admin/dashboard.php` | Admin executive KPI summary dashboard | 1,016 | route / view |
| `admin/index.php` | Admin root redirecting to dashboard.php | 3 | route |
| `admin/install.php` | Initial database setup and admin seeder | 145 | route / view |
| `admin/inventory.php` | Inventory movement ledger and stock adjustment | 718 | route / view |
| `admin/login.php` | Administrator authentication login screen | 172 | route / view |
| `admin/logout.php` | Administrator session termination and redirection | 6 | route |
| `admin/mail_queue.php` | Outgoing email queue status and retry | 292 | route / view |
| `admin/mail_settings.php` | SMTP mail server credentials and test | 472 | route / view |
| `admin/mail_templates.php` | Email HTML templates editor and preview | 433 | route / view |
| `admin/messages.php` | Contact form inbox messages viewer | 237 | route / view |
| `admin/monitoring.php` | Real-time server and database resource monitor | 1,162 | route / view |
| `admin/order_print.php` | Packing slip and printable order receipt | 274 | route / view |
| `admin/order_view.php` | Order details, line items, status updater | 474 | route / view |
| `admin/orders.php` | Orders listing, status filter, CSV export | 534 | route / view |
| `admin/page_form.php` | CMS static page editor and metadata | 245 | route / view |
| `admin/pages.php` | CMS pages listing and status toggle | 143 | route / view |
| `admin/product_form.php` | Product editor with variants and gallery | 1,054 | route / view |
| `admin/product_import.php` | Bulk CSV product catalog import wizard | 1,630 | route / view |
| `admin/products.php` | Product inventory catalog manager and search | 632 | route / view |
| `admin/profile.php` | Admin user profile and password updater | 212 | route / view |
| `admin/reviews.php` | Product reviews moderation, approve, and reject | 352 | route / view |
| `admin/settings.php` | Global store settings and configuration tabs | 2,349 | route / view |
| `admin/subscribers.php` | Newsletter subscribers list and CSV export | 215 | route / view |
| `admin/user_form.php` | Admin user accounts create and edit | 882 | route / view |
| `admin/users.php` | Staff users management and permissions view | 433 | route / view |
| `admin/ajax/analytics_data.php` | JSON data endpoint for analytics charts | 105 | api |
| `admin/ajax/monitor_data.php` | JSON telemetry endpoint for system monitor | 439 | api |
| `admin/ajax/option_presets.php` | JSON endpoint for variant option presets | 141 | api |
| `admin/ajax/stock_update.php` | Inline quick stock updater endpoint | 118 | api |
| `admin/includes/auth.php` | Admin authentication helpers and permission guards | 142 | include |
| `admin/includes/footer.php` | Admin panel common layout footer markup | 16 | view / include |
| `admin/includes/header.php` | Admin panel navigation bar and alerts | 185 | view / include |
| `admin/includes/sidebar.php` | Admin panel sidebar navigation menu markup | 208 | view / include |

### Storefront Module (`storefront/`)
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `storefront/auth/forgot-password.php` | Customer password reset request form | 100 | route / view |
| `storefront/auth/login.php` | Customer sign-in page with rate limiting | 153 | route / view |
| `storefront/auth/logout.php` | Customer sign-out and session destruction | 54 | route |
| `storefront/auth/register.php` | Customer registration with honeypot and trap | 242 | route / view |
| `storefront/auth/resend-verification.php` | Resend email verification link request form | 108 | route / view |
| `storefront/auth/reset-password.php` | Customer new password entry form | 143 | route / view |
| `storefront/auth/verify-email.php` | Customer token verification endpoint | 50 | route |
| `storefront/cart/checkout.php` | Multi-step checkout, coupon, order creation | 1,071 | route / view |
| `storefront/cart/confirmation.php` | Post-checkout thank you and order summary | 437 | route / view |
| `storefront/cart/index.php` | Shopping cart review and quantity updater | 660 | route / view |
| `storefront/errors/404.php` | Storefront HTTP 404 page not found | 36 | view |
| `storefront/errors/500.php` | Storefront HTTP 500 server error view | 40 | view |
| `storefront/errors/maintenance.php` | Storefront maintenance mode landing screen | 156 | view |
| `storefront/pages/contact.php` | Contact form submission with spam protection | 257 | route / view |
| `storefront/pages/newsletter.php` | Newsletter subscribe, confirm, and unsubscribe | 162 | route / view |
| `storefront/pages/view.php` | Dynamic CMS page reader by slug | 67 | route / view |
| `storefront/seo/sitemap.php` | Dynamic XML sitemap for search engines | 72 | route / api |
| `storefront/shop/category.php` | Category product listing with facet filters | 368 | route / view |
| `storefront/shop/index.php` | Product catalog grid with sorting controls | 419 | route / view |
| `storefront/shop/product.php` | Product detail page with variants and reviews | 1,033 | route / view |
| `storefront/track/index.php` | Public order tracking lookup by number | 368 | route / view |

### Public APIs (`api/`)
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `api/availability.php` | Stock availability API with 304 caching | 302 | api |
| `api/availability_stream.php` | Real-time availability SSE event stream endpoint | 117 | api |
| `api/cart_ajax.php` | Mini-cart drawer AJAX actions endpoint | 192 | api |
| `api/newsletter.php` | Newsletter subscription double opt-in API | 115 | api |
| `api/review.php` | Customer product review submission API | 189 | api |
| `api/search_suggest.php` | Live search autocomplete suggestions JSON endpoint | 141 | api |
| `api/wishlist.php` | Customer wishlist toggle and fetch API | 131 | api |

### Configuration (`config/`)
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `config/.htaccess` | Direct web access denial for config | 9 | config |
| `config/config.php` | Central configuration, constants, session init | 216 | config / include |
| `config/database.php` | PDO database singleton with connection profiling | 153 | config / include |
| `config/env.php` | Zero-dependency .env environment parser and loader | 74 | config / include |

### Core Includes (`includes/`)
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `includes/.htaccess` | Deny direct browser execution of includes | 7 | config |
| `includes/FileCache.php` | File-based cache driver with TTL support | 287 | include |
| `includes/SqlRunner.php` | Multi-statement SQL migration runner utility | 302 | include |
| `includes/analytics.php` | Visitor tracking, event logging, and queries | 1,114 | include |
| `includes/catalog.php` | Product data retrieval, variants, sales recording | 515 | include |
| `includes/crypto.php` | AES-256-GCM symmetric encryption for secrets | 35 | include |
| `includes/customer_auth.php` | Customer registration, login, hashing, tokens, rate-limits | 1,172 | include |
| `includes/functions.php` | Core global helpers, formatting, CSRF, settings | 1,136 | include |
| `includes/hooks.php` | Synchronous event hook registration and dispatcher | 65 | include |
| `includes/images.php` | Responsive image resizing pipeline via GD | 249 | include |
| `includes/inventory.php` | Atomic inventory movements and stock restoration | 294 | include |
| `includes/mailer.php` | Email queue insertion, SMTP sender, templates | 657 | include |
| `includes/permissions.php` | Admin roles and permission matrix checks | 392 | include |
| `includes/polyfills.php` | String polyfills for legacy PHP compatibility | 28 | include |
| `includes/shop_bootstrap.php` | Storefront bootloader, cart session management, totals | 812 | include |
| `includes/traffic.php` | Request telemetry, duration recording, fast shutdown | 215 | include |
| `includes/data/bd_districts.php` | 64 Bangladesh districts and shipping zones | 70 | include |
| `includes/layout/coza/footer.php` | Cozastore theme global footer markup | 243 | view / include |
| `includes/layout/coza/head.php` | HTML document head and CSS stylesheets | 99 | view / include |
| `includes/layout/coza/header.php` | Cozastore responsive navbar and desktop header | 233 | view / include |
| `includes/layout/coza/mini_cart.php` | Mini-cart slide-out drawer template markup | 25 | view / include |
| `includes/layout/coza/scripts.php` | Theme JS script includes and initialization | 323 | view / include |
| `includes/lib/PHPMailer/Exception.php` | PHPMailer exception error class | 18 | include |
| `includes/lib/PHPMailer/PHPMailer.php` | Complete SMTP mail delivery client library | 5,252 | include |
| `includes/lib/PHPMailer/README.md` | PHPMailer library documentation | 4 | config |
| `includes/lib/PHPMailer/SMTP.php` | Low-level RFC-compliant SMTP transport implementation | 1,497 | include |
| `includes/partials/breadcrumb.php` | Breadcrumb navigation partial component | 36 | view / include |
| `includes/partials/empty_state.php` | Empty state placeholder component | 34 | view / include |
| `includes/partials/footer.legacy.php` | Legacy storefront footer partial component | 210 | view / include |
| `includes/partials/footer.php` | Storefront footer wrapper delegating to Coza | 13 | view / include |
| `includes/partials/header.legacy.php` | Legacy storefront header partial component | 298 | view / include |
| `includes/partials/header.php` | Storefront header wrapper delegating to Coza | 11 | view / include |
| `includes/partials/mini_cart.php` | Storefront mini-cart drawer partial wrapper | 81 | view / include |
| `includes/partials/pagination.php` | Pagination markup partial renderer | 51 | view / include |
| `includes/partials/product_card.legacy.php` | Legacy product grid card component | 154 | view / include |
| `includes/partials/product_card.php` | Product grid card component (Cozastore skin) | 106 | view / include |
| `includes/partials/review_stars.php` | Star rating display partial component | 29 | view / include |

### Storage Layer Architecture (`src/Storage/`)
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `src/Storage/ImageProcessor.php` | GD image manipulation, cropping, WebP conversion | 232 | include |
| `src/Storage/LocalStorage.php` | Local filesystem storage driver implementation | 178 | include |
| `src/Storage/S3Storage.php` | AWS S3 storage driver with fallback | 291 | include |
| `src/Storage/StorageConfig.php` | Storage driver configuration loader and validator | 138 | config / include |
| `src/Storage/StorageInterface.php` | Common filesystem contract for media storage | 84 | include |
| `src/Storage/StorageManager.php` | High-level media storage manager and coordinator | 519 | include |

### Background Cron Tasks (`cron/`)
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `cron/.htaccess` | Deny web execution of cron scripts | 1 | config |
| `cron/aggregate_analytics.php` | Hourly analytics rollup into daily stats | 151 | cron |
| `cron/analytics_rollup.php` | Daily KPI analytics summary calculation | 49 | cron |
| `cron/cleanup_logs.php` | Purge telemetry and metric logs by retention | 103 | cron |
| `cron/collect_metrics.php` | 1-minute server, CPU, memory, DB telemetry | 743 | cron |
| `cron/customer_maintenance.php` | Purge expired customer tokens and unverified accounts | 106 | cron |
| `cron/parse_access_log.php` | Parse web server access log into traffic_minute | 155 | cron |
| `cron/process_mail_queue.php` | Batch email queue dispatcher with SKIP LOCKED | 128 | cron |
| `cron/purge_image_cache.php` | Delete temporary responsive image cache files | 14 | cron |
| `cron/rebuild_sales_summary.php` | Incremental sales summary rebuilder for products | 98 | cron |

### CLI Tools and Maintenance (`bin/`)
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `bin/cleanup-orphans.php` | Detect and purge orphaned unreferenced file assets | 109 | cron / config |
| `bin/migrate-files-to-s3.php` | Batch migrator copying local files to S3 | 337 | cron / config |
| `bin/migrate.php` | Schema migration CLI runner (up/down/status) | 131 | config |
| `bin/setup-ssl.sh` | Let's Encrypt SSL automated installation script | 32 | config |
| `bin/sync-storage.php` | Sync pending fallback media files to primary | 163 | cron / config |

### Database Schemas and Migrations (`sql/` & `migrations/`)
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `sql/.htaccess` | Deny web access to schema definitions | 7 | config |
| `sql/schema.sql` | Baseline MySQL database schema and initial seeds | 1,259 | sql |
| `migrations/001_storage_layer.up.sql` | Migration creating files table and foreign keys | 66 | sql |
| `migrations/001_storage_layer.down.sql` | Rollback dropping files table and foreign keys | 33 | sql |
| `migrations/002_add_meta_keywords_to_products.up.sql` | Add meta_keywords column to products | 1 | sql |
| `migrations/002_add_meta_keywords_to_products.down.sql` | Remove meta_keywords column from products | 1 | sql |
| `migrations/003_add_user_permissions.up.sql` | Add JSON permissions column to users table | 9 | sql |
| `migrations/003_add_user_permissions.down.sql` | Rollback permissions column from users table | 5 | sql |

### Docker, Containerization & Infrastructure (`docker/`)
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `docker/apache.conf` | Apache VirtualHost configuration for container | 74 | config |
| `docker/entrypoint.sh` | Container startup script configuring storage and cron | 95 | config |
| `docker/php.ini` | Production PHP runtime configuration overrides | 16 | config |
| `docker/cron/crontab` | Container cron task schedule definition | 18 | config |
| `docker/grafana/provisioning/dashboards/dashboards.yml` | Grafana dashboard provisioning specification | 13 | config |
| `docker/grafana/provisioning/dashboards/definitions/ecommerce_overview.json` | Grafana e-commerce operational dashboard definition | 273 | config |
| `docker/grafana/provisioning/dashboards/definitions/infrastructure_mysql.json` | Grafana server and MySQL monitoring dashboard | 190 | config |
| `docker/grafana/provisioning/datasources/prometheus.yml` | Grafana Prometheus datasource configuration | 10 | config |
| `docker/prometheus/prometheus.yml` | Prometheus scrape targets and intervals | 35 | config |

### Test Suites (`tests/`)
| Path | Purpose (max 8 words) | LOC | Category |
| :--- | :--- | :--- | :--- |
| `tests/test_product_import_suite.php` | Integration test suite for CSV catalog import | 369 | include |
| `tests/test_rbac_suite.php` | Integration test suite for RBAC permission checks | 143 | include |
| `tests/test_storage_suite.php` | Integration test suite for Local and S3 drivers | 301 | include |

### Static Assets Summary (`assets/`)
*Summarized by folder in accordance with task instructions:*
| Folder Path | File Count | Code LOC | Contained File Types & Summary |
| :--- | :--- | :--- | :--- |
| `assets/coza/` | 79 | 22,374 | Cozastore skin theme assets: HTML templates, images, web fonts (`.woff`, `.woff2`, `.ttf`, `.eot`), CSS stylesheets (`main.css`, `util.css`, `pdp-modern.css`), JS vendor libraries (`main.js`, `slick-custom.js`, `map-custom.js`) |
| `assets/css/` | 17 | 10,272 | Core styling: `style.css` (admin & shared), `shop.css` (combined storefront), plus 15 modular CSS components in `assets/css/shop/` (`00-tokens.css`, `01-base.css`, `02-layout.css`, `03-utilities.css`, `alert.css`, `badge.css`, `button.css`, `card.css`, `drawer.css`, `dropdown.css`, `form.css`, `stepper.css`, `table.css`, `tabs.css`, `pages.css`) |
| `assets/images/` | 1 | 0 | Binary visual assets: administrator login background image (`admin-login-bg.jpg`) |
| `assets/img/` | 1 | 144 | Vector graphics: SVG icon sprite sheet (`icons.svg`) used across admin and storefront |
| `assets/js/` | 6 | 2,480 | First-party client-side logic: `admin-variants.js` (variant matrix editor), `auth.js` (password validation), `availability.js` (live stock polling & SSE), `script.js` (admin UX), `shop.js` (mini-cart, search, wishlist), `shop/ui.js` (storefront UI components) |
| `assets/templates/` | 1 | 5 | Sample spreadsheet data: `product_import_template.csv` for CSV product imports |
| `assets/uploads/` | 8 | 0 | Default local media storage root: test images, banners, and product placeholders |
| `assets/vendor/` | 11 | 66 | Vendored third-party UI libraries: Bootstrap 5 bundle, Bootstrap Icons font, Chart.js bundle |

---
## 3. Route map

### Complete Application Route Table
| Clean Pretty URL | Legacy `.php` URL | HTTP Methods | Handler Script | View / Template File | Required Auth | Notes & Purpose |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `/` | `index.php` | GET | `index.php` | `index.php` + layout partials | Guest | Storefront homepage; hero, categories, new arrivals, best sellers |
| `/shop` | `storefront/shop/index.php` (or `shop.php`) | GET | `storefront/shop/index.php` | `storefront/shop/index.php` | Guest | Catalog grid; facets, price filter, sorting, circular pagination |
| `/category/{slug}` | `storefront/shop/category.php?slug={slug}` (or `category.php?slug=...`) | GET | `storefront/shop/category.php` | `storefront/shop/category.php` | Guest | Filtered catalog by category slug; SEO metadata |
| `/product/{slug}` | `storefront/shop/product.php?slug={slug}` (or `product.php?slug=...`) | GET | `storefront/shop/product.php` | `storefront/shop/product.php` | Guest | Product detail page; variant selector, image gallery, reviews, JSON-LD |
| `/cart` | `storefront/cart/index.php` (or `cart.php`) | GET, POST | `storefront/cart/index.php` | `storefront/cart/index.php` | Guest | Cart review; quantity updater, coupon code apply/remove, shipping estimator |
| `/checkout` | `storefront/cart/checkout.php` (or `checkout.php`) | GET, POST | `storefront/cart/checkout.php` | `storefront/cart/checkout.php` | Guest / Customer | Single/multi-step checkout; 64 district shipping, COD/bank, order placement |
| `/order/confirmation` | `storefront/cart/confirmation.php` (or `order_confirmation.php`) | GET, POST | `storefront/cart/confirmation.php` | `storefront/cart/confirmation.php` | Guest / Customer | Post-order thank you screen; order summary, guest account creation prompt |
| `/track` | `storefront/track/index.php` (or `track.php`) | GET, POST | `storefront/track/index.php` | `storefront/track/index.php` | Guest | Public order tracking lookup by order number and phone/email |
| `/login` | `storefront/auth/login.php` (or `login.php`) | GET, POST | `storefront/auth/login.php` | `storefront/auth/login.php` | Guest | Customer login; brute-force rate limiter, lockout defense, remember-me |
| `/register` | `storefront/auth/register.php` (or `register.php`) | GET, POST | `storefront/auth/register.php` | `storefront/auth/register.php` | Guest | Customer registration; spam honeypot, password strength validator |
| `/logout` | `storefront/auth/logout.php` (or `logout.php`) | GET, POST | `storefront/auth/logout.php` | Redirect to `/login` | Customer | Clears customer session and remember-me token/cookie |
| `/forgot-password` | `storefront/auth/forgot-password.php` (or `forgot_password.php`) | GET, POST | `storefront/auth/forgot-password.php` | `storefront/auth/forgot-password.php` | Guest | Password reset request; generates single-use 60-minute token |
| `/reset-password` | `storefront/auth/reset-password.php` (or `reset_password.php`) | GET, POST | `storefront/auth/reset-password.php` | `storefront/auth/reset-password.php` | Guest | Password reset submission using selector.secret token |
| `/verify-email` | `storefront/auth/verify-email.php` (or `verify_email.php`) | GET | `storefront/auth/verify-email.php` | Redirect to `/account` | Guest / Customer | Validates email confirmation token and marks customer verified |
| `/resend-verification` | `storefront/auth/resend-verification.php` (or `resend_verification.php`) | GET, POST | `storefront/auth/resend-verification.php` | `storefront/auth/resend-verification.php` | Guest / Customer | Resends email verification link to unverified customer |
| `/page/{slug}` | `storefront/pages/view.php?slug={slug}` | GET | `storefront/pages/view.php` | `storefront/pages/view.php` | Guest | Dynamic CMS content page reader (About, Terms, Privacy) |
| `/contact` | `storefront/pages/contact.php` | GET, POST | `storefront/pages/contact.php` | `storefront/pages/contact.php` | Guest | Contact form submission with spam protection, stores in contact_messages |
| `/newsletter` | `storefront/pages/newsletter.php` | GET, POST | `storefront/pages/newsletter.php` | `storefront/pages/newsletter.php` | Guest | Newsletter subscribe, double opt-in confirmation, and unsubscribe |
| `/sitemap.xml` | `storefront/seo/sitemap.php` (or `sitemap.php`) | GET | `storefront/seo/sitemap.php` | XML output | Guest | Dynamic XML sitemap listing home, shop, categories, and products |
| `/account` | `account/index.php` | GET | `account/index.php` | `account/_layout.php` + `account/index.php` | Customer | Customer account dashboard overview; recent orders, default address |
| `/account/orders` | `account/orders.php` | GET | `account/orders.php` | `account/_layout.php` + `account/orders.php` | Customer | Paginated order history list with status badges |
| `/account/order` | `account/order.php` | GET | `account/order.php` | `account/_layout.php` + `account/order.php` | Customer | Detailed order view, line items, itemized totals, cancellation option |
| `/account/invoice` | `account/invoice.php` | GET | `account/invoice.php` | `account/invoice.php` | Customer | Printable HTML invoice with print stylesheet |
| `/account/addresses` | `account/addresses.php` | GET, POST | `account/addresses.php` | `account/_layout.php` + `account/addresses.php` | Customer | Customer address book CRUD; maximum 10 addresses, default toggle |
| `/account/profile` | `account/profile.php` | GET, POST | `account/profile.php` | `account/_layout.php` + `account/profile.php` | Customer | Personal details editor, avatar upload via StorageManager |
| `/account/security` | `account/security.php` | GET, POST | `account/security.php` | `account/_layout.php` + `account/security.php` | Customer | Password change and active session revocation |
| `/account/wishlist` | `account/wishlist.php` | GET, POST | `account/wishlist.php` | `account/_layout.php` + `account/wishlist.php` | Customer | Customer saved wishlist items management |
| `/account/export` | `account/export.php` | GET, POST | `account/export.php` | JSON download stream | Customer | GDPR personal data download (profile, addresses, orders, wishlist) |
| `/account/delete` | `account/delete.php` | GET, POST | `account/delete.php` | `account/_layout.php` + `account/delete.php` | Customer | Account self-deletion with email confirmation and anonymization |
| `/admin` (or `/admin/`) | `admin/index.php` | GET | `admin/index.php` | Redirect to `dashboard.php` | Admin | Administrator redirect entry point |
| `/admin/dashboard.php` | - | GET | `admin/dashboard.php` | `admin/dashboard.php` | Admin | Executive summary, orders graph, low stock alerts, quick links |
| `/admin/login.php` | - | GET, POST | `admin/login.php` | `admin/login.php` | Guest | Admin panel login screen |
| `/admin/logout.php` | - | GET, POST | `admin/logout.php` | Redirect to `login.php` | Admin | Admin session destruction |
| `/admin/products.php` | - | GET, POST | `admin/products.php` | `admin/products.php` | Admin (`products.view`) | Product catalog table, filtering, bulk status toggle, bulk delete |
| `/admin/product_form.php` | - | GET, POST | `admin/product_form.php` | `admin/product_form.php` | Admin (`products.create`/`edit`) | Product creation/editor, variant generator, gallery upload |
| `/admin/product_import.php` | - | GET, POST | `admin/product_import.php` | `admin/product_import.php` | Admin (`products.import`) | CSV bulk product catalog import wizard |
| `/admin/categories.php` | - | GET, POST | `admin/categories.php` | `admin/categories.php` | Admin (`categories.view`) | Categories list, product count, status toggle, delete |
| `/admin/category_form.php` | - | GET, POST | `admin/category_form.php` | `admin/category_form.php` | Admin (`categories.manage`) | Category creation and editor form |
| `/admin/inventory.php` | - | GET, POST | `admin/inventory.php` | `admin/inventory.php` | Admin (`inventory.view`) | Stock ledger history, quick stock adjustment, export |
| `/admin/orders.php` | - | GET, POST | `admin/orders.php` | `admin/orders.php` | Admin (`orders.view`) | Orders listing, status filtering, bulk status change, CSV export |
| `/admin/order_view.php` | - | GET, POST | `admin/order_view.php` | `admin/order_view.php` | Admin (`orders.view`) | Order detail, line items, status transition machine, admin notes |
| `/admin/order_print.php` | - | GET | `admin/order_print.php` | `admin/order_print.php` | Admin (`orders.view`) | Printable packing slip and customer invoice |
| `/admin/customers.php` | - | GET, POST | `admin/customers.php` | `admin/customers.php` | Admin (`customers.view`) | Customers table, search, block/activate, CSV export |
| `/admin/customer_view.php` | - | GET, POST | `admin/customer_view.php` | `admin/customer_view.php` | Admin (`customers.view`) | Customer profile, addresses, order history, activity log |
| `/admin/reviews.php` | - | GET, POST | `admin/reviews.php` | `admin/reviews.php` | Admin (`reviews.manage`) | Review moderation; approve, reject, delete |
| `/admin/messages.php` | - | GET, POST | `admin/messages.php` | `admin/messages.php` | Admin (`messages.view`) | Contact form inbox messages, mark read, delete |
| `/admin/subscribers.php` | - | GET, POST | `admin/subscribers.php` | `admin/subscribers.php` | Admin (`subscribers.view`) | Newsletter subscribers directory and CSV export |
| `/admin/banners.php` | - | GET, POST | `admin/banners.php` | `admin/banners.php` | Admin (`settings.manage`) | Storefront promotional banners manager |
| `/admin/banner_form.php` | - | GET, POST | `admin/banner_form.php` | `admin/banner_form.php` | Admin (`settings.manage`) | Banner create and edit form |
| `/admin/pages.php` | - | GET, POST | `admin/pages.php` | `admin/pages.php` | Admin (`pages.view`) | Static CMS pages manager and status toggle |
| `/admin/page_form.php` | - | GET, POST | `admin/page_form.php` | `admin/page_form.php` | Admin (`pages.manage`) | CMS page create and edit form |
| `/admin/users.php` | - | GET, POST | `admin/users.php` | `admin/users.php` | Admin (`users.view`) | Staff administrative users manager |
| `/admin/user_form.php` | - | GET, POST | `admin/user_form.php` | `admin/user_form.php` | Admin (`users.manage`) | Add/Edit staff user, role selection, permission matrix |
| `/admin/profile.php` | - | GET, POST | `admin/profile.php` | `admin/profile.php` | Admin | Logged-in admin user profile and password updater |
| `/admin/activity.php` | - | GET | `admin/activity.php` | `admin/activity.php` | Admin (`activity.view`) | Audit trail log viewer with action filters |
| `/admin/analytics.php` | - | GET | `admin/analytics.php` | `admin/analytics.php` | Admin (`analytics.view`) | Analytics dashboard with funnels, revenue, top products |
| `/admin/analytics_product.php` | - | GET | `admin/analytics_product.php` | `admin/analytics_product.php` | Admin (`analytics.view`) | Single product performance analytics and variant breakdown |
| `/admin/monitoring.php` | - | GET | `admin/monitoring.php` | `admin/monitoring.php` | Admin (`monitoring.view`) | System health, CPU, memory, traffic, alert rules |
| `/admin/settings.php` | - | GET, POST | `admin/settings.php` | `admin/settings.php` | Admin (`settings.manage`) | Global configuration tabs (General, Store, Checkout, SEO, Storage) |
| `/admin/mail_settings.php` | - | GET, POST | `admin/mail_settings.php` | `admin/mail_settings.php` | Admin (`mail.settings`) | SMTP configuration and live test email dispatcher |
| `/admin/mail_templates.php` | - | GET, POST | `admin/mail_templates.php` | `admin/mail_templates.php` | Admin (`mail.templates`) | Mail templates HTML editor and placeholder manager |
| `/admin/mail_queue.php` | - | GET, POST | `admin/mail_queue.php` | `admin/mail_queue.php` | Admin (`mail.queue`) | Outbox email queue monitor, retry failed items |
| `/admin/install.php` | - | GET, POST | `admin/install.php` | `admin/install.php` | Guest / Uninstalled | Initial system installer creating schema and admin account |
| `/api/availability.php` | - | GET | `api/availability.php` | JSON output | Guest | Real-time stock status endpoint with ETag/304 caching |
| `/api/availability_stream.php` | - | GET | `api/availability_stream.php` | SSE event stream | Guest | Server-Sent Events real-time stock stream |
| `/api/cart_ajax.php` | - | GET, POST | `api/cart_ajax.php` | JSON output | Guest | Mini-cart drawer actions (`get`, `add`, `update`, `remove`) |
| `/api/newsletter.php` | - | POST | `api/newsletter.php` | JSON output | Guest | Newsletter subscription and double opt-in verification |
| `/api/review.php` | - | POST | `api/review.php` | JSON output | Customer / Guest | Product review submission endpoint |
| `/api/search_suggest.php` | - | GET | `api/search_suggest.php` | JSON output | Guest | Live search autocomplete suggestions JSON endpoint |
| `/api/wishlist.php` | - | GET, POST | `api/wishlist.php` | JSON output | Customer | Wishlist item toggle and saved IDs fetch endpoint |
| `/admin/ajax/analytics_data.php` | - | GET | `admin/ajax/analytics_data.php` | JSON output | Admin (`admin`/`editor`) | JSON dataset provider for analytics charts and funnels |
| `/admin/ajax/monitor_data.php` | - | GET | `admin/ajax/monitor_data.php` | JSON / CSV output | Admin (`monitoring.view`) | Telemetry feed for real-time monitoring charts |
| `/admin/ajax/option_presets.php` | - | GET, POST | `admin/ajax/option_presets.php` | JSON output | Admin (`products.edit`) | CRUD for variant option presets (Size, Color, etc.) |
| `/admin/ajax/stock_update.php` | - | GET, POST | `admin/ajax/stock_update.php` | JSON output | Admin (`inventory.manage`) | Quick stock updater modal backend |
| `/metrics` (or `metrics.php`) | - | GET | `metrics.php` | Plaintext Prometheus | Guest / Token | Prometheus telemetry metrics exporter |
| `/uploads/{file}` | `uploads.php?file={file}` | GET | `uploads.php` | Binary image/media | Guest | Dynamic media delivery controller with ETag/304 caching |

### URL Rewriting & Redirection Rules Summary
The application implements two parallel routing layers:
1. **Apache (`.htaccess`)**:
   - **Environment Protection**: Direct access to `.env` and dotfiles is forbidden (`Deny from all`).
   - **Directory Listing**: Disabled globally (`Options -Indexes`).
   - **Default Document**: `index.php`.
   - **Media Delivery Rewrite**: Requests to `(?:assets/)?uploads/(.+)` that do not exist as static files on disk are rewritten to `uploads.php?file=$1`.
   - **301 Permanent Redirects**: Legacy flat `.php` URLs (`shop.php`, `product.php`, `cart.php`, `checkout.php`, `login.php`, etc.) redirect with `301 Moved Permanently` to their respective clean paths or nested script targets, preserving query parameters (`QSA`).
   - **Clean Pretty URLs**: Patterns `/shop`, `/category/{slug}`, `/product/{slug}`, `/cart`, `/checkout`, `/order/confirmation`, `/track`, `/login`, `/register`, `/logout`, `/forgot-password`, `/reset-password`, `/verify-email`, `/resend-verification`, `/page/{slug}`, `/contact`, `/newsletter`, and `sitemap.xml` are rewritten internally to their respective handler scripts.
   - **Subfolder Legacy Fallback**: Requests starting with `/shop`, `/cart`, `/auth`, `/track`, `/pages`, `/errors`, `/seo` fallback internally to `/storefront/...`.
   - **HTTP Headers**: Enforces `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, and sets 1-year immutable caching for static assets (`.css`, `.js`, `.woff2`, `.png`, `.webp`, `.svg`).

2. **PHP Built-in Server Router (`router.php`)**:
   - Mirrors Apache rules for local development. Serves existing non-directory static files directly.
   - Extracts upload files matching `(?:assets/)?uploads/(.+)` and requires `uploads.php`.
   - Maps clean route URLs using `$rewrites` associative array.
   - Decodes regex capture groups for `/category/{slug}`, `/product/{slug}`, and `/page/{slug}`, placing the slug into `$_GET['slug']`.
   - Serves `storefront/errors/404.php` with HTTP status 404 for unmapped requests.

### Include-Only Files (Deny Direct Access)
These files do not contain top-level route dispatching and must only be included by controllers:
- `account/_layout.php`: Master wrapper for account portal.
- `config/config.php`, `config/database.php`, `config/env.php`: Bootstrap and configuration.
- `includes/FileCache.php`, `includes/SqlRunner.php`, `includes/analytics.php`, `includes/catalog.php`, `includes/crypto.php`, `includes/customer_auth.php`, `includes/functions.php`, `includes/hooks.php`, `includes/images.php`, `includes/inventory.php`, `includes/mailer.php`, `includes/permissions.php`, `includes/polyfills.php`, `includes/shop_bootstrap.php`, `includes/traffic.php`.
- `includes/data/bd_districts.php`: Static array dataset.
- `includes/layout/coza/*`: Head, header, footer, mini_cart, scripts partials.
- `includes/partials/*`: Breadcrumb, empty_state, footer, header, mini_cart, pagination, product_card, review_stars.
- `admin/includes/auth.php`, `admin/includes/header.php`, `admin/includes/sidebar.php`, `admin/includes/footer.php`: Admin layout partials.
- `src/Storage/*`: StorageManager and driver classes.
- Protected by `.htaccess` files inside `config/`, `includes/`, `cron/`, `sql/`, and `storage/`.

---

## 4. Bootstrap and config

### Include Load Order
The application follows a strict hierarchical include chain:
```
Request
  │
  ├── 1. config/env.php (Loads .env into $_ENV, $_SERVER, and getenv())
  │
  ├── 2. config/config.php (Loads env.php, composer autoloader, constants, session_start, timezone)
  │
  ├── 3. config/database.php (Initializes PDO / DebugPDO connection with connection-level timezone offset)
  │
  ├── 4. includes/functions.php (Global helpers, escaping, routing, CSRF, flash, settings loader, money formatting)
  │
  ├── 5. includes/shop_bootstrap.php (Storefront entry; loads traffic.php, catalog.php, customer_auth.php, cart session)
  │
  └── 6. Controller execution (Admin controllers require admin/includes/auth.php; Storefront controllers load layout/coza)
```

### What Runs on Load
1. **Environment Parser (`config/env.php`)**:
   - Custom zero-dependency parser parses `.env` line by line.
   - Trims quotes (`"` or `'`), strips trailing comments (` #`), populates `$_ENV`, `$_SERVER`, and calls `putenv()`.
   - Auto-loads `/etc/environment` if present (Linux production).
2. **Error Handling (`config/config.php:175`)**:
   - If `APP_ENV === 'development'`: `error_reporting(E_ALL)`, `ini_set('display_errors', '1')`.
   - If `APP_ENV === 'production'`: `error_reporting(0)`, `ini_set('display_errors', '0')`.
3. **Session Initialization (`config/config.php:184`)**:
   - If `!empty($_GET['test_sess'])`: Sets `session_id($_GET['test_sess'])` (Testing hook).
   - Configures cookie params: `lifetime = 0`, `path = '/'`, `httponly = true`, `samesite = 'Lax'`.
   - Calls `session_start()`.
4. **Timezone Configuration (`config/config.php:198`)**:
   - Defaults to `'Asia/Dhaka'`.
   - Reads `setting('timezone', 'Asia/Dhaka')` from the database if `setting()` is available.
   - Calls `date_default_timezone_set($appTimezone)`.
5. **Database Connection (`config/database.php:98`)**:
   - Lazy singleton via `Database::getConnection()` or `db()`.
   - DSN: `mysql:host={DB_HOST};port={DB_PORT};dbname={DB_NAME};charset={DB_CHARSET}`.
   - Attributes: `ATTR_ERRMODE => ERRMODE_EXCEPTION`, `ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC`, `ATTR_EMULATE_PREPARES => false`.
   - Connection Command: `SET time_zone = '{offset}'` executed immediately on connection initialization to match PHP timezone offset.
   - Profiling wrapper: If `APP_ENV === 'development'` or `?timing=1`, wraps PDO in `DebugPDO` to count queries and track duration.
6. **Polyfills (`includes/polyfills.php`)**:
   - Declares `str_contains()`, `str_starts_with()`, and `str_ends_with()` if running on PHP < 8.0.
7. **Security Headers & Traffic Recording (`includes/traffic.php`)**:
   - Sets headers: `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`.
   - On shutdown, `traffic_record()` aggregates requests and durations into `traffic_minute` and logs slow queries (> `monitor_slow_request_ms`) to `slow_requests`.

### Environment Keys (`.env` & `.env.example`)
| Key Name | Type / Example Values | Controls / Purpose |
| :--- | :--- | :--- |
| `DB_HOST` | Hostname / IP (e.g. `127.0.0.1`, `localhost`) | MySQL database server address |
| `DB_PORT` | Integer (e.g. `3306`) | MySQL TCP port |
| `DB_NAME` | String (e.g. `php_admin_panel`) | MySQL database schema name |
| `DB_USER` | String (e.g. `root`) | Database connection username |
| `DB_PASS` | Secret string | Database connection password |
| `DB_ROOT_PASSWORD` | Secret string | Root MySQL password for Docker container bootstrap |
| `DB_CHARSET` | String (default `utf8mb4`) | Database connection charset |
| `APP_NAME` | String (e.g. `Jinish Potro`) | Application brand display name |
| `APP_ENV` | `development` \| `production` | Error reporting, debug bar, and timing flags |
| `APP_KEY` | 64-char random hex string | Key for AES-256-GCM encryption of sensitive database fields |
| `APP_TIMEZONE` | Timezone string (e.g. `Asia/Dhaka`) | Application server timezone |
| `BASE_URL` | URL path (e.g. `/`, `/store/`) | Base URL path for routing and asset link generation |
| `SITE_URL` | Full URL (e.g. `https://jinishpotro.store/`) | Absolute canonical URL for outbound emails and cron tasks |
| `FORCE_HTTPS` | Boolean (`true` \| `false`) | Enforces 301 redirect to HTTPS for non-local traffic |
| `LETSENCRYPT_DIR`| Path string | Storage directory for Let's Encrypt certificates |
| `PORT_HTTP` | Integer (e.g. `80`, `8080`) | HTTP port mapping for Docker |
| `PORT_HTTPS` | Integer (e.g. `443`, `8443`) | HTTPS port mapping for Docker |
| `STORAGE_PRIMARY` | `local` \| `s3` | Primary driver for media uploads |
| `STORAGE_FALLBACK`| `local` \| `s3` \| empty | Secondary driver used if primary fails |
| `AWS_REGION` | String (e.g. `ap-southeast-1`) | AWS S3 storage region |
| `S3_BUCKET` | String | AWS S3 bucket name |
| `AWS_ACCESS_KEY_ID` | String | AWS IAM access key ID |
| `AWS_SECRET_ACCESS_KEY` | Secret string | AWS IAM secret access key |
| `S3_PUBLIC_BASE_URL` | URL (e.g. CloudFront URL) | Public CDN or direct S3 endpoint for uploaded media |
| `S3_KEY_PREFIX` | String (e.g. `uploads/`) | Prefix prepended to all S3 object keys |
| `UPLOADS_PATH` | Filesystem path | Custom filesystem path for media storage |
| `UPLOADS_URL` | URL path | Public web URL corresponding to `UPLOADS_PATH` |
| `GRAYLOG_ENDPOINT` | Hostname / IP | Remote Graylog telemetry endpoint |
| `METRICS_TOKEN` | Secret string | Bearer token required to access `/metrics` |

### Configuration Constants (`config/config.php`)
| Constant Name | Source | Purpose |
| :--- | :--- | :--- |
| `DB_HOST` | `env('DB_HOST', '127.0.0.1')` | Database host string |
| `DB_PORT` | `env('DB_PORT', '3306')` | Database port |
| `DB_NAME` | `env('DB_NAME', 'php_admin_panel')` | Database database name |
| `DB_USER` | `env('DB_USER', 'root')` | Database user |
| `DB_PASS` | `env('DB_PASS', '')` | Database password |
| `DB_CHARSET` | `env('DB_CHARSET', 'utf8mb4')` | Database character set |
| `APP_NAME` | `env('APP_NAME', 'PHP Admin Panel')` | Global site name fallback |
| `APP_ENV` | `env('APP_ENV', 'development')` | Environment identifier |
| `APP_KEY` | `env('APP_KEY')` | Encryption key for `includes/crypto.php` |
| `BASE_URL` | `env('BASE_URL')` or auto-detected | Base web path ending in `/` |
| `ADMIN_URL` | `BASE_URL . 'admin/'` | Base admin panel web path |
| `SITE_URL` | `env('SITE_URL')` or auto-detected | Absolute canonical site URL |
| `ROOT_PATH` | `dirname(__DIR__)` | Physical filesystem root directory of project |
| `INCLUDES_PATH` | `ROOT_PATH . '/includes'` | Physical path to includes folder |
| `UPLOADS_PATH` | `env('UPLOADS_PATH')` or `ROOT_PATH . '/assets/uploads'` | Physical path to uploads storage directory |
| `UPLOADS_URL` | `env('UPLOADS_URL')` or `BASE_URL . 'assets/uploads/'` | Web URL pointing to uploaded files |
| `UPLOADS_CACHE_PATH`| `UPLOADS_PATH . '/cache'` | Resized image cache directory |
| `UPLOADS_CACHE_URL` | `UPLOADS_URL . 'cache/'` | Resized image cache public URL |

---
## 5. Database

### Database Schema Inventory
All tables default to MySQL `InnoDB` storage engine with `utf8mb4` charset and `utf8mb4_unicode_ci` collation.

#### 1. `users`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL, `email` VARCHAR(191) NOT NULL, `password` VARCHAR(255) NOT NULL, `role` VARCHAR(50) NOT NULL DEFAULT 'editor' (broadened from ENUM in migration 003), `permissions` JSON NULL (added in migration 003), `avatar` VARCHAR(255) NULL, `avatar_file_id` BIGINT UNSIGNED NULL (FK, added in migration 001), `status` ENUM('active','inactive') NOT NULL DEFAULT 'active', `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `UNIQUE KEY uq_users_email (email)`, `KEY idx_users_status (status)`
- **FKs**: `fk_users_avatar_file_id FOREIGN KEY (avatar_file_id) REFERENCES files(id) ON DELETE SET NULL`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 2. `activity_log`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED NULL, `action` VARCHAR(64) NOT NULL, `description` TEXT NULL, `ip_address` VARCHAR(45) NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_activity_user (user_id)`, `KEY idx_activity_action (action)`, `KEY idx_activity_created (created_at)`
- **FKs**: `CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 3. `settings`
- **Columns**: `key_name` VARCHAR(64) NOT NULL, `value` MEDIUMTEXT NULL, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (key_name)`
- **Indexes**: None additional
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 4. `categories`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL, `slug` VARCHAR(120) NOT NULL, `description` TEXT NULL, `status` ENUM('active','inactive') NOT NULL DEFAULT 'active', `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `UNIQUE KEY uq_categories_slug (slug)`, `KEY idx_categories_status (status)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 5. `products`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `category_id` INT UNSIGNED NULL, `name` VARCHAR(200) NOT NULL, `slug` VARCHAR(220) NOT NULL, `sku` VARCHAR(64) NULL, `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00, `sale_price` DECIMAL(10,2) NULL, `stock` INT NOT NULL DEFAULT 0, `image` VARCHAR(255) NULL, `file_id` BIGINT UNSIGNED NULL (FK, added in migration 001), `short_description` TEXT NULL, `description` LONGTEXT NULL, `product_type` ENUM('simple','variable') NOT NULL DEFAULT 'simple', `allow_backorder` TINYINT(1) NOT NULL DEFAULT 0, `track_inventory` TINYINT(1) NOT NULL DEFAULT 1, `low_stock_threshold` INT NOT NULL DEFAULT 5, `rating_avg` DECIMAL(3,2) NOT NULL DEFAULT 0.00, `rating_count` INT UNSIGNED NOT NULL DEFAULT 0, `sold_count` INT UNSIGNED NOT NULL DEFAULT 0, `featured` TINYINT(1) NOT NULL DEFAULT 0, `meta_title` VARCHAR(255) NULL, `meta_description` TEXT NULL, `meta_keywords` VARCHAR(255) NULL (added in migration 002), `tags` VARCHAR(255) NULL, `status` ENUM('active','inactive') NOT NULL DEFAULT 'active', `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `UNIQUE KEY uq_products_slug (slug)`, `UNIQUE KEY uq_products_sku (sku)`, `KEY idx_products_category (category_id)`, `KEY idx_products_status (status)`, `KEY idx_products_featured (featured)`, `KEY idx_products_type (product_type)`
- **FKs**: `CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL`, `fk_products_file_id FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 6. `orders`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `order_number` VARCHAR(32) NOT NULL, `customer_id` INT UNSIGNED NULL, `customer_name` VARCHAR(100) NOT NULL, `customer_email` VARCHAR(191) NOT NULL, `customer_phone` VARCHAR(32) NOT NULL, `shipping_address` TEXT NOT NULL, `shipping_city` VARCHAR(64) NOT NULL, `shipping_zip` VARCHAR(16) NULL, `shipping_country` VARCHAR(64) NOT NULL DEFAULT 'Bangladesh', `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00, `shipping_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00, `discount_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00, `cod_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00, `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00, `payment_method` VARCHAR(32) NOT NULL DEFAULT 'cod', `payment_status` ENUM('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid', `shipping_method_id` INT UNSIGNED NULL, `shipping_method_name` VARCHAR(100) NULL, `coupon_code` VARCHAR(32) NULL, `notes` TEXT NULL, `status` ENUM('pending','processing','shipped','completed','cancelled') NOT NULL DEFAULT 'pending', `ip_address` VARCHAR(45) NULL, `stock_restored` TINYINT(1) NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `UNIQUE KEY uq_orders_number (order_number)`, `KEY idx_orders_customer (customer_id)`, `KEY idx_orders_status (status)`, `KEY idx_orders_created (created_at)`
- **FKs**: `CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 7. `order_items`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `order_id` INT UNSIGNED NOT NULL, `product_id` INT UNSIGNED NULL, `product_name` VARCHAR(200) NOT NULL, `product_sku` VARCHAR(64) NULL, `product_image` VARCHAR(255) NULL, `file_id` BIGINT UNSIGNED NULL (FK, added in migration 001), `variant_id` INT UNSIGNED NULL, `variant_sku` VARCHAR(64) NULL, `variant_label` VARCHAR(255) NULL, `unit_price` DECIMAL(10,2) NOT NULL, `quantity` INT NOT NULL, `line_total` DECIMAL(10,2) NOT NULL.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_order_items_order (order_id)`, `KEY idx_order_items_product (product_id)`, `KEY idx_order_items_variant (variant_id)`
- **FKs**: `CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE`, `CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL`, `fk_order_items_file_id FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 8. `option_presets`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(64) NOT NULL, `display_type` ENUM('button','dropdown','color','image') NOT NULL DEFAULT 'button', `position` INT NOT NULL DEFAULT 0.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: None additional
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 9. `option_preset_values`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `preset_id` INT UNSIGNED NOT NULL, `value` VARCHAR(64) NOT NULL, `color_hex` VARCHAR(16) NULL, `position` INT NOT NULL DEFAULT 0.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_preset_values_preset (preset_id)`
- **FKs**: `CONSTRAINT fk_preset_values_preset FOREIGN KEY (preset_id) REFERENCES option_presets(id) ON DELETE CASCADE`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 10. `product_options`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `product_id` INT UNSIGNED NOT NULL, `name` VARCHAR(64) NOT NULL, `display_type` ENUM('button','dropdown','color','image') NOT NULL DEFAULT 'button', `position` INT NOT NULL DEFAULT 0.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_product_options_product (product_id)`
- **FKs**: `CONSTRAINT fk_product_options_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 11. `product_option_values`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `option_id` INT UNSIGNED NOT NULL, `value` VARCHAR(64) NOT NULL, `color_hex` VARCHAR(16) NULL, `position` INT NOT NULL DEFAULT 0.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_option_values_option (option_id)`
- **FKs**: `CONSTRAINT fk_option_values_option FOREIGN KEY (option_id) REFERENCES product_options(id) ON DELETE CASCADE`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 12. `product_variants`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `product_id` INT UNSIGNED NOT NULL, `sku` VARCHAR(64) NULL, `price` DECIMAL(10,2) NULL, `sale_price` DECIMAL(10,2) NULL, `stock` INT NOT NULL DEFAULT 0, `image` VARCHAR(255) NULL, `file_id` BIGINT UNSIGNED NULL (FK, added in migration 001), `option_values_hash` VARCHAR(64) NOT NULL, `status` ENUM('active','inactive') NOT NULL DEFAULT 'active', `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `UNIQUE KEY uq_variant_hash (product_id, option_values_hash)`, `UNIQUE KEY uq_variant_sku (sku)`, `KEY idx_variants_product (product_id)`
- **FKs**: `CONSTRAINT fk_product_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE`, `fk_product_variants_file_id FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 13. `product_variant_values`
- **Columns**: `variant_id` INT UNSIGNED NOT NULL, `option_value_id` INT UNSIGNED NOT NULL.
- **PK**: `PRIMARY KEY (variant_id, option_value_id)`
- **Indexes**: `KEY idx_variant_values_val (option_value_id)`
- **FKs**: `CONSTRAINT fk_variant_val_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE`, `CONSTRAINT fk_variant_val_value FOREIGN KEY (option_value_id) REFERENCES product_option_values(id) ON DELETE CASCADE`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 14. `product_images`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `product_id` INT UNSIGNED NOT NULL, `filename` VARCHAR(255) NOT NULL, `file_id` BIGINT UNSIGNED NULL (FK, added in migration 001), `is_primary` TINYINT(1) NOT NULL DEFAULT 0, `position` INT NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_product_images_product (product_id)`
- **FKs**: `CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE`, `fk_product_images_file_id FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 15. `inventory_movements`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `product_id` INT UNSIGNED NOT NULL, `variant_id` INT UNSIGNED NULL, `change_qty` INT NOT NULL, `stock_after` INT NOT NULL, `reason` ENUM('order','cancel_restock','manual_adjust','restock','return','correction') NOT NULL, `order_id` INT UNSIGNED NULL, `user_id` INT UNSIGNED NULL, `note` VARCHAR(255) NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_inv_product (product_id)`, `KEY idx_inv_variant (variant_id)`, `KEY idx_inv_order (order_id)`, `KEY idx_inv_created (created_at)`
- **FKs**: `CONSTRAINT fk_inv_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE`, `CONSTRAINT fk_inv_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE`, `CONSTRAINT fk_inv_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL`, `CONSTRAINT fk_inv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 16. `shipping_methods`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL, `description` TEXT NULL, `cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00, `free_over` DECIMAL(10,2) NULL, `cod_allowed` TINYINT(1) NOT NULL DEFAULT 1, `position` INT NOT NULL DEFAULT 0, `status` ENUM('active','inactive') NOT NULL DEFAULT 'active', `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: None additional
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 17. `coupons`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `code` VARCHAR(32) NOT NULL, `type` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed', `value` DECIMAL(10,2) NOT NULL, `min_subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00, `max_discount` DECIMAL(10,2) NULL, `usage_limit` INT UNSIGNED NULL, `used_count` INT UNSIGNED NOT NULL DEFAULT 0, `starts_at` DATETIME NULL, `expires_at` DATETIME NULL, `status` ENUM('active','inactive') NOT NULL DEFAULT 'active', `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `UNIQUE KEY uq_coupons_code (code)`, `KEY idx_coupons_status (status)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 18. `order_status_history`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `order_id` INT UNSIGNED NOT NULL, `status` VARCHAR(32) NOT NULL, `note` TEXT NULL, `user_id` INT UNSIGNED NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_status_history_order (order_id)`
- **FKs**: `CONSTRAINT fk_status_history_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE`, `CONSTRAINT fk_status_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 19. `email_templates`
- **Columns**: `slug` VARCHAR(64) NOT NULL, `name` VARCHAR(100) NOT NULL, `audience` ENUM('customer','admin') NOT NULL DEFAULT 'customer', `subject` VARCHAR(255) NOT NULL, `body_html` MEDIUMTEXT NOT NULL, `variables` TEXT NULL, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (slug)`
- **Indexes**: None additional
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 20. `email_queue`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `to_email` VARCHAR(191) NOT NULL, `to_name` VARCHAR(100) NULL, `subject` VARCHAR(255) NOT NULL, `body_html` MEDIUMTEXT NOT NULL, `status` ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending', `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0, `last_error` TEXT NULL, `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `sent_at` DATETIME NULL, `related_type` VARCHAR(32) NULL, `related_id` BIGINT UNSIGNED NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_email_queue_claim (status, scheduled_at, id)`, `KEY idx_email_queue_related (related_type, related_id)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 21. `system_metrics`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `collected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `cpu_pct` DECIMAL(5,2) NULL, `load_1m` DECIMAL(6,2) NULL, `load_5m` DECIMAL(6,2) NULL, `load_15m` DECIMAL(6,2) NULL, `cpu_user_pct` DECIMAL(5,2) NULL, `cpu_sys_pct` DECIMAL(5,2) NULL, `cpu_iowait_pct` DECIMAL(5,2) NULL, `cpu_steal_pct` DECIMAL(5,2) NULL, `cpu_idle_pct` DECIMAL(5,2) NULL, `cpu_cores` SMALLINT UNSIGNED NULL, `mem_used_bytes` BIGINT UNSIGNED NULL, `mem_free_bytes` BIGINT UNSIGNED NULL, `mem_avail_bytes` BIGINT UNSIGNED NULL, `mem_total_bytes` BIGINT UNSIGNED NULL, `mem_buffers_bytes` BIGINT UNSIGNED NULL, `mem_cached_bytes` BIGINT UNSIGNED NULL, `mem_pct` DECIMAL(5,2) NULL, `swap_used_bytes` BIGINT UNSIGNED NULL, `swap_total_bytes` BIGINT UNSIGNED NULL, `swap_pct` DECIMAL(5,2) NULL, `disk_used_bytes` BIGINT UNSIGNED NULL, `disk_total_bytes` BIGINT UNSIGNED NULL, `disk_pct` DECIMAL(5,2) NULL, `disk_inodes_pct` DECIMAL(5,2) NULL, `net_rx_rate` INT UNSIGNED NULL, `net_tx_rate` INT UNSIGNED NULL, `tcp_established` INT UNSIGNED NULL, `tcp_time_wait` INT UNSIGNED NULL, `tcp_close_wait` INT UNSIGNED NULL, `proc_total` INT UNSIGNED NULL, `proc_running` INT UNSIGNED NULL, `proc_blocked` INT UNSIGNED NULL, `php_fpm_active` INT UNSIGNED NULL, `php_fpm_idle` INT UNSIGNED NULL, `php_fpm_queue` INT UNSIGNED NULL, `db_threads_connected` INT UNSIGNED NULL, `db_threads_running` INT UNSIGNED NULL, `db_qps` DECIMAL(8,2) NULL, `db_slow_queries_total` BIGINT UNSIGNED NULL, `db_innodb_buffer_pct` DECIMAL(5,2) NULL, `db_uptime_sec` INT UNSIGNED NULL, `alerts_triggered` TINYINT UNSIGNED NOT NULL DEFAULT 0.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_sys_metrics_time (collected_at)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 22. `network_samples`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `collected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `iface` VARCHAR(32) NOT NULL, `rx_bytes_sec` INT UNSIGNED NOT NULL DEFAULT 0, `tx_bytes_sec` INT UNSIGNED NOT NULL DEFAULT 0, `rx_errors_sec` INT UNSIGNED NOT NULL DEFAULT 0, `tx_errors_sec` INT UNSIGNED NOT NULL DEFAULT 0.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_net_samples_time_iface (collected_at, iface)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 23. `traffic_minute`
- **Columns**: `bucket` DATETIME NOT NULL, `req_total` INT UNSIGNED NOT NULL DEFAULT 0, `req_2xx` INT UNSIGNED NOT NULL DEFAULT 0, `req_3xx` INT UNSIGNED NOT NULL DEFAULT 0, `req_4xx` INT UNSIGNED NOT NULL DEFAULT 0, `req_5xx` INT UNSIGNED NOT NULL DEFAULT 0, `bytes_out` BIGINT UNSIGNED NOT NULL DEFAULT 0, `bytes_in` BIGINT UNSIGNED NOT NULL DEFAULT 0, `duration_ms_sum` BIGINT UNSIGNED NOT NULL DEFAULT 0, `duration_ms_max` INT UNSIGNED NOT NULL DEFAULT 0, `unique_ips` INT UNSIGNED NOT NULL DEFAULT 0.
- **PK**: `PRIMARY KEY (bucket)`
- **Indexes**: None additional
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 24. `slow_requests`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `path` VARCHAR(255) NOT NULL, `method` VARCHAR(10) NOT NULL, `status_code` SMALLINT NOT NULL, `duration_ms` INT UNSIGNED NOT NULL, `query_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0, `peak_mem` BIGINT UNSIGNED NOT NULL DEFAULT 0, `ip` VARCHAR(45) NULL.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_slow_created (created_at)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 25. `monitor_alert_rules`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(64) NOT NULL, `metric` VARCHAR(32) NOT NULL, `operator` ENUM('>','<','>=','<=','=') NOT NULL DEFAULT '>', `threshold` DECIMAL(10,2) NOT NULL, `duration_sec` INT UNSIGNED NOT NULL DEFAULT 60, `severity` ENUM('info','warning','critical') NOT NULL DEFAULT 'warning', `channel` ENUM('log','email','webhook') NOT NULL DEFAULT 'log', `channel_target` VARCHAR(255) NULL, `enabled` TINYINT(1) NOT NULL DEFAULT 1, `last_fired_at` DATETIME NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: None additional
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 26. `analytics_events`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `event_type` ENUM('pageview','view_item','add_to_cart','begin_checkout','purchase') NOT NULL, `visitor_hash` CHAR(32) NOT NULL, `session_id` VARCHAR(64) NULL, `user_id` INT UNSIGNED NULL, `customer_id` INT UNSIGNED NULL, `product_id` INT UNSIGNED NULL, `order_id` INT UNSIGNED NULL, `category_id` INT UNSIGNED NULL, `url_path` VARCHAR(255) NOT NULL, `referrer_host` VARCHAR(128) NULL, `device` ENUM('desktop','mobile','tablet') NOT NULL DEFAULT 'desktop', `browser` VARCHAR(32) NULL, `meta_json` TEXT NULL, `ip_address` VARCHAR(45) NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_ev_type_time (event_type, created_at)`, `KEY idx_ev_product (product_id, created_at)`, `KEY idx_ev_order (order_id)`, `KEY idx_ev_visitor (visitor_hash, created_at)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 27. `product_stats_daily`
- **Columns**: `date` DATE NOT NULL, `product_id` INT UNSIGNED NOT NULL, `views` INT UNSIGNED NOT NULL DEFAULT 0, `cart_adds` INT UNSIGNED NOT NULL DEFAULT 0, `orders_count` INT UNSIGNED NOT NULL DEFAULT 0, `units_sold` INT UNSIGNED NOT NULL DEFAULT 0, `revenue` DECIMAL(10,2) NOT NULL DEFAULT 0.00.
- **PK**: `PRIMARY KEY (date, product_id)`
- **Indexes**: `KEY idx_prod_stats_product (product_id)`
- **FKs**: `CONSTRAINT fk_prod_stats_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 28. `site_stats_daily`
- **Columns**: `date` DATE NOT NULL, `visitors` INT UNSIGNED NOT NULL DEFAULT 0, `pageviews` INT UNSIGNED NOT NULL DEFAULT 0, `orders_count` INT UNSIGNED NOT NULL DEFAULT 0, `revenue` DECIMAL(10,2) NOT NULL DEFAULT 0.00, `conversion_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00, `avg_order_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00.
- **PK**: `PRIMARY KEY (date)`
- **Indexes**: None additional
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 29. `search_log`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `term` VARCHAR(120) NOT NULL, `results_count` INT UNSIGNED NOT NULL DEFAULT 0, `ip_address` VARCHAR(45) NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_search_term (term)`, `KEY idx_search_created (created_at)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 30. `storefront_banners`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `placement` ENUM('hero','category_row','strip') NOT NULL DEFAULT 'hero', `title` VARCHAR(120) NULL, `subtitle` VARCHAR(255) NULL, `image` VARCHAR(255) NOT NULL, `file_id` BIGINT UNSIGNED NULL (FK, added in migration 001), `link_url` VARCHAR(255) NULL, `button_label` VARCHAR(40) NULL, `badge_text` VARCHAR(40) NULL, `position` INT NOT NULL DEFAULT 0, `status` ENUM('active','inactive') NOT NULL DEFAULT 'active', `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_banners_placement_status (placement, status, position)`
- **FKs**: `fk_storefront_banners_file_id FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 31. `customers`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL, `email` VARCHAR(191) NOT NULL, `phone` VARCHAR(32) NULL, `password_hash` VARCHAR(255) NOT NULL, `avatar` VARCHAR(255) NULL, `avatar_file_id` BIGINT UNSIGNED NULL (FK, added in migration 001), `status` ENUM('pending','active','blocked') NOT NULL DEFAULT 'pending', `email_verified_at` DATETIME NULL, `failed_logins` TINYINT UNSIGNED NOT NULL DEFAULT 0, `locked_until` DATETIME NULL, `marketing_opt_in` TINYINT(1) NOT NULL DEFAULT 0, `notes` TEXT NULL, `last_login_at` DATETIME NULL, `last_login_ip` VARCHAR(45) NULL, `deleted_at` DATETIME NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `UNIQUE KEY uq_customers_email (email)`, `KEY idx_customers_phone (phone)`, `KEY idx_customers_status (status)`
- **FKs**: `fk_customers_avatar_file_id FOREIGN KEY (avatar_file_id) REFERENCES files(id) ON DELETE SET NULL`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 32. `customer_addresses`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` INT UNSIGNED NOT NULL, `label` VARCHAR(32) NOT NULL DEFAULT 'Home', `full_name` VARCHAR(100) NOT NULL, `phone` VARCHAR(32) NOT NULL, `address` TEXT NOT NULL, `city` VARCHAR(64) NOT NULL, `area` VARCHAR(64) NULL, `zip` VARCHAR(16) NULL, `country` VARCHAR(64) NOT NULL DEFAULT 'Bangladesh', `is_default` TINYINT(1) NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_addresses_customer (customer_id)`
- **FKs**: `CONSTRAINT fk_addresses_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 33. `customer_tokens`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` INT UNSIGNED NOT NULL, `type` ENUM('verify_email','password_reset','remember_me','email_change') NOT NULL, `selector` CHAR(24) NOT NULL, `token_hash` CHAR(64) NOT NULL, `payload` VARCHAR(255) NULL, `expires_at` DATETIME NOT NULL, `ip` VARCHAR(45) NULL, `user_agent` VARCHAR(200) NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `UNIQUE KEY uq_customer_token_selector (selector)`, `KEY idx_customer_tokens_cust_type (customer_id, type)`, `KEY idx_customer_tokens_expires (expires_at)`
- **FKs**: `CONSTRAINT fk_tokens_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 34. `auth_attempts`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `scope` VARCHAR(32) NOT NULL, `identifier_hash` CHAR(64) NOT NULL, `ip` VARCHAR(45) NOT NULL, `success` TINYINT(1) NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_auth_attempts_lookup (scope, identifier_hash, success, created_at)`, `KEY idx_auth_attempts_ip (scope, ip, success, created_at)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 35. `wishlists`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` INT UNSIGNED NOT NULL, `product_id` INT UNSIGNED NOT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `UNIQUE KEY uq_wishlist_customer_product (customer_id, product_id)`, `KEY idx_wishlist_product (product_id)`
- **FKs**: `CONSTRAINT fk_wishlist_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE`, `CONSTRAINT fk_wishlist_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 36. `pages`
- **Columns**: `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `title` VARCHAR(150) NOT NULL, `slug` VARCHAR(160) NOT NULL, `content` LONGTEXT NULL, `meta_title` VARCHAR(255) NULL, `meta_description` TEXT NULL, `status` ENUM('published','draft') NOT NULL DEFAULT 'published', `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `UNIQUE KEY uq_pages_slug (slug)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 37. `contact_messages`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL, `email` VARCHAR(191) NOT NULL, `phone` VARCHAR(32) NULL, `order_number` VARCHAR(32) NULL, `message` TEXT NOT NULL, `ip` VARCHAR(45) NULL, `is_read` TINYINT(1) NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_contact_created (created_at)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 38. `newsletter_subscribers`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `email` VARCHAR(191) NOT NULL, `status` ENUM('pending','confirmed','unsubscribed') NOT NULL DEFAULT 'confirmed', `confirm_token` VARCHAR(64) NULL, `source` VARCHAR(32) NOT NULL DEFAULT 'footer', `ip` VARCHAR(45) NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `confirmed_at` DATETIME NULL.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `UNIQUE KEY uq_newsletter_email (email)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 39. `product_reviews`
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `product_id` INT UNSIGNED NOT NULL, `customer_id` INT UNSIGNED NULL, `customer_name` VARCHAR(100) NOT NULL, `customer_email` VARCHAR(191) NOT NULL, `rating` TINYINT UNSIGNED NOT NULL, `title` VARCHAR(120) NULL, `review_text` TEXT NOT NULL, `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending', `is_verified_buyer` TINYINT(1) NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `KEY idx_reviews_product_status (product_id, status, created_at)`, `KEY idx_reviews_customer (customer_id)`
- **FKs**: `CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE`, `CONSTRAINT fk_reviews_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL`
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 40. `files` (Added in `migrations/001_storage_layer.up.sql`)
- **Columns**: `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `storage_key` VARCHAR(512) NOT NULL, `provider` ENUM('local','s3') NOT NULL DEFAULT 'local', `bucket` VARCHAR(128) NULL, `original_name` VARCHAR(255) NOT NULL, `mime_type` VARCHAR(127) NOT NULL, `size` BIGINT UNSIGNED NOT NULL DEFAULT 0, `checksum_sha256` CHAR(64) NULL, `width` INT NULL, `height` INT NULL, `visibility` ENUM('public','private') NOT NULL DEFAULT 'public', `purpose` VARCHAR(32) NOT NULL, `owner_type` VARCHAR(32) NULL, `owner_id` BIGINT UNSIGNED NULL, `status` ENUM('pending','ready','failed','deleted') NOT NULL DEFAULT 'ready', `sync_status` ENUM('synced','pending','failed') NOT NULL DEFAULT 'synced', `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, `deleted_at` DATETIME NULL.
- **PK**: `PRIMARY KEY (id)`
- **Indexes**: `UNIQUE KEY uq_provider_storage_key (provider, storage_key(250))`, `KEY idx_files_owner (owner_type, owner_id)`, `KEY idx_files_purpose (purpose)`, `KEY idx_files_status_sync (status, sync_status)`, `KEY idx_files_created (created_at)`
- **FKs**: None on `files` table; referenced by `products`, `product_images`, `product_variants`, `customers`, `users`, `storefront_banners`, `order_items`.
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 41. `schema_migrations` (Created in `bin/migrate.php`)
- **Columns**: `version` VARCHAR(64) NOT NULL, `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP.
- **PK**: `PRIMARY KEY (version)`
- **Indexes**: None additional
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

#### 42. `product_sales_summary` (Runtime table queried in code; missing from schema.sql)
- **Columns**: `product_id` INT UNSIGNED NOT NULL, `variant_id` INT UNSIGNED NOT NULL DEFAULT 0, `variant_label` VARCHAR(255) NULL, `units` INT UNSIGNED NOT NULL DEFAULT 0, `revenue` DECIMAL(10,2) NOT NULL DEFAULT 0.00, `orders_count` INT UNSIGNED NOT NULL DEFAULT 0, `last_sale` DATETIME NULL.
- **PK**: `PRIMARY KEY (product_id, variant_id)`
- **Indexes**: `KEY idx_pss_product (product_id)`, `KEY idx_pss_units (units)`
- **FKs**: None
- **Engine / Collation**: `InnoDB`, `utf8mb4_unicode_ci`

---

### Migration File Application Order
To bootstrap the database cleanly without foreign key violations:
1. `sql/schema.sql` (Creates baseline 39 tables, initial views `v_product_stock`, `v_sales_by_variant`, and default seeds)
2. `bin/migrate.php` creates table `schema_migrations`
3. `migrations/001_storage_layer.up.sql` (Creates `files` table and adds `file_id` columns and FKs to `products`, `product_images`, `product_variants`, `customers`, `users`, `storefront_banners`, `order_items`)
4. `migrations/002_add_meta_keywords_to_products.up.sql` (Adds `meta_keywords` VARCHAR(255) to `products`)
5. `migrations/003_add_user_permissions.up.sql` (Modifies `users.role` to VARCHAR(50) and adds `permissions` JSON column, granting `['*']` to admin users)
6. **Required Manual DDL**: Create `product_sales_summary` table (see Open Questions).

---

### Database Seed Data Summary
*(No passwords or secret credentials included)*
- **`shipping_methods`**:
  - `Inside Dhaka` (cost: 60.00, free_over: NULL, cod_allowed: 1, position: 1)
  - `Outside Dhaka` (cost: 120.00, free_over: NULL, cod_allowed: 1, position: 2)
- **`settings`**:
  - Store identity: `site_name` ('Jinish Potro'), `store_tagline`, `store_email`, `store_phone`, `store_address`, `currency_code` ('BDT'), `currency_symbol` ('৳'), `timezone` ('Asia/Dhaka')
  - Toggles: `pretty_urls` (1), `dark_mode_enabled` (0), `guest_checkout` (1), `reviews_enabled` (1), `newsletter_enabled` (1), `cod_enabled` (1)
  - Layout & Homepage: `home_show_hero` (1), `home_show_categories` (1), `home_show_new_arrivals` (1), `home_show_best_sellers` (1), `home_show_featured` (1), `home_featured_count` (8)
  - Customer policies: `customer_registration_enabled` (1), `customer_require_email_verification` (1), `customer_remember_me_days` (30), `customer_password_min_length` (8)
  - Storage: `storage_primary` ('local'), `storage_fallback` ('s3')
- **`option_presets` & `option_preset_values`**:
  - Preset `Size` (`display_type = 'button'`): Values: `XS`, `S`, `M`, `L`, `XL`, `XXL`
  - Preset `Color` (`display_type = 'color'`): Values: `Black` (#000000), `White` (#FFFFFF), `Red` (#E11D48), `Blue` (#2563EB), `Green` (#16A34A), `Navy` (#0F172A)
- **`monitor_alert_rules`**:
  - Pre-seeded 6 default thresholds: CPU Usage > 90%, Memory Usage > 92%, Disk Usage > 90%, 5xx Error Rate > 5%, Slow Query Count > 20, DB Connected Threads > 100.
- **`email_templates`**:
  - Pre-seeded 15 system templates covering customer orders, admin notifications, customer welcome, password reset, email verification, and contact messages.

---

### Database Views, Triggers, Procedures, Events
- **Triggers**: None.
- **Stored Procedures**: None.
- **Database Events**: None.
- **SQL Views**:
  1. `v_product_stock`:
     ```sql
     SELECT p.id AS product_id,
            p.product_type,
            CASE WHEN p.product_type = 'variable'
                 THEN COALESCE(agg.total_stock, 0)
                 ELSE p.stock END AS total_stock,
            CASE WHEN p.product_type = 'variable'
                 THEN COALESCE(agg.variant_count, 0)
                 ELSE 1 END AS variant_count
     FROM products p
     LEFT JOIN (
       SELECT product_id, SUM(stock) AS total_stock, COUNT(*) AS variant_count
       FROM product_variants WHERE status = 'active'
       GROUP BY product_id
     ) agg ON agg.product_id = p.id
     ```
  2. `v_sales_by_variant`:
     ```sql
     SELECT oi.product_id, oi.variant_id, oi.product_name, oi.variant_label,
            SUM(oi.quantity)   AS units,
            SUM(oi.line_total) AS revenue,
            COUNT(DISTINCT oi.order_id) AS orders_count,
            MIN(o.created_at)  AS first_sale,
            MAX(o.created_at)  AS last_sale
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id AND o.status <> 'cancelled'
     GROUP BY oi.product_id, oi.variant_id, oi.product_name, oi.variant_label
     ```

---

### SQL Features Usage & Exact Code Citations

#### 1. Transactions (`beginTransaction`, `commit`, `rollBack`)
- `cron/process_mail_queue.php:59, 75, 124`: Atomic mail claim with SKIP LOCKED
- `cron/rebuild_sales_summary.php:52, 84, 94`: Atomic recomputation of sales summary chunks
- `tests/test_product_import_suite.php:80, 124, 185, 298`: Import rollback on failure
- `account/addresses.php:73, 79, 81`: Address default flag resets
- `account/delete.php:33, 90, 93`: Customer anonymization and token clearing
- `account/security.php:41, 57, 59`: Password update and remember token revocation
- `includes/customer_auth.php:197, 227, 246, 706, 735, 966`: Registration, verification, password reset
- `includes/inventory.php:46, 152, 161, 238, 284, 289`: Atomic stock adjustment and order cancellation restock
- `storefront/cart/checkout.php:263, 440, 480`: Complete order creation, inventory deduction, coupon counter
- `admin/orders.php:65, 128, 134`: Bulk order status update
- `admin/order_view.php:44, 85, 100`: Order status update machine and sales summary adjustment
- `admin/product_form.php:271, 461, 469`: Product, variant matrix, and options sync
- `admin/product_import.php:713, 992, 1014`: Bulk CSV importing

#### 2. `FOR UPDATE` (Row-level Locking)
- `cron/process_mail_queue.php:65`: `SELECT id FROM email_queue WHERE status = 'pending' AND scheduled_at <= NOW() ORDER BY id ASC LIMIT :lim FOR UPDATE SKIP LOCKED`
- `includes/inventory.php:243`: `SELECT id, status, stock_restored FROM orders WHERE id = :id FOR UPDATE`
- `admin/orders.php:67`: `SELECT id, order_number, status, payment_method, payment_status, stock_restored FROM orders WHERE id = :id FOR UPDATE`

#### 3. `LOCK TABLES`
- **Zero occurrences** found across the entire repository. Application strictly uses InnoDB row-level locking.

#### 4. `ON DUPLICATE KEY UPDATE`
- `cron/aggregate_analytics.php:75`: Upsert `product_stats_daily` views and cart additions
- `cron/aggregate_analytics.php:124`: Upsert `site_stats_daily` pageviews, visitors, orders, and revenue
- `cron/parse_access_log.php:127`: Upsert aggregated web server logs into `traffic_minute`
- `includes/analytics.php:347`: Upsert daily product stats
- `includes/analytics.php:395`: Upsert daily site stats
- `includes/catalog.php:317`: Increment `product_sales_summary` units, revenue, orders_count
- `includes/functions.php:366`: Upsert settings key/value pairs (`INSERT INTO settings ... ON DUPLICATE KEY UPDATE value = VALUES(value)`)
- `includes/traffic.php:147`: Upsert minute-bucket telemetry counters into `traffic_minute`

#### 5. `GROUP BY`
- `index.php:100`: `GROUP BY p.id` for best-seller summaries
- `metrics.php:66, 99, 113`: `GROUP BY status`, `GROUP BY provider` for Prometheus metrics
- `cron/aggregate_analytics.php:61, 72`: `GROUP BY product_id`, `GROUP BY oi.product_id`
- `cron/rebuild_sales_summary.php:78`: `GROUP BY oi.product_id, COALESCE(oi.variant_id, 0)`
- `api/search_suggest.php:25`: `GROUP BY c.id`
- `includes/analytics.php:333, 344, 607, 720, 757, 774, 794, 843, 856`: Reporting aggregations by period, category, device, day_of_week
- `admin/inventory.php:237`: `GROUP BY product_id, variant_id` in stock calculations
- `sql/schema.sql:318, 412`: Views `v_product_stock` and `v_sales_by_variant`

#### 6. Subqueries
- `index.php:49`: `(SELECT COUNT(*) FROM products p WHERE ...)` in category product count
- `index.php:272`: `(SELECT id FROM categories WHERE ...)`
- `account/index.php:16, orders.php:67`: `(SELECT COUNT(*) FROM order_items WHERE order_id = orders.id) AS item_count`
- `account/order.php:179`: `(SELECT filename FROM product_images WHERE product_id = oi.product_id ORDER BY is_primary DESC LIMIT 1)`
- `includes/analytics.php:526`: `AND oi.product_id IN (SELECT id FROM products WHERE category_id = {$catId})`
- `admin/inventory.php:199, 255, 299`: `(SELECT GROUP_CONCAT(...) FROM product_options ...)`
- `bin/cleanup-orphans.php:82-88`: 7 `NOT EXISTS (SELECT 1 FROM ...)` subqueries to detect orphaned media files

#### 7. `FULLTEXT` Indexes
- **Zero occurrences**. Product catalog search uses `LIKE CONCAT('%', :q, '%')` against `name`, `tags`, `short_description`, and `sku`.

#### 8. JSON Columns & Native JSON Functions
- `migrations/003_add_user_permissions.up.sql:6`: Column `users.permissions JSON NULL`
- `migrations/003_add_user_permissions.up.sql:9`: `UPDATE users SET permissions = JSON_ARRAY('*') WHERE role = 'admin'`
- `includes/permissions.php:121-140`: Uses `json_decode()` in PHP to parse user permission keys array

#### 9. Raw Concatenated SQL Statements (File:Line)
*Critical attention for SQLx query conversion:*
- `cron/cleanup_logs.php:38`: `$sql = "DELETE FROM \`{$table}\` WHERE {$whereClause} LIMIT {$batchSize}";`
- `cron/collect_metrics.php:150`: `$pdo->exec("ALTER TABLE \`system_metrics\` ADD COLUMN \`{$col}\` {$def}");`
- `cron/collect_metrics.php:719-722`: `$pdo->query("DELETE FROM ... WHERE collected_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY) LIMIT 5000");`
- `cron/process_mail_queue.php:73`: `$pdo->exec("UPDATE email_queue SET status = 'sending' WHERE id IN ($inClause)");`
- `cron/rebuild_sales_summary.php:62`: `$pdo->exec("DELETE FROM product_sales_summary WHERE product_id IN ($inClause)");`
- `includes/analytics.php:718`: `$sql .= " AND p.category_id = {$catId}";`
- `includes/analytics.php:887`: `$countSql = "SELECT COUNT(*) FROM products p WHERE {$whereClause}";`
- `includes/functions.php:614`: `$sql = "SELECT id FROM \`$table\` WHERE slug = :s AND id <> :id LIMIT 1";` (dynamic table interpolation)
- `includes/shop_bootstrap.php:425, 436`: `$stP = db()->prepare("SELECT * FROM products WHERE id IN ($inP)");`
- `bin/migrate-files-to-s3.php:221, 310`: Dynamic table and column interpolation in updates
- `admin/customers.php:181, 195, 199-201, 228, 232, 238`: `$inPlaceholders` concatenated into queries
- `admin/inventory.php:225`: `$itemsStmt = db()->prepare("SELECT * FROM ($baseSubquery) inv $whereSql ORDER BY product_id DESC, variant_id ASC LIMIT $perPage OFFSET $offset");` (direct integer concatenation)
- `admin/products.php:63, 67, 72, 82, 91, 153`: `$inClause` and `$whereSql` concatenated into `exec()` and `query()`
- `admin/reviews.php:63, 72`: `$in` concatenated into `UPDATE` and `DELETE`

---

### Query Inventory by Module

| Module | File | Target Tables | Operation | Purpose |
| :--- | :--- | :--- | :--- | :--- |
| **Catalog** | `includes/catalog.php` | `products`, `categories` | SELECT | Fetch product by ID or slug with category join |
| **Catalog** | `includes/catalog.php` | `product_options`, `product_option_values` | SELECT | Retrieve option hierarchy (Size, Color) |
| **Catalog** | `includes/catalog.php` | `product_variants`, `product_variant_values` | SELECT | Variant pricing, availability, and option values |
| **Catalog** | `includes/catalog.php` | `product_sales_summary` | INSERT / UPDATE | Increment/decrement product sales summary stats |
| **Storefront**| `index.php` | `categories`, `products`, `product_sales_summary`, `storefront_banners` | SELECT | Load cached categories, new arrivals, best sellers, banners |
| **Storefront**| `storefront/shop/index.php`| `products`, `categories`, `product_variants` | SELECT | Filtered product catalog, dynamic price range, pagination |
| **Storefront**| `storefront/shop/product.php`| `products`, `product_images`, `product_variants`, `product_reviews` | SELECT | Full product detail, gallery images, approved reviews, JSON-LD |
| **Cart** | `includes/shop_bootstrap.php`| `products`, `product_variants`, `shipping_methods`, `coupons` | SELECT | Validate cart lines against live stock, calculate totals |
| **Checkout** | `storefront/cart/checkout.php`| `orders`, `order_items`, `order_status_history`, `inventory_movements`, `coupons`, `customer_addresses` | INSERT, UPDATE | Atomic checkout transaction, order placement, stock decrement |
| **Auth** | `includes/customer_auth.php` | `customers`, `customer_tokens`, `auth_attempts` | SELECT, INSERT, UPDATE, DELETE | Customer register, login verify, lockout, token issue & consume |
| **Account** | `account/orders.php` | `orders`, `order_items` | SELECT | Customer order history listing with item count subquery |
| **Account** | `account/addresses.php` | `customer_addresses` | SELECT, INSERT, UPDATE, DELETE | Address book CRUD with default address enforcement |
| **Admin** | `admin/orders.php` | `orders`, `order_items`, `order_status_history` | SELECT, UPDATE, INSERT | Admin orders listing, search, bulk status transition machine |
| **Admin** | `admin/products.php` | `products`, `product_variants`, `product_images`, `inventory_movements` | SELECT, UPDATE, DELETE | Admin products table, bulk active/inactive, bulk deletion |
| **Admin** | `admin/product_form.php` | `products`, `product_options`, `product_variants`, `product_images` | INSERT, UPDATE, DELETE | Full product editor with variant combinatorial matrix generation |
| **Inventory**| `includes/inventory.php` | `products`, `product_variants`, `inventory_movements`, `orders` | SELECT, UPDATE, INSERT | Atomic stock increment/decrement, ledger logging, cancellation restock |
| **Mail** | `includes/mailer.php` | `email_queue`, `email_templates` | INSERT, SELECT, UPDATE | Mail queue insertion, template token hydration, status updating |
| **Analytics**| `includes/analytics.php` | `analytics_events`, `product_stats_daily`, `site_stats_daily`, `search_log` | INSERT, SELECT, UPDATE | Visitor event recording, daily rollup, KPI reports, funnels |
| **Monitor** | `cron/collect_metrics.php` | `system_metrics`, `network_samples`, `traffic_minute`, `slow_requests` | INSERT, DELETE | 1-minute server metric collection and automated pruning |

---

### Full Source: Stock, Coupon, and Order Status Functions

#### 1. Full Source: `stock_adjust()` (`includes/inventory.php:20-166`)
```php
function stock_adjust(
    int $productId,
    ?int $variantId,
    int $delta,
    string $reason,
    ?int $orderId = null,
    ?string $note = null
): bool {
    if ($delta === 0) {
        return true;
    }

    $allowedReasons = ['order', 'cancel_restock', 'manual_adjust', 'restock', 'return', 'correction'];
    $reasonMap = [
        'manual' => 'manual_adjust',
        'cancel' => 'cancel_restock',
        'adjust' => 'manual_adjust',
    ];
    $reason = $reasonMap[$reason] ?? $reason;
    if (!in_array($reason, $allowedReasons, true)) {
        $reason = 'manual_adjust';
    }

    $pdo = db();
    $inTx = $pdo->inTransaction();
    if (!$inTx) {
        $pdo->beginTransaction();
    }

    try {
        // Fetch product to inspect inventory rules
        $stmtP = $pdo->prepare(
            "SELECT id, product_type, stock, track_inventory, allow_backorder, low_stock_threshold
             FROM products WHERE id = :id"
        );
        $stmtP->execute([':id' => $productId]);
        $product = $stmtP->fetch();

        if (!$product) {
            if (!$inTx) $pdo->rollBack();
            return false;
        }

        $trackInventory = isset($product['track_inventory']) ? (int)$product['track_inventory'] : 1;
        $allowBackorder = !empty($product['allow_backorder']);

        $stockAfter = 0;

        if ($variantId !== null) {
            // Check variant existence
            $stmtV = $pdo->prepare(
                "SELECT id, stock FROM product_variants WHERE id = :id AND product_id = :pid"
            );
            $stmtV->execute([':id' => $variantId, ':pid' => $productId]);
            $variant = $stmtV->fetch();

            if (!$variant) {
                if (!$inTx) $pdo->rollBack();
                return false;
            }

            // Negative delta requires atomic conditional check when tracking is enabled and backorders are disallowed
            if ($delta < 0 && $trackInventory && !$allowBackorder) {
                $stmtUpdate = $pdo->prepare(
                    "UPDATE product_variants
                     SET stock = stock + :delta
                     WHERE id = :id AND (stock + :min_check) >= 0"
                );
                $stmtUpdate->execute([':delta' => $delta, ':id' => $variantId, ':min_check' => $delta]);
                if ($stmtUpdate->rowCount() === 0) {
                    if (!$inTx) $pdo->rollBack();
                    return false;
                }
            } else {
                $stmtUpdate = $pdo->prepare(
                    "UPDATE product_variants
                     SET stock = stock + :delta
                     WHERE id = :id"
                );
                $stmtUpdate->execute([':delta' => $delta, ':id' => $variantId]);
            }

            // Retrieve updated variant stock
            $stmtAfter = $pdo->prepare("SELECT stock FROM product_variants WHERE id = :id");
            $stmtAfter->execute([':id' => $variantId]);
            $stockAfter = (int)$stmtAfter->fetchColumn();
        } else {
            // Simple product
            if ($delta < 0 && $trackInventory && !$allowBackorder) {
                $stmtUpdate = $pdo->prepare(
                    "UPDATE products
                     SET stock = stock + :delta
                     WHERE id = :id AND (stock + :min_check) >= 0"
                );
                $stmtUpdate->execute([':delta' => $delta, ':id' => $productId, ':min_check' => $delta]);
                if ($stmtUpdate->rowCount() === 0) {
                    if (!$inTx) $pdo->rollBack();
                    return false;
                }
            } else {
                $stmtUpdate = $pdo->prepare(
                    "UPDATE products
                     SET stock = stock + :delta
                     WHERE id = :id"
                );
                $stmtUpdate->execute([':delta' => $delta, ':id' => $productId]);
            }

            // Retrieve updated product stock
            $stmtAfter = $pdo->prepare("SELECT stock FROM products WHERE id = :id");
            $stmtAfter->execute([':id' => $productId]);
            $stockAfter = (int)$stmtAfter->fetchColumn();
        }

        // Write inventory ledger movement
        $userId = $_SESSION['user']['id'] ?? null;
        $stmtMov = $pdo->prepare(
            "INSERT INTO inventory_movements (product_id, variant_id, change_qty, stock_after, reason, order_id, user_id, note)
             VALUES (:pid, :vid, :qty, :after, :reason, :oid, :uid, :note)"
        );
        $stmtMov->execute([
            ':pid'    => $productId,
            ':vid'    => $variantId,
            ':qty'    => $delta,
            ':after'  => $stockAfter,
            ':reason' => $reason,
            ':oid'    => $orderId,
            ':uid'    => $userId,
            ':note'   => $note,
        ]);

        if (!$inTx) {
            $pdo->commit();
        }

        // Post-adjustment: verify low stock and trigger hooks/notifications
        stock_check_low($productId, $variantId);

        return true;
    } catch (Throwable $e) {
        if (!$inTx) {
            $pdo->rollBack();
        }
        error_log("stock_adjust failed: " . $e->getMessage());
        return false;
    }
}
```

#### 2. Full Source: `stock_restore_for_order()` (`includes/inventory.php:233-294`)
```php
function stock_restore_for_order(int $orderId): bool
{
    $pdo = db();
    $inTx = $pdo->inTransaction();
    if (!$inTx) {
        $pdo->beginTransaction();
    }

    try {
        // Lock order row for update
        $stmt = $pdo->prepare("SELECT id, status, stock_restored FROM orders WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            if (!$inTx) $pdo->rollBack();
            return false;
        }

        if (!empty($order['stock_restored'])) {
            // Already restored: do not restore again
            if (!$inTx) $pdo->commit();
            return false;
        }

        // Fetch all items from order_items
        $stmtItems = $pdo->prepare("SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = :oid");
        $stmtItems->execute([':oid' => $orderId]);
        $items = $stmtItems->fetchAll();

        foreach ($items as $item) {
            $pid = (int)$item['product_id'];
            $vid = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
            $qty = (int)$item['quantity'];

            if ($qty > 0) {
                stock_adjust(
                    $pid,
                    $vid,
                    $qty,
                    'cancel_restock',
                    $orderId,
                    "Order #{$orderId} cancelled - stock restored"
                );
            }
        }

        // Mark stock_restored = 1
        $pdo->prepare("UPDATE orders SET stock_restored = 1 WHERE id = :id")->execute([':id' => $orderId]);

        if (!$inTx) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if (!$inTx) {
            $pdo->rollBack();
        }
        error_log("Failed to restore stock for order {$orderId}: " . $e->getMessage());
        return false;
    }
}
```

#### 3. Full Source: `coupon_validate()` (`includes/shop_bootstrap.php:640-702`)
```php
function coupon_validate(string $code, float $subtotal): array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'error' => 'Please enter a coupon code.'];
    }

    try {
        $stmt = db()->prepare("SELECT * FROM coupons WHERE UPPER(code) = :c LIMIT 1");
        $stmt->execute([':c' => $code]);
        $coupon = $stmt->fetch();

        if (!$coupon || $coupon['status'] !== 'active') {
            return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'error' => 'Invalid or inactive coupon code.'];
        }

        $now = time();
        if (!empty($coupon['starts_at']) && strtotime($coupon['starts_at']) > $now) {
            return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'error' => 'This coupon is not active yet.'];
        }

        if (!empty($coupon['expires_at']) && strtotime($coupon['expires_at']) < $now) {
            return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'error' => 'This coupon has expired.'];
        }

        if ($coupon['usage_limit'] !== null && (int)$coupon['usage_limit'] > 0 && (int)$coupon['used_count'] >= (int)$coupon['usage_limit']) {
            return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'error' => 'This coupon has reached its maximum usage limit.'];
        }

        $minSubtotal = (float)($coupon['min_subtotal'] ?? 0);
        if ($subtotal < $minSubtotal) {
            return [
                'valid'    => false,
                'coupon'   => null,
                'discount' => 0.0,
                'error'    => 'Minimum order amount for this coupon is ' . format_price($minSubtotal) . '.',
            ];
        }

        $val = (float)$coupon['value'];
        $discount = 0.0;
        if ($coupon['type'] === 'percent') {
            $discount = round($subtotal * ($val / 100), 2);
            if ($coupon['max_discount'] !== null && (float)$coupon['max_discount'] > 0) {
                $discount = min($discount, (float)$coupon['max_discount']);
            }
        } else {
            // Fixed discount
            $discount = min($val, $subtotal);
        }

        $discount = round($discount, 2);

        return [
            'valid'    => true,
            'coupon'   => $coupon,
            'discount' => $discount,
            'error'    => null,
        ];
    } catch (Throwable $e) {
        return ['valid' => false, 'coupon' => null, 'discount' => 0.0, 'error' => 'Coupon lookup error.'];
    }
}
```

#### 4. Full Source: `cart_totals()` (`includes/shop_bootstrap.php:707-786`)
```php
function cart_totals(
    array $cart,
    ?int $shippingMethodId = null,
    ?string $couponCode = null,
    string $paymentMethod = 'cod'
): array {
    $subtotal = (float)($cart['subtotal'] ?? 0.0);
    $discount = 0.0;
    $coupon = null;
    $couponError = null;

    if (!empty($couponCode)) {
        $cRes = coupon_validate($couponCode, $subtotal);
        if ($cRes['valid']) {
            $coupon = $cRes['coupon'];
            $discount = $cRes['discount'];
        } else {
            $couponError = $cRes['error'];
        }
    }

    $discountedSubtotal = max(0.0, round($subtotal - $discount, 2));

    // Shipping methods from DB
    $shippingMethod = null;
    $shippingFee = 0.0;

    try {
        $methods = db()->query(
            "SELECT * FROM shipping_methods WHERE status = 'active' ORDER BY position ASC, id ASC"
        )->fetchAll();

        if ($methods) {
            if ($shippingMethodId !== null) {
                foreach ($methods as $m) {
                    if ((int)$m['id'] === $shippingMethodId) {
                        $shippingMethod = $m;
                        break;
                    }
                }
            }
            if (!$shippingMethod) {
                $shippingMethod = $methods[0];
            }

            $shippingFee = (float)$shippingMethod['cost'];
            // Free shipping threshold crossing computed on subtotal AFTER discount
            if ($shippingMethod['free_over'] !== null && (float)$shippingMethod['free_over'] > 0) {
                if ($discountedSubtotal >= (float)$shippingMethod['free_over']) {
                    $shippingFee = 0.0;
                }
            }
        }
    } catch (Throwable $e) {
        $shippingFee = (float)setting('shipping_fee', '0');
    }

    // COD fee
    $codFee = 0.0;
    if ($paymentMethod === 'cod') {
        $codEnabled = (int)setting('cod_enabled', '1') === 1;
        if ($codEnabled) {
            $codFee = (float)setting('cod_fee', '0.00');
        }
    }

    $total = round($discountedSubtotal + $shippingFee + $codFee, 2);

    return [
        'subtotal'            => $subtotal,
        'discount'            => $discount,
        'discounted_subtotal' => $discountedSubtotal,
        'coupon'              => $coupon,
        'coupon_error'        => $couponError,
        'shipping_method'     => $shippingMethod,
        'shipping_fee'        => $shippingFee,
        'cod_fee'             => $codFee,
        'total'               => $total,
    ];
}
```

#### 5. Full Source: `is_valid_status_transition()` & Order Status Updater (`admin/order_view.php:33-97` & `admin/orders.php:25-36`)
```php
function is_valid_status_transition(string $from, string $to): bool
{
    if ($from === $to) return true;
    $validTransitions = [
        'pending'    => ['processing', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped'    => ['completed', 'cancelled'],
        'completed'  => ['cancelled'], // return / refund
        'cancelled'  => [], // terminal
    ];
    return in_array($to, $validTransitions[$from] ?? [], true);
}
```

*Order status updater execution block (`admin/order_view.php:43-97`):*
```php
$pdo = db();
$pdo->beginTransaction();
try {
    $oldStatus = $order['status'];

    $updateSql = "UPDATE orders SET status = :status";
    $updateParams = [':status' => $newStatus, ':id' => $id];

    // Auto-mark payment as paid when completed for COD
    if ($newStatus === 'completed' && $order['payment_method'] === 'cod') {
        $updateSql .= ", payment_status = 'paid'";
    }

    $updateSql .= " WHERE id = :id";
    $pdo->prepare($updateSql)->execute($updateParams);

    // If cancelled -> atomic stock restore
    if ($newStatus === 'cancelled') {
        stock_restore_for_order($id);
    }

    // Update product_sales_summary in same transaction
    if ($newStatus === 'completed' && $oldStatus !== 'completed') {
        product_sales_summary_record_order($id, $pdo);
    } elseif ($newStatus === 'cancelled' && $oldStatus === 'completed') {
        product_sales_summary_reverse_order($id, $pdo);
    }

    // Insert into order_status_history
    $uid = $_SESSION['user']['id'] ?? null;
    $historyNote = $note ?: ('Status changed from ' . ucfirst($oldStatus) . ' to ' . ucfirst($newStatus));
    $stmtHist = $pdo->prepare(
        "INSERT INTO order_status_history (order_id, status, note, user_id)
         VALUES (:oid, :st, :note, :uid)"
    );
    $stmtHist->execute([
        ':oid'  => $id,
        ':st'   => $newStatus,
        ':note' => $historyNote,
        ':uid'  => $uid,
    ]);

    $pdo->commit();

    // Fire order.status_changed hook
    hook_fire('order.status_changed', [
        'order_id'   => $id,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'note'       => $historyNote,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
```

#### 6. Full Source: `product_sales_summary_record_order()` & `reverse_order()` (`includes/catalog.php:305-353`)
```php
function product_sales_summary_record_order(int $orderId, ?PDO $pdo = null): void
{
    $pdo = $pdo ?: db();
    try {
        $pdo->exec(
            "INSERT INTO product_sales_summary (product_id, variant_id, variant_label, units, revenue, orders_count, last_sale)
             SELECT 
                 oi.product_id,
                 COALESCE(oi.variant_id, 0) AS variant_id,
                 MAX(oi.variant_label) AS variant_label,
                 SUM(oi.quantity) AS units,
                 SUM(oi.line_total) AS revenue,
                 1 AS orders_count,
                 NOW() AS last_sale
             FROM order_items oi
             WHERE oi.order_id = {$orderId} AND oi.product_id IS NOT NULL
             GROUP BY oi.product_id, COALESCE(oi.variant_id, 0)
             ON DUPLICATE KEY UPDATE
                 units        = product_sales_summary.units + VALUES(units),
                 revenue      = product_sales_summary.revenue + VALUES(revenue),
                 orders_count = product_sales_summary.orders_count + 1,
                 last_sale    = GREATEST(COALESCE(product_sales_summary.last_sale, '1970-01-01'), VALUES(last_sale))"
        );
    } catch (Throwable $e) {
        error_log("Failed to record product_sales_summary for order #{$orderId}: " . $e->getMessage());
    }
}

function product_sales_summary_reverse_order(int $orderId, ?PDO $pdo = null): void
{
    $pdo = $pdo ?: db();
    try {
        $pdo->exec(
            "UPDATE product_sales_summary pss
             JOIN (
                 SELECT 
                     product_id,
                     COALESCE(variant_id, 0) AS variant_id,
                     SUM(quantity) AS units,
                     SUM(line_total) AS revenue
                 FROM order_items
                 WHERE order_id = {$orderId} AND product_id IS NOT NULL
                 GROUP BY product_id, COALESCE(variant_id, 0)
             ) oi ON oi.product_id = pss.product_id AND oi.variant_id = pss.variant_id
             SET 
                 pss.units        = GREATEST(0, pss.units - oi.units),
                 pss.revenue      = GREATEST(0.00, pss.revenue - oi.revenue),
                 pss.orders_count = GREATEST(0, pss.orders_count - 1)"
        );
    } catch (Throwable $e) {
        error_log("Failed to reverse product_sales_summary for order #{$orderId}: " . $e->getMessage());
    }
}
```

---
## 6. Helpers and PHP-specific behavior

### Function & Class Inventory in `includes/` and `config/`

#### `config/env.php`
- `load_env(string $path): void`: Parses `.env` line by line, populating `$_ENV`, `$_SERVER`, and calling `putenv()`.
- `env(string $key, $default = null): mixed`: Retrieves environment variable with boolean/null string cast.

#### `config/config.php`
- `is_https(): bool`: Detects HTTPS via direct port 443 or reverse proxy headers (`X-Forwarded-Proto`, CloudFront, Cloudflare).
- `storage(): \App\Storage\StorageManager`: Returns the singleton `StorageManager` instance.

#### `config/database.php`
- `class DebugPDOStatement`: Statement decorator measuring query duration and incrementing query counters for `Server-Timing`.
- `class DebugPDO extends PDO`: PDO decorator profiling `query()`, `exec()`, and `prepare()`.
- `class Database`: Database singleton establishing PDO connection with connection-level timezone offset command.
- `db(): PDO`: Convenience global wrapper returning the active PDO connection instance.

#### `includes/functions.php`
- `e(?string $value): string`: Escapes string using `htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE` and `UTF-8`.
- `url(string $path = ''): string`: Prefixes path with `BASE_URL`.
- `admin_url(string $path = ''): string`: Prefixes path with `ADMIN_URL`.
- `shop_url(string $path = ''): string`: Maps legacy storefront URLs to central `route()` builder.
- `get_route_map(): array`: Returns master route table definition with pretty URL patterns and files.
- `pretty_urls_active(): bool`: Checks if pretty URL rewrites are enabled in settings or `.on` lockfile.
- `route(string $name, array $params = []): string`: Resolves named route to clean pretty URL or query string URL.
- `route_is(string $name): bool`: Tests if the current HTTP request URI matches a named route pattern.
- `redirect(string $path, int $code = 302): void`: Issues HTTP Location header redirect to relative path or named route.
- `admin_redirect(string $path): void`: Issues HTTP Location header redirect relative to `ADMIN_URL`.
- `csrf_token(): string`: Retrieves or generates cryptographically secure 32-byte hex token in `$_SESSION['csrf_token']`.
- `csrf_field(): string`: Outputs hidden HTML `<input type="hidden" name="_csrf" value="...">`.
- `csrf_verify(?string $token): bool`: Constant-time verification of submitted token against session token via `hash_equals`.
- `require_csrf(): void`: Terminates request with 403 Forbidden if POST lacks valid `_csrf` token.
- `get_client_ip(): string`: Extracts real client IP respecting `HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`, and `HTTP_X_REAL_IP`.
- `flash(string $type, string $message): void`: Appends flash message array to `$_SESSION['_flash']`.
- `get_flashes(): array`: Retrieves and clears pending flash messages from session.
- `input(string $key, $default = ''): string`: Safely reads trimmed POST or GET input parameter.
- `old(string $key, $default = ''): string`: Retrieves and HTML-escapes previous form input from `$_SESSION['_old']`.
- `set_old(array $data): void`: Caches submitted form data in `$_SESSION['_old']` for redisplay.
- `clear_old(): void`: Unsets `$_SESSION['_old']` on successful transaction.
- `setting_cache(): &array`: In-memory static cache of database settings, backed by `FileCache` (60 min TTL).
- `setting(string $key, ?string $default = null): ?string`: Fast in-memory lookup of global setting value.
- `update_setting(string $key, string $value): void`: Upserts key/value into `settings` table, invalidates file cache.
- `settings_save(array $pairs, array $rules, string $tabName = 'settings'): array`: Validates and persists whitelisted settings pairs.
- `schema_ready(): bool`: Verifies that `settings` table exists in database.
- `log_activity(string $action, string $description = ''): void`: Inserts audit log entry into `activity_log`.
- `active_if(bool $condition, string $class = 'active'): string`: Returns CSS class string if condition is true.
- `format_date(?string $datetime, string $format = 'M j, Y H:i'): string`: Formats date string using application timezone.
- `avatar_url(?string $avatar, ?string $name = ''): string`: Generates avatar image URL or SVG initials placeholder.
- `slugify(string $text): string`: Transliterates Unicode string to lowercase alphanumeric slug.
- `unique_slug(string $base, string $table, int $excludeId = 0): string`: Guarantees unique slug in target table by appending integer suffix.
- `price(?float $amount): string`: Formats float to currency string using `setting('currency_symbol')`.
- `format_price($amount): string`: Alias for `price()`.
- `upload_error_message(int $code): string`: Maps PHP file upload error integer to descriptive string.
- `product_image_url(?string $image, string $size = 'full', ?int $productId = null): string`: Resolves product image URL across storage drivers.
- `store_logo_url(?string $logo = null): ?string`: Returns store logo URL from setting or default asset.
- `upload_url(?string $path = ''): string`: Resolves path relative to `UPLOADS_URL`.
- `color_name_to_hex(?string $name): ?string`: Maps common color names (Red, Blue, etc.) to 6-digit hex code.
- `store_favicon_url(?string $fav = null): ?string`: Returns store favicon URL or default icon.
- `banner_image_url(?string $image): string`: Resolves promotional banner image URL.
- `category_placeholder_url(): string`: Returns inline SVG placeholder data URL for categories.
- `category_image_url(?string $image): string`: Resolves category image URL or fallback placeholder.
- `icon(string $name, string $class = '', string $title = ''): string`: Outputs inline `<svg><use xlink:href="#icon-name"></svg>`.
- `pagination_window(int $currentPage, int $totalPages, int $maxOptions = 15): array`: Computes sliding pagination window with `'...'` ellipsis.
- `render_admin_pagination(...): string`: Renders Bootstrap 5 pagination HTML.
- `render_editorial_pagination(...): string`: Renders editorial layout pagination HTML.
- `render_coza_pagination(...): string`: Renders Cozastore circular numbered pagination HTML.

#### `includes/catalog.php`
- `product_get(int $id): ?array`: Fetches single product row with category name and slug.
- `product_get_by_slug(string $slug): ?array`: Fetches single active product by slug.
- `product_options_get(int $productId): array`: Fetches option dimensions and values for product.
- `variants_get(int $productId, bool $onlyActive = true): array`: Fetches all variants with resolved option values.
- `variant_get(int $variantId): ?array`: Fetches single variant by ID.
- `variant_effective_price(array $variant, array $product): float`: Returns variant sale price, regular price, or parent price.
- `variant_available_qty(array $variant, array $product): int`: Computes real available stock considering backorder settings.
- `product_available_qty(int $productId): int`: Computes total available quantity for simple or variable product.
- `variant_hash(array $optionValueIds): string`: Computes deterministic MD5 hash of sorted option value IDs.
- `product_gallery_images(int $productId): array`: Fetches ordered secondary product images.
- `product_second_image(int $productId): ?string`: Returns first gallery image for hover flip animation.
- `product_color_swatches(int $productId): array`: Returns unique color swatches and hex codes for product cards.
- `product_sales_summary_record_order(int $orderId, ?PDO $pdo = null): void`: Increments units and revenue in `product_sales_summary`.
- `product_sales_summary_reverse_order(int $orderId, ?PDO $pdo = null): void`: Decrements units and revenue on order cancellation.
- `storefront_banners_get(string $placement = 'hero'): array`: Fetches active promotional banners for placement.
- `shipping_delivery_estimate(): string`: Computes human delivery date range (e.g. "2-4 days").
- `storefront_filter_options(): array`: Computes aggregated price ranges and categories for catalog filters.
- `products_attach_card_data(array &$products): void`: Batch attaches second hover images and color swatches to product cards.
- `product_recalculate_rating(int $productId): void`: Recalculates `rating_avg` and `rating_count` from approved reviews.

#### `includes/inventory.php`
- `stock_adjust(int $productId, ?int $variantId, int $delta, string $reason, ?int $orderId = null, ?string $note = null): bool`: Atomically updates stock and writes to `inventory_movements` ledger.
- `stock_check_low(int $productId, ?int $variantId): void`: Checks threshold and fires `'stock.low'` hook / queues alert email.
- `stock_restore_for_order(int $orderId): bool`: Restores stock for cancelled order exactly once via `stock_restored` flag.

#### `includes/permissions.php`
- `admin_permissions_catalog(): array`: Returns nested catalog of all system permissions grouped by module.
- `all_permission_keys(): array`: Returns flat array of all valid permission strings.
- `admin_roles_catalog(): array`: Returns role templates (`admin`, `manager`, `editor`, `viewer`) with default permissions.
- `user_permissions(?array $user): array`: Extracts and parses granted permission keys array from user record.
- `user_has_permission(?array $user, string ...$permissions): bool`: Evaluates user against required permissions (wildcard `*` aware).
- `has_permission(string ...$permissions): bool`: Evaluates currently logged-in admin user against permissions.
- `require_permission(string ...$permissions): void`: Enforces permission check or aborts with 403 Forbidden.

#### `includes/crypto.php`
- `app_encrypt(string $plainText, ?string $key = null): string`: Encrypts plaintext via AES-256-GCM returning `base64(iv . tag . ciphertext)`.
- `app_decrypt(string $cipherPayload, ?string $key = null): ?string`: Decrypts AES-256-GCM payload; returns null on tampering.

#### `includes/FileCache.php`
- `class FileCache`: Filesystem key/value cache driver with TTL and md5 subfolder hashing (`storage/cache/`).

#### `includes/images.php`
- `image_pipeline_init_cache(): void`: Creates cache directory and `.htaccess` execution blocker.
- `image_get_variant(string $relPath, int $targetWidth, string $format = 'webp'): ?string`: Dynamic on-demand GD resize and WebP conversion.
- `product_generate_responsive_variants(int $productId, string $sourceFilePath, string $baseFilename): array`: Pre-generates WebP sizes (`thumb:300`, `card:600`, `detail:1200`, `zoom:1800`).
- `img_tag(string $src, string $alt = '', string $sizes = '100vw', array $opts = []): string`: Generates responsive `<picture>` tag with WebP `<source>` elements.
- `image_pipeline_clear_cache(): int`: Purges cached responsive images.

#### `includes/shop_bootstrap.php`
- `check_storefront_maintenance(): void`: Halts storefront with 503 if maintenance mode setting is enabled.
- `shop_active_categories(): array`: Cached active categories retrieval for navigation header.
- `product_effective_price(array $p): float`: Returns sale price if on sale, otherwise regular price.
- `product_is_on_sale(array $p): bool`: Evaluates whether product currently has valid discounted price.
- `product_discount_percent(array $p): int`: Calculates rounded percentage discount integer.
- `cart_key(int $productId, ?int $variantId = null): string`: Returns `p{id}` or `p{id}_v{id}` key.
- `cart_parse_key(string $key): array`: Decodes cart key string back to product and variant IDs.
- `cart_migrate(): void`: Migrates legacy integer-keyed cart session to string keys.
- `cart_add(int $productId, $arg2 = 1, $arg3 = null): void`: Adds quantity to session cart.
- `cart_set($keyOrPid, int $qty, ?int $variantId = null): void`: Sets exact item quantity in cart.
- `cart_set_key(string $key, int $qty): void`: Sets cart item quantity by key.
- `cart_remove($keyOrPid, ?int $variantId = null): void`: Removes item from cart session.
- `cart_remove_key(string $key): void`: Removes cart item by key.
- `cart_clear(): void`: Empties `$_SESSION['cart']`.
- `cart_raw(): array`: Returns raw `$_SESSION['cart']` key-value array.
- `cart_count(): int`: Returns total count of all units in cart.
- `cart_load(): array`: Hydrates cart items against database, validates live stock, adjusts quantities.
- `coza_render_mini_cart(array $cart): string`: Renders HTML markup for slide-out mini-cart drawer.
- `coupon_validate(string $code, float $subtotal): array`: Validates coupon code and calculates discount amount.
- `cart_totals(array $cart, ?int $shippingMethodId = null, ?string $couponCode = null, string $paymentMethod = 'cod'): array`: Computes order subtotal, discount, shipping, COD fee, and grand total.
- `generate_order_number(): string`: Formats sequence order number (`ORD-YYYYMM-XXXX`).

#### `includes/customer_auth.php`
- `customer_current(): ?array`: Returns authenticated customer record from session or remember cookie.
- `customer_register(array $in): array`: Registers customer account with validation, password pre-hashing, and welcome email.
- `customer_login(string $email, string $pass, bool $remember = false): array`: Validates customer credentials, enforces rate limits and lockout.
- `customer_session_start(array $data): void`: Populates `$_SESSION['customer']` and regenerates session ID.
- `customer_logout(): void`: Clears customer session, deletes remember token in DB, clears cookie.
- `customer_require_login(string $next = ''): array`: Enforces customer authentication or redirects to `/login`.
- `password_ok(string $pw, string $email, string $name = ''): ?string`: Checks minimum length, common dictionaries, and personal info match.
- `customer_password_hash(string $pw): string`: Hashes password using SHA-256 pre-hash followed by `PASSWORD_DEFAULT` bcrypt.
- `customer_password_verify(string $pw, string $hash): bool`: Verifies password against pre-hashed bcrypt hash.
- `customer_password_needs_rehash(string $hash): bool`: Checks if bcrypt hash needs cost upgrade.
- `token_issue(int $customerId, string $type, int $ttlSeconds, ?string $payload = null): string`: Issues selector.secret token stored as sha256 in DB.
- `token_consume(string $type, string $raw): ?array`: Single-use atomic validation and consumption of customer token.
- `token_peek(string $type, string $raw): ?array`: Non-destructive inspection of valid token.
- `token_revoke_all(int $customerId, ?string $type = null): void`: Deletes customer tokens in database.
- `remember_me_cookie_set(string $rawToken, int $days): void`: Sets persistent `c_remember` cookie with SameSite=Lax.
- `remember_me_cookie_clear(): void`: Clears `c_remember` browser cookie.
- `customer_remember_restore(): ?array`: Re-authenticates customer from `c_remember` cookie via DB selector.
- `auth_rate_limited(string $scope, string $identifier, string $ip): bool`: Checks failed attempts in `auth_attempts` table.
- `auth_attempt_log(string $scope, string $identifier, string $ip, bool $success): void`: Logs authentication attempt into `auth_attempts`.
- `customer_claim_guest_orders(int $customerId, string $email): int`: Associates historical guest orders matching customer email.
- `customer_url_next(?string $raw): string`: Sanitizes redirection URL to prevent open redirect vulnerabilities.
- `customer_forgot_password(string $email): bool`: Generates password reset token and queues email.
- `customer_reset_password(string $rawToken, string $newPassword, string $confirmPassword): array`: Consumes token and updates customer password.
- `customer_verify_email(string $rawToken): array`: Consumes email verification token and updates `email_verified_at`.
- `customer_resend_verification(?string $email = null): array`: Re-issues verification token email.

---

### Full Source of Core Engine Helpers

#### 1. Money Formatter (`includes/functions.php:624-635`)
```php
function price(?float $amount): string
{
    $symbol = setting('currency_symbol', '$');
    if ($amount === null) return '—';
    return $symbol . number_format((float)$amount, 2);
}

function format_price($amount): string
{
    return price($amount !== null ? (float)$amount : null);
}
```

#### 2. URL Builder: Pretty vs Non-Pretty (`includes/functions.php:18-26, 121-176`)
```php
function url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

function admin_url(string $path = ''): string
{
    return ADMIN_URL . ltrim($path, '/');
}

function pretty_urls_active(): bool
{
    static $active = null;
    if ($active !== null) {
        return $active;
    }
    $settingOn = function_exists('setting') ? ((int)setting('pretty_urls', '0') === 1) : false;
    $lockFileOn = file_exists(__DIR__ . '/../storage/pretty_urls.on');
    $active = ($settingOn || $lockFileOn);
    return $active;
}

function route(string $name, array $params = []): string
{
    $map = get_route_map();
    if (!isset($map[$name])) {
        return url($name . (!empty($params) ? '?' . http_build_query($params) : ''));
    }

    $entry = $map[$name];
    $isPretty = pretty_urls_active();

    if ($isPretty && isset($entry['pretty'])) {
        $pattern = $entry['pretty'];
        foreach ($params as $k => $v) {
            $placeholder = '{' . $k . '}';
            if (str_contains($pattern, $placeholder)) {
                $pattern = str_replace($placeholder, rawurlencode((string)$v), $pattern);
                unset($params[$k]);
            }
        }
        $url = BASE_URL . $pattern;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $url;
    }

    $file = $entry['file'];
    if ($file === '') {
        return BASE_URL . (!empty($params) ? '?' . http_build_query($params) : '');
    }

    $url = BASE_URL . $file;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}
```

#### 3. Order Number Generator (`includes/shop_bootstrap.php:789-811`)
```php
function generate_order_number(): string
{
    $prefix = setting('order_number_prefix', 'ORD');
    if ($prefix === '') $prefix = 'ORD';
    $monthPrefix = $prefix . '-' . date('Ym') . '-';

    try {
        $stmt = db()->prepare(
            "SELECT order_number FROM orders
             WHERE order_number LIKE :p
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':p' => $monthPrefix . '%']);
        $last = $stmt->fetchColumn();
        $nextNum = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $nextNum = (int)$m[1] + 1;
        }
        return $monthPrefix . str_pad((string)$nextNum, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return $monthPrefix . strtoupper(bin2hex(random_bytes(2)));
    }
}
```

#### 4. Slug Generator (`includes/functions.php:599-621`)
```php
function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT//IGNORE', $text) ?: $text;
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = strtolower(trim($text, '-'));
    $text = preg_replace('~-+~', '-', $text);
    return $text ?: 'n-a';
}

function unique_slug(string $base, string $table, int $excludeId = 0): string
{
    $slug = $base;
    $i    = 1;
    $sql  = "SELECT id FROM `$table` WHERE slug = :s AND id <> :id LIMIT 1";
    while (true) {
        $stmt = db()->prepare($sql);
        $stmt->execute([':s' => $slug, ':id' => $excludeId]);
        if (!$stmt->fetch()) return $slug;
        $slug = $base . '-' . (++$i);
    }
}
```

#### 5. CSRF Protection (`includes/functions.php:241-268`)
```php
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    return !empty($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && !csrf_verify($_POST['_csrf'] ?? null)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}
```

#### 6. Rate Limiter (`includes/customer_auth.php:742-817`)
```php
function auth_rate_limited(string $scope, string $identifier, string $ip): bool
{
    $pdo = db();
    $identHash = hash('sha256', strtolower(trim($identifier)));

    try {
        if ($scope === 'login') {
            // Max 5 failed attempts per identifier in 15 minutes
            if ($identifier !== '') {
                $st1 = $pdo->prepare(
                    "SELECT COUNT(*) FROM auth_attempts
                     WHERE scope = 'login' AND identifier_hash = :idh AND success = 0
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
                );
                $st1->execute([':idh' => $identHash]);
                if ((int)$st1->fetchColumn() >= 5) {
                    return true;
                }
            }

            // Max 20 failed attempts per IP in 15 minutes
            $st2 = $pdo->prepare(
                "SELECT COUNT(*) FROM auth_attempts
                 WHERE scope = 'login' AND ip = :ip AND success = 0
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $st2->execute([':ip' => $ip]);
            if ((int)$st2->fetchColumn() >= 20) {
                return true;
            }
        } elseif ($scope === 'forgot_password') {
            // Max 3 requests per identifier in 60 minutes
            if ($identifier !== '') {
                $st1 = $pdo->prepare(
                    "SELECT COUNT(*) FROM auth_attempts
                     WHERE scope = 'forgot_password' AND identifier_hash = :idh
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)"
                );
                $st1->execute([':idh' => $identHash]);
                if ((int)$st1->fetchColumn() >= 3) {
                    return true;
                }
            }
        }
    } catch (Throwable $e) {
        error_log("auth_rate_limited error: " . $e->getMessage());
    }

    return false;
}

function auth_attempt_log(string $scope, string $identifier, string $ip, bool $success): void
{
    try {
        $identHash = hash('sha256', strtolower(trim($identifier)));
        $st = db()->prepare(
            "INSERT INTO auth_attempts (scope, identifier_hash, ip, success, created_at)
             VALUES (:scope, :idh, :ip, :succ, NOW())"
        );
        $st->execute([
            ':scope' => $scope,
            ':idh'   => $identHash,
            ':ip'    => $ip,
            ':succ'  => $success ? 1 : 0,
        ]);
    } catch (Throwable $e) {}
}
```

#### 7. Settings Loader (`includes/functions.php:332-372`)
```php
function &setting_cache(): array
{
    static $cache = null;
    if ($cache === null) {
        $cached = FileCache::get('settings:all');
        if (is_array($cached)) {
            $cache = $cached;
            return $cache;
        }

        $cache = [];
        try {
            $rows = db()->query('SELECT key_name, value FROM settings')->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['key_name']] = $r['value'];
            }
            FileCache::set('settings:all', $cache, 3600); // 60 min TTL
        } catch (Throwable $e) {
            // Settings table may not exist yet during install.
        }
    }
    return $cache;
}

function setting(string $key, ?string $default = null): ?string
{
    $cache = &setting_cache();
    return $cache[$key] ?? $default;
}

function update_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (key_name, value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    $stmt->execute([':k' => $key, ':v' => $value]);
    $cache = &setting_cache();
    $cache[$key] = $value;
    FileCache::delete('settings:all');
}
```

#### 8. Flash Messages (`includes/functions.php:292-302`)
```php
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}
```

#### 9. Pagination (`includes/functions.php:964-1004, 1103-1135`)
```php
function pagination_window(int $currentPage, int $totalPages, int $maxOptions = 15): array
{
    if ($totalPages <= 1) {
        return [];
    }

    if ($totalPages <= $maxOptions) {
        return range(1, $totalPages);
    }

    $half = (int)floor($maxOptions / 2);
    $start = max(1, $currentPage - $half);
    $end = min($totalPages, $start + $maxOptions - 1);

    if ($end - $start + 1 < $maxOptions) {
        $start = max(1, $end - $maxOptions + 1);
    }

    $pages = [];
    if ($start > 1) {
        $pages[] = 1;
        if ($start > 2) {
            $pages[] = '...';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        if (!in_array($i, $pages, true)) {
            $pages[] = $i;
        }
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $pages[] = '...';
        }
        $pages[] = $totalPages;
    }

    return $pages;
}

function render_coza_pagination(int $currentPage, int $totalPages, array $queryParams = [], int $maxOptions = 10, string $pageParam = 'page'): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $window = pagination_window($currentPage, $totalPages, $maxOptions);
    $html = '<div class="flex-c-m flex-w w-full p-t-38 m-lr--7" aria-label="Page navigation">';

    if ($currentPage > 1) {
        $prevUrl = '?' . http_build_query(array_merge($queryParams, [$pageParam => $currentPage - 1]));
        $html .= '<a href="' . htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') . '" class="flex-c-m how-pagination1 trans-04 m-all-7" aria-label="Previous page"><i class="zmdi zmdi-chevron-left"></i></a>';
    }

    foreach ($window as $item) {
        if ($item === '...') {
            $html .= '<span class="flex-c-m stext-106 cl6 m-all-7">&hellip;</span>';
        } elseif ((int)$item === $currentPage) {
            $html .= '<span class="flex-c-m how-pagination1 trans-04 m-all-7 active-pagination1" aria-current="page">' . (int)$item . '</span>';
        } else {
            $url = '?' . http_build_query(array_merge($queryParams, [$pageParam => (int)$item]));
            $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="flex-c-m how-pagination1 trans-04 m-all-7">' . (int)$item . '</a>';
        }
    }

    if ($currentPage < $totalPages) {
        $nextUrl = '?' . http_build_query(array_merge($queryParams, [$pageParam => $currentPage + 1]));
        $html .= '<a href="' . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . '" class="flex-c-m how-pagination1 trans-04 m-all-7" aria-label="Next page"><i class="zmdi zmdi-chevron-right"></i></a>';
    }

    $html .= '</div>';
    return $html;
}
```

---

### Tricky PHP Built-ins Used & Rust Equivalents

| PHP Built-in | Exact Flags & Arguments Used in Repo | Behavior / Traps | Rust Implementation Equivalent |
| :--- | :--- | :--- | :--- |
| `number_format` | `number_format((float)$amount, 2)` | Formats with standard comma thousands separator and dot decimal (`1,234.56`) | Use `format!("{:.2}", amount)` or `num-format` crate with custom locale |
| `round` | `round($val, 2)` | Standard half-up floating point rounding to 2 decimals | In Rust, `(val * 100.0).round() / 100.0` or prefer integer minor units (cents/poisha) |
| `strtotime` | `strtotime('-1 day')`, `strtotime($str)` | Dynamic relative string parsing; returns Unix timestamp or `false` on failure | Use `chrono::DateTime::parse_from_rfc3339` or `chrono::NaiveDateTime` |
| `date` | `date('Y-m-d H:i:s')`, `date('c')`, `date('Ym')` | Formats current or given timestamp using PHP format characters | `chrono::Local::now().format("%Y-%m-%d %H:%M:%S")` |
| `mb_strlen` | `mb_strlen($pw)` | Multi-byte UTF-8 character length (not byte count) | `pw.chars().count()` in Rust |
| `mb_strtolower`| `mb_strtolower(trim($name))` | Unicode-aware lowercase conversion | `name.trim().to_lowercase()` |
| `filter_var` | `filter_var($email, FILTER_VALIDATE_EMAIL)` | RFC email validation check | Use `validator` or `email_address` crate in Rust |
| `htmlspecialchars`| `htmlspecialchars($val, ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8')` | Encodes `"`, `'`, `<`, `>`, `&` and replaces invalid code points | Tera template engine auto-escapes HTML, or `v_htmlescape` crate |
| `json_encode` | `json_encode($data, JSON_PRETTY_PRINT)` | Serializes data structures to JSON; can fail returning `false` | `serde_json::to_string(&data)` |
| `sprintf` | `sprintf('mysql:host=%s;port=%s;...', ...)` | Formatted string interpolation | `format!("mysql:host={};port={};...", ...)` |
| `usort` | `usort($lines, function($a, $b) { return $aKey <=> $bKey; })` | In-place non-stable sort using spaceship operator (`<=>`) | `lines.sort_by(\|a, b\| a_key.cmp(&b_key))` |
| `random_bytes`| `random_bytes(32)`, `random_bytes(16)` | CSPRNG generating secure raw bytes | `rand::thread_rng().gen::<[u8; 32]>()` or `ring::rand::SystemRandom` |

---
## 7. Auth, sessions, security

### Session Configuration
- **Session Name**: `PHPSESSID` (PHP default).
- **Cookie Parameters**:
  - `lifetime`: `0` (session cookie destroyed when browser closes).
  - `path`: `/` (entire domain).
  - `domain`: `''` (current host).
  - `secure`: `false` by default, but dynamically set to `true` when `is_https()` evaluates to true or behind HTTPS reverse proxy.
  - `httponly`: `true` (prohibits JavaScript document.cookie access).
  - `samesite`: `'Lax'` (mitigates CSRF across external cross-site navigations).
- **Session Save Handler**: `files` (default filesystem storage in `/tmp` or PHP default path).
- **Session Keys Map**:
  | Session Key | Data Type | Meaning & Payload | Where Set | Where Read |
  | :--- | :--- | :--- | :--- | :--- |
  | `$_SESSION['user']` | Array | Authenticated staff/admin user (`id`, `name`, `email`, `role`, `permissions`, `avatar`) | `admin/includes/auth.php:49` | `admin/includes/auth.php` (`current_user()`, `has_role()`) |
  | `$_SESSION['customer']` | Array | Authenticated customer record (`id`, `name`, `email`, `phone`, `status`, `pwd_stamp`, `verified`, `login_time`) | `includes/customer_auth.php:394` | `includes/customer_auth.php:customer_current()`, layout headers |
  | `$_SESSION['cart']` | Associative Array | Active shopping cart lines `[cart_key => int_qty]` (e.g. `['p5' => 2, 'p8_v14' => 1]`) | `includes/shop_bootstrap.php:349, 368` | `shop_bootstrap.php:cart_raw()`, `cart_load()` |
  | `$_SESSION['applied_coupon']` | String | Active applied coupon code in cart | `storefront/cart/index.php:57`, `checkout.php:60` | `includes/shop_bootstrap.php:cart_totals()`, `checkout.php` |
  | `$_SESSION['csrf_token']` | String (64-char hex) | Cryptographic anti-CSRF token | `includes/functions.php:244` | `includes/functions.php:csrf_verify()`, `api/newsletter.php` |
  | `$_SESSION['_flash']` | Array of Arrays | Ephemeral notification messages `[['type' => '...', 'message' => '...']]` | `includes/functions.php:294` | `includes/functions.php:get_flashes()`, admin/shop headers |
  | `$_SESSION['_old']` | Associative Array | Previous form input values preserved on validation failure | `includes/functions.php:319` | `includes/functions.php:old()` in forms |
  | `$_SESSION['last_placed_order']` | String | Order number of the order just completed by visitor | `storefront/cart/checkout.php:447` | `storefront/cart/confirmation.php:23` |
  | `$_SESSION['checkout_token']` | String | Anti-replay one-time token for order submission | `storefront/cart/checkout.php:127` | Cleared at `checkout.php:445` |
  | `$_SESSION['checkout_started_tracked']` | Boolean | Prevents firing duplicate `begin_checkout` analytics events | `storefront/cart/checkout.php:131` | Cleared at `checkout.php:446` |

---

### Customer Authentication Flows

1. **Registration Flow (`storefront/auth/register.php` & `includes/customer_auth.php:132-251`)**:
   - Validates CSRF token `_csrf`.
   - Anti-spam: Invisible honeypot field `website_url` must be empty, and submission time trap requires minimum 2 seconds since page load.
   - Validates unique email, valid Bangladeshi phone (`01[3-9]\d{8}`), minimum password length (default 8 chars), and rejects common passwords.
   - Computes password hash via `customer_password_hash($pw)`.
   - If `customer_require_email_verification == 1`: Inserts customer with `status = 'pending'`, issues single-use token of type `'verify_email'` (24h TTL), queues `customer_verify_email` template email.
   - If verification disabled: Inserts with `status = 'active'`, establishes session immediately via `customer_session_start()`, auto-claims prior guest orders by email matching.

2. **Login Flow (`storefront/auth/login.php` & `includes/customer_auth.php:257-377`)**:
   - Evaluates rate limiting via `auth_rate_limited('login', $email, $ip)`: Max 5 failed attempts per email in 15 minutes, or 20 failed attempts per IP.
   - Queries `customers` by email where `deleted_at IS NULL`.
   - Constant-time defense: If user does not exist, performs dummy `customer_password_verify()` against static bcrypt hash to prevent timing enumeration.
   - Account lockout: If `locked_until > NOW()`, aborts.
   - Password verification: Evaluates `customer_password_verify($pass, $user['password_hash'])`.
   - On failure: Increments `failed_logins`. If `failed_logins >= 5`, sets `locked_until = NOW() + 15 minutes` (or `customer_lockout_minutes` setting).
   - On success: Resets `failed_logins = 0`, updates `last_login_at` and `last_login_ip`, logs success in `auth_attempts`.
   - If unverified and `customer_block_login_unverified == 1`: Aborts and prompts user to check email.
   - If "Remember Me" checked: Generates 30-day token via `token_issue($id, 'remember_me', 30 days)` and sets persistent `c_remember` cookie.
   - Populates `$_SESSION['customer']`, regenerates session ID (`session_regenerate_id(true)`), auto-claims guest orders, merges session cart with previously saved customer cart lines.

3. **Logout Flow (`storefront/auth/logout.php` & `includes/customer_auth.php:424-435`)**:
   - Reads `c_remember` cookie. If present, calls `token_revoke_all($id, 'remember_me')` in `customer_tokens` table.
   - Calls `remember_me_cookie_clear()` setting `c_remember` expiry to `time() - 3600`.
   - Unsets `$_SESSION['customer']`.
   - Calls `session_regenerate_id(true)`.

4. **Remember-Me Mechanism (`includes/customer_auth.php:640-738`)**:
   - Cookie Name: `c_remember`.
   - Format: `{selector}.{secret}` (where selector is 24-character hex, secret is 64-character hex).
   - Database Storage (`customer_tokens`):
     - `selector`: CHAR(24) indexed.
     - `token_hash`: CHAR(64) computed as `hash('sha256', $secret)`.
     - `type`: `'remember_me'`.
     - `expires_at`: `NOW() + INTERVAL 30 DAY`.
   - Restoration (`customer_remember_restore()`): Looks up token by selector where `type = 'remember_me'` and `expires_at > NOW()`. Verifies `hash_equals($token['token_hash'], hash('sha256', $secret))`. Single-use rotation: Rotates secret and issues updated cookie on every auto-login.

5. **Email Verification Flow (`storefront/auth/verify-email.php` & `customer_auth.php:1022-1064`)**:
   - Link contains `?token={selector}.{secret}`.
   - `token_consume('verify_email', $raw)` atomically verifies and deletes the token row from `customer_tokens`.
   - Updates `customers SET status = 'active', email_verified_at = NOW() WHERE id = :id`.
   - Automatically claims previous guest orders placed with that email.

6. **Password Reset Flow (`storefront/auth/forgot-password.php` & `reset-password.php`)**:
   - Request: Submits email. Rate limited to 3 requests per hour. Looked up in `customers`.
   - Token Generation: `token_issue($id, 'password_reset', 3600)` (60-minute TTL).
   - Email: Queues `customer_password_reset` with reset URL containing `{selector}.{secret}`.
   - Submission: Consumes token via `token_consume('password_reset', $token)`.
   - Updates password hash via `customer_password_hash($newPassword)`.
   - Revokes all existing customer sessions and remember tokens via `token_revoke_all($id)`.
   - Queues `customer_password_changed` security notification email.

---

### Password Hashing Algorithms & Options
1. **Customer Password Hashing (`includes/customer_auth.php:499-514`)**:
   - **Algorithm**: Two-tier hashing.
   - **Tier 1 (Pre-hash)**: `base64_encode(hash('sha256', $password, true))`.
     - *Rationale*: Bypasses the hard 72-byte truncation limit of bcrypt and normalizes multi-byte Unicode strings into fixed-length 44-character ASCII strings.
   - **Tier 2 (Storage)**: `password_hash($preHashed, PASSWORD_DEFAULT)` producing `$2y$10$...` standard bcrypt hash.
   - **Verification**: `password_verify(base64_encode(hash('sha256', $password, true)), $storedHash)`.
2. **Admin User Password Hashing (`admin/includes/auth.php:15-33`)**:
   - **Algorithm**: Standard bcrypt direct without pre-hashing: `password_hash($password, PASSWORD_BCRYPT)`.
   - **Verification**: Direct `password_verify($password, $user['password'])`.
   - **Auto-Rehash**: Calls `password_needs_rehash($hash, PASSWORD_BCRYPT)` on login and updates database if work factor increases.

---

### Admin Authentication & Permission System
- **User Table**: `users` (separate from `customers`).
- **Seeding & Installation**: Initial administrator created via `admin/install.php` with name, email, password, and `role = 'admin'`.
- **Role & Permission Representation**:
  - `role`: String column (`admin`, `manager`, `editor`, `viewer`).
  - `permissions`: JSON array column on `users` table (e.g. `["*"]` or `["products.view", "products.edit"]`).
  - If `role === 'admin'`, the wildcard permission `'*'` is automatically injected into `$_SESSION['user']['permissions']`.
- **Permission Checking (`includes/permissions.php:165-185`)**:
  - `user_has_permission($user, ...$permissions)` checks if `*` is present, or if exact permission key is in array, or if module wildcard (e.g. `products.*`) matches.

---

### CSRF Protection Architecture
- **Form Field Name**: `_csrf` (via `csrf_field()`).
- **HTTP Header Name**: `X-CSRF-Token` (checked in AJAX endpoints `api/newsletter.php`, `api/review.php`, and `api/wishlist.php`).
- **Session Key**: `$_SESSION['csrf_token']` (32 bytes of `random_bytes()`, 64 hex chars).
- **Verification**: Constant-time comparison `hash_equals($_SESSION['csrf_token'], $token)`.
- **Endpoints Lacking CSRF Protection**:
  1. `api/cart_ajax.php`: Accepts POST actions (`add`, `update`, `remove`) without ANY CSRF token check.
  2. `admin/install.php`: Accepts installation POST without CSRF verification.
  3. `storefront/track/index.php`: Accepts order lookup POST without CSRF verification.

---

### Rate Limiters Summary

| Scope / Endpoint | Key / Identifier | Threshold | Window | Storage Backend | File & Line |
| :--- | :--- | :--- | :--- | :--- | :--- |
| Customer Login (Per Email) | `sha256(lowercase(email))` | 5 failed attempts | 15 minutes | MySQL table `auth_attempts` | `includes/customer_auth.php:749` |
| Customer Login (Per IP) | `$_SERVER['REMOTE_ADDR']` | 20 failed attempts | 15 minutes | MySQL table `auth_attempts` | `includes/customer_auth.php:760` |
| Customer Lockout | Customer `locked_until` column | 5 failed logins | 15 minutes | MySQL column `customers.locked_until` | `includes/customer_auth.php:337` |
| Password Reset Request | `sha256(lowercase(email))` | 3 requests | 60 minutes | MySQL table `auth_attempts` | `includes/customer_auth.php:771` |
| Availability Polling API | Client IP MD5 | 60 requests | 60 seconds | Filesystem JSON in `sys_get_temp_dir()` | `api/availability.php:20` |

---

### Security Headers
Configured across `.htaccess`, `config/config.php`, `includes/traffic.php`, and controllers:
- `X-Content-Type-Options: nosniff` (enforced via `.htaccess` and `traffic.php`).
- `Referrer-Policy: strict-origin-when-cross-origin` (enforced via `.htaccess` and `traffic.php`).
- `X-Frame-Options: SAMEORIGIN` (enforced in auth pages and admin layout).
- `Cache-Control: private, no-cache, no-store, must-revalidate` (for all dynamic PHP HTML and JSON endpoints).
- `Cache-Control: public, max-age=31536000, immutable` (for static assets and media).

---

### File Uploads Architecture
- **Allowed MIME Types & Purpose Rules** (`src/Storage/StorageManager.php:40-75`):
  - `product`: `image/jpeg`, `image/pjpeg`, `image/png`, `image/webp`, `image/gif` (Max 8 MB)
  - `avatar`: `image/jpeg`, `image/pjpeg`, `image/png`, `image/webp` (Max 4 MB)
  - `banner`: `image/jpeg`, `image/pjpeg`, `image/png`, `image/webp` (Max 10 MB)
  - `branding`: `image/jpeg`, `image/png`, `image/webp`, `image/svg+xml`, `image/x-icon`, `image/vnd.microsoft.icon` (Max 5 MB)
  - `document`: `application/pdf`, `text/csv`, `text/plain` (Max 15 MB)
- **Validation Pipeline**:
  1. `is_uploaded_file($tmpName)` HTTP POST check.
  2. File size verified against purpose whitelist.
  3. Strict MIME detection using `finfo_file(FILEINFO_MIME_TYPE)`.
  4. Non-SVG images verified using `getimagesize()` via `ImageProcessor::inspect()`.
  5. SVG files inspected with regex `/<script|javascript:|onload=|onerror=|onclick=/i` to prevent XSS payloads.
- **Filename & Storage Key Generation (`generateStorageKey()`)**:
  - Format: `{purpose}/{YYYY}/{MM}/{32_char_random_hex}.{ext}`
  - Example: `product/2026/11/a9f3b8c7e12d45a687f0b9c1d2e3f4a5.webp`
- **GD Image Processing & Transformations**:
  - `avatar`: Center square cropped, resized to `400x400`, re-encoded to WebP at quality 85, stripping EXIF.
  - `product`: Scaled to max width `1800px`, re-encoded to WebP at quality 85, stripping EXIF.
  - Responsive Variants (`generateResponsiveVariants()`): Generates `thumb` (300w), `card` (600w), and `detail` (1200w).
- **Media Delivery (`uploads.php`)**:
  - Intercepts requests to `/uploads/*` or `/assets/uploads/*`.
  - Releases session lock immediately (`session_write_close()`) to avoid stalling concurrent media requests.
  - Strips null bytes and traversal sequences (`\0`, `../`, `..\\`).
  - Supports ETag and `If-None-Match` / `If-Modified-Since` responding with `304 Not Modified`.
  - Fallback: Delivers subtle placeholder SVG if requested file does not exist on disk.
- **Symlink Provisioning (`storage_link.php`)**:
  - Creates symbolic link from `ROOT_PATH/uploads` to external `UPLOADS_PATH`.

---

### Security Vulnerabilities & Findings Table

| File & Line Number | Description of Vulnerability | Severity | Impact on Rust Migration |
| :--- | :--- | :--- | :--- |
| `config/config.php:185` | **Session Fixation / Arbitrary Session ID Injection**: If `$_GET['test_sess']` is supplied with alphanumeric characters, `session_id()` is forcibly overridden without authentication. | **HIGH** | Do NOT port this testing hook to Rust; strictly generate secure session IDs. |
| `admin/install.php:1-145` | **Unprotected Installer Endpoint**: `admin/install.php` lacks CSRF protection and can be triggered if install checks are not strictly locked. | **HIGH** | Eliminate dynamic runtime installer endpoint in Rust; use static database migrations (`sqlx migrate`). |
| `api/cart_ajax.php:14-180` | **Missing CSRF on Cart State Mutations**: POST actions `add`, `update`, `remove` execute without verifying CSRF token. | **MEDIUM** | Implement CSRF protection or SameSite cookie verification in Axum middleware. |
| `storefront/track/index.php:65` | **Unthrottled Order Enumeration**: Order lookup by phone/email and order number has no rate limiting, allowing enumeration. | **MEDIUM** | Add IP-based and target-based rate limiting in Rust router. |
| `admin/inventory.php:225` | **Raw Concatenated SQL Limits**: Direct string interpolation of `$perPage` and `$offset` into SQL query string instead of parameter binding. | **MEDIUM** | Use parameterized queries in SQLx (`LIMIT ? OFFSET ?`). |
| `admin/products.php:63, 67, 91` | **Dynamic In-Clause String Interpolation**: `$inClause` concatenated directly into `db()->exec()`. | **LOW** | Use SQLx `IN (...)` query builder or `UNNEST` array binding in Rust. |
| `config/config.php:26` | **Insecure Default Fallback APP_KEY**: Falls back to hardcoded string `'CHANGE_ME_TO_64_RANDOM_CHARS'` if `.env` omitted. | **HIGH** | Make `APP_KEY` mandatory on server startup in Rust; fail fast if missing. |
| `uploads.php:33` | **Path Traversal Sanitization by Blacklisting**: Uses `str_replace(['../', '..\\'], '')` instead of canonical path verification. | **LOW** | Use `std::path::Path::canonicalize()` and verify parent directory prefix in Rust. |
| `api/availability_stream.php:40` | **Worker Thread Exhaustion DoS Risk**: Long-running SSE loops with `sleep(2)` hold server threads open indefinitely. | **MEDIUM** | Rust Axum handles asynchronous SSE natively via `tokio::sync::broadcast` without blocking threads. |
| `api/availability.php:20` | **Insecure Tempfile Rate Limiter**: Writes rate limit state to `sys_get_temp_dir() . '/rate_' . md5($ip) . '.json'`. | **LOW** | Replace with in-memory thread-safe rate limiter in Rust (`governor` or DashMap). |

---
## 8. Storefront and account UI

### Storefront & Account Page Specifications

| Page URL | Database Data Required | Layout & Partials Included | Forms (Action, Method, Fields) | JS Files Attached | Inline PHP Logic in Template |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `/` | `categories`, `products` (new arrivals, best sellers, featured), `storefront_banners`, `settings` | `header.php`, `footer.php`, `product_card.php`, `mini_cart.php` | Search modal (GET `route('shop')`, `q`); Newsletter form (POST `api/newsletter.php`, `email`, `_csrf`) | `shop.js`, `main.js`, `slick-custom.js` | Section visibility toggles from settings (`home_show_hero`, `home_show_best_sellers`); category product count pills; price discount badge computation |
| `/shop` | `products`, `categories`, `product_variants` | `header.php`, `footer.php`, `product_card.php`, Coza circular pagination | Filter form (GET `route('shop')`, `category`, `sort`, `min_price`, `max_price`, `in_stock`, `on_sale`, `q`) | `shop.js`, `shop/ui.js`, `availability.js` | Active filter chips generation with removal URLs; dynamic price tier URLs; isotope product card grid loop; empty state fallback |
| `/category/{slug}` | `categories` (by slug), `products`, `product_variants` | `header.php`, `footer.php`, `product_card.php`, `breadcrumb.php` | Filter & search drawers (same as `/shop`) | `shop.js`, `availability.js` | Category title & SEO meta hydration; category active state highlighted in top navigation |
| `/product/{slug}` | `products`, `product_options`, `product_option_values`, `product_variants`, `product_images`, `product_reviews`, `orders` (verified buyer check) | `header.php`, `footer.php`, `review_stars.php`, `breadcrumb.php` | Add to Cart form (POST `route('cart')` or AJAX `api/cart_ajax.php`, `product_id`, `variant_id`, `quantity`, `_csrf`); Review form (POST `api/review.php`, `rating`, `title`, `review_text`, `customer_name`, `customer_email`, `_csrf`) | `shop.js`, `availability.js`, `slick-custom.js`, `pdp-modern.css` | JSON-LD schema builder; variant option selector rendering (button, dropdown, color swatch); dynamic stock status badge; customer review submission modal; related products query |
| `/cart` | `$_SESSION['cart']` items hydrated against `products`, `product_variants`, `shipping_methods`, `coupons` | `header.php`, `footer.php`, `mini_cart.php` | Cart update form (POST `route('cart')`, `action`, `cart_key`, `quantity`, `_csrf`); Coupon form (POST `route('cart')`, `action=apply_coupon`, `coupon_code`, `_csrf`); Shipping calculator form (POST, `shipping_method_id`, `district`) | `shop.js` | Line item totals calculation; available stock clamp warnings; discount breakdown; conditional shipping fee waiver |
| `/checkout` | `cart_load()` items, `customer_addresses`, `shipping_methods`, `coupons`, `settings` | `header.php`, `footer.php`, `mini_cart.php` | Order submission form (POST `route('checkout')`, `customer_name`, `customer_email`, `customer_phone`, `shipping_address`, `shipping_city`, `shipping_zip`, `shipping_method_id`, `payment_method`, `save_address`, `saved_address_id`, `notes`, `_csrf`) | `shop.js` | Authenticated address auto-fill; payment method availability toggles (COD vs Bank Transfer); free shipping crossing verification |
| `/order/confirmation` | `orders` (by `$_SESSION['last_placed_order']`), `order_items`, `customers` | `header.php`, `footer.php` | Quick password create form for guest checkout conversion (POST `route('order_confirmation')`, `password`, `password_confirm`, `_csrf`) | `shop.js` | Display order number, delivery address, order items breakdown, payment status banner |
| `/track` | `orders`, `order_items`, `order_status_history` | `header.php`, `footer.php` | Tracking lookup form (POST `route('track')`, `order_number`, `contact_info`, `_csrf`) | `shop.js` | Stepper progress bar mapping 5 order statuses (`pending`, `processing`, `shipped`, `completed`, `cancelled`); itemized cost summary |
| `/login` | `customers` (by email) | `header.php`, `footer.php` | Login form (POST `route('login')`, `email`, `password`, `remember_me`, `next`, `_csrf`) | `auth.js` | Brute-force lockout remaining minutes warning; error alert banners; next URL redirect preservation |
| `/register` | `settings` (registration toggles, terms URL) | `header.php`, `footer.php` | Registration form (POST `route('register')`, `name`, `email`, `phone`, `password`, `password_confirm`, `website_url` [honeypot], `form_load_time` [time-trap], `marketing_opt_in`, `_csrf`) | `auth.js` | Client-side password strength meter; honeypot style concealment; dynamic terms & conditions modal link |
| `/forgot-password` | `customers` (by email) | `header.php`, `footer.php` | Request form (POST `route('forgot_password')`, `email`, `_csrf`) | `auth.js` | Success confirmation state; anti-enumeration notification ("If that email exists, a link was sent") |
| `/reset-password` | `customer_tokens` (by selector) | `header.php`, `footer.php` | Reset form (POST `route('reset_password')`, `token`, `password`, `password_confirm`, `_csrf`) | `auth.js` | Token expiration/invalidity warning screen; password validation rules |
| `/contact` | `settings` (contact email, phone, address) | `header.php`, `footer.php` | Contact form (POST `route('contact')`, `name`, `email`, `phone`, `order_number`, `message`, `honey_field` [honeypot], `_csrf`) | `main.js` | Google Map iframe / address info cards; anti-spam verification; flash success receipt |
| `/newsletter` | `newsletter_subscribers` | `header.php`, `footer.php` | Subscription / unsubscribe form (POST `route('newsletter')`, `action`, `email`, `token`, `_csrf`) | `shop.js` | Double opt-in confirmation banner; unsubscribe cancellation feedback |
| `/account` | `customers`, `orders` (last 5), `customer_addresses` (default) | `account/_layout.php`, `header.php`, `footer.php` | None (read-only overview dashboard) | `shop.js` | Account summary stats (total orders, total spend, saved items); quick link buttons |
| `/account/orders` | `orders`, `order_items` (subquery count) | `account/_layout.php`, `header.php`, `footer.php` | None (paginated list) | `shop.js` | Status badge styling; pagination controls; empty orders state |
| `/account/order` | `orders` (verified by customer_id), `order_items`, `order_status_history` | `account/_layout.php`, `header.php`, `footer.php` | Cancel order form (POST `route('account_order')`, `action=cancel`, `order_id`, `_csrf`) | `shop.js` | Customer cancellation allowed only if order status is `pending`; timeline status history list |
| `/account/invoice` | `orders`, `order_items`, `settings` | Printable standalone invoice | None | Inline JS `window.print()` | Printable A4 invoice layout; company VAT/tax info; auto-print script trigger |
| `/account/addresses` | `customer_addresses` | `account/_layout.php`, `header.php`, `footer.php` | Address CRUD form (POST `route('account_addresses')`, `action`, `id`, `label`, `full_name`, `phone`, `address`, `city`, `area`, `zip`, `is_default`, `_csrf`) | `shop.js` | 10 address limit check; default badge pill; delete confirmation prompt |
| `/account/profile` | `customers` | `account/_layout.php`, `header.php`, `footer.php` | Profile form (POST `route('account_profile')`, `name`, `phone`, `avatar` [file], `marketing_opt_in`, `_csrf`) | `shop.js` | Avatar preview image; file upload validation; error alerts |
| `/account/security` | `customers` | `account/_layout.php`, `header.php`, `footer.php` | Password change form (POST `route('account_security')`, `current_password`, `new_password`, `confirm_password`, `_csrf`) | `auth.js` | Current password verification; active sessions revocation option |
| `/account/wishlist` | `wishlists`, `products`, `product_variants` | `account/_layout.php`, `header.php`, `footer.php`, `product_card.php` | Remove form (POST `api/wishlist.php`, `product_id`, `_csrf`) | `shop.js` | Grid of saved products; Add to Cart integration; empty state view |
| `/account/export` | `customers`, `customer_addresses`, `orders`, `wishlists` | `account/_layout.php`, `header.php`, `footer.php` | Password confirm form (POST `route('account_export')`, `password`, `_csrf`) | None | Re-authenticates password before streaming JSON file download |
| `/account/delete` | `customers`, `orders` (orders count) | `account/_layout.php`, `header.php`, `footer.php` | Account deletion form (POST `route('account_delete')`, `password`, `confirm_text`, `_csrf`) | None | Soft-anonymization: renames customer to `Deleted Customer`, updates email to `deleted+id@invalid`, cleans tokens/addresses |

---

### Shared Layout Partials Directory

| Partial File Path | Purpose & Output | Consuming Pages |
| :--- | :--- | :--- |
| `includes/partials/header.php` | Storefront header shim wrapping head, Coza header, and mini-cart | Included by all storefront and account pages |
| `includes/partials/footer.php` | Storefront footer shim delegating to Coza footer and script tags | Included by all storefront and account pages |
| `includes/layout/coza/head.php` | HTML `<head>`, meta tags, favicon, fonts, vendor CSS | Included by `header.php` |
| `includes/layout/coza/header.php` | Desktop navbar, mobile hamburger, announcement bar, cart badge | Included by `header.php` |
| `includes/layout/coza/mini_cart.php` | HTML drawer shell for mini-cart drawer | Included by `header.php` |
| `includes/layout/coza/footer.php` | Global footer links, category links, newsletter widget, copyright | Included by `footer.php` |
| `includes/layout/coza/scripts.php` | Vendor scripts (jQuery, bootstrap, slick) and application `shop.js` | Included by `footer.php` |
| `includes/partials/product_card.php` | Product grid item card: image, hover flip, swatches, quick add | `index.php`, `shop/index.php`, `shop/category.php`, `account/wishlist.php` |
| `includes/partials/breadcrumb.php` | Breadcrumb navigation trail (`Home > Shop > Category`) | `shop/category.php`, `shop/product.php`, CMS pages |
| `includes/partials/review_stars.php` | Star rating SVGs (full, half, empty) for ratings | `product_card.php`, `shop/product.php` |
| `includes/partials/empty_state.php` | Standardized empty catalog / empty orders illustration placeholder | `shop/index.php`, `account/orders.php`, `account/wishlist.php` |
| `account/_layout.php` | Sidebar navigation tabs for customer account portal | All 10 views in `account/*.php` |

---

### Shared Header Partial In Full (`includes/layout/coza/header.php`)

```php
<?php
$categories = shop_active_categories();
$cartCnt = cart_count();
$wishlistCnt = function_exists('wishlist_count') ? wishlist_count() : 0;
$headerCustomer = function_exists('customer_current') ? customer_current() : null;
$siteBrand = trim((string)setting('site_name', ''));
if ($siteBrand === '' || $siteBrand === 'PHP Admin Panel' || $siteBrand === 'Store') {
    $siteBrand = 'Jinish Potro';
}
$storeLogoUrl = store_logo_url();
$announcementText = trim((string)setting('announcement_bar_text', ''));
$announcementEnabled = (int)setting('announcement_bar_enabled', '0') === 1 && $announcementText !== '';
?>
	<!-- Header -->
	<header class="<?= !empty($headerClass) ? htmlspecialchars($headerClass, ENT_QUOTES, 'UTF-8') : 'header-v4' ?>">
		<!-- Header desktop -->
		<div class="container-menu-desktop">
			<!-- Topbar -->
			<div class="top-bar">
				<div class="content-topbar flex-sb-m h-full container">
					<div class="left-top-bar">
						<?= htmlspecialchars($announcementEnabled ? $announcementText : setting('store_tagline', 'Quality products delivered to your door'), ENT_QUOTES, 'UTF-8') ?>
					</div>

					<div class="right-top-bar flex-w h-full">
						<a href="<?= htmlspecialchars(route('track'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m trans-04 p-lr-25 topbar-track-btn <?= route_is('track') ? 'active' : '' ?>" title="Track Order">
							<i class="fa fa-truck m-r-6"></i> Track Order
						</a>

						<?php if ($headerCustomer): ?>
							<a href="<?= htmlspecialchars(route('account'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m trans-04 p-lr-25">
								<i class="fa fa-user m-r-6"></i> <?= htmlspecialchars($headerCustomer['name'], ENT_QUOTES, 'UTF-8') ?>
							</a>
							<a href="<?= htmlspecialchars(route('logout'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m trans-04 p-lr-25 text-danger">
								Sign Out
							</a>
						<?php else: ?>
							<a href="<?= htmlspecialchars(route('login'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m trans-04 p-lr-25 <?= route_is('login') ? 'active' : '' ?>">
								Sign In
							</a>
							<a href="<?= htmlspecialchars(route('register'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m trans-04 p-lr-25 <?= route_is('register') ? 'active' : '' ?>">
								Sign Up
							</a>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="wrap-menu-desktop how-shadow1">
				<nav class="limiter-menu-desktop container">
					
					<!-- Logo desktop -->		
					<a href="<?= htmlspecialchars(shop_url(), ENT_QUOTES, 'UTF-8') ?>" class="logo">
						<?php if ($storeLogoUrl): ?>
							<img src="<?= htmlspecialchars($storeLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($siteBrand, ENT_QUOTES, 'UTF-8') ?>" style="max-height: 40px; width: auto; object-fit: contain;">
						<?php else: ?>
							<span class="brand-text-logo"><?= htmlspecialchars($siteBrand, ENT_QUOTES, 'UTF-8') ?><span class="brand-dot">.</span></span>
						<?php endif; ?>
					</a>

					<!-- Menu desktop -->
					<div class="menu-desktop">
						<ul class="main-menu">
							<li class="<?= route_is('home') ? 'active-menu' : '' ?>">
								<a href="<?= htmlspecialchars(route('home'), ENT_QUOTES, 'UTF-8') ?>">Home</a>
							</li>

							<li class="<?= route_is('shop') ? 'active-menu' : '' ?>">
								<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>">Shop<?php if (!empty($categories)): ?> <i class="fa fa-angle-down m-l-4" style="font-size: 11px; opacity: 0.7;"></i><?php endif; ?></a>
								<?php if (!empty($categories)): ?>
									<ul class="sub-menu">
										<li><a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>">All Categories</a></li>
										<?php foreach ($categories as $cat): ?>
											<li>
												<a href="<?= htmlspecialchars(route('category', ['slug' => $cat['slug']]), ENT_QUOTES, 'UTF-8') ?>">
													<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
												</a>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</li>
						</ul>
					</div>	

					<!-- Icon header -->
					<div class="wrap-icon-header flex-w flex-r-m">
						<div class="icon-header-item cl2 hov-cl1 trans-04 p-l-22 p-r-11 js-show-modal-search" title="Search">
							<i class="zmdi zmdi-search"></i>
						</div>

						<div class="icon-header-item cl2 hov-cl1 trans-04 p-l-22 p-r-11 icon-header-noti js-show-cart" data-notify="<?= (int)$cartCnt ?>" title="Cart">
							<i class="zmdi zmdi-shopping-cart"></i>
						</div>

						<a href="<?= htmlspecialchars(route('account_wishlist'), ENT_QUOTES, 'UTF-8') ?>" class="dis-block icon-header-item cl2 hov-cl1 trans-04 p-l-22 p-r-11 icon-header-noti" data-notify="<?= (int)$wishlistCnt ?>" title="Wishlist">
							<i class="zmdi zmdi-favorite-outline"></i>
						</a>

						<a href="<?= htmlspecialchars(!empty($headerCustomer) ? route('account') : route('login'), ENT_QUOTES, 'UTF-8') ?>" class="dis-block icon-header-item cl2 hov-cl1 trans-04 p-l-22 p-r-11" title="Account">
							<i class="zmdi zmdi-account"></i>
						</a>

						<div class="icon-header-item cl2 hov-cl1 trans-04 p-l-22 p-r-11 pointer" data-theme-toggle title="Toggle Dark/Light Mode" role="button" aria-label="Toggle Dark/Light Mode" style="cursor: pointer;">
							<i class="fa fa-sun-o theme-icon-sun"></i>
							<i class="fa fa-moon-o theme-icon-moon"></i>
						</div>
					</div>
				</nav>
			</div>	
		</div>

		<!-- Header Mobile -->
		<div class="wrap-header-mobile">
			<!-- Logo mobile -->		
			<div class="logo-mobile">
				<a href="<?= htmlspecialchars(shop_url(), ENT_QUOTES, 'UTF-8') ?>">
					<?php if ($storeLogoUrl): ?>
						<img src="<?= htmlspecialchars($storeLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($siteBrand, ENT_QUOTES, 'UTF-8') ?>" style="max-height: 34px; width: auto; object-fit: contain;">
					<?php else: ?>
						<span class="brand-text-logo"><?= htmlspecialchars($siteBrand, ENT_QUOTES, 'UTF-8') ?><span class="brand-dot">.</span></span>
					<?php endif; ?>
				</a>
			</div>

			<!-- Icon header mobile -->
			<div class="wrap-icon-header d-flex align-items-center flex-nowrap m-r-10">
				<div class="icon-header-item cl2 hov-cl1 trans-04 js-show-modal-search" title="Search">
					<i class="zmdi zmdi-search"></i>
				</div>

				<div class="icon-header-item cl2 hov-cl1 trans-04 icon-header-noti js-show-cart" data-notify="<?= (int)$cartCnt ?>" title="Cart">
					<i class="zmdi zmdi-shopping-cart"></i>
				</div>

				<a href="<?= htmlspecialchars(route('account_wishlist'), ENT_QUOTES, 'UTF-8') ?>" class="dis-block icon-header-item cl2 hov-cl1 trans-04 icon-header-noti" data-notify="<?= (int)$wishlistCnt ?>" title="Wishlist">
					<i class="zmdi zmdi-favorite-outline"></i>
				</a>

				<div class="icon-header-item cl2 hov-cl1 trans-04 pointer" data-theme-toggle title="Toggle Dark/Light Mode" role="button" aria-label="Toggle Dark/Light Mode" style="cursor: pointer;">
					<i class="fa fa-sun-o theme-icon-sun"></i>
					<i class="fa fa-moon-o theme-icon-moon"></i>
				</div>
			</div>

			<!-- Button show menu -->
			<div class="btn-show-menu-mobile hamburger hamburger--squeeze">
				<span class="hamburger-box">
					<span class="hamburger-inner"></span>
				</span>
			</div>
		</div>

		<!-- Menu Mobile -->
		<div class="menu-mobile">
			<ul class="topbar-mobile">
				<li>
					<div class="left-top-bar">
						<?= htmlspecialchars($announcementEnabled ? $announcementText : setting('store_tagline', 'Quality products delivered to your door'), ENT_QUOTES, 'UTF-8') ?>
					</div>
				</li>

				<li>
					<div class="right-top-bar flex-w h-full">
						<a href="<?= htmlspecialchars(route('track'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m p-lr-10 trans-04 <?= route_is('track') ? 'active' : '' ?>">
							<i class="fa fa-truck m-r-5"></i> Track Order
						</a>

						<?php if ($headerCustomer): ?>
							<a href="<?= htmlspecialchars(route('account'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m p-lr-10 trans-04">
								My Account
							</a>
							<a href="<?= htmlspecialchars(route('logout'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m p-lr-10 trans-04 text-danger">
								Sign Out
							</a>
						<?php else: ?>
							<a href="<?= htmlspecialchars(route('login'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m p-lr-10 trans-04 <?= route_is('login') ? 'active' : '' ?>">
								Sign In
							</a>
							<a href="<?= htmlspecialchars(route('register'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m p-lr-10 trans-04 <?= route_is('register') ? 'active' : '' ?>">
								Sign Up
							</a>
						<?php endif; ?>
					</div>
				</li>
			</ul>

			<ul class="main-menu-m">
				<li class="<?= route_is('home') ? 'active-menu' : '' ?>">
					<a href="<?= htmlspecialchars(route('home'), ENT_QUOTES, 'UTF-8') ?>">Home</a>
				</li>

				<li class="<?= route_is('shop') ? 'active-menu' : '' ?>">
					<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>">Shop</a>
					<?php if (!empty($categories)): ?>
						<ul class="sub-menu-m">
							<li><a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>">All Products</a></li>
							<?php foreach ($categories as $cat): ?>
								<li>
									<a href="<?= htmlspecialchars(route('category', ['slug' => $cat['slug']]), ENT_QUOTES, 'UTF-8') ?>">
										<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
						<span class="arrow-main-menu-m">
							<i class="fa fa-angle-right" aria-hidden="true"></i>
						</span>
					<?php endif; ?>
				</li>

				<li class="<?= route_is('track') ? 'active-menu' : '' ?>">
					<a href="<?= htmlspecialchars(route('track'), ENT_QUOTES, 'UTF-8') ?>">Track Order</a>
				</li>
			</ul>
		</div>

		<!-- Modal Search -->
		<div class="modal-search-header flex-c-m trans-04 js-hide-modal-search">
			<div class="container-search-header">
				<button type="button" class="flex-c-m btn-hide-modal-search trans-04 js-hide-modal-search">
					<img src="<?= htmlspecialchars(url('assets/coza/images/icons/icon-close2.png'), ENT_QUOTES, 'UTF-8') ?>" alt="CLOSE">
				</button>

				<form action="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" method="get" class="wrap-search-header flex-w p-l-15">
					<button type="submit" class="flex-c-m trans-04">
						<i class="zmdi zmdi-search"></i>
					</button>
					<input class="plh3" type="text" name="q" placeholder="Search products..." value="<?= htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
				</form>
			</div>
		</div>
	</header>
```

---

### View Portion: Product Listing Page In Full (`storefront/shop/index.php:216-419`)

```php
include __DIR__ . '/../../includes/partials/header.php';
?>

<!-- =================== COZASTORE CATALOG CONTENT =================== -->
<div class="bg0 m-t-23 p-b-140">
	<div class="container">

		<!-- Category Filter Pills Bar & Action Controls -->
		<div class="flex-w flex-sb-m p-b-40">
			<div class="flex-w flex-l-m filter-tope-group m-tb-10">
				<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="stext-106 cl6 hov1 bor3 trans-04 m-r-32 m-tb-5 <?= empty($catSlug) ? 'how-active1' : '' ?>">
					All Products
				</a>

				<?php if (!empty($categoriesList)): ?>
					<?php foreach ($categoriesList as $cl): ?>
						<a href="<?= htmlspecialchars(route('category', ['slug' => $cl['slug']]), ENT_QUOTES, 'UTF-8') ?>" class="stext-106 cl6 hov1 bor3 trans-04 m-r-32 m-tb-5 <?= ($cl['slug'] === $catSlug) ? 'how-active1' : '' ?>">
							<?= htmlspecialchars($cl['name'], ENT_QUOTES, 'UTF-8') ?>
						</a>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div class="flex-w flex-c-m m-tb-10">
				<div class="flex-c-m stext-106 cl6 size-104 bor4 pointer hov-btn3 trans-04 m-r-8 m-tb-4 js-show-filter">
					<i class="icon-filter cl2 m-r-6 fs-15 trans-04 zmdi zmdi-filter-list"></i>
					<i class="icon-close-filter cl2 m-r-6 fs-15 trans-04 zmdi zmdi-close dis-none"></i>
					Filter
				</div>

				<div class="flex-c-m stext-106 cl6 size-105 bor4 pointer hov-btn3 trans-04 m-tb-4 js-show-search">
					<i class="icon-search cl2 m-r-6 fs-15 trans-04 zmdi zmdi-search"></i>
					<i class="icon-close-search cl2 m-r-6 fs-15 trans-04 zmdi zmdi-close dis-none"></i>
					Search
				</div>
			</div>
			
			<!-- Search product panel -->
			<div class="dis-none panel-search w-full p-t-10 p-b-15">
				<form action="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" method="GET" class="bor8 dis-flex p-l-15">
					<?php if ($catSlug !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($catSlug, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
					<?php if ($sort !== 'newest'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
					<button type="submit" class="size-113 flex-c-m fs-16 cl2 hov-cl1 trans-04">
						<i class="zmdi zmdi-search"></i>
					</button>
					<input class="mtext-107 cl2 size-114 plh2 p-r-15" type="text" name="q" placeholder="Search catalog..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
				</form>	
			</div>

			<!-- Filter Drawer panel -->
			<div class="dis-none panel-filter w-full p-t-10">
				<div class="wrap-filter flex-w bg6 w-full p-lr-40 p-t-27 p-lr-15-sm">
					<!-- Sort By Column -->
					<div class="filter-col1 p-r-15 p-b-27">
						<div class="mtext-102 cl2 p-b-15">
							Sort By
						</div>
						<?php
						$qSort = $baseQuery; unset($qSort['sort'], $qSort['page']);
						$buildSortUrl = fn($s) => '?' . http_build_query(array_merge($qSort, ['sort' => $s]));
						?>
						<ul>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildSortUrl('newest'), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= $sort === 'newest' ? 'filter-link-active' : '' ?>">
									Newness
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildSortUrl('best_selling'), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= $sort === 'best_selling' ? 'filter-link-active' : '' ?>">
									Popularity
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildSortUrl('price_asc'), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= $sort === 'price_asc' ? 'filter-link-active' : '' ?>">
									Price: Low to High
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildSortUrl('price_desc'), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= $sort === 'price_desc' ? 'filter-link-active' : '' ?>">
									Price: High to Low
								</a>
							</li>
						</ul>
					</div>

					<!-- Price Filter Column -->
					<div class="filter-col2 p-r-15 p-b-27">
						<div class="mtext-102 cl2 p-b-15">
							Price
						</div>
						<?php
						$qPrice = $baseQuery; unset($qPrice['min_price'], $qPrice['max_price'], $qPrice['page']);
						$buildPriceUrl = fn($min, $max = null) => '?' . http_build_query(array_merge($qPrice, array_filter(['min_price' => $min, 'max_price' => $max], fn($v) => $v !== null)));
						?>
						<ul>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars('?' . http_build_query($qPrice), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= ($minPrice === null && $maxPrice === null) ? 'filter-link-active' : '' ?>">
									All Prices
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildPriceUrl(0, 500), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= ($minPrice == 0 && $maxPrice == 500) ? 'filter-link-active' : '' ?>">
									<?= price(0) ?> - <?= price(500) ?>
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildPriceUrl(500, 1500), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= ($minPrice == 500 && $maxPrice == 1500) ? 'filter-link-active' : '' ?>">
									<?= price(500) ?> - <?= price(1500) ?>
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars($buildPriceUrl(1500), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= ($minPrice == 1500 && $maxPrice === null) ? 'filter-link-active' : '' ?>">
									<?= price(1500) ?>+
								</a>
							</li>
						</ul>
					</div>

					<!-- Availability Column -->
					<div class="filter-col3 p-r-15 p-b-27">
						<div class="mtext-102 cl2 p-b-15">
							Availability
						</div>
						<?php
						$qStock = $baseQuery; unset($qStock['in_stock'], $qStock['on_sale'], $qStock['page']);
						?>
						<ul>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars('?' . http_build_query(array_merge($baseQuery, ['in_stock' => $inStockOnly === '1' ? null : '1'])), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= $inStockOnly === '1' ? 'filter-link-active' : '' ?>">
									<i class="zmdi <?= $inStockOnly === '1' ? 'zmdi-check-square' : 'zmdi-square-o' ?> m-r-4"></i> In Stock Only
								</a>
							</li>
							<li class="p-b-6">
								<a href="<?= htmlspecialchars('?' . http_build_query(array_merge($baseQuery, ['on_sale' => $onSaleOnly === '1' ? null : '1'])), ENT_QUOTES, 'UTF-8') ?>" class="filter-link stext-106 trans-04 <?= $onSaleOnly === '1' ? 'filter-link-active' : '' ?>">
									<i class="zmdi <?= $onSaleOnly === '1' ? 'zmdi-check-square' : 'zmdi-square-o' ?> m-r-4"></i> On Sale Only
								</a>
							</li>
						</ul>
					</div>

					<!-- Categories Column -->
					<div class="filter-col4 p-b-27">
						<div class="mtext-102 cl2 p-b-15">
							Categories
						</div>
						<div class="flex-w p-t-4 m-r--5">
							<?php foreach ($categoriesList as $cl): ?>
								<a href="<?= htmlspecialchars(route('category', ['slug' => $cl['slug']]), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-107 cl6 size-301 bor7 p-lr-15 hov-tag1 trans-04 m-r-5 m-b-5 <?= $cl['slug'] === $catSlug ? 'bg1 cl0' : '' ?>">
									<?= htmlspecialchars($cl['name'], ENT_QUOTES, 'UTF-8') ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Active Filters Chips Bar -->
		<?php if (!empty($activeChips)): ?>
			<div class="flex-w flex-m p-b-30">
				<span class="stext-109 cl6 m-r-10">Active Filters:</span>
				<?php foreach ($activeChips as $chip): ?>
					<a href="<?= htmlspecialchars($chip['url'], ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-107 cl2 size-301 bor7 p-lr-12 hov-tag1 trans-04 m-r-8 m-b-5 bg6" style="border-radius: 20px;">
						<?= htmlspecialchars($chip['label'], ENT_QUOTES, 'UTF-8') ?>
						<i class="zmdi zmdi-close m-l-6 fs-12"></i>
					</a>
				<?php endforeach; ?>
				<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl1 hov-cl2 trans-04 m-l-10 m-b-5">
					Clear All
				</a>
			</div>
		<?php endif; ?>

		<!-- Product Grid -->
		<?php if (!empty($products)): ?>
			<div class="row isotope-grid">
				<?php foreach ($products as $p): ?>
					<?php include __DIR__ . '/../../includes/partials/product_card.php'; ?>
				<?php endforeach; ?>
			</div>

			<!-- Cozastore Numbered Circular Pagination -->
			<?= render_coza_pagination($page, $pages, $_GET) ?>
		<?php else: ?>
			<div class="row">
				<div class="col-12 text-center p-tb-80 empty-products-state">
					<div class="empty-state-icon m-b-20">
						<i class="zmdi zmdi-search-for cl6" style="font-size: 64px;"></i>
					</div>
					<h4 class="mtext-105 cl2 p-b-10 font-weight-bold">No products found</h4>
					<p class="stext-115 cl6 p-b-25" style="max-width: 520px; margin: 0 auto 25px auto;">
						We couldn't find any products matching your active filters. Try clearing some filters to see more items.
					</p>
					<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m stext-101 cl0 size-101 bg1 bor1 hov-btn1 p-lr-15 trans-04 m-auto" style="max-width: 220px; border-radius: 25px;">
						Clear Filters
					</a>
				</div>
			</div>
		<?php endif; ?>

	</div>
</div>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
```

---

### Dynamic UI Settings Matrix

| Setting Key | Where Stored | Where Read in Code | UI Impact & Effect |
| :--- | :--- | :--- | :--- |
| `theme_accent` | `settings` table | `includes/layout/coza/head.php:40` | Injects CSS variable `:root { --theme-accent: ... }` overriding brand primary color |
| `dark_mode_enabled` | `settings` table | `includes/layout/coza/head.php:55`, `header.php:104` | Adds `data-theme="dark"` attribute to `<html>` and renders header sun/moon toggle button |
| `announcement_bar_enabled` | `settings` table | `includes/layout/coza/header.php:12` | Conditionally reveals topbar announcement text banner |
| `announcement_bar_text` | `settings` table | `includes/layout/coza/header.php:22` | Text string rendered in top bar (e.g. "Free shipping on orders over ৳1500") |
| `home_show_hero` | `settings` table | `index.php:24` | Toggles rendering of full-width slick slider hero carousel |
| `home_show_categories` | `settings` table | `index.php:25` | Toggles rendering of 3-column category promotional banner cards |
| `home_show_new_arrivals` | `settings` table | `index.php:26` | Toggles rendering of latest catalog arrivals grid section |
| `home_show_best_sellers` | `settings` table | `index.php:27` | Toggles rendering of top 8 products from `product_sales_summary` |
| `home_show_featured` | `settings` table | `index.php:28` | Toggles rendering of featured products row |
| `home_show_reviews` | `settings` table | `index.php:29` | Toggles customer testimonials review carousel |
| `pretty_urls` | `settings` table | `includes/functions.php:127` | Switches internal route URL generator from `product.php?slug=X` to `/product/X` |
| `currency_symbol` | `settings` table | `includes/functions.php:626` | Prefix symbol rendered on all prices across storefront and invoices (default: `৳` or `$`) |
| `store_logo` | `settings` table | `includes/functions.php:672` | Header logo image path; displays text title if unset |
| `store_tagline` | `settings` table | `includes/layout/coza/header.php:22` | Secondary slogan displayed when announcement bar is disabled |

---

### Non-HTML Outputs Generation Summary
1. **Dynamic XML Sitemap (`storefront/seo/sitemap.php`)**:
   - Emits `Content-Type: application/xml; charset=utf-8`.
   - Generates `<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">`.
   - Queries `categories` and `products` where `status = 'active'`.
   - Formats ISO 8601 `<lastmod>` timestamps via `date('c', strtotime(updated_at))`.
2. **Robots Exclusion (`robots.txt`)**:
   - Plaintext file at web root.
   - Restricts crawlers from `/admin/`, `/api/`, `/scratch/`, and points to canonical sitemap location.
3. **Structured Data / JSON-LD (`storefront/shop/product.php:182-206`)**:
   - Injected into product detail page `<head>`.
   - `@type: "Product"`, `name`, `image`, `description`, `sku`, `offers` (`priceCurrency`, `price`, `availability`), `aggregateRating` (`ratingValue`, `reviewCount`).
4. **Printable Invoices (`account/invoice.php` & `admin/order_print.php`)**:
   - HTML markup paired with CSS `@media print` rules.
   - Suppresses navigation bars, sidebars, buttons, and backgrounds.
   - Formats A4 printable table with itemized lines, discounts, tax/shipping fees, and payment status.
   - Automatically invokes JavaScript `window.print()` upon render.

---


### View Portion: Product Detail Page In Full (`storefront/shop/product.php:175-1033`)

```php
include __DIR__ . '/../../includes/partials/header.php';
?>

<!-- Modern PDP Stylesheet -->
<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/css/pdp-modern.css'), ENT_QUOTES, 'UTF-8') ?>">

<!-- JSON-LD Structured Data for SEO -->
<script type="application/ld+json">
{
  "@context": "https://schema.org/",
  "@type": "Product",
  "name": <?= json_encode($product['name']) ?>,
  "image": <?= json_encode($mainImgUrl) ?>,
  "description": <?= json_encode(strip_tags($product['short_description'] ?: $product['description'] ?: '')) ?>,
  "sku": <?= json_encode($product['sku'] ?: (string)$productId) ?>,
  "offers": {
    "@type": "Offer",
    "url": <?= json_encode(route('product', ['slug' => $product['slug']])) ?>,
    "priceCurrency": "BDT",
    "price": <?= json_encode((float)($onSale ? $product['sale_price'] : $product['price'])) ?>,
    "availability": "<?= $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock' ?>",
    "itemCondition": "https://schema.org/NewCondition"
  }
  <?php if ($totalReviews > 0): ?>,
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "<?= number_format($avgRating, 1) ?>",
    "reviewCount": "<?= $totalReviews ?>"
  }
  <?php endif; ?>
}
</script>

<!-- Breadcrumb -->
<div class="container m-t-20 m-b-20">
	<div class="bread-crumb flex-w p-l-0 p-r-15 p-t-15 p-lr-0-lg">
		<a href="<?= htmlspecialchars(route('home'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
			Home
			<i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
		</a>

		<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
			Shop
			<i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
		</a>

		<?php if (!empty($product['category_name']) && !empty($product['category_slug'])): ?>
			<a href="<?= htmlspecialchars(route('category', ['slug' => $product['category_slug']]), ENT_QUOTES, 'UTF-8') ?>" class="stext-109 cl8 hov-cl1 trans-04">
				<?= htmlspecialchars($product['category_name'], ENT_QUOTES, 'UTF-8') ?>
				<i class="fa fa-angle-right m-l-9 m-r-10" aria-hidden="true"></i>
			</a>
		<?php endif; ?>

		<span class="stext-109 cl4">
			<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>
		</span>
	</div>
</div>

<!-- =================== CLEAN & MODERN PRODUCT DETAIL SHOWCASE =================== -->
<section class="sec-product-detail bg0 p-t-20 p-b-60">
	<div class="container">
		<div class="row">
			<!-- Gallery Column (Left) -->
			<div class="col-lg-6 p-b-30">
				<div class="pdp-gallery-card">
					<div class="pdp-main-image-wrap pos-relative">
						<?php if ($onSale): ?>
							<span class="pdp-badge-discount">-<?= (int)$discount ?>% OFF</span>
						<?php endif; ?>

						<a class="pdp-zoom-btn js-gallery-zoom" href="<?= htmlspecialchars($mainImgUrl, ENT_QUOTES, 'UTF-8') ?>" title="Zoom Image">
							<i class="zmdi zmdi-fullscreen"></i>
						</a>

						<img id="pdpMainImg" src="<?= htmlspecialchars($mainImgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>" class="pdp-main-img">
					</div>

					<?php if (count($gallery) > 1): ?>
						<div class="pdp-thumbnails-strip">
							<?php foreach ($gallery as $idx => $img): 
								$gUrl = product_image_url($img['filename'] ?? null, 'large', $productId);
								$tUrl = product_image_url($img['filename'] ?? null, 'thumbnail', $productId);
							?>
							<div class="pdp-thumb-item <?= $idx === 0 ? 'active' : '' ?>" data-large="<?= htmlspecialchars($gUrl, ENT_QUOTES, 'UTF-8') ?>" onclick="switchPdpImage(this, '<?= htmlspecialchars($gUrl, ENT_QUOTES, 'UTF-8') ?>')">
								<img src="<?= htmlspecialchars($tUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>">
							</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
				
			<!-- Product Info & Purchase Column (Right) -->
			<div class="col-lg-6 p-b-30">
				<div class="pdp-info-card">
					<?php if (!empty($product['category_name']) && !empty($product['category_slug'])): ?>
						<div class="m-b-8">
							<a href="<?= htmlspecialchars(route('category', ['slug' => $product['category_slug']]), ENT_QUOTES, 'UTF-8') ?>" class="pdp-category-pill">
								<?= htmlspecialchars($product['category_name'], ENT_QUOTES, 'UTF-8') ?>
							</a>
						</div>
					<?php endif; ?>

					<h1 class="pdp-product-title">
						<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>
					</h1>

					<div class="pdp-meta-row">
						<div class="d-flex align-items-center">
							<span class="pdp-stars">
								<?php for ($i = 1; $i <= 5; $i++): ?>
									<i class="zmdi <?= $avgRating >= $i ? 'zmdi-star' : 'zmdi-star-outline' ?>"></i>
								<?php endfor; ?>
							</span>
							<span class="pdp-rating-num"><?= $totalReviews > 0 ? number_format($avgRating, 1) : '5.0' ?></span>
							<a href="#reviews" class="pdp-reviews-link" onclick="$('#tabLinkReviews').tab('show');">(<?= $totalReviews ?> review<?= $totalReviews === 1 ? '' : 's' ?>)</a>
						</div>

						<span class="pdp-stock-badge <?= $inStock ? 'in-stock' : 'out-of-stock' ?>" id="pdpStockBadge">
							<i class="zmdi <?= $inStock ? 'zmdi-check-circle' : 'zmdi-alert-circle-o' ?> m-r-4"></i>
							<?= $inStock ? 'In Stock: ' . (int)$currentStock : 'Sold Out' ?>
						</span>

						<?php if (!empty($product['sku'])): ?>
							<span class="pdp-sku-badge">SKU: <strong><?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?></strong></span>
						<?php endif; ?>
					</div>

					<!-- Pricing Box -->
					<div class="pdp-price-box">
						<?php if ($onSale): ?>
							<span class="pdp-price-current" id="pdpPrice"><?= price((float)$product['sale_price']) ?></span>
							<span class="pdp-price-original" id="pdpOldPrice"><?= price((float)$product['price']) ?></span>
							<span class="pdp-discount-tag">Save <?= (int)$discount ?>%</span>
						<?php else: ?>
							<span class="pdp-price-current" id="pdpPrice"><?= price((float)$product['price']) ?></span>
						<?php endif; ?>
					</div>

					<?php if (!empty($product['short_description']) && trim((string)$product['short_description']) !== trim((string)$product['description'])): ?>
						<p class="pdp-short-desc">
							<?= htmlspecialchars($product['short_description'], ENT_QUOTES, 'UTF-8') ?>
						</p>
					<?php endif; ?>

					<!-- Purchase Form -->
					<form method="post" action="<?= htmlspecialchars(route('cart'), ENT_QUOTES, 'UTF-8') ?>" id="pdpAddToCartForm">
						<?= csrf_field() ?>
						<input type="hidden" name="action" value="add">
						<input type="hidden" name="product_id" value="<?= $productId ?>">
						<input type="hidden" name="variant_id" id="selectedVariantId" value="<?= ($isVariable && empty($options) && !empty($variants[0]['id'])) ? (int)$variants[0]['id'] : '' ?>">

						<!-- Variant Option Selectors -->
						<?php if ($isVariable && !empty($options)): ?>
							<div class="pdp-options-wrap m-b-20">
								<?php foreach ($options as $opt): 
									$dispType = $opt['display_type'] ?? 'button';
									$optId = (int)$opt['id'];
								?>
									<div class="pdp-option-group m-b-18" data-option-id="<?= $optId ?>">
										<div class="d-flex align-items-center justify-content-between mb-2">
											<label class="pdp-option-label fw-bold mb-0 text-dark">
												<?= htmlspecialchars($opt['name'], ENT_QUOTES, 'UTF-8') ?>:
												<span class="pdp-selected-val text-primary fw-semibold ms-1" id="opt_label_<?= $optId ?>"></span>
											</label>
										</div>

										<?php if ($dispType === 'swatch'): ?>
											<!-- Color Swatch UI -->
											<div class="pdp-swatch-list d-flex flex-wrap" style="gap: 12px; margin-top: 8px; margin-bottom: 8px;">
												<?php foreach ($opt['values'] as $val): 
													$hex = !empty($val['swatch_hex']) ? $val['swatch_hex'] : '#3b82f6';
													$isWhite = in_array(strtolower($hex), ['#fff', '#ffffff', 'white', '#fafafa', '#f8fafc', '#f1f5f9']);
												?>
													<button type="button" 
													        class="pdp-swatch-btn js-pdp-opt-btn"
													        data-option-id="<?= $optId ?>"
													        data-val-id="<?= (int)$val['id'] ?>"
													        data-val-name="<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>"
													        title="<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>"
													        style="background-color: <?= htmlspecialchars($hex, ENT_QUOTES, 'UTF-8') ?>; width: 44px; height: 44px; min-width: 44px; min-height: 44px; border-radius: 50%; <?= $isWhite ? 'border: 2px solid #cbd5e1 !important;' : '' ?>">
														<i class="zmdi zmdi-check pdp-swatch-check <?= $isWhite ? 'text-dark' : 'text-white' ?>" style="display: none; font-size: 20px;"></i>
													</button>
												<?php endforeach; ?>
											</div>
											<input type="hidden" class="option-select" data-option-id="<?= $optId ?>" value="">

										<?php elseif ($dispType === 'button'): ?>
											<!-- Buttons Pill UI -->
											<div class="pdp-pill-list d-flex flex-wrap gap-2">
												<?php foreach ($opt['values'] as $val): ?>
													<button type="button"
													        class="pdp-pill-btn js-pdp-opt-btn"
													        data-option-id="<?= $optId ?>"
													        data-val-id="<?= (int)$val['id'] ?>"
													        data-val-name="<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>">
														<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>
													</button>
												<?php endforeach; ?>
											</div>
											<input type="hidden" class="option-select" data-option-id="<?= $optId ?>" value="">

										<?php elseif ($dispType === 'radio'): ?>
											<!-- Radio UI -->
											<div class="pdp-radio-list d-flex flex-wrap gap-3">
												<?php foreach ($opt['values'] as $val): ?>
													<label class="pdp-radio-pill d-inline-flex align-items-center gap-1 cursor-pointer">
														<input type="radio" 
														       name="opt_radio_<?= $optId ?>"
														       class="form-check-input js-pdp-radio-val"
														       data-option-id="<?= $optId ?>"
														       data-val-id="<?= (int)$val['id'] ?>"
														       data-val-name="<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>">
														<span class="small fw-semibold ms-1"><?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?></span>
													</label>
												<?php endforeach; ?>
											</div>
											<input type="hidden" class="option-select" data-option-id="<?= $optId ?>" value="">

										<?php else: ?>
											<!-- Dropdown UI -->
											<div class="rs1-select2 bor8 bg0" style="border-radius: 8px;">
												<select class="js-select2 option-select" data-option-id="<?= $optId ?>">
													<option value="">Choose <?= htmlspecialchars($opt['name'], ENT_QUOTES, 'UTF-8') ?></option>
													<?php foreach ($opt['values'] as $val): ?>
														<option value="<?= (int)$val['id'] ?>">
															<?= htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8') ?>
														</option>
													<?php endforeach; ?>
												</select>
												<div class="dropDownSelect2"></div>
											</div>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<!-- Quantity Controls, Add To Cart & Buy Now Buttons -->
						<div class="pdp-actions-panel">
							<div class="pdp-actions-row">
								<div class="pdp-quantity-stepper">
									<button type="button" class="btn-num-product-down pdp-qty-btn" aria-label="Decrease quantity">
										<i class="fs-16 zmdi zmdi-minus"></i>
									</button>

									<input class="num-product pdp-qty-val" type="number" name="quantity" id="pdpQtyInput" value="1" min="1" max="<?= max(1, $currentStock) ?>">

									<button type="button" class="btn-num-product-up pdp-qty-btn" aria-label="Increase quantity">
										<i class="fs-16 zmdi zmdi-plus"></i>
									</button>
								</div>

								<!-- Add to Cart (AJAX - stays on page) -->
								<button type="button" class="pdp-btn-cart" id="pdpAddBtn" <?= !$inStock ? 'disabled' : '' ?>>
									<i class="zmdi zmdi-shopping-cart-plus m-r-8 fs-18"></i> Add to Cart
								</button>

								<!-- Buy Now (Direct Checkout) -->
								<button type="submit" name="action_type" value="buy_now" class="pdp-btn-buy" id="pdpBuyNowBtn" <?= !$inStock ? 'disabled' : '' ?>>
									<i class="zmdi zmdi-flash m-r-8 fs-18"></i> Buy Now
								</button>

								<!-- Wishlist Button -->
								<button type="button" class="pdp-btn-wish" onclick="window.cozaToggleWishlist(<?= $productId ?>, this); return false;" title="Add to Wishlist" aria-label="Add to Wishlist">
									<i class="zmdi zmdi-favorite-outline fs-20"></i>
								</button>
							</div>
						</div>	
					</form>

					<!-- Product Description (Immediately after Add to Cart & Buy Now) -->
					<?php
					$pdpDesc = trim((string)($product['description'] ?? ''));
					if ($pdpDesc === '') {
						$pdpDesc = trim((string)($product['short_description'] ?? ''));
					}
					if ($pdpDesc === '') {
						$pdpDesc = 'Crafted with premium materials and verified for enduring durability.';
					}
					?>
					<div class="pdp-description-box">
						<h5 class="pdp-desc-heading">Product Description</h5>
						<div class="pdp-desc-text">
							<?php
							if (strip_tags($pdpDesc) !== $pdpDesc) {
								echo strip_tags($pdpDesc, '<p><br><ul><ol><li><strong><em><b><i><h2><h3><h4><span><a>');
							} else {
								echo nl2br(htmlspecialchars($pdpDesc, ENT_QUOTES, 'UTF-8'));
							}
							?>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Details Tabs (Specifications, Reviews) -->
		<div class="pdp-tabs-wrap">
			<ul class="nav pdp-tabs-nav" role="tablist">
				<li class="nav-item">
					<a class="pdp-tab-item active" data-toggle="tab" href="#information" role="tab" id="tabLinkInfo">Specifications</a>
				</li>

				<li class="nav-item">
					<a class="pdp-tab-item" data-toggle="tab" href="#reviews" role="tab" id="tabLinkReviews">Verified Reviews (<?= $totalReviews ?>)</a>
				</li>
			</ul>

			<!-- Tab panes -->
			<div class="tab-content">
				<!-- Tab 1: Specifications -->
				<div class="tab-pane fade show active" id="information" role="tabpanel">
					<div class="col-lg-8 m-lr-auto">
						<div class="table-responsive pdp-spec-table-wrap">
							<table class="table table-borderless pdp-spec-table">
								<tbody>
									<tr>
										<td class="pdp-spec-label">Product SKU</td>
										<td class="pdp-spec-value"><?= htmlspecialchars($product['sku'] ?: 'PROD-' . str_pad((string)$productId, 5, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?></td>
									</tr>
									<tr>
										<td class="pdp-spec-label">Product Type</td>
										<td class="pdp-spec-value"><?= ucfirst(htmlspecialchars($product['product_type'], ENT_QUOTES, 'UTF-8')) ?></td>
									</tr>
									<?php if (!empty($product['category_name'])): ?>
									<tr>
										<td class="pdp-spec-label">Category</td>
										<td class="pdp-spec-value"><?= htmlspecialchars($product['category_name'], ENT_QUOTES, 'UTF-8') ?></td>
									</tr>
									<?php endif; ?>
									<?php if (!empty($product['brand'])): ?>
									<tr>
										<td class="pdp-spec-label">Brand</td>
										<td class="pdp-spec-value"><?= htmlspecialchars($product['brand'], ENT_QUOTES, 'UTF-8') ?></td>
									</tr>
									<?php endif; ?>
									<tr>
										<td class="pdp-spec-label">Authenticity</td>
										<td class="pdp-spec-value">100% Original and Brand New</td>
									</tr>
									<tr>
										<td class="pdp-spec-label">Delivery Time</td>
										<td class="pdp-spec-value">24h inside Dhaka, 48-72h outside Dhaka</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<!-- Tab 2: Reviews -->
				<div class="tab-pane fade" id="reviews" role="tabpanel">
					<div class="col-lg-8 m-lr-auto">
						<div class="p-b-30">
							<!-- Review list -->
							<?php if (!empty($reviews)): ?>
								<?php foreach ($reviews as $rev): ?>
									<div class="p-all-20 m-b-20 bg0" style="border: 1px solid #eef0f3; border-radius: 12px;">
										<div class="flex-w flex-sb-m p-b-10">
											<div class="flex-m">
												<div class="wrap-pic-s size-109 bor0 of-hidden m-r-12">
													<img src="<?= htmlspecialchars(avatar_url($rev['author_name']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($rev['author_name'], ENT_QUOTES, 'UTF-8') ?>" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover;">
												</div>
												<div>
													<span class="font-weight-bold cl2 d-block" style="font-size: 15px;">
														<?= htmlspecialchars($rev['author_name'], ENT_QUOTES, 'UTF-8') ?>
													</span>
													<span class="badge" style="background: #ecfdf5; color: #059669; font-size: 11px; font-weight: 600;">Verified Customer</span>
												</div>
											</div>

											<span class="fs-14" style="color: #f59e0b;">
												<?php for ($i = 1; $i <= (int)$rev['rating']; $i++): ?>
													<i class="zmdi zmdi-star"></i>
												<?php endfor; ?>
											</span>
										</div>

										<h5 class="font-weight-bold cl2 m-b-6" style="font-size: 15px;">
											<?= htmlspecialchars($rev['title'], ENT_QUOTES, 'UTF-8') ?>
										</h5>

										<p class="stext-102 cl6" style="line-height: 1.6;">
											<?= nl2br(htmlspecialchars($rev['body'], ENT_QUOTES, 'UTF-8')) ?>
										</p>

										<?php if (!empty($rev['admin_reply'])): ?>
											<div class="p-all-15 m-t-15 bg6" style="border-left: 3px solid #111; border-radius: 6px;">
												<span class="stext-102 font-weight-bold cl2 d-block">Store Response:</span>
												<span class="stext-102 cl6"><?= nl2br(htmlspecialchars($rev['admin_reply'], ENT_QUOTES, 'UTF-8')) ?></span>
											</div>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							<?php else: ?>
								<div class="text-center p-tb-30 text-muted bg0 p-all-30 m-b-25" style="border: 1px dashed #d1d5db; border-radius: 12px;">
									<i class="zmdi zmdi-comment-text-alt fs-36 d-block m-b-10" style="color: #9ca3af; font-size: 40px;"></i>
									<p class="stext-102 cl6 mb-0">No customer reviews yet. Be the first to share your experience!</p>
								</div>
							<?php endif; ?>
							
							<!-- Add review form -->
							<div class="bg0 p-all-25 m-t-20" style="border: 1px solid #eef0f3; border-radius: 14px;">
								<form class="w-full" id="writeReviewForm" method="post" action="<?= htmlspecialchars(url('api/review.php'), ENT_QUOTES, 'UTF-8') ?>">
									<?= csrf_field() ?>
									<input type="hidden" name="product_id" value="<?= $productId ?>">
									<input type="hidden" name="form_time" value="<?= time() ?>">
									<div style="display:none;"><input type="text" name="website_hp" value=""></div>

									<h5 class="mtext-108 cl2 p-b-15 font-weight-bold">
										Write a Customer Review
									</h5>

									<div class="flex-w flex-m p-b-20">
										<span class="stext-102 cl3 m-r-16 font-weight-bold">
											Your Rating:
										</span>

										<span class="wrap-rating fs-18 cl11 pointer" style="cursor: pointer;">
											<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
											<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
											<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
											<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
											<i class="item-rating pointer zmdi zmdi-star-outline" style="color: #f59e0b;"></i>
											<input class="dis-none" type="number" name="rating" value="5" required>
										</span>
									</div>

									<div class="row p-b-15">
										<div class="col-12 p-b-15">
											<label class="stext-102 cl3 font-weight-bold" for="reviewTitle">Review Title</label>
											<input class="size-111 bor8 stext-102 cl2 p-lr-20" id="reviewTitle" type="text" name="title" placeholder="e.g. Excellent quality, fast delivery!" required maxlength="120" style="border-radius: 8px;">
										</div>

										<div class="col-12 p-b-15">
											<label class="stext-102 cl3 font-weight-bold" for="review">Your Review Details</label>
											<textarea class="size-110 bor8 stext-102 cl2 p-lr-20 p-tb-10" id="review" name="body" placeholder="Share your experience with this product..." required minlength="10" maxlength="2000" style="border-radius: 8px; height: 110px;"></textarea>
										</div>

										<div class="col-sm-6 p-b-15">
											<label class="stext-102 cl3 font-weight-bold" for="name">Your Name</label>
											<input class="size-111 bor8 stext-102 cl2 p-lr-20" id="name" type="text" name="author_name" value="<?= htmlspecialchars(function_exists('customer_current') && customer_current() ? customer_current()['name'] : '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Your name" style="border-radius: 8px;">
										</div>
									</div>

									<button type="submit" id="submitReviewBtn" class="flex-c-m stext-101 cl0 size-112 bg3 bor1 hov-btn3 p-lr-25 trans-04 pointer" style="border-radius: 8px; font-weight: 700; height: 46px;">
										Submit Review
									</button>
								</form>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Related Products Carousel -->
	<?php if (!empty($related)): ?>
	<div class="bg6 m-t-60 p-t-50 p-b-70">
		<div class="container">
			<div class="p-b-35 text-center">
				<h3 class="ltext-103 cl5 font-weight-bold">
					Related Products
				</h3>
			</div>

			<div class="row isotope-grid">
				<?php foreach ($related as $p): ?>
					<?php include __DIR__ . '/../../includes/partials/product_card.php'; ?>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php endif; ?>
</section>

<!-- Switch PDP Main Image Function & Enhanced Controls -->
<script>
function switchPdpImage(el, url) {
	var thumbs = document.querySelectorAll('.pdp-thumb-item');
	thumbs.forEach(function(item) { item.classList.remove('active'); });
	if (el) el.classList.add('active');
	var main = document.getElementById('pdpMainImg');
	if (main) main.src = url;
	var zoom = document.querySelector('.js-gallery-zoom');
	if (zoom) zoom.href = url;
}
</script>

<!-- PDP AJAX Add to Cart & Review Handler -->
<script>
document.addEventListener('DOMContentLoaded', function() {
	// Ensure no duplicate jQuery listeners from legacy template scripts
	if (typeof $ !== 'undefined') {
		$('.btn-num-product-down, .btn-num-product-up').off('click');
	}

	// Stepper increment / decrement and input validation handler
	var downBtn = document.querySelector('.btn-num-product-down');
	var upBtn = document.querySelector('.btn-num-product-up');
	var qtyInput = document.getElementById('pdpQtyInput');

	function validatePdpQty() {
		if (!qtyInput) return;
		var val = parseInt(qtyInput.value, 10);
		var max = parseInt(qtyInput.max, 10) || 999;
		var min = parseInt(qtyInput.min, 10) || 1;
		if (isNaN(val) || val < min) {
			qtyInput.value = min;
		} else if (val > max) {
			qtyInput.value = max;
			if (typeof swal === 'function') {
				swal('Stock Limit', 'Only ' + max + ' units available in stock.', 'warning');
			}
		}
	}

	if (qtyInput) {
		qtyInput.addEventListener('change', validatePdpQty);
		qtyInput.addEventListener('input', validatePdpQty);
	}

	if (downBtn && qtyInput) {
		downBtn.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			if (e.stopImmediatePropagation) e.stopImmediatePropagation();
			var current = parseInt(qtyInput.value, 10) || 1;
			var min = parseInt(qtyInput.min, 10) || 1;
			if (current > min) {
				qtyInput.value = current - 1;
				qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
				qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
			}
		});
	}
	if (upBtn && qtyInput) {
		upBtn.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			if (e.stopImmediatePropagation) e.stopImmediatePropagation();
			var current = parseInt(qtyInput.value, 10) || 1;
			var max = parseInt(qtyInput.max, 10) || 999;
			if (current < max) {
				qtyInput.value = current + 1;
				qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
				qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
			} else {
				if (typeof swal === 'function') {
					swal('Stock Limit', 'Only ' + max + ' units available in stock.', 'info');
				}
			}
		});
	}

	// Initialize Magnific Popup zoom on main image
	if (typeof $ !== 'undefined' && typeof $.fn.magnificPopup === 'function') {
		$('.js-gallery-zoom').magnificPopup({
			type: 'image',
			closeOnContentClick: true,
			mainClass: 'mfp-img-mobile',
			image: { verticalFit: true }
		});
	}

	// Interactive Button / Swatch Click Handler
	$('.js-pdp-opt-btn').on('click', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var optId = $btn.data('option-id');
		var valId = $btn.data('val-id');
		var valName = $btn.data('val-name');

		var $group = $btn.closest('.pdp-option-group');
		$group.find('.js-pdp-opt-btn').removeClass('active');
		$group.find('.pdp-swatch-check').hide();

		$btn.addClass('active');
		$btn.find('.pdp-swatch-check').show();

		$('#opt_label_' + optId).text(valName);

		var $hiddenInput = $group.find('input.option-select');
		$hiddenInput.val(valId).trigger('change');
	});

	// Radio Handler
	$('.js-pdp-radio-val').on('change', function() {
		var $radio = $(this);
		var optId = $radio.data('option-id');
		var valId = $radio.data('val-id');
		var valName = $radio.data('val-name');

		$('#opt_label_' + optId).text(valName);

		var $hiddenInput = $radio.closest('.pdp-option-group').find('input.option-select');
		$hiddenInput.val(valId).trigger('change');
	});

	// Real-Time Variant Option & Stock Updates
	var variantsData = <?= json_encode($variants) ?>;
	var totalOptionsCount = <?= count($options ?? []) ?>;
	if (variantsData && variantsData.length > 0) {
		$('.option-select').on('change', function() {
			var selectedOpts = {};
			$('.option-select').each(function() {
				var optId = $(this).data('option-id');
				var valId = parseInt($(this).val(), 10);
				if (valId) selectedOpts[optId] = valId;
			});

			var selectedCount = Object.keys(selectedOpts).length;
			var match = null;
			if (selectedCount === totalOptionsCount && totalOptionsCount > 0) {
				for (var i = 0; i < variantsData.length; i++) {
					var v = variantsData[i];
					var isMatch = true;
					for (var optId in selectedOpts) {
						if (v.option_value_ids && v.option_value_ids[optId] != selectedOpts[optId]) {
							isMatch = false;
							break;
						}
					}
					if (isMatch) {
						match = v;
						break;
					}
				}
			}

			var vIdInput = document.getElementById('selectedVariantId');
			var priceElem = document.getElementById('pdpPrice');
			var stockBadge = document.getElementById('pdpStockBadge');
			var addBtn = document.getElementById('pdpAddBtn');
			var buyBtn = document.getElementById('pdpBuyNowBtn');
			var qtyInput = document.getElementById('pdpQtyInput');

			if (match) {
				if (vIdInput) vIdInput.value = match.id;

				var pVal = parseFloat(match.sale_price || match.price || 0);
				if (priceElem && pVal > 0) {
					var curSym = <?= json_encode(setting('currency_symbol', '৳')) ?>;
					priceElem.textContent = curSym + pVal.toFixed(2);
				}

				var vStock = parseInt(match.stock, 10);
				if (vStock > 0) {
					if (stockBadge) {
						stockBadge.className = 'pdp-stock-badge in-stock';
						stockBadge.innerHTML = '<i class="zmdi zmdi-check-circle m-r-4"></i> In Stock: ' + vStock;
					}
					if (addBtn) addBtn.disabled = false;
					if (buyBtn) buyBtn.disabled = false;
					if (qtyInput) qtyInput.max = vStock;
				} else {
					if (stockBadge) {
						stockBadge.className = 'pdp-stock-badge out-of-stock';
						stockBadge.innerHTML = '<i class="zmdi zmdi-alert-circle-o m-r-4"></i> Sold Out';
					}
					if (addBtn) addBtn.disabled = true;
					if (buyBtn) buyBtn.disabled = true;
				}
			} else {
				if (vIdInput) vIdInput.value = '';
				if (stockBadge && totalOptionsCount > 0) {
					stockBadge.className = 'pdp-stock-badge out-of-stock';
					stockBadge.innerHTML = '<i class="zmdi zmdi-help-outline m-r-4"></i> Select Options';
				}
			}
		});

		// Auto-select first value for swatches and buttons on page load
		$('.pdp-option-group').each(function() {
			var $firstBtn = $(this).find('.js-pdp-opt-btn').first();
			if ($firstBtn.length) {
				$firstBtn.trigger('click');
			} else {
				var $firstRadio = $(this).find('.js-pdp-radio-val').first();
				if ($firstRadio.length) {
					$firstRadio.prop('checked', true).trigger('change');
				}
			}
		});
	}

	// AJAX Add to Cart (Stays on PDP, updates counter, triggers sweetalert)
	var addBtn = document.getElementById('pdpAddBtn');
	if (addBtn) {
		addBtn.addEventListener('click', function(e) {
			e.preventDefault();
			var isVar = <?= $isVariable ? 'true' : 'false' ?>;
			var vidInput = document.getElementById('selectedVariantId');
			var vid = vidInput ? vidInput.value : '';

			if (isVar && !vid) {
				if (typeof swal === 'function') {
					swal('Please Select Options', 'Please choose all available product options before adding to cart.', 'warning');
				} else {
					alert('Please choose all available product options before adding to cart.');
				}
				return;
			}

			var pid = <?= (int)$productId ?>;
			var max = parseInt(qtyInput ? qtyInput.max : 999, 10) || 999;
			var qty = parseInt(document.getElementById('pdpQtyInput').value, 10) || 1;
			if (qty > max) {
				if (typeof swal === 'function') {
					swal('Stock Limit', 'Only ' + max + ' units available in stock.', 'warning');
				}
				if (qtyInput) qtyInput.value = max;
				return;
			}
			var pName = <?= json_encode($product['name']) ?>;

			if (typeof window.cozaAddToCart === 'function') {
				window.cozaAddToCart(pid, qty, vid, pName);
			} else {
				var fd = new FormData();
				fd.append('action', 'add');
				fd.append('product_id', pid);
				if (vid) fd.append('variant_id', vid);
				fd.append('quantity', qty);
				fd.append('_csrf', window.SHOP_CSRF_TOKEN || '');
				fetch((window.SHOP_BASE_URL || '/') + 'api/cart_ajax.php', {
					method: 'POST',
					body: fd,
					headers: { 'X-Requested-With': 'XMLHttpRequest' }
				})
				.then(function(r) { return r.json(); })
				.then(function(res) {
					if (res && res.ok) {
						if (typeof window.cozaUpdateCartUI === 'function') {
							window.cozaUpdateCartUI(res);
						} else if (res.cart_count !== undefined) {
							$('.js-show-cart').attr('data-notify', res.cart_count);
						}
						if (typeof swal === 'function') {
							swal(pName, 'has been added to your cart!', 'success');
						} else {
							alert(pName + ' has been added to your cart!');
						}
					} else {
						var msg = (res && res.error) ? res.error : 'Could not add product to cart.';
						if (typeof swal === 'function') swal('Notice', msg, 'error');
						else alert(msg);
					}
				})
				.catch(function() {
					if (typeof swal === 'function') swal('Notice', 'Could not add to cart.', 'error');
				});
			}
		});
	}

	// Buy Now and Form submit stock limit protection
	var buyBtn = document.getElementById('pdpBuyNowBtn');
	if (buyBtn) {
		buyBtn.addEventListener('click', function(e) {
			var isVar = <?= $isVariable ? 'true' : 'false' ?>;
			var vidInput = document.getElementById('selectedVariantId');
			var vid = vidInput ? vidInput.value : '';
			if (isVar && !vid) {
				e.preventDefault();
				if (typeof swal === 'function') {
					swal('Please Select Options', 'Please choose all available product options before proceeding.', 'warning');
				} else {
					alert('Please choose all available product options before proceeding.');
				}
				return;
			}
			var max = parseInt(qtyInput ? qtyInput.max : 999, 10) || 999;
			var qty = parseInt(document.getElementById('pdpQtyInput').value, 10) || 1;
			if (qty > max) {
				e.preventDefault();
				if (typeof swal === 'function') {
					swal('Stock Limit', 'Only ' + max + ' units available in stock.', 'warning');
				}
				if (qtyInput) qtyInput.value = max;
				return;
			}
		});
	}

	var pdpForm = document.getElementById('pdpAddToCartForm');
	if (pdpForm) {
		pdpForm.addEventListener('submit', function(e) {
			var isVar = <?= $isVariable ? 'true' : 'false' ?>;
			var vidInput = document.getElementById('selectedVariantId');
			var vid = vidInput ? vidInput.value : '';
			if (isVar && !vid) {
				e.preventDefault();
				if (typeof swal === 'function') {
					swal('Please Select Options', 'Please choose all available product options before adding to cart.', 'warning');
				} else {
					alert('Please choose all available product options before adding to cart.');
				}
				return;
			}
			var max = parseInt(qtyInput ? qtyInput.max : 999, 10) || 999;
			var qty = parseInt(document.getElementById('pdpQtyInput').value, 10) || 1;
			if (qty > max) {
				e.preventDefault();
				if (typeof swal === 'function') {
					swal('Stock Limit', 'Only ' + max + ' units available in stock.', 'warning');
				}
				if (qtyInput) qtyInput.value = max;
				return;
			}
		});
	}

	var reviewForm = document.getElementById('writeReviewForm');
	if (reviewForm) {
		reviewForm.addEventListener('submit', function(e) {
			e.preventDefault();
			var btn = document.getElementById('submitReviewBtn');
			if (btn) {
				btn.disabled = true;
				btn.textContent = 'Submitting...';
			}

			var formData = new FormData(this);
			fetch(this.action, {
				method: 'POST',
				body: formData,
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (btn) {
					btn.disabled = false;
					btn.textContent = 'Submit Review';
				}
				if (data && data.success) {
					swal('Thank you!', data.message || 'Your review has been submitted for moderation.', 'success');
					reviewForm.reset();
				} else {
					var err = (data && data.error) ? data.error : 'Failed to submit review.';
					swal('Notice', err, 'error');
				}
			})
			.catch(function() {
				if (btn) {
					btn.disabled = false;
					btn.textContent = 'Submit Review';
				}
				swal('Error', 'Network error. Please try again.', 'error');
			});
		});
	}
});
</script>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>

```

---

## 9. Frontend JS contract

### 9.1 JavaScript Files in `assets/` and Network Contracts

| JS File Path | Network Action / Form Submit | Endpoint | HTTP Method | Request Format / Payload | Expected Response Shape | Error Handling Expected |
|---|---|---|---|---|---|---|
| `assets/js/shop.js` | Live Search Suggestions | `${baseUrl}api/search_suggest.php?q={query}` | `GET` | URL query parameter `q` | `{"query": string, "products": [{"id": int, "name": string, "slug": string, "price": string, "image": string, "url": string}], "categories": [{"id": int, "name": string, "slug": string, "url": string}], "total_results": int}` | `catch()` hides dropdown quietly; handles 0 results by displaying empty state message. |
| `assets/js/shop.js` | Mini-Cart Fetch | `${baseUrl}api/cart_ajax.php?action=get` | `GET` | URL query parameter `action=get` | `{"ok": bool, "cart": {"items": [...], "item_count": int, "subtotal": float, "subtotal_formatted": string, "empty": bool}}` | Logs error, displays toast notification on failure. |
| `assets/js/shop.js` | Mini-Cart Add Item | `${baseUrl}api/cart_ajax.php` | `POST` | `FormData`: `action=add`, `product_id=INT`, `variant_id=INT?`, `quantity=INT` | `{"ok": true, "message": string, "cart": {...}}` | `!res.ok` parses JSON `error` field, displays floating alert toast, re-enables submit button. |
| `assets/js/shop.js` | Mini-Cart Update Qty | `${baseUrl}api/cart_ajax.php` | `POST` | `FormData`: `action=update`, `cart_key=STRING`, `quantity=INT` | `{"ok": true, "warning"?: string, "cart": {...}}` | Shows optional warning if stock capped; rolls back input value on failure. |
| `assets/js/shop.js` | Mini-Cart Remove Item | `${baseUrl}api/cart_ajax.php` | `POST` | `FormData`: `action=remove`, `cart_key=STRING` | `{"ok": true, "cart": {...}}` | Removes DOM row with fade animation, updates badge and subtotal; shows toast on error. |
| `assets/js/shop.js` | Mini-Cart Clear | `${baseUrl}api/cart_ajax.php` | `POST` | `FormData`: `action=clear` | `{"ok": true, "cart": {...}}` | Clears DOM items list, shows empty cart graphic. |
| `assets/js/shop.js` | Batch Availability Poll | `${baseUrl}api/availability.php?ids={pids}` | `GET` | Comma-separated product IDs | `{"ok": true, "products": {"<id>": {"stock": int, "status": "in_stock"|"low_stock"|"out_of_stock", "price": float}}}` | Quiet failure; preserves current DOM badges if endpoint unreachable. |
| `assets/js/shop.js` | Wishlist Fetch IDs | `${baseUrl}api/wishlist.php?action=list_ids` | `GET` | URL query parameter `action=list_ids` | `{"ok": true, "logged_in": bool, "ids": [int, ...]}` | If `logged_in: false`, leaves buttons in default unliked state. |
| `assets/js/shop.js` | Wishlist Toggle | `${baseUrl}api/wishlist.php` | `POST` | URL-encoded: `action=toggle&product_id=INT` with Header `X-CSRF-Token` | `{"ok": true, "in_wishlist": bool, "product_id": int, "count": int, "message": string}` | Optimistic UI toggle; reverts icon class and badge counter if request fails or 401 unauthenticated. |
| `assets/js/availability.js` | Live Stock Poll | `${baseUrl}api/availability.php?product_id={id}&_t={time}` | `GET` (with `If-None-Match: ETag`) | ETag request header `If-None-Match` | `304 Not Modified` OR `200 OK` with `{"id": int, "sku": string, "price": float, "sale_price": float?, "stock": int, "status": string, "label_text": string, "image": string, "options": [...], "variants": [...]}` | Handles `304` as no-op; exponential backoff on network failure (10s -> 20s -> 40s); disables Add-to-Cart on 404. |
| `assets/js/availability.js` | Live Stock SSE Stream | `${baseUrl}api/availability_stream.php?product_id={id}` | `GET` (SSE `EventSource`) | Query `product_id` | SSE event `stock` with payload `{"variants": [...]}`; event `reconnect` forces reconnect. | On SSE connection drop, falls back to HTTP ETag polling schedule. |
| `assets/js/admin-variants.js` | Load Option Presets | `ajax/option_presets.php?action=list` | `GET` | URL query parameter `action=list` | `{"ok": true, "presets": [{"id": int, "name": string, "values": [...]}]}` | Alerts admin via browser banner if presets fail to load. |
| `assets/js/admin-variants.js` | Save Option Preset | `ajax/option_presets.php` | `POST` | JSON: `{"action": "save", "name": string, "values": [...]}` | `{"ok": true, "preset_id": int}` | Displays inline validation errors if name empty or duplicate values. |
| `assets/js/auth.js` | Client Password Validation | N/A (DOM only) | N/A | Password field event listener | Toggles visibility, evaluates length >= 8, uppercase, lowercase, number, symbol | Updates strength bar meter (`weak`, `medium`, `strong`); prevents form submit if invalid. |
| `assets/js/script.js` | Global UI & Dark Mode | N/A (DOM only) | N/A | LocalStorage `bs-theme` toggle | Sets `data-bs-theme="dark"` or `"light"` on `<html>` | Fallback to OS `prefers-color-scheme`. |
| `assets/js/shop/ui.js` | Drawers, Modals, Tabs | N/A (DOM only) | N/A | DOM click events on `[data-drawer-open]`, `[data-modal-open]` | Toggles active class, manages body scroll lock | Trap focus within open dialog; dismiss on Escape key. |
| `assets/coza/js/main.js` | CozaStore Template UI | N/A (jQuery) | N/A | Menu mobile, scroll-to-top, isotope filtering, gallery popup | Toggles DOM classes, initializes MagnificPopup and Parallax | Standard jQuery plugin error boundaries. |
| `assets/coza/js/map-custom.js` | Google Map Initializer | N/A (Google Maps) | N/A | Reads `div#google_map` coordinates | Renders Google Map canvas with custom pin marker | Graceful fallback if Google Maps API key is invalid or not loaded. |
| `assets/coza/js/slick-custom.js` | Carousel Slider Init | N/A (Slick Slider) | N/A | DOM selector `[data-appear]` | Initializes hero slider, product detail slider, thumbnail syncer | Re-calculates dimensions on window resize. |

---

### 9.2 Server-Rendered Data Passed into JavaScript

Frontend scripts rely on data passed from PHP templates through three channels:

1. **Global `window` Variables** (rendered in `<head>` or before bundle includes):
   - `window.SHOP_BASE_URL`: Base URI path (e.g. `"/SHOP/"` or `"/"`) generated by `url('')`. Used by `shop.js`, `availability.js`, and `coza/scripts.php` to prefix all fetch/ajax URLs.
   - `window.SHOP_CHECKOUT_URL`: Fully qualified or relative checkout path generated by `route('checkout')`. Used by mini-cart "Proceed to Checkout" button.
   - `window.SHOP_CSRF_TOKEN`: Active CSRF session token string generated by `csrf_token()`. Passed in `X-CSRF-Token` headers for wishlist toggling and AJAX forms.
   - `window.CUSTOMER_LOGGED_IN`: Boolean `true` or `false` indicating customer authentication state for conditional wishlist UI and mini-cart prompts.
   - `window.initProductVariantsBuilder`: Admin variant builder configuration object rendered in `admin/product_form.php` containing existing option values, generated variants, and image gallery mappings.

2. **HTML `data-*` Attributes on DOM Elements**:
   - `data-product-availability`: Container element for live PDP stock.
     - `data-product-id="<int>"`: Product ID to monitor.
     - `data-poll-seconds="<int>"`: Polling interval (e.g. `10`).
     - `data-realtime-mode="poll|sse"`: Configured transport from `realtime_mode` setting.
     - `data-currency-symbol="<char>"`: Currency symbol (e.g. `৳` or `$`).
     - `data-uploads-url="<path>"`: Base media path for dynamic variant image swapping.
   - `data-cart-drawer-trigger`: Buttons opening slide-out mini-cart.
   - `data-cart-drawer-close`: Buttons dismissing mini-cart.
   - `data-quick-add`: Button on product grid cards with `data-product-id` and optional `data-variant-id` for 1-click cart insertion.
   - `data-buy-now`: Button triggering instantaneous cart insertion followed by immediate `window.location.href = window.SHOP_CHECKOUT_URL`.
   - `data-cart-key="<hash>"`: Line item identifier on cart rows (`product_id` or `product_id:variant_id`).
   - `data-qty-change="1|-1"`: Increment/decrement buttons on cart quantities.
   - `data-cart-remove`: Trash icon buttons on cart line items.
   - `data-wishlist-btn`: Heart icon buttons with `data-product-id="<int>"`.
   - `data-toggle-password="<id>"`: Password visibility toggle buttons targeting input ID.

3. **Inline JSON Blocks (`<script type="application/json">` / Inline Variables)**:
   - PDP Variant Map (`storefront/shop/product.php`): Inline JSON array containing all active product variants, attribute value ID tuples (`{"Color": 1, "Size": 4}`), stock levels, price differentials, and image URLs.
   - Admin Variant Editor Matrix (`admin/product_form.php`): JSON payload of existing product options, option values, preset definitions, and variant combinations loaded into `assets/js/admin-variants.js`.
   - Monitoring Metrics Payload (`admin/monitoring.php`): Initial snapshot of CPU, RAM, disk, and MySQL telemetry injected for Chart.js initialization before the periodic AJAX poll takes over.

---

## 10. Admin panel and API

### 10.1 Admin Pages Directory (`admin/`)

| Page File | Clean / URL Path | Key Actions (`$_POST['action']` or `$action`) | Form Fields & Inputs | Tables Written | File Uploads | JS Files / Scripts Used | Required Permissions (`require_permission`) |
|---|---|---|---|---|---|---|---|
| `activity.php` | `/admin/activity.php` | Filter, search, clear logs | `user_id`, `action`, `date_from`, `date_to`, `q` | None (reads `activity_logs`) | None | None | `activity.view` |
| `analytics.php` | `/admin/analytics.php` | Date filter, metric export | `from`, `to`, `compare`, `category_id`, `group_by` | None (reads `orders`, `order_items`, `traffic_minute`) | None | `chart.umd.min.js` | `analytics.view` |
| `analytics_product.php` | `/admin/analytics_product.php` | Product sales analysis | `from`, `to`, `product_id` | None (reads `product_sales_summary`, `order_items`) | None | `chart.umd.min.js` | `analytics.view` |
| `banner_form.php` | `/admin/banner_form.php` | `create`, `update` | `title`, `subtitle`, `link_url`, `button_text`, `position`, `sort_order`, `status`, `image` | `storefront_banners` | Image upload (`image/*`) | None | `banners.manage` |
| `banners.php` | `/admin/banners.php` | `delete`, `toggle_status`, `reorder` | `id`, `status`, `sort_order` | `storefront_banners` | None | Drag-and-drop sort | `banners.manage` |
| `categories.php` | `/admin/categories.php` | `delete`, `toggle_status` | `id`, `status` | `categories` | None | None | `categories.manage` |
| `category_form.php` | `/admin/category_form.php` | `create`, `update` | `name`, `slug`, `parent_id`, `description`, `status` | `categories` | None | Slug auto-generator | `categories.manage` |
| `customer_view.php` | `/admin/customer_view.php` | `update_status`, `adjust_balance`, `delete` | `status`, `admin_note` | `customers`, `customer_tokens` | None | None | `customers.view`, `customers.manage` |
| `customers.php` | `/admin/customers.php` | Filter, export CSV, bulk status | `q`, `status`, `export` | None (reads `customers`) | None | None | `customers.view`, `customers.manage` |
| `dashboard.php` | `/admin/dashboard.php` | Metric overview | None | None (reads KPIs) | None | `chart.umd.min.js` | Login required (`has_role('admin','editor','viewer')`) |
| `index.php` | `/admin/index.php` | Dashboard redirect | None | None | None | None | None |
| `install.php` | `/admin/install.php` | `install_admin` | `username`, `email`, `password`, `site_name` | `users`, `settings` | None | None | Only when `users` table empty |
| `inventory.php` | `/admin/inventory.php` | `quick_stock`, `export_csv` | `product_id`, `variant_id`, `stock`, `stock_threshold`, `note` | `products`, `product_variants`, `stock_adjustments` | None | `ajax/stock_update.php` modal | `inventory.view`, `inventory.manage` |
| `login.php` | `/admin/login.php` | `login` | `username`, `password`, `remember` | None (reads `users`) | None | `assets/js/auth.js` | Public (guest only) |
| `logout.php` | `/admin/logout.php` | `logout` | None | Destroys admin session | None | None | Login required |
| `mail_queue.php` | `/admin/mail_queue.php` | `retry`, `delete`, `clear_failed` | `id`, `action` | `email_queue` | None | None | `mail.manage` |
| `mail_settings.php` | `/admin/mail_settings.php` | `save_settings`, `send_test` | `mail_enabled`, `mail_mailer`, `mail_host`, `mail_port`, `mail_username`, `mail_password`, `mail_encryption`, `mail_from_address`, `mail_from_name`, `test_recipient` | `settings` | None | Async test mail AJAX | `mail.manage` |
| `mail_templates.php` | `/admin/mail_templates.php` | `update_template`, `reset_default` | `slug`, `subject`, `body_html`, `enabled` | `email_templates` | None | Rich text / code textarea | `mail.manage` |
| `messages.php` | `/admin/messages.php` | `mark_read`, `delete`, `reply` | `id`, `action`, `reply_body` | `contact_messages`, `email_queue` | None | Modal reply | `messages.manage` |
| `monitoring.php` | `/admin/monitoring.php` | Live dashboard, range select, alert rules | `range`, `iface`, `format`, `rule_metric`, `rule_threshold`, `rule_action` | `monitor_alert_rules` | None | `chart.umd.min.js`, `ajax/monitor_data.php` | `monitoring.view` |
| `order_print.php` | `/admin/order_print.php` | Printable invoice / packing slip | `id`, `format` | None (reads `orders`, `order_items`) | None | `window.print()` | `orders.print`, `orders.view` |
| `order_view.php` | `/admin/order_view.php` | `update_status`, `add_note`, `update_shipping` | `status`, `tracking_number`, `courier`, `admin_note` | `orders`, `order_status_history`, `products`, `product_variants`, `product_sales_summary` | None | None | `orders.view`, `orders.manage` |
| `orders.php` | `/admin/orders.php` | `bulk_status`, `bulk_delete`, `export_csv` | `q`, `status`, `date_from`, `date_to`, `payment_method`, `bulk_action`, `selected_ids[]` | `orders`, `order_status_history`, `products`, `product_variants` | None | None | `orders.view`, `orders.manage` |
| `page_form.php` | `/admin/page_form.php` | `create`, `update` | `title`, `slug`, `body_html`, `meta_title`, `meta_description`, `status` | `pages` | None | Slug generator | `pages.manage` |
| `pages.php` | `/admin/pages.php` | `delete`, `toggle_status` | `id`, `status` | `pages` | None | None | `pages.manage` |
| `product_form.php` | `/admin/product_form.php` | `create`, `update` | `name`, `slug`, `sku`, `category_id`, `price`, `sale_price`, `stock`, `stock_threshold`, `product_type`, `description`, `status`, `featured`, `image`, `gallery_images[]`, `options_json`, `variants_json` | `products`, `product_options`, `product_option_values`, `product_variants`, `product_images`, `stock_adjustments` | Product images (`image/*`) | `admin-variants.js`, dynamic matrix generator | `products.create`, `products.edit` |
| `product_import.php` | `/admin/product_import.php` | `import_csv`, `download_template` | `csv_file`, `update_existing`, `delimiter` | `products`, `categories`, `product_options`, `product_option_values`, `product_variants`, `product_images` | CSV file (`.csv`, `text/plain`) | File upload progress | `products.create`, `products.edit` |
| `products.php` | `/admin/products.php` | `delete`, `toggle_status`, `bulk_delete`, `quick_stock` | `q`, `category_id`, `status`, `stock_status`, `bulk_action`, `selected_ids[]` | `products`, `product_variants`, `stock_adjustments` | None | `ajax/stock_update.php` modal | `products.view`, `products.create`, `products.edit` |
| `profile.php` | `/admin/profile.php` | `update_profile`, `update_password` | `name`, `email`, `current_password`, `new_password`, `confirm_password`, `avatar` | `users` | User avatar (`image/*`) | Password meter | Login required (`admin/editor/viewer`) |
| `reviews.php` | `/admin/reviews.php` | `approve`, `reject`, `delete`, `bulk_action` | `id`, `status`, `selected_ids[]` | `product_reviews`, `products` (recalculates rating) | None | Star rating render | `reviews.view`, `reviews.manage` |
| `settings.php` | `/admin/settings.php` | `save_general`, `save_store`, `save_shipping`, `save_storage`, `create_coupon`, `delete_coupon` | 150+ settings keys across 7 tabs: site name, currency, COD fees, free shipping threshold, coupons, S3 config | `settings`, `coupons`, `shipping_methods` | Favicon, logos (`image/*`) | Tab state switcher, async AJAX save | `settings.general`, `settings.storage`, `coupons.manage` |
| `subscribers.php` | `/admin/subscribers.php` | `delete`, `export_csv` | `q`, `status` | `newsletter_subscribers` | None | None | `subscribers.manage` |
| `user_form.php` | `/admin/user_form.php` | `create`, `update` | `username`, `name`, `email`, `role`, `password`, `permissions[]`, `avatar` | `users` | User avatar (`image/*`) | RBAC permission checkboxes | `users.manage` |
| `users.php` | `/admin/users.php` | `delete`, `toggle_status` | `id`, `status` | `users` | None | None | `users.view`, `users.manage` |

---

### 10.2 System Monitoring Architecture

#### 1. Telemetry Collection Engine (`cron/collect_metrics.php`)
- **Execution**: Triggered via CLI cron every 1 minute (`* * * * * php cron/collect_metrics.php`). Protected by non-blocking file lock `storage/collect_metrics.lock`.
- **Host Metrics (Linux `/proc/` & System APIs)**:
  - **CPU & Load**: Reads `/proc/loadavg` and `sys_getloadavg()` for 1m, 5m, 15m load averages. Reads `/proc/cpuinfo` for core count. Reads `/proc/stat` snapshot deltas against `storage/monitor_state.json` to calculate aggregate `cpu_percent` (`(idle_delta / total_delta) * 100`).
  - **Memory & Swap**: Reads `/proc/meminfo` parsing `MemTotal`, `MemAvailable` (or `MemFree + Buffers + Cached`), `SwapTotal`, `SwapFree`. Computes `mem_used = MemTotal - MemAvailable`.
  - **Disk Usage**: Uses `disk_total_space(__DIR__)` and `disk_free_space(__DIR__)` to determine partition capacity and utilization.
  - **Uptime**: Reads `/proc/uptime` (first floating value in seconds).
  - **Network RX/TX**: Reads `/proc/net/dev` skipping virtual loops (`lo`, `docker`, `veth`). Calculates delta bytes divided by delta seconds against `storage/monitor_state.json` to compute instantaneous `net_rx_rate` and `net_tx_rate` (Bytes/sec). Writes per-interface rows into `network_samples`.
- **MySQL Database Metrics**:
  - Executes `SHOW GLOBAL STATUS` querying `Threads_connected`, `Queries` (calculates queries-per-second delta against previous timestamp), and `Slow_queries`.
  - Executes `SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()` to compute physical database size in bytes.
- **Persistence**: Writes aggregated system snapshot into `system_metrics` (table auto-verified and repaired dynamically if columns missing).

#### 2. Request & Traffic Telemetry (`includes/shop_bootstrap.php`)
- Wrapped around every web request. Records start time via `microtime(true)`.
- On shutdown (`register_shutdown_function`), calculates request duration `total_ms`, memory delta, and HTTP status code.
- Upserts aggregate bucket counts into `traffic_minute` (`bucket = DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:00')`):
  ```sql
  INSERT INTO traffic_minute (bucket, requests, bytes_in, bytes_out, total_ms, s2xx, s3xx, s4xx, s5xx)
  VALUES (:bucket, 1, :bytes_in, :bytes_out, :dur, :s2, :s3, :s4, :s5)
  ON DUPLICATE KEY UPDATE
    requests  = requests + 1,
    bytes_in  = bytes_in + VALUES(bytes_in),
    bytes_out = bytes_out + VALUES(bytes_out),
    total_ms  = total_ms + VALUES(total_ms),
    s2xx      = s2xx + VALUES(s2xx),
    s3xx      = s3xx + VALUES(s3xx),
    s4xx      = s4xx + VALUES(s4xx),
    s5xx      = s5xx + VALUES(s5xx);
  ```
- If duration exceeds threshold (e.g. >1000ms), records detailed slow trace into `slow_requests` (`path`, `method`, `status_code`, `duration_ms`, `query_count`, `peak_mem`).

#### 3. Admin Monitoring JSON Contract (`admin/ajax/monitor_data.php`)
The frontend dashboard in `admin/monitoring.php` polls `admin/ajax/monitor_data.php?range=1h&iface=auto` every 5 seconds.

**Exact JSON Response Shape**:
```json
{
  "generated_at": "2026-11-22 16:48:45",
  "range": "1h",
  "iface": "auto",
  "tiles": {
    "cpu_percent": 14.5,
    "cpu_cores": 4,
    "load_1": 0.35,
    "load_5": 0.42,
    "load_15": 0.38,
    "mem_total": 8589934592,
    "mem_used": 3435973836,
    "mem_percent": 40.0,
    "swap_total": 2147483648,
    "swap_used": 0,
    "swap_percent": 0.0,
    "disk_total": 107374182400,
    "disk_used": 26843545600,
    "disk_percent": 25.0,
    "net_rx_rate": 128500,
    "net_tx_rate": 450200,
    "uptime_sec": 864200,
    "db_threads": 6,
    "db_qps": 24.5,
    "db_slow_queries": 0,
    "db_size_bytes": 14680064,
    "php_version": "8.2.12",
    "req_per_min": 142.0,
    "error_rate": 0.05,
    "collector_running": true,
    "last_collected_at": "2026-11-22 16:48:01",
    "collector_command": "* * * * * php /home/ubuntu/.../cron/collect_metrics.php >/dev/null 2>&1"
  },
  "series": {
    "labels": ["15:48", "15:49", "15:50", "..."],
    "cpu": [12.0, 15.2, 14.5],
    "load": [0.30, 0.32, 0.35],
    "mem": [39.8, 40.0, 40.0],
    "swap": [0.0, 0.0, 0.0],
    "net_rx": [120000, 125000, 128500],
    "net_tx": [420000, 435000, 450200],
    "bw_out": [5242880, 6144000, 5800000],
    "bw_in": [1048576, 1200000, 1150000],
    "req": [135, 148, 142],
    "rt_avg": [42.1, 45.3, 41.8],
    "status": {
      "s2xx": [132, 146, 140],
      "s3xx": [2, 1, 2],
      "s4xx": [1, 1, 0],
      "s5xx": [0, 0, 0]
    },
    "db_threads": [5, 6, 6],
    "db_qps": [22.1, 26.4, 24.5]
  },
  "bandwidth": {
    "month_bytes": 18454938240,
    "quota_gb": 100.0,
    "quota_percent": 18.5
  },
  "tables": {
    "slow": [
      {
        "path": "/storefront/shop/index.php",
        "method": "GET",
        "status_code": 200,
        "duration_ms": 1120.5,
        "query_count": 14,
        "peak_mem": 8388608,
        "created_at": "2026-11-22 16:12:04"
      }
    ],
    "interfaces": ["auto", "eth0"]
  }
}
```

---

### 10.3 API Endpoints Catalog (`api/`)

| Endpoint Script | Clean URL Route | HTTP Method | Auth Requirement | Request Parameters | Example Response JSON | Error Response Format | HTTP Status Codes | Tables Queried / Written | Callers |
|---|---|---|---|---|---|---|---|---|---|
| `api/availability.php` | `GET /api/availability.php` | `GET` | Guest / Public (Rate limited: 60 req/min/IP unless Admin session) | `product_id=INT`, optional `_t=INT` (cache-buster), Header `If-None-Match: "ETAG"` | `{"id": 12, "sku": "SHIRT-RED-L", "price": 1200.0, "sale_price": null, "stock": 8, "status": "in_stock", "label_text": "● In Stock", "image": "shirt.jpg", "options": [...], "variants": [...]}` | `{"ok": false, "error": "Product not found."}` | `200 OK`, `304 Not Modified`, `400 Bad Request`, `404 Not Found`, `429 Too Many Requests` | `products`, `product_variants`, `product_options`, `product_option_values` | `assets/js/availability.js`, `assets/js/shop.js`, `admin/products.php` |
| `api/availability_stream.php` | `GET /api/availability_stream.php` | `GET` (SSE) | Guest / Public (Enabled when `realtime_mode == 'sse'`) | `product_id=INT` | SSE Stream: `event: stock
data: {"variants": [{"id": 1, "sku": "...", "stock": 5, "price": 1200.0}]}

` | Closes connection on invalid product ID | `200 OK` (`text/event-stream`), `400 Bad Request` | `products`, `product_variants` | `assets/js/availability.js` (SSE mode) |
| `api/cart_ajax.php` | `POST /api/cart_ajax.php`, `GET /api/cart_ajax.php?action=get` | `GET`, `POST` | Guest / Customer (Session-based) | `action` (`add`\|`update`\|`remove`\|`clear`\|`get`), `product_id=INT`, `variant_id=INT?`, `quantity=INT`, `cart_key=STRING` | `{"ok": true, "action": "add", "message": "Product added to cart", "cart": {"items": [...], "item_count": 3, "subtotal": 3600.0, "subtotal_formatted": "৳3,600.00", "empty": false}}` | `{"ok": false, "error": "Only 3 units available in stock."}` | `200 OK`, `400 Bad Request`, `404 Not Found` | Reads `products`, `product_variants`, `product_images`; writes `$_SESSION['cart']` | `assets/js/shop.js`, `includes/layout/coza/scripts.php` |
| `api/newsletter.php` | `POST /api/newsletter.php` | `POST` | Guest / Public (Rate limited: 5 req/hr/IP; honeypot `website`; min 2s elapsed) | `email=STRING`, `token=STRING`, `website=""`, `form_rendered_at=INT` | `{"ok": true, "status": "active"\|"pending", "message": "Thank you for subscribing!"}` | `{"ok": false, "error": "Please enter a valid email address."}` | `200 OK`, `400 Bad Request`, `422 Unprocessable`, `429 Too Many Requests` | `newsletter_subscribers`, `email_queue` | Footer newsletter form, `storefront/pages/newsletter.php` |
| `api/review.php` | `POST /api/review.php` | `POST` | Customer login required (if `reviews_require_login=1`); Honeypot `comment_extra_field` | `product_id=INT`, `rating=INT(1-5)`, `title=STRING`, `body=STRING`, `author_name=STRING`, `rendered_at=INT` | `{"success": true, "status": "approved"\|"pending", "message": "Thank you! Your review has been submitted."}` | `{"success": false, "error": "Review description must be at least 10 characters long."}` | `200 OK`, `400 Bad Request`, `401 Unauthorized`, `404 Not Found`, `500 Server Error` | `product_reviews`, `products` (updates avg rating), `email_queue` | `storefront/shop/product.php` review submission form |
| `api/search_suggest.php` | `GET /api/search_suggest.php` | `GET` | Guest / Public (Unblocked session) | `q=STRING` (query term) | `{"query": "shirt", "products": [{"id": 1, "name": "Casual Shirt", "slug": "casual-shirt", "price": "৳1,200", "image": "...", "url": "..."}], "categories": [{"id": 3, "name": "Men Shirts", "slug": "men-shirts", "url": "..."}], "total_results": 4}` | Returns empty arrays `products: [], categories: []` on error or query < 2 chars | `200 OK` | `products`, `categories`, `search_log` (logs search query and hit count) | `assets/js/shop.js` search input |
| `api/wishlist.php` | `POST /api/wishlist.php`, `GET /api/wishlist.php?action=list_ids` | `GET`, `POST` | Customer login required for toggle; Guest gets empty list | `action` (`toggle`\|`list_ids`), `product_id=INT`, Header `X-CSRF-Token` | `{"ok": true, "in_wishlist": true, "product_id": 4, "count": 3, "message": "Item added to your wishlist."}` | `{"ok": false, "error": "Please log in to manage your wishlist."}` | `200 OK`, `400 Bad Request`, `401 Unauthorized` | `wishlists` (`INSERT IGNORE` or `DELETE`) | `assets/js/shop.js`, `includes/layout/coza/scripts.php` |

---

### 10.4 Data Exports Generation and Formats

1. **Orders Bulk CSV Export (`admin/orders.php:export`)**:
   - **Generation**: Streams directly to output stream `php://output` via PHP `fputcsv()`.
   - **Headers**:
     ```http
     Content-Type: text/csv; charset=utf-8
     Content-Disposition: attachment; filename="orders_export_YYYY-MM-DD_HHmmss.csv"
     Cache-Control: no-store, no-cache
     ```
   - **BOM**: Prepends UTF-8 Byte Order Mark (`ï»¿`) for Microsoft Excel Unicode compatibility.
   - **Columns**: `Order Number`, `Date`, `Customer Name`, `Customer Email`, `Customer Phone`, `Shipping Address`, `City`, `Payment Method`, `Payment Status`, `Order Status`, `Subtotal`, `Shipping Fee`, `Discount`, `COD Fee`, `Total Amount`, `Items Summary`.

2. **Customers CSV Export (`admin/customers.php:export`)**:
   - **Generation**: `fputcsv()` stream on filtered customer records.
   - **Columns**: `Customer ID`, `Full Name`, `Email`, `Phone`, `Status`, `Email Verified`, `Orders Count`, `Total Spent`, `Registered Date`, `Last Login`.

3. **Inventory Stock CSV Export (`admin/inventory.php:export`)**:
   - **Generation**: `fputcsv()` stream of all products and active variants.
   - **Columns**: `Product ID`, `Variant ID`, `Product Name`, `SKU`, `Variant Label`, `Type`, `Price`, `Current Stock`, `Stock Threshold`, `Status`.

4. **Newsletter Subscribers CSV Export (`admin/subscribers.php:export`)**:
   - **Generation**: `fputcsv()` stream.
   - **Columns**: `ID`, `Email`, `Status`, `Verified At`, `Subscribed At`, `IP Address`.

5. **Customer GDPR Personal Data Export (`account/export.php`)**:
   - **Generation**: Requires password re-authentication (`customer_password_verify`). Fetches customer profile, all addresses, order history summaries, and wishlist items.
   - **Format**: `application/json` file attachment download:
     ```http
     Content-Type: application/json; charset=utf-8
     Content-Disposition: attachment; filename="customer-data-{cid}-{date}.json"
     ```
   - **Payload**: Root object containing `exported_at` (ISO 8601), `profile` (name, email, phone, marketing opt-in, registration timestamp), `addresses` (array of address objects), `orders` (array of historical order headers), and `wishlist` (array of product names, slugs, and timestamps).

6. **Printable Invoices and Packing Slips (`account/invoice.php` and `admin/order_print.php`)**:
   - **Generation**: Server-side rendered HTML document formatted with strict `@media print` CSS rules.
   - **Features**: Includes company header, tax registration, invoice date, billing/delivery addresses, line items table with unit prices and line totals, fee breakdowns, payment method, order notes, and barcode/QR code placeholder.
   - **Auto-Trigger**: Contains inline JavaScript `window.onload = function() { window.print(); }` with an on-screen "Print Invoice" action button.

---

## 11. Business logic

### 11.1 Checkout Workflow Step-by-Step

```
[Customer Cart]
       │
       ▼
1. Validate Cart Items & Live Stock (Purge deleted, clamp to stock)
       │
       ▼
2. Resolve Customer Profile & Address (Guest vs Logged-in / Saved Address)
       │
       ▼
3. Calculate Shipping Fee (Bangladesh 64 Districts: inside_dhaka vs outside_dhaka)
       │
       ▼
4. Validate & Apply Coupon Code (fixed vs percent, min subtotal, max discount, usage limits)
       │
       ▼
5. Calculate Payment Fees (Cash on Delivery fee vs Bank Transfer)
       │
       ▼
6. Recompute Final Order Total: Subtotal - Discount + Shipping + COD Fee
       │
       ▼
7. BEGIN DATABASE TRANSACTION
   ├─ Lock / Re-verify fresh cart
   ├─ Generate unique Order Number (ORD-YYYYMM-XXXXX) with duplicate retry
   ├─ INSERT INTO orders (header)
   ├─ INSERT INTO order_items (snapshot unit_price, product_sku, variant_sku, line_total)
   ├─ Atomically Decrement Stock: stock_adjust(-qty, 'order') (fails if stock < qty)
   ├─ Increment sold_count on products
   ├─ INSERT INTO order_status_history ('pending')
   ├─ Increment coupons.used_count (if coupon applied)
   └─ COMMIT TRANSACTION
       │
       ▼
8. Post-Commit Actions
   ├─ Clear Session Cart & Applied Coupon
   ├─ Record last_placed_order in session
   ├─ Fire Hook: order.placed (triggers mail_queue for customer & admin emails)
   └─ Redirect to /order/confirmation?o={order_number}&t={signed_hmac}
```

#### Detailed Calculation Rules:
1. **Cart Subtotal**: Sum of `line_total = unit_price * quantity` for all items. Unit price is `sale_price` (if `sale_price > 0 && sale_price < price`) else base `price`.
2. **Coupon Rules (`coupon_validate()`)**:
   - `status === 'active'`
   - `starts_at <= NOW() <= expires_at`
   - `used_count < usage_limit` (if `usage_limit > 0`)
   - `cart_subtotal >= min_spend`
   - **Discount Computation**:
     - `fixed`: `min(coupon.value, cart_subtotal)`
     - `percent`: `min(cart_subtotal * (coupon.value / 100), max_discount ?? infinity)`
3. **Shipping Calculation**:
   - Free Shipping Rule: If `subtotal >= shipping_free_threshold` (e.g. 5000 BDT) and setting enabled, shipping fee is `0.00`.
   - District-based Rules (`includes/data/bd_districts.php`):
     - `Dhaka` district &rarr; `zone = 'inside_dhaka'` &rarr; default `60.00 BDT`
     - All other 63 districts &rarr; `zone = 'outside_dhaka'` &rarr; default `120.00 BDT`
   - Custom Method Rule: If customer selects an express/custom shipping method from `shipping_methods`, the method's database `cost` overrides the flat rate.
4. **Cash on Delivery (COD) Fee**:
   - If payment method is `cod`, adds `setting('payment_cod_fee', 0.00)` (typically 0 or 20 BDT).
   - If payment method is `bank_transfer`, COD fee is `0.00`.
5. **Rounding**: All money calculations are stored as `DECIMAL(10,2)` and rounded using standard half-up `round($val, 2)`.
6. **Order Number Format**: `{PREFIX}-{YYYYMM}-{RAND5}` (e.g. `ORD-202611-48291`), generated via loop with up to 5 retries catching MySQL error `23000` (duplicate key).

---

### 11.2 Full Source of Order Creation Function

From `storefront/cart/checkout.php:260-464`:

```php
    // 8. Execute Order Transaction if no errors
    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Re-load fresh cart inside transaction to avoid race conditions
            $freshCart = cart_load();
            if (empty($freshCart['items'])) {
                throw new Exception('Your cart is empty.');
            }

            // Recompute totals strictly from DB
            $recomputedTotals = cart_totals(
                $freshCart,
                $chosenMethod ? (int)$chosenMethod['id'] : null,
                $appliedCouponCode,
                $old['payment_method']
            );

            // Sort lines by variant_id / product_id ascending to prevent deadlocks
            $orderLines = $freshCart['items'];
            usort($orderLines, function ($a, $b) {
                $aKey = ($a['variant_id'] ?? 0) ? (int)$a['variant_id'] : ((int)$a['product_id'] + 1000000);
                $bKey = ($b['variant_id'] ?? 0) ? (int)$b['variant_id'] : ((int)$b['product_id'] + 1000000);
                return $aKey <=> $bKey;
            });

            // Generate unique order number with retry on duplicate key
            $orderNumber = null;
            $orderId = null;
            $prefixSetting = setting('order_number_prefix', 'ORD') ?: 'ORD';
            $monthPrefix = $prefixSetting . '-' . date('Ym') . '-';

            // Find current highest sequence with row lock or generate
            $customerId = $currentCustomer ? (int)$currentCustomer['id'] : null;

            for ($retry = 0; $retry < 5; $retry++) {
                $randSuffix = str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
                $candidateNumber = $monthPrefix . $randSuffix;

                $stmtInsertOrder = $pdo->prepare(
                    "INSERT INTO orders
                      (order_number, customer_id, customer_name, customer_email, customer_phone,
                       shipping_address, shipping_city, shipping_zip, shipping_country,
                       subtotal, shipping_fee, discount_total, cod_fee, total,
                       payment_method, payment_status, shipping_method_id, shipping_method_name,
                       coupon_code, ip_address, notes, status, stock_restored)
                     VALUES
                      (:order_number, :customer_id, :name, :email, :phone,
                       :address, :city, :zip, 'Bangladesh',
                       :subtotal, :shipping_fee, :discount_total, :cod_fee, :total,
                       :payment_method, 'unpaid', :shipping_method_id, :shipping_method_name,
                       :coupon_code, :ip, :notes, 'pending', 0)"
                );

                try {
                    $stmtInsertOrder->execute([
                        ':order_number'         => $candidateNumber,
                        ':customer_id'          => $customerId,
                        ':name'                 => $old['customer_name'],
                        ':email'                => $old['customer_email'],
                        ':phone'                => $old['customer_phone'],
                        ':address'              => $old['shipping_address'],
                        ':city'                 => $old['shipping_city'],
                        ':zip'                  => $old['shipping_zip'],
                        ':subtotal'             => $recomputedTotals['subtotal'],
                        ':shipping_fee'         => $recomputedTotals['shipping_fee'],
                        ':discount_total'       => $recomputedTotals['discount'],
                        ':cod_fee'              => $recomputedTotals['cod_fee'],
                        ':total'                => $recomputedTotals['total'],
                        ':payment_method'       => $old['payment_method'],
                        ':shipping_method_id'   => $chosenMethod['id'] ?? null,
                        ':shipping_method_name' => $chosenMethod['name'] ?? null,
                        ':coupon_code'          => $recomputedTotals['coupon']['code'] ?? null,
                        ':ip'                   => $clientIp,
                        ':notes'                => $old['notes'],
                    ]);
                    $orderId = (int)$pdo->lastInsertId();
                    $orderNumber = $candidateNumber;
                    break;
                } catch (PDOException $pe) {
                    if ($pe->getCode() === '23000' && str_contains($pe->getMessage(), 'Duplicate')) {
                        continue;
                    }
                    throw $pe;
                }
            }

            if (!$orderId || !$orderNumber) {
                throw new Exception('Unable to generate unique order number. Please try again.');
            }

            // Save address to customer address book if logged in and requested
            if ($customerId && !empty($_POST['save_address']) && (empty($_POST['saved_address_id']) || $_POST['saved_address_id'] === 'new')) {
                try {
                    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM customer_addresses WHERE customer_id = :cid");
                    $cntStmt->execute([':cid' => $customerId]);
                    $addrCount = (int)$cntStmt->fetchColumn();
                    if ($addrCount < 10) {
                        $isDef = ($addrCount === 0) ? 1 : 0;
                        $stSave = $pdo->prepare(
                            "INSERT INTO customer_addresses
                              (customer_id, label, full_name, phone, address, city, zip, is_default, created_at)
                             VALUES
                              (:cid, 'Home', :rname, :phone, :addr, :city, :zip, :def, NOW())"
                        );
                        $stSave->execute([
                            ':cid'   => $customerId,
                            ':rname' => $old['customer_name'],
                            ':phone' => $old['customer_phone'],
                            ':addr'  => $old['shipping_address'],
                            ':city'  => $old['shipping_city'],
                            ':zip'   => $old['shipping_zip'] ?: null,
                            ':def'   => $isDef,
                        ]);
                    }
                } catch (Throwable $e) {}
            }

            // Insert line items & atomically adjust stock
            $stmtItem = $pdo->prepare(
                "INSERT INTO order_items
                  (order_id, product_id, product_name, product_sku,
                   variant_id, variant_sku, variant_label,
                   unit_price, quantity, line_total)
                 VALUES
                  (:oid, :pid, :pname, :psku,
                   :vid, :vsku, :vlabel,
                   :price, :qty, :total)"
            );

            foreach ($orderLines as $line) {
                $pid = (int)$line['product_id'];
                $vid = $line['variant_id'] ? (int)$line['variant_id'] : null;
                $qty = (int)$line['qty'];

                $stmtItem->execute([
                    ':oid'    => $orderId,
                    ':pid'    => $pid,
                    ':pname'  => $line['product_name'],
                    ':psku'   => $line['product']['sku'] ?? null,
                    ':vid'    => $vid,
                    ':vsku'   => $line['sku'] ?? null,
                    ':vlabel' => $line['variant_label'] ?: null,
                    ':price'  => $line['unit_price'],
                    ':qty'    => $qty,
                    ':total'  => $line['line_total'],
                ]);

                // Atomic stock decrement
                $adjusted = stock_adjust($pid, $vid, -$qty, 'order', $orderId, "Order {$orderNumber} placed");
                if (!$adjusted) {
                    throw new Exception("Unable to reserve stock for "{$line['product_name']}". It may have just sold out.");
                }

                // Increment sold_count on products
                $pdo->prepare(
                    "UPDATE products SET sold_count = sold_count + :q WHERE id = :pid"
                )->execute([':q' => $qty, ':pid' => $pid]);
            }

            // Insert order status history
            $stmtHist = $pdo->prepare(
                "INSERT INTO order_status_history (order_id, status, note)
                 VALUES (:oid, 'pending', :note)"
            );
            $stmtHist->execute([
                ':oid'  => $orderId,
                ':note' => 'Order placed by customer via ' . ($old['payment_method'] === 'cod' ? 'Cash on Delivery' : 'Bank Transfer'),
            ]);

            // Increment coupon usage
            if (!empty($recomputedTotals['coupon']['id'])) {
                $pdo->prepare(
                    "UPDATE coupons SET used_count = used_count + 1 WHERE id = :cid"
                )->execute([':cid' => $recomputedTotals['coupon']['id']]);
            }

            // Commit transaction
            $pdo->commit();

            // Clear cart & session coupon
            cart_clear();
            unset($_SESSION['applied_coupon']);
            unset($_SESSION['checkout_token']);
            unset($_SESSION['checkout_started_tracked']);
            $_SESSION['last_placed_order'] = $orderNumber;

            // Trigger order.placed hook
            hook_fire('order.placed', [
                'order_id'     => $orderId,
                'order_number' => $orderNumber,
                'total'        => $recomputedTotals['total'],
                'customer_name' => $old['customer_name'],
                'customer_phone' => $old['customer_phone'],
                'customer_email' => $old['customer_email'],
            ]);

            // Signed HMAC token for confirmation page
            $appKey = defined('APP_KEY') ? APP_KEY : 'secret-key-fallback';
            $signedToken = hash_hmac('sha256', $orderNumber, $appKey);

            redirect(route('order_confirmation', ['o' => $orderNumber, 't' => $signedToken]));

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex->getMessage();
        }
    }
```

---

### 11.3 Cart Storage, Merge on Login, and Validation

1. **Storage Format**:
   - `$_SESSION['cart']` stores an associative array keyed by `cart_key`:
     - Simple product: `cart_key = (string)$productId` (e.g. `"12"`)
     - Variable product: `cart_key = $productId . ':' . $variantId` (e.g. `"12:35"`)
     - Value: integer quantity `int $qty` (e.g. `2`).
2. **Merge on Login (`customer_auth.php:customer_login()`)**:
   - Customer carts are stored purely in the user session. When a guest adds items to their session cart and subsequently logs in, the session cart is **preserved without destruction**, seamlessly attaching the guest items to the newly authenticated customer session.
3. **Cart Validation (`cart_load()`)**:
   - Reads every key in `$_SESSION['cart']`.
   - Fetches fresh product and variant records from the database.
   - If product or variant is deleted or inactive (`status != 'active'`), removes item and adds flash alert `"Item X is no longer available."`.
   - If requested quantity exceeds currently available stock, caps quantity to available stock and sets alert `"Quantity for X adjusted to available stock (Y units)."`.
   - If available stock is 0, purges item entirely.

---

### 11.4 Product Catalog Logic

1. **Variant Matrix Generation (`admin-variants.js` & `admin/product_form.php`)**:
   - Options defined as attributes (e.g. `Color: [Red, Blue]`, `Size: [S, M, L]`).
   - Generates Cartesian product combinations (`2 * 3 = 6 variants`).
   - Auto-generates unique SKUs: `{PARENT_SKU}-{VAL1_CODE}-{VAL2_CODE}`.
   - Each variant stores distinct `price` (optional override), `sku`, `stock`, `status`, and `image_id`.
2. **Availability Calculation**:
   - Simple Product: `stock` column on `products`.
   - Variable Product: Sum of `stock` on active rows in `product_variants`.
   - Threshold Alerts: If `stock <= stock_threshold`, flag status as `low_stock`. If `stock <= 0`, flag as `out_of_stock`.
3. **Featured and Best-Sellers**:
   - **Featured**: Flagged via `products.featured = 1`. Filtered in storefront widgets via `WHERE status = 'active' AND featured = 1 ORDER BY id DESC LIMIT X`.
   - **Best Sellers**: Computed from `products.sold_count DESC` and aggregated monthly from `product_sales_summary.units DESC`.
4. **Search and Filtering**:
   - Keyword search matches `name LIKE :q OR description LIKE :q OR sku LIKE :q`.
   - Faceted filtering supports category hierarchy, price range (`min_price <= price <= max_price`), in-stock status (`stock > 0`), and sort order (`newest`, `price_low_high`, `price_high_low`, `popular`).

---

### 11.5 Supporting Storefront Workflows

1. **Newsletter Double Opt-in (`api/newsletter.php` & `storefront/pages/newsletter.php`)**:
   - User inputs email in footer or dedicated page.
   - Protected by honeypot input `website` and minimum 2-second render trap.
   - Enters `newsletter_subscribers` with `status = 'pending'` and a 48-char random token.
   - Dispatches email template `newsletter_confirm` with URL `/storefront/pages/newsletter.php?action=confirm&token={token}`.
   - Upon clicking, subscriber status updates to `'active'` and `verified_at = NOW()`.
2. **Contact Form Anti-Spam (`storefront/pages/view.php`)**:
   - Honeypot input field: `<input type="text" name="website" style="display:none">`.
   - Render timestamp token: `$_SESSION['contact_form_rendered_at'] = time()`. Form submitted within < 2 seconds is flagged as automated bot.
   - Submissions enqueued in `contact_messages` and notification sent to admin via template `contact_received_admin`.
3. **Order Tracking Lookup (`storefront/track/index.php`)**:
   - Public tracking form accepts `order_number` and `phone` or `email`.
   - SQL query requires exact match on `order_number` AND (`customer_phone = :p` OR `customer_email = :e`).
   - Renders visual vertical progress stepper:
     - `pending`: "Order Received - Awaiting processing"
     - `processing`: "Confirmed & Preparing for shipment"
     - `shipped`: "Dispatched with {courier} - Tracking Code: {tracking_number}"
     - `completed`: "Delivered to recipient"
     - `cancelled`: "Order has been cancelled"

---

## 12. Cron, email, deployment

### 12.1 Mail Queue Worker (`cron/process_mail_queue.php`)

- **Execution**: CLI-only cron runner executed every 1 minute (`* * * * * php cron/process_mail_queue.php`).
- **Batch Size**: 50 emails per run.
- **Locking & Concurrency**:
  - Checks database server version.
  - If MySQL 8.0+ or MariaDB 10.6+: Uses non-blocking transaction lock:
    ```sql
    SELECT id FROM email_queue
    WHERE status = 'pending' AND scheduled_at <= NOW()
    ORDER BY id ASC
    LIMIT 50
    FOR UPDATE SKIP LOCKED;
    ```
    Immediately updates claimed rows to `status = 'sending'`, then commits to prevent blocking concurrent workers.
  - If older MySQL 5.7: Fallback atomic claim row-by-row `UPDATE email_queue SET status = 'sending' WHERE id = :id AND status = 'pending'`.
- **Delivery & Error Handling**:
  - Invokes `mail_process_queue_item($id)` which loads SMTP credentials from `settings` and sends via PHPMailer.
  - On Success: `status = 'sent'`, `sent_at = NOW()`, `last_error = NULL`.
  - On Failure:
    - Increments `attempts = attempts + 1`.
    - Exponential backoff schedule: `[1 min, 5 min, 30 min]`.
    - If `attempts < max_attempts` (default 3): Sets `status = 'pending'` and `scheduled_at = DATE_ADD(NOW(), INTERVAL {backoff} MINUTE)`.
    - If `attempts >= max_attempts`: Sets `status = 'failed'` and records sanitized error message in `last_error`.

---

### 12.2 Customer Maintenance Worker (`cron/customer_maintenance.php`)

- **Execution**: Daily CLI cron (`0 0 * * * php cron/customer_maintenance.php`).
- **Tasks & Tables Affected**:
  1. **Purge Expired Auth Tokens**:
     ```sql
     DELETE FROM customer_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
     ```
  2. **Purge Rate Limiting Logs**:
     ```sql
     DELETE FROM auth_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
     ```
  3. **Purge Abandoned Unverified Registrations**:
     - Controlled by setting `customer_unverified_ttl_days` (default 14 days; 0 = disabled).
     - Selects pending customers with no verified email:
       ```sql
       SELECT id, avatar, avatar_file_id FROM customers
       WHERE status = 'pending' AND email_verified_at IS NULL AND deleted_at IS NULL
         AND created_at < DATE_SUB(NOW(), INTERVAL :days DAY);
       ```
     - For each matching customer: Deletes avatar file from disk/S3, deletes records from `customer_tokens`, `customer_addresses`, and `wishlists`, sets `customer_id = NULL` on any orphan orders, and deletes customer row from `customers`.
     - Logs maintenance summary in `activity_logs`.

---

### 12.3 Other Cron Jobs in `cron/`

| Cron Script | Frequency | Purpose | Key Tables Touched | Locking / Constraints |
|---|---|---|---|---|
| `cron/collect_metrics.php` | Every 1 min | Gathers host OS CPU, RAM, disk, network, and MySQL status | `system_metrics`, `network_samples` | File lock `storage/collect_metrics.lock` |
| `cron/rebuild_sales_summary.php` | Daily / Hourly | Re-aggregates total units sold and revenue per product/variant | `order_items`, `orders`, `product_sales_summary` | CLI only; deletes and re-inserts summary rows |
| `cron/cleanup_logs.php` | Daily | Purges stale operational data | `activity_logs` (>90d), `traffic_minute` (>60d), `slow_requests` (>30d) | CLI only; limits row batch deletes to prevent table lock |
| `cron/aggregate_analytics.php` | Hourly | Summarizes 1-minute traffic into hourly/daily aggregates | `traffic_minute`, `analytics_summary` | CLI only |
| `cron/parse_access_log.php` | Every 15 min | Parses Apache access logs into `traffic_minute` if mod_status not available | `traffic_minute` | Position bookmark file in `storage/` |

---

### 12.4 Email Templates & Placeholders Directory

All email templates are stored in database table `email_templates`. Each template defines `subject`, `body_html`, and target `audience` (`customer` or `admin`). Placeholders use double curly brackets `{{variable}}`.

| Template Slug | Purpose | Audience | Available Placeholders |
|---|---|---|---|
| `order_placed_customer` | Customer order confirmation | `customer` | `{{order_number}}`, `{{customer_name}}`, `{{items_table}}`, `{{subtotal}}`, `{{shipping_fee}}`, `{{discount}}`, `{{total}}`, `{{payment_method}}`, `{{shipping_address}}`, `{{track_url}}`, `{{account_order_url}}`, `{{site_name}}` |
| `order_placed_admin` | New order notification alert | `admin` | `{{order_number}}`, `{{customer_name}}`, `{{customer_phone}}`, `{{customer_email}}`, `{{items_table}}`, `{{total}}`, `{{payment_method}}`, `{{admin_order_url}}` |
| `order_processing` | Order preparation notice | `customer` | `{{order_number}}`, `{{customer_name}}`, `{{track_url}}` |
| `order_shipped` | Shipment dispatch notice | `customer` | `{{order_number}}`, `{{customer_name}}`, `{{courier}}`, `{{tracking_number}}`, `{{total}}`, `{{track_url}}` |
| `order_completed` | Delivery success notice | `customer` | `{{order_number}}`, `{{customer_name}}`, `{{site_name}}` |
| `order_cancelled` | Order cancellation notice | `customer` | `{{order_number}}`, `{{customer_name}}`, `{{site_name}}` |
| `low_stock_admin` | Inventory low stock alert | `admin` | `{{product_name}}`, `{{variant_label}}`, `{{stock}}`, `{{threshold}}`, `{{admin_product_url}}` |
| `test_email` | SMTP diagnostic test email | `admin` | `{{site_name}}`, `{{sent_at}}` |
| `customer_welcome` | Welcome email after signup | `customer` | `{{customer_name}}`, `{{site_name}}`, `{{account_url}}` |
| `customer_verify_email` | Email verification activation link | `customer` | `{{customer_name}}`, `{{verify_url}}`, `{{expires_in}}`, `{{site_name}}` |
| `customer_password_reset` | Password reset link | `customer` | `{{customer_name}}`, `{{reset_url}}`, `{{expires_in}}`, `{{site_name}}` |
| `customer_password_changed` | Security notice of password change | `customer` | `{{customer_name}}`, `{{ip}}`, `{{changed_at}}`, `{{reset_url}}`, `{{site_name}}` |
| `customer_email_change` | Confirmation link for new email | `customer` | `{{customer_name}}`, `{{verify_url}}`, `{{site_name}}` |
| `contact_received_admin` | Contact form inquiry alert | `admin` | `{{name}}`, `{{email}}`, `{{phone}}`, `{{message}}`, `{{order_number}}` |
| `newsletter_confirm` | Newsletter double opt-in confirmation | `customer` | `{{confirm_url}}`, `{{site_name}}` |
| `review_received_admin` | New product review alert | `admin` | `{{author_name}}`, `{{rating}}`, `{{product_name}}`, `{{body}}`, `{{admin_review_url}}` |

---

### 12.5 Deployment, Tests, and Tools Summary

1. **Server Architecture & Web Server Configuration (`DEPLOY.md` & `Deployment.txt`)**:
   - **Target OS**: Linux (Ubuntu 22.04 LTS / 24.04 LTS) or Windows (XAMPP).
   - **Web Server**: Apache 2.4 with `mod_rewrite`, `mod_headers`, `mod_deflate`, `mod_expires`.
   - **Permissions**:
     - Web root: `/home/ubuntu/e-commerce-system-production`
     - External uploads: `/home/ubuntu/files` owned by `www-data:www-data`, permissions `0775`.
     - Internal storage & cache: `storage/cache`, owned by `www-data:www-data`, permissions `0775`.
   - **Storage Symlink**: Symbolic link created via `php storage_link.php` mapping `/home/ubuntu/files` &rarr; `public/uploads` for sub-millisecond static image delivery without passing through PHP.
2. **Automated Test Suites (`tests/`)**:
   - `tests/test_product_import_suite.php`: Integration test verifying CSV import edge cases (simple vs variable, duplicate SKUs, missing headers, special characters).
   - `tests/test_rbac_suite.php`: Unit tests verifying role permissions (`admin`, `editor`, `viewer`) and unauthorized 403 blocks.
   - `tests/test_storage_suite.php`: Test harness for `StorageManager` validating local disk storage, S3 cloud upload, deletion, and fallback image URL generation.
3. **CLI Management Utilities (`bin/`)**:
   - `bin/migrate.php`: Database migration runner executing `.sql` files in `migrations/` and recording applied versions in `schema_migrations`.
   - `bin/cleanup-orphans.php`: Scans uploads directory vs database tables (`product_images`, `customers.avatar`, `storefront_banners`) and removes unreferenced physical files.
   - `bin/migrate-files-to-s3.php`: Copies local upload files to AWS S3 bucket and updates database metadata records.
   - `bin/sync-storage.php`: Continuous or periodic sync utility reconciling local storage with remote cloud bucket.
   - `bin/setup-ssl.sh`: Shell script automating Certbot / Let's Encrypt TLS certificate provisioning for Apache VirtualHost.

---

## 13. Migration assessment

### 13.1 Recommended Rust Template Engine: Tera

**Recommendation: Tera** (over Askama and Maud).

**Comparative Evaluation**:
- **Tera (Recommended)**:
  - **Syntax Match**: Tera uses Jinja2 / Django syntax (`{% extends "layout.html" %}`, `{% block content %}`, `{{ price | format_money }}`). This matches the CozaStore PHP layout structure almost 1:1, allowing templates in `storefront/` and `includes/layout/` to be ported with zero structural or HTML class modifications.
  - **Rapid UI Iteration**: Tera loads and parses templates at runtime (with optional caching). Frontend styling and markup adjustments do not require recompiling the entire Rust binary.
  - **Custom Filters & Functions**: Tera allows registering Rust functions as filters (`format_bdt`, `url`, `route`, `csrf_field`).
  - **Macro Ecosystem**: Reusable components (breadcrumbs, pagination, modals, cart drawer) map cleanly into Tera macros.
- **Askama**:
  - Compiles templates directly into Rust code at build time. While offering microsecond execution speeds, every minor CSS/HTML tweak requires a full `cargo build`. Furthermore, Askama's rigid compile-time type system makes dynamic, nullable PHP template variables cumbersome to manage.
- **Maud**:
  - Requires writing all HTML in pure Rust macro DSL (`html! { div.class { "text" } }`). This would require manually converting thousands of lines of CozaStore HTML, introducing extreme risk of markup regressions and making zero-UI-change impossible to verify.

---

### 13.2 Password Hashes and Session Migration Strategy

1. **Can existing PHP password hashes be verified from Rust?**
   - **YES**.
   - **Admin Passwords**: Stored as standard bcrypt `$2y$10$...`. Rust's `bcrypt` crate verifies them directly without changes:
     ```rust
     bcrypt::verify(&plain_password, &admin_user.password_hash)
     ```
   - **Customer Passwords**: Customer auth uses a **two-tier hash**:
     In PHP (`includes/customer_auth.php:501`): `base64_encode(hash('sha256', $password, true))` is hashed with bcrypt.
     In Rust, verify by computing the exact same pre-hash before checking bcrypt:
     ```rust
     use sha2::{Sha256, Digest};
     use base64::engine::general_purpose::STANDARD as BASE64;
     use base64::Engine;

     let mut hasher = Sha256::new();
     hasher.update(plain_password.as_bytes());
     let raw_sha = hasher.finalize();
     let pre_hashed = BASE64.encode(raw_sha);

     bcrypt::verify(&pre_hashed, &customer.password_hash)
     ```
2. **Can PHP sessions be read from Rust?**
   - **NO**. PHP sessions are stored on disk in `/var/lib/php/sessions/sess_*` using PHP's proprietary serialization format (`session_encode()`). Decoding dynamic PHP objects in Rust is error-prone and brittle.
   - **Session Cutover Strategy**:
     - Active PHP web sessions terminate upon cutover (users re-login).
     - **Seamless Remember-Me Preservation**: Customer "Remember-Me" tokens stored in `customer_tokens` (`selector` 24 hex chars, `token_hash = sha256(secret)`) **CAN** be read and validated directly by Rust SQLx!
     - When a customer visits with cookie `remember_me=selector:validator`:
       1. Rust extracts `selector` and `validator`.
       2. Looks up row in `customer_tokens` where `selector = :selector AND expires_at > NOW()`.
       3. Checks `sha256(validator) == token_hash`.
       4. If valid, automatically issues a fresh Axum session cookie without prompting for credentials.

---

### 13.3 Top 15 Highest-Risk PHP Behaviors to Reproduce in Rust

| Risk Rank | PHP Behavior & Architecture | File Citation | Rust Implementation Risk & Mitigation |
|---|---|---|---|
| **1** | **Two-Tier Customer Password Pre-Hashing** | `includes/customer_auth.php:501` | If Rust checks raw password against bcrypt without `base64(sha256(pw, raw))`, **all customer logins will fail**. Must wrap password verification in a custom helper. |
| **2** | **Unmigrated `product_sales_summary` Runtime Table** | `cron/rebuild_sales_summary.php:24`, `storefront/shop/index.php:38` | Table is missing from `sql/schema.sql`. Rust migration must create an explicit SQLx migration for this table before querying best-sellers. |
| **3** | **Dynamic SQL String Concatenation in Admin Inventory** | `admin/inventory.php:225` | Concatenates `$perPage` and `$offset` directly into SQL strings. Rust SQLx rejects dynamic queries at compile time; must use parameterized `.bind()` or structured QueryBuilder. |
| **4** | **Cartesian Variant Matrix Resolution** | `admin/product_form.php:328`, `assets/js/admin-variants.js` | Option matrix generation and variant matching must replicate exact key order (`{parent_sku}-{val1}-{val2}`). |
| **5** | **Race Conditions in Stock Adjustments** | `includes/inventory.php:12` | PHP relies on atomic MySQL `UPDATE products SET stock = stock + :delta WHERE stock + :delta >= 0`. Rust must use strict SQLx transactions with row locks to avoid deadlocks. |
| **6** | **Deadlock Prevention via Sorted Line Items** | `storefront/cart/checkout.php:280` | Checkout orders line items by `variant_id` / `product_id` ascending before locking rows. Omitting this in Rust will cause MySQL InnoDB deadlocks under concurrency. |
| **7** | **ETag 304 Polling and Immediate Session Release** | `api/availability.php:15`, `send_etag_response()` | PHP explicitly calls `session_write_close()` before long-running logic. In Axum, session middleware must avoid holding global write locks across async requests. |
| **8** | **District-based Shipping Calculation** | `includes/data/bd_districts.php`, `includes/functions.php:382` | Shipping relies on strict division of 64 districts (`inside_dhaka` = 60 vs `outside_dhaka` = 120), overridden by free shipping threshold evaluated **after** coupon discount. |
| **9** | **Order Number Collision Retries** | `storefront/cart/checkout.php:297` | PHP catches MySQL duplicate key `23000` up to 5 times. Rust SQLx must match database error codes and retry order number generation. |
| **10** | **Dual-Mode URL Routing (`PRETTY_URLS`)** | `includes/functions.php:218` | Routing helper checks `PRETTY_URLS` setting. Rust Axum must bind both clean paths (`/shop/product/{slug}`) and legacy paths (`/storefront/shop/product.php?slug={slug}`). |
| **11** | **Composite Session Cart Keys** | `includes/functions.php:305` | Simple products use `"{id}"`, variable products use `"{id}:{vid}"`. Rust cart deserializer must parse both integer and string composite keys seamlessly. |
| **12** | **Non-blocking Mail Queue with `SKIP LOCKED`** | `cron/process_mail_queue.php:60` | Queue worker relies on `FOR UPDATE SKIP LOCKED`. Rust background worker using Tokio tasks must preserve `SKIP LOCKED` query semantics. |
| **13** | **Dynamic Key-Value Settings Type Casting** | `includes/functions.php:104` | Settings table stores raw text with runtime casting (`(int)`, `(float)`, `json_decode()`). Rust must implement a strongly-typed `SettingsMap` with safe fallbacks. |
| **14** | **Form Bot Traps (Honeypot + Elapsed Time)** | `api/newsletter.php:25`, `api/review.php:40` | Rejection if `website` filled or `< 2s` elapsed between render and POST. Rust form extractors must enforce these validation guards. |
| **15** | **Multi-tier Media Storage Resolution** | `src/Storage/StorageManager.php:140` | Resolves media across database file records &rarr; local disk &rarr; AWS S3 &rarr; SVG fallback. Rust media handler must replicate this exact cascade. |

---

### 13.4 Recommended Module Migration Order

| Phase | Module Name | Size | Dependencies | Description |
|---|---|---|---|---|
| **Phase 1** | **Core Foundation & Database** | `M` | None | Axum server setup, SQLx connection pool, `.env` config, database models, and migration of `product_sales_summary`. |
| **Phase 2** | **Helpers, Settings & Crypto** | `S` | Phase 1 | Money formatter, URL router, HMAC token generation, dynamic settings cache, and customer SHA256 pre-hasher. |
| **Phase 3** | **Auth, Session & Remember-Me** | `M` | Phase 2 | Axum session store, customer login/register, admin RBAC auth, and persistent cookie token validator. |
| **Phase 4** | **Catalog & Storefront Browsing** | `L` | Phase 3 | Tera template engine integration, shop listing, category view, PDP view, search suggestions, and stock availability API. |
| **Phase 5** | **Cart, Wishlist & Customer Account** | `M` | Phase 4 | Mini-cart drawer, cart operations, wishlist toggle, customer address book, order history, and data export. |
| **Phase 6** | **Checkout & Order Processing** | `L` | Phase 5 | 64-district shipping engine, coupon validator, atomic order creation transaction, and stock decrements. |
| **Phase 7** | **Background Workers & Cron** | `M` | Phase 6 | Tokio cron tasks: mail queue processor (SMTP), customer token purge, metrics collector, and sales summary rebuild. |
| **Phase 8** | **Admin Catalog & Orders** | `L` | Phase 3, 6 | Admin product CRUD, variant matrix generator, order fulfillment, status state machine, and CSV exports. |
| **Phase 9** | **Admin Settings, CMS & Monitoring** | `M` | Phase 8 | 150+ settings tabs, banner management, custom pages, and real-time Chart.js monitoring feed (`/proc/` parser). |
| **Phase 10** | **Media Storage & Final Cutover** | `S` | Phase 1-9 | StorageManager abstraction (local symlink vs S3 driver), Apache/Nginx reverse proxy config, and cutover execution. |

---

### 13.5 Top 10 Security Issues Ranked

| Rank | Security Vulnerability | File & Line Citation | Severity | Impact & Rust Remediation |
|---|---|---|---|---|
| **1** | **Missing CSRF on AJAX Cart Mutations** | `api/cart_ajax.php:12` | **HIGH** | Allows cross-origin websites to silently mutate or clear user cart via CSRF. Rust Axum must require CSRF validation on all state-changing endpoints. |
| **2** | **Raw SQL Limit/Offset Concatenation** | `admin/inventory.php:225` | **HIGH** | Direct string interpolation into SQL query. While values are currently cast, dynamic SQL string construction is dangerous. Rust SQLx prevents raw interpolation. |
| **3** | **Unauthenticated Admin Installer** | `admin/install.php:1` | **HIGH** | If `users` table is accidentally emptied or truncated, installer becomes publicly accessible to anyone. Rust must disable installer via compile-time flag or persistent config lockfile. |
| **4** | **Unrestricted File Upload MIME Sniffing** | `src/Storage/StorageManager.php:85` | **MEDIUM** | Validates MIME type using client headers and file extension. Malicious files could bypass extension checks if web server allows script execution. Rust should use `infer` crate for magic byte validation. |
| **5** | **Session File Locking Blocking Requests** | `includes/shop_bootstrap.php:45` | **MEDIUM** | Concurrent requests block until previous request releases PHP session lock. Axum async architecture eliminates thread blocking natively. |
| **6** | **Missing CSRF on Order Tracking Form** | `storefront/track/index.php:18` | **LOW** | Allows automated brute-force querying of order numbers. Rust should add rate-limiting and CSRF protection. |
| **7** | **Temporary File Rate Limiter Concurrency** | `api/availability.php:22` | **LOW** | Uses JSON files in `sys_get_temp_dir()` without strict locking, causing lost increments under high load. Rust should use an in-memory `dashmap` or Redis rate limiter. |
| **8** | **Full Server Path Leakage in Error Logs** | `cron/collect_metrics.php:65` | **LOW** | Exception traces written to disk expose internal absolute directory paths. Rust logging should sanitize file paths. |
| **9** | **Hardcoded Fallback APP_KEY** | `config/config.php:26` | **LOW** | Falls back to `'CHANGE_ME_TO_64_RANDOM_CHARS'`. Rust server must panic on boot if `APP_KEY` is not set or equals default placeholder. |
| **10** | **Weak Cookie Lax SameSite Default** | `includes/customer_auth.php:42` | **LOW** | Cookies use `SameSite=Lax`. Rust should set `SameSite=Strict` for sensitive auth cookies and enforce `Secure` in production. |

---

## 14. Open questions

1. **`product_sales_summary` Database Schema**:
   - The table `product_sales_summary` is actively referenced across `index.php`, `cron/rebuild_sales_summary.php`, `includes/catalog.php`, `admin/analytics_product.php`, and `admin/order_view.php`, but has no `CREATE TABLE` definition in `sql/schema.sql` or `migrations/`.
   - **Resolution Required**: An explicit SQL migration must be created in the Rust codebase:
     ```sql
     CREATE TABLE IF NOT EXISTS `product_sales_summary` (
       `product_id` INT UNSIGNED NOT NULL,
       `variant_id` INT UNSIGNED NOT NULL DEFAULT 0,
       `variant_label` VARCHAR(255) DEFAULT NULL,
       `units` INT UNSIGNED NOT NULL DEFAULT 0,
       `revenue` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
       `orders_count` INT UNSIGNED NOT NULL DEFAULT 0,
       `last_sale` DATETIME DEFAULT NULL,
       PRIMARY KEY (`product_id`, `variant_id`),
       KEY `idx_pss_units` (`units`),
       KEY `idx_pss_revenue` (`revenue`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
     ```
2. **Dynamic Schema Alteration in Metrics Collector (`cron/collect_metrics.php:150`)**:
   - `cron/collect_metrics.php` executes `SHOW COLUMNS FROM system_metrics` and dynamically executes `ALTER TABLE ADD COLUMN` at runtime if columns are missing.
   - In Rust, database schemas should be static and version-controlled via SQLx migrations. Dynamic table altering should be phased out in favor of static migrations.
3. **Background Media Sync (`bin/sync-storage.php`)**:
   - How is `bin/sync-storage.php` intended to run in production? Does it run as a persistent systemd daemon, or is media expected to sync synchronously on each file upload?
   - **Recommendation**: In Rust, handle S3 uploads asynchronously via Tokio background tasks immediately upon file ingestion, removing the need for a separate sync cron.
4. **Caching Layer Replacement**:
   - `includes/FileCache.php` serializes objects to filesystem disk files in `storage/cache/`.
   - In Rust Axum, this can be replaced with an in-memory LRU cache (`moka` crate) for ultra-fast sub-millisecond retrieval, or Redis if multi-node clustering is required in the future.

---
