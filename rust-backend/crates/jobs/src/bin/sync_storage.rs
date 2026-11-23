use clap::Parser;
use common::config::Config;
use db::create_pool_with_tz;
use jobs::sync_storage::run_sync_storage;

#[derive(Parser, Debug)]
#[command(name = "sync-storage", about = "Asynchronous storage sync: copies local fallback files to S3")]
struct Args {
    #[arg(long, default_value = "50", help = "Max number of files to sync per run")]
    limit: usize,

    #[arg(long, help = "Sync all ready local files to S3")]
    all: bool,

    #[arg(long, help = "Perform a dry-run without uploading files or updating sync_status")]
    dry_run: bool,
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    dotenvy::dotenv().ok();
    tracing_subscriber::fmt::init();

    let args = Args::parse();
    let config = Config::from_env();
    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset).await?;

    let result = run_sync_storage(&pool, args.limit, args.all, args.dry_run).await?;
    println!(
        "Storage sync finished: {} synced, {} failed in {:.3}s.",
        result.synced, result.failed, result.elapsed_secs
    );

    if result.failed > 0 {
        std::process::exit(1);
    }
    Ok(())
}
