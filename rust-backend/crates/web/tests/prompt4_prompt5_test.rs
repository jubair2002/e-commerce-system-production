use axum::{
    body::Body,
    http::{header, Method, Request, StatusCode},
};
use common::config::calculate_timezone_offset;
use common::crypto::{admin_password_verify, customer_password_hash, customer_password_verify, verify_csrf_token};
use common::formatters::{escape_html, format_order_number, number_format, slugify};
use db::create_pool_with_tz;
use db::queries::customer_auth::password_ok;
use db::SettingsRepository;
use jobs::Mailer;
use rust_decimal::Decimal;
use std::sync::Arc;
use tower::ServiceExt;
use tower_sessions::SessionManagerLayer;
use tower_sessions_sqlx_store::MySqlStore;
use web::middleware::RateLimitManager;
use web::state::{user_has_permission, AppState};
use web::{build_router, init_templates};

async fn setup_test_app() -> axum::Router {
    dotenvy::dotenv().ok();
    let config = common::Config::from_env();

    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset)
        .await
        .expect("Failed to create test MySQL pool");

    let settings = SettingsRepository::load(&pool)
        .await
        .expect("Failed to load settings");

    let tera = init_templates(
        "templates",
        settings.clone(),
        config.app_url.clone(),
    )
    .expect("Failed to init templates");
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
        .expect("schema")
        .with_table_name("tower_sessions")
        .expect("table");

    let session_layer = SessionManagerLayer::new(session_store)
        .with_secure(false)
        .with_same_site(tower_sessions::cookie::SameSite::Lax)
        .with_http_only(true);

    build_router(state).layer(session_layer)
}

#[tokio::test]
async fn test_config_timezone_offset() {
    assert_eq!(calculate_timezone_offset("Asia/Dhaka"), "+06:00");
    assert_eq!(calculate_timezone_offset("UTC"), "+00:00");
}

#[tokio::test]
async fn test_health_endpoint() {
    let app = setup_test_app().await;

    let req = Request::builder()
        .uri("/health")
        .method(Method::GET)
        .body(Body::empty())
        .unwrap();

    let res = app.oneshot(req).await.unwrap();
    assert_eq!(res.status(), StatusCode::OK);

    let bytes = axum::body::to_bytes(res.into_body(), 1024 * 64).await.unwrap();
    let json: serde_json::Value = serde_json::from_slice(&bytes).unwrap();
    assert_eq!(json["status"], "ok");
    assert_eq!(json["db"], "ok");
}

#[tokio::test]
async fn test_security_headers_present() {
    let app = setup_test_app().await;

    let req = Request::builder()
        .uri("/health")
        .method(Method::GET)
        .body(Body::empty())
        .unwrap();

    let res = app.oneshot(req).await.unwrap();
    let headers = res.headers();

    assert_eq!(
        headers.get("X-Content-Type-Options").unwrap(),
        "nosniff"
    );
    assert_eq!(
        headers.get("Referrer-Policy").unwrap(),
        "strict-origin-when-cross-origin"
    );
    assert_eq!(
        headers.get("X-Frame-Options").unwrap(),
        "SAMEORIGIN"
    );
    assert_eq!(
        headers.get("X-XSS-Protection").unwrap(),
        "1; mode=block"
    );
}

#[tokio::test]
async fn test_csrf_middleware_enforcement() {
    let app = setup_test_app().await;

    // State changing request without valid CSRF must be rejected with 403 Forbidden
    let req = Request::builder()
        .uri("/login")
        .method(Method::POST)
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .body(Body::from("email=test%40example.com&password=secret"))
        .unwrap();

    let res = app.oneshot(req).await.unwrap();
    assert_eq!(res.status(), StatusCode::FORBIDDEN);
}

#[tokio::test]
async fn test_formatters_parity() {
    assert_eq!(slugify("Special Discount: 50% Off!"), "special-discount-50-off");
    assert_eq!(format_order_number("ORD", "202611", 7), "ORD-202611-0007");
    assert_eq!(format_order_number("ORD", "202611", 10000), "ORD-202611-10000");
    assert_eq!(number_format(Decimal::new(123456, 2), 2, ".", ","), "1,234.56");
    assert_eq!(escape_html("<script>'\"&</script>"), "&lt;script&gt;&#039;&quot;&amp;&lt;/script&gt;");
}

#[test]
fn test_customer_password_hash_cost_10() {
    let hash = customer_password_hash("TestP@ssw0rd!").expect("hash should succeed");
    // Verify bcrypt cost 10: $2b$10$ or $2y$10$
    assert!(hash.starts_with("$2b$10$") || hash.starts_with("$2y$10$"));
    assert!(customer_password_verify("TestP@ssw0rd!", &hash));
    assert!(!customer_password_verify("WrongPassword", &hash));
}

#[test]
fn test_admin_password_direct_bcrypt() {
    let hash = common::crypto::admin_password_hash("AdminSecret!123").expect("hash should succeed");
    assert!(admin_password_verify("AdminSecret!123", &hash));
    assert!(!admin_password_verify("WrongPassword", &hash));
}

#[test]
fn test_password_policy_rules() {
    // Too short
    assert!(password_ok("short", "user@test.com", "John", 8).is_err());
    // Matches email
    assert!(password_ok("user@test.com", "user@test.com", "John", 8).is_err());
    // Matches email prefix
    assert!(password_ok("user", "user@test.com", "John", 8).is_err());
    // Matches name
    assert!(password_ok("john", "user@test.com", "John", 8).is_err());
    // Common password
    assert!(password_ok("password123", "user@test.com", "John", 8).is_err());
    // Strong password passes
    assert!(password_ok("Compl3x!Secur3P@ss", "user@test.com", "John", 8).is_ok());
}

#[test]
fn test_admin_rbac_wildcards() {
    // Admin role has all permissions
    assert!(user_has_permission("admin", &[], &["orders.delete"]));
    assert!(user_has_permission("admin", &[], &["settings.storage"]));

    // Global wildcard `*`
    assert!(user_has_permission("custom", &[String::from("*")], &["users.delete"]));

    // Module wildcard e.g. `orders.*`
    let manager_perms = vec![String::from("orders.*"), String::from("catalog.view")];
    assert!(user_has_permission("manager", &manager_perms, &["orders.view"]));
    assert!(user_has_permission("manager", &manager_perms, &["orders.manage"]));
    assert!(user_has_permission("manager", &manager_perms, &["orders.delete"]));
    assert!(!user_has_permission("manager", &manager_perms, &["catalog.delete"]));

    // Specific permission
    let editor_perms = vec![String::from("catalog.view"), String::from("catalog.edit")];
    assert!(user_has_permission("editor", &editor_perms, &["catalog.view"]));
    assert!(user_has_permission("editor", &editor_perms, &["catalog.edit"]));
    assert!(!user_has_permission("editor", &editor_perms, &["catalog.delete"]));
}

#[test]
fn test_csrf_constant_time_comparison() {
    let token1 = "abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789";
    let token2 = "abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789";
    let token3 = "abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456780";

    assert!(verify_csrf_token(token1, token2));
    assert!(!verify_csrf_token(token1, token3));
    assert!(!verify_csrf_token(token1, ""));
}

#[test]
fn test_governor_rate_limiter() {
    let rlm = RateLimitManager::new();
    let test_ip = "192.168.1.100";

    // First lookup should succeed
    assert!(rlm.check_track(test_ip));
    assert!(rlm.check_availability(test_ip));
}
