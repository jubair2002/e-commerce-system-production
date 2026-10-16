# 08 — Built-in monitoring dashboard (CPU, RAM, disk, network in/out, bandwidth, DB)

> Paste `00_MASTER_CONTEXT_and_ORDER.md` first. Prompts 01–07 done. SQL `004_monitoring.sql` applied.

## Role
Senior PHP + Linux engineer. Build monitoring **inside the admin panel** with zero paid services. Same admin look; charts with Chart.js (already used on the dashboard).

## Goal
Admin → **Monitoring** shows live and historical server health: CPU, load, memory, swap, disk, network in/out (per interface), application bandwidth, requests & errors, response times, DB health, PHP info, and alerts. Data is collected by a cron script into the tables from `004`.

## 1. Collector — `cron/collect_metrics.php` (CLI only, run every minute)
Read OS data without shell-exec where possible:

**Linux (primary target — VPS/EC2):**
- CPU %: sample `/proc/stat` twice 1 s apart (or diff vs. previous stored values in a small `storage/monitor_state.json`) → busy/total.
- Load: `sys_getloadavg()`; cores: count `processor` lines in `/proc/cpuinfo`.
- Memory/swap: parse `/proc/meminfo` (`MemTotal, MemAvailable, SwapTotal, SwapFree`); used = total − available.
- Disk: `disk_total_space()/disk_free_space()` on web root (and optional extra mount points from setting).
- Network: parse `/proc/net/dev`; per-interface rx/tx counters → rate = (Δbytes)/(Δseconds) vs previous row; **handle counter reset/wrap** (negative delta → treat as 0). Auto-pick the first non-`lo`, non-`docker*/veth*/br-*` interface with traffic when `monitor_default_iface='auto'`.
- Uptime: `/proc/uptime`.

**Windows/XAMPP fallback (clearly isolated function):** use `wmic cpu get loadpercentage`, `wmic OS get FreePhysicalMemory,TotalVisibleMemorySize`, `typeperf`/`netstat -e` for bytes. If a metric is unavailable store NULL and show "n/a" in UI — never fatal.

**MySQL:** `SHOW GLOBAL STATUS` for `Threads_connected, Questions, Slow_queries, Uptime, Innodb_buffer_pool_read_requests/reads`; QPS = ΔQuestions/Δtime; DB size from `information_schema.TABLES`.

- Insert one row in `system_metrics` + rows in `network_samples` per interface.
- Purge rows older than `monitor_retention_days` once per day (batch `DELETE ... LIMIT 5000`).
- Evaluate `monitor_alert_rules`: metric above/below threshold for `for_minutes` consecutive samples → send email via `mail_queue()` (subject "[ALERT] CPU > 85% for 5 min"), set `last_fired_at`, don't re-fire within 60 min; also write `activity_log`.
- Cron lines for Linux crontab and Windows Task Scheduler in a comment block. Lock file to avoid overlapping runs.

## 2. Application traffic & bandwidth — `includes/traffic.php`
- Loaded at the top of `shop_bootstrap.php`, `admin/includes/auth.php` and `api/*`:
  `$GLOBALS['_t0']=microtime(true); ob_start();` and `register_shutdown_function('traffic_record')`.
- `traffic_record()`: bytes_out = `ob_get_length()` (+ headers estimate ~300 B; for static files note: Apache serves them directly so they're **not** counted — document this and offer optional Apache `mod_log_config` log parser `cron/parse_access_log.php` that reads the last N lines of the access log, summing `%O` bytes by minute for **true** bandwidth including images/CSS/JS), bytes_in = `CONTENT_LENGTH`, duration ms, status via `http_response_code()`, area (storefront / admin / api).
- One cheap upsert: `INSERT ... ON DUPLICATE KEY UPDATE requests=requests+1, bytes_out=bytes_out+?...` on `traffic_minute` (bucket = `DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:00')`).
- If duration ≥ `monitor_slow_request_ms` insert into `slow_requests` (cap table at 500 rows).
- Sample at 100 % — it is one indexed upsert; wrap in try/catch, never break the page; skip entirely when `monitor_enabled=0`.

## 3. Admin UI — `admin/monitoring.php` (+ `admin/ajax/monitor_data.php`)
Sidebar: "Monitoring" (admin role only). Top bar: range selector **Live (auto 5 s) · 1 h · 6 h · 24 h · 7 d · 30 d**, interface selector, refresh toggle, "Export CSV".

**Stat tiles (top row):** CPU %, Load (1/5/15), RAM used/total %, Disk used/total %, Network ↓ / ↑ current rate (Mbps/KB/s), Uptime, DB connections, Requests/min, Error rate (5xx/total).
Colour rules: green < 70 %, amber 70–89 %, red ≥ 90 % (text + icon too, not colour only).

**Charts (Chart.js, downsample server-side to ≤ 300 points using bucketed AVG/MAX):**
1. CPU % + load average (dual axis)
2. Memory used + swap (stacked area)
3. **Network in/out** (two lines, bytes/s, auto units)
4. **Bandwidth usage:** app-level bytes out/in per hour/day (bar) + month-to-date total and, if `monitor_bandwidth_quota_gb` > 0, a quota progress bar with projection to month end
5. Requests/min and avg/max response time
6. HTTP status mix (2xx/3xx/4xx/5xx stacked)
7. DB: threads, QPS, slow queries delta

**Tables:** Top 10 slowest recent requests (`slow_requests`); disk usage per mount; PHP info card (version, memory_limit, upload_max_filesize, max_execution_time, OPcache enabled + hit rate, loaded extensions that matter: pdo_mysql, openssl, mbstring, gd/imagick, intl); MySQL info (version, uptime, buffer-pool hit ratio, DB size, largest 5 tables); storage breakdown (`assets/uploads` size, logs size, `email_queue` rows, `analytics_events` rows) with "Purge old data" buttons (confirm).

**Alerts tab:** CRUD for `monitor_alert_rules`, last fired, test-fire button; recipients come from mail settings.

**Health badge in admin header:** small coloured dot (OK/Warn/Critical) linking to Monitoring, computed from the latest sample (cached 30 s). If the collector hasn't produced data for > 3 min show "Collector not running" with the exact cron command to fix it.

**Optional external link:** if `monitor_external_url` is set show an "Open Netdata / Grafana" button (open in new tab; also allow an iframe card only if the URL is https and the admin ticks "embed").

## 4. JSON endpoint contract (`admin/ajax/monitor_data.php?range=1h&iface=auto`)
Admin-only, `Cache-Control: no-store`, returns `{generated_at, tiles:{...}, series:{cpu:[[ts,val],...], mem:[...], net_rx:[...], net_tx:[...], bw_out:[...], req:[...], rt_avg:[...], status:{...}}, tables:{slow:[...]}}`. Validate `range` against a whitelist; `iface` against known interfaces. Use `GROUP BY FLOOR(UNIX_TIMESTAMP(collected_at)/:step)`.

## 5. Optional free/open-source add-ons (document, don't require)
Short setup notes for running **Netdata** (`bash <(curl -SsL https://get.netdata.cloud/kickstart.sh)`) or **Glances** (`pip install glances; glances -w`) bound to `127.0.0.1` and reverse-proxied under an auth-protected path, so the built-in page can link to them. State clearly that the built-in page works alone.

## Edge cases
- Shared hosting with `open_basedir` blocking `/proc` → show "OS metrics unavailable on this host" while app traffic + DB metrics still work.
- First sample has no previous counter → rate NULL.
- Container (Docker/EKS) → read cgroup memory/cpu (`/sys/fs/cgroup/...`) when present and prefer it.
- Timezone: store UTC or the configured zone consistently with `config.php`; label chart axes with the admin timezone.

## Acceptance
1. Start collector via cron → within 2 minutes tiles and charts populate.
2. `stress`/`yes > /dev/null` for 6 minutes → CPU alert email fires once.
3. Download a large file / run a loop of requests → network and bandwidth charts rise.
4. Stop the cron → header badge shows "Collector not running".
5. Page loads < 300 ms for 24 h range with 100 k rows (indexes used; show EXPLAIN in your notes).
