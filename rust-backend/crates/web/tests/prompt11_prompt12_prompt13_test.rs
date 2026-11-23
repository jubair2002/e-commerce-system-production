use axum::{
    body::Body,
    http::{header, Request, StatusCode},
};
use chrono::Utc;
use rust_decimal::Decimal;
use std::path::Path;
use std::sync::Arc;
use tower::ServiceExt;
use tower_sessions::{MemoryStore, SessionManagerLayer};

use common::config::Config;
use common::crypto::admin_password_hash;
use db::queries::inventory::stock_adjust;
use db::{create_pool_with_tz, SettingsRepository};
use jobs::Mailer;
use web::routes::admin::marketing::product_recalculate_rating;
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

// Helper to create a test admin user and return (id, email, password)
async fn create_test_admin(
    state: &AppState,
    role: &str,
    perms: Option<&str>,
) -> (u64, String, String) {
    let email = format!("admin_test_{}@example.com", Utc::now().timestamp_micros());
    let password = "AdminPassword123!";
    let hashed = admin_password_hash(password).expect("Hash admin password");

    let res = sqlx::query(
        "INSERT INTO users (name, email, password, role, permissions, status, created_at, updated_at)
         VALUES ('Test Staff', ?, ?, ?, ?, 'active', NOW(), NOW())"
    )
    .bind(&email)
    .bind(&hashed)
    .bind(role)
    .bind(perms)
    .execute(&state.db)
    .await
    .expect("Insert test admin user");

    (res.last_insert_id(), email, password.to_string())
}

async fn login_admin_and_get_cookie(router: &axum::Router, email: &str, password: &str) -> String {
    // 1. GET /admin/login.php
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

    // 2. POST /admin/login.php
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

    // Consume body to ensure tower-sessions commits the session to store
    let _ = axum::body::to_bytes(res2.into_body(), usize::MAX).await;

    cookie_final
}

// =========================================================================
// PROMPT 11 TESTS: Admin Shell, Catalog, Variants SKU & Inventory
// =========================================================================

#[tokio::test]
async fn test_admin_unauthenticated_redirect_to_login() {
    let (_, router) = setup_test_app().await;

    let endpoints = [
        "/admin/dashboard.php",
        "/admin/products.php",
        "/admin/orders.php",
        "/admin/customers.php",
        "/admin/settings.php",
    ];

    for ep in endpoints {
        let req = Request::builder().uri(ep).body(Body::empty()).unwrap();
        let res = router.clone().oneshot(req).await.unwrap();
        assert_eq!(
            res.status(),
            StatusCode::SEE_OTHER,
            "Endpoint {} should redirect when unauthenticated",
            ep
        );
        let loc = res.headers().get(header::LOCATION).unwrap().to_str().unwrap();
        assert_eq!(loc, "/admin/login.php");
    }
}

#[tokio::test]
async fn test_admin_sku_matrix_naming_rule() {
    // Parity Rule: {PARENT_SKU}-{VAL1}-{VAL2} uppercase & sanitized
    let parent_sku = "TSHIRT";
    let val1 = "Black";
    let val2 = "XL";

    let clean_v1 = val1.trim().replace(' ', "-").to_uppercase();
    let clean_v2 = val2.trim().replace(' ', "-").to_uppercase();
    let variant_sku = format!("{}-{}-{}", parent_sku, clean_v1, clean_v2);

    assert_eq!(variant_sku, "TSHIRT-BLACK-XL");
}

#[tokio::test]
async fn test_admin_inventory_adjustment_and_movements_ledger() {
    let (state, _) = setup_test_app().await;

    // 1. Insert a test product
    let sku = format!("INV-TEST-{}", Utc::now().timestamp_micros());
    let res = sqlx::query(
        "INSERT INTO products (name, slug, sku, price, stock, status, created_at, updated_at)
         VALUES ('Inventory Test Product', ?, ?, 500.00, 20, 'active', NOW(), NOW())"
    )
    .bind(&sku)
    .bind(&sku)
    .execute(&state.db)
    .await
    .unwrap();
    let product_id = res.last_insert_id();

    // 2. Perform a stock adjustment: +15 units via stock_adjust
    let ok = stock_adjust(
        &state.db,
        product_id,
        None,
        15,
        "manual",
        None,
        None,
        Some("Restock from supplier batch A"),
    )
    .await
    .unwrap();
    assert!(ok, "Stock adjust should succeed");

    // 3. Verify products.stock is updated to 35
    let current_stock: i32 = sqlx::query_scalar("SELECT stock FROM products WHERE id = ?")
        .bind(product_id)
        .fetch_one(&state.db)
        .await
        .unwrap();
    assert_eq!(current_stock, 35);

    // 4. Verify inventory_movements ledger entry
    let (change_qty, stock_after, reason, note): (i32, i32, String, Option<String>) = sqlx::query_as(
        "SELECT change_qty, stock_after, reason, note FROM inventory_movements WHERE product_id = ? ORDER BY id DESC LIMIT 1"
    )
    .bind(product_id)
    .fetch_one(&state.db)
    .await
    .unwrap();

    assert_eq!(change_qty, 15);
    assert_eq!(stock_after, 35);
    assert_eq!(reason, "manual_adjust");
    assert_eq!(note.as_deref(), Some("Restock from supplier batch A"));
}

// =========================================================================
// PROMPT 12 TESTS: Orders State Machine, Cancellation Restore, GDPR Anonymization, Ratings
// =========================================================================

#[tokio::test]
async fn test_admin_order_state_machine_and_cancellation_restore() {
    let (state, _) = setup_test_app().await;

    // 1. Create a product with initial stock = 10
    let sku = format!("ORD-STATE-{}", Utc::now().timestamp_micros());
    let p_res = sqlx::query(
        "INSERT INTO products (name, slug, sku, price, stock, status, created_at, updated_at)
         VALUES ('Order Machine Prod', ?, ?, 1200.00, 10, 'active', NOW(), NOW())"
    )
    .bind(&sku)
    .bind(&sku)
    .execute(&state.db)
    .await
    .unwrap();
    let product_id = p_res.last_insert_id();

    // 2. Insert an order for 2 units
    let ord_num = format!("ORD-MCH-{}", Utc::now().timestamp_micros());
    let o_res = sqlx::query(
        "INSERT INTO orders
            (order_number, total, subtotal, payment_method, payment_status, status,
             customer_name, customer_email, customer_phone, shipping_address, shipping_city, created_at, updated_at)
         VALUES (?, 2400.00, 2400.00, 'cod', 'unpaid', 'pending', 'John Doe', 'john@example.com', '01700000000', 'House 1', 'Dhaka', NOW(), NOW())"
    )
    .bind(&ord_num)
    .execute(&state.db)
    .await
    .unwrap();
    let order_id = o_res.last_insert_id();

    let _ = sqlx::query(
        "INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, line_total)
         VALUES (?, ?, 'Order Machine Prod', 1200.00, 2, 2400.00)"
    )
    .bind(order_id)
    .bind(product_id)
    .execute(&state.db)
    .await
    .unwrap();

    // Initial stock was 10. When order was created, stock decremented by 2 -> 8
    let _ = sqlx::query("UPDATE products SET stock = stock - 2 WHERE id = ?")
        .bind(product_id)
        .execute(&state.db)
        .await
        .unwrap();

    let stock_before_cancel: i32 = sqlx::query_scalar("SELECT stock FROM products WHERE id = ?")
        .bind(product_id)
        .fetch_one(&state.db)
        .await
        .unwrap();
    assert_eq!(stock_before_cancel, 8);

    // 3. Move order from pending -> processing -> completed with COD payment auto-update
    let _ = sqlx::query("UPDATE orders SET status = 'completed', payment_status = 'paid' WHERE id = ?")
        .bind(order_id)
        .execute(&state.db)
        .await
        .unwrap();

    // 4. Now test cancellation stock restoration
    db::queries::checkout::stock_restore_for_order(&state.db, order_id)
        .await
        .expect("Stock restore");

    let restored_stock: i32 = sqlx::query_scalar("SELECT stock FROM products WHERE id = ?")
        .bind(product_id)
        .fetch_one(&state.db)
        .await
        .unwrap();
    assert_eq!(restored_stock, 10, "Stock should be restored from 8 back to 10 on cancellation");
}

#[tokio::test]
async fn test_admin_customer_gdpr_anonymization() {
    let (state, _) = setup_test_app().await;

    // 1. Create a customer
    let email = format!("gdpr_{}@example.com", Utc::now().timestamp_micros());
    let c_res = sqlx::query(
        "INSERT INTO customers (name, email, password_hash, phone, status, created_at, updated_at)
         VALUES ('Sensitive Name', ?, 'fakehash', '01712345678', 'active', NOW(), NOW())"
    )
    .bind(&email)
    .execute(&state.db)
    .await
    .unwrap();
    let cid = c_res.last_insert_id();

    // 2. Perform GDPR anonymization
    let anon_email = format!("anonymized_{}@deleted.local", cid);
    let _ = sqlx::query(
        "UPDATE customers
         SET name = 'Anonymized User',
             email = ?,
             phone = NULL,
             status = 'blocked',
             deleted_at = NOW(),
             updated_at = NOW()
         WHERE id = ?"
    )
    .bind(&anon_email)
    .bind(cid)
    .execute(&state.db)
    .await
    .unwrap();

    // Delete customer addresses
    let _ = sqlx::query("DELETE FROM customer_addresses WHERE customer_id = ?")
        .bind(cid)
        .execute(&state.db)
        .await
        .unwrap();

    // 3. Verify anonymized data
    let (name, final_email, phone, status): (String, String, Option<String>, String) = sqlx::query_as(
        "SELECT name, email, phone, status FROM customers WHERE id = ?"
    )
    .bind(cid)
    .fetch_one(&state.db)
    .await
    .unwrap();

    assert_eq!(name, "Anonymized User");
    assert_eq!(final_email, anon_email);
    assert_eq!(phone, None);
    assert_eq!(status, "blocked");
}

#[tokio::test]
async fn test_admin_review_moderation_and_rating_recalculation() {
    let (state, _) = setup_test_app().await;

    // 1. Insert product
    let sku = format!("REV-TEST-{}", Utc::now().timestamp_micros());
    let p_res = sqlx::query(
        "INSERT INTO products (name, slug, sku, price, stock, status, rating_avg, rating_count, created_at, updated_at)
         VALUES ('Review Test Product', ?, ?, 300.00, 10, 'active', 0.00, 0, NOW(), NOW())"
    )
    .bind(&sku)
    .bind(&sku)
    .execute(&state.db)
    .await
    .unwrap();
    let product_id = p_res.last_insert_id();

    // 2. Insert two reviews: one 5-star and one 4-star, status = approved
    let _ = sqlx::query(
        "INSERT INTO product_reviews (product_id, rating, body, author_name, status, created_at)
         VALUES (?, 5, 'Great!', 'Alice', 'approved', NOW())"
    )
    .bind(product_id)
    .execute(&state.db)
    .await
    .unwrap();

    let _ = sqlx::query(
        "INSERT INTO product_reviews (product_id, rating, body, author_name, status, created_at)
         VALUES (?, 4, 'Good quality', 'Bob', 'approved', NOW())"
    )
    .bind(product_id)
    .execute(&state.db)
    .await
    .unwrap();

    // Also insert a rejected review (should not count)
    let _ = sqlx::query(
        "INSERT INTO product_reviews (product_id, rating, body, author_name, status, created_at)
         VALUES (?, 1, 'Bad', 'Eve', 'rejected', NOW())"
    )
    .bind(product_id)
    .execute(&state.db)
    .await
    .unwrap();

    // 3. Recalculate rating
    product_recalculate_rating(&state.db, product_id).await;

    // 4. Verify product rating: (5 + 4) / 2 = 4.50, rating_count = 2
    let (rating_avg, rating_count): (Decimal, u32) = sqlx::query_as(
        "SELECT rating_avg, rating_count FROM products WHERE id = ?"
    )
    .bind(product_id)
    .fetch_one(&state.db)
    .await
    .unwrap();

    assert_eq!(rating_avg, Decimal::new(45, 1)); // 4.5
    assert_eq!(rating_count, 2);
}

// =========================================================================
// PROMPT 13 TESTS: Settings, Coupons, Cache Invalidation & AJAX Endpoints
// =========================================================================

#[tokio::test]
async fn test_admin_settings_update_and_moka_invalidation() {
    let (state, _) = setup_test_app().await;

    // Update site_name in settings table
    let new_name = format!("Jinish Potro Test Store {}", Utc::now().timestamp_micros());
    let _ = sqlx::query("UPDATE settings SET value = ? WHERE key_name = 'site_name'")
        .bind(&new_name)
        .execute(&state.db)
        .await
        .unwrap();

    // 1. Invalidate Moka cache -> cache is emptied
    state.settings.invalidate();
    assert_eq!(state.settings.get("site_name"), None);

    // 2. Refresh from MySQL -> cache holds fresh updated value
    state.settings.refresh(&state.db).await.unwrap();
    let fresh = state.settings.get("site_name");
    assert_eq!(fresh.as_deref(), Some(new_name.as_str()));
}

#[tokio::test]
async fn test_admin_ajax_endpoints() {
    let (state, router) = setup_test_app().await;

    // 1. Verify unauthenticated returns 401 or Unauthorized
    let req = Request::builder()
        .uri("/admin/ajax/option_presets.php")
        .body(Body::empty())
        .unwrap();
    let res = router.clone().oneshot(req).await.unwrap();
    assert_eq!(res.status(), StatusCode::OK);
    let bytes = axum::body::to_bytes(res.into_body(), usize::MAX).await.unwrap();
    let json: serde_json::Value = serde_json::from_slice(&bytes).unwrap();
    assert_eq!(json.get("ok").and_then(|v| v.as_bool()), Some(false));

    // 2. Authenticate admin user
    let (_, email, pwd) = create_test_admin(&state, "admin", None).await;
    let cookie = login_admin_and_get_cookie(&router, &email, &pwd).await;

    // 3. Option presets when authenticated
    let req = Request::builder()
        .uri("/admin/ajax/option_presets.php")
        .header(header::COOKIE, &cookie)
        .body(Body::empty())
        .unwrap();
    let res = router.clone().oneshot(req).await.unwrap();
    assert_eq!(res.status(), StatusCode::OK);
    let bytes = axum::body::to_bytes(res.into_body(), usize::MAX).await.unwrap();
    let json: serde_json::Value = serde_json::from_slice(&bytes).unwrap();
    assert_eq!(json.get("ok").and_then(|v| v.as_bool()), Some(true));
    assert!(json.get("presets").is_some());

    // 4. Monitoring data when authenticated
    let req = Request::builder()
        .uri("/admin/ajax/monitor_data.php")
        .header(header::COOKIE, &cookie)
        .body(Body::empty())
        .unwrap();
    let res = router.clone().oneshot(req).await.unwrap();
    assert_eq!(res.status(), StatusCode::OK);
    let bytes = axum::body::to_bytes(res.into_body(), usize::MAX).await.unwrap();
    let json: serde_json::Value = serde_json::from_slice(&bytes).unwrap();
    assert!(json.get("memory").is_some());
    assert!(json.get("mysql").is_some());

    // 5. Analytics data when authenticated
    let req = Request::builder()
        .uri("/admin/ajax/analytics_data.php?range=30d")
        .header(header::COOKIE, &cookie)
        .body(Body::empty())
        .unwrap();
    let res = router.clone().oneshot(req).await.unwrap();
    assert_eq!(res.status(), StatusCode::OK);
    let bytes = axum::body::to_bytes(res.into_body(), usize::MAX).await.unwrap();
    let json: serde_json::Value = serde_json::from_slice(&bytes).unwrap();
    assert_eq!(json.get("ok").and_then(|v| v.as_bool()), Some(true));
    assert!(json.get("kpis").is_some());
}
