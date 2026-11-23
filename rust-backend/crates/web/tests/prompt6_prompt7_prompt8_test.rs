use axum::{
    body::Body,
    http::{header, Request, StatusCode},
};
use chrono::Utc;
use common::config::Config;
use db::create_pool_with_tz;
use db::queries::cart::{calculate_totals, cart_key, HydratedCart};
use db::queries::checkout::{is_valid_status_transition, place_order, CheckoutFormData};
use db::queries::storage::{
    generate_image_variant, generate_storage_key, process_avatar_image, process_product_image,
    validate_svg_content,
};
use db::SettingsRepository;
use jobs::Mailer;
use rust_decimal::Decimal;
use serde::Deserialize;
use std::fs;
use std::path::Path;
use std::sync::Arc;
use tower::ServiceExt;
use tower_sessions::SessionManagerLayer;
use tower_sessions_sqlx_store::MySqlStore;
use web::build_router;
use web::init_templates;
use web::state::AppState;

fn fixture_path(filename: &str) -> String {
    let p1 = format!("../../../parity-tests/golden/fixtures/{}", filename);
    let p2 = format!("../../parity-tests/golden/fixtures/{}", filename);
    let p3 = format!("parity-tests/golden/fixtures/{}", filename);
    if Path::new(&p1).exists() {
        p1
    } else if Path::new(&p2).exists() {
        p2
    } else {
        p3
    }
}

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

    let session_store = MySqlStore::new(pool)
        .with_schema_name(&config.db_name)
        .expect("schema");
    let _ = session_store.migrate().await;

    let router = build_router(state.clone())
        .layer(SessionManagerLayer::new(session_store).with_secure(false));

    (state, router)
}

// =========================================================================
// PROMPT 6 TESTS: Media Pipeline, Storage Manager & Assets Caching
// =========================================================================

#[test]
fn test_storage_key_generation_scheme() {
    let key1 = generate_storage_key("avatar", "webp");
    let key2 = generate_storage_key("product", "jpg");
    let key3 = generate_storage_key("../bad/purpose..", ".png");

    let now_ym = chrono::Utc::now().format("%Y/%m").to_string();
    assert!(key1.starts_with(&format!("avatar/{}/", now_ym)));
    assert!(key2.starts_with(&format!("product/{}/", now_ym)));
    assert!(!key3.contains(".."));
    assert!(key3.starts_with(&format!("badpurpose/{}/", now_ym)));
    assert_ne!(key1, key2);
}

#[test]
fn test_storage_svg_script_rejection() {
    let clean_svg = b"<svg xmlns=\"http://www.w3.org/2000/svg\"><circle cx=\"10\" cy=\"10\" r=\"5\"/></svg>";
    assert!(validate_svg_content(clean_svg).is_ok());

    let xss_svg1 = b"<svg xmlns=\"http://www.w3.org/2000/svg\"><script>alert(1)</script></svg>";
    assert!(validate_svg_content(xss_svg1).is_err());

    let xss_svg2 = b"<svg onload=\"alert('xss')\"></svg>";
    assert!(validate_svg_content(xss_svg2).is_err());

    let xss_svg3 = b"<svg><a href=\"javascript:alert(1)\">click</a></svg>";
    assert!(validate_svg_content(xss_svg3).is_err());
}

#[test]
fn test_image_processing_avatar_and_product() {
    // Create an in-memory 200x100 RGB image
    let img = image::RgbImage::from_fn(200, 100, |x, y| {
        image::Rgb([(x % 255) as u8, (y % 255) as u8, 128])
    });
    let mut bytes: Vec<u8> = Vec::new();
    let mut cursor = std::io::Cursor::new(&mut bytes);
    img.write_to(&mut cursor, image::ImageFormat::Jpeg).unwrap();

    // 1. Process avatar: crops and resizes to 400x400 WebP
    let (avatar_webp, w, h) = process_avatar_image(&bytes).unwrap();
    assert_eq!(w, 400);
    assert_eq!(h, 400);
    assert!(!avatar_webp.is_empty());

    // 2. Process product: keeps max width 1800, WebP
    let (prod_webp, pw, ph) = process_product_image(&bytes).unwrap();
    assert_eq!(pw, 200);
    assert_eq!(ph, 100);
    assert!(!prod_webp.is_empty());

    // 3. Responsive variant: thumb 300px
    let (thumb_webp, tw, _) = generate_image_variant(&bytes, 300, 82.0).unwrap();
    assert_eq!(tw, 200); // Original width was 200, so not enlarged
    assert!(!thumb_webp.is_empty());
}

#[tokio::test]
async fn test_assets_serving_immutable_cache_header() {
    let (_, router) = setup_test_app().await;

    let req = Request::builder()
        .uri("/assets/coza/css/main.css")
        .body(Body::empty())
        .unwrap();

    let res = router.oneshot(req).await.unwrap();
    if res.status() == StatusCode::OK {
        let cache_control = res
            .headers()
            .get(header::CACHE_CONTROL)
            .and_then(|h| h.to_str().ok());
        assert_eq!(cache_control, Some("public, max-age=31536000, immutable"));
    }
}

// =========================================================================
// PROMPT 7 TESTS: Public Read Paths & Public JSON APIs
// =========================================================================

#[tokio::test]
async fn test_robots_txt_endpoint() {
    let (_, router) = setup_test_app().await;

    let req = Request::builder()
        .uri("/robots.txt")
        .body(Body::empty())
        .unwrap();

    let res = router.oneshot(req).await.unwrap();
    assert_eq!(res.status(), StatusCode::OK);
    let bytes = axum::body::to_bytes(res.into_body(), usize::MAX).await.unwrap();
    let text = String::from_utf8_lossy(&bytes);
    assert!(text.contains("User-agent: *"));
    assert!(text.contains("Disallow: /admin/"));
    assert!(text.contains("Sitemap:"));
}

#[tokio::test]
async fn test_availability_etag_and_304() {
    let (_, router) = setup_test_app().await;

    let req1 = Request::builder()
        .uri("/api/availability?ids=1,2")
        .body(Body::empty())
        .unwrap();

    let res1 = router.clone().oneshot(req1).await.unwrap();
    assert_eq!(res1.status(), StatusCode::OK);
    let etag = res1
        .headers()
        .get(header::ETAG)
        .and_then(|h| h.to_str().ok())
        .expect("ETag header should be present")
        .to_string();

    // Second request with If-None-Match should return 304 NOT_MODIFIED
    let req2 = Request::builder()
        .uri("/api/availability?ids=1,2")
        .header(header::IF_NONE_MATCH, etag)
        .body(Body::empty())
        .unwrap();

    let res2 = router.oneshot(req2).await.unwrap();
    assert_eq!(res2.status(), StatusCode::NOT_MODIFIED);
}

#[tokio::test]
async fn test_metrics_token_authentication() {
    let (_state, router) = setup_test_app().await;

    // Set METRICS_TOKEN
    std::env::set_var("METRICS_TOKEN", "secret-metrics-token-12345");

    // 1. Without token -> 401 Unauthorized
    let req_unauth = Request::builder()
        .uri("/metrics")
        .body(Body::empty())
        .unwrap();
    let res_unauth = router.clone().oneshot(req_unauth).await.unwrap();
    assert_eq!(res_unauth.status(), StatusCode::UNAUTHORIZED);

    // 2. With valid Bearer token -> 200 OK with Prometheus gauges
    let req_auth = Request::builder()
        .uri("/metrics")
        .header(header::AUTHORIZATION, "Bearer secret-metrics-token-12345")
        .body(Body::empty())
        .unwrap();
    let res_auth = router.oneshot(req_auth).await.unwrap();
    assert_eq!(res_auth.status(), StatusCode::OK);
    let bytes = axum::body::to_bytes(res_auth.into_body(), usize::MAX).await.unwrap();
    let text = String::from_utf8_lossy(&bytes);
    assert!(text.contains("ecommerce_database_up"));
    assert!(text.contains("ecommerce_orders_total"));

    // Reset env
    std::env::remove_var("METRICS_TOKEN");
}

// =========================================================================
// PROMPT 8 TESTS: Totals, Golden Vectors, Concurrency & State Parity
// =========================================================================

#[derive(Deserialize)]
struct GoldenCartItem {
    pub product_id: u64,
    pub variant_id: Option<u64>,
    pub price: Decimal,
    pub qty: i32,
    pub line_total: Decimal,
}

#[derive(Deserialize)]
struct GoldenCart {
    pub items: Vec<GoldenCartItem>,
    pub subtotal: Decimal,
}

#[derive(Deserialize)]
#[allow(dead_code)]
struct GoldenCartResult {
    pub subtotal: Decimal,
    pub discount: Decimal,
    pub discounted_subtotal: Decimal,
    pub coupon_valid: bool,
    pub coupon_error: Option<String>,
    pub shipping_fee: Decimal,
    pub cod_fee: Decimal,
    pub total: Decimal,
}

#[derive(Deserialize)]
struct GoldenCartScenario {
    pub scenario_id: usize,
    pub cart: GoldenCart,
    pub coupon_code: Option<String>,
    pub shipping_method_id: Option<u64>,
    pub payment_method: String,
    pub result: GoldenCartResult,
}

#[derive(Deserialize)]
#[allow(dead_code)]
struct ShippingDistrictFixture {
    pub district: String,
    pub zone: String,
    pub method_id: u64,
    pub standard_fee: Decimal,
    pub calculated_fee_normal: Decimal,
    pub calculated_fee_free_threshold: Decimal,
}

#[tokio::test]
async fn test_golden_cart_totals_fixtures() {
    let (state, _) = setup_test_app().await;

    let path = fixture_path("cart_totals.json");
    let content = fs::read_to_string(&path)
        .unwrap_or_else(|_| panic!("Failed to read cart_totals.json at {}", path));
    let scenarios: Vec<GoldenCartScenario> = serde_json::from_str(&content).unwrap();

    for s in scenarios.iter().take(50) {
        let hydrated = HydratedCart {
            items: s
                .cart
                .items
                .iter()
                .map(|item| db::queries::cart::CartItem {
                    cart_key: cart_key(item.product_id, item.variant_id),
                    product_id: item.product_id,
                    variant_id: item.variant_id,
                    qty: item.qty,
                    unit_price: item.price,
                    line_total: item.line_total,
                    product_name: "Fixture Item".to_string(),
                    variant_label: None,
                    image: None,
                    image_url: "/placeholder.svg".to_string(),
                    slug: "fixture".to_string(),
                    available_qty: 100,
                    formatted_price: format!("৳{}", item.price),
                    formatted_line_total: format!("৳{}", item.line_total),
                })
                .collect(),
            subtotal: s.cart.subtotal,
            formatted_subtotal: format!("৳{}", s.cart.subtotal),
            total_count: s.cart.items.iter().map(|i| i.qty).sum(),
            alerts: Vec::new(),
        };

        let totals = calculate_totals(
            &state.db,
            &hydrated,
            s.shipping_method_id,
            s.coupon_code.as_deref(),
            &s.payment_method,
            &state.settings,
        )
        .await
        .unwrap();

        assert_eq!(
            totals.subtotal, s.result.subtotal,
            "Scenario #{}: subtotal mismatch",
            s.scenario_id
        );
        assert_eq!(
            totals.discount, s.result.discount,
            "Scenario #{}: discount mismatch",
            s.scenario_id
        );
        assert_eq!(
            totals.discounted_subtotal, s.result.discounted_subtotal,
            "Scenario #{}: discounted_subtotal mismatch",
            s.scenario_id
        );
        assert_eq!(
            totals.shipping_fee, s.result.shipping_fee,
            "Scenario #{}: shipping_fee mismatch",
            s.scenario_id
        );
        assert_eq!(
            totals.cod_fee, s.result.cod_fee,
            "Scenario #{}: cod_fee mismatch",
            s.scenario_id
        );
        assert_eq!(
            totals.total, s.result.total,
            "Scenario #{}: total mismatch",
            s.scenario_id
        );
    }
}

#[tokio::test]
async fn test_golden_shipping_districts_fixtures() {
    let (_state, _) = setup_test_app().await;

    let path = fixture_path("shipping_districts.json");
    let content = fs::read_to_string(&path)
        .unwrap_or_else(|_| panic!("Failed to read shipping_districts.json at {}", path));
    let districts: Vec<ShippingDistrictFixture> = serde_json::from_str(&content).unwrap();

    assert!(districts.len() >= 63, "All districts must be present in fixtures");

    for d in districts {
        // District Dhaka has method 1 (inside dhaka), all 63 others have method 2 (outside dhaka)
        if d.district.eq_ignore_ascii_case("Dhaka") {
            assert_eq!(d.method_id, 1);
            assert_eq!(d.standard_fee, Decimal::from(60));
        } else {
            assert_eq!(d.method_id, 2);
            assert_eq!(d.standard_fee, Decimal::from(120));
        }
    }
}

#[test]
fn test_order_status_transitions() {
    // Valid transitions
    assert!(is_valid_status_transition("pending", "processing"));
    assert!(is_valid_status_transition("pending", "cancelled"));
    assert!(is_valid_status_transition("processing", "shipped"));
    assert!(is_valid_status_transition("processing", "cancelled"));
    assert!(is_valid_status_transition("shipped", "completed"));
    assert!(is_valid_status_transition("shipped", "cancelled"));
    assert!(is_valid_status_transition("completed", "cancelled"));
    assert!(is_valid_status_transition("pending", "pending"));

    // Invalid transitions
    assert!(!is_valid_status_transition("pending", "completed"));
    assert!(!is_valid_status_transition("cancelled", "pending"));
    assert!(!is_valid_status_transition("cancelled", "processing"));
    assert!(!is_valid_status_transition("completed", "processing"));
}

#[tokio::test]
async fn test_concurrency_100_checkouts_1_unit_stock() {
    let (state, _) = setup_test_app().await;

    // 1. Create a dedicated test product with stock = 1
    let slug = format!("concurrency-test-prod-{}", Utc::now().timestamp_nanos_opt().unwrap_or(0));
    let res = sqlx::query(
        "INSERT INTO products (name, slug, sku, price, stock, track_inventory, allow_backorder, status, created_at, updated_at)
         VALUES ('Concurrency Test Item', ?, ?, 100.00, 1, 1, 0, 'active', NOW(), NOW())"
    )
    .bind(&slug)
    .bind(format!("SKU-{}", slug))
    .execute(&state.db)
    .await
    .expect("Failed to insert concurrency test product");

    let product_id = res.last_insert_id();

    let cart_item = db::queries::cart::CartItem {
        cart_key: format!("{}:0", product_id),
        product_id,
        variant_id: None,
        qty: 1,
        unit_price: Decimal::from(100),
        line_total: Decimal::from(100),
        product_name: "Concurrency Test Item".to_string(),
        variant_label: None,
        image: None,
        image_url: "/placeholder.svg".to_string(),
        slug: slug.clone(),
        available_qty: 1,
        formatted_price: "৳100.00".to_string(),
        formatted_line_total: "৳100.00".to_string(),
    };

    let hydrated_cart = HydratedCart {
        items: vec![cart_item],
        subtotal: Decimal::from(100),
        formatted_subtotal: "৳100.00".to_string(),
        total_count: 1,
        alerts: Vec::new(),
    };

    let totals = calculate_totals(
        &state.db,
        &hydrated_cart,
        Some(1),
        None,
        "cod",
        &state.settings,
    )
    .await
    .unwrap();

    let mut handles = Vec::new();

    // Spawn 100 concurrent checkout attempts against the 1 unit of stock
    for i in 0..100 {
        let pool = state.db.clone();
        let cart = hydrated_cart.clone();
        let tot = totals.clone();
        let form = CheckoutFormData {
            customer_name: format!("Concurrent Buyer {}", i),
            customer_email: format!("buyer{}@example.com", i),
            customer_phone: Some("01700000000".to_string()),
            shipping_address: "123 Test St".to_string(),
            shipping_city: Some("Dhaka".to_string()),
            shipping_zip: Some("1200".to_string()),
            shipping_country: Some("Bangladesh".to_string()),
            shipping_method_id: Some(1),
            payment_method: "cod".to_string(),
            coupon_code: None,
            notes: None,
        };

        handles.push(tokio::spawn(async move {
            place_order(&pool, form, &cart, &tot, None, "CONC", Some("127.0.0.1")).await
        }));
    }

    let mut successes = 0;
    let mut failures = 0;

    for h in handles {
        match h.await.unwrap() {
            Ok(_) => successes += 1,
            Err(_) => failures += 1,
        }
    }

    // Verify EXACTLY 1 checkout succeeded, and 99 failed with stock insufficiency
    assert_eq!(successes, 1, "Exactly one concurrent checkout should succeed");
    assert_eq!(failures, 99, "99 concurrent checkouts should fail due to stock depletion");

    // Verify final stock is 0 (no oversell, no negative stock)
    let final_stock: i32 = sqlx::query_scalar("SELECT stock FROM products WHERE id = ?")
        .bind(product_id)
        .fetch_one(&state.db)
        .await
        .unwrap();

    assert_eq!(final_stock, 0, "Final stock in DB must be exactly 0");

    // Clean up test product
    let _ = sqlx::query("DELETE FROM products WHERE id = ?").bind(product_id).execute(&state.db).await;
}
