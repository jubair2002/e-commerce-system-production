# Upgrade pack for `php-ecommerce-admin-panel`

> **Repository:** [https://github.com/jubair2002/e-commerce-system-production.git](https://github.com/jubair2002/e-commerce-system-production.git)

```
upgrade-pack/
├── README.md
├── sql/                      <- copy into the repo's /sql folder
│   ├── 001_product_variants.sql        options, values, variants, gallery, new product columns, presets
│   ├── 002_inventory_orders_cod.sql    stock ledger, shipping, coupons, COD + order upgrade, order history
│   ├── 003_mail_system.sql             mail settings, 8 default templates, email queue
│   ├── 004_monitoring.sql              system/network/traffic metrics, alert rules
│   ├── 005_analytics.sql               events, daily rollups, search log, sales view
│   ├── 006_storefront_settings.sql     banners + storefront/SEO/theme settings + indexes
│   ├── 007_backfill_existing_data.sql  run last: history, opening stock, sold_count
│   └── upgrade_all.sql                 001..007 in one file
└── prompts/                  <- give to your AI coding assistant, in order
    ├── 00_MASTER_CONTEXT_and_ORDER.md  (paste first, every session)
    ├── 01_core_refactor_helpers.md
    ├── 02_admin_product_variants.md
    ├── 03_realtime_availability_stock.md
    ├── 04_storefront_redesign.md
    ├── 05_cart_checkout_cod.md
    ├── 06_admin_store_settings_cms.md
    ├── 07_admin_mail_system.md
    ├── 08_admin_system_monitoring.md
    ├── 09_admin_analytics_dashboard.md
    └── 10_security_qa_deploy.md
```

## Run the SQL
1. Install the original app first (`admin/install.php`, then `admin/migrate.php`) so `schema.sql` + `migrations.sql` are applied.
2. Back up: `mysqldump -u root php_admin_panel > backup.sql`
3. phpMyAdmin → select `php_admin_panel` → Import → `sql/upgrade_all.sql`
   (or CLI: `mysql -u root < sql/upgrade_all.sql`).
4. Safe to run again; existing data is kept.

Notes
- The files start with `USE php_admin_panel;`. If your DB has another name, change that one line.
- Needs MySQL 5.7+ or MariaDB 10.x. Tested on MariaDB 10.11 (clean install, then run twice).
- Existing products stay "simple" and keep working; variants are opt-in per product.
