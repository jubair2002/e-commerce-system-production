use clap::Parser;
use jobs::purge_image_cache::run_purge_image_cache;

#[derive(Parser, Debug)]
#[command(name = "purge-image-cache", about = "Purge cached image variants from disk")]
struct Args {
    #[arg(long, help = "Perform a dry-run without deleting files")]
    dry_run: bool,
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    dotenvy::dotenv().ok();
    tracing_subscriber::fmt::init();

    let args = Args::parse();
    let result = run_purge_image_cache(args.dry_run).await?;
    println!(
        "Purged {} cached image variant(s) in {:.3}s.",
        result.purged_files, result.elapsed_secs
    );

    Ok(())
}
