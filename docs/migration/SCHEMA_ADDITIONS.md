# Database Schema Additions

This document logs all additive schema modifications made to the MySQL database for the Rust backend.
Per project rules:
1. All changes must be strictly additive (new table, new column, new index).
2. No drops, renames, or existing column type changes are permitted.
3. Every change must have a corresponding numbered SQL migration in `/rust-backend/migrations/`.
4. The PHP application must remain 100% operational after each addition.

---

### Migration 0001: Tower Sessions Store Table
- **File:** `rust-backend/migrations/0001_tower_sessions.sql`
- **Purpose:** Storage table for Axum / Tower asynchronous user sessions (`tower-sessions-sqlx-store`).
- **PHP Compatibility Impact:** None. PHP uses native file-based sessions or custom sessions without touching this table.

---

### Migration 0002: Product Sales Summary Precomputed Table
- **File:** `rust-backend/migrations/0002_product_sales_summary.sql`
- **Purpose:** Precomputed sales aggregation table queried in `index.php` (Best Sellers section) and written by `includes/catalog.php:product_sales_summary_record_order()` and `cron/rebuild_sales_summary.php`. This table was actively referenced in PHP application source code but was missing from `sql/schema.sql`.
- **PHP Compatibility Impact:** Highly positive. Prevents PHP `PDOException` in `index.php` and allows PHP cron `rebuild_sales_summary.php` and order completion sales tracking to work without errors.

