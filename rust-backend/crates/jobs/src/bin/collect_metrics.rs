use clap::Parser;
use common::config::Config;
use db::create_pool_with_tz;
use jobs::collect_metrics::run_collect_metrics;

#[derive(Parser, Debug)]
#[command(name = "collect-metrics", about = "System metrics & server health collector")]
struct Args {
    #[arg(long, help = "Perform a dry-run without writing to database")]
    dry_run: bool,

    #[arg(short, long, help = "Verbose console output")]
    verbose: bool,
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    dotenvy::dotenv().ok();
    if std::env::var("RUST_LOG").is_err() {
        std::env::set_var("RUST_LOG", "info");
    }
    tracing_subscriber::fmt::init();

    let args = Args::parse();
    let config = Config::from_env();
    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset).await?;

    let result = run_collect_metrics(&pool, args.dry_run, args.verbose).await?;
    if args.verbose {
        println!(
            "Metrics collected in {:.3}s (CPU: {:?}%, Mem Used: {} MB, DB QPS: {}).",
            result.elapsed_secs,
            result.cpu_percent,
            result.mem_used_bytes / 1024 / 1024,
            result.db_qps
        );
    }

    Ok(())
}
