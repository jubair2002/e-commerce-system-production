use chrono::{Duration, Utc};
use sqlx::{MySql, Pool};
use tracing::info;

#[derive(Debug, Default, Clone)]
pub struct AggregateAnalyticsResult {
    pub target_date: String,
    pub product_stats_rows: u64,
    pub site_stats_rows: u64,
    pub elapsed_secs: f64,
}

pub async fn run_aggregate_analytics(
    pool: &Pool<MySql>,
    target_date_opt: Option<&str>,
    dry_run: bool,
) -> Result<AggregateAnalyticsResult, String> {
    let start = std::time::Instant::now();
    let yesterday = (Utc::now() - Duration::days(1)).format("%Y-%m-%d").to_string();
    let target_date = target_date_opt.unwrap_or(&yesterday);

    info!(
        "Starting Nightly Analytics Aggregation for date: {} (dry_run: {})...",
        target_date, dry_run
    );

    let mut product_stats_rows = 0u64;
    let mut site_stats_rows = 0u64;

    if dry_run {
        info!("  [DRY-RUN] Would aggregate product_stats_daily and site_stats_daily for {}", target_date);
    } else {
        // 1. Roll-up product_stats_daily
        let prod_sql = r#"
            INSERT INTO product_stats_daily (stat_date, product_id, views, unique_views, add_to_cart, orders, units_sold, revenue)
            SELECT 
                ? AS stat_date,
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
                WHERE DATE(created_at) = ? AND product_id IS NOT NULL
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
                WHERE DATE(o.created_at) = ? AND o.status <> 'cancelled'
                GROUP BY oi.product_id
            ) ord ON ord.product_id = p.id
            WHERE COALESCE(ev.views, 0) > 0 OR COALESCE(ord.units_sold, 0) > 0
            ON DUPLICATE KEY UPDATE
                views        = VALUES(views),
                unique_views = VALUES(unique_views),
                add_to_cart  = VALUES(add_to_cart),
                orders       = VALUES(orders),
                units_sold   = VALUES(units_sold),
                revenue      = VALUES(revenue)
        "#;

        let res_prod = sqlx::query(prod_sql)
            .bind(target_date)
            .bind(target_date)
            .bind(target_date)
            .execute(pool)
            .await;

        if let Ok(r) = res_prod {
            product_stats_rows = r.rows_affected();
            info!("  product_stats_daily: updated {} row(s)", product_stats_rows);
        }

        // 2. Roll-up site_stats_daily
        let site_sql = r#"
            INSERT INTO site_stats_daily (stat_date, visitors, product_views, add_to_cart, checkout_starts, orders, cancelled_orders, revenue, avg_order_value)
            SELECT 
                ? AS stat_date,
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
                WHERE DATE(created_at) = ?
            ) ev ON 1 = 1
            LEFT JOIN (
                SELECT 
                    COUNT(DISTINCT o.id) AS orders_count,
                    COUNT(DISTINCT CASE WHEN o.status = 'cancelled' THEN o.id END) AS cancelled_orders,
                    SUM(CASE WHEN o.status <> 'cancelled' THEN o.total ELSE 0.00 END) AS revenue
                FROM orders o
                WHERE DATE(o.created_at) = ?
            ) ord ON 1 = 1
            ON DUPLICATE KEY UPDATE
                visitors         = VALUES(visitors),
                product_views    = VALUES(product_views),
                add_to_cart      = VALUES(add_to_cart),
                checkout_starts  = VALUES(checkout_starts),
                orders           = VALUES(orders),
                cancelled_orders = VALUES(cancelled_orders),
                revenue          = VALUES(revenue),
                avg_order_value  = VALUES(avg_order_value)
        "#;

        let res_site = sqlx::query(site_sql)
            .bind(target_date)
            .bind(target_date)
            .bind(target_date)
            .execute(pool)
            .await;

        if let Ok(r) = res_site {
            site_stats_rows = r.rows_affected();
            info!("  site_stats_daily: updated {} row(s)", site_stats_rows);
        }
    }

    Ok(AggregateAnalyticsResult {
        target_date: target_date.to_string(),
        product_stats_rows,
        site_stats_rows,
        elapsed_secs: start.elapsed().as_secs_f64(),
    })
}
