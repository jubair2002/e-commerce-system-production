<?php
/**
 * Cron Job: Cleanup Logs and Expired Transient Records.
 *
 * Runs in safe batches of 1,000 rows (LIMIT 1000) to prevent table locking,
 * buffer pool bloat, and long-running replication lags.
 * Safe to re-run (idempotent).
 *
 * Usage:
 *   php cron/cleanup_logs.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Access denied: CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$monitorRetentionDays   = max(7, (int)setting('monitor_retention_days', '30'));
$analyticsRetentionDays = max(30, (int)setting('analytics_retention_days', '180'));

echo "[" . date('Y-m-d H:i:s') . "] Starting logs & metrics cleanup...\n";
$start = microtime(true);

/**
 * Batch delete helper using LIMIT 1000 loop to avoid long InnoDB locks.
 */
function batch_delete(PDO $pdo, string $table, string $whereClause, array $params = [], int $batchSize = 1000): int
{
    $totalDeleted = 0;
    $sql = "DELETE FROM `{$table}` WHERE {$whereClause} LIMIT {$batchSize}";
    $stmt = $pdo->prepare($sql);

    do {
        $stmt->execute($params);
        $deleted = $stmt->rowCount();
        $totalDeleted += $deleted;
        if ($deleted > 0) {
            usleep(20000); // 20ms pause between batches to release row locks for active web traffic
        }
    } while ($deleted === $batchSize);

    return $totalDeleted;
}

$retentionThresholdMonitor = date('Y-m-d H:i:s', strtotime("-{$monitorRetentionDays} days"));
$retentionThresholdAnalytics = date('Y-m-d H:i:s', strtotime("-{$analyticsRetentionDays} days"));
$retentionThresholdAuth = date('Y-m-d H:i:s', strtotime("-30 days"));
$retentionThresholdSearch = date('Y-m-d H:i:s', strtotime("-90 days"));
$retentionThresholdMail = date('Y-m-d H:i:s', strtotime("-30 days"));

try {
    // 1. activity_log > monitor_retention_days
    $del = batch_delete($pdo, 'activity_log', 'created_at < :t', [':t' => $retentionThresholdMonitor]);
    echo "  activity_log: deleted {$del} row(s)\n";

    // 2. system_metrics > monitor_retention_days
    $del = batch_delete($pdo, 'system_metrics', 'collected_at < :t', [':t' => $retentionThresholdMonitor]);
    echo "  system_metrics: deleted {$del} row(s)\n";

    // 3. network_samples > monitor_retention_days
    $del = batch_delete($pdo, 'network_samples', 'collected_at < :t', [':t' => $retentionThresholdMonitor]);
    echo "  network_samples: deleted {$del} row(s)\n";

    // 4. slow_requests > monitor_retention_days
    $del = batch_delete($pdo, 'slow_requests', 'created_at < :t', [':t' => $retentionThresholdMonitor]);
    echo "  slow_requests: deleted {$del} row(s)\n";

    // 5. traffic_minute > monitor_retention_days
    $del = batch_delete($pdo, 'traffic_minute', 'bucket < :t', [':t' => $retentionThresholdMonitor]);
    echo "  traffic_minute: deleted {$del} row(s)\n";

    // 6. analytics_events > analytics_retention_days
    $del = batch_delete($pdo, 'analytics_events', 'created_at < :t', [':t' => $retentionThresholdAnalytics]);
    echo "  analytics_events: deleted {$del} row(s)\n";

    // 7. auth_attempts > 30 days
    $del = batch_delete($pdo, 'auth_attempts', 'created_at < :t', [':t' => $retentionThresholdAuth]);
    echo "  auth_attempts: deleted {$del} row(s)\n";

    // 8. search_log > 90 days
    $del = batch_delete($pdo, 'search_log', 'created_at < :t', [':t' => $retentionThresholdSearch]);
    echo "  search_log: deleted {$del} row(s)\n";

    // 9. email_queue where status = 'sent' and sent_at < 30 days ago
    $del = batch_delete($pdo, 'email_queue', "status = 'sent' AND sent_at < :t", [':t' => $retentionThresholdMail]);
    echo "  email_queue (sent): deleted {$del} row(s)\n";

    $elapsed = round(microtime(true) - $start, 3);
    echo "[" . date('Y-m-d H:i:s') . "] Cleanup finished successfully in {$elapsed}s.\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
