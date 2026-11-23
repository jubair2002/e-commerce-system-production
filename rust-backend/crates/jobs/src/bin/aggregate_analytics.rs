use clap::Parser;
use common::config::Config;
use db::create_pool_with_tz;
use jobs::aggregate_analytics::run_aggregate_analytics;

#[derive(Parser, Debug)]
#[command(name = "aggregate-analytics", about = "Nightly analytics roll-up for products and site stats")]
struct Args {
    #[arg(long, help = "Target aggregation date in YYYY-MM-DD format (defaults to yesterday)")]
    date: Option<String>,

    #[arg(long, help = "Perform a dry-run without writing aggregations")]
    dry_run: bool,
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    dotenvy::dotenv().ok();
    tracing_subscriber::fmt::init();

    let args = Args::parse();
    let config = Config::from_env();
    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset).await?;

    let result = run_aggregate_analytics(&pool, args.date.as_deref(), args.dry_run).await?;
    println!(
        "Analytics aggregation for {} finished: {} product rows, {} site rows in {:.3}s.",
        result.target_date, result.product_stats_rows, result.site_stats_rows, result.elapsed_secs
    );

    Ok(())
}
