use clap::Parser;
use common::config::Config;
use db::create_pool_with_tz;
use jobs::customer_maintenance::run_customer_maintenance;

#[derive(Parser, Debug)]
#[command(name = "customer-maintenance", about = "Daily customer tokens and registration maintenance")]
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

    let result = run_customer_maintenance(&pool, args.dry_run).await?;
    println!(
        "Customer Maintenance completed in {:.3}s (tokens purged: {}, auth purged: {}, unverified purged: {}).",
        result.elapsed_secs, result.tokens_purged, result.auth_purged, result.unverified_purged
    );

    Ok(())
}
