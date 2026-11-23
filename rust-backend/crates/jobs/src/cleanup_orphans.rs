use sqlx::{MySql, Pool, Row};
use std::path::PathBuf;
use tracing::info;

use db::queries::storage::StorageManager;

#[derive(Debug, Default, Clone)]
pub struct CleanupOrphansResult {
    pub purged_files: u64,
    pub marked_orphans: u64,
    pub elapsed_secs: f64,
}

pub async fn run_cleanup_orphans(
    pool: &Pool<MySql>,
    retention_days: u32,
    dry_run: bool,
) -> Result<CleanupOrphansResult, String> {
    let start = std::time::Instant::now();
    info!(
        "Starting Storage Orphan & Soft-Delete Cleanup Worker (retention: {} days, dry_run: {})...",
        retention_days, dry_run
    );

    let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
    let storage = StorageManager::new(uploads_dir);

    // 1. Purge files soft-deleted older than retention period
    let expired_files = sqlx::query(
        "SELECT id, storage_key, purpose, provider FROM files
         WHERE deleted_at IS NOT NULL
           AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
    )
    .bind(retention_days)
    .fetch_all(pool)
    .await
    .map_err(|e| format!("Query expired files error: {}", e))?;

    info!(
        "Found {} expired soft-deleted file(s) to purge from storage.",
        expired_files.len()
    );

    let mut purged_count = 0u64;

    for file in expired_files {
        let file_id: u64 = file.try_get("id").unwrap_or(0);
        let key: String = file.try_get("storage_key").unwrap_or_default();
        let provider: String = file.try_get("provider").unwrap_or_else(|_| "local".to_string());

        if dry_run {
            info!("  [DRY-RUN] Would purge #{} ({}://{})", file_id, provider, key);
            purged_count += 1;
            continue;
        }

        let _ = storage.delete_file(pool, file_id).await;
        // Permanently remove record
        let _ = sqlx::query("DELETE FROM files WHERE id = ?").bind(file_id).execute(pool).await;
        info!("  [PURGED] File #{} ({}) removed from storage and database.", file_id, key);
        purged_count += 1;
    }

    // 2. Identify unreferenced orphan files
    info!("Scanning for orphaned unreferenced media files older than 7 days...");
    let orphan_rows = sqlx::query(
        "SELECT f.id, f.storage_key, f.purpose
         FROM files f
         WHERE f.status != 'deleted'
           AND f.deleted_at IS NULL
           AND f.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
           AND NOT EXISTS (SELECT 1 FROM products p WHERE p.file_id = f.id)
           AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.file_id = f.id)
           AND NOT EXISTS (SELECT 1 FROM product_variants pv WHERE pv.file_id = f.id)
           AND NOT EXISTS (SELECT 1 FROM customers c WHERE c.avatar_file_id = f.id)
           AND NOT EXISTS (SELECT 1 FROM users u WHERE u.avatar_file_id = f.id)
           AND NOT EXISTS (SELECT 1 FROM storefront_banners b WHERE b.file_id = f.id)
           AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.file_id = f.id)",
    )
    .fetch_all(pool)
    .await
    .map_err(|e| format!("Query orphans error: {}", e))?;

    info!("Found {} orphaned file(s).", orphan_rows.len());

    let mut marked_count = 0u64;

    for orphan in orphan_rows {
        let oid: u64 = orphan.try_get("id").unwrap_or(0);
        let key: String = orphan.try_get("storage_key").unwrap_or_default();

        if dry_run {
            info!("  [DRY-RUN] Would mark orphan file #{} ({}) as deleted.", oid, key);
            marked_count += 1;
        } else {
            let _ = sqlx::query(
                "UPDATE files SET status = 'deleted', deleted_at = NOW() WHERE id = ?",
            )
            .bind(oid)
            .execute(pool)
            .await;
            info!("  [ORPHAN] Marked file #{} ({}) as deleted.", oid, key);
            marked_count += 1;
        }
    }

    info!(
        "Cleanup complete: {} purged, {} orphaned files soft-deleted.",
        purged_count, marked_count
    );

    Ok(CleanupOrphansResult {
        purged_files: purged_count,
        marked_orphans: marked_count,
        elapsed_secs: start.elapsed().as_secs_f64(),
    })
}
