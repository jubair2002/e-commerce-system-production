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

## 4. Subdomain Reverse Proxy Setup (`grafana.domain.com`)

When you are ready to point your public domain (`yourdomain.com` and `grafana.domain.com`) to the server:

### Option A: Using Nginx on the Host Server
An Nginx template is provided in [`docker/nginx/conf.d/jinispotro.conf`](../docker/nginx/conf.d/jinispotro.conf):

```nginx
# 1. Main Storefront & Admin Panel
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    client_max_body_size 64M;

    location / {
        proxy_pass http://127.0.0.1:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

# 2. Grafana Monitoring Subdomain
server {
    listen 80;
    server_name grafana.domain.com;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # WebSocket support for live panel updates
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

To secure both domains with free SSL certificates via Certbot:
```bash
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com -d grafana.domain.com
```

### Option B: Using Cloudflare Tunnel
If using Cloudflare Tunnels (Zero Trust):
1. In Cloudflare Zero Trust Dashboard &rarr; Access &rarr; Tunnels.
2. Route `yourdomain.com` &rarr; `http://localhost:80`.
3. Route `grafana.domain.com` &rarr; `http://localhost:3000`.

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

