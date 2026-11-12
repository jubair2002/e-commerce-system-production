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

## Installation & Deployment Options

### Option A: One-Command Docker Deployment with Prometheus & Grafana (Recommended)

The entire production stack (PHP 8.2 Apache App, MySQL 8.0, Prometheus, pre-configured Grafana, and Exporters) can be spun up in a single command.

1. **Configure Environment**:
   ```bash
   cp .env.example .env
   ```
   Open `.env` and enter your AWS S3 details:
   ```ini
   STORAGE_PRIMARY=s3
   STORAGE_FALLBACK=local
   AWS_REGION=ap-southeast-1
   S3_BUCKET=your-production-bucket-name
   AWS_ACCESS_KEY_ID=your-aws-access-key-id
   AWS_SECRET_ACCESS_KEY=your-aws-secret-access-key
   ```
   *(All database passwords, background cron daemons, and Prometheus/Grafana datasources and dashboards are pre-configured out-of-the-box).*

2. **Launch the Full Stack**:
   ```bash
   docker compose up -d
   ```

3. **Access Your Services**:
   - **Storefront**: `http://localhost/`
   - **Admin Control Panel**: `http://localhost/admin/` (`admin@admin.com` / `admin123`)
   - **Grafana Dashboards**: `http://localhost:3000` (`admin` / `admin` or Anonymous Viewer)
   - **Prometheus Metrics Engine**: `http://localhost:9090`

For reverse proxy instructions (routing `grafana.domain.com` and `yourdomain.com` with SSL), see [`docs/DOCKER_DEPLOYMENT.md`](docs/DOCKER_DEPLOYMENT.md).

---

### Option B: Traditional VPS / Local Manual Setup

#### 1. Database Initialization
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

### Storage Fallback Sync Worker (Dual / HA Mode)
When running with `STORAGE_PRIMARY=s3` and `STORAGE_FALLBACK=local`, uploads that fell back to local storage during S3 outages are automatically synced to S3 by this worker. Run every 5 minutes:
```cron
*/5 * * * * php /path/to/project/bin/sync-storage.php >/dev/null 2>&1
```

### Storage Orphan & Retention Purge
Purges soft-deleted media objects older than retention window (default 30 days) and identifies unreferenced file records. Run daily at 3:00 AM:
```cron
0 3 * * * php /path/to/project/bin/cleanup-orphans.php --days=30 >/dev/null 2>&1
```

---

## Storage Layer & AWS S3 Configuration

The application features a production-grade, enterprise storage abstraction layer (`App\Storage`) supporting **Local Disk** and **Amazon Web Services (AWS) S3** (or S3-compatible object stores such as Cloudflare R2, MinIO, or DigitalOcean Spaces), usable independently or together in a high-availability dual configuration.

### Storage Modes & Failover Architecture

1. **Local Only** (`STORAGE_PRIMARY=local`, `STORAGE_FALLBACK=none`):
   - Standard out-of-the-box mode requiring no external cloud setup.
   - Files are stored under `assets/uploads/` (or custom `LOCAL_STORAGE_PATH`).
   - Hardened with directory traversal protection and Apache/Nginx execution blocks.
2. **S3 Only** (`STORAGE_PRIMARY=s3`, `STORAGE_FALLBACK=none`):
   - All uploads are streamed directly to AWS S3.
   - Multipart uploads (`Aws\S3\ObjectUploader`) are automatically engaged for objects > 5MB.
   - Public URLs leverage Amazon CloudFront or S3 virtual-hosted URLs.
3. **Dual High-Availability Mode** (`STORAGE_PRIMARY=s3`, `STORAGE_FALLBACK=local`):
   - Writes target primary storage (S3) first.
   - If S3 is temporarily unreachable or throws an exception, the upload automatically fails over to local disk without dropping the user's request.
   - The file record is flagged with `sync_status = 'pending'`.
   - The background worker `bin/sync-storage.php` asynchronously promotes pending files to S3 once connectivity is restored.
   - Reads check the provider recorded on the database row, falling back to alternate providers if an object is missing.

### Environment Configuration (.env)

| Variable | Default | Description |
|---|---|---|
| `STORAGE_PRIMARY` | `local` | Primary storage engine (`local` or `s3`). |
| `STORAGE_FALLBACK` | `none` | Fallback storage engine (`none`, `local`, or `s3`). |
| `AWS_REGION` | `ap-southeast-1` | AWS region where the bucket resides. |
| `S3_BUCKET` | *(empty)* | AWS S3 bucket name. |
| `AWS_ACCESS_KEY_ID` | *(empty)* | IAM Access Key ID. Leave empty on EC2 to use IAM Roles! |
| `AWS_SECRET_ACCESS_KEY` | *(empty)* | IAM Secret Access Key. Leave empty on EC2 to use IAM Roles! |
| `S3_PUBLIC_BASE_URL` | *(empty)* | Custom CloudFront CDN domain (e.g. `https://d123456.cloudfront.net`). |
| `S3_KEY_PREFIX` | `uploads/` | Prefix prepended to all S3 object keys. |
| `LOCAL_STORAGE_PATH` | `assets/uploads` | Path on server disk for local storage. |
| `LOCAL_PUBLIC_BASE_URL` | *(auto)* | Base URL for local files (derived from `BASE_URL`). |
| `S3_ENDPOINT` | *(empty)* | Custom endpoint for MinIO / LocalStack / Cloudflare R2. |
| `S3_USE_PATH_STYLE_ENDPOINT`| `false` | Enable path-style addressing (`true` for MinIO/LocalStack). |

> [!TIP]
> **EC2 IAM Instance Role (Recommended)**: On AWS EC2, leave `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` empty. The AWS SDK for PHP automatically retrieves secure, rotating credentials from the EC2 Instance Metadata Service (IMDSv2) via IAM Instance Profile.

### Administrative Control Panel Configuration

Storage can also be configured and monitored directly in the browser:
1. Log in to the Admin Panel (`/admin/login.php`) as Super Admin.
2. Navigate to **Settings** &rarr; **Storage & AWS S3** tab.
3. Select your desired Storage Mode (Local Disk Only, AWS S3 Only, or Dual Primary/Fallback).
4. Enter your S3 bucket name, region, CloudFront URL, and optional credentials.
5. Click **Test S3 Connection** to execute an atomic put-get-delete verification probe against your bucket.
6. Click **Save Storage Settings**. Credentials stored in the database are encrypted at rest using AES-256-CBC via `APP_KEY` and masked on screen.

### Database Migrations CLI

Database schema changes are managed via versioned migration files in `migrations/`:
```bash
# Check status of applied migrations
php bin/migrate.php status

# Apply pending migrations
php bin/migrate.php up

# Rollback last migration (safe reversal)
php bin/migrate.php down
```

The migration runner records applied migrations in `schema_migrations` and creates the `files` table along with relational `file_id` columns across `products`, `product_images`, `product_variants`, `customers`, `users`, and `storefront_banners`.

### Migrating Existing Files to S3

To transfer existing on-disk files (`assets/uploads/`) to S3 without downtime:
```bash
# Dry run to preview operations without modifying database or uploading
php bin/migrate-files-to-s3.php --dry-run

# Migrate files in batches of 50
php bin/migrate-files-to-s3.php --limit=50

# Migrate a specific purpose (e.g. avatars, products, banners)
php bin/migrate-files-to-s3.php --purpose=product
```
> [!IMPORTANT]
> The migration script is **non-destructive, idempotent, and resumable**. It copies files to S3, calculates SHA-256 checksums, creates metadata records in `files`, and updates entity references inside database transactions. Existing files on disk are **never deleted or moved**.

---

## AWS Setup Guidance

### 1. S3 Bucket Configuration
- **Bucket name**: e.g., `jinispotro-production-media`
- **Object Ownership**: Set to **ACLs disabled (Bucket owner enforced)**. Antigravity/Jinispotro uses IAM and bucket policies; object ACLs are disabled for security.
- **Block Public Access**: Keep **Block all public access = ON** if serving media through AWS CloudFront with Origin Access Control (OAC). If serving directly from S3 without CloudFront, turn off Block Public Access and attach the public read bucket policy below.
- **Default Encryption**: Enable **Server-side encryption with Amazon S3 managed keys (SSE-S3)**.
- **Lifecycle Rules**: Create a lifecycle rule to automatically abort incomplete multipart uploads after 7 days.

### 2. IAM Policy (Least-Privilege)
Attach this policy to your EC2 IAM Role (or IAM User if using static keys):
```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "JinispotroBucketLevelAccess",
            "Effect": "Allow",
            "Action": [
                "s3:ListBucket",
                "s3:GetBucketLocation"
            ],
            "Resource": "arn:aws:s3:::jinispotro-production-media"
        },
        {
            "Sid": "JinispotroObjectLevelAccess",
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:DeleteObject",
                "s3:PutObjectTagging"
            ],
            "Resource": "arn:aws:s3:::jinispotro-production-media/*"
        }
    ]
}
```

### 3. S3 CORS Configuration (for Presigned Direct Uploads)
Under S3 Bucket &rarr; Permissions &rarr; Cross-origin resource sharing (CORS):
```json
[
    {
        "AllowedHeaders": ["*"],
        "AllowedMethods": ["GET", "PUT", "HEAD"],
        "AllowedOrigins": ["https://yourdomain.com", "http://localhost"],
        "ExposeHeaders": ["ETag"],
        "MaxAgeSeconds": 3600
    }
]
```

### 4. CloudFront CDN Setup with Origin Access Control (OAC)
For maximum speed, low latency, and zero origin egress cost:
1. Create a CloudFront Distribution with your S3 bucket as the origin.
2. Select **Origin Access Control (OAC)** and create a new control setting.
3. CloudFront provides a Bucket Policy statement to paste into your S3 Bucket Permissions:
```json
{
    "Version": "2012-10-17",
    "Statement": {
        "Sid": "AllowCloudFrontServicePrincipalReadOnly",
        "Effect": "Allow",
        "Principal": {
            "Service": "cloudfront.amazonaws.com"
        },
        "Action": "s3:GetObject",
        "Resource": "arn:aws:s3:::jinispotro-production-media/*",
        "Condition": {
            "StringEquals": {
                "AWS:SourceArn": "arn:aws:cloudfront::ACCOUNT_ID:distribution/DISTRIBUTION_ID"
            }
        }
    }
}
```
4. Set `S3_PUBLIC_BASE_URL=https://d123456789.cloudfront.net` in your `.env`.

### 5. Local S3 Development with MinIO
To simulate AWS S3 locally without cloud accounts:
```bash
docker compose up -d minio createbuckets
```
Configure your `.env`:
```ini
STORAGE_PRIMARY=s3
STORAGE_FALLBACK=local
AWS_REGION=us-east-1
S3_BUCKET=jinispotro-media
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
S3_ENDPOINT=http://127.0.0.1:9000
S3_USE_PATH_STYLE_ENDPOINT=true
S3_PUBLIC_BASE_URL=http://127.0.0.1:9000/jinispotro-media
```

---

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
