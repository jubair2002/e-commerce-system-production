<?php
/**
 * CLI Cron Runner for processing pending mail queue items.
 *
 * Usage:
 *   php cron/process_mail_queue.php
 *
 * Setup Examples:
 * Linux Crontab (every minute):
 *   * * * * * /usr/bin/php /path/to/project/cron/process_mail_queue.php >> /var/log/shop-mail.log 2>&1
 *
 * Windows Task Scheduler:
 *   Action: Start a program
 *   Program/script: C:\xampp\php\php.exe
 *   Add arguments: C:\xampp\htdocs\shop\cron\process_mail_queue.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Access denied: CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/mailer.php';

try {
    $start = microtime(true);
    echo "[" . date('Y-m-d H:i:s') . "] Starting mail queue processor...\n";

    // 1. Process up to 50 items
    $result = mail_process_queue(50);
    echo "  Processed: {$result['processed']}, Sent: {$result['sent']}, Failed: {$result['failed']}\n";

    // 2. Auto-purge sent emails older than 60 days
    $purgeStmt = db()->prepare(
        "DELETE FROM email_queue
         WHERE status = 'sent' AND sent_at < NOW() - INTERVAL 60 DAY"
    );
    $purgeStmt->execute();
    $purged = $purgeStmt->rowCount();
    if ($purged > 0) {
        echo "  Purged {$purged} sent email(s) older than 60 days.\n";
    }

    $elapsed = round(microtime(true) - $start, 3);
    echo "[" . date('Y-m-d H:i:s') . "] Completed in {$elapsed}s.\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] FATAL ERROR: " . $e->getMessage() . "\n");
    exit(2);
}
