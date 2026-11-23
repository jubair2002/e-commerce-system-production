use axum::{
    body::Body,
    http::{header, Request, StatusCode},
};
use chrono::Utc;
use std::path::Path;
use std::sync::Arc;
use tower::ServiceExt;
use tower_sessions::{MemoryStore, SessionManagerLayer};

use common::config::Config;
use common::crypto::admin_password_hash;
use db::{create_pool_with_tz, SettingsRepository};
use jobs::Mailer;
use web::routes::build_router;
use web::state::AppState;
use web::templates::init_templates;

async fn setup_test_app() -> (AppState, axum::Router) {
    dotenvy::dotenv().ok();
    let config = Config::from_env();
    let pool = create_pool_with_tz(&config.database_url(), &config.time_zone_offset)
        .await
        .expect("Failed to connect to test MySQL database");

    let settings = SettingsRepository::load(&pool)
        .await
        .expect("Failed to load settings");

    let templates_path = if Path::new("templates").is_dir() {
        "templates"
    } else if Path::new("../../templates").is_dir() {
        "../../templates"
    } else {
        "rust-backend/templates"
    };

    let tera = init_templates(templates_path, settings.clone(), config.app_url.clone())
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

    let session_store = MemoryStore::default();
    let router = build_router(state.clone())
        .layer(SessionManagerLayer::new(session_store).with_secure(false));

    (state, router)
}

async fn create_test_admin(state: &AppState, role: &str) -> (u64, String, String) {
    let email = format!("admin_p14_{}@example.com", Utc::now().timestamp_micros());
    let password = "AdminPassword123!";
    let hashed = admin_password_hash(password).expect("Hash admin password");

    let res = sqlx::query(
        "INSERT INTO users (name, email, password, role, permissions, status, created_at, updated_at)
         VALUES ('Test Staff P14', ?, ?, ?, NULL, 'active', NOW(), NOW())"
    )
    .bind(&email)
    .bind(&hashed)
    .bind(role)
    .execute(&state.db)
    .await
    .expect("Insert test admin user");

    (res.last_insert_id(), email, password.to_string())
}

async fn login_admin_and_get_cookie(router: &axum::Router, email: &str, password: &str) -> (String, String) {
    let req = Request::builder()
        .uri("/admin/login.php")
        .body(Body::empty())
        .unwrap();
    let res = router.clone().oneshot(req).await.unwrap();
    assert_eq!(res.status(), StatusCode::OK);

    let cookie1 = res
        .headers()
        .get(header::SET_COOKIE)
        .expect("Session cookie on GET /admin/login.php")
        .to_str()
        .unwrap()
        .split(';')
        .next()
        .unwrap()
        .to_string();

    let body_bytes = axum::body::to_bytes(res.into_body(), usize::MAX).await.unwrap();
    let html = String::from_utf8_lossy(&body_bytes);

    let csrf_token = if let Some(idx) = html.find("name=\"_csrf\" value=\"") {
        let rest = &html[idx + "name=\"_csrf\" value=\"".len()..];
        let end = rest.find('"').unwrap_or(0);
        &rest[..end]
    } else if let Some(idx) = html.find("name=\"csrf_token\" value=\"") {
        let rest = &html[idx + "name=\"csrf_token\" value=\"".len()..];
        let end = rest.find('"').unwrap_or(0);
        &rest[..end]
    } else {
        ""
    };

    let post_body = serde_urlencoded::to_string([
        ("email", email),
        ("password", password),
        ("_csrf", csrf_token),
        ("csrf_token", csrf_token),
    ])
    .unwrap();

    let req2 = Request::builder()
        .method("POST")
        .uri("/admin/login.php")
        .header(header::COOKIE, &cookie1)
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .body(Body::from(post_body))
        .unwrap();

    let res2 = router.clone().oneshot(req2).await.unwrap();
    assert_eq!(res2.status(), StatusCode::SEE_OTHER);

    let cookie_final = if let Some(c2) = res2.headers().get_all(header::SET_COOKIE).iter().next_back() {
        c2.to_str().unwrap().split(';').next().unwrap().to_string()
    } else {
        cookie1
    };

    let _ = axum::body::to_bytes(res2.into_body(), usize::MAX).await;
    (cookie_final, csrf_token.to_string())
}

#[tokio::test]
async fn test_admin_monitor_data_json_contract() {
    let (state, app) = setup_test_app().await;
    let (_id, email, password) = create_test_admin(&state, "admin").await;
    let (cookie, _csrf) = login_admin_and_get_cookie(&app, &email, &password).await;

    let req = Request::builder()
        .uri("/admin/ajax/monitor_data.php?range=1h")
        .header(header::COOKIE, &cookie)
        .body(Body::empty())
        .unwrap();

    let resp = app.clone().oneshot(req).await.unwrap();
    assert_eq!(resp.status(), StatusCode::OK);

    let bytes = axum::body::to_bytes(resp.into_body(), 1024 * 256).await.unwrap();
    let body: serde_json::Value = serde_json::from_slice(&bytes).expect("valid JSON response");

    assert_eq!(body["ok"], true);
    assert_eq!(body["range"], "1h");
    assert!(body["tiles"].is_object());
    assert!(body["tiles"]["cpu_percent"].is_number());
    assert!(body["tiles"]["mem_percent"].is_number());
    assert!(body["tiles"]["uptime_sec"].is_number());
    assert!(body["tiles"]["php_version"].is_string());
    assert!(body["tiles"]["collector_command"].is_string());

    assert!(body["series"].is_object());
    assert!(body["series"]["labels"].is_array());
    assert!(body["series"]["cpu"].is_array());
    assert!(body["series"]["load"].is_array());
    assert!(body["series"]["mem"].is_array());

    assert!(body["bandwidth"].is_object());
    assert!(body["bandwidth"]["month_bytes"].is_number());
    assert!(body["bandwidth"]["quota_percent"].is_number());

    assert!(body["tables"].is_object());
    assert!(body["tables"]["slow"].is_array());
    assert!(body["tables"]["interfaces"].is_array());
    assert!(body["alerts"].is_array());
}

#[tokio::test]
async fn test_admin_monitor_data_csv_contract() {
    let (state, app) = setup_test_app().await;
    let (_id, email, password) = create_test_admin(&state, "admin").await;
    let (cookie, _csrf) = login_admin_and_get_cookie(&app, &email, &password).await;

    let req = Request::builder()
        .uri("/admin/ajax/monitor_data.php?format=csv")
        .header(header::COOKIE, &cookie)
        .body(Body::empty())
        .unwrap();

    let resp = app.clone().oneshot(req).await.unwrap();
    assert_eq!(resp.status(), StatusCode::OK);
    assert_eq!(resp.headers().get(header::CONTENT_TYPE).unwrap(), "text/csv; charset=utf-8");

    let bytes = axum::body::to_bytes(resp.into_body(), 1024 * 256).await.unwrap();
    // Verify UTF-8 BOM
    assert!(bytes.len() >= 3);
    assert_eq!(&bytes[0..3], &[0xEF, 0xBB, 0xBF]);

    let csv_text = String::from_utf8_lossy(&bytes[3..]);
    assert!(csv_text.contains("Collected At,CPU (%),Load 1m,Load 5m,Load 15m,RAM Used (MB),RAM Total (MB),RAM (%),Disk Used (GB),Disk Total (GB),Disk (%),Net RX (KB/s),Net TX (KB/s),DB Threads,DB QPS"));
}

#[tokio::test]
async fn test_admin_analytics_data_json_contract() {
    let (state, app) = setup_test_app().await;
    let (_id, email, password) = create_test_admin(&state, "admin").await;
    let (cookie, _csrf) = login_admin_and_get_cookie(&app, &email, &password).await;

    let req = Request::builder()
        .uri("/admin/ajax/analytics_data.php?from=2026-01-01&to=2026-12-31")
        .header(header::COOKIE, &cookie)
        .body(Body::empty())
        .unwrap();

    let resp = app.clone().oneshot(req).await.unwrap();
    assert_eq!(resp.status(), StatusCode::OK);

    let bytes = axum::body::to_bytes(resp.into_body(), 1024 * 256).await.unwrap();
    let body: serde_json::Value = serde_json::from_slice(&bytes).expect("valid JSON response");

    assert_eq!(body["ok"], true);
    assert!(body["kpis"].is_object());
    assert!(body["kpis"]["revenue"].is_number() || body["kpis"]["revenue"].is_string());
    assert!(body["kpis"]["orders"].is_number());
    assert!(body["funnel"].is_object());
    assert!(body["funnel"]["views"].is_number());
    assert!(body["trend"].is_object());
    assert!(body["top_products"].is_array());
    assert!(body["orders_status"].is_array());
    assert!(body["insights"].is_array());
}

#[tokio::test]
async fn test_admin_monitoring_and_analytics_pages_render() {
    let (state, app) = setup_test_app().await;
    let (_id, email, password) = create_test_admin(&state, "admin").await;
    let (cookie, _csrf) = login_admin_and_get_cookie(&app, &email, &password).await;

    // 1. GET /admin/monitoring.php
    let req = Request::builder()
        .uri("/admin/monitoring.php")
        .header(header::COOKIE, &cookie)
        .body(Body::empty())
        .unwrap();
    let resp = app.clone().oneshot(req).await.unwrap();
    assert_eq!(resp.status(), StatusCode::OK);

    // 2. GET /admin/analytics.php
    let req = Request::builder()
        .uri("/admin/analytics.php")
        .header(header::COOKIE, &cookie)
        .body(Body::empty())
        .unwrap();
    let resp = app.clone().oneshot(req).await.unwrap();
    assert_eq!(resp.status(), StatusCode::OK);

    // 3. GET /admin/analytics.php?export=csv
    let req = Request::builder()
        .uri("/admin/analytics.php?export=csv")
        .header(header::COOKIE, &cookie)
        .body(Body::empty())
        .unwrap();
    let resp = app.clone().oneshot(req).await.unwrap();
    assert_eq!(resp.status(), StatusCode::OK);
    let bytes = axum::body::to_bytes(resp.into_body(), 1024 * 256).await.unwrap();
    assert_eq!(&bytes[0..3], &[0xEF, 0xBB, 0xBF]); // UTF-8 BOM
    let csv_text = String::from_utf8_lossy(&bytes[3..]);
    assert!(csv_text.contains("Product ID,Product Name,SKU,Category,Stock Left,Units Sold,Revenue (BDT)"));
}

#[tokio::test]
async fn test_admin_monitoring_post_save_rule_and_alert_eval() {
    let (state, app) = setup_test_app().await;
    let (_id, email, password) = create_test_admin(&state, "admin").await;
    let (cookie, csrf) = login_admin_and_get_cookie(&app, &email, &password).await;

    let form_body = format!("action=save_rule&metric=cpu_percent&operator=%3E&threshold=90&for_minutes=5&notify_email=1&enabled=1&_csrf={}", csrf);
    let req = Request::builder()
        .method("POST")
        .uri("/admin/monitoring.php")
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .header(header::COOKIE, &cookie)
        .body(Body::from(form_body))
        .unwrap();

    let resp = app.clone().oneshot(req).await.unwrap();
    assert_eq!(resp.status(), StatusCode::SEE_OTHER);

    // Check alert rule exists in MySQL
    let rule_exists: Option<u64> = sqlx::query_scalar(
        "SELECT id FROM monitor_alert_rules WHERE metric = 'cpu_percent' AND threshold = 90.00 LIMIT 1"
    )
    .fetch_optional(&state.db)
    .await
    .unwrap();
    assert!(rule_exists.is_some());
}
