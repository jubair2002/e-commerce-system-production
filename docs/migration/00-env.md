# 00 - Migration Environment & Parity Setup

This document specifies the exact environment versions, port assignments, commands, and database reset procedures for the Jinish Potro PHP-to-Rust migration parity stack.

---

## 1. Toolchain & Environment Versions

| Tool | Version | Detection / Installation Path | Status |
| :--- | :--- | :--- | :--- |
| **OS** | Ubuntu 26.04.1 LTS (Linux 7.0.0-43-generic x86_64) | Host system | Verified |
| **rustc** | 1.100.0 (stable) | `$HOME/.cargo/bin/rustc` | Verified |
| **cargo** | 1.100.0 | `$HOME/.cargo/bin/cargo` | Verified |
| **rustup** | 1.29.3 | `$HOME/.cargo/bin/rustup` | Verified |
| **clippy** | 0.1.100 | `$HOME/.cargo/bin/cargo-clippy` | Verified |
| **rustfmt** | 1.11.0 | `$HOME/.cargo/bin/cargo-fmt` | Verified |
| **cargo-watch** | 8.5.3 | `$HOME/.cargo/bin/cargo-watch` | Installed |
| **sqlx-cli** | 0.9.1 (mysql, rustls) | `$HOME/.cargo/bin/cargo-sqlx` | Installed |
| **cargo-nextest** | 0.9.152 | `$HOME/.cargo/bin/cargo-nextest` | Installed |
| **cargo-audit** | 0.23.0 | `$HOME/.cargo/bin/cargo-audit` | Installed |
| **Docker** | 29.10.0 / Compose v2.41.0 | `/usr/bin/docker` | Verified |
| **Node.js** | v26.13.1 (npm 11.20.0) | `/home/jubair/.nvm/versions/node/v26.13.1/bin/node` | Verified (for Playwright) |
| **MySQL Client** | 8.4.13 | `/usr/bin/mysql` | Verified |
| **PHP (Container)** | 8.2 (Apache) | Built from project `Dockerfile` (untouched) | Verified |

---

## 2. Parity Network Topology & Ports

```
                          +-------------------------+
                          |   Nginx Reverse Proxy   |
                          |       Port :8080        |
                          +------------+------------+
                                       |
                   +-------------------+-------------------+
                   |                                       |
                   v (Default or X-Backend: php)           v (X-Backend: rust)
        +----------------------+                +----------------------+
        |      PHP Apache      |                |      Rust Axum       |
        |      Port :8081      |                |      Port :8082      |
        +----------+-----------+                +----------+-----------+
                   |                                       |
                   +-------------------+-------------------+
                                       |
                                       v
                          +-------------------------+
                          |      MySQL 8.0 DB       |
                          |       Port :3306        |
                          +-------------------------+
```

| Service | Port (Host) | Container Name | Description |
| :--- | :--- | :--- | :--- |
| **Nginx Proxy** | `8080` | `jinispotro_parity_nginx` | Front entrypoint supporting dynamic routing via `X-Backend: rust` or `php` |
| **PHP App** | `8081` | `jinispotro_parity_php` | Production PHP 8.2 + Apache application (built from untouched `Dockerfile`) |
| **Rust App** | `8082` | `jinispotro_parity_rust` | Rust backend (Axum + SQLx + Tokio + Tera) built from `rust-backend/Dockerfile` |
| **MySQL 8.0** | `3306` | `jinispotro_parity_db` | Shared database loaded with schema + migrations + additions |

---

## 3. Stack Management Commands

### Starting the Stack
Build and start all services in detached mode:
```bash
docker compose -f docker-compose.parity.yml up -d --build
```

### Checking Service Health & Logs
```bash
# View status of all parity containers
docker compose -f docker-compose.parity.yml ps

# Follow logs across all containers
docker compose -f docker-compose.parity.yml logs -f

# Follow logs for specific backend
docker compose -f docker-compose.parity.yml logs -f rust-app
docker compose -f docker-compose.parity.yml logs -f php-app
```

### Stopping the Stack
```bash
docker compose -f docker-compose.parity.yml down
```

---

## 4. Database Reset Procedure

The database entrypoint automatically provisions a fresh database on first boot using SQL files located in `docker/parity-initdb.d/`.

### Automated Reset (Recommended)
To completely wipe the database volume and re-run all initialization migrations from scratch:
```bash
docker compose -f docker-compose.parity.yml down -v
docker compose -f docker-compose.parity.yml up -d --build
```

### Initialization Sequence Applied:
1. `01-schema.sql`: Base database `php_admin_panel`, 39 tables, initial views (`v_product_stock`, `v_sales_by_variant`), and seed data.
2. `02-001_storage_layer.up.sql`: Adds `files` table, creates `file_id` columns and foreign keys.
3. `03-002_add_meta_keywords.up.sql`: Adds `meta_keywords` column to `products`.
4. `04-003_add_user_permissions.up.sql`: Updates `users.role` and adds `permissions` JSON column.
5. `05-product_sales_summary.sql`: Creates `product_sales_summary` precomputed sales table.
6. `06-tower_sessions.sql`: Creates `tower_sessions` table for Axum async session store.

### Manual SQL Verification:
```bash
mysql -h 127.0.0.1 -P 3306 -u jinispotro -psecret123 php_admin_panel -e "SHOW TABLES;"
```

---

## 5. Local Rust Backend Development (Native)

For rapid development outside Docker:

```bash
# Set path for Rust and Node
export PATH="/home/jubair/.nvm/versions/node/v26.13.1/bin:$HOME/.cargo/bin:$PATH"

# Run tests across workspace
cd rust-backend
cargo test --workspace

# Or using cargo-nextest
cargo nextest run --workspace

# Lint with strict warnings enforcement
cargo clippy --workspace -- -D warnings

# Run local development server
cargo run --bin web
```
