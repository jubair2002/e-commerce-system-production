<?php
/**
 * Privacy-Friendly First-Party Analytics Engine (Prompt 09).
 *
 * Provides:
 *   - Event collection via analytics_track() and hook listeners
 *   - Privacy-friendly hashing (no raw IP or UA stored)
 *   - Roll-up aggregation into product_stats_daily and site_stats_daily
 *   - Unified query layer analytics_query() with live fallback
 *   - Automated data retention purges
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/hooks.php';

// Cache UTM parameters in session if present on landing
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
    if (!empty($_GET['utm_source'])) {
        $_SESSION['analytics_utm_source'] = substr(trim((string)$_GET['utm_source']), 0, 60);
    }
    if (!empty($_GET['utm_campaign'])) {
        $_SESSION['analytics_utm_campaign'] = substr(trim((string)$_GET['utm_campaign']), 0, 80);
    }
}

/**
 * Compute daily rotating privacy hash for the current visitor.
 * md5(IP + UA + daily_salt) — never stores or exposes raw IP.
 */
function analytics_visitor_hash(): string
{
    static $hash = null;
    if ($hash !== null) {
        return $hash;
    }

    $appKey = defined('APP_KEY') && !empty(APP_KEY) ? APP_KEY : 'default-analytics-salt-key';
    $dailySalt = hash('sha256', $appKey . date('Y-m-d'));

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    // Clean potential multiple forwarded IPs to first client IP
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $hash = md5($ip . $ua . $dailySalt);
    return $hash;
}

/**
 * Detect client device class (desktop, mobile, tablet, bot).
 */
function analytics_detect_device(): string
{
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') {
        return 'desktop';
    }

    // Common bot & crawler patterns
    if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|bingbot|googlebot|yandex|duckduckbot|baiduspider|twitterbot/i', $ua)) {
        return 'bot';
    }

    // Tablet
    if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) {
        return 'tablet';
    }

    // Mobile
    if (preg_match('/mobile|android|iphone|ipod|blackberry|opera mini|iemobile|wpdesktop/i', $ua)) {
        return 'mobile';
    }

    return 'desktop';
}

/**
 * Determine referrer host (strips own domain to "direct").
 */
function analytics_referrer_host(): string
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if (empty($ref)) {
        return 'direct';
    }

    $host = parse_url($ref, PHP_URL_HOST);
    if (!$host) {
        return 'direct';
    }

    $myHost = $_SERVER['HTTP_HOST'] ?? '';
    if (strcasecmp($host, $myHost) === 0) {
        return 'direct';
    }

    return substr(strtolower($host), 0, 120);
}

/**
 * Insert an event into analytics_events.
 *
 * @param string $type Event type enum: 'product_view','add_to_cart','remove_from_cart','checkout_start','purchase','search','page_view'
 * @param array $data Additional attributes (product_id, variant_id, order_id, qty, value, etc.)
 */
function analytics_track(string $type, array $data = []): bool
{
    // 1. Master toggle
    if ((int)setting('analytics_enabled', '1') !== 1) {
        return false;
    }

    // 2. Do Not Track honor
    if ((int)setting('analytics_respect_dnt', '0') === 1) {
        if (!empty($_SERVER['HTTP_DNT']) && $_SERVER['HTTP_DNT'] === '1') {
            return false;
        }
    }

    // 3. Skip prefetch & preview requests
    if (
        (isset($_SERVER['HTTP_X_PURPOSE']) && $_SERVER['HTTP_X_PURPOSE'] === 'preview') ||
        (isset($_SERVER['HTTP_PURPOSE']) && $_SERVER['HTTP_PURPOSE'] === 'preview')
    ) {
        return false;
    }

    // 4. Skip logged-in admins if setting enabled
    if ((int)setting('analytics_exclude_admin', '1') === 1) {
        if (!empty($_SESSION['user']['id'])) {
            return false;
        }
    }

    $device = analytics_detect_device();
    // 5. Skip bots if setting enabled
    if ($device === 'bot' && (int)setting('analytics_exclude_bots', '1') === 1) {
        return false;
    }

    $productId = !empty($data['product_id']) ? (int)$data['product_id'] : null;
    $variantId = !empty($data['variant_id']) ? (int)$data['variant_id'] : null;
    $orderId   = !empty($data['order_id'])   ? (int)$data['order_id']   : null;
    $qty       = isset($data['qty']) ? (int)$data['qty'] : null;
    $value     = isset($data['value']) ? (float)$data['value'] : null;

    // 6. Deduplicate product_view by same visitor + product within 30 min (session timestamp)
    if ($type === 'product_view' && $productId) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $now = time();
            $lastView = $_SESSION['analytics_views'][$productId] ?? 0;
            if (($now - $lastView) < 1800) {
                return false; // Skip duplicate within 30 min
            }
            $_SESSION['analytics_views'][$productId] = $now;
        }
    }

    $visitorHash  = analytics_visitor_hash();
    $referrerHost = analytics_referrer_host();
    $utmSource    = !empty($data['utm_source']) ? substr($data['utm_source'], 0, 60) : ($_SESSION['analytics_utm_source'] ?? null);
    $utmCampaign  = !empty($data['utm_campaign']) ? substr($data['utm_campaign'], 0, 80) : ($_SESSION['analytics_utm_campaign'] ?? null);

    try {
        $stmt = db()->prepare(
            "INSERT INTO analytics_events 
             (event_type, product_id, variant_id, order_id, visitor_hash, qty, value, referrer_host, utm_source, utm_campaign, device, created_at)
             VALUES (:type, :pid, :vid, :oid, :vhash, :qty, :val, :ref, :utms, :utmc, :device, NOW())"
        );
        $stmt->execute([
            ':type'   => $type,
            ':pid'    => $productId,
            ':vid'    => $variantId,
            ':oid'    => $orderId,
            ':vhash'  => $visitorHash,
            ':qty'    => $qty,
            ':val'    => $value,
            ':ref'    => $referrerHost,
            ':utms'   => $utmSource,
            ':utmc'   => $utmCampaign,
            ':device' => $device,
        ]);
        return true;
    } catch (Throwable $e) {
        // Analytics must never break page response
        error_log('Analytics track error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Record a search term in search_log and emit an analytics search event.
 */
function analytics_log_search(string $term, int $resultsCount): void
{
    $clean = trim($term);
    if ($clean === '') {
        return;
    }

    try {
        $vhash = analytics_visitor_hash();
        $stmt = db()->prepare(
            "INSERT INTO search_log (term, results, visitor_hash, created_at)
             VALUES (:term, :results, :vhash, NOW())"
        );
        $stmt->execute([
            ':term'    => mb_substr($clean, 0, 120),
            ':results' => max(0, $resultsCount),
            ':vhash'   => $vhash,
        ]);
    } catch (Throwable $e) {
        error_log('Search log error: ' . $e->getMessage());
    }

    analytics_track('search', [
        'value' => $resultsCount,
    ]);
}

// -------------------------------------------------------------
// Standard Hook Listeners (Prompt 01 / 09)
// -------------------------------------------------------------

hook_on('product.viewed', function (array $payload) {
    if (!empty($payload['product_id'])) {
        analytics_track('product_view', [
            'product_id' => (int)$payload['product_id'],
            'value'      => isset($payload['price']) ? (float)$payload['price'] : null,
        ]);
    }
});

hook_on('cart.added', function (array $payload) {
    if (!empty($payload['product_id'])) {
        analytics_track('add_to_cart', [
            'product_id' => (int)$payload['product_id'],
            'variant_id' => !empty($payload['variant_id']) ? (int)$payload['variant_id'] : null,
            'qty'        => !empty($payload['quantity']) ? (int)$payload['quantity'] : 1,
            'value'      => isset($payload['price']) ? (float)$payload['price'] : null,
        ]);
    }
});

hook_on('cart.removed', function (array $payload) {
    if (!empty($payload['product_id'])) {
        analytics_track('remove_from_cart', [
            'product_id' => (int)$payload['product_id'],
            'variant_id' => !empty($payload['variant_id']) ? (int)$payload['variant_id'] : null,
            'qty'        => !empty($payload['quantity']) ? (int)$payload['quantity'] : 1,
        ]);
    }
});

hook_on('checkout.started', function (array $payload) {
    analytics_track('checkout_start', [
        'value' => isset($payload['total']) ? (float)$payload['total'] : null,
    ]);
});

hook_on('order.placed', function (array $payload) {
    $orderId = !empty($payload['order_id']) ? (int)$payload['order_id'] : null;
    if (!$orderId) {
        return;
    }

    try {
        $stmt = db()->prepare(
            "SELECT product_id, variant_id, quantity, line_total 
             FROM order_items WHERE order_id = :oid"
        );
        $stmt->execute([':oid' => $orderId]);
        $items = $stmt->fetchAll();

        foreach ($items as $item) {
            analytics_track('purchase', [
                'order_id'   => $orderId,
                'product_id' => (int)$item['product_id'],
                'variant_id' => !empty($item['variant_id']) ? (int)$item['variant_id'] : null,
                'qty'        => (int)$item['quantity'],
                'value'      => (float)$item['line_total'],
            ]);
        }
    } catch (Throwable $e) {
        error_log('Order.placed analytics hook error: ' . $e->getMessage());
    }
});

// -------------------------------------------------------------
// Roll-up computation and aggregation
// -------------------------------------------------------------

/**
 * Execute roll-up aggregation into product_stats_daily and site_stats_daily.
 * Recomputes today and yesterday (or a specific date).
 */
function analytics_rollup(?string $targetDate = null): array
{
    $pdo = db();
    $dates = $targetDate ? [$targetDate] : [
        date('Y-m-d', strtotime('-1 day')),
        date('Y-m-d'),
    ];

    $productRowsUpdated = 0;
    $siteRowsUpdated    = 0;

    foreach ($dates as $date) {
        // 1. Roll-up product_stats_daily
        $stmt = $pdo->prepare(
            "INSERT INTO product_stats_daily (stat_date, product_id, views, unique_views, add_to_cart, orders, units_sold, revenue)
             SELECT 
                 :stat_date AS stat_date,
                 p.id AS product_id,
                 COALESCE(ev.views, 0) AS views,
                 COALESCE(ev.unique_views, 0) AS unique_views,
                 COALESCE(ev.add_to_cart, 0) AS add_to_cart,
                 COALESCE(ord.orders_count, 0) AS orders,
                 COALESCE(ord.units_sold, 0) AS units_sold,
                 COALESCE(ord.revenue, 0.00) AS revenue
             FROM products p
             LEFT JOIN (
                 SELECT 
                     product_id,
                     SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS views,
                     COUNT(DISTINCT CASE WHEN event_type = 'product_view' THEN visitor_hash END) AS unique_views,
                     SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS add_to_cart
                 FROM analytics_events
                 WHERE DATE(created_at) = :stat_date_ev AND product_id IS NOT NULL
                 GROUP BY product_id
             ) ev ON ev.product_id = p.id
             LEFT JOIN (
                 SELECT 
                     oi.product_id,
                     COUNT(DISTINCT o.id) AS orders_count,
                     SUM(oi.quantity) AS units_sold,
                     SUM(oi.line_total) AS revenue
                 FROM order_items oi
                 JOIN orders o ON o.id = oi.order_id
                 WHERE DATE(o.created_at) = :stat_date_ord AND o.status <> 'cancelled'
                 GROUP BY oi.product_id
             ) ord ON ord.product_id = p.id
             WHERE COALESCE(ev.views, 0) > 0 OR COALESCE(ord.units_sold, 0) > 0
             ON DUPLICATE KEY UPDATE
                 views        = VALUES(views),
                 unique_views = VALUES(unique_views),
                 add_to_cart  = VALUES(add_to_cart),
                 orders       = VALUES(orders),
                 units_sold   = VALUES(units_sold),
                 revenue      = VALUES(revenue)"
        );
        $stmt->execute([
            ':stat_date'     => $date,
            ':stat_date_ev'  => $date,
            ':stat_date_ord' => $date,
        ]);
        $productRowsUpdated += $stmt->rowCount();

        // 2. Roll-up site_stats_daily
        $stmtSite = $pdo->prepare(
            "INSERT INTO site_stats_daily (stat_date, visitors, product_views, add_to_cart, checkout_starts, orders, cancelled_orders, revenue, avg_order_value)
             SELECT 
                 :stat_date AS stat_date,
                 COALESCE(ev.visitors, 0) AS visitors,
                 COALESCE(ev.product_views, 0) AS product_views,
                 COALESCE(ev.add_to_cart, 0) AS add_to_cart,
                 COALESCE(ev.checkout_starts, 0) AS checkout_starts,
                 COALESCE(ord.orders_count, 0) AS orders,
                 COALESCE(ord.cancelled_orders, 0) AS cancelled_orders,
                 COALESCE(ord.revenue, 0.00) AS revenue,
                 CASE WHEN COALESCE(ord.orders_count, 0) > 0 
                      THEN ROUND(COALESCE(ord.revenue, 0.00) / ord.orders_count, 2)
                      ELSE 0.00 END AS avg_order_value
             FROM (SELECT 1) dummy
             LEFT JOIN (
                 SELECT 
                     COUNT(DISTINCT visitor_hash) AS visitors,
                     SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS product_views,
                     SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS add_to_cart,
                     SUM(CASE WHEN event_type = 'checkout_start' THEN 1 ELSE 0 END) AS checkout_starts
                 FROM analytics_events
                 WHERE DATE(created_at) = :stat_date_ev
             ) ev ON 1=1
             LEFT JOIN (
                 SELECT 
                     COUNT(DISTINCT CASE WHEN status <> 'cancelled' THEN id END) AS orders_count,
                     COUNT(DISTINCT CASE WHEN status = 'cancelled' THEN id END) AS cancelled_orders,
                     SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0 END) AS revenue
                 FROM orders
                 WHERE DATE(created_at) = :stat_date_ord
             ) ord ON 1=1
             ON DUPLICATE KEY UPDATE
                 visitors         = VALUES(visitors),
                 product_views    = VALUES(product_views),
                 add_to_cart      = VALUES(add_to_cart),
                 checkout_starts  = VALUES(checkout_starts),
                 orders           = VALUES(orders),
                 cancelled_orders = VALUES(cancelled_orders),
                 revenue          = VALUES(revenue),
                 avg_order_value  = VALUES(avg_order_value)"
        );
        $stmtSite->execute([
            ':stat_date'     => $date,
            ':stat_date_ev'  => $date,
            ':stat_date_ord' => $date,
        ]);
        $siteRowsUpdated += $stmtSite->rowCount();
    }

    // 3. Purge older analytics events in batches
    $retentionDays = max(30, (int)setting('analytics_retention_days', '180'));
    $purgedEvents = 0;
    try {
        $stmtPurge = $pdo->prepare(
            "DELETE FROM analytics_events 
             WHERE created_at < DATE_SUB(CURDATE(), INTERVAL :days DAY)
             LIMIT 5000"
        );
        $stmtPurge->execute([':days' => $retentionDays]);
        $purgedEvents = $stmtPurge->rowCount();
    } catch (Throwable $e) {
        error_log('Analytics purge error: ' . $e->getMessage());
    }

    return [
        'dates'               => $dates,
        'product_stats_rows'  => $productRowsUpdated,
        'site_stats_rows'     => $siteRowsUpdated,
        'purged_events'       => $purgedEvents,
    ];
}

// -------------------------------------------------------------
// Unified Analytics Query Layer
// -------------------------------------------------------------

/**
 * Universal query interface for analytics metrics.
 * Automatically switches between daily roll-ups and raw live queries.
 *
 * @param string $metric 'kpis', 'trend', 'funnel', 'top_products', 'categories', 'orders_status', 'heatmap', 'traffic', 'device', 'products_table', 'insights'
 * @param string $from Y-m-d start date
 * @param string $to Y-m-d end date
 * @param array $filters Additional filters: category_id, limit, etc.
 */
function analytics_query(string $metric, string $from, string $to, array $filters = []): mixed
{
    $pdo = db();
    $from = date('Y-m-d', strtotime($from));
    $to   = date('Y-m-d', strtotime($to));

    // Limit maximum window to 366 days
    $diff = (strtotime($to) - strtotime($from)) / 86400;
    if ($diff > 366) {
        $from = date('Y-m-d', strtotime($to . ' -366 days'));
    }

    switch ($metric) {
        case 'kpis':
            return analytics_query_kpis($from, $to, $filters);

        case 'trend':
            return analytics_query_trend($from, $to, $filters);

        case 'funnel':
            return analytics_query_funnel($from, $to, $filters);

        case 'top_products':
            return analytics_query_top_products($from, $to, $filters);

        case 'categories':
            return analytics_query_categories($from, $to, $filters);

        case 'orders_status':
            return analytics_query_orders_status($from, $to);

        case 'heatmap':
            return analytics_query_heatmap($from, $to);

        case 'traffic':
            return analytics_query_traffic($from, $to);

        case 'device':
            return analytics_query_device($from, $to);

        case 'products_table':
            return analytics_query_products_table($from, $to, $filters);

        case 'insights':
            return analytics_query_insights($from, $to, $filters);

        default:
            return [];
    }
}

/**
 * Compute primary KPI metrics for the date range.
 */
function analytics_query_kpis(string $from, string $to, array $filters = []): array
{
    $pdo = db();
    $catId = !empty($filters['category_id']) ? (int)$filters['category_id'] : 0;

    // 1. Orders, revenue, units, cancelled from orders & order_items
    $orderSql = "SELECT 
                    COUNT(DISTINCT CASE WHEN o.status <> 'cancelled' THEN o.id END) AS orders_count,
                    COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN o.total ELSE 0 END), 0) AS revenue,
                    COUNT(DISTINCT CASE WHEN o.status = 'cancelled' THEN o.id END) AS cancelled_count,
                    COUNT(DISTINCT o.id) AS total_orders
                 FROM orders o
                 WHERE DATE(o.created_at) BETWEEN :from AND :to";
    $stmt = $pdo->prepare($orderSql);
    $stmt->execute([':from' => $from, ':to' => $to]);
    $orderStats = $stmt->fetch() ?: [];

    // Units sold
    $unitsSql = "SELECT COALESCE(SUM(oi.quantity), 0) AS units_sold
                 FROM order_items oi
                 JOIN orders o ON o.id = oi.order_id
                 WHERE DATE(o.created_at) BETWEEN :from AND :to AND o.status <> 'cancelled'";
    if ($catId > 0) {
        $unitsSql .= " AND oi.product_id IN (SELECT id FROM products WHERE category_id = {$catId})";
    }
    $stmtU = $pdo->prepare($unitsSql);
    $stmtU->execute([':from' => $from, ':to' => $to]);
    $units = (int)$stmtU->fetchColumn();

    // 2. Events data (Visitors, product views, add-to-cart, checkout starts)
    $evSql = "SELECT 
                COUNT(DISTINCT visitor_hash) AS visitors,
                SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS product_views,
                SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS add_to_cart,
                SUM(CASE WHEN event_type = 'checkout_start' THEN 1 ELSE 0 END) AS checkout_starts
              FROM analytics_events
              WHERE DATE(created_at) BETWEEN :from AND :to";
    if ($catId > 0) {
        $evSql .= " AND (product_id IN (SELECT id FROM products WHERE category_id = {$catId}) OR product_id IS NULL)";
    }
    $stmtEv = $pdo->prepare($evSql);
    $stmtEv->execute([':from' => $from, ':to' => $to]);
    $evStats = $stmtEv->fetch() ?: [];

    // 3. COD pending amount (cash to collect)
    $codPending = (float)$pdo->query(
        "SELECT COALESCE(SUM(total), 0) FROM orders 
         WHERE payment_method = 'cod' 
           AND status IN ('pending', 'processing') 
           AND payment_status <> 'paid'"
    )->fetchColumn();

    $revenue   = (float)($orderStats['revenue'] ?? 0);
    $orders    = (int)($orderStats['orders_count'] ?? 0);
    $totalOrds = (int)($orderStats['total_orders'] ?? 0);
    $cancelled = (int)($orderStats['cancelled_count'] ?? 0);

    $visitors  = (int)($evStats['visitors'] ?? 0);
    $views     = (int)($evStats['product_views'] ?? 0);
    $cartAdds  = (int)($evStats['add_to_cart'] ?? 0);

    $aov = $orders > 0 ? round($revenue / $orders, 2) : 0.0;
    $cartRate = $views > 0 ? round(($cartAdds / $views) * 100, 1) : 0.0;
    $convRate = $visitors > 0 ? round(($orders / $visitors) * 100, 2) : 0.0;
    $cancelRate = $totalOrds > 0 ? round(($cancelled / $totalOrds) * 100, 1) : 0.0;

    return [
        'revenue'          => $revenue,
        'orders'           => $orders,
        'units_sold'       => $units,
        'aov'              => $aov,
        'visitors'         => $visitors,
        'product_views'    => $views,
        'add_to_cart'      => $cartAdds,
        'add_to_cart_rate' => $cartRate,
        'conversion_rate'  => $convRate,
        'cancel_rate'      => $cancelRate,
        'cod_pending'      => $codPending,
    ];
}

/**
 * Revenue and orders trend over time (Daily / Weekly / Monthly).
 */
function analytics_query_trend(string $from, string $to, array $filters = []): array
{
    $pdo = db();
    $groupBy = $filters['group_by'] ?? 'day'; // day, week, month

    $dateFormat = "%Y-%m-%d";
    if ($groupBy === 'week') {
        $dateFormat = "%Y-%u"; // year-week
    } elseif ($groupBy === 'month') {
        $dateFormat = "%Y-%m"; // year-month
    }

    $stmt = $pdo->prepare(
        "SELECT 
             DATE_FORMAT(created_at, '{$dateFormat}') AS period,
             MIN(DATE(created_at)) AS start_date,
             COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0 END), 0) AS revenue,
             COUNT(DISTINCT CASE WHEN status <> 'cancelled' THEN id END) AS orders_count
         FROM orders
         WHERE DATE(created_at) BETWEEN :from AND :to
         GROUP BY period
         ORDER BY start_date ASC"
    );
    $stmt->execute([':from' => $from, ':to' => $to]);
    $rows = $stmt->fetchAll();

    $labels  = [];
    $revenue = [];
    $orders  = [];

    foreach ($rows as $r) {
        $label = date('M j', strtotime($r['start_date']));
        if ($groupBy === 'month') {
            $label = date('M Y', strtotime($r['start_date']));
        } elseif ($groupBy === 'week') {
            $label = 'W' . date('W, M j', strtotime($r['start_date']));
        }

        $labels[]  = $label;
        $revenue[] = (float)$r['revenue'];
        $orders[]  = (int)$r['orders_count'];
    }

    return [
        'labels'  => $labels,
        'revenue' => $revenue,
        'orders'  => $orders,
    ];
}

/**
 * Conversion Funnel query: Views -> Cart -> Checkout -> Purchase.
 */
function analytics_query_funnel(string $from, string $to, array $filters = []): array
{
    $pdo = db();

    $stmt = $pdo->prepare(
        "SELECT 
             SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS views,
             SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS cart_adds,
             SUM(CASE WHEN event_type = 'checkout_start' THEN 1 ELSE 0 END) AS checkouts
         FROM analytics_events
         WHERE DATE(created_at) BETWEEN :from AND :to"
    );
    $stmt->execute([':from' => $from, ':to' => $to]);
    $ev = $stmt->fetch() ?: [];

    // Real orders (excluding cancelled)
    $stmtO = $pdo->prepare(
        "SELECT COUNT(*) FROM orders 
         WHERE DATE(created_at) BETWEEN :from AND :to AND status <> 'cancelled'"
    );
    $stmtO->execute([':from' => $from, ':to' => $to]);
    $purchases = (int)$stmtO->fetchColumn();

    $views     = (int)($ev['views'] ?? 0);
    $cartAdds  = (int)($ev['cart_adds'] ?? 0);
    $checkouts = (int)($ev['checkouts'] ?? 0);

    // Fallbacks if storefront is new or events count < orders count
    if ($checkouts < $purchases) {
        $checkouts = $purchases;
    }
    if ($cartAdds < $checkouts) {
        $cartAdds = $checkouts;
    }
    if ($views < $cartAdds) {
        $views = $cartAdds;
    }

    $stages = [
        ['name' => 'Product Views',     'count' => $views,     'drop_rate' => 0],
        [
            'name'      => 'Added to Cart',
            'count'     => $cartAdds,
            'drop_rate' => $views > 0 ? round(max(0, 100 - (($cartAdds / $views) * 100)), 1) : 0,
        ],
        [
            'name'      => 'Checkout Started',
            'count'     => $checkouts,
            'drop_rate' => $cartAdds > 0 ? round(max(0, 100 - (($checkouts / $cartAdds) * 100)), 1) : 0,
        ],
        [
            'name'      => 'Purchased',
            'count'     => $purchases,
            'drop_rate' => $checkouts > 0 ? round(max(0, 100 - (($purchases / $checkouts) * 100)), 1) : 0,
        ],
    ];

    return $stages;
}

/**
 * Top 10 products by revenue and units.
 */
function analytics_query_top_products(string $from, string $to, array $filters = []): array
{
    $pdo = db();
    $catId = !empty($filters['category_id']) ? (int)$filters['category_id'] : 0;

    $sql = "SELECT 
                p.id, p.name, p.image, c.name AS category_name,
                SUM(oi.quantity) AS units,
                SUM(oi.line_total) AS revenue
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN products p ON p.id = oi.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE DATE(o.created_at) BETWEEN :from AND :to AND o.status <> 'cancelled'";
    if ($catId > 0) {
        $sql .= " AND p.category_id = {$catId}";
    }
    $sql .= " GROUP BY p.id, p.name, p.image, c.name
              ORDER BY revenue DESC
              LIMIT 10";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':from' => $from, ':to' => $to]);
    $byRevenue = $stmt->fetchAll();

    // By units
    $sqlUnits = str_replace('ORDER BY revenue DESC', 'ORDER BY units DESC', $sql);
    $stmtU = $pdo->prepare($sqlUnits);
    $stmtU->execute([':from' => $from, ':to' => $to]);
    $byUnits = $stmtU->fetchAll();

    return [
        'by_revenue' => $byRevenue,
        'by_units'   => $byUnits,
    ];
}

/**
 * Category share of total revenue.
 */
function analytics_query_categories(string $from, string $to, array $filters = []): array
{
    $pdo = db();

    $stmt = $pdo->prepare(
        "SELECT 
             COALESCE(c.name, 'Uncategorized') AS category_name,
             COALESCE(SUM(oi.line_total), 0) AS revenue,
             COALESCE(SUM(oi.quantity), 0) AS units
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         JOIN products p ON p.id = oi.product_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE DATE(o.created_at) BETWEEN :from AND :to AND o.status <> 'cancelled'
         GROUP BY category_name
         ORDER BY revenue DESC"
    );
    $stmt->execute([':from' => $from, ':to' => $to]);
    return $stmt->fetchAll();
}

/**
 * Orders breakdown by status.
 */
function analytics_query_orders_status(string $from, string $to): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS count, COALESCE(SUM(total), 0) AS revenue
         FROM orders
         WHERE DATE(created_at) BETWEEN :from AND :to
         GROUP BY status
         ORDER BY count DESC"
    );
    $stmt->execute([':from' => $from, ':to' => $to]);
    return $stmt->fetchAll();
}

/**
 * Heatmap: orders by Day of Week (0-6) x Hour of Day (0-23).
 */
function analytics_query_heatmap(string $from, string $to): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT 
             DAYOFWEEK(created_at) - 1 AS day_of_week, -- 0=Sunday, 6=Saturday
             HOUR(created_at) AS hour_of_day,
             COUNT(*) AS count
         FROM orders
         WHERE DATE(created_at) BETWEEN :from AND :to AND status <> 'cancelled'
         GROUP BY day_of_week, hour_of_day"
    );
    $stmt->execute([':from' => $from, ':to' => $to]);
    $rows = $stmt->fetchAll();

    $matrix = [];
    for ($d = 0; $d < 7; $d++) {
        for ($h = 0; $h < 24; $h++) {
            $matrix[$d][$h] = 0;
        }
    }
    foreach ($rows as $r) {
        $matrix[(int)$r['day_of_week']][(int)$r['hour_of_day']] = (int)$r['count'];
    }

    return $matrix;
}

/**
 * Traffic sources (referrer host and utm_source).
 */
function analytics_query_traffic(string $from, string $to): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT 
             COALESCE(referrer_host, 'direct') AS source,
             COUNT(DISTINCT visitor_hash) AS visitors,
             COUNT(*) AS events
         FROM analytics_events
         WHERE DATE(created_at) BETWEEN :from AND :to
         GROUP BY source
         ORDER BY visitors DESC
         LIMIT 8"
    );
    $stmt->execute([':from' => $from, ':to' => $to]);
    return $stmt->fetchAll();
}

/**
 * Device split (desktop, mobile, tablet).
 */
function analytics_query_device(string $from, string $to): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT 
             device,
             COUNT(DISTINCT visitor_hash) AS visitors
         FROM analytics_events
         WHERE DATE(created_at) BETWEEN :from AND :to AND device <> 'bot'
         GROUP BY device"
    );
    $stmt->execute([':from' => $from, ':to' => $to]);
    $rows = $stmt->fetchAll();

    $split = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
    foreach ($rows as $r) {
        $dev = $r['device'];
        if (isset($split[$dev])) {
            $split[$dev] = (int)$r['visitors'];
        }
    }
    return $split;
}

/**
 * Product analytics table with performance badges, velocity, and stock days.
 */
function analytics_query_products_table(string $from, string $to, array $filters = []): array
{
    $pdo = db();
    $catId  = !empty($filters['category_id']) ? (int)$filters['category_id'] : 0;
    $search = !empty($filters['search']) ? trim($filters['search']) : '';
    $page   = max(1, (int)($filters['page'] ?? 1));
    $limit  = max(1, min(100, (int)($filters['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $countParams = [];

    if ($catId > 0) {
        $where[] = "p.category_id = :cat_id";
        $countParams[':cat_id'] = $catId;
    }
    if ($search !== '') {
        $where[] = "(p.name LIKE :q OR p.sku LIKE :q)";
        $countParams[':q'] = "%{$search}%";
    }

    $whereClause = implode(' AND ', $where);

    // Count total products
    $countSql = "SELECT COUNT(*) FROM products p WHERE {$whereClause}";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($countParams);
    $totalCount = (int)$stmtCount->fetchColumn();

    $diffDays = max(1, (int)round((strtotime($to) - strtotime($from)) / 86400) + 1);
    $prevToDate   = date('Y-m-d 23:59:59', strtotime($from . ' -1 day'));
    $prevFromDate = date('Y-m-d 00:00:00', strtotime($from . " -{$diffDays} days"));

    // Query product data with events & sales metrics
    $sql = "SELECT 
                p.id, p.name, p.sku, p.stock, p.price, p.sale_price, p.image,
                c.name AS category_name,
                COALESCE(ev.views, 0) AS views,
                COALESCE(ev.unique_visitors, 0) AS unique_visitors,
                COALESCE(ev.add_to_cart, 0) AS add_to_cart,
                COALESCE(ord.orders_count, 0) AS orders_count,
                COALESCE(ord.units_sold, 0) AS units_sold,
                COALESCE(ord.revenue, 0.00) AS revenue,
                COALESCE(prev.views, 0) AS prev_views,
                COALESCE(recent.units_30d, 0) AS units_30d
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN (
                SELECT 
                    product_id,
                    SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS views,
                    COUNT(DISTINCT CASE WHEN event_type = 'product_view' THEN visitor_hash END) AS unique_visitors,
                    SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS add_to_cart
                FROM analytics_events
                WHERE DATE(created_at) BETWEEN :from_ev AND :to_ev
                GROUP BY product_id
            ) ev ON ev.product_id = p.id
            LEFT JOIN (
                SELECT 
                    oi.product_id,
                    COUNT(DISTINCT o.id) AS orders_count,
                    SUM(oi.quantity) AS units_sold,
                    SUM(oi.line_total) AS revenue
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE DATE(o.created_at) BETWEEN :from_ord AND :to_ord AND o.status <> 'cancelled'
                GROUP BY oi.product_id
            ) ord ON ord.product_id = p.id
            LEFT JOIN (
                -- Previous period views for Trending badge check
                SELECT 
                    product_id,
                    SUM(CASE WHEN event_type = 'product_view' THEN 1 ELSE 0 END) AS views
                FROM analytics_events
                WHERE created_at BETWEEN :from_prev AND :to_prev
                GROUP BY product_id
            ) prev ON prev.product_id = p.id
            LEFT JOIN (
                -- Last 30 days sales velocity for stock exhaustion days
                SELECT 
                    oi.product_id,
                    SUM(oi.quantity) AS units_30d
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND o.status <> 'cancelled'
                GROUP BY oi.product_id
            ) recent ON recent.product_id = p.id
            WHERE {$whereClause}
            ORDER BY revenue DESC, views DESC
            LIMIT {$limit} OFFSET {$offset}";

    $mainParams = array_merge($countParams, [
        ':from_ev'   => $from,
        ':to_ev'     => $to,
        ':from_ord'  => $from,
        ':to_ord'    => $to,
        ':from_prev' => $prevFromDate,
        ':to_prev'   => $prevToDate,
    ]);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($mainParams);
    $rows = $stmt->fetchAll();

    // Process badges, rates, and sparklines
    foreach ($rows as &$r) {
        $views = (int)$r['views'];
        $cart  = (int)$r['add_to_cart'];
        $units = (int)$r['units_sold'];
        $stock = (int)$r['stock'];
        $prevViews = (int)$r['prev_views'];
        $units30d  = (int)$r['units_30d'];

        $r['view_to_cart_pct'] = $views > 0 ? round(($cart / $views) * 100, 1) : 0.0;
        $r['cart_to_buy_pct']  = $cart > 0 ? round(($units / $cart) * 100, 1) : 0.0;
        $r['avg_price']        = $units > 0 ? round((float)$r['revenue'] / $units, 2) : (float)($r['sale_price'] ?: $r['price']);

        // Days of stock remaining (stock / avg daily units last 30d)
        $dailyVelocity = $units30d / 30.0;
        if ($stock <= 0) {
            $r['days_stock'] = 0;
        } elseif ($dailyVelocity > 0) {
            $r['days_stock'] = (int)ceil($stock / $dailyVelocity);
        } else {
            $r['days_stock'] = 999; // Abundant / no recent sales
        }

        // Status Badge Logic
        // Trending: views +50% vs prev period (min 10 views)
        // Out of stock: stock <= 0
        // Low stock: stock <= 5
        // Slow mover: views > 20 but 0 units sold
        // Dead stock: 0 views and 0 sales in period
        if ($stock <= 0) {
            $r['badge'] = ['label' => 'Out of Stock', 'class' => 'bg-danger'];
        } elseif ($prevViews > 0 && $views >= 10 && ($views - $prevViews) / $prevViews >= 0.5) {
            $r['badge'] = ['label' => '🔥 Trending', 'class' => 'bg-warning text-dark'];
        } elseif ($stock <= 5) {
            $r['badge'] = ['label' => 'Low Stock', 'class' => 'bg-danger text-light'];
        } elseif ($views >= 20 && $units === 0) {
            $r['badge'] = ['label' => 'Slow Mover', 'class' => 'bg-secondary'];
        } elseif ($views === 0 && $units === 0) {
            $r['badge'] = ['label' => 'Dead Stock', 'class' => 'bg-dark'];
        } else {
            $r['badge'] = ['label' => 'Active', 'class' => 'bg-success bg-opacity-75'];
        }
    }
    unset($r);

    return [
        'total'   => $totalCount,
        'page'    => $page,
        'limit'   => $limit,
        'pages'   => (int)ceil($totalCount / $limit),
        'items'   => $rows,
    ];
}

/**
 * Plain-language Insights Engine (no ML).
 */
function analytics_query_insights(string $from, string $to, array $filters = []): array
{
    $pdo = db();
    $insights = [];

    // 1. Low stock & selling fast: <= 5 left, velocity > 0
    try {
        $stmt = $pdo->query(
            "SELECT p.id, p.name, p.stock, COALESCE(SUM(oi.quantity), 0) AS sold_30d
             FROM products p
             JOIN order_items oi ON oi.product_id = p.id
             JOIN orders o ON o.id = oi.order_id AND o.status <> 'cancelled'
             WHERE p.stock > 0 AND p.stock <= 5 AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY p.id, p.name, p.stock
             HAVING sold_30d >= 3
             ORDER BY p.stock ASC
             LIMIT 3"
        );
        $fast = $stmt->fetchAll();
        foreach ($fast as $f) {
            $daily = $f['sold_30d'] / 30.0;
            $daysLeft = max(1, (int)round($f['stock'] / $daily));
            $insights[] = [
                'type'    => 'danger',
                'icon'    => 'bi-exclamation-triangle-fill',
                'title'   => 'Low Stock & Selling Fast',
                'message' => "<strong>" . e($f['name']) . "</strong> has only {$f['stock']} left (~{$daysLeft} days of stock remaining). Consider restocking soon.",
                'link'    => admin_url('analytics_product.php?id=' . $f['id']),
            ];
        }
    } catch (Throwable $e) {}

    // 2. High views, low add-to-cart: >= 15 views, add-to-cart rate < 5%
    try {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.name, 
                    SUM(CASE WHEN ev.event_type = 'product_view' THEN 1 ELSE 0 END) AS views,
                    SUM(CASE WHEN ev.event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS cart_adds
             FROM products p
             JOIN analytics_events ev ON ev.product_id = p.id
             WHERE DATE(ev.created_at) BETWEEN :from AND :to
             GROUP BY p.id, p.name
             HAVING views >= 15 AND (cart_adds / views) < 0.05
             LIMIT 2"
        );
        $stmt->execute([':from' => $from, ':to' => $to]);
        $highViews = $stmt->fetchAll();
        foreach ($highViews as $hv) {
            $insights[] = [
                'type'    => 'warning',
                'icon'    => 'bi-eye-fill',
                'title'   => 'High Views, Low Add-to-Cart',
                'message' => "<strong>" . e($hv['name']) . "</strong> has {$hv['views']} views but only {$hv['cart_adds']} cart adds. Check price, sizing guides, or product photos.",
                'link'    => admin_url('analytics_product.php?id=' . $hv['id']),
            ];
        }
    } catch (Throwable $e) {}


    // 4. Coupons usage and impact
    try {
        $stmt = $pdo->prepare(
            "SELECT 
                 coupon_code,
                 COUNT(*) AS uses,
                 SUM(discount_total) AS total_discount,
                 SUM(total) AS total_revenue
             FROM orders
             WHERE DATE(created_at) BETWEEN :from AND :to 
               AND status <> 'cancelled' 
               AND coupon_code IS NOT NULL 
               AND coupon_code <> ''
             GROUP BY coupon_code
             ORDER BY uses DESC
             LIMIT 2"
        );
        $stmt->execute([':from' => $from, ':to' => $to]);
        $coupons = $stmt->fetchAll();
        foreach ($coupons as $c) {
            $insights[] = [
                'type'    => 'success',
                'icon'    => 'bi-tag-fill',
                'title'   => 'Coupon Impact: ' . e($c['coupon_code']),
                'message' => "Used in <strong>{$c['uses']} orders</strong>, driving " . price($c['total_revenue']) . " in revenue with " . price($c['total_discount']) . " in discounts given.",
                'link'    => admin_url('orders.php?status=all'),
            ];
        }
    } catch (Throwable $e) {}

    return $insights;
}
