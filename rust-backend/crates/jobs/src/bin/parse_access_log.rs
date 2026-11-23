use clap::Parser;
use common::config::Config;
use db::create_pool_with_tz;
use jobs::parse_access_log::run_parse_access_log;

#[derive(Parser, Debug)]
#[command(name = "parse-access-log", about = "Parse web server access log into traffic_minute")]
struct Args {
    #[arg(long, help = "Path to custom access log file")]
    path: Option<String>,

    #[arg(long, help = "Perform a dry-run without writing to database")]
    dry_run: bool,
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    dotenvy::dotenv().ok();
    tracing_subscriber::fmt::init();

    let args = Args::parse();
    let config = Config::from_env();
    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset).await?;

    let result = run_parse_access_log(&pool, args.path.as_deref(), args.dry_run).await?;
    println!(
        "Access log parsing complete: {:?} (updated {} minute buckets in {:.3}s).",
        result.log_file, result.buckets_updated, result.elapsed_secs
    );

    Ok(())
}
