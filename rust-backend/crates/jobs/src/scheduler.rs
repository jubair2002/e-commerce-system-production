use sqlx::{MySql, Pool};
use std::time::Duration;
use tokio::time::interval;
use tracing::{error, info};

use crate::aggregate_analytics::run_aggregate_analytics;
use crate::cleanup_logs::run_cleanup_logs;
use crate::collect_metrics::run_collect_metrics;
use crate::customer_maintenance::run_customer_maintenance;
use crate::mail_worker::run_mail_worker;
use crate::rebuild_sales_summary::run_rebuild_sales_summary;
use crate::sync_storage::run_sync_storage;

/// Start an in-process background scheduler running periodic jobs
pub async fn start_in_process_scheduler(pool: Pool<MySql>) {
    info!("Starting in-process background job scheduler...");

    // 1. Mail Worker (Every 60s)
    let pool_mail = pool.clone();
    tokio::spawn(async move {
        let mut ticker = interval(Duration::from_secs(60));
        loop {
            ticker.tick().await;
            if let Err(e) = run_mail_worker(&pool_mail, false, 50).await {
                error!("Scheduler mail-worker error: {}", e);
            }
        }
    });

    // 2. Metrics Collector (Every 60s)
    let pool_metrics = pool.clone();
    tokio::spawn(async move {
        let mut ticker = interval(Duration::from_secs(60));
        loop {
            ticker.tick().await;
            if let Err(e) = run_collect_metrics(&pool_metrics, false, false).await {
                error!("Scheduler collect-metrics error: {}", e);
            }
        }
    });

    // 3. Storage Sync (Every 5 minutes = 300s)
    let pool_sync = pool.clone();
    tokio::spawn(async move {
        let mut ticker = interval(Duration::from_secs(300));
        loop {
            ticker.tick().await;
            if let Err(e) = run_sync_storage(&pool_sync, 50, false, false).await {
                error!("Scheduler sync-storage error: {}", e);
            }
        }
    });

    // 4. Daily Maintenance (Every 1 hour check for maintenance run)
    let pool_daily = pool.clone();
    tokio::spawn(async move {
        let mut ticker = interval(Duration::from_secs(3600));
        loop {
            ticker.tick().await;
            let hour = chrono::Utc::now().format("%H").to_string();
            if hour == "00" {
                let _ = run_customer_maintenance(&pool_daily, false).await;
                let _ = run_aggregate_analytics(&pool_daily, None, false).await;
            } else if hour == "03" {
                let _ = run_cleanup_logs(&pool_daily, false).await;
                let _ = run_rebuild_sales_summary(&pool_daily, false).await;
            }
        }
    });

    info!("In-process background job scheduler running.");
}
