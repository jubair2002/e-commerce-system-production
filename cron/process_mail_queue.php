<?php
/**
 * CLI Cron: High-Performance Mail Queue Processor.
 *
 * Uses non-blocking SELECT ... FOR UPDATE SKIP LOCKED (MySQL 8.0+ / MariaDB 10.6+)
 * to allow concurrent workers without lock contention.
 * Falls back to atomic UPDATE ... WHERE status = 'pending' claim pattern on older engines.
 * Never runs during a web request. Safe to execute concurrently.
 *
 * Usage:
 *   php cron/process_mail_queue.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Access denied: CLI execution only. Email sending must never block web requests.\n";
    exit(1);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/mailer.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$limit = 50;
$start = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Starting mail queue processor (batch limit {$limit})...\n";

if ((int)setting('mail_enabled', '0') !== 1) {
    echo "Mail sending is currently disabled in settings (mail_enabled=0). Exiting.\n";
    exit(0);
}

// Detect MySQL / MariaDB version for SKIP LOCKED support
$dbVersion = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
$isMariaDB = stripos($dbVersion, 'mariadb') !== false;
$supportsSkipLocked = false;

if ($isMariaDB) {
    // MariaDB supports SKIP LOCKED from 10.6.0+
    if (preg_match('/10\.([6-9]|\d{2})\./', $dbVersion)) {
        $supportsSkipLocked = true;
    }
} else {
    // MySQL supports SKIP LOCKED from 8.0.1+
    if (version_compare($dbVersion, '8.0.0', '>=')) {
        $supportsSkipLocked = true;
    }
}

$claimedIds = [];

try {
    if ($supportsSkipLocked) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT id FROM email_queue
             WHERE status = 'pending' AND scheduled_at <= NOW()
             ORDER BY id ASC
             LIMIT :lim
             FOR UPDATE SKIP LOCKED"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $claimedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($claimedIds)) {
            $inClause = implode(',', array_map('intval', $claimedIds));
            $pdo->exec("UPDATE email_queue SET status = 'sending' WHERE id IN ($inClause)");
        }
        $pdo->commit();
    } else {
        // Fallback for MySQL 5.7 / older MariaDB: Fetch and atomically claim row-by-row
        $stmt = $pdo->prepare(
            "SELECT id FROM email_queue
             WHERE status = 'pending' AND scheduled_at <= NOW()
             ORDER BY id ASC
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $candidateIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $claimStmt = $pdo->prepare("UPDATE email_queue SET status = 'sending' WHERE id = :id AND status = 'pending'");
        foreach ($candidateIds as $cid) {
            $claimStmt->execute([':id' => $cid]);
            if ($claimStmt->rowCount() > 0) {
                $claimedIds[] = (int)$cid;
            }
        }
    }

    if (empty($claimedIds)) {
        echo "No pending emails ready for dispatch.\n";
        exit(0);
    }

    echo "Claimed " . count($claimedIds) . " email(s) for delivery...\n";

    $sent = 0;
    $failed = 0;

    foreach ($claimedIds as $queueId) {
        $ok = mail_process_queue_item((int)$queueId);
        if ($ok) {
            $sent++;
        } else {
            $failed++;
        }
    }

    update_setting('mail_last_run', (string)time());

    $elapsed = round(microtime(true) - $start, 3);
    echo "[" . date('Y-m-d H:i:s') . "] Processed: " . count($claimedIds) . ", Sent: {$sent}, Failed: {$failed} in {$elapsed}s.\n";
    exit(0);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] FATAL ERROR: " . $e->getMessage() . "\n");
    exit(2);
}
