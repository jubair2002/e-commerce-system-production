# Worker & Background Tasks Cutover Guide

This document describes the exact procedure for safely cutting over background jobs from the legacy PHP implementation to the high-performance Rust workers in `/rust-backend/crates/jobs`, ensuring that **no email is dropped and no email is double-sent**.

---

## 1. Background Jobs Inventory & Parity Mapping

| Legacy PHP Job | Rust Binary Target | Cadence | Purpose |
| :--- | :--- | :--- | :--- |
| `cron/process_mail_queue.php` | `mail-worker` | Every 1 min | Claims 50 emails using `FOR UPDATE SKIP LOCKED`, dispatches via Lettre SMTP, handles retries `[1, 5, 30]` min. |
| `cron/collect_metrics.php` | `collect-metrics` | Every 1 min | Zero-overhead `/proc` parsing + MySQL status & size collector; writes `system_metrics` & `network_samples`. |
| `bin/sync-storage.php` | `sync-storage` | Every 5 min | Copies fallback local files to AWS S3; updates `sync_status = 'synced'`. |
| `cron/customer_maintenance.php`| `customer-maintenance`| Daily 00:00 | Purges expired tokens (>7d), auth logs (>30d), and abandoned unverified customers (>14d). |
| `cron/cleanup_logs.php` | `cleanup-logs` | Daily 03:00 | Batched deletion (1000/batch) for logs older than retention thresholds. |
| `cron/rebuild_sales_summary.php`| `rebuild-sales-summary`| Daily 03:30 | Incremental rebuilder for `product_sales_summary` of modified orders. |
| `cron/aggregate_analytics.php` | `aggregate-analytics` | Daily 00:30 | Nightly analytics roll-up for `product_stats_daily` and `site_stats_daily`. |
| `cron/parse_access_log.php` | `parse-access-log` | Every 5 min | Web server access log parsing into minute traffic buckets. |
| `cron/purge_image_cache.php` | `purge-image-cache` | Daily 04:00 | Purges expired responsive variant files from cache directory. |
| `bin/cleanup-orphans.php` | `cleanup-orphans` | Daily 03:00 | Deletes soft-deleted files (>30d) and soft-deletes unreferenced media (>7d). |
| `bin/migrate-files-to-s3.php` | `migrate-files-to-s3` | On-demand CLI | Idempotent bulk migration tool for media assets. |
| `bin/create-admin.php` | `create-admin` | On-demand CLI | Provisions admin user with bcrypt password and permissions. |
| *N/A (All-in-one Daemon)* | `jobs-runner --scheduler`| Continuous | In-process asynchronous scheduler executing all periodic tasks. |

---

## 2. Cutover Protocol: Zero-Loss, Zero-Duplicate Guarantee

### Phase A: Preparation & Pre-flight Verification
1. **Compile Release Binaries**:
   ```bash
   cd /var/www/html/rust-backend
   cargo build --release -p jobs --bins
   ```
2. **Verify Database Connectivity and Dry-Run**:
   ```bash
   ./target/release/mail-worker --dry-run
   ./target/release/collect-metrics --dry-run --verbose
   ./target/release/customer-maintenance --dry-run
   ```
3. **Verify Row-Level Locking Support**:
   The `mail-worker` executes `SELECT id FROM email_queue ... FOR UPDATE SKIP LOCKED`.
   Both PHP and Rust use the atomic claim pattern:
   - Records transition from `status = 'pending'` to `status = 'sending'`.
   - Even if both PHP and Rust ran simultaneously for a single second, the `FOR UPDATE SKIP LOCKED` or `UPDATE ... WHERE status = 'pending'` atomic guarantee ensures a message is claimed by exactly one runner.

---

### Phase B: Disabling PHP Cron Execution
1. Open the crontab for `www-data` or root:
   ```bash
   crontab -u www-data -e
   # or edit /etc/cron.d/ecommerce
   ```
2. Comment out or delete all PHP cron entries:
   ```cron
   # * * * * * www-data php /var/www/html/cron/collect_metrics.php >/dev/null 2>&1
   # * * * * * www-data php /var/www/html/cron/process_mail_queue.php >/dev/null 2>&1
   # */5 * * * * www-data php /var/www/html/bin/sync-storage.php >> /var/log/storage-sync.log 2>&1
   # 0 3 * * * www-data php /var/www/html/bin/cleanup-orphans.php --days=30 >/dev/null 2>&1
   # 0 0 * * * www-data php /var/www/html/cron/customer_maintenance.php >/dev/null 2>&1
   ```
3. Check for any in-flight PHP cron processes and wait for them to finish cleanly:
   ```bash
   pgrep -fl "php.*/cron/"
   ```
   *Do not kill in-flight mail processes; allow up to 30 seconds for current batch completion.*

4. Verify email queue state:
   ```sql
   SELECT status, COUNT(*) FROM email_queue GROUP BY status;
   ```
   If any emails are stuck in `sending` status due to past crashed PHP scripts:
   ```sql
   UPDATE email_queue SET status = 'pending' WHERE status = 'sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE);
   ```

---

### Phase C: Starting Rust Background Workers

Choose **Option 1 (Systemd Timers)** for standard discrete job isolation, or **Option 2 (Unified In-Process Scheduler Daemon)** for single-process operations.

#### Option 1: Systemd Units and Timers (Recommended for Linux Production)
Copy the unit files from `deploy/systemd/` to `/etc/systemd/system/`:
```bash
sudo cp deploy/systemd/ecommerce-*.service /etc/systemd/system/
sudo cp deploy/systemd/ecommerce-*.timer /etc/systemd/system/

sudo systemctl daemon-reload

# Enable and start timers
sudo systemctl enable --now ecommerce-mail-worker.timer
sudo systemctl enable --now ecommerce-collect-metrics.timer
sudo systemctl enable --now ecommerce-sync-storage.timer
sudo systemctl enable --now ecommerce-customer-maintenance.timer
```

Verify timer status:
```bash
systemctl list-timers "ecommerce-*"
```

#### Option 2: All-in-One Background Daemon
```bash
sudo cp deploy/systemd/ecommerce-scheduler.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ecommerce-scheduler.service
sudo systemctl status ecommerce-scheduler.service
```

---

## 3. Windows Task Scheduler Instructions (for Windows Deployments)

For Windows Server environments, create scheduled tasks using PowerShell or `schtasks.exe`:

### 1. Mail Worker (Every 1 minute):
```powershell
$Action = New-ScheduledTaskAction -Execute "C:\app\rust-backend\target\release\mail-worker.exe" -Argument "--limit=50" -WorkingDirectory "C:\app\rust-backend"
$Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName "Ecommerce-MailWorker" -Action $Action -Trigger $Trigger -User "SYSTEM"
```

### 2. Metrics Collector (Every 1 minute):
```powershell
$Action = New-ScheduledTaskAction -Execute "C:\app\rust-backend\target\release\collect-metrics.exe" -WorkingDirectory "C:\app\rust-backend"
$Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName "Ecommerce-CollectMetrics" -Action $Action -Trigger $Trigger -User "SYSTEM"
```

### 3. Customer Maintenance (Daily at midnight):
```powershell
$Action = New-ScheduledTaskAction -Execute "C:\app\rust-backend\target\release\customer-maintenance.exe" -WorkingDirectory "C:\app\rust-backend"
$Trigger = New-ScheduledTaskTrigger -Daily -At "00:00"
Register-ScheduledTask -TaskName "Ecommerce-CustomerMaintenance" -Action $Action -Trigger $Trigger -User "SYSTEM"
```

---

## 4. Verification and Health Monitoring

1. **Check Mail Dispatch Logs**:
   ```bash
   journalctl -u ecommerce-mail-worker.service -n 50 -f
   ```
2. **Inspect System Metrics Table**:
   ```sql
   SELECT collected_at, cpu_percent, mem_used, db_qps, uptime_sec FROM system_metrics ORDER BY id DESC LIMIT 5;
   ```
3. **Inspect Prometheus Endpoint**:
   ```bash
   curl -H "Authorization: Bearer <METRICS_TOKEN>" http://127.0.0.1:8082/metrics | grep ecommerce_
   ```

---

## 5. Rollback Plan

If unexpected failures occur during cutover:
1. Stop Rust services / timers:
   ```bash
   sudo systemctl stop ecommerce-*.timer ecommerce-*.service
   ```
2. Un-comment PHP crontab entries:
   ```bash
   crontab -u www-data -e
   ```
3. Reset any stuck `sending` queue entries to `pending`:
   ```sql
   UPDATE email_queue SET status = 'pending' WHERE status = 'sending';
   ```
4. Verify PHP resumes queue processing:
   ```bash
   php /var/www/html/cron/process_mail_queue.php
   ```
