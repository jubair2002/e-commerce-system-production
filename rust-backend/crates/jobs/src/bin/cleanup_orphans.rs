use clap::Parser;
use common::config::Config;
use db::create_pool_with_tz;
use jobs::cleanup_orphans::run_cleanup_orphans;

#[derive(Parser, Debug)]
#[command(name = "cleanup-orphans", about = "Purge expired soft-deleted files and clean unreferenced media")]
struct Args {
    #[arg(long, default_value = "30", help = "Retention period for soft-deleted files in days")]
    days: u32,

    #[arg(long, help = "Perform a dry-run without modifying files or records")]
    dry_run: bool,
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    dotenvy::dotenv().ok();
    tracing_subscriber::fmt::init();

    let args = Args::parse();
    let config = Config::from_env();
    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset).await?;

    let result = run_cleanup_orphans(&pool, args.days, args.dry_run).await?;
    println!(
        "Cleanup complete: {} purged, {} orphaned files soft-deleted in {:.3}s.",
        result.purged_files, result.marked_orphans, result.elapsed_secs
    );

    Ok(())
}
