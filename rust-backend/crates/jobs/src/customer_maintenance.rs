use sqlx::{MySql, Pool, Row};
use std::path::PathBuf;
use tracing::info;

use db::queries::storage::StorageManager;
use db::SettingsRepository;

#[derive(Debug, Default, Clone)]
pub struct CustomerMaintenanceResult {
    pub tokens_purged: u64,
    pub auth_purged: u64,
    pub unverified_purged: u64,
    pub elapsed_secs: f64,
}

pub async fn run_customer_maintenance(
    pool: &Pool<MySql>,
    dry_run: bool,
) -> Result<CustomerMaintenanceResult, String> {
    let start = std::time::Instant::now();
    info!("Starting Customer Maintenance Cron (dry_run: {})...", dry_run);

    let settings = SettingsRepository::load(pool)
        .await
        .map_err(|e| format!("Settings load error: {}", e))?;

    // 1. Purge expired customer tokens older than 7 days
    let tokens_purged = if dry_run {
        sqlx::query_scalar::<_, i64>(
            "SELECT COUNT(*) FROM customer_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
        )
        .fetch_one(pool)
        .await
        .unwrap_or(0) as u64
    } else {
        let res = sqlx::query(
            "DELETE FROM customer_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
        )
        .execute(pool)
        .await
        .map_err(|e| format!("Token purge error: {}", e))?;
        res.rows_affected()
    };
    info!("  1. Purged {} expired token(s) older than 7 days.", tokens_purged);

    // 2. Purge auth_attempts records older than 30 days
    let auth_purged = if dry_run {
        sqlx::query_scalar::<_, i64>(
            "SELECT COUNT(*) FROM auth_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
        )
        .fetch_one(pool)
        .await
        .unwrap_or(0) as u64
    } else {
        let res = sqlx::query(
            "DELETE FROM auth_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
        )
        .execute(pool)
        .await
        .map_err(|e| format!("Auth attempts purge error: {}", e))?;
        res.rows_affected()
    };
    info!("  2. Purged {} auth attempt log(s) older than 30 days.", auth_purged);

    // 3. Purge abandoned pending/unverified registrations
    let ttl_days: i32 = settings
        .get_or("customer_unverified_ttl_days", "14")
        .parse()
        .unwrap_or(14);

    let mut unverified_purged = 0u64;

    if ttl_days > 0 {
        let rows = sqlx::query(
            "SELECT id, avatar, avatar_file_id FROM customers
             WHERE status = 'pending'
               AND email_verified_at IS NULL
               AND deleted_at IS NULL
               AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
        )
        .bind(ttl_days)
        .fetch_all(pool)
        .await
        .map_err(|e| format!("Fetch abandoned error: {}", e))?;

        let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
        let storage = StorageManager::new(uploads_dir);

        for r in rows {
            let cid: u64 = r.try_get("id").unwrap_or(0);
            let avatar_file_id: Option<u64> = r.try_get("avatar_file_id").ok();

            if dry_run {
                info!("  [DRY-RUN] Would purge abandoned customer #{}", cid);
                unverified_purged += 1;
                continue;
            }

            if let Some(fid) = avatar_file_id {
                let _ = storage.delete_file(pool, fid).await;
            }

            let _ = sqlx::query("DELETE FROM customer_tokens WHERE customer_id = ?")
                .bind(cid)
                .execute(pool)
                .await;
            let _ = sqlx::query("DELETE FROM customer_addresses WHERE customer_id = ?")
                .bind(cid)
                .execute(pool)
                .await;
            let _ = sqlx::query("DELETE FROM wishlists WHERE customer_id = ?")
                .bind(cid)
                .execute(pool)
                .await;
            let _ = sqlx::query("UPDATE orders SET customer_id = NULL WHERE customer_id = ?")
                .bind(cid)
                .execute(pool)
                .await;
            let _ = sqlx::query("DELETE FROM customers WHERE id = ?")
                .bind(cid)
                .execute(pool)
                .await;

            unverified_purged += 1;
        }

        info!(
            "  3. Purged {} abandoned unverified registration(s) older than {} days.",
            unverified_purged, ttl_days
        );
    } else {
        info!("  3. Unverified registration cleanup is disabled (customer_unverified_ttl_days = 0).");
    }

    if !dry_run {
        let msg = format!(
            "Maintenance cron ran: {} tokens purged, {} auth logs purged, {} abandoned registrations purged",
            tokens_purged, auth_purged, unverified_purged
        );
        let _ = sqlx::query(
            "INSERT INTO activity_log (user_id, action, description, created_at) VALUES (NULL, 'customer.cron_maintenance', ?, NOW())",
        )
        .bind(&msg)
        .execute(pool)
        .await;
    }

    Ok(CustomerMaintenanceResult {
        tokens_purged,
        auth_purged,
        unverified_purged,
        elapsed_secs: start.elapsed().as_secs_f64(),
    })
}
