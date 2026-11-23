use clap::Parser;
use common::config::Config;
use db::create_pool_with_tz;
use jobs::cleanup_logs::run_cleanup_logs;

#[derive(Parser, Debug)]
#[command(name = "cleanup-logs", about = "Logs and expired transient records retention cleanup")]
struct Args {
    #[arg(long, help = "Perform a dry-run without deleting records")]
    dry_run: bool,
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    dotenvy::dotenv().ok();
    tracing_subscriber::fmt::init();

    let args = Args::parse();
    let config = Config::from_env();
    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset).await?;

    let result = run_cleanup_logs(&pool, args.dry_run).await?;
    println!(
        "Cleanup finished successfully: {} row(s) deleted in {:.3}s.",
        result.total_deleted, result.elapsed_secs
    );

    Ok(())
}
