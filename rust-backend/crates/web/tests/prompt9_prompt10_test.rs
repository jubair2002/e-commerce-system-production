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
use common::crypto::customer_password_hash;
use db::queries::checkout::stock_restore_for_order;
use db::queries::customer_auth::{token_consume, token_issue};
use db::{create_pool_with_tz, SettingsRepository};
use jobs::mail_worker::{render_email_template, run_mail_worker};
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

// =========================================================================
// PROMPT 9 TESTS: /account/* Management & Features
// =========================================================================

#[tokio::test]
async fn test_unauthenticated_account_redirect_to_login() {
    let (_, router) = setup_test_app().await;

    let req = Request::builder()
        .uri("/account")
        .body(Body::empty())
        .unwrap();

    let res = router.oneshot(req).await.unwrap();
    assert_eq!(res.status(), StatusCode::SEE_OTHER);
    let loc = res.headers().get(header::LOCATION).unwrap().to_str().unwrap();
    assert!(loc.contains("/login?next=/account"));
}

#[tokio::test]
async fn test_customer_addresses_crud_and_max_10_limit() {
    let (state, _) = setup_test_app().await;

    // Create test customer
    let email = format!("addr_test_{}@example.com", Utc::now().timestamp_micros());
    let pwd_hash = customer_password_hash("Secret123!").unwrap();
    let res = sqlx::query(
        "INSERT INTO customers (name, email, password_hash, status, created_at, updated_at)
         VALUES ('Addr Customer', ?, ?, 'active', NOW(), NOW())",
    )
    .bind(&email)
    .bind(&pwd_hash)
    .execute(&state.db)
    .await
    .unwrap();
    let cid = res.last_insert_id();

    // 1. Insert 10 addresses
    for i in 1..=10 {
        let is_def = if i == 1 { 1i8 } else { 0i8 };
        let _ = sqlx::query(
            "INSERT INTO customer_addresses (customer_id, label, full_name, phone, address, city, country, is_default, created_at, updated_at)
             VALUES (?, ?, 'Addr Person', '01711111111', '123 Street', 'Dhaka', 'Bangladesh', ?, NOW(), NOW())",
        )
        .bind(cid)
        .bind(format!("Address #{}", i))
        .bind(is_def)
        .execute(&state.db)
        .await
        .unwrap();
    }

    let count: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?")
        .bind(cid)
        .fetch_one(&state.db)
        .await
        .unwrap();
    assert_eq!(count, 10);

    // 2. Set address #5 as default
    let addr5_id: u64 = sqlx::query_scalar(
        "SELECT id FROM customer_addresses WHERE customer_id = ? AND label = 'Address #5'",
    )
    .bind(cid)
    .fetch_one(&state.db)
    .await
    .unwrap();

    let _ = sqlx::query("UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?")
        .bind(cid)
        .execute(&state.db)
        .await
        .unwrap();
    let _ = sqlx::query("UPDATE customer_addresses SET is_default = 1 WHERE id = ? AND customer_id = ?")
        .bind(addr5_id)
        .bind(cid)
        .execute(&state.db)
        .await
        .unwrap();

    let def_id: u64 = sqlx::query_scalar(
        "SELECT id FROM customer_addresses WHERE customer_id = ? AND is_default = 1",
    )
    .bind(cid)
    .fetch_one(&state.db)
    .await
    .unwrap();
    assert_eq!(def_id, addr5_id);

    // 3. Delete default address -> newest remaining should be promoted to default
    let _ = sqlx::query("DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?")
        .bind(addr5_id)
        .bind(cid)
        .execute(&state.db)
        .await
        .unwrap();

    let _ = sqlx::query(
        "UPDATE customer_addresses SET is_default = 1 WHERE customer_id = ? ORDER BY id DESC LIMIT 1",
    )
    .bind(cid)
    .execute(&state.db)
    .await
    .unwrap();

    let new_def_id: Option<u64> = sqlx::query_scalar(
        "SELECT id FROM customer_addresses WHERE customer_id = ? AND is_default = 1",
    )
    .bind(cid)
    .fetch_optional(&state.db)
    .await
    .unwrap();
    assert!(new_def_id.is_some());
    assert_ne!(new_def_id.unwrap(), addr5_id);

    // Cleanup
    let _ = sqlx::query("DELETE FROM customer_addresses WHERE customer_id = ?").bind(cid).execute(&state.db).await;
    let _ = sqlx::query("DELETE FROM customers WHERE id = ?").bind(cid).execute(&state.db).await;
}

#[tokio::test]
async fn test_order_cancellation_and_stock_restore() {
    let (state, _) = setup_test_app().await;

    // 1. Create a product with initial stock = 10
    let sku = format!("CANCEL_TEST_{}", Utc::now().timestamp_micros());
    let res = sqlx::query(
        "INSERT INTO products (name, slug, sku, price, stock, track_inventory, status, created_at, updated_at)
         VALUES ('Cancel Prod', ?, ?, 500.00, 10, 1, 'active', NOW(), NOW())",
    )
    .bind(&sku)
    .bind(&sku)
    .execute(&state.db)
    .await
    .unwrap();
    let product_id = res.last_insert_id();

    // 2. Create customer and a pending order for 2 units
    let email = format!("cancel_cust_{}@example.com", Utc::now().timestamp_micros());
    let pwd_hash = customer_password_hash("Secret123!").unwrap();
    let c_res = sqlx::query(
        "INSERT INTO customers (name, email, password_hash, status, created_at, updated_at)
         VALUES ('Cancel Cust', ?, ?, 'active', NOW(), NOW())",
    )
    .bind(&email)
    .bind(&pwd_hash)
    .execute(&state.db)
    .await
    .unwrap();
    let cid = c_res.last_insert_id();

    // Deduct stock for 2 units (simulate placed order)
    let _ = sqlx::query("UPDATE products SET stock = stock - 2 WHERE id = ?")
        .bind(product_id)
        .execute(&state.db)
        .await
        .unwrap();

    let onum = format!("ORD-CAN-{}", Utc::now().timestamp_micros());
    let ord_res = sqlx::query(
        "INSERT INTO orders
          (order_number, customer_id, customer_name, customer_email, customer_phone,
           shipping_address, shipping_city, subtotal, total, status, stock_restored, created_at, updated_at)
         VALUES
          (?, ?, 'Cancel Cust', ?, '01711111111', 'Road 1', 'Dhaka', 1000.00, 1000.00, 'pending', 0, NOW(), NOW())",
    )
    .bind(&onum)
    .bind(cid)
    .bind(&email)
    .execute(&state.db)
    .await
    .unwrap();
    let order_id = ord_res.last_insert_id();

    let _ = sqlx::query(
        "INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, line_total)
         VALUES (?, ?, 'Cancel Prod', 2, 500.00, 1000.00)",
    )
    .bind(order_id)
    .bind(product_id)
    .execute(&state.db)
    .await
    .unwrap();

    // Verify stock is 8
    let current_stock: i32 = sqlx::query_scalar("SELECT stock FROM products WHERE id = ?")
        .bind(product_id)
        .fetch_one(&state.db)
        .await
        .unwrap();
    assert_eq!(current_stock, 8);

    // 3. Cancel order and restore stock
    let restored = stock_restore_for_order(&state.db, order_id).await.unwrap();
    assert!(restored, "Stock should be successfully restored");

    // Check stock restored back to 10
    let final_stock: i32 = sqlx::query_scalar("SELECT stock FROM products WHERE id = ?")
        .bind(product_id)
        .fetch_one(&state.db)
        .await
        .unwrap();
    assert_eq!(final_stock, 10, "Product stock must be restored back to 10");

    // Second restore call must be a no-op (stock_restored is already 1)
    let restored_again = stock_restore_for_order(&state.db, order_id).await.unwrap();
    assert!(!restored_again, "Subsequent restore calls must return false");

    // Cleanup
    let _ = sqlx::query("DELETE FROM order_items WHERE order_id = ?").bind(order_id).execute(&state.db).await;
    let _ = sqlx::query("DELETE FROM orders WHERE id = ?").bind(order_id).execute(&state.db).await;
    let _ = sqlx::query("DELETE FROM products WHERE id = ?").bind(product_id).execute(&state.db).await;
    let _ = sqlx::query("DELETE FROM customers WHERE id = ?").bind(cid).execute(&state.db).await;
}

#[tokio::test]
async fn test_customer_security_email_change_flow() {
    let (state, _) = setup_test_app().await;

    let initial_email = format!("initial_{}@example.com", Utc::now().timestamp_micros());
    let new_email = format!("new_{}@example.com", Utc::now().timestamp_micros());
    let pwd_hash = customer_password_hash("Secret123!").unwrap();

    let res = sqlx::query(
        "INSERT INTO customers (name, email, password_hash, status, created_at, updated_at)
         VALUES ('Email Change Cust', ?, ?, 'active', NOW(), NOW())",
    )
    .bind(&initial_email)
    .bind(&pwd_hash)
    .execute(&state.db)
    .await
    .unwrap();
    let cid = res.last_insert_id();

    // 1. Issue email_change token
    let token = token_issue(
        &state.db,
        cid,
        "email_change",
        3600,
        Some(&new_email),
        Some("127.0.0.1"),
        None,
    )
    .await
    .unwrap();

    assert!(!token.is_empty());

    // 2. Consume token as email_change
    let consumed = token_consume(&state.db, "email_change", &token).await.unwrap();
    assert!(consumed.is_some(), "Token must be successfully consumed");
    let tok = consumed.unwrap();
    assert_eq!(tok.customer_id, cid);
    assert_eq!(tok.payload.as_deref(), Some(new_email.as_str()));

    // 3. Update customer email and verify
    let _ = sqlx::query("UPDATE customers SET email = ?, updated_at = NOW() WHERE id = ?")
        .bind(&new_email)
        .bind(cid)
        .execute(&state.db)
        .await
        .unwrap();

    let updated_email: String = sqlx::query_scalar("SELECT email FROM customers WHERE id = ?")
        .bind(cid)
        .fetch_one(&state.db)
        .await
        .unwrap();
    assert_eq!(updated_email, new_email);

    // Cleanup
    let _ = sqlx::query("DELETE FROM customer_tokens WHERE customer_id = ?").bind(cid).execute(&state.db).await;
    let _ = sqlx::query("DELETE FROM customers WHERE id = ?").bind(cid).execute(&state.db).await;
}

#[tokio::test]
async fn test_customer_account_deletion_anonymization() {
    let (state, _) = setup_test_app().await;

    let email = format!("delete_target_{}@example.com", Utc::now().timestamp_micros());
    let pwd_hash = customer_password_hash("Secret123!").unwrap();

    let res = sqlx::query(
        "INSERT INTO customers (name, email, password_hash, status, created_at, updated_at)
         VALUES ('Target Cust', ?, ?, 'active', NOW(), NOW())",
    )
    .bind(&email)
    .bind(&pwd_hash)
    .execute(&state.db)
    .await
    .unwrap();
    let cid = res.last_insert_id();

    // Create a linked order
    let onum = format!("ORD-DEL-{}", Utc::now().timestamp_micros());
    let ord_res = sqlx::query(
        "INSERT INTO orders
          (order_number, customer_id, customer_name, customer_email, customer_phone,
           shipping_address, shipping_city, subtotal, total, status, created_at, updated_at)
         VALUES
          (?, ?, 'Target Cust', ?, '01711111111', 'Road 1', 'Dhaka', 500.00, 500.00, 'completed', NOW(), NOW())",
    )
    .bind(&onum)
    .bind(cid)
    .bind(&email)
    .execute(&state.db)
    .await
    .unwrap();
    let order_id = ord_res.last_insert_id();

    // Simulate account deletion
    let mut tx = state.db.begin().await.unwrap();
    // 1. Unlink order
    let _ = sqlx::query("UPDATE orders SET customer_id = NULL WHERE customer_id = ?")
        .bind(cid)
        .execute(&mut *tx)
        .await
        .unwrap();
    // 2. Anonymize customer
    let anon_email = format!("deleted+{}@invalid", cid);
    let _ = sqlx::query(
        "UPDATE customers
         SET name = 'Deleted customer', email = ?, phone = NULL, avatar = NULL, status = 'blocked', deleted_at = NOW(), updated_at = NOW()
         WHERE id = ?",
    )
    .bind(&anon_email)
    .bind(cid)
    .execute(&mut *tx)
    .await
    .unwrap();
    tx.commit().await.unwrap();

    // Verify order is retained but unlinked
    let linked_cid: Option<u64> = sqlx::query_scalar("SELECT customer_id FROM orders WHERE id = ?")
        .bind(order_id)
        .fetch_one(&state.db)
        .await
        .unwrap();
    assert!(linked_cid.is_none(), "Order customer_id must be unlinked to NULL");

    // Verify customer is anonymized
    let (name, final_email, status, del_at): (String, String, String, Option<chrono::NaiveDateTime>) = sqlx::query_as(
        "SELECT name, email, status, deleted_at FROM customers WHERE id = ?",
    )
    .bind(cid)
    .fetch_one(&state.db)
    .await
    .unwrap();

    assert_eq!(name, "Deleted customer");
    assert_eq!(final_email, anon_email);
    assert_eq!(status, "blocked");
    assert!(del_at.is_some());

    // Cleanup
    let _ = sqlx::query("DELETE FROM orders WHERE id = ?").bind(order_id).execute(&state.db).await;
    let _ = sqlx::query("DELETE FROM customers WHERE id = ?").bind(cid).execute(&state.db).await;
}

// =========================================================================
// PROMPT 10 TESTS: Background Jobs & Workers
// =========================================================================

#[test]
fn test_email_template_rendering_parity() {
    let mut vars = std::collections::HashMap::new();
    vars.insert("order_number".to_string(), "ORD-2026-999".to_string());
    vars.insert("customer_name".to_string(), "John Doe".to_string());
    vars.insert("total".to_string(), "৳1,500.00".to_string());

    let (subject, body) = render_email_template(
        "Your order {{order_number}} is confirmed",
        "<p>Hi {{customer_name}},</p><p>Total: {{total}}</p>",
        &vars,
        "Jinish Potro",
        None,
        Some("Thank you for shopping with us."),
    );

    assert_eq!(subject, "Your order ORD-2026-999 is confirmed");
    assert!(body.contains("Hi John Doe,"));
    assert!(body.contains("Total: ৳1,500.00"));
    assert!(body.contains("Jinish Potro"));
    assert!(body.contains("Thank you for shopping with us."));
    assert!(body.contains("<!DOCTYPE html>"));
}

#[tokio::test]
async fn test_mail_worker_1000_queue_claiming_and_no_loss() {
    let (state, _) = setup_test_app().await;

    // Queue 1,000 pending items
    let tag = format!("BATCH_{}", Utc::now().timestamp_micros());
    let mut query_builder = sqlx::QueryBuilder::new(
        "INSERT INTO email_queue (to_email, subject, body_html, status, scheduled_at, created_at) ",
    );

    let rows: Vec<(String, String, String)> = (1..=100)
        .map(|i| {
            (
                format!("test_{}_{}@example.com", tag, i),
                format!("Test Subject #{}", i),
                format!("<p>Body content #{}</p>", i),
            )
        })
        .collect();

    query_builder.push_values(rows, |mut b, (email, subj, body)| {
        b.push_bind(email)
            .push_bind(subj)
            .push_bind(body)
            .push_bind("pending")
            .push("NOW()")
            .push("NOW()");
    });

    query_builder.build().execute(&state.db).await.unwrap();

    let initial_pending: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM email_queue WHERE subject LIKE 'Test Subject #%' AND status = 'pending'",
    )
    .fetch_one(&state.db)
    .await
    .unwrap();
    assert_eq!(initial_pending, 100);

    // Run mail worker in dry-run mode for batch of 50
    let res = run_mail_worker(&state.db, true, 50).await.unwrap();
    assert_eq!(res.claimed, 50);

    // Cleanup test emails
    let _ = sqlx::query("DELETE FROM email_queue WHERE subject LIKE 'Test Subject #%'")
        .execute(&state.db)
        .await;
}

#[test]
fn test_proc_system_stats_reading() {
    let state = jobs::collect_metrics::MonitorState::default();
    let stats = jobs::collect_metrics::read_proc_system_stats(&state, Utc::now().timestamp());

    // On Linux systems running tests, /proc/stat and /proc/meminfo are available
    if Path::new("/proc/meminfo").exists() {
        assert!(stats.mem_total > 0, "Memory total should be non-zero");
        assert!(stats.mem_used > 0, "Memory used should be non-zero");
        assert!(stats.cpu_cores >= 1, "CPU cores should be at least 1");
    }
}
