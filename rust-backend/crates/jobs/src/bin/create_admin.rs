use clap::Parser;
use common::config::Config;
use db::create_pool_with_tz;
use jobs::create_admin::run_create_admin;

#[derive(Parser, Debug)]
#[command(name = "create-admin", about = "Create or update an administrative user")]
struct Args {
    #[arg(short, long, help = "Admin username")]
    username: String,

    #[arg(short, long, help = "Admin email address")]
    email: String,

    #[arg(short, long, help = "Admin password (minimum 8 characters)")]
    password: String,

    #[arg(short, long, default_value = "super_admin", help = "Role: super_admin or admin")]
    role: String,

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

    let uid = run_create_admin(
        &pool,
        &args.username,
        &args.email,
        &args.password,
        &args.role,
        args.dry_run,
    )
    .await?;

    println!("Admin user successfully provisioned (ID: {}).", uid);
    Ok(())
}
