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

    // Auto-create runtime tables if missing
    let _ = sqlx::query(
        "CREATE TABLE IF NOT EXISTS `tower_sessions` (
            id CHAR(22) PRIMARY KEY NOT NULL,
            data BLOB NOT NULL,
            expiry_date TIMESTAMP(6) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    )
    .execute(&db_pool)
    .await;

    let _ = sqlx::query(
        "CREATE TABLE IF NOT EXISTS `files` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `storage_key` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
          `provider` enum('local','s3') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local',
          `bucket` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `mime_type` varchar(127) COLLATE utf8mb4_unicode_ci NOT NULL,
          `size` bigint unsigned NOT NULL DEFAULT '0',
          `checksum_sha256` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `width` int DEFAULT NULL,
          `height` int DEFAULT NULL,
          `visibility` enum('public','private') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
          `purpose` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
          `owner_type` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `owner_id` bigint unsigned DEFAULT NULL,
          `status` enum('pending','ready','failed','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ready',
          `sync_status` enum('synced','pending','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'synced',
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `deleted_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_provider_storage_key` (`provider`,`storage_key`(250)),
          KEY `idx_files_owner` (`owner_type`,`owner_id`),
          KEY `idx_files_purpose` (`purpose`),
          KEY `idx_files_status_sync` (`status`,`sync_status`),
          KEY `idx_files_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    )
    .execute(&db_pool)
    .await;

    let _ = sqlx::query(
        "CREATE TABLE IF NOT EXISTS `product_sales_summary` (
          `product_id` int unsigned NOT NULL,
          `variant_id` int unsigned NOT NULL DEFAULT '0',
          `variant_label` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `units` int unsigned NOT NULL DEFAULT '0',
          `revenue` decimal(12,2) NOT NULL DEFAULT '0.00',
          `orders_count` int unsigned NOT NULL DEFAULT '0',
          `last_sale` datetime DEFAULT NULL,
          PRIMARY KEY (`product_id`,`variant_id`),
          KEY `idx_units` (`units` DESC),
          KEY `idx_revenue` (`revenue` DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    )
    .execute(&db_pool)
    .await;
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

    // Spawn background system metrics collector (initial collection + every 60s)
    let metrics_pool = db_pool.clone();
    tokio::spawn(async move {
        let _ = jobs::collect_metrics::run_collect_metrics(&metrics_pool, false, false).await;
        let mut interval = tokio::time::interval(std::time::Duration::from_secs(60));
        loop {
            interval.tick().await;
            if let Err(e) = jobs::collect_metrics::run_collect_metrics(&metrics_pool, false, false).await {
                tracing::warn!("Metrics collection error: {}", e);
            }
        }
    });

    // Spawn background mail queue runner (every 10s)
    let mail_pool = db_pool.clone();
    tokio::spawn(async move {
        let mut interval = tokio::time::interval(std::time::Duration::from_secs(10));
        loop {
            interval.tick().await;
            if let Err(e) = jobs::mail_worker::run_mail_worker(&mail_pool, false, 25).await {
                tracing::warn!("Mail worker error: {}", e);
            }
        }
    });

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
