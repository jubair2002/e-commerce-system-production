use std::sync::Arc;
use tokio::net::TcpListener;
use tower_http::compression::CompressionLayer;
use tower_http::cors::CorsLayer;
use tower_http::trace::TraceLayer;
use tower_sessions::SessionManagerLayer;
use tower_sessions_sqlx_store::MySqlStore;

use rust_backend::config::Config;
use rust_backend::db::create_pool;
use rust_backend::routes::build_router;
use rust_backend::services::mailer::Mailer;
use rust_backend::settings::SettingsRepository;
use rust_backend::state::AppState;
use rust_backend::templates::init_templates;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    tracing_subscriber::fmt::init();
    dotenvy::dotenv().ok();

    let config = Config::from_env();
    tracing::info!("Starting Jinish Potro Rust backend on port {}", config.port);

    let db_pool = create_pool(&config.database_url()).await?;
    let settings = SettingsRepository::load(&db_pool).await?;

    let tera = init_templates("templates", settings.clone(), config.app_url.clone())?;
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
    let session_layer = SessionManagerLayer::new(session_store)
        .with_secure(false)
        .with_same_site(tower_sessions::cookie::SameSite::Lax);

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
