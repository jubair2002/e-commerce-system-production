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

// List all known network interfaces
$knownInterfaces = ['auto'];
try {
    $ifaceRows = $pdo->query("SELECT DISTINCT iface FROM network_samples ORDER BY iface ASC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ifaceRows as $if) {
        if (!in_array($if, $knownInterfaces, true)) {
            $knownInterfaces[] = $if;
        }
    }
} catch (Throwable $e) {}

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
    $latestSM = $stmtLatest->fetch() ?: [];
} catch (Throwable $e) {}

$lastCollectedAt = $latestSM['collected_at'] ?? null;
$collectorRunning = false;
if ($lastCollectedAt) {
    $collectorLag = $now - strtotime($lastCollectedAt);
    $collectorRunning = ($collectorLag <= 180); // within 3 minutes
}

$memTotal = (float)($latestSM['mem_total'] ?? 0);
$memUsed  = (float)($latestSM['mem_used'] ?? 0);
$memPercent = ($memTotal > 0) ? round(($memUsed / $memTotal) * 100, 1) : 0;

$swapTotal = (float)($latestSM['swap_total'] ?? 0);
$swapUsed  = (float)($latestSM['swap_used'] ?? 0);
$swapPercent = ($swapTotal > 0) ? round(($swapUsed / $swapTotal) * 100, 1) : 0;

$diskTotal = (float)($latestSM['disk_total'] ?? 0);
$diskUsed  = (float)($latestSM['disk_used'] ?? 0);
$diskPercent = ($diskTotal > 0) ? round(($diskUsed / $diskTotal) * 100, 1) : 0;

// Application requests and errors in the last 5 minutes
$recentReqCount = 0;
$recentErrors   = 0;
try {
    $stmtTraffic5m = $pdo->query(
        "SELECT SUM(requests) as reqs, SUM(s5xx) as errs
         FROM traffic_minute
         WHERE bucket >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    );
    $tf5m = $stmtTraffic5m->fetch();
    if ($tf5m) {
        $recentReqCount = (int)($tf5m['reqs'] ?? 0);
        $recentErrors   = (int)($tf5m['errs'] ?? 0);
    }
} catch (Throwable $e) {}

$reqPerMin = round($recentReqCount / 5, 1);
$errorRate = ($recentReqCount > 0) ? round(($recentErrors / $recentReqCount) * 100, 2) : 0.0;

$tiles = [
    'cpu_percent'       => isset($latestSM['cpu_percent']) ? (float)$latestSM['cpu_percent'] : null,
    'cpu_cores'         => (int)($latestSM['cpu_cores'] ?? 1),
    'load_1'            => isset($latestSM['load_1']) ? (float)$latestSM['load_1'] : null,
    'load_5'            => isset($latestSM['load_5']) ? (float)$latestSM['load_5'] : null,
    'load_15'           => isset($latestSM['load_15']) ? (float)$latestSM['load_15'] : null,
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
    'db_threads'        => isset($latestSM['db_threads']) ? (int)$latestSM['db_threads'] : null,
    'db_qps'            => isset($latestSM['db_qps']) ? (float)$latestSM['db_qps'] : null,
    'db_slow_queries'   => isset($latestSM['db_slow_queries']) ? (int)$latestSM['db_slow_queries'] : null,
    'db_size_bytes'     => isset($latestSM['db_size_bytes']) ? (int)$latestSM['db_size_bytes'] : null,
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
        $ramPct = ((float)$row['mem_total'] > 0) ? round(((float)$row['mem_used'] / (float)$row['mem_total']) * 100, 1) : 0;
        $diskPct = ((float)$row['disk_total'] > 0) ? round(((float)$row['disk_used'] / (float)$row['disk_total']) * 100, 1) : 0;
        fputcsv($out, [
            $row['collected_at'],
            $row['cpu_percent'],
            $row['load_1'],
            $row['load_5'],
            $row['load_15'],
            round((float)$row['mem_used'] / 1048576, 1),
            round((float)$row['mem_total'] / 1048576, 1),
            $ramPct,
            round((float)$row['disk_used'] / 1073741824, 2),
            round((float)$row['disk_total'] / 1073741824, 2),
            $diskPct,
            round((float)$row['net_rx_rate'] / 1024, 1),
            round((float)$row['net_tx_rate'] / 1024, 1),
            $row['db_threads'],
            $row['db_qps'],
        ], ',', '"', "\\");
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
try {
    $stmtSeries = $pdo->prepare(
        "SELECT
            FLOOR(UNIX_TIMESTAMP(collected_at) / :step) * :step AS b_ts,
            ROUND(AVG(cpu_percent), 1) as avg_cpu,
            ROUND(AVG(load_1), 2) as avg_load,
            ROUND(AVG(CASE WHEN mem_total > 0 THEN (mem_used / mem_total) * 100 ELSE 0 END), 1) as avg_mem_pct,
            ROUND(AVG(CASE WHEN swap_total > 0 THEN (swap_used / swap_total) * 100 ELSE 0 END), 1) as avg_swap_pct,
            ROUND(AVG(net_rx_rate)) as avg_rx_rate,
            ROUND(AVG(net_tx_rate)) as avg_tx_rate,
            ROUND(AVG(db_threads)) as avg_db_threads,
            ROUND(AVG(db_qps), 1) as avg_db_qps
         FROM system_metrics
         WHERE collected_at >= :since
         GROUP BY b_ts
         ORDER BY b_ts ASC
         LIMIT 350"
    );
    $stmtSeries->execute([
        ':step'  => $stepSec,
        ':since' => $sinceDate,
    ]);
    $metricBuckets = [];
    while ($row = $stmtSeries->fetch(PDO::FETCH_ASSOC)) {
        $metricBuckets[(int)$row['b_ts']] = $row;
    }
} catch (Throwable $e) {
    $metricBuckets = [];
}

// Query Application Traffic (bucketed by $stepSec)
try {
    $stmtTraffic = $pdo->prepare(
        "SELECT
            FLOOR(UNIX_TIMESTAMP(bucket) / :step) * :step AS b_ts,
            SUM(requests) as sum_req,
            SUM(bytes_out) as sum_bytes_out,
            SUM(bytes_in) as sum_bytes_in,
            ROUND(SUM(total_ms) / NULLIF(SUM(requests), 0), 1) as avg_rt,
            SUM(s2xx) as sum_2xx,
            SUM(s3xx) as sum_3xx,
            SUM(s4xx) as sum_4xx,
            SUM(s5xx) as sum_5xx
         FROM traffic_minute
         WHERE bucket >= :since
         GROUP BY b_ts
         ORDER BY b_ts ASC
         LIMIT 350"
    );
    $stmtTraffic->execute([
        ':step'  => $stepSec,
        ':since' => $sinceDate,
    ]);
    $trafficBuckets = [];
    while ($row = $stmtTraffic->fetch(PDO::FETCH_ASSOC)) {
        $trafficBuckets[(int)$row['b_ts']] = $row;
    }
} catch (Throwable $e) {
    $trafficBuckets = [];
}

// Intersect timestamps
$allTimestamps = array_unique(array_merge(array_keys($metricBuckets), array_keys($trafficBuckets)));
sort($allTimestamps);

foreach ($allTimestamps as $ts) {
    $sm = $metricBuckets[$ts] ?? [];
    $tf = $trafficBuckets[$ts] ?? [];

    $series['labels'][]     = date($labelFormat, $ts);
    $series['cpu'][]        = isset($sm['avg_cpu']) ? (float)$sm['avg_cpu'] : null;
    $series['load'][]       = isset($sm['avg_load']) ? (float)$sm['avg_load'] : null;
    $series['mem'][]        = isset($sm['avg_mem_pct']) ? (float)$sm['avg_mem_pct'] : null;
    $series['swap'][]       = isset($sm['avg_swap_pct']) ? (float)$sm['avg_swap_pct'] : null;
    $series['net_rx'][]     = isset($sm['avg_rx_rate']) ? (int)$sm['avg_rx_rate'] : 0;
    $series['net_tx'][]     = isset($sm['avg_tx_rate']) ? (int)$sm['avg_tx_rate'] : 0;
    $series['db_threads'][] = isset($sm['avg_db_threads']) ? (int)$sm['avg_db_threads'] : null;
    $series['db_qps'][]     = isset($sm['avg_db_qps']) ? (float)$sm['avg_db_qps'] : null;

    $series['bw_out'][]     = (int)($tf['sum_bytes_out'] ?? 0);
    $series['bw_in'][]      = (int)($tf['sum_bytes_in'] ?? 0);
    $series['req'][]        = (int)($tf['sum_req'] ?? 0);
    $series['rt_avg'][]     = isset($tf['avg_rt']) ? (float)$tf['avg_rt'] : null;

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
    $slowRequests = $pdo->query(
        "SELECT path, method, status_code, duration_ms, query_count, peak_mem, created_at
         FROM slow_requests
         ORDER BY id DESC
         LIMIT 10"
    )->fetchAll();
} catch (Throwable $e) {}

// Monthly Bandwidth Totals
$monthStart = date('Y-m-01 00:00:00');
$monthBandwidthBytes = 0;
try {
    $stmtMBW = $pdo->prepare("SELECT SUM(bytes_out + bytes_in) FROM traffic_minute WHERE bucket >= :mstart");
    $stmtMBW->execute([':mstart' => $monthStart]);
    $monthBandwidthBytes = (int)($stmtMBW->fetchColumn() ?: 0);
} catch (Throwable $e) {}

$quotaGb = (float)setting('monitor_bandwidth_quota_gb', '0');
$quotaBytes = $quotaGb * 1073741824;
$quotaPercent = ($quotaBytes > 0) ? min(100, round(($monthBandwidthBytes / $quotaBytes) * 100, 1)) : 0;

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
