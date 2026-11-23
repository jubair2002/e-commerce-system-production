use clap::Parser;
use common::config::Config;
use db::create_pool_with_tz;
use jobs::mail_worker::run_mail_worker;

#[derive(Parser, Debug)]
#[command(name = "mail-worker", about = "Transactional mail queue processor")]
struct Args {
    #[arg(long, help = "Perform a dry-run without dispatching emails or modifying records")]
    dry_run: bool,

    #[arg(long, default_value = "50", help = "Batch size to claim per run")]
    limit: usize,
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    dotenvy::dotenv().ok();
    tracing_subscriber::fmt::init();

    let args = Args::parse();
    let config = Config::from_env();
    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset).await?;

    let result = run_mail_worker(&pool, args.dry_run, args.limit).await?;
    println!(
        "Processed: {}, Sent: {}, Failed: {} in {:.3}s.",
        result.claimed, result.sent, result.failed, result.elapsed_secs
    );

    if result.failed > 0 {
        std::process::exit(1);
    }
    Ok(())
}
