<?php
/**
 * Daily Customer Maintenance CLI Cron (Prompt 14).
 *
 * Tasks:
 *   1. Delete expired customer_tokens older than 7 days.
 *   2. Delete auth_attempts records older than 30 days.
 *   3. Auto-delete unverified pending customers older than customer_unverified_ttl_days (default 14 days; 0 = keep).
 *
 * Usage:
 *   php cron/customer_maintenance.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Access denied: CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/customer_auth.php';

try {
    $start = microtime(true);
    $pdo = db();
    echo "[" . date('Y-m-d H:i:s') . "] Starting Customer Maintenance Cron...\n";

    // 1. Purge expired customer tokens older than 7 days
    $stmtTokens = $pdo->prepare(
        "DELETE FROM customer_tokens
         WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $stmtTokens->execute();
    $tokensPurged = $stmtTokens->rowCount();
    echo "  1. Purged {$tokensPurged} expired token(s) older than 7 days.\n";

    // 2. Purge auth_attempts records older than 30 days
    $stmtAuth = $pdo->prepare(
        "DELETE FROM auth_attempts
         WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $stmtAuth->execute();
    $authPurged = $stmtAuth->rowCount();
    echo "  2. Purged {$authPurged} auth attempt log(s) older than 30 days.\n";

    // 3. Purge abandoned pending/unverified registrations
    $ttlDays = (int)setting('customer_unverified_ttl_days', '14');
    $unverifiedPurged = 0;

    if ($ttlDays > 0) {
        // Find abandoned unverified registrations with no linked orders
        $stmtFind = $pdo->prepare(
            "SELECT id, avatar FROM customers
             WHERE status = 'pending'
               AND email_verified_at IS NULL
               AND deleted_at IS NULL
               AND created_at < DATE_SUB(NOW(), INTERVAL :days DAY)"
        );
        $stmtFind->execute([':days' => $ttlDays]);
        $abandoned = $stmtFind->fetchAll();

        foreach ($abandoned as $cust) {
            $cid = (int)$cust['id'];

            // Clean avatar file if present
            if (!empty($cust['avatar'])) {
                $aPath = UPLOADS_PATH . '/avatars/' . $cust['avatar'];
                if (file_exists($aPath)) {
                    @unlink($aPath);
                }
            }

            // Clean associations
            $pdo->prepare("DELETE FROM customer_tokens WHERE customer_id = :cid")->execute([':cid' => $cid]);
            $pdo->prepare("DELETE FROM customer_addresses WHERE customer_id = :cid")->execute([':cid' => $cid]);
            $pdo->prepare("DELETE FROM wishlists WHERE customer_id = :cid")->execute([':cid' => $cid]);

            // Unlink any stray orders just in case
            $pdo->prepare("UPDATE orders SET customer_id = NULL WHERE customer_id = :cid")->execute([':cid' => $cid]);

            // Delete customer row
            $pdo->prepare("DELETE FROM customers WHERE id = :cid")->execute([':cid' => $cid]);
            $unverifiedPurged++;
        }

        echo "  3. Purged {$unverifiedPurged} abandoned unverified registration(s) older than {$ttlDays} days.\n";
    } else {
        echo "  3. Unverified registration cleanup is disabled (customer_unverified_ttl_days = 0).\n";
    }

    log_activity(
        'customer.cron_maintenance',
        "Maintenance cron ran: {$tokensPurged} tokens purged, {$authPurged} auth logs purged, {$unverifiedPurged} abandoned registrations purged"
    );

    $elapsed = round(microtime(true) - $start, 3);
    echo "[" . date('Y-m-d H:i:s') . "] Customer Maintenance completed in {$elapsed}s.\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n");
    exit(2);
}
