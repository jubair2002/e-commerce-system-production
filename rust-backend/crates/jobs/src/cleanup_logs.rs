use chrono::{Duration, Utc};
use sqlx::{MySql, Pool};
use tracing::info;

use db::SettingsRepository;

#[derive(Debug, Default, Clone)]
pub struct CleanupLogsResult {
    pub total_deleted: u64,
    pub elapsed_secs: f64,
}

async fn batch_delete(
    pool: &Pool<MySql>,
    table: &str,
    where_clause: &str,
    threshold: &str,
    batch_size: u64,
    dry_run: bool,
) -> Result<u64, sqlx::Error> {
    if dry_run {
        let count_sql = format!("SELECT COUNT(*) FROM `{}` WHERE {}", table, where_clause);
        let count: i64 = sqlx::query_scalar(&count_sql)
            .bind(threshold)
            .fetch_one(pool)
            .await
            .unwrap_or(0);
        return Ok(count as u64);
    }

    let mut total = 0u64;
    let delete_sql = format!(
        "DELETE FROM `{}` WHERE {} LIMIT {}",
        table, where_clause, batch_size
    );

    loop {
        let res = sqlx::query(&delete_sql).bind(threshold).execute(pool).await?;
        let deleted = res.rows_affected();
        total += deleted;

        if deleted < batch_size {
            break;
        }

        tokio::time::sleep(tokio::time::Duration::from_millis(20)).await;
    }

    Ok(total)
}

pub async fn run_cleanup_logs(
    pool: &Pool<MySql>,
    dry_run: bool,
) -> Result<CleanupLogsResult, String> {
    let start = std::time::Instant::now();
    info!("Starting logs & metrics retention cleanup (dry_run: {})...", dry_run);

    let settings = SettingsRepository::load(pool)
        .await
        .map_err(|e| format!("Settings load error: {}", e))?;

    let monitor_retention_days: i64 = settings
        .get_or("monitor_retention_days", "30")
        .parse::<i64>()
        .unwrap_or(30)
        .max(7);

    let analytics_retention_days: i64 = settings
        .get_or("analytics_retention_days", "180")
        .parse::<i64>()
        .unwrap_or(180)
        .max(30);

    let now = Utc::now();
    let threshold_monitor = (now - Duration::days(monitor_retention_days)).format("%Y-%m-%d %H:%M:%S").to_string();
    let threshold_analytics = (now - Duration::days(analytics_retention_days)).format("%Y-%m-%d %H:%M:%S").to_string();
    let threshold_auth = (now - Duration::days(30)).format("%Y-%m-%d %H:%M:%S").to_string();
    let threshold_search = (now - Duration::days(90)).format("%Y-%m-%d %H:%M:%S").to_string();
    let threshold_mail = (now - Duration::days(30)).format("%Y-%m-%d %H:%M:%S").to_string();

    let mut total_deleted = 0u64;

    // 1. activity_log
    let del = batch_delete(pool, "activity_log", "created_at < ?", &threshold_monitor, 1000, dry_run)
        .await
        .unwrap_or(0);
    info!("  activity_log: deleted {} row(s)", del);
    total_deleted += del;

    // 2. system_metrics
    let del = batch_delete(pool, "system_metrics", "collected_at < ?", &threshold_monitor, 1000, dry_run)
        .await
        .unwrap_or(0);
    info!("  system_metrics: deleted {} row(s)", del);
    total_deleted += del;

    // 3. network_samples
    let del = batch_delete(pool, "network_samples", "collected_at < ?", &threshold_monitor, 1000, dry_run)
        .await
        .unwrap_or(0);
    info!("  network_samples: deleted {} row(s)", del);
    total_deleted += del;

    // 4. slow_requests
    let del = batch_delete(pool, "slow_requests", "created_at < ?", &threshold_monitor, 1000, dry_run)
        .await
        .unwrap_or(0);
    info!("  slow_requests: deleted {} row(s)", del);
    total_deleted += del;

    // 5. traffic_minute
    let del = batch_delete(pool, "traffic_minute", "bucket < ?", &threshold_monitor, 1000, dry_run)
        .await
        .unwrap_or(0);
    info!("  traffic_minute: deleted {} row(s)", del);
    total_deleted += del;

    // 6. analytics_events
    let del = batch_delete(pool, "analytics_events", "created_at < ?", &threshold_analytics, 1000, dry_run)
        .await
        .unwrap_or(0);
    info!("  analytics_events: deleted {} row(s)", del);
    total_deleted += del;

    // 7. auth_attempts
    let del = batch_delete(pool, "auth_attempts", "created_at < ?", &threshold_auth, 1000, dry_run)
        .await
        .unwrap_or(0);
    info!("  auth_attempts: deleted {} row(s)", del);
    total_deleted += del;

    // 8. search_log
    let del = batch_delete(pool, "search_log", "created_at < ?", &threshold_search, 1000, dry_run)
        .await
        .unwrap_or(0);
    info!("  search_log: deleted {} row(s)", del);
    total_deleted += del;

    // 9. email_queue (sent)
    let del = batch_delete(pool, "email_queue", "status = 'sent' AND sent_at < ?", &threshold_mail, 1000, dry_run)
        .await
        .unwrap_or(0);
    info!("  email_queue (sent): deleted {} row(s)", del);
    total_deleted += del;

    info!(
        "Cleanup finished successfully: {} total rows purged.",
        total_deleted
    );

    Ok(CleanupLogsResult {
        total_deleted,
        elapsed_secs: start.elapsed().as_secs_f64(),
    })
}
