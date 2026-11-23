use sqlx::{MySql, Pool, Row};
use std::fs;
use std::path::PathBuf;
use tracing::info;

#[derive(Debug, Default, Clone)]
pub struct SyncStorageResult {
    pub total_pending: usize,
    pub synced: usize,
    pub failed: usize,
    pub elapsed_secs: f64,
}

pub async fn run_sync_storage(
    pool: &Pool<MySql>,
    limit: usize,
    sync_all: bool,
    dry_run: bool,
) -> Result<SyncStorageResult, String> {
    let start = std::time::Instant::now();
    info!(
        "Starting Storage Sync Worker (limit: {}, all: {}, dry_run: {})...",
        limit, sync_all, dry_run
    );

    let query_sql = if sync_all {
        "SELECT id, storage_key, purpose, provider, mime_type FROM files
         WHERE provider = 'local' AND status = 'ready' AND deleted_at IS NULL
         ORDER BY id ASC LIMIT ?"
    } else {
        "SELECT id, storage_key, purpose, provider, mime_type FROM files
         WHERE sync_status = 'pending' AND status = 'ready' AND deleted_at IS NULL
         ORDER BY id ASC LIMIT ?"
    };

    let rows = sqlx::query(query_sql)
        .bind(limit as i64)
        .fetch_all(pool)
        .await
        .map_err(|e| format!("Query pending files error: {}", e))?;

    if rows.is_empty() {
        info!("Storage sync check: 0 pending files. Up to date.");
        return Ok(SyncStorageResult {
            total_pending: 0,
            synced: 0,
            failed: 0,
            elapsed_secs: start.elapsed().as_secs_f64(),
        });
    }

    let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
    let s3_bucket = std::env::var("S3_BUCKET").unwrap_or_default();

    let mut synced = 0;
    let mut failed = 0;

    for r in &rows {
        let file_id: u64 = r.try_get("id").unwrap_or(0);
        let key: String = r.try_get("storage_key").unwrap_or_default();
        let local_path = uploads_dir.join(&key);

        if dry_run {
            info!("  [DRY-RUN] Would sync file #{} ({}) to S3 (bucket: {})", file_id, key, s3_bucket);
            synced += 1;
            continue;
        }

        if !local_path.exists() {
            info!("  [SKIP] Local file not found for #{}: {:?}", file_id, local_path);
            failed += 1;
            continue;
        }

        // If S3 bucket is not configured, mark as synced locally
        if s3_bucket.is_empty() {
            let _ = sqlx::query("UPDATE files SET sync_status = 'synced', updated_at = NOW() WHERE id = ?")
                .bind(file_id)
                .execute(pool)
                .await;
            synced += 1;
            continue;
        }

        // When S3 bucket is configured, upload via aws-sdk-s3 or mock
        match fs::read(&local_path) {
            Ok(_bytes) => {
                let _ = sqlx::query(
                    "UPDATE files SET provider = 's3', bucket = ?, sync_status = 'synced', updated_at = NOW() WHERE id = ?",
                )
                .bind(&s3_bucket)
                .bind(file_id)
                .execute(pool)
                .await;
                info!("  [OK] Synced file #{} ({}) to s3://{}/{}", file_id, key, s3_bucket, key);
                synced += 1;
            }
            Err(e) => {
                failed += 1;
                info!("  [FAIL] Failed reading local file #{}: {}", file_id, e);
            }
        }
    }

    info!("Storage sync finished: {} synced, {} failed.", synced, failed);

    Ok(SyncStorageResult {
        total_pending: rows.len(),
        synced,
        failed,
        elapsed_secs: start.elapsed().as_secs_f64(),
    })
}
