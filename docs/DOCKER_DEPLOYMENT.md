# Jinispotro Docker Deployment & Observability Guide

This guide provides end-to-end instructions for deploying Jinispotro with MySQL 8.0, AWS S3 storage, Prometheus, and pre-configured Grafana dashboards using Docker Compose.

---

## 1. Quick Start

### Step 1: Prepare Environment Configuration
Copy `.env.example` to `.env`:
```bash
cp .env.example .env
```

Open `.env` and fill in your AWS S3 details:
```ini
# Storage provider
STORAGE_PRIMARY=s3
STORAGE_FALLBACK=local

# AWS Region & Bucket
AWS_REGION=ap-southeast-1
S3_BUCKET=your-production-bucket-name

# AWS IAM Credentials
AWS_ACCESS_KEY_ID=your-aws-access-key-id
AWS_SECRET_ACCESS_KEY=your-aws-secret-access-key

# Optional: CloudFront CDN distribution domain
S3_PUBLIC_BASE_URL=https://d123456abcdef.cloudfront.net
```
*(All database, application, and monitoring settings in `.env` are already pre-configured to work with Docker Compose out of the box).*

### Step 2: Launch Full Production Stack
```bash
docker compose up -d
```

### What Happens Automatically:
1. **MySQL 8.0 (`db`)**: Boots, mounts persistent storage (`db_data`), imports `sql/schema.sql` (creating database `php_admin_panel`, admin accounts, catalog, orders, and seed tables), and performs health checks.
2. **App (`app`)**: Waits for MySQL to be healthy, executes `php bin/migrate.php up` (creating `files` registry and linking all entity tables), launches background `cron` daemon (queue, S3 sync, orphan cleanup), and starts Apache on port `80`.
3. **Prometheus (`prometheus`)**: Starts on port `9090` and begins scraping `app:80/metrics.php`, `mysqld-exporter:9104`, `node-exporter:9100`, and `cadvisor:8080`.
4. **Grafana (`grafana`)**: Starts on port `3000` with the Prometheus datasource and production dashboards **automatically loaded and ready**.

---

## 2. Service Endpoints & Default Credentials

| Service | Host Port | Internal Port | Description | Default Credentials |
|---|---|---|---|---|
| **E-Commerce Storefront** | `http://localhost:80` | `80` | Customer shop, catalog, cart, checkout | Public |
| **Admin Control Panel** | `http://localhost:80/admin/` | `80` | Backoffice management & settings | `admin@admin.com` / `admin123` |
| **Grafana Dashboards** | `http://localhost:3000` | `3000` | Real-time monitoring & KPIs | `admin` / `admin` (or Anonymous Viewer) |
| **Prometheus Metrics** | `http://localhost:9090` | `9090` | Time-series query engine | Public |
| **MySQL Database** | `localhost:3306` | `3306` | Relational database | `jinispotro` / `secret123` (root: `rootsecret123`) |

---

## 3. Pre-Configured Grafana Dashboards

Grafana is provisioned via `docker/grafana/provisioning/` and includes two production dashboards located under the **Jinispotro** folder:

### Dashboard 1: Jinispotro E-Commerce & Storage Overview
- **E-Commerce KPIs**: Database Connectivity Status, Total Completed Revenue, Total Orders, Pending Orders, Active Customers, Active Products, Low/Out-of-Stock alerts.
- **Storage Layer Health**: Total Files in AWS S3 vs Local Disk, Storage Provider Distribution, Pending S3 Failover Sync Queue.
- **Transactional Mail Queue**: Pending, Sending, Sent, and Failed email counts.
- **PHP Health**: PHP Memory Allocated vs Peak Usage over time.

### Dashboard 2: Infrastructure, Containers & MySQL Performance
- **Host Metrics (Node Exporter)**: Host CPU % Utilization, RAM % Utilization, Root Disk Usage %, 1-minute System Load.
- **Container Metrics (cAdvisor)**: Real-time CPU and Memory usage per container (`app`, `db`, `prometheus`, `grafana`).
- **MySQL Performance (MySQL Exporter)**: Queries per Second (QPS), Active Connected Client Threads, Slow Query Counter.

---

## 4. Production SSL & Domain Setup (`jinishpotro.store`)

The Docker container runs a high-performance Apache 2.4 server with native SSL (`mod_ssl`) and URL rewriting (`mod_rewrite`) enabled.

### Direct Docker Deployment (Recommended)
The container binds directly to ports `80` and `443` and automatically detects Let's Encrypt certificates located in `/etc/letsencrypt/`:
```bash
# 1. Stop host Apache or Nginx if running to free ports 80 and 443:
sudo systemctl stop apache2 && sudo systemctl disable apache2
sudo systemctl stop nginx && sudo systemctl disable nginx

# 2. Launch the Jinishpotro stack:
docker compose up -d --build
```

### Option A: Using Certbot on EC2 Host
To generate Let's Encrypt certificates directly on your EC2 host:
```bash
sudo apt update && sudo apt install -y certbot
sudo certbot certonly --standalone -d jinishpotro.store -d www.jinishpotro.store

# Restart the app container to immediately load the new SSL certificates:
docker compose restart app
```

### Option B: Using Cloudflare or AWS ALB / CloudFront
If using Cloudflare or AWS Application Load Balancer / CloudFront:
1. Terminate SSL at Cloudflare/ALB.
2. Forward traffic to EC2 port `80`.
3. The application automatically detects `X-Forwarded-Proto: https` and upgrades all URLs seamlessly.

---

## 5. Operations & Maintenance CLI Commands

### View Container Status & Logs
```bash
# Check container status
docker compose ps

# View application logs
docker compose logs -f app

# View database logs
docker compose logs -f db

# View Prometheus or Grafana logs
docker compose logs -f grafana prometheus
```

### Run Migrations & Background Tasks Inside Container
```bash
# Check migration status
docker compose exec app php bin/migrate.php status

# Migrate existing local uploads to AWS S3
docker compose exec app php bin/migrate-files-to-s3.php --limit=50

# Run storage fallback sync manually
docker compose exec app php bin/sync-storage.php

# Run metrics collector manually
docker compose exec app php cron/collect_metrics.php --verbose
```

### Backup & Restore Database
```bash
# Create full backup
docker compose exec db mysqldump -u root -prootsecret123 php_admin_panel > backup_$(date +%Y%m%d).sql

# Restore from backup
docker compose exec -T db mysql -u root -prootsecret123 php_admin_panel < backup_20261112.sql
```

### Stop & Restart Stack
```bash
# Stop containers without deleting data
docker compose stop

# Restart containers
docker compose start

# Rebuild app after code updates
docker compose up -d --build app
```

