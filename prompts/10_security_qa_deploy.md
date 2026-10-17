# 10 — Security hardening, QA and deployment

> Paste `00_MASTER_CONTEXT_and_ORDER.md` first. Run after prompts 01–09.

## Role
Application-security-minded senior PHP engineer doing a final pass. Review the **whole repo**, fix what you find, and produce a report.

## A. Known weaknesses in the original code to fix (verified from the repo)
1. `admin/install.php` and `admin/migrate.php` are web-runnable. Add: refuse to run when `install.lock` exists; installer writes the lock; show a red admin banner until both files are deleted; block them in `.htaccess` once locked.
2. Checkout used `SELECT stock` then a later `UPDATE ... GREATEST(stock - q, 0)` → overselling and silent negative-to-zero. Already replaced in prompt 03/05 — **verify no other code path touches `stock` directly** (`grep -rn "stock" --include=*.php`). Everything must use `stock_adjust()`.
3. `checkout.php` echoed raw exception text to customers (`$e->getMessage()`). Replace with a generic message + `error_log()`; details only when `APP_ENV==='development'`.
4. `APP_ENV` defaults to `development` (displays errors). Make production the documented default, add `APP_ENV` and DB credentials overrides from environment variables (`getenv`) with fallback to constants. Add `config/config.local.php` (git-ignored) support.
5. Session cookie lacks `secure` flag and strict mode. Set `session.use_strict_mode=1`, `cookie_secure` when HTTPS, `samesite=Lax`, regenerate ID on login (exists) and on privilege change; add idle timeout (30 min admin).
6. No login throttling. Add `login_attempts` logic (table or session+IP file): 5 failures → 15 min lock, generic error text, log to activity.
7. No security headers. Add in a shared include: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, and a **Content-Security-Policy** compatible with Bootstrap/Chart.js CDNs (add SRI hashes + pinned versions for every CDN asset, or self-host them in `assets/vendor/`).
8. `avatar_url()` calls ui-avatars.com with the user's name (privacy leak, external dependency) → generate initials avatar locally with CSS/SVG.
9. Uploads: verify `.htaccess` in `assets/uploads/` blocks PHP execution (`php_flag engine off`, `RemoveHandler .php`, deny `*.php*`), images re-encoded with GD (`imagecreatefrom*` → `imagewebp/jpeg`) to strip payloads/EXIF, size limits, random names.
10. `track.php` / order lookups need rate limiting and a uniform "not found" response (no user enumeration). Confirmation page requires signed token (prompt 05).
11. CSRF: confirm every state-changing request, including AJAX, validates `_csrf` (header `X-CSRF-Token` for fetch). GET must never change state (logout, delete, status change).
12. Open redirects: `return_to` is allow-listed already — keep; audit other redirects.
13. Output escaping: grep for `<?=` without `e()`; JSON in `<script>` must use `json_encode(..., JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)`.
14. Roles: editors must not reach Settings, Mail, Monitoring, user management; add a central `require_role()` check at the top of every new admin file; test by logging in as editor.
15. Secrets: nothing sensitive in git; `.gitignore` covers `config/config.local.php`, `storage/`, `install.lock`, uploads, logs.

## B. Robustness
- Central error handler: log to `storage/logs/app-YYYY-MM-DD.log` (outside web root or protected by `.htaccess`), friendly 500 page, no stack traces in production.
- Database: confirm all tables InnoDB utf8mb4; foreign keys present; slow-query check with `EXPLAIN` on product listing with filters, product page, orders list, analytics queries; add missing indexes via a new `sql/008_indexes.sql` (idempotent style used in 001–007).
- Backups: `cron/backup_db.php` (mysqldump via `exec` if available, otherwise PHP dump of tables) keeping last 7 daily files in `storage/backups/` (protected), plus admin "Download backup" button (admin only, CSRF, audit-logged).
- Add `healthz.php` (returns JSON `{db:ok, time}`; no sensitive info) for uptime monitors.

## C. Test plan to produce (`tests/QA_CHECKLIST.md`)
Provide a runnable checklist with expected results for: variants CRUD, live stock, cart/checkout (simple + variable), COD rules, coupon rules, cancel/restock, emails (SMTP ok/fail), monitoring collector, analytics funnel, role permissions, XSS payloads (`"><img src=x onerror=alert(1)>` in product name, customer name, notes, variant value, coupon code), SQLi probes in every GET param, CSRF replay, file upload of `shell.php.jpg`, race on last item, mobile layouts (360/768/1280), keyboard-only purchase, Lighthouse scores.
Also provide automated scripts where cheap: `tests/run_all.php` that executes the earlier CLI tests and prints PASS/FAIL.

## D. Deployment guide (`DEPLOY.md`)
Two paths:
1. **XAMPP / local:** steps, php.ini values (`upload_max_filesize=8M`, `post_max_size=10M`, `max_input_vars=3000` — needed for many variants!), enabling `openssl`, `mbstring`, `gd`, `pdo_mysql`, Task Scheduler jobs for the 3 cron scripts.
2. **Linux VPS / AWS EC2 (Ubuntu + Nginx/Apache + PHP-FPM + MariaDB):** firewall, `certbot` HTTPS, Nginx snippet that denies `/config`, `/includes`, `/sql`, `/storage`, `/tests`, `/cron`; PHP-FPM `pm` tuning, OPcache settings, logrotate for app logs, crontab entries (`* * * * *` metrics + mail queue, hourly analytics rollup, daily backup), `systemd` not required. Include an optional Docker Compose file (php-fpm + nginx + mariadb) for reproducible local runs.
Include the **go-live checklist**: delete installer, set `APP_ENV=production`, change `APP_KEY`, set timezone, SMTP test, create non-root DB user with least privileges (`SELECT, INSERT, UPDATE, DELETE` + `CREATE/ALTER` only during migration), HTTPS redirect, backups tested by restoring once.

## Output
1. Fixed code (diffs per file). 2. `SECURITY_REPORT.md` listing each finding, severity, fix, and how to verify. 3. QA checklist and deploy guide files. 4. A final "what I did NOT change / future work" list (payment gateways, multi-currency, customer accounts, reviews, bKash/Nagad integration).
