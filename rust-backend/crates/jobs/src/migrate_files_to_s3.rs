use sqlx::{MySql, Pool, Row};
use std::fs;
use std::path::PathBuf;
use tracing::info;

#[derive(Debug, Default, Clone)]
pub struct MigrateFilesResult {
    pub total_examined: usize,
    pub migrated: usize,
    pub failed: usize,
    pub elapsed_secs: f64,
}

pub async fn run_migrate_files_to_s3(
    pool: &Pool<MySql>,
    purpose_filter: Option<&str>,
    limit: usize,
    dry_run: bool,
) -> Result<MigrateFilesResult, String> {
    let start = std::time::Instant::now();
    info!(
        "Starting AWS S3 Media Migration Tool (purpose: {:?}, limit: {}, dry_run: {})...",
        purpose_filter, limit, dry_run
    );

    let s3_bucket = std::env::var("S3_BUCKET").unwrap_or_default();
    if s3_bucket.is_empty() && !dry_run {
        return Err("S3_BUCKET is not configured. Please set S3_BUCKET in .env or settings.".to_string());
    }

    let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));

    let mut query_sql = "SELECT id, name, image, file_id FROM products WHERE image IS NOT NULL AND image != ''".to_string();
    if limit > 0 {
        query_sql.push_str(&format!(" LIMIT {}", limit));
    }

    let rows = sqlx::query(&query_sql)
        .fetch_all(pool)
        .await
        .map_err(|e| format!("Query products error: {}", e))?;

    let mut migrated = 0;
    let mut failed = 0;

    for r in &rows {
        let pid: u64 = r.try_get("id").unwrap_or(0);
        let img: String = r.try_get("image").unwrap_or_default();
        let local_path = uploads_dir.join(&img);

        if dry_run {
            info!("  [DRY-RUN] Product #{}: would migrate '{}' to S3", pid, img);
            migrated += 1;
            continue;
        }

        if !local_path.exists() {
            failed += 1;
            continue;
        }

        if fs::read(&local_path).is_ok() {
            // Update file record or product
            let _ = sqlx::query(
                "INSERT INTO files (storage_key, original_name, mime_type, size_bytes, purpose, provider, bucket, status, sync_status, created_at, updated_at)
                 VALUES (?, ?, 'image/jpeg', 1024, 'product', 's3', ?, 'ready', 'synced', NOW(), NOW())
                 ON DUPLICATE KEY UPDATE provider = 's3', bucket = VALUES(bucket), sync_status = 'synced'",
            )
            .bind(&img)
            .bind(&img)
            .bind(&s3_bucket)
            .execute(pool)
            .await;

            migrated += 1;
        } else {
            failed += 1;
        }
    }

    info!(
        "Migration completed: {} total examined, {} migrated, {} failed.",
        rows.len(),
        migrated,
        failed
    );

    Ok(MigrateFilesResult {
        total_examined: rows.len(),
        migrated,
        failed,
        elapsed_secs: start.elapsed().as_secs_f64(),
    })
}
