# Jinispotro — Modern PHP E-Commerce System & Admin Panel

A high-performance, lightweight, self-contained e-commerce storefront and administrative management panel built with plain PHP (8.0+), PDO, and MySQL/MariaDB. Zero framework dependencies, no Composer required, and no JavaScript build steps. Runs reliably on standard shared hosting (XAMPP / cPanel) as well as Linux cloud VPS environments.

---

## Key Features

### 🛍️ Customer Storefront
- **Refined Editorial Design**: Clean, minimal, airy aesthetic with high accessibility, responsive across 360px to 1440px displays.
- **Dynamic Product Catalog**: Filter by category, price range, availability, and sorting (newest, best-sellers, featured).
- **Product Variants & Real-Time Availability**: Size, color, and custom variant swatches with live stock availability verification.
- **Frictionless Checkout**: Streamlined Cash-on-Delivery (COD) and bank transfer checkout with coupon support and automatic shipping calculation across all 64 Bangladesh districts.
- **Customer Self-Service Portal**:
  - Full authentication workflow: Registration, login, password resets, and email verification.
  - Profile customization, avatar management, and saved address book.
  - Interactive order history, delivery timeline tracking, and printable tax invoices.
  - Wishlist management and GDPR personal data export / account deletion.
- **Content Pages & Communication**:
  - CMS-backed pages (About Us, FAQ, Shipping & Returns, Terms, Privacy Policy).
  - Anti-spam protected contact inquiry form.
  - Newsletter double-opt-in subscription system.
  - Public order tracking via order number and phone/email lookup.

### 🛡️ Administrative Control Panel
- **Product & Inventory Management**: Variants, SKU matrices, atomic stock adjustments (`stock_adjust()`), and image galleries.
- **Order Lifecycle Management**: Order status updates, tracking consignment tracking, shipping method rules, and discount coupons.
- **Customer Management**: Customer accounts listing, spending metrics, block/unblock controls, and privacy-compliant customer deletion (orders unlinked for accounting integrity).
- **Asynchronous Mail Queue**: Non-blocking SMTP email delivery via background queue worker with 10+ customizable templates.
- **System Monitoring**: Live real-time traffic throughput, CPU/memory profiling, request timings, and alert logging.
- **Storefront Design & CMS Settings**: Real-time toggles for banners, announcements, theme accents, dark mode, and pretty URLs.

---

## Technical Stack & Requirements

- **PHP Version**: PHP 8.0 or higher (with `pdo_mysql`, `gd`, `mbstring`, `openssl`). Polyfill included for maximum backward compatibility.
- **Database**: MySQL 5.7+ or MariaDB 10.4+ (InnoDB engine, `utf8mb4` encoding).
- **Web Server**: Apache 2.4+ (with `mod_rewrite`, `mod_deflate`, `mod_headers`, `mod_expires`) or Nginx.
- **Frontend**: Vanilla CSS and ES2018 JavaScript, self-hosted Inter fonts, and self-hosted Bootstrap components. No external third-party CDN dependencies.

---

## Installation & Setup

### 1. Database Initialization
Create your database and import the consolidated schema:
```bash
mysql -u root -p -e "CREATE DATABASE php_admin_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p php_admin_panel < sql/schema.sql
```
*(Alternatively, import `sql/schema.sql` via phpMyAdmin).*

### 2. Environment Configuration
Copy the sample environment file and configure your credentials:
```bash
cp .env.example .env
```
Update `.env` with your database credentials:
```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=php_admin_panel
DB_USER=root
DB_PASS=your_password
BASE_URL=/
APP_KEY=generate_64_random_characters_here
```

### 3. Default Administrative Access
The schema creates the initial administrative account:
- **URL**: `http://localhost/admin/login.php`
- **Email**: `admin@admin.com`
- **Password**: `admin123`

*(Be sure to update this password immediately upon first login).*

---

## Background Scheduled Tasks (Cron)

To ensure instantaneous checkout and page loads, transactional emails and system maintenance run in the background.

### Mail Queue Worker
Run every minute:
- **Linux / cPanel Cron**:
  ```cron
  * * * * * php /path/to/project/cron/process_mail_queue.php >/dev/null 2>&1
  ```
- **Windows Task Scheduler (XAMPP)**:
  - Program: `C:\xampp\php\php.exe`
  - Arguments: `C:\xampp\htdocs\shop\cron\process_mail_queue.php`
  - Trigger: Repeat every 1 minute indefinitely.

### Customer & Maintenance Cleanup
Run daily at midnight:
```cron
0 0 * * * php /path/to/project/cron/customer_maintenance.php >/dev/null 2>&1
```

---

## Architecture & URL Routing

The storefront structure isolates entry points into structured directories while keeping a single `index.php` in the web root.

For detailed folder structure documentation, see [`docs/STRUCTURE.md`](docs/STRUCTURE.md).

### Route Map (Standard vs Clean URLs)

| Route Name | File Path | Clean URL Pattern |
|---|---|---|
| `home` | `index.php` | `/` |
| `shop` | `shop/index.php` | `/shop` |
| `category` | `shop/category.php` | `/category/{slug}` |
| `product` | `shop/product.php` | `/product/{slug}` |
| `cart` | `cart/index.php` | `/cart` |
| `checkout` | `cart/checkout.php` | `/checkout` |
| `order_confirmation` | `cart/confirmation.php` | `/order/confirmation` |
| `track` | `track/index.php` | `/track` |
| `login` | `auth/login.php` | `/login` |
| `register` | `auth/register.php` | `/register` |
| `page` | `pages/view.php` | `/page/{slug}` |
| `contact` | `pages/contact.php` | `/contact` |
| `newsletter` | `pages/newsletter.php` | `/newsletter` |
| `sitemap` | `seo/sitemap.php` | `/sitemap.xml` |

---

## Security Highlights

- **Prepared Statements**: 100% of SQL queries use parameterized PDO prepared statements.
- **CSRF Protection**: All state-modifying POST requests require cryptographically validated CSRF tokens.
- **Rate Limiting**: Brute-force throttling on customer authentication and public inquiry endpoints.
- **Atomic Concurrency**: Inventory allocations use row-locking and atomic adjustments (`stock_adjust()`) preventing overselling.
- **Web-Denied Folders**: Strict `.htaccess` rules deny direct HTTP access to `/config/`, `/storage/`, `/sql/`, `/cron/`, `/tests/`, and `/tools/`.

---

## License

This project is licensed under the [MIT License](LICENSE).
