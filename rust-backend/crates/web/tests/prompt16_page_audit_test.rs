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
use common::crypto::{admin_password_hash, customer_password_hash};
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

async fn get_admin_auth_cookie(router: &axum::Router, state: &AppState) -> String {
    let email = format!("p16_admin_{}@example.com", Utc::now().timestamp_micros());
    let password = "AdminPassword123!";
    let hashed = admin_password_hash(password).expect("Hash admin password");

    let _ = sqlx::query(
        "INSERT INTO users (name, email, password, role, permissions, status, created_at, updated_at)
         VALUES ('P16 Audit Admin', ?, ?, 'admin', NULL, 'active', NOW(), NOW())"
    )
    .bind(&email)
    .bind(&hashed)
    .execute(&state.db)
    .await
    .unwrap();

    let req = Request::builder().uri("/admin/login.php").body(Body::empty()).unwrap();
    let res = router.clone().oneshot(req).await.unwrap();
    let cookie1 = res.headers().get(header::SET_COOKIE).unwrap().to_str().unwrap().split(';').next().unwrap().to_string();

    let body_bytes = axum::body::to_bytes(res.into_body(), usize::MAX).await.unwrap();
    let html = String::from_utf8_lossy(&body_bytes);
    let csrf_token = if let Some(idx) = html.find("name=\"_csrf\" value=\"") {
        let rest = &html[idx + "name=\"_csrf\" value=\"".len()..];
        let end = rest.find('"').unwrap_or(0);
        &rest[..end]
    } else {
        ""
    };

    let post_body = serde_urlencoded::to_string([
        ("email", email.as_str()),
        ("password", password),
        ("_csrf", csrf_token),
    ]).unwrap();

    let req2 = Request::builder()
        .method("POST")
        .uri("/admin/login.php")
        .header(header::COOKIE, &cookie1)
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .body(Body::from(post_body))
        .unwrap();

    let res2 = router.clone().oneshot(req2).await.unwrap();
    let cookie_final = if let Some(c2) = res2.headers().get_all(header::SET_COOKIE).iter().next_back() {
        c2.to_str().unwrap().split(';').next().unwrap().to_string()
    } else {
        cookie1
    };
    let _ = axum::body::to_bytes(res2.into_body(), usize::MAX).await;
    cookie_final
}

async fn get_customer_auth_cookie(router: &axum::Router, state: &AppState) -> String {
    let email = format!("p16_customer_{}@example.com", Utc::now().timestamp_micros());
    let password = "CustomerPassword123!";
    let hashed = customer_password_hash(password).expect("Hash customer password");

    let _ = sqlx::query(
        "INSERT INTO customers (name, email, password_hash, status, created_at, updated_at)
         VALUES ('P16 Audit Customer', ?, ?, 'active', NOW(), NOW())"
    )
    .bind(&email)
    .bind(&hashed)
    .execute(&state.db)
    .await
    .unwrap();

    let req = Request::builder().uri("/login").body(Body::empty()).unwrap();
    let res = router.clone().oneshot(req).await.unwrap();
    let cookie1 = res.headers().get(header::SET_COOKIE).unwrap().to_str().unwrap().split(';').next().unwrap().to_string();

    let body_bytes = axum::body::to_bytes(res.into_body(), usize::MAX).await.unwrap();
    let html = String::from_utf8_lossy(&body_bytes);
    let csrf_token = if let Some(idx) = html.find("name=\"_csrf\" value=\"") {
        let rest = &html[idx + "name=\"_csrf\" value=\"".len()..];
        let end = rest.find('"').unwrap_or(0);
        &rest[..end]
    } else {
        ""
    };

    let post_body = serde_urlencoded::to_string([
        ("login", email.as_str()),
        ("password", password),
        ("_csrf", csrf_token),
    ]).unwrap();

    let req2 = Request::builder()
        .method("POST")
        .uri("/login")
        .header(header::COOKIE, &cookie1)
        .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
        .body(Body::from(post_body))
        .unwrap();

    let res2 = router.clone().oneshot(req2).await.unwrap();
    let cookie_final = if let Some(c2) = res2.headers().get_all(header::SET_COOKIE).iter().next_back() {
        c2.to_str().unwrap().split(';').next().unwrap().to_string()
    } else {
        cookie1
    };
    let _ = axum::body::to_bytes(res2.into_body(), usize::MAX).await;
    cookie_final
}

#[tokio::test]
async fn test_audit_storefront_pages() {
    let (_state, router) = setup_test_app().await;

    let storefront_routes = vec![
        "/",
        "/index.php",
        "/shop",
        "/shop.php",
        "/cart",
        "/cart.php",
        "/checkout",
        "/checkout.php",
        "/order/confirmation",
        "/track",
        "/track.php",
        "/contact",
        "/contact.php",
        "/sitemap.xml",
        "/robots.txt",
        "/health",
    ];

    for path in storefront_routes {
        let req = Request::builder().uri(path).body(Body::empty()).unwrap();
        let res = router.clone().oneshot(req).await.unwrap();
        let status = res.status();
        assert!(
            status == StatusCode::OK || status == StatusCode::SEE_OTHER || status == StatusCode::MOVED_PERMANENTLY,
            "Storefront route failed: {} returned status {}",
            path,
            status
        );
    }
}

#[tokio::test]
async fn test_audit_customer_auth_pages() {
    let (_state, router) = setup_test_app().await;

    let auth_routes = vec![
        "/login",
        "/login.php",
        "/register",
        "/register.php",
        "/forgot-password",
        "/forgot_password.php",
        "/reset-password",
        "/reset_password.php",
        "/verify-email",
        "/verify_email.php",
        "/resend-verification",
        "/resend_verification.php",
    ];

    for path in auth_routes {
        let req = Request::builder().uri(path).body(Body::empty()).unwrap();
        let res = router.clone().oneshot(req).await.unwrap();
        let status = res.status();
        assert!(
            status == StatusCode::OK || status == StatusCode::SEE_OTHER || status == StatusCode::MOVED_PERMANENTLY,
            "Auth route failed: {} returned status {}",
            path,
            status
        );
    }
}

#[tokio::test]
async fn test_audit_customer_account_pages_authenticated() {
    let (state, router) = setup_test_app().await;
    let cookie = get_customer_auth_cookie(&router, &state).await;

    let account_routes = vec![
        "/account",
        "/account/index.php",
        "/account/orders",
        "/account/orders.php",
        "/account/addresses",
        "/account/profile",
        "/account/security",
        "/account/wishlist",
        "/account/export",
    ];

    for path in account_routes {
        let req = Request::builder()
            .uri(path)
            .header(header::COOKIE, &cookie)
            .body(Body::empty())
            .unwrap();
        let res = router.clone().oneshot(req).await.unwrap();
        let status = res.status();
        assert!(
            status == StatusCode::OK || status == StatusCode::SEE_OTHER,
            "Account route failed: {} returned status {}",
            path,
            status
        );
    }
}

#[tokio::test]
async fn test_audit_admin_pages_authenticated() {
    let (state, router) = setup_test_app().await;
    let cookie = get_admin_auth_cookie(&router, &state).await;

    let admin_pages = vec![
        "/admin/login.php",
        "/admin/dashboard.php",
        "/admin/dashboard",
        "/admin/profile.php",
        "/admin/activity.php",
        "/admin/products.php",
        "/admin/product_form.php",
        "/admin/product_import.php",
        "/admin/categories.php",
        "/admin/category_form.php",
        "/admin/inventory.php",
        "/admin/orders.php",
        "/admin/customers.php",
        "/admin/reviews.php",
        "/admin/messages.php",
        "/admin/subscribers.php",
        "/admin/banners.php",
        "/admin/banner_form.php",
        "/admin/pages.php",
        "/admin/page_form.php",
        "/admin/users.php",
        "/admin/user_form.php",
        "/admin/settings.php",
        "/admin/mail_settings.php",
        "/admin/mail_templates.php",
        "/admin/mail_queue.php",
        "/admin/monitoring.php",
        "/admin/analytics.php",
        "/admin/analytics_product.php?id=3",
    ];

    for path in admin_pages {
        let req = Request::builder()
            .uri(path)
            .header(header::COOKIE, &cookie)
            .body(Body::empty())
            .unwrap();
        let res = router.clone().oneshot(req).await.unwrap();
        let status = res.status();
        if status != StatusCode::OK && !(path == "/admin/login.php" && status == StatusCode::SEE_OTHER) {
            let bytes = axum::body::to_bytes(res.into_body(), usize::MAX).await.unwrap();
            panic!("Admin page audit failed: {} returned status {} - body: {}", path, status, String::from_utf8_lossy(&bytes));
        }
    }
}

#[tokio::test]
async fn test_audit_admin_ajax_and_public_apis() {
    let (state, router) = setup_test_app().await;
    let cookie = get_admin_auth_cookie(&router, &state).await;

    // Public APIs
    let api_routes = vec![
        "/api/cart_ajax.php",
        "/api/availability.php?product_id=3",
        "/api/search_suggest.php?q=test",
        "/api/wishlist.php",
    ];
    for path in api_routes {
        let req = Request::builder().uri(path).body(Body::empty()).unwrap();
        let res = router.clone().oneshot(req).await.unwrap();
        let status = res.status();
        assert_eq!(
            status,
            StatusCode::OK,
            "Public API failed: {} returned status {}",
            path,
            status
        );
    }

    // Admin AJAX endpoints
    let admin_ajax_routes = vec![
        "/admin/ajax/stock_update.php",
        "/admin/ajax/option_presets.php",
        "/admin/ajax/monitor_data.php",
        "/admin/ajax/analytics_data.php",
    ];
    for path in admin_ajax_routes {
        let req = Request::builder()
            .uri(path)
            .header(header::COOKIE, &cookie)
            .body(Body::empty())
            .unwrap();
        let res = router.clone().oneshot(req).await.unwrap();
        let status = res.status();
        assert_eq!(
            status,
            StatusCode::OK,
            "Admin AJAX failed: {} returned status {}",
            path,
            status
        );
    }
}
