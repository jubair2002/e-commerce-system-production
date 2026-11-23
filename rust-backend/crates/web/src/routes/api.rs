use axum::{
    extract::{Query, State},
    response::{
        sse::{Event, KeepAlive, Sse},
        IntoResponse, Json, Response,
    },
    Form,
};
use chrono::Utc;
use futures::stream::Stream;
use rust_decimal::Decimal;
use serde::Deserialize;
use std::{convert::Infallible, time::Duration};
use tower_sessions::Session;

use crate::state::{AppState, SessionHelper};
use common::crypto::generate_csrf_token;
use common::money::format_money;
use db::queries::cart::{
    cart_add, cart_clear, cart_load, cart_remove_key, cart_set_key, HydratedCart,
};
use db::queries::catalog::resolve_image_url;
use domain::models::product::Product;

#[derive(Debug, Deserialize)]
pub struct CartAjaxForm {
    pub action: Option<String>,
    pub product_id: Option<u64>,
    pub variant_id: Option<u64>,
    pub quantity: Option<i32>,
    pub cart_key: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct AvailabilityQuery {
    pub ids: Option<String>,
    pub id: Option<u64>,
    pub product_id: Option<u64>,
}

#[derive(Debug, Deserialize)]
pub struct SearchSuggestQuery {
    pub q: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct NewsletterApiForm {
    pub csrf_token: Option<String>,
    pub email: Option<String>,
    pub source: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct WishlistApiForm {
    pub _csrf: Option<String>,
    pub action: Option<String>,
    pub product_id: Option<u64>,
}

#[derive(Debug, Deserialize)]
pub struct WishlistApiQuery {
    pub action: Option<String>,
    pub product_id: Option<u64>,
}

#[derive(Debug, Deserialize)]
pub struct ReviewApiForm {
    pub csrf_token: Option<String>,
    pub website_hp: Option<String>,
    pub form_time: Option<i64>,
    pub product_id: Option<u64>,
    pub rating: Option<i32>,
    pub title: Option<String>,
    pub body: Option<String>,
    pub author_name: Option<String>,
}

// -------------------------------------------------------------
// GET /api/cart_ajax.php, GET /api/cart
// -------------------------------------------------------------
pub async fn cart_ajax_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Response {
    let raw_cart = SessionHelper::get_cart(&session).await;
    let currency_sym = state.settings.get_or("currency_symbol", "৳");
    let loaded = cart_load(&state.db, &raw_cart, &currency_sym)
        .await
        .unwrap_or(HydratedCart {
            items: Vec::new(),
            subtotal: Decimal::ZERO,
            formatted_subtotal: format_money(Some(Decimal::ZERO), &currency_sym),
            total_count: 0,
            alerts: Vec::new(),
        });

    build_cart_ajax_response("get", &loaded, None)
}

// -------------------------------------------------------------
// POST /api/cart_ajax.php, POST /api/cart
// -------------------------------------------------------------
pub async fn cart_ajax_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<CartAjaxForm>,
) -> Response {
    let action = form.action.unwrap_or_else(|| "get".to_string());
    let mut raw_cart = SessionHelper::get_cart(&session).await;

    if action == "add" {
        let pid = form.product_id.unwrap_or(0);
        let qty = form.quantity.unwrap_or(1).max(1);
        if pid > 0 {
            let prod: Option<Product> = sqlx::query_as(
                "SELECT * FROM products WHERE id = ? AND status = 'active' LIMIT 1",
            )
            .bind(pid)
            .fetch_optional(&state.db)
            .await
            .unwrap_or(None);

            if prod.is_some() {
                cart_add(&mut raw_cart, pid, form.variant_id, qty);
                SessionHelper::set_cart(&session, &raw_cart).await;
            } else {
                return (
                    axum::http::StatusCode::NOT_FOUND,
                    Json(serde_json::json!({ "ok": false, "error": "Product is no longer available." })),
                )
                    .into_response();
            }
        } else {
            return (
                axum::http::StatusCode::BAD_REQUEST,
                Json(serde_json::json!({ "ok": false, "error": "Invalid product." })),
            )
                .into_response();
        }
    } else if action == "update" {
        if let Some(ref key) = form.cart_key {
            let qty = form.quantity.unwrap_or(1).max(1);
            cart_set_key(&mut raw_cart, key, qty);
            SessionHelper::set_cart(&session, &raw_cart).await;
        }
    } else if action == "remove" {
        if let Some(ref key) = form.cart_key {
            cart_remove_key(&mut raw_cart, key);
            SessionHelper::set_cart(&session, &raw_cart).await;
        }
    } else if action == "clear" {
        cart_clear(&mut raw_cart);
        SessionHelper::set_cart(&session, &raw_cart).await;
    }

    let currency_sym = state.settings.get_or("currency_symbol", "৳");
    let loaded = cart_load(&state.db, &raw_cart, &currency_sym)
        .await
        .unwrap_or(HydratedCart {
            items: Vec::new(),
            subtotal: Decimal::ZERO,
            formatted_subtotal: format_money(Some(Decimal::ZERO), &currency_sym),
            total_count: 0,
            alerts: Vec::new(),
        });

    build_cart_ajax_response(&action, &loaded, None)
}

fn build_cart_ajax_response(
    action: &str,
    loaded: &HydratedCart,
    warning: Option<String>,
) -> Response {
    let mut coza_html = String::new();
    if loaded.items.is_empty() {
        coza_html.push_str("<div class=\"w-full text-center p-t-50 p-b-50\"><i class=\"zmdi zmdi-shopping-cart-plus fs-50 cl6 d-block m-b-15\" style=\"font-size: 48px;\"></i><p class=\"stext-107 cl6 m-b-20\">Your cart is currently empty.</p><a href=\"/shop\" class=\"flex-c-m stext-101 cl0 size-107 bg3 bor2 hov-btn3 p-lr-15 trans-04 m-auto\">Start Shopping</a></div>");
    } else {
        coza_html.push_str("<ul class=\"header-cart-wrapitem w-full\" id=\"cozaMiniCartList\">");
        for item in &loaded.items {
            let img = resolve_image_url(item.image.as_deref());
            let var_span = if let Some(ref vl) = item.variant_label {
                format!("<span class=\"stext-109 cl6 d-block fs-12 m-b-4\">{}</span>", vl)
            } else {
                String::new()
            };
            coza_html.push_str(&format!(
                "<li class=\"header-cart-item flex-w flex-t m-b-12\" data-cart-key=\"{}\"><div class=\"header-cart-item-img\" title=\"Remove item\"><img src=\"{}\" alt=\"{}\" style=\"width: 60px; height: 60px; object-fit: cover;\"></div><div class=\"header-cart-item-txt p-t-8\"><a href=\"/product/{}\" class=\"header-cart-item-name m-b-6 hov-cl1 trans-04 text-truncate d-block\" style=\"max-width: 170px;\">{}</a>{}<span class=\"header-cart-item-info\">{} &times; {}</span></div></li>",
                item.cart_key, img, item.product_name, item.slug, item.product_name, var_span, item.qty, item.formatted_price
            ));
        }
        coza_html.push_str(&format!(
            "</ul><div class=\"w-full\"><div class=\"header-cart-total w-full p-tb-30 fs-18 fw-bold\">Subtotal: <span class=\"cl1\" id=\"cozaMiniCartTotal\">{}</span></div><div class=\"header-cart-buttons flex-w w-full\"><a href=\"/cart\" class=\"flex-c-m stext-101 cl0 size-107 bg3 bor2 hov-btn3 p-lr-15 trans-04 m-r-8 m-b-10\">View Cart</a><a href=\"/checkout\" class=\"flex-c-m stext-101 cl0 size-107 bg3 bor2 hov-btn3 p-lr-15 trans-04 m-b-10\">Check Out</a></div></div>",
            loaded.formatted_subtotal
        ));
    }

    let mut response = serde_json::json!({
        "ok": true,
        "action": action,
        "cart_count": loaded.total_count,
        "subtotal": loaded.subtotal,
        "subtotal_fmt": loaded.formatted_subtotal,
        "alerts": loaded.alerts,
        "items": loaded.items,
        "coza_html": coza_html,
        "html": coza_html,
    });

    if let Some(w) = warning {
        response["warning"] = serde_json::Value::String(w);
    }

    Json(response).into_response()
}

// -------------------------------------------------------------
// GET /api/availability.php
// -------------------------------------------------------------
pub async fn availability_handler(
    State(state): State<AppState>,
    Query(query): Query<AvailabilityQuery>,
) -> Response {
    let raw_ids = query.ids.unwrap_or_default();
    let id_list: Vec<u64> = if !raw_ids.is_empty() {
        raw_ids
            .split(',')
            .filter_map(|s| s.trim().parse::<u64>().ok())
            .take(24)
            .collect()
    } else if let Some(pid) = query.id.or(query.product_id) {
        vec![pid]
    } else {
        vec![]
    };

    if id_list.is_empty() {
        return Json(serde_json::json!({})).into_response();
    }

    let stock_mode = state.settings.get_or("stock_display_mode", "count_when_low");

    #[derive(Debug, sqlx::FromRow)]
    #[allow(dead_code)]
    struct StockRow {
        pub id: u64,
        pub stock: i32,
        pub track_inventory: i8,
        pub allow_backorder: i8,
        pub low_stock_threshold: i32,
    }

    let mut results = serde_json::Map::new();

    for id in id_list {
        let row: Option<StockRow> = sqlx::query_as(
            "SELECT id, stock, track_inventory, allow_backorder, low_stock_threshold FROM products WHERE id = ? AND status = 'active' LIMIT 1",
        )
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if let Some(r) = row {
            let status = if r.track_inventory == 0 {
                "in_stock"
            } else if r.allow_backorder == 1 && r.stock <= 0 {
                "backorder"
            } else if r.stock <= 0 {
                "out_of_stock"
            } else if r.stock <= r.low_stock_threshold {
                "low_stock"
            } else {
                "in_stock"
            };

            let exposed_stock = if stock_mode == "always" || (stock_mode == "count_when_low" && status == "low_stock") {
                Some(r.stock.max(0))
            } else {
                None
            };

            results.insert(
                id.to_string(),
                serde_json::json!({
                    "id": id,
                    "status": status,
                    "stock": exposed_stock,
                    "track_inventory": r.track_inventory == 1,
                    "allow_backorder": r.allow_backorder == 1,
                }),
            );
        }
    }

    Json(serde_json::Value::Object(results)).into_response()
}

// -------------------------------------------------------------
// GET /api/availability_stream.php (SSE stream)
// -------------------------------------------------------------
pub async fn availability_stream_handler(
    State(state): State<AppState>,
    Query(query): Query<AvailabilityQuery>,
) -> Sse<impl Stream<Item = Result<Event, Infallible>>> {
    let product_id = query.product_id.or(query.id).unwrap_or(0);

    let stream = async_stream::stream! {
        let mut last_stock: Option<i32> = None;
        for _ in 0..25 {
            let stock: Option<i32> = sqlx::query_scalar(
                "SELECT stock FROM products WHERE id = ? AND status = 'active' LIMIT 1",
            )
            .bind(product_id)
            .fetch_optional(&state.db)
            .await
            .ok()
            .flatten();

            if stock != last_stock {
                last_stock = stock;
                let s = stock.unwrap_or(0);
                let payload = serde_json::json!({
                    "product_id": product_id,
                    "stock": s,
                    "status": if s <= 0 { "out_of_stock" } else { "in_stock" },
                    "timestamp": Utc::now().timestamp(),
                });
                yield Ok(Event::default().event("stock").data(payload.to_string()));
            }

            tokio::time::sleep(Duration::from_secs(1)).await;
        }
    };

    Sse::new(stream).keep_alive(KeepAlive::default())
}

// -------------------------------------------------------------
// GET /api/search_suggest.php
// -------------------------------------------------------------
pub async fn search_suggest_handler(
    State(state): State<AppState>,
    Query(query): Query<SearchSuggestQuery>,
) -> Json<serde_json::Value> {
    let q = query.q.as_deref().unwrap_or("").trim();
    let currency_sym = state.settings.get_or("currency_symbol", "৳");

    if q.len() < 2 {
        #[derive(Debug, sqlx::FromRow)]
        struct CatRow {
            pub id: u64,
            pub name: String,
            pub slug: String,
        }

        let cats: Vec<CatRow> = sqlx::query_as(
            "SELECT c.id, c.name, c.slug FROM categories c WHERE c.status = 'active' ORDER BY c.display_order ASC LIMIT 5",
        )
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();

        let cats_json: Vec<serde_json::Value> = cats
            .into_iter()
            .map(|c| {
                serde_json::json!({
                    "id": c.id,
                    "name": c.name,
                    "slug": c.slug,
                    "url": format!("/category/{}", c.slug),
                })
            })
            .collect();

        return Json(serde_json::json!({
            "query": q,
            "products": [],
            "categories": cats_json,
            "total_results": 0,
        }));
    }

    let like = format!("%{}%", q);

    #[derive(Debug, sqlx::FromRow)]
    struct ProductSearchRow {
        pub id: u64,
        pub name: String,
        pub slug: String,
        pub sku: String,
        pub price: Decimal,
        pub sale_price: Option<Decimal>,
        pub image: Option<String>,
        pub category_name: Option<String>,
    }

    let products: Vec<ProductSearchRow> = sqlx::query_as(
        "SELECT p.id, p.name, p.slug, p.sku, p.price, p.sale_price, p.image, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.status = 'active' AND (p.name LIKE ? OR p.sku LIKE ? OR p.short_description LIKE ?)
         ORDER BY (p.name LIKE ?) DESC, p.created_at DESC LIMIT 6",
    )
    .bind(&like)
    .bind(&like)
    .bind(&like)
    .bind(format!("{}%", q))
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let products_json: Vec<serde_json::Value> = products
        .into_iter()
        .map(|p| {
            let on_sale = p.sale_price.is_some() && p.sale_price.unwrap() > Decimal::ZERO && p.sale_price.unwrap() < p.price;
            serde_json::json!({
                "id": p.id,
                "name": p.name,
                "slug": p.slug,
                "sku": p.sku,
                "category": p.category_name.unwrap_or_default(),
                "price": p.price,
                "price_fmt": format_money(Some(p.price), &currency_sym),
                "sale_price": p.sale_price,
                "sale_price_fmt": p.sale_price.map(|sp| format_money(Some(sp), &currency_sym)),
                "on_sale": on_sale,
                "image": resolve_image_url(p.image.as_deref()),
                "url": format!("/product/{}", p.slug),
            })
        })
        .collect();

    #[derive(Debug, sqlx::FromRow)]
    struct CatSearchRow {
        pub id: u64,
        pub name: String,
        pub slug: String,
    }

    let categories: Vec<CatSearchRow> = sqlx::query_as(
        "SELECT id, name, slug FROM categories WHERE status = 'active' AND name LIKE ? ORDER BY name ASC LIMIT 4",
    )
    .bind(&like)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let categories_json: Vec<serde_json::Value> = categories
        .into_iter()
        .map(|c| {
            serde_json::json!({
                "id": c.id,
                "name": c.name,
                "slug": c.slug,
                "url": format!("/category/{}", c.slug),
            })
        })
        .collect();

    let total = products_json.len() + categories_json.len();

    Json(serde_json::json!({
        "query": q,
        "products": products_json,
        "categories": categories_json,
        "total_results": total,
    }))
}

// -------------------------------------------------------------
// POST /api/newsletter.php, /api/newsletter
// -------------------------------------------------------------
pub async fn newsletter_api_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<NewsletterApiForm>,
) -> Response {
    if !SessionHelper::verify_csrf(&session, form.csrf_token.as_deref()).await {
        return (
            axum::http::StatusCode::FORBIDDEN,
            Json(serde_json::json!({ "success": false, "error": "Invalid session token. Please refresh." })),
        )
            .into_response();
    }

    if state.settings.get_or("newsletter_enabled", "1") != "1" {
        return (
            axum::http::StatusCode::FORBIDDEN,
            Json(serde_json::json!({ "success": false, "error": "Newsletter subscriptions are currently closed." })),
        )
            .into_response();
    }

    let email = form.email.as_deref().unwrap_or("").trim().to_lowercase();
    if email.is_empty() || !email.contains('@') {
        return (
            axum::http::StatusCode::BAD_REQUEST,
            Json(serde_json::json!({ "success": false, "error": "Please enter a valid email address." })),
        )
            .into_response();
    }

    let source = form.source.as_deref().unwrap_or("storefront_footer");
    let double_opt_in = state.settings.get_or("newsletter_double_optin", "1") == "1";

    let existing: Option<(u64, String)> = sqlx::query_as(
        "SELECT id, status FROM newsletter_subscribers WHERE email = ? LIMIT 1",
    )
    .bind(&email)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    if let Some((_, ref status)) = existing {
        if status == "active" {
            return Json(serde_json::json!({
                "success": true,
                "message": "You are already subscribed to our newsletter! Thank you."
            }))
            .into_response();
        }
    }

    let raw_token = generate_csrf_token();
    let mut hasher = sha2::Sha256::new();
    use sha2::Digest;
    hasher.update(raw_token.as_bytes());
    let token_hash = hex::encode(hasher.finalize());

    if double_opt_in {
        if let Some((id, _)) = existing {
            let _ = sqlx::query(
                "UPDATE newsletter_subscribers SET status = 'pending', token_hash = ?, ip = '127.0.0.1', created_at = NOW() WHERE id = ?",
            )
            .bind(&token_hash)
            .bind(id)
            .execute(&state.db)
            .await;
        } else {
            let _ = sqlx::query(
                "INSERT INTO newsletter_subscribers (email, status, token_hash, source, ip, created_at) VALUES (?, 'pending', ?, ?, '127.0.0.1', NOW())",
            )
            .bind(&email)
            .bind(&token_hash)
            .bind(source)
            .execute(&state.db)
            .await;
        }

        let confirm_url = format!("{}/newsletter?action=confirm&t={}", state.config.app_url.trim_end_matches('/'), raw_token);
        let site_name = state.settings.get_or("site_name", "Jinish Potro");
        let _ = state.mailer.queue_email(
            &email,
            None,
            &format!("Confirm your subscription to {}", site_name),
            &format!(
                "<p>Hello,</p><p>Please confirm your email subscription by clicking the link below:</p><p><a href=\"{}\">{}</a></p>",
                confirm_url, confirm_url
            ),
            false,
        ).await;

        Json(serde_json::json!({
            "success": true,
            "message": "Please check your email to confirm your subscription."
        }))
        .into_response()
    } else {
        if let Some((id, _)) = existing {
            let _ = sqlx::query(
                "UPDATE newsletter_subscribers SET status = 'active', confirmed_at = NOW(), ip = '127.0.0.1' WHERE id = ?",
            )
            .bind(id)
            .execute(&state.db)
            .await;
        } else {
            let _ = sqlx::query(
                "INSERT INTO newsletter_subscribers (email, status, confirmed_at, source, ip, created_at) VALUES (?, 'active', NOW(), ?, '127.0.0.1', NOW())",
            )
            .bind(&email)
            .bind(source)
            .execute(&state.db)
            .await;
        }

        Json(serde_json::json!({
            "success": true,
            "message": "Thank you for subscribing to our newsletter!"
        }))
        .into_response()
    }
}

// -------------------------------------------------------------
// GET /api/wishlist.php
// -------------------------------------------------------------
pub async fn wishlist_api_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<WishlistApiQuery>,
) -> Response {
    let customer = SessionHelper::get_customer(&session).await;
    let customer_id = customer.as_ref().map(|c| c.id).unwrap_or(0);

    let action = query.action.unwrap_or_else(|| "list_ids".to_string());

    if state.settings.get_or("customer_wishlist_enabled", "1") != "1" {
        return Json(serde_json::json!({ "ok": false, "error": "Wishlist is currently disabled." })).into_response();
    }

    if action == "list_ids" {
        if customer_id == 0 {
            return Json(serde_json::json!({ "ok": true, "logged_in": false, "ids": [] })).into_response();
        }

        let ids: Vec<u64> = sqlx::query_scalar(
            "SELECT product_id FROM wishlists WHERE customer_id = ?",
        )
        .bind(customer_id)
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();

        return Json(serde_json::json!({ "ok": true, "logged_in": true, "ids": ids })).into_response();
    }

    Json(serde_json::json!({ "ok": false, "error": "Unknown action." })).into_response()
}

// -------------------------------------------------------------
// POST /api/wishlist.php
// -------------------------------------------------------------
pub async fn wishlist_api_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<WishlistApiForm>,
) -> Response {
    let customer = SessionHelper::get_customer(&session).await;
    let customer_id = customer.as_ref().map(|c| c.id).unwrap_or(0);

    let action = form.action.unwrap_or_else(|| "toggle".to_string());

    if state.settings.get_or("customer_wishlist_enabled", "1") != "1" {
        return Json(serde_json::json!({ "ok": false, "error": "Wishlist is currently disabled." })).into_response();
    }

    if action == "toggle" {
        let product_id = form.product_id.unwrap_or(0);

        if customer_id == 0 {
            let login_url = format!("/login?next=/account/wishlist&add_wishlist={}", product_id);
            return Json(serde_json::json!({
                "ok": false,
                "error": "login_required",
                "login_url": login_url,
                "message": "Please sign in to save items to your wishlist.",
            }))
            .into_response();
        }

        if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
            return Json(serde_json::json!({ "ok": false, "error": "Invalid or expired session token." })).into_response();
        }

        if product_id == 0 {
            return Json(serde_json::json!({ "ok": false, "error": "Invalid product ID." })).into_response();
        }

        let exists: Option<u64> = sqlx::query_scalar(
            "SELECT product_id FROM wishlists WHERE customer_id = ? AND product_id = ? LIMIT 1",
        )
        .bind(customer_id)
        .bind(product_id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        let added = if exists.is_some() {
            let _ = sqlx::query("DELETE FROM wishlists WHERE customer_id = ? AND product_id = ?")
                .bind(customer_id)
                .bind(product_id)
                .execute(&state.db)
                .await;
            false
        } else {
            let _ = sqlx::query(
                "INSERT IGNORE INTO wishlists (customer_id, product_id, created_at) VALUES (?, ?, NOW())",
            )
            .bind(customer_id)
            .bind(product_id)
            .execute(&state.db)
            .await;
            true
        };

        let total_count: i64 = sqlx::query_scalar(
            "SELECT COUNT(*) FROM wishlists WHERE customer_id = ?",
        )
        .bind(customer_id)
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

        return Json(serde_json::json!({
            "ok": true,
            "added": added,
            "total_count": total_count,
        }))
        .into_response();
    }

    Json(serde_json::json!({ "ok": false, "error": "Unknown action." })).into_response()
}

// -------------------------------------------------------------
// POST /api/review.php, /api/review
// -------------------------------------------------------------
pub async fn review_api_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<ReviewApiForm>,
) -> Response {
    if !SessionHelper::verify_csrf(&session, form.csrf_token.as_deref()).await {
        return (
            axum::http::StatusCode::FORBIDDEN,
            Json(serde_json::json!({ "success": false, "error": "Invalid or expired session token." })),
        )
            .into_response();
    }

    if state.settings.get_or("reviews_enabled", "1") != "1" {
        return (
            axum::http::StatusCode::FORBIDDEN,
            Json(serde_json::json!({ "success": false, "error": "Customer reviews are currently disabled." })),
        )
            .into_response();
    }

    if let Some(ref hp) = form.website_hp {
        if !hp.trim().is_empty() {
            return (
                axum::http::StatusCode::BAD_REQUEST,
                Json(serde_json::json!({ "success": false, "error": "Spam detection triggered." })),
            )
                .into_response();
        }
    }

    let form_time = form.form_time.unwrap_or(0);
    if form_time > 0 && (Utc::now().timestamp() - form_time) < 3 {
        return (
            axum::http::StatusCode::BAD_REQUEST,
            Json(serde_json::json!({ "success": false, "error": "Form submitted too quickly. Please try again." })),
        )
            .into_response();
    }

    let customer = SessionHelper::get_customer(&session).await;
    let customer_id = customer.as_ref().map(|c| c.id);
    let require_login = state.settings.get_or("reviews_require_login", "1") == "1";

    if require_login && customer_id.is_none() {
        return (
            axum::http::StatusCode::UNAUTHORIZED,
            Json(serde_json::json!({ "success": false, "error": "Please sign in to submit a product review." })),
        )
            .into_response();
    }

    let product_id = form.product_id.unwrap_or(0);
    let rating = form.rating.unwrap_or(0);
    let title = form.title.as_deref().unwrap_or("").trim();
    let body = form.body.as_deref().unwrap_or("").trim();
    let author_name = form
        .author_name
        .as_deref()
        .map(|s| s.trim())
        .filter(|s| !s.is_empty())
        .or_else(|| customer.as_ref().map(|c| c.name.as_str()))
        .unwrap_or("Anonymous Buyer");

    if product_id == 0 {
        return (
            axum::http::StatusCode::BAD_REQUEST,
            Json(serde_json::json!({ "success": false, "error": "Invalid product specified." })),
        )
            .into_response();
    }

    if !(1..=5).contains(&rating) {
        return (
            axum::http::StatusCode::BAD_REQUEST,
            Json(serde_json::json!({ "success": false, "error": "Please select a rating between 1 and 5 stars." })),
        )
            .into_response();
    }

    if title.is_empty() {
        return (
            axum::http::StatusCode::BAD_REQUEST,
            Json(serde_json::json!({ "success": false, "error": "Please provide a headline for your review." })),
        )
            .into_response();
    }

    let auto_approve = state.settings.get_or("reviews_auto_approve", "0") == "1";
    let status = if auto_approve { "approved" } else { "pending" };

    let _ = sqlx::query(
        "INSERT INTO product_reviews
         (product_id, customer_id, author_name, rating, title, body, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
    )
    .bind(product_id)
    .bind(customer_id)
    .bind(author_name)
    .bind(rating)
    .bind(title)
    .bind(body)
    .bind(status)
    .execute(&state.db)
    .await;

    let msg = if auto_approve {
        "Thank you! Your review has been published."
    } else {
        "Thank you! Your review has been submitted and is pending moderation."
    };

    Json(serde_json::json!({
        "success": true,
        "message": msg,
    }))
    .into_response()
}
