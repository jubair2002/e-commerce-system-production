<?php
/**
 * Optional Apache mod_log_config access log parser for true bandwidth calculation.
 * Reads the last N lines of the web server access log, summing %O bytes by minute
 * into traffic_minute table.
 *
 * Usage:
 *   php cron/parse_access_log.php [/var/log/apache2/access.log]
 *
 * Example crontab entry (every 5 minutes):
 *   * /5 * * * * php /path/to/cron/parse_access_log.php /var/log/apache2/access.log >> /var/log/access_log_parser.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    die("CLI execution only.\n");
}

require_once __DIR__ . '/../includes/shop_bootstrap.php';

$logFile = $argv[1] ?? setting('apache_access_log_path', '/var/log/apache2/access.log');

if (!file_exists($logFile) || !is_readable($logFile)) {
    echo "[" . date('Y-m-d H:i:s') . "] Notice: Access log file '{$logFile}' does not exist or is not readable.\n";
    exit(0);
}

// Read the last 5000 lines
$lines = [];
$fp = fopen($logFile, 'rb');
if (!$fp) {
    echo "[" . date('Y-m-d H:i:s') . "] Error opening '{$logFile}'.\n";
    exit(1);
}

// Tail the last ~5000 lines efficiently
$bufferSize = 8192;
$maxLines = 5000;
fseek($fp, 0, SEEK_END);
$pos = ftell($fp);
$data = '';

while ($pos > 0 && substr_count($data, "\n") < $maxLines) {
    $readLen = min($pos, $bufferSize);
    $pos -= $readLen;
    fseek($fp, $pos);
    $data = fread($fp, $readLen) . $data;
}
fclose($fp);

$rawLines = explode("\n", trim($data));
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Aggregate by minute
// Common / Combined / Custom log format parsing:
// "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\""
// e.g.: 127.0.0.1 - - [16/Oct/2026:22:15:30 +0600] "GET /assets/css/shop.css HTTP/1.1" 200 4520 ...
$aggregates = [];

foreach ($rawLines as $line) {
    if (empty(trim($line))) continue;

    // Regex matching timestamp and bytes
    if (preg_match('/\[(\d{2}\/[A-Za-z]{3}\/\d{4}:\d{2}:\d{2}:\d{2}\s+[+-]\d{4})\].*?"\s+(\d{3})\s+(\d+|-)/', $line, $m)) {
        $rawTime = $m[1];
        $status  = (int)$m[2];
        $bytes   = ($m[3] === '-' ? 0 : (int)$m[3]);

        $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $rawTime);
        if ($dt) {
            $minuteBucket = $dt->format('Y-m-d H:i:00');
            if (!isset($aggregates[$minuteBucket])) {
                $aggregates[$minuteBucket] = [
                    'requests'   => 0,
                    'bytes_out'  => 0,
                    'status_2xx' => 0,
                    'status_3xx' => 0,
                    'status_4xx' => 0,
                    'status_5xx' => 0,
                ];
            }
            $aggregates[$minuteBucket]['requests']++;
            $aggregates[$minuteBucket]['bytes_out'] += $bytes;
            if ($status >= 200 && $status < 300) $aggregates[$minuteBucket]['status_2xx']++;
            elseif ($status >= 300 && $status < 400) $aggregates[$minuteBucket]['status_3xx']++;
            elseif ($status >= 400 && $status < 500) $aggregates[$minuteBucket]['status_4xx']++;
            elseif ($status >= 500) $aggregates[$minuteBucket]['status_5xx']++;
        }
    }
}

$upsertStmt = $pdo->prepare(
    "INSERT INTO traffic_minute
       (bucket_minute, area, requests, bytes_out, bytes_in, duration_ms_total,
        status_2xx, status_3xx, status_4xx, status_5xx)
     VALUES
       (:b, 'storefront', :req, :bytes, 0, 0, :s2, :s3, :s4, :s5)
     ON DUPLICATE KEY UPDATE
       bytes_out = GREATEST(bytes_out, VALUES(bytes_out))"
);

$updated = 0;
foreach ($aggregates as $bucket => $stats) {
    $upsertStmt->execute([
        ':b'     => $bucket,
        ':req'   => $stats['requests'],
        ':bytes' => $stats['bytes_out'],
        ':s2'    => $stats['status_2xx'],
        ':s3'    => $stats['status_3xx'],
        ':s4'    => $stats['status_4xx'],
        ':s5'    => $stats['status_5xx'],
    ]);
    $updated++;
}

echo "[" . date('Y-m-d H:i:s') . "] Processed access log. Updated {$updated} minute buckets.\n";
