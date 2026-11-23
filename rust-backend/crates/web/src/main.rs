use std::sync::Arc;
use tokio::net::TcpListener;
use tower_http::compression::CompressionLayer;
use tower_http::cors::CorsLayer;
use tower_http::trace::TraceLayer;
use tower_sessions::SessionManagerLayer;
use tower_sessions_sqlx_store::MySqlStore;

use common::crypto::admin_password_hash;
use common::Config;
use db::create_pool_with_tz;
use db::SettingsRepository;
use jobs::Mailer;
use web::build_router;
use web::init_templates;
use web::AppState;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    tracing_subscriber::fmt::init();
    dotenvy::dotenv().ok();

    let config = Config::from_env();

    // Check CLI subcommands (e.g. create-admin)
    let args: Vec<String> = std::env::args().collect();
    if args.len() > 1 && args[1] == "create-admin" {
        return handle_create_admin(&config, &args[2..]).await;
    }

    tracing::info!("Starting Jinish Potro Rust backend on port {}", config.port);

    let db_pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset).await?;
    let settings = SettingsRepository::load(&db_pool).await?;

    let templates_dir = std::env::var("TEMPLATES_DIR").unwrap_or_else(|_| {
        for candidate in &["templates", "rust-backend/templates", "../templates", "../../templates"] {
            if std::path::Path::new(candidate).is_dir() {
                return candidate.to_string();
            }
        }
        "templates".to_string()
    });

    let tera = init_templates(&templates_dir, settings.clone(), config.base_url.clone())?;
    let tera = Arc::new(tera);

    let mailer = Arc::new(Mailer::new(
        config.smtp_host.clone(),
        config.smtp_port,
        config.smtp_user.clone(),
        config.smtp_password.clone(),
        config.smtp_from.clone(),
        db_pool.clone(),
    ));

    let app_state = AppState {
        db: db_pool.clone(),
        settings,
        tera,
        config: config.clone(),
        mailer,
    };

    let session_store = MySqlStore::new(db_pool)
        .with_schema_name(&config.db_name)
        .map_err(std::io::Error::other)?
        .with_table_name("tower_sessions")
        .map_err(std::io::Error::other)?;

    let is_secure = config.site_url.starts_with("https://") || config.app_url.starts_with("https://");
    let session_layer = SessionManagerLayer::new(session_store)
        .with_secure(is_secure)
        .with_same_site(tower_sessions::cookie::SameSite::Lax)
        .with_http_only(true);

    let app = build_router(app_state)
        .layer(session_layer)
        .layer(CompressionLayer::new())
        .layer(CorsLayer::permissive())
        .layer(TraceLayer::new_for_http());

    let addr = format!("{}:{}", config.host, config.port);
    let listener = TcpListener::bind(&addr).await?;
    tracing::info!("Server listening on http://{}", addr);

    axum::serve(listener, app).await?;

    Ok(())
}

/// CLI command create-admin handler:
/// Usage: web create-admin <name> <email> <password> [role]
/// Or:    web create-admin --name <name> --email <email> --password <password>
async fn handle_create_admin(
    config: &Config,
    args: &[String],
) -> Result<(), Box<dyn std::error::Error>> {
    let mut name = String::new();
    let mut email = String::new();
    let mut password = String::new();
    let mut role = "admin".to_string();

    let mut i = 0;
    while i < args.len() {
        match args[i].as_str() {
            "--name" if i + 1 < args.len() => {
                name = args[i + 1].clone();
                i += 2;
            }
            "--email" if i + 1 < args.len() => {
                email = args[i + 1].clone();
                i += 2;
            }
            "--password" if i + 1 < args.len() => {
                password = args[i + 1].clone();
                i += 2;
            }
            "--role" if i + 1 < args.len() => {
                role = args[i + 1].clone();
                i += 2;
            }
            other if !other.starts_with('-') => {
                if name.is_empty() {
                    name = other.to_string();
                } else if email.is_empty() {
                    email = other.to_string();
                } else if password.is_empty() {
                    password = other.to_string();
                } else if role == "admin" {
                    role = other.to_string();
                }
                i += 1;
            }
            _ => {
                i += 1;
            }
        }
    }

    if name.is_empty() || email.is_empty() || password.is_empty() {
        eprintln!("Usage: web create-admin <name> <email> <password> [role]");
        eprintln!("   or: web create-admin --name <name> --email <email> --password <password> [--role <role>]");
        std::process::exit(1);
    }

    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset).await?;
    let hash = admin_password_hash(&password)?;
    let perms = if role == "admin" {
        r#"["*"]"#
    } else {
        r#"["catalog.*","orders.*"]"#
    };

    sqlx::query(
        "INSERT INTO users (name, email, password, role, permissions, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'active', NOW(), NOW())
         ON DUPLICATE KEY UPDATE password = VALUES(password), role = VALUES(role), permissions = VALUES(permissions), status = 'active'",
    )
    .bind(&name)
    .bind(&email)
    .bind(&hash)
    .bind(&role)
    .bind(perms)
    .execute(&pool)
    .await?;

    println!("Admin user '{}' ({}) successfully created with role '{}'.", name, email, role);
    Ok(())
}
