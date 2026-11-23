use clap::Parser;
use common::config::Config;
use db::create_pool_with_tz;
use jobs::migrate_files_to_s3::run_migrate_files_to_s3;

#[derive(Parser, Debug)]
#[command(name = "migrate-files-to-s3", about = "Idempotent file migration tool: local disk -> AWS S3")]
struct Args {
    #[arg(long, help = "Optional purpose filter: product, avatar, banner, branding")]
    purpose: Option<String>,

    #[arg(long, default_value = "0", help = "Max number of files to migrate (0 = all)")]
    limit: usize,

    #[arg(long, help = "Perform a dry-run without uploading files or modifying records")]
    dry_run: bool,
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    dotenvy::dotenv().ok();
    tracing_subscriber::fmt::init();

    let args = Args::parse();
    let config = Config::from_env();
    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset).await?;

    let result = run_migrate_files_to_s3(&pool, args.purpose.as_deref(), args.limit, args.dry_run).await?;
    println!(
        "Migration completed: {} total examined, {} migrated, {} failed in {:.3}s.",
        result.total_examined, result.migrated, result.failed, result.elapsed_secs
    );

    if result.failed > 0 {
        std::process::exit(1);
    }
    Ok(())
}
