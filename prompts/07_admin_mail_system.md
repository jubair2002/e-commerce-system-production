# 07 — Admin mail system (SMTP config, templates, queue, test button)

> Paste `00_MASTER_CONTEXT_and_ORDER.md` first. Prompts 01–06 done. SQL `003_mail_system.sql` applied.

## Role
Senior PHP engineer. Mid-size e-commerce needs: reliable transactional email, configurable entirely from admin, no Composer.

## Goal
Admin opens **Admin → Settings → Mail**, enters host / port / encryption / username / password / from name / from email, clicks **Send test email**, sees success or the exact SMTP error, then order emails start working. Templates are editable. Failures are retried and visible.

## 1. Library
Vendor **PHPMailer 6.x** (3 files: `PHPMailer.php`, `SMTP.php`, `Exception.php`) into `includes/lib/PHPMailer/` and require manually (document the download URL and version in a README comment; do not fetch at runtime). Wrapper: `includes/mailer.php`.

## 2. Mailer API (`includes/mailer.php`)
- `mail_config(): array` — reads `mail_*` settings; decrypts `mail_password_enc` with `app_decrypt()`.
- `mail_send_now(array $msg): array` → `['ok'=>bool,'error'=>?string,'debug'=>?string]`; drivers: `smtp`, `phpmail` (PHP `mail()` fallback), `log` (dev: don't send, mark as sent with note).
- `mail_queue(string $to, string $subject, string $html, ?string $text=null, array $meta=[]): int` — inserts into `email_queue`.
- `mail_queue_template(string $slug, string $toEmail, ?string $toName, array $vars, array $meta=[]): ?int` — loads `email_templates` (skip if `enabled=0`), renders placeholders `{{var}}` (HTML-escape every value except pre-rendered safe blocks like `items_table`), auto-generates plain-text alternative via `strip_tags`, wraps in a responsive base layout (inline CSS, 600 px container, logo, footer text from `mail_footer_text`).
- `mail_process_queue(int $limit=20)` — picks `pending` rows whose `scheduled_at <= NOW()` using `UPDATE ... SET status='sending' WHERE id=? AND status='pending'` claim pattern (safe for overlapping cron runs), sends, marks `sent` / retries with backoff (1, 5, 30 min) until `mail_max_attempts`, then `failed` with `last_error` (truncate, never store passwords).
- `mail_send_mode`: `instant` → send inside `register_shutdown_function` after `fastcgi_finish_request()` when available (so the customer doesn't wait); `queue` → rely on cron.
- Support SMTP options: `SMTPSecure` tls/ssl/none, `SMTPAutoTLS`, timeout setting, `CharSet UTF-8`, `Encoding base64`, DKIM left as documented future work.
- Listeners (register in `includes/hooks.php` bootstrap): `order.placed` → customer + admin templates (admin recipients from `mail_admin_recipients`, fallback `site_email`); `order.status_changed` → `order_processing | order_shipped | order_completed | order_cancelled`; `stock.low` → `low_stock_admin` (throttle: max 1 mail per product/variant per 24 h).
- `items_table` helper builds an HTML table: image thumb (absolute `SITE_URL` URL), name, variant label, qty, line total.

## 3. Cron runner
`cron/process_mail_queue.php` (CLI only: `if (PHP_SAPI !== 'cli') exit;`), processes up to 50 per run, exit code non-zero on fatal. Provide copy-paste setup for:
- Linux: `* * * * * /usr/bin/php /var/www/shop/cron/process_mail_queue.php >> /var/log/shop-mail.log 2>&1`
- Windows/XAMPP Task Scheduler command line.
- Fallback "poor man's cron": on any storefront request, if last run > 2 min ago (setting `mail_last_run`) process 5 emails in shutdown handler.

## 4. Admin UI — `admin/mail_settings.php` (admin role only; tab inside Settings or standalone with sidebar link)
**Connection card**
- Fields exactly: Enable mail (toggle) · Driver (SMTP / PHP mail / Log only) · Host · Port · Encryption (TLS/SSL/None) · Username · Password (write-only; shows "•••• saved", empty submit keeps old) · From email · From name · Reply-to · Timeout · Send mode (Queue / Instant) · Max attempts.
- **Provider presets dropdown** that fills host/port/encryption: Gmail (smtp.gmail.com 587 TLS — note app password required), Outlook/Office365 (smtp.office365.com 587 TLS), Zoho (smtp.zoho.com 587), Brevo (smtp-relay.brevo.com 587), Mailgun (smtp.mailgun.org 587), Amazon SES (email-smtp.<region>.amazonaws.com 587), SendGrid (smtp.sendgrid.net 587), Custom.
- **"Send test email"** button: AJAX POST (CSRF) with a recipient field (default current admin email). Returns success or sanitized error + SMTP transcript in a collapsible debug box (passwords masked). Uses `test_email` template. Rate-limit 5/min.
- Show an inline **health panel**: last successful send time, queue pending/failed counts, "Process queue now" button.
- Warn if `APP_KEY` is default, if from-domain differs from SMTP domain (SPF/DKIM tip), if port/encryption mismatch (465 ↔ ssl, 587 ↔ tls).

**Notifications card** — toggles: admin new-order alert, customer confirmation, status-change mails (per status), low-stock alerts, admin recipients (comma list validated), footer text.

**Templates** — `admin/mail_templates.php`: list the 8 seeded templates; edit subject + HTML body in a textarea with a **placeholder chips** palette (click inserts `{{order_number}}`), enable toggle, **live preview** iframe rendered with sample data, "Send this template to me", "Reset to default" (keep defaults in code array).

**Queue & log** — `admin/mail_queue.php`: table (status filter, search by recipient/subject/order), view rendered body, **Retry**, **Delete**, **Retry all failed**, auto-purge sent mail older than 60 days via cron.

## 5. Security
- Password encrypted with `app_encrypt()`; never in logs, `activity_log`, HTML source or exceptions.
- Header-injection safe (strip CR/LF from subject/names), validate emails with `FILTER_VALIDATE_EMAIL`.
- Template body saved as admin-trusted HTML, but variable values are escaped; strip `<script>` and `on*=` attributes from saved templates as defense in depth.
- Test endpoint admin-only + CSRF + rate-limit.

## Acceptance
1. With Gmail app password: test mail arrives; wrong password shows "SMTP authentication failed" (not a stack trace).
2. Place a COD order → customer + admin mails appear in queue, cron sends them, order page shows "emails: sent".
3. Kill SMTP (wrong host) → rows retry then become `failed` with message; "Retry" works after fixing config.
4. Two cron processes at once never send the same mail twice.
5. Mail driver `log` stores messages without sending (dev mode).
