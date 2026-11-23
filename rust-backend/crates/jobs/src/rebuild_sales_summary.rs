use chrono::Utc;
use sqlx::{MySql, Pool};
use tracing::info;

use db::SettingsRepository;

#[derive(Debug, Default, Clone)]
pub struct RebuildSalesSummaryResult {
    pub affected_products: usize,
    pub rows_updated: u64,
    pub elapsed_secs: f64,
}

pub async fn run_rebuild_sales_summary(
    pool: &Pool<MySql>,
    dry_run: bool,
) -> Result<RebuildSalesSummaryResult, String> {
    let start = std::time::Instant::now();
    let settings = SettingsRepository::load(pool)
        .await
        .map_err(|e| format!("Settings load error: {}", e))?;

    let one_day_ago = (Utc::now() - chrono::Duration::days(1)).format("%Y-%m-%d %H:%M:%S").to_string();
    let last_run = settings.get_or("sales_summary_last_run", &one_day_ago);

    info!(
        "Rebuilding sales summary for orders updated since {} (dry_run: {})...",
        last_run, dry_run
    );

    let affected_product_ids: Vec<u64> = sqlx::query_scalar(
        "SELECT DISTINCT oi.product_id
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE (o.updated_at >= ? OR o.created_at >= ?)
           AND oi.product_id IS NOT NULL",
    )
    .bind(&last_run)
    .bind(&last_run)
    .fetch_all(pool)
    .await
    .unwrap_or_default();

    if affected_product_ids.is_empty() {
        info!("No orders modified since last run. Nothing to recompute.");
        if !dry_run {
            let now_str = Utc::now().format("%Y-%m-%d %H:%M:%S").to_string();
            let _ = sqlx::query(
                "INSERT INTO settings (`key`, `value`) VALUES ('sales_summary_last_run', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            )
            .bind(&now_str)
            .execute(pool)
            .await;
        }
        return Ok(RebuildSalesSummaryResult {
            affected_products: 0,
            rows_updated: 0,
            elapsed_secs: start.elapsed().as_secs_f64(),
        });
    }

    let count = affected_product_ids.len();
    info!("Found {} affected product(s). Recomputing summaries...", count);

    let mut total_updated = 0u64;

    for chunk in affected_product_ids.chunks(200) {
        if dry_run {
            info!("  [DRY-RUN] Would recompute chunk of {} products", chunk.len());
            total_updated += chunk.len() as u64;
            continue;
        }

        let mut tx = pool
            .begin()
            .await
            .map_err(|e| format!("Tx begin error: {}", e))?;

        let in_clause = chunk
            .iter()
            .map(|id| id.to_string())
            .collect::<Vec<_>>()
            .join(",");

        let delete_sql = format!(
            "DELETE FROM product_sales_summary WHERE product_id IN ({})",
            in_clause
        );
        let _ = sqlx::query(&delete_sql).execute(&mut *tx).await;

        let insert_sql = format!(
            "INSERT INTO product_sales_summary (product_id, variant_id, variant_label, units, revenue, orders_count, last_sale)
             SELECT 
                 oi.product_id,
                 COALESCE(oi.variant_id, 0) AS variant_id,
                 MAX(oi.variant_label) AS variant_label,
                 COALESCE(SUM(oi.quantity), 0) AS units,
                 COALESCE(SUM(oi.line_total), 0.00) AS revenue,
                 COUNT(DISTINCT oi.order_id) AS orders_count,
                 MAX(o.created_at) AS last_sale
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id AND o.status <> 'cancelled'
             WHERE oi.product_id IN ({})
             GROUP BY oi.product_id, COALESCE(oi.variant_id, 0)",
            in_clause
        );

        let res = sqlx::query(&insert_sql).execute(&mut *tx).await;
        if let Ok(r) = res {
            total_updated += r.rows_affected();
        }

        tx.commit().await.map_err(|e| format!("Tx commit error: {}", e))?;
    }

    if !dry_run {
        let now_str = Utc::now().format("%Y-%m-%d %H:%M:%S").to_string();
        let _ = sqlx::query(
            "INSERT INTO settings (`key`, `value`) VALUES ('sales_summary_last_run', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        )
        .bind(&now_str)
        .execute(pool)
        .await;
    }

    info!(
        "Successfully recomputed {} summary row(s) for {} products.",
        total_updated, count
    );

    Ok(RebuildSalesSummaryResult {
        affected_products: count,
        rows_updated: total_updated,
        elapsed_secs: start.elapsed().as_secs_f64(),
    })
}
