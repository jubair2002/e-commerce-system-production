<?php
/**
 * Transactional Mail System & Queue Processor.
 * Vendors PHPMailer 6.x manually without Composer.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/hooks.php';

// Vendor PHPMailer classes manually
require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Load and decrypt mail configuration from settings.
 */
function mail_config(): array
{
    $encPass = setting('mail_password_enc', '');
    $password = '';
    if (!empty($encPass)) {
        try {
            $password = app_decrypt($encPass);
        } catch (Throwable $e) {
            $password = '';
        }
    }

    return [
        'enabled'          => (int)setting('mail_enabled', '0') === 1,
        'driver'           => setting('mail_driver', 'smtp'), // smtp | phpmail | log
        'host'             => setting('mail_host', 'localhost'),
        'port'             => (int)setting('mail_port', '587'),
        'encryption'       => setting('mail_encryption', 'tls'), // tls | ssl | none
        'username'         => setting('mail_username', ''),
        'password'         => $password,
        'from_email'       => setting('mail_from_email', setting('site_email', 'noreply@example.com')),
        'from_name'        => setting('mail_from_name', setting('site_name', APP_NAME)),
        'reply_to'         => setting('mail_reply_to', ''),
        'admin_notify'     => (int)setting('mail_admin_notify', '1') === 1,
        'admin_recipients' => setting('mail_admin_recipients', setting('site_email', '')),
        'timeout'          => max(5, (int)setting('mail_timeout', '15')),
        'max_attempts'     => max(1, (int)setting('mail_max_attempts', '3')),
        'send_mode'        => setting('mail_send_mode', 'queue'), // queue | instant
        'notify_low_stock' => (int)setting('mail_notify_low_stock', '1') === 1,
        'footer_text'      => setting('mail_footer_text', ''),
    ];
}

/**
 * Send an email immediately using configured driver.
 *
 * @param array $msg ['to_email', 'to_name', 'subject', 'body_html', 'body_text']
 * @return array ['ok' => bool, 'error' => ?string, 'debug' => ?string]
 */
function mail_send_now(array $msg): array
{
    $cfg = mail_config();
    $driver = $cfg['driver'];

    $toEmail = trim((string)($msg['to_email'] ?? ''));
    $toName  = trim((string)($msg['to_name'] ?? ''));
    $subject = trim(str_replace(["\r", "\n"], '', (string)($msg['subject'] ?? '')));
    $html    = (string)($msg['body_html'] ?? '');
    $text    = (string)($msg['body_text'] ?? strip_tags($html));

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => "Invalid recipient email address: {$toEmail}", 'debug' => null];
    }

    // Driver: Log only (dev / test mode)
    if ($driver === 'log') {
        $logSummary = "LOG DRIVER: To: {$toEmail} | Subject: {$subject} | Date: " . date('Y-m-d H:i:s');
        return ['ok' => true, 'error' => null, 'debug' => $logSummary];
    }

    $debugLog = '';
    $mail = new PHPMailer(true);

    try {
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Timeout = $cfg['timeout'];

        // Debug output buffer
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function ($str, $level) use (&$debugLog, $cfg) {
            // Mask passwords in debug output
            if (!empty($cfg['password'])) {
                $str = str_replace($cfg['password'], '••••••••', $str);
            }
            $debugLog .= $str . "\n";
        };

        if ($driver === 'smtp') {
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->Port       = $cfg['port'];
            $mail->SMTPAuth   = !empty($cfg['username']);
            $mail->Username   = $cfg['username'];
            $mail->Password   = $cfg['password'];

            if ($cfg['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($cfg['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }
        } else {
            // PHP mail() driver
            $mail->isMail();
        }

        // Sender & Recipient
        $fromEmail = filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL) ? $cfg['from_email'] : 'noreply@example.com';
        $fromName  = str_replace(["\r", "\n"], '', $cfg['from_name'] ?: APP_NAME);
        $mail->setFrom($fromEmail, $fromName);

        if (!empty($cfg['reply_to']) && filter_var($cfg['reply_to'], FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($cfg['reply_to']);
        }

        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;

        $mail->send();

        return ['ok' => true, 'error' => null, 'debug' => $debugLog];

    } catch (Exception $e) {
        $cleanError = $mail->ErrorInfo ?: $e->getMessage();
        // Ensure password is never leaked in error
        if (!empty($cfg['password'])) {
            $cleanError = str_replace($cfg['password'], '••••••••', $cleanError);
        }
        return ['ok' => false, 'error' => $cleanError, 'debug' => $debugLog];
    }
}

/**
 * Enqueue an email into email_queue table.
 */
function mail_queue(
    string $toEmail,
    string $subject,
    string $html,
    ?string $text = null,
    array $meta = []
): int {
    $cfg = mail_config();

    $toEmail = trim($toEmail);
    $toName  = trim((string)($meta['to_name'] ?? ''));
    $subject = trim(str_replace(["\r", "\n"], '', $subject));
    $text    = $text ?? strip_tags($html);
    $slug    = $meta['template_slug'] ?? null;
    $relType = $meta['related_type'] ?? null;
    $relId   = !empty($meta['related_id']) ? (int)$meta['related_id'] : null;

    $stmt = db()->prepare(
        "INSERT INTO email_queue
          (template_slug, to_email, to_name, subject, body_html, body_text, related_type, related_id, status, scheduled_at)
         VALUES
          (:slug, :email, :name, :subject, :html, :text, :rtype, :rid, 'pending', NOW())"
    );
    $stmt->execute([
        ':slug'    => $slug,
        ':email'   => $toEmail,
        ':name'    => $toName,
        ':subject' => $subject,
        ':html'    => $html,
        ':text'    => $text,
        ':rtype'   => $relType,
        ':rid'     => $relId,
    ]);

    $queueId = (int)db()->lastInsertId();

    // Instant send mode: process in shutdown handler
    if ($cfg['send_mode'] === 'instant') {
        register_shutdown_function(function () use ($queueId) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            mail_process_queue_item($queueId);
        });
    }

    return $queueId;
}

/**
 * Wrap email body in a responsive HTML base layout (600px container, header/logo, footer).
 */
function mail_wrap_html(string $innerHtml, string $title = ''): string
{
    $siteName = setting('site_name', APP_NAME);
    $storeLogo = setting('store_logo');
    $footerText = setting('mail_footer_text', setting('footer_text', ''));

    $logoHtml = "<h2>" . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . "</h2>";
    if (!empty($storeLogo)) {
        $logoUrl = rtrim(BASE_URL, '/') . '/assets/uploads/branding/' . $storeLogo;
        $logoHtml = "<img src=\"{$logoUrl}\" alt=\"" . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . "\" style=\"max-height: 48px; border:0;\">";
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#18181b;">
  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f4f5; padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px; background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.06); border:1px solid #e4e4e7;">
          <!-- Header -->
          <tr>
            <td style="padding:28px 32px; border-bottom:1px solid #f4f4f5; text-align:center;">
              {$logoHtml}
            </td>
          </tr>
          <!-- Body Content -->
          <tr>
            <td style="padding:32px; font-size:15px; line-height:1.6; color:#27272a;">
              {$innerHtml}
            </td>
          </tr>
          <!-- Footer -->
          <tr>
            <td style="padding:24px 32px; background-color:#fafafa; border-top:1px solid #f4f4f5; font-size:12px; color:#71717a; text-align:center;">
              <div>&copy; {$siteName}. All rights reserved.</div>
              <div style="margin-top:6px;">{$footerText}</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Render an email template by slug with placeholder replacement {{var}} and queue it.
 */
function mail_queue_template(
    string $slug,
    string $toEmail,
    ?string $toName,
    array $vars,
    array $meta = []
): ?int {
    $stmt = db()->prepare("SELECT * FROM email_templates WHERE slug = :s LIMIT 1");
    $stmt->execute([':s' => $slug]);
    $tmpl = $stmt->fetch();

    if (!$tmpl || empty($tmpl['enabled'])) {
        return null;
    }

    $subject = $tmpl['subject'];
    $bodyHtml = $tmpl['body_html'];

    // Global placeholder variables
    $vars['site_name'] = $vars['site_name'] ?? setting('site_name', APP_NAME);
    $vars['store_phone'] = $vars['store_phone'] ?? setting('store_phone', '');
    $vars['store_email'] = $vars['store_email'] ?? setting('site_email', '');

    // Replace {{placeholder}} in subject and body
    foreach ($vars as $k => $v) {
        $valStr = (string)$v;
        // HTML escape all placeholders EXCEPT safe pre-rendered blocks like items_table
        $escapedVal = ($k === 'items_table') ? $valStr : htmlspecialchars($valStr, ENT_QUOTES, 'UTF-8');
        $subject = str_replace('{{' . $k . '}}', $valStr, $subject);
        $bodyHtml = str_replace('{{' . $k . '}}', $escapedVal, $bodyHtml);
    }

    $fullHtml = mail_wrap_html($bodyHtml, $subject);
    $meta['template_slug'] = $slug;
    $meta['to_name'] = $toName;

    return mail_queue($toEmail, $subject, $fullHtml, null, $meta);
}

/**
 * Process a single queue item by ID.
 */
function mail_process_queue_item(int $id): bool
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "UPDATE email_queue SET status = 'sending' WHERE id = :id AND status = 'pending'"
    );
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) {
        return false;
    }

    $stmtFetch = $pdo->prepare("SELECT * FROM email_queue WHERE id = :id");
    $stmtFetch->execute([':id' => $id]);
    $row = $stmtFetch->fetch();
    if (!$row) return false;

    $cfg = mail_config();
    $res = mail_send_now([
        'to_email'  => $row['to_email'],
        'to_name'   => $row['to_name'],
        'subject'   => $row['subject'],
        'body_html' => $row['body_html'],
        'body_text' => $row['body_text'],
    ]);

    $newAttempts = (int)$row['attempts'] + 1;

    if ($res['ok']) {
        $pdo->prepare(
            "UPDATE email_queue
             SET status = 'sent', sent_at = NOW(), attempts = :att, last_error = NULL
             WHERE id = :id"
        )->execute([':att' => $newAttempts, ':id' => $id]);
        return true;
    } else {
        $backoffs = [1, 5, 30];
        $backoffMin = $backoffs[min($newAttempts - 1, count($backoffs) - 1)] ?? 30;
        $maxAttempts = $cfg['max_attempts'];
        $cleanErr = mb_substr($res['error'] ?? 'Unknown send failure', 0, 490);

        if ($newAttempts >= $maxAttempts) {
            $pdo->prepare(
                "UPDATE email_queue
                 SET status = 'failed', attempts = :att, last_error = :err
                 WHERE id = :id"
            )->execute([':att' => $newAttempts, ':err' => $cleanErr, ':id' => $id]);
        } else {
            $pdo->prepare(
                "UPDATE email_queue
                 SET status = 'pending', attempts = :att, last_error = :err, scheduled_at = NOW() + INTERVAL :b MINUTE
                 WHERE id = :id"
            )->execute([':att' => $newAttempts, ':err' => $cleanErr, ':b' => $backoffMin, ':id' => $id]);
        }
        return false;
    }
}

/**
 * Process pending email queue items with atomic claiming pattern.
 * Safe for overlapping cron executions.
 */
function mail_process_queue(int $limit = 20): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT id FROM email_queue
         WHERE status = 'pending' AND scheduled_at <= NOW()
         ORDER BY id ASC
         LIMIT :lim"
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sent = 0;
    $failed = 0;

    foreach ($ids as $id) {
        $ok = mail_process_queue_item((int)$id);
        if ($ok) {
            $sent++;
        } else {
            $failed++;
        }
    }

    // Update last run timestamp
    update_setting('mail_last_run', (string)time());

    return [
        'processed' => count($ids),
        'sent'      => $sent,
        'failed'    => $failed,
    ];
}

/**
 * Build responsive HTML table for ordered items.
 */
function items_table(array $items): string
{
    $baseUrl = rtrim(BASE_URL, '/');
    $rows = '';

    foreach ($items as $item) {
        $pname = htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $label = !empty($item['variant_label']) ? '<div style="font-size:12px; color:#71717a;">' . htmlspecialchars($item['variant_label'], ENT_QUOTES, 'UTF-8') . '</div>' : '';
        $qty = (int)($item['quantity'] ?? 1);
        $total = format_price((float)($item['line_total'] ?? 0));

        $imgUrl = '';
        if (!empty($item['image'])) {
            $imgUrl = $baseUrl . '/assets/uploads/products/' . $item['image'];
        }
        $imgCell = $imgUrl ? "<td style=\"width:54px; padding:10px 0;\"><img src=\"{$imgUrl}\" width=\"48\" height=\"48\" style=\"border-radius:4px; object-fit:cover; border:1px solid #e4e4e7;\" alt=\"\"></td>" : '';

        $rows .= <<<HTML
<tr style="border-bottom:1px solid #f4f4f5;">
  {$imgCell}
  <td style="padding:10px 8px; vertical-align:middle;">
    <div style="font-weight:600; font-size:14px; color:#18181b;">{$pname}</div>
    {$label}
  </td>
  <td align="center" style="padding:10px 8px; vertical-align:middle; font-size:14px; color:#52525b;">{$qty}</td>
  <td align="right" style="padding:10px 8px; vertical-align:middle; font-weight:600; font-size:14px; color:#18181b;">{$total}</td>
</tr>
HTML;
    }

    return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; margin:16px 0;">
  <thead>
    <tr style="border-bottom:2px solid #e4e4e7; text-align:left; font-size:12px; color:#71717a; text-transform:uppercase;">
      <th colspan="2" style="padding:8px 0;">Item</th>
      <th style="padding:8px; text-align:center;">Qty</th>
      <th style="padding:8px; text-align:right;">Total</th>
    </tr>
  </thead>
  <tbody>
    {$rows}
  </tbody>
</table>
HTML;
}

// -----------------------------------------------------------------
// Event Listeners (Registered with hooks.php)
// -----------------------------------------------------------------

// 1. Order Placed Listener
hook_register('order.placed', function (array $data) {
    try {
        $orderId = (int)($data['order_id'] ?? 0);
        if ($orderId <= 0) return;

        $stmt = db()->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();
        if (!$order) return;

        $stmtItems = db()->prepare("SELECT * FROM order_items WHERE order_id = :id");
        $stmtItems->execute([':id' => $orderId]);
        $items = $stmtItems->fetchAll();

        $itemsTableHtml = items_table($items);
        $trackUrl = rtrim(BASE_URL, '/') . '/track.php?order_number=' . urlencode($order['order_number']) . '&phone=' . urlencode($order['customer_phone']);
        $adminOrderUrl = rtrim(BASE_URL, '/') . '/admin/order_view.php?id=' . $order['id'];

        $vars = [
            'order_number'     => $order['order_number'],
            'customer_name'    => $order['customer_name'],
            'customer_phone'   => $order['customer_phone'],
            'customer_email'   => $order['customer_email'],
            'shipping_address' => $order['shipping_address'] . ', ' . $order['shipping_city'],
            'subtotal'         => format_price($order['subtotal']),
            'shipping_fee'     => format_price($order['shipping_fee']),
            'discount'         => format_price($order['discount_total']),
            'total'            => format_price($order['total']),
            'payment_method'   => strtoupper($order['payment_method']),
            'track_url'        => $trackUrl,
            'admin_order_url'  => $adminOrderUrl,
            'items_table'      => $itemsTableHtml,
        ];

        // Send to Customer
        if (!empty($order['customer_email'])) {
            mail_queue_template('order_placed_customer', $order['customer_email'], $order['customer_name'], $vars, [
                'related_type' => 'order',
                'related_id'   => $order['id'],
            ]);
        }

        // Send to Admin
        $cfg = mail_config();
        if ($cfg['admin_notify'] && !empty($cfg['admin_recipients'])) {
            $recipients = array_filter(array_map('trim', explode(',', $cfg['admin_recipients'])));
            foreach ($recipients as $adminEmail) {
                if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                    mail_queue_template('order_placed_admin', $adminEmail, 'Admin', $vars, [
                        'related_type' => 'order',
                        'related_id'   => $order['id'],
                    ]);
                }
            }
        }
    } catch (Throwable $e) {
        error_log("Error in order.placed mail hook: " . $e->getMessage());
    }
});

// 2. Order Status Changed Listener
hook_register('order.status_changed', function (array $data) {
    try {
        $orderId = (int)($data['order_id'] ?? 0);
        $newStatus = $data['new_status'] ?? '';
        if ($orderId <= 0 || !$newStatus) return;

        $slugMap = [
            'processing' => 'order_processing',
            'shipped'    => 'order_shipped',
            'completed'  => 'order_completed',
            'cancelled'  => 'order_cancelled',
        ];

        $templateSlug = $slugMap[$newStatus] ?? null;
        if (!$templateSlug) return;

        $stmt = db()->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();
        if (!$order || empty($order['customer_email'])) return;

        $trackUrl = rtrim(BASE_URL, '/') . '/track.php?order_number=' . urlencode($order['order_number']) . '&phone=' . urlencode($order['customer_phone']);

        $vars = [
            'order_number'    => $order['order_number'],
            'customer_name'   => $order['customer_name'],
            'courier'         => $order['courier'] ?: 'Courier',
            'tracking_number' => $order['tracking_number'] ?: 'N/A',
            'total'           => format_price($order['total']),
            'track_url'       => $trackUrl,
        ];

        mail_queue_template($templateSlug, $order['customer_email'], $order['customer_name'], $vars, [
            'related_type' => 'order',
            'related_id'   => $order['id'],
        ]);
    } catch (Throwable $e) {
        error_log("Error in order.status_changed mail hook: " . $e->getMessage());
    }
});

// 3. Low Stock Listener (Throttled: max 1 per 24 hours per product/variant)
hook_register('stock.low', function (array $data) {
    try {
        $cfg = mail_config();
        if (!$cfg['notify_low_stock'] || empty($cfg['admin_recipients'])) return;

        $pid = (int)($data['product_id'] ?? 0);
        $vid = !empty($data['variant_id']) ? (int)$data['variant_id'] : null;

        // Check throttle: was a low_stock_admin email queued for this item in the last 24h?
        $stmtCheck = db()->prepare(
            "SELECT COUNT(*) FROM email_queue
             WHERE template_slug = 'low_stock_admin'
               AND related_type = 'product'
               AND related_id = :pid
               AND created_at >= NOW() - INTERVAL 24 HOUR"
        );
        $stmtCheck->execute([':pid' => $pid]);
        if ((int)$stmtCheck->fetchColumn() > 0) {
            return; // Throttled
        }

        $adminProductUrl = rtrim(BASE_URL, '/') . '/admin/product_form.php?id=' . $pid;
        $vars = [
            'product_name'      => $data['product_name'] ?? 'Product',
            'variant_label'     => $data['label'] ?? '',
            'stock'             => (string)($data['stock'] ?? 0),
            'threshold'         => (string)($data['threshold'] ?? 5),
            'admin_product_url' => $adminProductUrl,
        ];

        $recipients = array_filter(array_map('trim', explode(',', $cfg['admin_recipients'])));
        foreach ($recipients as $adminEmail) {
            if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                mail_queue_template('low_stock_admin', $adminEmail, 'Admin', $vars, [
                    'related_type' => 'product',
                    'related_id'   => $pid,
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log("Error in stock.low mail hook: " . $e->getMessage());
    }
});
