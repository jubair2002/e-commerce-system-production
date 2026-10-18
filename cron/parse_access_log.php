<?php
/**
 * Universal Access Log Parser for true bandwidth calculation.
 * Reads the last N lines of Apache or Nginx access logs, summing bytes by minute
 * into traffic_minute table.
 *
 * Usage:
 *   php cron/parse_access_log.php [/var/log/apache2/access.log]
 *
 * Crontab entry (every 5 minutes):
 *   * /5 * * * * php /path/to/cron/parse_access_log.php >> /path/to/storage/access_log_parser.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    die("CLI execution only.\n");
}

require_once __DIR__ . '/../includes/shop_bootstrap.php';

function log_parser_error(string $message, ?Throwable $e = null): void
{
    $logDir = __DIR__ . '/../storage';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/monitor_error.log';
    $time = date('Y-m-d H:i:s');
    $details = $e ? " | " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() : "";
    @file_put_contents($logFile, "[{$time}] {$message}{$details}\n", FILE_APPEND);
}

// Multi-server log detection: check command line argument, settings, and common server paths
$candidatePaths = array_filter([
    $argv[1] ?? null,
    function_exists('setting') ? setting('apache_access_log_path', null) : null,
    function_exists('setting') ? setting('nginx_access_log_path', null) : null,
    '/var/log/apache2/access.log',
    '/var/log/httpd/access_log',
    '/var/log/nginx/access.log',
    '/var/log/apache2/other_vhosts_access.log',
    '/etc/httpd/logs/access_log',
    '/var/log/apache2/ecommerce-access.log',
]);

$logFile = null;
foreach ($candidatePaths as $candidate) {
    if (!empty($candidate) && file_exists($candidate) && is_readable($candidate)) {
        $logFile = $candidate;
        break;
    }
}

if (!$logFile) {
    echo "[" . date('Y-m-d H:i:s') . "] Notice: No readable access log found among standard Apache/Nginx paths. Skipping external access log parsing.\n";
    exit(0);
}

try {
    $fp = fopen($logFile, 'rb');
    if (!$fp) {
        throw new RuntimeException("Error opening access log file: '{$logFile}'");
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
    // Supports both Apache Combined/Common and Nginx default log formats:
    // e.g.: 127.0.0.1 - - [16/Oct/2026:22:15:30 +0600] "GET /path HTTP/1.1" 200 4520 "referer" "agent"
    $aggregates = [];

    foreach ($rawLines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Flexible regex for timestamp, status code, and bytes sent
        if (preg_match('/\[(\d{2}\/[A-Za-z]{3}\/\d{4}:\d{2}:\d{2}:\d{2}\s+[+-]\d{4})\].*?"\s+(\d{3})\s+(\d+|-)/', $line, $m) ||
            preg_match('/\[(\d{2}\/[A-Za-z]{3}\/\d{4}:\d{2}:\d{2}:\d{2}\s+[+-]\d{4})\].*?\s+(\d{3})\s+(\d+|-)/', $line, $m)) {
            $rawTime = $m[1];
            $status  = (int)$m[2];
            $bytes   = ($m[3] === '-' ? 0 : (int)$m[3]);

            $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $rawTime);
            if ($dt) {
                $minuteBucket = $dt->format('Y-m-d H:i:00');
                if (!isset($aggregates[$minuteBucket])) {
                    $aggregates[$minuteBucket] = [
                        'requests'  => 0,
                        'bytes_out' => 0,
                        's2xx'      => 0,
                        's3xx'      => 0,
                        's4xx'      => 0,
                        's5xx'      => 0,
                    ];
                }
                $aggregates[$minuteBucket]['requests']++;
                $aggregates[$minuteBucket]['bytes_out'] += $bytes;
                if ($status >= 200 && $status < 300) $aggregates[$minuteBucket]['s2xx']++;
                elseif ($status >= 300 && $status < 400) $aggregates[$minuteBucket]['s3xx']++;
                elseif ($status >= 400 && $status < 500) $aggregates[$minuteBucket]['s4xx']++;
                elseif ($status >= 500) $aggregates[$minuteBucket]['s5xx']++;
            }
        }
    }

    $upsertStmt = $pdo->prepare(
        "INSERT INTO traffic_minute
           (bucket, area, requests, bytes_out, bytes_in, total_ms, max_ms, s2xx, s3xx, s4xx, s5xx)
         VALUES
           (:b, 'storefront', :req, :bytes, 0, 0, 0, :s2, :s3, :s4, :s5)
         ON DUPLICATE KEY UPDATE
           requests  = GREATEST(requests, VALUES(requests)),
           bytes_out = GREATEST(bytes_out, VALUES(bytes_out)),
           s2xx      = GREATEST(s2xx, VALUES(s2xx)),
           s3xx      = GREATEST(s3xx, VALUES(s3xx)),
           s4xx      = GREATEST(s4xx, VALUES(s4xx)),
           s5xx      = GREATEST(s5xx, VALUES(s5xx))"
    );

    $updated = 0;
    foreach ($aggregates as $bucket => $stats) {
        $upsertStmt->execute([
            ':b'     => $bucket,
            ':req'   => $stats['requests'],
            ':bytes' => $stats['bytes_out'],
            ':s2'    => $stats['s2xx'],
            ':s3'    => $stats['s3xx'],
            ':s4'    => $stats['s4xx'],
            ':s5'    => $stats['s5xx'],
        ]);
        $updated++;
    }

    echo "[" . date('Y-m-d H:i:s') . "] Processed access log '{$logFile}'. Updated {$updated} minute buckets.\n";
} catch (Throwable $e) {
    log_parser_error("Failed parsing access log '{$logFile}'", $e);
    echo "[" . date('Y-m-d H:i:s') . "] Error processing access log: " . $e->getMessage() . "\n";
    exit(1);
}
