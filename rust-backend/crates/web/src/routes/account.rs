use axum::{
    extract::{Path, Query, State},
    response::{Html, IntoResponse, Redirect, Response},
    Form,
};
use chrono::Utc;
use rust_decimal::Decimal;
use serde::{Deserialize, Serialize};
use tower_sessions::Session;

use crate::state::{build_base_context, AppState, SessionHelper};
use common::crypto::{customer_password_hash, customer_password_verify};
use common::money::format_money;
use db::queries::cart::cart_add;
use db::queries::catalog::resolve_image_url;
use domain::models::customer::{Customer, CustomerAddress};
use domain::models::order::{Order, OrderItem, OrderStatusHistory};
use domain::models::product::Product;

#[derive(Debug, Deserialize)]
pub struct OrderQuery {
    pub order: Option<String>,
    pub number: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct AddressForm {
    pub _csrf: Option<String>,
    pub action: Option<String>,
    pub address_id: Option<u64>,
    pub label: Option<String>,
    pub full_name: Option<String>,
    pub phone: Option<String>,
    pub address: Option<String>,
    pub city: Option<String>,
    pub area: Option<String>,
    pub zip: Option<String>,
    pub country: Option<String>,
    pub is_default: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct ProfileForm {
    pub _csrf: Option<String>,
    pub name: Option<String>,
    pub phone: Option<String>,
    pub marketing_opt_in: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct PasswordChangeForm {
    pub _csrf: Option<String>,
    pub action: Option<String>,
    pub current_password: Option<String>,
    pub new_password: Option<String>,
    pub new_password_confirmation: Option<String>,
    pub new_email: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct WishlistActionForm {
    pub _csrf: Option<String>,
    pub action: Option<String>,
    pub product_id: Option<u64>,
}

#[derive(Debug, Deserialize)]
pub struct ExportForm {
    pub _csrf: Option<String>,
    pub password: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct DeleteForm {
    pub _csrf: Option<String>,
    pub password: Option<String>,
    pub confirm_word: Option<String>,
}

// -------------------------------------------------------------
// GET /account, GET /account/index.php
// -------------------------------------------------------------
pub async fn account_index_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Err(Redirect::to("/login?next=/account").into_response()),
    };

    let mut ctx = build_base_context(&state, &session, "account").await;
    ctx.insert("active_tab", "overview");

    let orders: Vec<Order> = sqlx::query_as(
        "SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 5",
    )
    .bind(customer.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let total_orders: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM orders WHERE customer_id = ?",
    )
    .bind(customer.id)
    .fetch_one(&state.db)
    .await
    .unwrap_or(0);

    let default_address: Option<CustomerAddress> = sqlx::query_as(
        "SELECT * FROM customer_addresses WHERE customer_id = ? AND is_default = 1 LIMIT 1",
    )
    .bind(customer.id)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    ctx.insert("recent_orders", &orders);
    ctx.insert("total_orders", &total_orders);
    ctx.insert("default_address", &default_address);

    state
        .tera
        .render("account/index.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// GET /account/orders, GET /account/orders.php
// -------------------------------------------------------------
pub async fn account_orders_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Err(Redirect::to("/login?next=/account/orders").into_response()),
    };

    let mut ctx = build_base_context(&state, &session, "account_orders").await;
    ctx.insert("active_tab", "orders");

    let orders: Vec<Order> = sqlx::query_as(
        "SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC",
    )
    .bind(customer.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    ctx.insert("orders", &orders);

    state
        .tera
        .render("account/orders.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// GET /account/order/:number, GET /account/order.php
// -------------------------------------------------------------
pub async fn account_order_number_handler(
    State(state): State<AppState>,
    session: Session,
    Path(order_num): Path<String>,
) -> Result<Html<String>, Response> {
    render_account_order(&state, &session, &order_num).await
}

pub async fn account_order_php_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<OrderQuery>,
) -> Result<Html<String>, Response> {
    let order_num = query.order.or(query.number).unwrap_or_default();
    render_account_order(&state, &session, &order_num).await
}

async fn render_account_order(
    state: &AppState,
    session: &Session,
    order_num: &str,
) -> Result<Html<String>, Response> {
    let customer = match SessionHelper::get_customer(session).await {
        Some(c) => c,
        None => return Err(Redirect::to("/login?next=/account/orders").into_response()),
    };

    let mut ctx = build_base_context(state, session, "account_order").await;
    ctx.insert("active_tab", "orders");

    let order: Option<Order> = sqlx::query_as(
        "SELECT * FROM orders WHERE order_number = ? AND customer_id = ? LIMIT 1",
    )
    .bind(order_num)
    .bind(customer.id)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let ord = match order {
        Some(o) => o,
        None => return Err(Redirect::to("/account/orders").into_response()),
    };

    let items: Vec<OrderItem> = sqlx::query_as(
        "SELECT * FROM order_items WHERE order_id = ?",
    )
    .bind(ord.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let history: Vec<OrderStatusHistory> = sqlx::query_as(
        "SELECT * FROM order_status_history WHERE order_id = ? ORDER BY id ASC",
    )
    .bind(ord.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    ctx.insert("order", &ord);
    ctx.insert("items", &items);
    ctx.insert("history", &history);

    state
        .tera
        .render("account/order.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// GET /account/invoice/:number, GET /account/invoice.php
// -------------------------------------------------------------
pub async fn account_invoice_number_handler(
    State(state): State<AppState>,
    session: Session,
    Path(order_num): Path<String>,
) -> Result<Html<String>, Response> {
    render_account_invoice(&state, &session, &order_num).await
}

pub async fn account_invoice_php_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<OrderQuery>,
) -> Result<Html<String>, Response> {
    let order_num = query.order.or(query.number).unwrap_or_default();
    render_account_invoice(&state, &session, &order_num).await
}

async fn render_account_invoice(
    state: &AppState,
    session: &Session,
    order_num: &str,
) -> Result<Html<String>, Response> {
    let customer = match SessionHelper::get_customer(session).await {
        Some(c) => c,
        None => return Err(Redirect::to("/login").into_response()),
    };

    let mut ctx = build_base_context(state, session, "account_invoice").await;

    let order: Option<Order> = sqlx::query_as(
        "SELECT * FROM orders WHERE order_number = ? AND customer_id = ? LIMIT 1",
    )
    .bind(order_num)
    .bind(customer.id)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let ord = match order {
        Some(o) => o,
        None => return Err(Redirect::to("/account/orders").into_response()),
    };

    let items: Vec<OrderItem> = sqlx::query_as(
        "SELECT * FROM order_items WHERE order_id = ?",
    )
    .bind(ord.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    ctx.insert("order", &ord);
    ctx.insert("items", &items);

    state
        .tera
        .render("account/invoice.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// GET /account/addresses, POST /account/addresses, GET /account/addresses.php
// -------------------------------------------------------------
pub async fn account_addresses_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Err(Redirect::to("/login?next=/account/addresses").into_response()),
    };

    let mut ctx = build_base_context(&state, &session, "account_addresses").await;
    ctx.insert("active_tab", "addresses");

    let addresses: Vec<CustomerAddress> = sqlx::query_as(
        "SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id DESC",
    )
    .bind(customer.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    ctx.insert("addresses", &addresses);

    state
        .tera
        .render("account/addresses.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

pub async fn account_addresses_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<AddressForm>,
) -> Response {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Redirect::to("/login").into_response(),
    };

    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/account/addresses").into_response();
    }

    let action = form.action.as_deref().unwrap_or("add");

    match action {
        "delete" => {
            if let Some(aid) = form.address_id {
                let _ = sqlx::query(
                    "DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?",
                )
                .bind(aid)
                .bind(customer.id)
                .execute(&state.db)
                .await;
                SessionHelper::add_flash(&session, "success", "Address deleted successfully.").await;
            }
        }
        "set_default" => {
            if let Some(aid) = form.address_id {
                let _ = sqlx::query(
                    "UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?",
                )
                .bind(customer.id)
                .execute(&state.db)
                .await;

                let _ = sqlx::query(
                    "UPDATE customer_addresses SET is_default = 1 WHERE id = ? AND customer_id = ?",
                )
                .bind(aid)
                .bind(customer.id)
                .execute(&state.db)
                .await;
                SessionHelper::add_flash(&session, "success", "Default shipping address updated.").await;
            }
        }
        "add" | "save" => {
            let label = form.label.as_deref().unwrap_or("Home").trim();
            let full_name = form.full_name.as_deref().unwrap_or("").trim();
            let phone = form.phone.as_deref().unwrap_or("").trim();
            let address = form.address.as_deref().unwrap_or("").trim();
            let city = form.city.as_deref().unwrap_or("").trim();
            let is_default = if form.is_default.is_some() { 1i8 } else { 0i8 };

            if full_name.is_empty() || phone.is_empty() || address.is_empty() || city.is_empty() {
                SessionHelper::add_flash(&session, "danger", "Please fill in all required address fields.").await;
                return Redirect::to("/account/addresses").into_response();
            }

            if is_default == 1 {
                let _ = sqlx::query(
                    "UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?",
                )
                .bind(customer.id)
                .execute(&state.db)
                .await;
            }

            if let Some(aid) = form.address_id {
                if aid > 0 {
                    let _ = sqlx::query(
                        "UPDATE customer_addresses
                         SET label = ?, full_name = ?, phone = ?, address = ?, city = ?, area = ?, zip = ?, is_default = ?, updated_at = NOW()
                         WHERE id = ? AND customer_id = ?",
                    )
                    .bind(label)
                    .bind(full_name)
                    .bind(phone)
                    .bind(address)
                    .bind(city)
                    .bind(&form.area)
                    .bind(&form.zip)
                    .bind(is_default)
                    .bind(aid)
                    .bind(customer.id)
                    .execute(&state.db)
                    .await;
                    SessionHelper::add_flash(&session, "success", "Address updated successfully.").await;
                    return Redirect::to("/account/addresses").into_response();
                }
            }

            let _ = sqlx::query(
                "INSERT INTO customer_addresses
                 (customer_id, label, full_name, phone, address, city, area, zip, country, is_default, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Bangladesh', ?, NOW(), NOW())",
            )
            .bind(customer.id)
            .bind(label)
            .bind(full_name)
            .bind(phone)
            .bind(address)
            .bind(city)
            .bind(&form.area)
            .bind(&form.zip)
            .bind(is_default)
            .execute(&state.db)
            .await;

            SessionHelper::add_flash(&session, "success", "New delivery address added successfully.").await;
        }
        _ => {}
    }

    Redirect::to("/account/addresses").into_response()
}

// -------------------------------------------------------------
// GET /account/profile, POST /account/profile, GET /account/profile.php
// -------------------------------------------------------------
pub async fn account_profile_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Err(Redirect::to("/login?next=/account/profile").into_response()),
    };

    let mut ctx = build_base_context(&state, &session, "account_profile").await;
    ctx.insert("active_tab", "profile");

    let fresh: Option<Customer> = sqlx::query_as(
        "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1",
    )
    .bind(customer.id)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    ctx.insert("customer_profile", &fresh);

    state
        .tera
        .render("account/profile.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

pub async fn account_profile_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<ProfileForm>,
) -> Response {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Redirect::to("/login").into_response(),
    };

    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/account/profile").into_response();
    }

    let name = form.name.as_deref().unwrap_or("").trim();
    let phone = form.phone.as_deref().map(|p| p.trim());
    let opt_in = if form.marketing_opt_in.is_some() { 1i8 } else { 0i8 };

    if name.is_empty() {
        SessionHelper::add_flash(&session, "danger", "Full name cannot be empty.").await;
        return Redirect::to("/account/profile").into_response();
    }

    let _ = sqlx::query(
        "UPDATE customers SET name = ?, phone = ?, marketing_opt_in = ?, updated_at = NOW() WHERE id = ?",
    )
    .bind(name)
    .bind(phone)
    .bind(opt_in)
    .bind(customer.id)
    .execute(&state.db)
    .await;

    // Update session
    let mut updated_session = customer.clone();
    updated_session.name = name.to_string();
    updated_session.phone = phone.map(|s| s.to_string());
    SessionHelper::set_customer(&session, &updated_session).await;

    SessionHelper::add_flash(&session, "success", "Your profile has been updated successfully.").await;
    Redirect::to("/account/profile").into_response()
}

// -------------------------------------------------------------
// GET /account/security, POST /account/security, GET /account/security.php
// -------------------------------------------------------------
pub async fn account_security_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    if SessionHelper::get_customer(&session).await.is_none() {
        return Err(Redirect::to("/login?next=/account/security").into_response());
    }

    let mut ctx = build_base_context(&state, &session, "account_security").await;
    ctx.insert("active_tab", "security");

    state
        .tera
        .render("account/security.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

pub async fn account_security_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<PasswordChangeForm>,
) -> Response {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Redirect::to("/login").into_response(),
    };

    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/account/security").into_response();
    }

    let action = form.action.as_deref().unwrap_or("change_password");

    if action == "change_password" {
        let cur = form.current_password.as_deref().unwrap_or("");
        let new_pwd = form.new_password.as_deref().unwrap_or("");
        let conf = form.new_password_confirmation.as_deref().unwrap_or("");

        let cust: Option<Customer> = sqlx::query_as(
            "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1",
        )
        .bind(customer.id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        let c_rec = match cust {
            Some(c) => c,
            None => return Redirect::to("/login").into_response(),
        };

        if !customer_password_verify(cur, &c_rec.password_hash) {
            SessionHelper::add_flash(&session, "danger", "Incorrect current password.").await;
            return Redirect::to("/account/security").into_response();
        }

        if new_pwd.len() < 8 {
            SessionHelper::add_flash(&session, "danger", "New password must be at least 8 characters long.").await;
            return Redirect::to("/account/security").into_response();
        }

        if new_pwd != conf {
            SessionHelper::add_flash(&session, "danger", "New passwords do not match.").await;
            return Redirect::to("/account/security").into_response();
        }

        let new_hash = match customer_password_hash(new_pwd) {
            Ok(h) => h,
            Err(e) => {
                SessionHelper::add_flash(&session, "danger", &format!("Password error: {}", e)).await;
                return Redirect::to("/account/security").into_response();
            }
        };

        let _ = sqlx::query(
            "UPDATE customers SET password_hash = ?, updated_at = NOW() WHERE id = ?",
        )
        .bind(&new_hash)
        .bind(customer.id)
        .execute(&state.db)
        .await;

        // Revoke remember me tokens
        let _ = sqlx::query(
            "DELETE FROM customer_tokens WHERE customer_id = ? AND type = 'remember_me'",
        )
        .bind(customer.id)
        .execute(&state.db)
        .await;

        SessionHelper::add_flash(&session, "success", "Your password has been changed successfully.").await;
    }

    Redirect::to("/account/security").into_response()
}

// -------------------------------------------------------------
// GET /account/wishlist, POST /account/wishlist, GET /account/wishlist.php
// -------------------------------------------------------------
pub async fn account_wishlist_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Err(Redirect::to("/login?next=/account/wishlist").into_response()),
    };

    let mut ctx = build_base_context(&state, &session, "account_wishlist").await;
    ctx.insert("active_tab", "wishlist");

    let wishlist_enabled = state.settings.get_or("customer_wishlist_enabled", "1") == "1";
    ctx.insert("wishlist_enabled", &wishlist_enabled);

    let currency_sym = state.settings.get_or("currency_symbol", "৳");

    #[derive(Debug, Serialize, sqlx::FromRow)]
    struct WishlistRow {
        pub id: u64,
        pub name: String,
        pub slug: String,
        pub sku: String,
        pub price: Decimal,
        pub sale_price: Option<Decimal>,
        pub stock: i32,
        pub image: Option<String>,
        pub product_type: String,
        pub track_inventory: i8,
    }

    let rows: Vec<WishlistRow> = sqlx::query_as(
        "SELECT p.id, p.name, p.slug, p.sku, p.price, p.sale_price, p.stock, p.image, p.product_type, p.track_inventory
         FROM wishlists w
         JOIN products p ON p.id = w.product_id
         WHERE w.customer_id = ?
         ORDER BY w.created_at DESC",
    )
    .bind(customer.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let mut products_json = Vec::new();
    for r in rows {
        let is_var = r.product_type == "variable";
        let on_sale = r.sale_price.is_some() && r.sale_price.unwrap() > Decimal::ZERO && r.sale_price.unwrap() < r.price;
        let price_html = if on_sale {
            format!(
                "<span class=\"stext-105 cl3 text-danger fw-bold me-2\">{}</span><span class=\"stext-105 cl3 text-muted text-decoration-line-through\">{}</span>",
                format_money(r.sale_price, &currency_sym),
                format_money(Some(r.price), &currency_sym)
            )
        } else {
            format!(
                "<span class=\"stext-105 cl3\">{}</span>",
                format_money(Some(r.price), &currency_sym)
            )
        };

        let image_url = resolve_image_url(r.image.as_deref());

        products_json.push(serde_json::json!({
            "id": r.id,
            "name": r.name,
            "slug": r.slug,
            "sku": r.sku,
            "stock": r.stock,
            "image_url": image_url,
            "is_variable": is_var,
            "track_inventory": r.track_inventory == 1,
            "price_html": price_html,
        }));
    }

    ctx.insert("products", &products_json);

    state
        .tera
        .render("account/wishlist.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

pub async fn account_wishlist_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<WishlistActionForm>,
) -> Response {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Redirect::to("/login").into_response(),
    };

    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/account/wishlist").into_response();
    }

    let action = form.action.as_deref().unwrap_or("");
    let product_id = form.product_id.unwrap_or(0);

    if product_id > 0 {
        if action == "remove" {
            let _ = sqlx::query(
                "DELETE FROM wishlists WHERE customer_id = ? AND product_id = ?",
            )
            .bind(customer.id)
            .bind(product_id)
            .execute(&state.db)
            .await;
            SessionHelper::add_flash(&session, "info", "Item removed from your wishlist.").await;
        } else if action == "move_to_cart" {
            let prod: Option<Product> = sqlx::query_as(
                "SELECT * FROM products WHERE id = ? AND status = 'active' LIMIT 1",
            )
            .bind(product_id)
            .fetch_optional(&state.db)
            .await
            .unwrap_or(None);

            if let Some(p) = prod {
                if p.product_type == "variable" {
                    return Redirect::to(&format!("/product/{}", p.slug)).into_response();
                } else if p.track_inventory == 1 && p.stock <= 0 {
                    SessionHelper::add_flash(&session, "warning", &format!("Sorry, \"{}\" is currently out of stock.", p.name)).await;
                } else {
                    let mut raw_cart = SessionHelper::get_cart(&session).await;
                    cart_add(&mut raw_cart, p.id, None, 1);
                    SessionHelper::set_cart(&session, &raw_cart).await;

                    let _ = sqlx::query(
                        "DELETE FROM wishlists WHERE customer_id = ? AND product_id = ?",
                    )
                    .bind(customer.id)
                    .bind(product_id)
                    .execute(&state.db)
                    .await;

                    SessionHelper::add_flash(&session, "success", &format!("\"{}\" was moved to your cart.", p.name)).await;
                }
            }
        }
    }

    Redirect::to("/account/wishlist").into_response()
}

// -------------------------------------------------------------
// GET /account/export, POST /account/export, GET /account/export.php
// -------------------------------------------------------------
pub async fn account_export_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    if SessionHelper::get_customer(&session).await.is_none() {
        return Err(Redirect::to("/login").into_response());
    }

    let mut ctx = build_base_context(&state, &session, "account_export").await;
    ctx.insert("active_tab", "security");

    state
        .tera
        .render("account/export.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

pub async fn account_export_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<ExportForm>,
) -> Response {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Redirect::to("/login").into_response(),
    };

    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/account/export").into_response();
    }

    let fresh: Option<Customer> = sqlx::query_as(
        "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1",
    )
    .bind(customer.id)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let cust_rec = match fresh {
        Some(c) => c,
        None => return Redirect::to("/login").into_response(),
    };

    let pwd = form.password.as_deref().unwrap_or("");
    if !customer_password_verify(pwd, &cust_rec.password_hash) {
        SessionHelper::add_flash(&session, "danger", "Incorrect password. Data export requires password confirmation.").await;
        return Redirect::to("/account/export").into_response();
    }

    // Gather customer data
    let addresses: Vec<CustomerAddress> = sqlx::query_as(
        "SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY id ASC",
    )
    .bind(customer.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let orders: Vec<Order> = sqlx::query_as(
        "SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC",
    )
    .bind(customer.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    #[derive(Debug, Serialize, sqlx::FromRow)]
    struct WishlistExport {
        pub name: String,
        pub slug: String,
        pub created_at: Option<chrono::NaiveDateTime>,
    }

    let wishlist: Vec<WishlistExport> = sqlx::query_as(
        "SELECT p.name, p.slug, w.created_at FROM wishlists w JOIN products p ON p.id = w.product_id WHERE w.customer_id = ? ORDER BY w.created_at DESC",
    )
    .bind(customer.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let export_payload = serde_json::json!({
        "exported_at": Utc::now().to_rfc3339(),
        "profile": {
            "name": cust_rec.name,
            "email": cust_rec.email,
            "phone": cust_rec.phone,
            "marketing_opt_in": cust_rec.marketing_opt_in,
            "created_at": cust_rec.created_at,
            "last_login_at": cust_rec.last_login_at,
        },
        "addresses": addresses,
        "orders": orders,
        "wishlist": wishlist,
    });

    let filename = format!("customer-data-{}-{}.json", customer.id, Utc::now().format("%Y%m%d-%H%M%S"));

    (
        [
            (axum::http::header::CONTENT_TYPE, "application/json; charset=utf-8"),
            (
                axum::http::header::CONTENT_DISPOSITION,
                &format!("attachment; filename=\"{}\"", filename),
            ),
        ],
        serde_json::to_string_pretty(&export_payload).unwrap_or_default(),
    )
        .into_response()
}

// -------------------------------------------------------------
// GET /account/delete, POST /account/delete, GET /account/delete.php
// -------------------------------------------------------------
pub async fn account_delete_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    if SessionHelper::get_customer(&session).await.is_none() {
        return Err(Redirect::to("/login").into_response());
    }

    let mut ctx = build_base_context(&state, &session, "account_delete").await;
    ctx.insert("active_tab", "security");

    state
        .tera
        .render("account/delete.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

pub async fn account_delete_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<DeleteForm>,
) -> Response {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Redirect::to("/login").into_response(),
    };

    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/account/delete").into_response();
    }

    let fresh: Option<Customer> = sqlx::query_as(
        "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1",
    )
    .bind(customer.id)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let cust_rec = match fresh {
        Some(c) => c,
        None => return Redirect::to("/login").into_response(),
    };

    let pwd = form.password.as_deref().unwrap_or("");
    let conf = form.confirm_word.as_deref().unwrap_or("").trim();

    if !customer_password_verify(pwd, &cust_rec.password_hash) {
        SessionHelper::add_flash(&session, "danger", "Incorrect account password.").await;
        return Redirect::to("/account/delete").into_response();
    }

    if conf != "DELETE" {
        SessionHelper::add_flash(&session, "danger", "You must type DELETE (in capital letters) to confirm account closure.").await;
        return Redirect::to("/account/delete").into_response();
    }

    // Atomic anonymization in transaction
    let mut tx = match state.db.begin().await {
        Ok(t) => t,
        Err(_) => {
            SessionHelper::add_flash(&session, "danger", "Database error. Please try again.").await;
            return Redirect::to("/account/delete").into_response();
        }
    };

    // 1. Unlink orders (kept for financial records)
    let _ = sqlx::query("UPDATE orders SET customer_id = NULL WHERE customer_id = ?")
        .bind(customer.id)
        .execute(&mut *tx)
        .await;

    // 2. Remove addresses, wishlist, tokens
    let _ = sqlx::query("DELETE FROM customer_addresses WHERE customer_id = ?")
        .bind(customer.id)
        .execute(&mut *tx)
        .await;
    let _ = sqlx::query("DELETE FROM wishlists WHERE customer_id = ?")
        .bind(customer.id)
        .execute(&mut *tx)
        .await;
    let _ = sqlx::query("DELETE FROM customer_tokens WHERE customer_id = ?")
        .bind(customer.id)
        .execute(&mut *tx)
        .await;

    // 3. Anonymize customer row
    let anon_email = format!("deleted+{}@invalid", customer.id);
    let _ = sqlx::query(
        "UPDATE customers
         SET name = 'Deleted customer', email = ?, phone = NULL, avatar = NULL, status = 'blocked', deleted_at = NOW(), updated_at = NOW()
         WHERE id = ?",
    )
    .bind(&anon_email)
    .bind(customer.id)
    .execute(&mut *tx)
    .await;

    if tx.commit().await.is_err() {
        SessionHelper::add_flash(&session, "danger", "Failed to delete account. Please try again.").await;
        return Redirect::to("/account/delete").into_response();
    }

    // Farewell email
    let site_name = state.settings.get_or("site_name", "Jinish Potro");
    let _ = state.mailer.queue_email(
        &cust_rec.email,
        Some(&cust_rec.name),
        &format!("Your {} account has been deleted", site_name),
        &format!(
            "<p>Hi {},</p><p>As requested, your customer account with {} has been permanently deleted and your personal information has been anonymized.</p>",
            cust_rec.name, site_name
        ),
        false,
    ).await;

    SessionHelper::remove_customer(&session).await;
    SessionHelper::add_flash(&session, "info", "Your account has been permanently deleted. We are sorry to see you go!").await;
    Redirect::to("/").into_response()
}
