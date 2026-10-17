<?php
/**
 * CLI / Cron Task: Purge Image Cache
 * Cleans expired or orphaned cached images from assets/uploads/cache/
 */
require_once __DIR__ . '/../includes/shop_bootstrap.php';
require_once __DIR__ . '/../includes/images.php';

if (PHP_SAPI !== 'cli') {
    die("CLI only.\n");
}

$purged = image_pipeline_clear_cache();
echo "[" . date('Y-m-d H:i:s') . "] Purged {$purged} cached image variant(s).\n";
