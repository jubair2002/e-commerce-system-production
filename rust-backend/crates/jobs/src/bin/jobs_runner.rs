use clap::{Parser, Subcommand};
use common::config::Config;
use db::create_pool_with_tz;

#[derive(Parser, Debug)]
#[command(name = "jobs-runner", about = "Unified background worker and in-process scheduler")]
struct Cli {
    #[arg(long, help = "Start the long-running in-process scheduler daemon")]
    scheduler: bool,

    #[command(subcommand)]
    command: Option<Commands>,
}

#[derive(Subcommand, Debug)]
enum Commands {
    MailWorker {
        #[arg(long)]
        dry_run: bool,
        #[arg(long, default_value = "50")]
        limit: usize,
    },
    CustomerMaintenance {
        #[arg(long)]
        dry_run: bool,
    },
    CollectMetrics {
        #[arg(long)]
        dry_run: bool,
        #[arg(short, long)]
        verbose: bool,
    },
    RebuildSalesSummary {
        #[arg(long)]
        dry_run: bool,
    },
    CleanupLogs {
        #[arg(long)]
        dry_run: bool,
    },
    AggregateAnalytics {
        #[arg(long)]
        date: Option<String>,
        #[arg(long)]
        dry_run: bool,
    },
    ParseAccessLog {
        #[arg(long)]
        path: Option<String>,
        #[arg(long)]
        dry_run: bool,
    },
    PurgeImageCache {
        #[arg(long)]
        dry_run: bool,
    },
    CleanupOrphans {
        #[arg(long, default_value = "30")]
        days: u32,
        #[arg(long)]
        dry_run: bool,
    },
    SyncStorage {
        #[arg(long, default_value = "50")]
        limit: usize,
        #[arg(long)]
        all: bool,
        #[arg(long)]
        dry_run: bool,
    },
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    dotenvy::dotenv().ok();
    if std::env::var("RUST_LOG").is_err() {
        std::env::set_var("RUST_LOG", "info");
    }
    tracing_subscriber::fmt::init();

    let cli = Cli::parse();
    let config = Config::from_env();
    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset).await?;

    if cli.scheduler {
        jobs::scheduler::start_in_process_scheduler(pool).await;
        // Keep running until signal
        tokio::signal::ctrl_c().await?;
        println!("Shutting down background job scheduler.");
        return Ok(());
    }

    match cli.command {
        Some(Commands::MailWorker { dry_run, limit }) => {
            let res = jobs::mail_worker::run_mail_worker(&pool, dry_run, limit).await?;
            println!("Processed: {}, Sent: {}, Failed: {} in {:.3}s.", res.claimed, res.sent, res.failed, res.elapsed_secs);
        }
        Some(Commands::CustomerMaintenance { dry_run }) => {
            let res = jobs::customer_maintenance::run_customer_maintenance(&pool, dry_run).await?;
            println!("Customer Maintenance finished in {:.3}s.", res.elapsed_secs);
        }
        Some(Commands::CollectMetrics { dry_run, verbose }) => {
            let res = jobs::collect_metrics::run_collect_metrics(&pool, dry_run, verbose).await?;
            println!("Metrics collected in {:.3}s.", res.elapsed_secs);
        }
        Some(Commands::RebuildSalesSummary { dry_run }) => {
            let res = jobs::rebuild_sales_summary::run_rebuild_sales_summary(&pool, dry_run).await?;
            println!("Recomputed {} rows for {} products.", res.rows_updated, res.affected_products);
        }
        Some(Commands::CleanupLogs { dry_run }) => {
            let res = jobs::cleanup_logs::run_cleanup_logs(&pool, dry_run).await?;
            println!("Deleted {} rows in {:.3}s.", res.total_deleted, res.elapsed_secs);
        }
        Some(Commands::AggregateAnalytics { date, dry_run }) => {
            let res = jobs::aggregate_analytics::run_aggregate_analytics(&pool, date.as_deref(), dry_run).await?;
            println!("Analytics aggregated for {}.", res.target_date);
        }
        Some(Commands::ParseAccessLog { path, dry_run }) => {
            let res = jobs::parse_access_log::run_parse_access_log(&pool, path.as_deref(), dry_run).await?;
            println!("Updated {} minute buckets.", res.buckets_updated);
        }
        Some(Commands::PurgeImageCache { dry_run }) => {
            let res = jobs::purge_image_cache::run_purge_image_cache(dry_run).await?;
            println!("Purged {} cached images.", res.purged_files);
        }
        Some(Commands::CleanupOrphans { days, dry_run }) => {
            let res = jobs::cleanup_orphans::run_cleanup_orphans(&pool, days, dry_run).await?;
            println!("Purged {} files, marked {} orphans.", res.purged_files, res.marked_orphans);
        }
        Some(Commands::SyncStorage { limit, all, dry_run }) => {
            let res = jobs::sync_storage::run_sync_storage(&pool, limit, all, dry_run).await?;
            println!("Synced: {}, Failed: {}.", res.synced, res.failed);
        }
        None => {
            println!("No command specified. Use --help to view available commands, or --scheduler to run the daemon.");
        }
    }

    Ok(())
}
