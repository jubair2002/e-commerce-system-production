# Production & Local Performance Deployment Guide

This guide details recommended environment configurations for maximum performance, sub-150ms TTFB, and zero-stalling page navigation on both Windows (XAMPP) and Linux (Apache/Nginx) servers.

---

## 1. XAMPP / Windows PHP Configuration (`php.ini`)

Locate your active `php.ini` (e.g. `C:\xampp\php\php.ini`) and configure OPcache and realpath cache:

```ini
; -------------------------------------------------------------
; OPcache Configuration (Dramatically accelerates PHP execution)
; -------------------------------------------------------------
zend_extension=opcache
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
opcache.fast_shutdown=1

; -------------------------------------------------------------
; Realpath Cache (Caches file paths to eliminate disk seeks)
; -------------------------------------------------------------
realpath_cache_size=4096K
realpath_cache_ttl=600
```

---

## 2. MySQL / MariaDB Configuration (`my.ini` / `my.cnf`)

Locate `C:\xampp\mysql\bin\my.ini` (or `/etc/mysql/my.cnf` on Linux) under the `[mysqld]` section:

```ini
[mysqld]
; Batch transaction flushes every second instead of synchronous disk fsync per commit
innodb_flush_log_at_trx_commit=2

; Allocate memory buffer for tables and indexes
innodb_buffer_pool_size=256M

; Disable reverse DNS lookups on incoming connections for instant connection establishment
skip-name-resolve

; Use 127.0.0.1 for DB_HOST in .env instead of localhost (avoids Windows named pipe lookup)
```

---

## 3. Apache Web Server Configuration (`httpd.conf`)

Ensure Apache keep-alive and performance modules are enabled:

```apache
# KeepAlive settings: allows browser to reuse open TCP connections
KeepAlive On
MaxKeepAliveRequests 100
KeepAliveTimeout 5

# Ensure these modules are uncommented (no leading #):
LoadModule deflate_module modules/mod_deflate.so
LoadModule expires_module modules/mod_expires.so
LoadModule headers_module modules/mod_headers.so
LoadModule rewrite_module modules/mod_rewrite.so
```

---

## 4. Background Email Queue Worker Setup

Email sending is handled asynchronously via the database queue (`email_queue`) to ensure visitor checkouts and navigation are never stalled by remote SMTP servers.

### Windows (XAMPP Task Scheduler)
1. Open **Task Scheduler** (`taskschd.msc`).
2. Click **Create Basic Task** &rarr; Name: `Store Mail Queue Worker`.
3. Trigger: **Daily** &rarr; Start today.
4. Action: **Start a program**:
   - **Program/script:** `C:\xampp\php\php.exe`
   - **Add arguments:** `C:\xampp\htdocs\SHOP\cron\process_mail_queue.php`
5. After creating, open the task properties &rarr; **Triggers** tab &rarr; Edit trigger &rarr; Check **Repeat task every 1 minute** for a duration of **Indefinitely**.

### Linux / cPanel Cron Job
Add the following entry via `crontab -e`:

```bash
* * * * * /usr/bin/php /var/www/html/cron/process_mail_queue.php >/dev/null 2>&1
```

---

## 5. Built-in Server-Timing Diagnostics

When in development mode (`APP_ENV=development`) or when appending `?timing=1` to any URL, the server dispatches a `Server-Timing` HTTP response header:

```http
Server-Timing: app;dur=12, db;dur=3, q;desc="6 queries"
```

You can view this breakdown in Chrome/Firefox DevTools:
1. Open **DevTools** (`F12`) &rarr; **Network** tab.
2. Click on the main document request &rarr; **Timing** tab.
3. Inspect the **Server Timing** breakdown (`app`, `db`, and total query count).
