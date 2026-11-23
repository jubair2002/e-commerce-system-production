use std::fs;
use std::path::PathBuf;
use tracing::info;

#[derive(Debug, Default, Clone)]
pub struct PurgeImageCacheResult {
    pub purged_files: u64,
    pub elapsed_secs: f64,
}

pub async fn run_purge_image_cache(dry_run: bool) -> Result<PurgeImageCacheResult, String> {
    let start = std::time::Instant::now();
    info!("Starting image variant cache purge (dry_run: {})...", dry_run);

    let cache_dirs = [
        PathBuf::from("assets/uploads/cache"),
        PathBuf::from("storage/cache"),
        PathBuf::from("../../assets/uploads/cache"),
    ];

    let mut purged = 0u64;

    for dir in &cache_dirs {
        if dir.is_dir() {
            if let Ok(entries) = fs::read_dir(dir) {
                for entry in entries.flatten() {
                    let path = entry.path();
                    if path.is_file() {
                        if dry_run {
                            info!("  [DRY-RUN] Would remove cached image: {:?}", path);
                            purged += 1;
                        } else if fs::remove_file(&path).is_ok() {
                            purged += 1;
                        }
                    }
                }
            }
        }
    }

    info!("Purged {} cached image variant(s).", purged);

    Ok(PurgeImageCacheResult {
        purged_files: purged,
        elapsed_secs: start.elapsed().as_secs_f64(),
    })
}
