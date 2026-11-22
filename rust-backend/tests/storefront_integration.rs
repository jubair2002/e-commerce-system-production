use axum::{
    body::Body,
    http::{Request, StatusCode},
};
use std::sync::Arc;
use tower::ServiceExt;
use tower_sessions::SessionManagerLayer;
use tower_sessions_sqlx_store::MySqlStore;

use rust_backend::config::Config;
use rust_backend::db::create_pool;
use rust_backend::routes::build_router;
use rust_backend::services::mailer::Mailer;
use rust_backend::settings::SettingsRepository;
use rust_backend::state::AppState;
use rust_backend::templates::init_templates;

async fn setup_app() -> axum::Router {
    let _ = tracing_subscriber::fmt::try_init();
    dotenvy::dotenv().ok();
    let config = Config::from_env();
    let pool = create_pool(&config.database_url())
        .await
        .expect("DB connection failed");
    let settings = SettingsRepository::load(&pool)
        .await
        .expect("Settings load failed");

    let tera = init_templates("templates", settings.clone(), config.app_url.clone())
        .expect("Tera init failed");
    let tera = Arc::new(tera);

    let mailer = Arc::new(Mailer::new(
        config.smtp_host.clone(),
        config.smtp_port,
        config.smtp_user.clone(),
        config.smtp_password.clone(),
        config.smtp_from.clone(),
        pool.clone(),
    ));

    let state = AppState {
        db: pool.clone(),
        settings,
        tera,
        config: config.clone(),
        mailer,
    };

    let session_store = MySqlStore::new(pool)
        .with_schema_name(&config.db_name)
        .unwrap()
        .with_table_name("tower_sessions")
        .unwrap();
    let session_layer = SessionManagerLayer::new(session_store)
        .with_secure(false);

    build_router(state).layer(session_layer)
}

#[tokio::test]
async fn test_storefront_home() {
    let app = setup_app().await;

    let response = app
        .oneshot(Request::builder().uri("/").body(Body::empty()).unwrap())
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_storefront_shop() {
    let app = setup_app().await;

    let response = app
        .oneshot(Request::builder().uri("/shop").body(Body::empty()).unwrap())
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_sitemap_xml() {
    let app = setup_app().await;

    let response = app
        .oneshot(Request::builder().uri("/sitemap.xml").body(Body::empty()).unwrap())
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_api_search_suggest() {
    let app = setup_app().await;

    let response = app
        .oneshot(
            Request::builder()
                .uri("/api/search_suggest.php?q=bag")
                .body(Body::empty())
                .unwrap(),
        )
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_api_availability() {
    let app = setup_app().await;

    let response = app
        .oneshot(
            Request::builder()
                .uri("/api/availability.php?ids=1,2,3")
                .body(Body::empty())
                .unwrap(),
        )
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_api_cart_ajax_get() {
    let app = setup_app().await;

    let response = app
        .oneshot(
            Request::builder()
                .uri("/api/cart_ajax.php")
                .body(Body::empty())
                .unwrap(),
        )
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_404_fallback() {
    let app = setup_app().await;

    let response = app
        .oneshot(
            Request::builder()
                .uri("/this-page-definitely-does-not-exist")
                .body(Body::empty())
                .unwrap(),
        )
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::NOT_FOUND);
}

#[tokio::test]
async fn test_storefront_cart() {
    let app = setup_app().await;

    let response = app
        .oneshot(Request::builder().uri("/cart").body(Body::empty()).unwrap())
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_storefront_product() {
    let app = setup_app().await;

    let response = app
        .oneshot(
            Request::builder()
                .uri("/product/test-simple-product-1794720641")
                .body(Body::empty())
                .unwrap(),
        )
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_storefront_track() {
    let app = setup_app().await;

    let response = app
        .oneshot(Request::builder().uri("/track").body(Body::empty()).unwrap())
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_storefront_contact() {
    let app = setup_app().await;

    let response = app
        .oneshot(Request::builder().uri("/contact").body(Body::empty()).unwrap())
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_auth_login() {
    let app = setup_app().await;

    let response = app
        .oneshot(Request::builder().uri("/login").body(Body::empty()).unwrap())
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_auth_register() {
    let app = setup_app().await;

    let response = app
        .oneshot(Request::builder().uri("/register").body(Body::empty()).unwrap())
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_auth_forgot_password() {
    let app = setup_app().await;

    let response = app
        .oneshot(
            Request::builder()
                .uri("/forgot-password")
                .body(Body::empty())
                .unwrap(),
        )
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}

#[tokio::test]
async fn test_account_unauthenticated_redirect() {
    let app = setup_app().await;

    let response = app
        .oneshot(Request::builder().uri("/account").body(Body::empty()).unwrap())
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::SEE_OTHER);
}

#[tokio::test]
async fn test_metrics_endpoint() {
    let app = setup_app().await;

    let response = app
        .oneshot(Request::builder().uri("/metrics").body(Body::empty()).unwrap())
        .await
        .unwrap();

    assert_eq!(response.status(), StatusCode::OK);
}
