# Migration Progress Tracker

| Module | Status | Parity Result | Notes |
| :--- | :--- | :--- | :--- |
| **Workspace & Tooling (Prompt 1)** | Complete | Passed | Cargo workspace (5 crates: common, domain, db, jobs, web), tools verified (rustup, clippy, rustfmt, cargo-watch, sqlx-cli, cargo-nextest, cargo-audit, docker, node, mysql) |
| **Parity Environment (Prompt 1)** | Complete | Passed | `docker-compose.parity.yml`: MySQL 8, untouched PHP Apache (:8081), Rust Axum (:8082), Nginx (:8080) with header-based/path routing; `docker/parity-initdb.d` initialization sequence |
| **Environment Docs (Prompt 1)** | Complete | Passed | `docs/migration/00-env.md`: versions, start/stop commands, port assignments, and clean DB reset instructions |
| **Source Verification (Prompt 2)** | Complete | Passed | `VERIFIED_FACTS.md` & `DISCREPANCIES.md`: resolved cart key (`{id}:{vid}`), remember-me cookie (`customer_remember`), table inventory (43 tables), 142 settings contract, route map, AES-256-CBC, and hooks; 11 golden vector JSON fixtures verified in Rust tests |
| **Parity Test Suite (Prompt 3)** | Complete | Passed | `PARITY.md`: deterministic fixture DB (`fixture_db.sql`), normalizers (DOM, JSON, CSV), crawler/recorder, Playwright visual diffing (360/768/1440px), POST scenario runner, CLI (`make parity ROUTE=... UNIT=... ALL=...`), HTML report |
| **Foundation & Architecture** | Complete | Passed | Axum 0.7, Tokio, SQLx 0.8, MySQL, Tera 1.20, Tower-sessions with MySQL store, Config & Settings repository |
| **Unit 1: Storefront Core & Views** | Complete | Passed (16/16 tests) | Home, Shop, Category, PDP, Cart, Checkout, Order Confirmation, Track, Contact, Sitemap |
| **Unit 1: Customer Auth & Portal** | Complete | Passed | Login, Register, Logout, Forgot Password, Reset Password, Verify Email, Resend Verification, Dashboard, Orders, Addresses, Profile, Security, Wishlist, Export, Delete |
| **Unit 1: Storefront Public APIs** | Complete | Passed | Cart AJAX drawer, Availability check, Search suggestions, SSE availability stream, Newsletter, Reviews, Wishlist toggle |
| **Unit 1: Media & Asset Delivery** | Complete | Passed | `/uploads/*` resolution, mime sniffing, placeholder fallback, ETag HTTP caching |
| **Unit 1: Prometheus Metrics** | Complete | Passed | `/metrics` Prometheus text metrics endpoint |
| **Unit 2: Admin Panel Auth & RBAC** | In Progress | Pending | Admin session, CSRF, RBAC permission matrices |
| **Unit 2: Admin Catalog & Inventory** | Not Started | Pending | Products, Categories, Option Presets, CSV Import/Export, Stock Movements |
| **Unit 2: Admin Orders & Customers** | Not Started | Pending | Order status transitions, Invoice printing, Customer management |
| **Unit 2: Admin Marketing & Content** | Not Started | Pending | Coupons, Banners, Subscribers, Reviews, Static Pages, Contact Messages |
| **Unit 2: Admin Monitoring & Settings** | Not Started | Pending | Telemetry display, Alert rules, General/Mail/Storage configuration |
| **Unit 2: Admin AJAX Endpoints** | Not Started | Pending | Monitor data, stock inline updates, option presets, analytics data |
| **Unit 3: Background Workers / Cron** | Not Started | Pending | Process mail queue, telemetry collector, access log parser, customer maintenance, rollups |
