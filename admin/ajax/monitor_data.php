<?php
/**
 * Real-time and historical monitoring data API endpoint.
 * Admin-only, cache-inhibited JSON/CSV feed.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$range = strtolower(trim((string)($_GET['range'] ?? '1h')));
$allowedRanges = ['live', '1h', '6h', '24h', '7d', '30d'];
if (!in_array($range, $allowedRanges, true)) {
    $range = '1h';
}

$format = strtolower(trim((string)($_GET['format'] ?? 'json')));
$ifaceParam = trim((string)($_GET['iface'] ?? 'auto'));

$pdo = db();

if (!function_exists('log_monitor_ajax_error')) {
    function log_monitor_ajax_error(string $msg, ?Throwable $e = null): void {
        $line = '[' . date('Y-m-d H:i:s') . '] [AJAX_MONITOR] ' . $msg;
        if ($e) {
            $line .= ' - Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        }
        $line .= "\n";
        $logPath = dirname(__DIR__, 2) . '/storage/monitor_error.log';
        @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
}

// List all known network interfaces
$knownInterfaces = ['auto'];
try {
    $ifaceRows = $pdo->query("SELECT DISTINCT iface FROM network_samples ORDER BY iface ASC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ifaceRows as $if) {
        if ($if && !in_array($if, $knownInterfaces, true)) {
            $knownInterfaces[] = $if;
        }
    }
} catch (Throwable $e) {
    log_monitor_ajax_error("Failed to query network interfaces", $e);
}

if (!in_array($ifaceParam, $knownInterfaces, true)) {
    $ifaceParam = 'auto';
}

// Compute time window & downsampling step
$now = time();
switch ($range) {
    case 'live':
        $sinceSec = 300; // 5 minutes
        $stepSec  = 5;   // raw / 5s
        $labelFormat = 'H:i:s';
        break;
    case '6h':
        $sinceSec = 21600;
        $stepSec  = 120; // 2 min buckets (180 points)
        $labelFormat = 'H:i';
        break;
    case '24h':
        $sinceSec = 86400;
        $stepSec  = 300; // 5 min buckets (288 points)
        $labelFormat = 'H:i';
        break;
    case '7d':
        $sinceSec = 604800;
        $stepSec  = 1800; // 30 min buckets (336 points)
        $labelFormat = 'M j H:i';
        break;
    case '30d':
        $sinceSec = 2592000;
        $stepSec  = 7200; // 2 hour buckets (360 points)
        $labelFormat = 'M j';
        break;
    case '1h':
    default:
        $sinceSec = 3600;
        $stepSec  = 60; // 1 min buckets (60 points)
        $labelFormat = 'H:i';
        break;
}

$sinceDate = date('Y-m-d H:i:s', $now - $sinceSec);

// -----------------------------------------------------------------
// 1. Fetch Latest System Snapshot for Stat Tiles
// -----------------------------------------------------------------
$latestSM = [];
try {
    $stmtLatest = $pdo->query("SELECT * FROM system_metrics ORDER BY id DESC LIMIT 1");
    $latestSM = $stmtLatest ? ($stmtLatest->fetch(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    log_monitor_ajax_error("Failed to fetch latest system_metrics snapshot", $e);
}

$lastCollectedAt = $latestSM['collected_at'] ?? null;
$collectorRunning = false;
if ($lastCollectedAt) {
    $collectorLag = $now - strtotime($lastCollectedAt);
    $collectorRunning = ($collectorLag <= 180); // within 3 minutes
}

$memTotal = (float)($latestSM['mem_total'] ?? 0);
$memUsed  = (float)($latestSM['mem_used'] ?? 0);
$memPercent = ($memTotal > 0) ? round(($memUsed / $memTotal) * 100, 1) : 0.0;

$swapTotal = (float)($latestSM['swap_total'] ?? 0);
$swapUsed  = (float)($latestSM['swap_used'] ?? 0);
$swapPercent = ($swapTotal > 0) ? round(($swapUsed / $swapTotal) * 100, 1) : 0.0;

$diskTotal = (float)($latestSM['disk_total'] ?? 0);
$diskUsed  = (float)($latestSM['disk_used'] ?? 0);
$diskPercent = ($diskTotal > 0) ? round(($diskUsed / $diskTotal) * 100, 1) : 0.0;

// Application requests and errors in the last 5 minutes
$recentReqCount = 0;
$recentErrors   = 0;
try {
    $stmtTraffic5m = $pdo->query(
        "SELECT SUM(requests) as reqs, SUM(s5xx) as errs
         FROM traffic_minute
         WHERE bucket >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    );
    $tf5m = $stmtTraffic5m ? $stmtTraffic5m->fetch(PDO::FETCH_ASSOC) : false;
    if ($tf5m) {
        $recentReqCount = (int)($tf5m['reqs'] ?? 0);
        $recentErrors   = (int)($tf5m['errs'] ?? 0);
    }
} catch (Throwable $e) {
    log_monitor_ajax_error("Failed to fetch 5m traffic summary", $e);
}

$reqPerMin = round($recentReqCount / 5, 1);
$errorRate = ($recentReqCount > 0) ? round(($recentErrors / $recentReqCount) * 100, 2) : 0.0;

$tiles = [
    'cpu_percent'       => isset($latestSM['cpu_percent']) && $latestSM['cpu_percent'] !== null ? (float)$latestSM['cpu_percent'] : 0.0,
    'cpu_cores'         => (int)($latestSM['cpu_cores'] ?? 1),
    'load_1'            => isset($latestSM['load_1']) && $latestSM['load_1'] !== null ? (float)$latestSM['load_1'] : 0.0,
    'load_5'            => isset($latestSM['load_5']) && $latestSM['load_5'] !== null ? (float)$latestSM['load_5'] : 0.0,
    'load_15'           => isset($latestSM['load_15']) && $latestSM['load_15'] !== null ? (float)$latestSM['load_15'] : 0.0,
    'mem_total'         => (int)$memTotal,
    'mem_used'          => (int)$memUsed,
    'mem_percent'       => $memPercent,
    'swap_total'        => (int)$swapTotal,
    'swap_used'         => (int)$swapUsed,
    'swap_percent'      => $swapPercent,
    'disk_total'        => (int)$diskTotal,
    'disk_used'         => (int)$diskUsed,
    'disk_percent'      => $diskPercent,
    'net_rx_rate'       => (int)($latestSM['net_rx_rate'] ?? 0),
    'net_tx_rate'       => (int)($latestSM['net_tx_rate'] ?? 0),
    'uptime_sec'        => (int)($latestSM['uptime_sec'] ?? 0),
    'db_threads'        => isset($latestSM['db_threads']) && $latestSM['db_threads'] !== null ? (int)$latestSM['db_threads'] : 0,
    'db_qps'            => isset($latestSM['db_qps']) && $latestSM['db_qps'] !== null ? (float)$latestSM['db_qps'] : 0.0,
    'db_slow_queries'   => isset($latestSM['db_slow_queries']) && $latestSM['db_slow_queries'] !== null ? (int)$latestSM['db_slow_queries'] : 0,
    'db_size_bytes'     => isset($latestSM['db_size_bytes']) && $latestSM['db_size_bytes'] !== null ? (int)$latestSM['db_size_bytes'] : 0,
    'php_version'       => $latestSM['php_version'] ?? PHP_VERSION,
    'req_per_min'       => $reqPerMin,
    'error_rate'        => $errorRate,
    'collector_running' => $collectorRunning,
    'last_collected_at' => $lastCollectedAt,
    'collector_command' => '* * * * * php ' . realpath(__DIR__ . '/../../cron/collect_metrics.php') . ' >/dev/null 2>&1',
];

// -----------------------------------------------------------------
// 2. CSV Export Mode
// -----------------------------------------------------------------
if ($format === 'csv') {
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="system_metrics_' . $range . '_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-store, no-cache');
    }

    $out = fopen('php://output', 'w');
    // UTF-8 BOM
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Collected At', 'CPU (%)', 'Load 1m', 'Load 5m', 'Load 15m',
        'RAM Used (MB)', 'RAM Total (MB)', 'RAM (%)',
        'Disk Used (GB)', 'Disk Total (GB)', 'Disk (%)',
        'Net RX (KB/s)', 'Net TX (KB/s)', 'DB Threads', 'DB QPS'
    ], ',', '"', "\\");

    try {
        $stmtCsv = $pdo->prepare(
            "SELECT collected_at, cpu_percent, load_1, load_5, load_15,
                    mem_used, mem_total, disk_used, disk_total,
                    net_rx_rate, net_tx_rate, db_threads, db_qps
             FROM system_metrics
             WHERE collected_at >= :since
             ORDER BY id ASC"
        );
        $stmtCsv->execute([':since' => $sinceDate]);
        while ($row = $stmtCsv->fetch(PDO::FETCH_ASSOC)) {
            $ramPct = ((float)($row['mem_total'] ?? 0) > 0) ? round(((float)($row['mem_used'] ?? 0) / (float)$row['mem_total']) * 100, 1) : 0.0;
            $diskPct = ((float)($row['disk_total'] ?? 0) > 0) ? round(((float)($row['disk_used'] ?? 0) / (float)$row['disk_total']) * 100, 1) : 0.0;
            fputcsv($out, [
                $row['collected_at'] ?? date('Y-m-d H:i:s'),
                (float)($row['cpu_percent'] ?? 0),
                (float)($row['load_1'] ?? 0),
                (float)($row['load_5'] ?? 0),
                (float)($row['load_15'] ?? 0),
                round((float)($row['mem_used'] ?? 0) / 1048576, 1),
                round((float)($row['mem_total'] ?? 0) / 1048576, 1),
                $ramPct,
                round((float)($row['disk_used'] ?? 0) / 1073741824, 2),
                round((float)($row['disk_total'] ?? 0) / 1073741824, 2),
                $diskPct,
                round((float)($row['net_rx_rate'] ?? 0) / 1024, 1),
                round((float)($row['net_tx_rate'] ?? 0) / 1024, 1),
                (int)($row['db_threads'] ?? 0),
                (float)($row['db_qps'] ?? 0),
            ], ',', '"', "\\");
        }
    } catch (Throwable $e) {
        log_monitor_ajax_error("CSV export error", $e);
    }
    fclose($out);
    exit;
}

// -----------------------------------------------------------------
// 3. Time-Series Aggregation & Downsampling
// -----------------------------------------------------------------
$series = [
    'labels'     => [],
    'cpu'        => [],
    'load'       => [],
    'mem'        => [],
    'swap'       => [],
    'net_rx'     => [],
    'net_tx'     => [],
    'bw_out'     => [],
    'bw_in'      => [],
    'req'        => [],
    'rt_avg'     => [],
    'status'     => [
        's2xx' => [],
        's3xx' => [],
        's4xx' => [],
        's5xx' => [],
    ],
    'db_threads' => [],
    'db_qps'     => [],
];

// Query System Metrics (bucketed by $stepSec)
$metricBuckets = [];
$stepInt = (int)$stepSec;
try {
    $stmtSeries = $pdo->prepare(
        "SELECT
            FLOOR(UNIX_TIMESTAMP(collected_at) / {$stepInt}) * {$stepInt} AS b_ts,
            ROUND(AVG(COALESCE(cpu_percent, 0)), 1) as avg_cpu,
            ROUND(AVG(COALESCE(load_1, 0)), 2) as avg_load,
            ROUND(AVG(CASE WHEN COALESCE(mem_total, 0) > 0 THEN (COALESCE(mem_used, 0) / mem_total) * 100 ELSE 0 END), 1) as avg_mem_pct,
            ROUND(AVG(CASE WHEN COALESCE(swap_total, 0) > 0 THEN (COALESCE(swap_used, 0) / swap_total) * 100 ELSE 0 END), 1) as avg_swap_pct,
            ROUND(AVG(COALESCE(net_rx_rate, 0))) as avg_rx_rate,
            ROUND(AVG(COALESCE(net_tx_rate, 0))) as avg_tx_rate,
            ROUND(AVG(COALESCE(db_threads, 0))) as avg_db_threads,
            ROUND(AVG(COALESCE(db_qps, 0)), 1) as avg_db_qps
         FROM system_metrics
         WHERE collected_at >= :since
         GROUP BY b_ts
         ORDER BY b_ts ASC
         LIMIT 350"
    );
    $stmtSeries->execute([
        ':since' => $sinceDate,
    ]);
    while ($row = $stmtSeries->fetch(PDO::FETCH_ASSOC)) {
        $metricBuckets[(int)$row['b_ts']] = $row;
    }
} catch (Throwable $e) {
    log_monitor_ajax_error("Failed to query series from system_metrics", $e);
    $metricBuckets = [];
}

// If a specific network interface was chosen, override RX/TX rates with interface samples
if ($ifaceParam !== 'auto') {
    try {
        $stmtIface = $pdo->prepare(
            "SELECT
                FLOOR(UNIX_TIMESTAMP(collected_at) / {$stepInt}) * {$stepInt} AS b_ts,
                ROUND(AVG(COALESCE(rx_rate, 0))) as avg_rx_rate,
                ROUND(AVG(COALESCE(tx_rate, 0))) as avg_tx_rate
             FROM network_samples
             WHERE collected_at >= :since AND iface = :iface
             GROUP BY b_ts"
        );
        $stmtIface->execute([
            ':since' => $sinceDate,
            ':iface' => $ifaceParam,
        ]);
        while ($irow = $stmtIface->fetch(PDO::FETCH_ASSOC)) {
            $bts = (int)$irow['b_ts'];
            if (isset($metricBuckets[$bts])) {
                $metricBuckets[$bts]['avg_rx_rate'] = $irow['avg_rx_rate'];
                $metricBuckets[$bts]['avg_tx_rate'] = $irow['avg_tx_rate'];
            }
        }
    } catch (Throwable $e) {
        log_monitor_ajax_error("Failed to query interface samples for {$ifaceParam}", $e);
    }
}

// Query Application Traffic (bucketed by $stepSec)
$trafficBuckets = [];
try {
    $stmtTraffic = $pdo->prepare(
        "SELECT
            FLOOR(UNIX_TIMESTAMP(bucket) / {$stepInt}) * {$stepInt} AS b_ts,
            SUM(COALESCE(requests, 0)) as sum_req,
            SUM(COALESCE(bytes_out, 0)) as sum_bytes_out,
            SUM(COALESCE(bytes_in, 0)) as sum_bytes_in,
            ROUND(SUM(COALESCE(total_ms, 0)) / NULLIF(SUM(COALESCE(requests, 0)), 0), 1) as avg_rt,
            SUM(COALESCE(s2xx, 0)) as sum_2xx,
            SUM(COALESCE(s3xx, 0)) as sum_3xx,
            SUM(COALESCE(s4xx, 0)) as sum_4xx,
            SUM(COALESCE(s5xx, 0)) as sum_5xx
         FROM traffic_minute
         WHERE bucket >= :since
         GROUP BY b_ts
         ORDER BY b_ts ASC
         LIMIT 350"
    );
    $stmtTraffic->execute([
        ':since' => $sinceDate,
    ]);
    while ($row = $stmtTraffic->fetch(PDO::FETCH_ASSOC)) {
        $trafficBuckets[(int)$row['b_ts']] = $row;
    }
} catch (Throwable $e) {
    log_monitor_ajax_error("Failed to query series from traffic_minute", $e);
    $trafficBuckets = [];
}

// Intersect timestamps
$allTimestamps = array_unique(array_merge(array_keys($metricBuckets), array_keys($trafficBuckets)));
sort($allTimestamps);

// Fallback baseline if no metric or traffic rows exist yet: generate baseline timeline so charts render cleanly
if (empty($allTimestamps)) {
    $baselineCount = max(6, min(24, (int)($sinceSec / max(1, $stepSec))));
    $stepInterval = max(1, (int)floor($sinceSec / max(1, $baselineCount - 1)));
    $tCursor = $now - $sinceSec;
    for ($i = 0; $i < $baselineCount; $i++) {
        $allTimestamps[] = $tCursor;
        $tCursor += $stepInterval;
    }
}

foreach ($allTimestamps as $ts) {
    $sm = $metricBuckets[$ts] ?? [];
    $tf = $trafficBuckets[$ts] ?? [];

    $series['labels'][]     = date($labelFormat, $ts);
    $series['cpu'][]        = isset($sm['avg_cpu']) && $sm['avg_cpu'] !== null ? (float)$sm['avg_cpu'] : 0.0;
    $series['load'][]       = isset($sm['avg_load']) && $sm['avg_load'] !== null ? (float)$sm['avg_load'] : 0.0;
    $series['mem'][]        = isset($sm['avg_mem_pct']) && $sm['avg_mem_pct'] !== null ? (float)$sm['avg_mem_pct'] : 0.0;
    $series['swap'][]       = isset($sm['avg_swap_pct']) && $sm['avg_swap_pct'] !== null ? (float)$sm['avg_swap_pct'] : 0.0;
    $series['net_rx'][]     = isset($sm['avg_rx_rate']) && $sm['avg_rx_rate'] !== null ? (int)$sm['avg_rx_rate'] : 0;
    $series['net_tx'][]     = isset($sm['avg_tx_rate']) && $sm['avg_tx_rate'] !== null ? (int)$sm['avg_tx_rate'] : 0;
    $series['db_threads'][] = isset($sm['avg_db_threads']) && $sm['avg_db_threads'] !== null ? (int)$sm['avg_db_threads'] : 0;
    $series['db_qps'][]     = isset($sm['avg_db_qps']) && $sm['avg_db_qps'] !== null ? (float)$sm['avg_db_qps'] : 0.0;

    $series['bw_out'][]     = (int)($tf['sum_bytes_out'] ?? 0);
    $series['bw_in'][]      = (int)($tf['sum_bytes_in'] ?? 0);
    $series['req'][]        = (int)($tf['sum_req'] ?? 0);
    $series['rt_avg'][]     = isset($tf['avg_rt']) && $tf['avg_rt'] !== null ? (float)$tf['avg_rt'] : 0.0;

    $series['status']['s2xx'][] = (int)($tf['sum_2xx'] ?? 0);
    $series['status']['s3xx'][] = (int)($tf['sum_3xx'] ?? 0);
    $series['status']['s4xx'][] = (int)($tf['sum_4xx'] ?? 0);
    $series['status']['s5xx'][] = (int)($tf['sum_5xx'] ?? 0);
}

// -----------------------------------------------------------------
// 4. Tables: Top 10 Slow Requests, Mounts, Interfaces
// -----------------------------------------------------------------
$slowRequests = [];
try {
    $stmtSlow = $pdo->query(
        "SELECT path, method, status_code, duration_ms, query_count, peak_mem, created_at
         FROM slow_requests
         ORDER BY id DESC
         LIMIT 10"
    );
    if ($stmtSlow) {
        $slowRequests = $stmtSlow->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    log_monitor_ajax_error("Failed to query slow_requests", $e);
    $slowRequests = [];
}

// Monthly Bandwidth Totals
$monthStart = date('Y-m-01 00:00:00');
$monthBandwidthBytes = 0;
try {
    $stmtMBW = $pdo->prepare("SELECT SUM(bytes_out + bytes_in) FROM traffic_minute WHERE bucket >= :mstart");
    $stmtMBW->execute([':mstart' => $monthStart]);
    $monthBandwidthBytes = (int)($stmtMBW->fetchColumn() ?: 0);
} catch (Throwable $e) {
    log_monitor_ajax_error("Failed to query monthly bandwidth totals", $e);
    $monthBandwidthBytes = 0;
}

$quotaGb = (float)setting('monitor_bandwidth_quota_gb', '0');
$quotaBytes = $quotaGb * 1073741824;
$quotaPercent = ($quotaBytes > 0) ? min(100, round(($monthBandwidthBytes / $quotaBytes) * 100, 1)) : 0.0;

$response = [
    'generated_at'  => date('Y-m-d H:i:s'),
    'range'         => $range,
    'iface'         => $ifaceParam,
    'tiles'         => $tiles,
    'series'        => $series,
    'bandwidth'     => [
        'month_bytes'   => $monthBandwidthBytes,
        'quota_gb'      => $quotaGb,
        'quota_percent' => $quotaPercent,
    ],
    'tables'        => [
        'slow'       => $slowRequests,
        'interfaces' => $knownInterfaces,
    ],
];

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}
echo json_encode($response);
exit;
