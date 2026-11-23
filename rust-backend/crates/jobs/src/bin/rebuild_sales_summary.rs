use clap::Parser;
use common::config::Config;
use db::create_pool_with_tz;
use jobs::rebuild_sales_summary::run_rebuild_sales_summary;

#[derive(Parser, Debug)]
#[command(name = "rebuild-sales-summary", about = "Incremental sales summary rebuilder")]
struct Args {
    #[arg(long, help = "Perform a dry-run without modifying database records")]
    dry_run: bool,
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    dotenvy::dotenv().ok();
    tracing_subscriber::fmt::init();

    let args = Args::parse();
    let config = Config::from_env();
    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset).await?;

    let result = run_rebuild_sales_summary(&pool, args.dry_run).await?;
    println!(
        "Successfully recomputed {} summary row(s) for {} products in {:.3}s.",
        result.rows_updated, result.affected_products, result.elapsed_secs
    );

    Ok(())
}
