<?php
/**
 * Admin Analytics AJAX Data Endpoint (Prompt 09).
 * Returns aggregated metrics, charts, funnels, and insights in JSON.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (!has_role('admin', 'editor')) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$from = trim((string)($_GET['from'] ?? date('Y-m-d', strtotime('-29 days'))));
$to   = trim((string)($_GET['to'] ?? date('Y-m-d')));
$catId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$groupBy = in_array($_GET['group_by'] ?? '', ['day', 'week', 'month'], true) ? $_GET['group_by'] : 'day';
$compare = !empty($_GET['compare']);

// Validate date formats
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d', strtotime('-29 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    $tmp = $from;
    $from = $to;
    $to = $tmp;
}

$filters = [
    'category_id' => $catId,
    'group_by'    => $groupBy,
];

try {
    // Current period metrics
    $kpis         = analytics_query('kpis', $from, $to, $filters);
    $trend        = analytics_query('trend', $from, $to, $filters);
    $funnel       = analytics_query('funnel', $from, $to, $filters);
    $topProducts  = analytics_query('top_products', $from, $to, $filters);
    $categories   = analytics_query('categories', $from, $to, $filters);
    $ordersStatus = analytics_query('orders_status', $from, $to);
    $heatmap      = analytics_query('heatmap', $from, $to);
    $traffic      = analytics_query('traffic', $from, $to);
    $device       = analytics_query('device', $from, $to);
    $insights     = analytics_query('insights', $from, $to, $filters);

    $prevKpis = null;
    $deltas   = [];

    if ($compare) {
        $daysDiff = max(1, (int)round((strtotime($to) - strtotime($from)) / 86400) + 1);
        $prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . " -{$daysDiff} days +1 day"));

        $prevKpis = analytics_query('kpis', $prevFrom, $prevTo, $filters);

        foreach ($kpis as $key => $val) {
            $prevVal = $prevKpis[$key] ?? 0;
            if ($prevVal == 0) {
                $pct = $val > 0 ? 100.0 : 0.0;
            } else {
                $pct = round((($val - $prevVal) / $prevVal) * 100, 1);
            }
            $deltas[$key] = [
                'current'  => $val,
                'previous' => $prevVal,
                'pct'      => $pct,
                'is_up'    => ($val >= $prevVal),
            ];
        }
    }

    echo json_encode([
        'ok'            => true,
        'from'          => $from,
        'to'            => $to,
        'filters'       => $filters,
        'kpis'          => $kpis,
        'prev_kpis'     => $prevKpis,
        'deltas'        => $deltas,
        'trend'         => $trend,
        'funnel'        => $funnel,
        'top_products'  => $topProducts,
        'categories'    => $categories,
        'orders_status' => $ordersStatus,
        'heatmap'       => $heatmap,
        'traffic'       => $traffic,
        'device'        => $device,
        'insights'      => $insights,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Analytics query failed: ' . $e->getMessage(),
    ]);
}
