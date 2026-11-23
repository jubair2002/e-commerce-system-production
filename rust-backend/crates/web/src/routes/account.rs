use axum::{
    extract::{Multipart, Path, Query, State},
    http::{header, HeaderMap, StatusCode},
    response::{Html, IntoResponse, Redirect, Response},
    Form,
};
use chrono::Utc;
use rust_decimal::Decimal;
use serde::{Deserialize, Serialize};
use std::path::PathBuf;
use tower_sessions::Session;

use crate::state::{build_base_context, AppState, SessionHelper};
use common::crypto::{customer_password_hash, customer_password_verify};
use common::money::format_money;
use db::queries::cart::cart_add;
use db::queries::catalog::resolve_image_url;
use db::queries::checkout::stock_restore_for_order;
use db::queries::customer_auth::{token_issue, token_revoke_all};
use db::queries::storage::{process_avatar_image, StorageManager, StoreOptions};
use domain::models::customer::{Customer, CustomerAddress};
use domain::models::order::{Order, OrderItem, OrderStatusHistory};
use domain::models::product::Product;

#[derive(Debug, Deserialize)]
pub struct OrderQuery {
    pub order: Option<String>,
    pub number: Option<String>,
    pub o: Option<String>,
    pub id: Option<u64>,
}

#[derive(Debug, Deserialize)]
pub struct OrdersQuery {
    pub status: Option<String>,
    pub q: Option<String>,
    pub page: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct OrderActionForm {
    pub _csrf: Option<String>,
    pub action: Option<String>,
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
pub struct SecurityForm {
    pub _csrf: Option<String>,
    pub action: Option<String>,
    // Change password fields
    pub current_password: Option<String>,
    pub new_password: Option<String>,
    pub new_password_confirm: Option<String>,
    // Change email fields
    pub new_email: Option<String>,
    pub email_change_password: Option<String>,
    // Revoke device fields
    pub token_id: Option<u64>,
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
    Query(query): Query<OrdersQuery>,
) -> Result<Html<String>, Response> {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Err(Redirect::to("/login?next=/account/orders").into_response()),
    };

    let mut ctx = build_base_context(&state, &session, "account_orders").await;
    ctx.insert("active_tab", "orders");

    let per_page: i64 = 10;
    let current_page = query.page.unwrap_or(1).max(1);

    // Build filter conditions
    let allowed_statuses = ["pending", "processing", "shipped", "delivered", "cancelled"];
    let status_filter = query
        .status
        .as_deref()
        .map(|s| s.trim().to_lowercase())
        .filter(|s| allowed_statuses.contains(&s.as_str()));

    let search_q = query.q.as_deref().map(|s| s.trim()).filter(|s| !s.is_empty());

    let mut count_sql = "SELECT COUNT(*) FROM orders WHERE customer_id = ?".to_string();
    if status_filter.is_some() {
        count_sql.push_str(" AND status = ?");
    }
    if search_q.is_some() {
        count_sql.push_str(" AND order_number LIKE ?");
    }

    let mut count_query = sqlx::query_scalar::<_, i64>(&count_sql).bind(customer.id);
    if let Some(ref st) = status_filter {
        count_query = count_query.bind(st);
    }
    if let Some(ref q) = search_q {
        count_query = count_query.bind(format!("%{}%", q));
    }
    let total_orders: i64 = count_query.fetch_one(&state.db).await.unwrap_or(0);

    let all_orders_count: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM orders WHERE customer_id = ?",
    )
    .bind(customer.id)
    .fetch_one(&state.db)
    .await
    .unwrap_or(0);

    let total_pages = ((total_orders as f64) / (per_page as f64)).ceil() as i64;
    let total_pages = total_pages.max(1);
    let page = current_page.min(total_pages);
    let offset = (page - 1) * per_page;

    let mut select_sql = "SELECT * FROM orders WHERE customer_id = ?".to_string();
    if status_filter.is_some() {
        select_sql.push_str(" AND status = ?");
    }
    if search_q.is_some() {
        select_sql.push_str(" AND order_number LIKE ?");
    }
    select_sql.push_str(" ORDER BY id DESC LIMIT ? OFFSET ?");

    let mut orders_query = sqlx::query_as::<_, Order>(&select_sql).bind(customer.id);
    if let Some(ref st) = status_filter {
        orders_query = orders_query.bind(st);
    }
    if let Some(ref q) = search_q {
        orders_query = orders_query.bind(format!("%{}%", q));
    }
    orders_query = orders_query.bind(per_page).bind(offset);

    let orders: Vec<Order> = orders_query.fetch_all(&state.db).await.unwrap_or_default();

    ctx.insert("orders", &orders);
    ctx.insert("total_orders", &total_orders);
    ctx.insert("all_orders_count", &all_orders_count);
    ctx.insert("current_page", &page);
    ctx.insert("total_pages", &total_pages);
    ctx.insert("status_filter", &status_filter);
    ctx.insert("search_query", &search_q);

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
    let order_num = query.order.or(query.number).or(query.o).unwrap_or_default();
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
        None => {
            // Strict 404 response if not owned or not found
            let mut ctx = build_base_context(state, session, "404").await;
            ctx.insert("error_title", "Order Not Found");
            ctx.insert(
                "error_message",
                "The order you requested could not be found or does not belong to your account.",
            );
            return Err((
                StatusCode::NOT_FOUND,
                Html(state.tera.render("errors/404.html", &ctx).unwrap_or_else(|_| "<h1>Order Not Found</h1>".to_string())),
            ).into_response());
        }
    };

    let mut ctx = build_base_context(state, session, "account_order").await;
    ctx.insert("active_tab", "orders");

    let can_cancel_setting = state.settings.get_or("customer_can_cancel", "1") == "1";
    let can_cancel = can_cancel_setting && ord.status == "pending";
    ctx.insert("can_cancel", &can_cancel);

    let items: Vec<OrderItem> = sqlx::query_as(
        "SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC",
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
// POST /account/order/:number, POST /account/order.php
// -------------------------------------------------------------
pub async fn account_order_post_handler(
    State(state): State<AppState>,
    session: Session,
    path_order: Option<Path<String>>,
    query: Option<Query<OrderQuery>>,
    Form(form): Form<OrderActionForm>,
) -> Response {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Redirect::to("/login").into_response(),
    };

    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid or expired session. Please try again.").await;
        return Redirect::to("/account/orders").into_response();
    }

    let order_num = path_order
        .map(|p| p.0)
        .or_else(|| query.and_then(|q| q.o.clone().or(q.order.clone()).or(q.number.clone())))
        .unwrap_or_default();

    let order: Option<Order> = sqlx::query_as(
        "SELECT * FROM orders WHERE order_number = ? AND customer_id = ? LIMIT 1",
    )
    .bind(&order_num)
    .bind(customer.id)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let ord = match order {
        Some(o) => o,
        None => return Redirect::to("/account/orders").into_response(),
    };

    let action = form.action.as_deref().unwrap_or("");

    match action {
        "cancel_order" => {
            let can_cancel_setting = state.settings.get_or("customer_can_cancel", "1") == "1";
            if !can_cancel_setting || ord.status != "pending" {
                SessionHelper::add_flash(&session, "danger", "This order can no longer be cancelled.").await;
            } else {
                let mut tx = match state.db.begin().await {
                    Ok(t) => t,
                    Err(_) => {
                        SessionHelper::add_flash(&session, "danger", "An error occurred while cancelling your order.").await;
                        return Redirect::to(&format!("/account/order/{}", order_num)).into_response();
                    }
                };

                let res = sqlx::query(
                    "UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND customer_id = ? AND status = 'pending'",
                )
                .bind(ord.id)
                .bind(customer.id)
                .execute(&mut *tx)
                .await;

                if let Ok(r) = res {
                    if r.rows_affected() == 1 {
                        let _ = sqlx::query(
                            "INSERT INTO order_status_history (order_id, status, note, user_id, created_at) VALUES (?, 'cancelled', 'Cancelled by customer', NULL, NOW())",
                        )
                        .bind(ord.id)
                        .execute(&mut *tx)
                        .await;

                        let _ = tx.commit().await;

                        // Restore inventory
                        let _ = stock_restore_for_order(&state.db, ord.id).await;

                        SessionHelper::add_flash(
                            &session,
                            "success",
                            &format!("Order #{} has been cancelled successfully.", order_num),
                        )
                        .await;
                    } else {
                        let _ = tx.rollback().await;
                        SessionHelper::add_flash(
                            &session,
                            "danger",
                            "Order cancellation failed. The status may have already changed.",
                        )
                        .await;
                    }
                } else {
                    let _ = tx.rollback().await;
                    SessionHelper::add_flash(&session, "danger", "An error occurred while cancelling your order.").await;
                }
            }
            Redirect::to(&format!("/account/order/{}", order_num)).into_response()
        }
        "buy_again" => {
            let items: Vec<OrderItem> = sqlx::query_as(
                "SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC",
            )
            .bind(ord.id)
            .fetch_all(&state.db)
            .await
            .unwrap_or_default();

            let mut raw_cart = SessionHelper::get_cart(&session).await;
            let mut added_count = 0;

            for it in items {
                let prod: Option<Product> = sqlx::query_as(
                    "SELECT * FROM products WHERE id = ? AND status = 'active' LIMIT 1",
                )
                .bind(it.product_id)
                .fetch_optional(&state.db)
                .await
                .unwrap_or(None);

                if let Some(p) = prod {
                    let qty = it.quantity.max(1);
                    cart_add(&mut raw_cart, p.id, it.variant_id, qty);
                    added_count += 1;
                }
            }

            SessionHelper::set_cart(&session, &raw_cart).await;

            if added_count > 0 {
                SessionHelper::add_flash(
                    &session,
                    "success",
                    &format!("Items from order #{} have been added to your cart.", order_num),
                )
                .await;
            } else {
                SessionHelper::add_flash(&session, "warning", "None of the items from this order are currently available.").await;
            }

            Redirect::to("/cart").into_response()
        }
        _ => Redirect::to(&format!("/account/order/{}", order_num)).into_response(),
    }
}

// -------------------------------------------------------------
// GET /account/invoice/:number, GET /account/invoice.php
// -------------------------------------------------------------
pub async fn account_invoice_number_handler(
    State(state): State<AppState>,
    session: Session,
    Path(order_num): Path<String>,
) -> Result<Html<String>, Response> {
    render_account_invoice(&state, &session, &order_num, None).await
}

pub async fn account_invoice_php_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<OrderQuery>,
) -> Result<Html<String>, Response> {
    let order_num = query.order.or(query.number).or(query.o).unwrap_or_default();
    render_account_invoice(&state, &session, &order_num, query.id).await
}

async fn render_account_invoice(
    state: &AppState,
    session: &Session,
    order_num: &str,
    order_id: Option<u64>,
) -> Result<Html<String>, Response> {
    let customer = match SessionHelper::get_customer(session).await {
        Some(c) => c,
        None => return Err(Redirect::to("/login").into_response()),
    };

    let mut ctx = build_base_context(state, session, "account_invoice").await;

    let order: Option<Order> = if !order_num.is_empty() {
        sqlx::query_as(
            "SELECT * FROM orders WHERE order_number = ? AND customer_id = ? LIMIT 1",
        )
        .bind(order_num)
        .bind(customer.id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None)
    } else if let Some(oid) = order_id {
        sqlx::query_as(
            "SELECT * FROM orders WHERE id = ? AND customer_id = ? LIMIT 1",
        )
        .bind(oid)
        .bind(customer.id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None)
    } else {
        None
    };

    let ord = match order {
        Some(o) => o,
        None => {
            return Err((
                StatusCode::NOT_FOUND,
                Html("<div style=\"font-family:sans-serif;text-align:center;padding:50px;\"><h2>Order Not Found</h2><p>This invoice does not exist or you do not have permission to view it.</p></div>".to_string()),
            ).into_response());
        }
    };

    let items: Vec<OrderItem> = sqlx::query_as(
        "SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC",
    )
    .bind(ord.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    ctx.insert("order", &ord);
    ctx.insert("items", &items);
    ctx.insert("site_name", &state.settings.get_or("site_name", "Jinish Potro"));
    ctx.insert("store_address", &state.settings.get_or("store_address", ""));
    ctx.insert("store_phone", &state.settings.get_or("store_phone", ""));
    ctx.insert("store_email", &state.settings.get_or("store_email", ""));
    ctx.insert("store_logo_url", &state.settings.get_or("store_logo", ""));

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
                let row: Option<(i8,)> = sqlx::query_as(
                    "SELECT is_default FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1",
                )
                .bind(aid)
                .bind(customer.id)
                .fetch_optional(&state.db)
                .await
                .unwrap_or(None);

                if let Some((was_default,)) = row {
                    let _ = sqlx::query(
                        "DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?",
                    )
                    .bind(aid)
                    .bind(customer.id)
                    .execute(&state.db)
                    .await;

                    if was_default == 1 {
                        let _ = sqlx::query(
                            "UPDATE customer_addresses SET is_default = 1 WHERE customer_id = ? ORDER BY id DESC LIMIT 1",
                        )
                        .bind(customer.id)
                        .execute(&state.db)
                        .await;
                    }

                    SessionHelper::add_flash(&session, "success", "Address deleted successfully.").await;
                } else {
                    SessionHelper::add_flash(&session, "danger", "Unable to delete address. Record not found.").await;
                }
            }
        }
        "set_default" => {
            if let Some(aid) = form.address_id {
                let mut tx = match state.db.begin().await {
                    Ok(t) => t,
                    Err(_) => return Redirect::to("/account/addresses").into_response(),
                };

                let _ = sqlx::query(
                    "UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?",
                )
                .bind(customer.id)
                .execute(&mut *tx)
                .await;

                let res = sqlx::query(
                    "UPDATE customer_addresses SET is_default = 1 WHERE id = ? AND customer_id = ?",
                )
                .bind(aid)
                .bind(customer.id)
                .execute(&mut *tx)
                .await;

                if res.is_ok() && tx.commit().await.is_ok() {
                    SessionHelper::add_flash(&session, "success", "Default shipping address updated.").await;
                } else {
                    SessionHelper::add_flash(&session, "danger", "Failed to update default address.").await;
                }
            }
        }
        "add" | "save" => {
            let aid = form.address_id.unwrap_or(0);
            let label = form.label.as_deref().unwrap_or("Home").trim();
            let full_name = form.full_name.as_deref().unwrap_or("").trim();
            let phone = form.phone.as_deref().unwrap_or("").trim();
            let address = form.address.as_deref().unwrap_or("").trim();
            let city = form.city.as_deref().unwrap_or("").trim();
            let mut is_default = if form.is_default.is_some() { 1i8 } else { 0i8 };

            if full_name.len() < 2 {
                SessionHelper::add_flash(&session, "danger", "Full name is required.").await;
                return Redirect::to("/account/addresses").into_response();
            }
            if phone.is_empty() {
                SessionHelper::add_flash(&session, "danger", "Phone number is required for delivery.").await;
                return Redirect::to("/account/addresses").into_response();
            }
            if address.len() < 5 {
                SessionHelper::add_flash(&session, "danger", "Please enter a complete street address.").await;
                return Redirect::to("/account/addresses").into_response();
            }
            if city.is_empty() {
                SessionHelper::add_flash(&session, "danger", "City or District is required.").await;
                return Redirect::to("/account/addresses").into_response();
            }

            // Check max 10 limit if adding new address
            let count: i64 = sqlx::query_scalar(
                "SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?",
            )
            .bind(customer.id)
            .fetch_one(&state.db)
            .await
            .unwrap_or(0);

            if aid == 0 {
                if count >= 10 {
                    SessionHelper::add_flash(
                        &session,
                        "danger",
                        "You have reached the maximum limit of 10 saved addresses. Please delete an existing address to add a new one.",
                    )
                    .await;
                    return Redirect::to("/account/addresses").into_response();
                }
                if count == 0 {
                    is_default = 1;
                }
            }

            if is_default == 1 {
                let _ = sqlx::query(
                    "UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?",
                )
                .bind(customer.id)
                .execute(&state.db)
                .await;
            }

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
            } else {
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
                SessionHelper::add_flash(&session, "success", "New address added successfully.").await;
            }
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
    mut multipart: Multipart,
) -> Response {
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Redirect::to("/login").into_response(),
    };

    let mut csrf_token = None;
    let mut name = String::new();
    let mut phone = String::new();
    let mut marketing_opt_in = 0i8;
    let mut remove_avatar = false;
    let mut avatar_bytes: Option<Vec<u8>> = None;
    let mut avatar_filename = String::new();

    while let Ok(Some(field)) = multipart.next_field().await {
        let field_name = field.name().unwrap_or("").to_string();
        if field_name == "_csrf" {
            if let Ok(text) = field.text().await {
                csrf_token = Some(text);
            }
        } else if field_name == "name" {
            if let Ok(text) = field.text().await {
                name = text;
            }
        } else if field_name == "phone" {
            if let Ok(text) = field.text().await {
                phone = text;
            }
        } else if field_name == "marketing_opt_in" {
            marketing_opt_in = 1;
        } else if field_name == "remove_avatar" {
            remove_avatar = true;
        } else if field_name == "avatar" {
            let fn_str = field.file_name().unwrap_or("avatar.jpg").to_string();
            if let Ok(bytes) = field.bytes().await {
                if !bytes.is_empty() {
                    avatar_bytes = Some(bytes.to_vec());
                    avatar_filename = fn_str;
                }
            }
        }
    }

    if !SessionHelper::verify_csrf(&session, csrf_token.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/account/profile").into_response();
    }

    let name = name.trim();
    let phone_clean = phone.trim().replace([' ', '-', '(', ')'], "");

    if name.len() < 2 || name.len() > 100 {
        SessionHelper::add_flash(&session, "danger", "Name must be between 2 and 100 characters.").await;
        return Redirect::to("/account/profile").into_response();
    }

    if !phone_clean.is_empty() {
        let bd_phone_valid = (phone_clean.starts_with("01") && phone_clean.len() == 11)
            || (phone_clean.starts_with("+8801") && phone_clean.len() == 14)
            || (phone_clean.starts_with("8801") && phone_clean.len() == 13);
        if !bd_phone_valid {
            SessionHelper::add_flash(
                &session,
                "danger",
                "Please enter a valid Bangladeshi mobile number (e.g. 01712345678).",
            )
            .await;
            return Redirect::to("/account/profile").into_response();
        }
    }

    let fresh: Option<Customer> = sqlx::query_as(
        "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1",
    )
    .bind(customer.id)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let fresh_c = match fresh {
        Some(c) => c,
        None => return Redirect::to("/login").into_response(),
    };

    let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
    let storage = StorageManager::new(uploads_dir);

    let mut new_avatar_key: Option<String> = None;
    let mut new_avatar_file_id: Option<u64> = None;

    if let Some(bytes) = avatar_bytes {
        if bytes.len() > 2 * 1024 * 1024 {
            SessionHelper::add_flash(&session, "danger", "Avatar file size must be less than 2 MB.").await;
            return Redirect::to("/account/profile").into_response();
        }

        match process_avatar_image(&bytes) {
            Ok((webp_bytes, _, _)) => {
                let opts = StoreOptions {
                    owner_type: Some("customer".to_string()),
                    owner_id: Some(customer.id),
                    visibility: Some("public".to_string()),
                    is_test: false,
                };

                match storage.store_file(&state.db, "avatar", &avatar_filename, &webp_bytes, opts).await {
                    Ok(info) => {
                        new_avatar_file_id = Some(info.id);
                        new_avatar_key = Some(info.storage_key);

                        // Clean up old avatar file
                        if let Some(old_fid) = fresh_c.avatar_file_id {
                            let _ = storage.delete_file(&state.db, old_fid).await;
                        }
                    }
                    Err(e) => {
                        SessionHelper::add_flash(&session, "danger", &format!("Failed to upload avatar: {}", e)).await;
                        return Redirect::to("/account/profile").into_response();
                    }
                }
            }
            Err(e) => {
                SessionHelper::add_flash(&session, "danger", &format!("Invalid avatar image: {}", e)).await;
                return Redirect::to("/account/profile").into_response();
            }
        }
    } else if remove_avatar {
        if let Some(old_fid) = fresh_c.avatar_file_id {
            let _ = storage.delete_file(&state.db, old_fid).await;
        }
        new_avatar_key = Some("".to_string());
        new_avatar_file_id = None;
    }

    if let Some(ref av_key) = new_avatar_key {
        let av_val = if av_key.is_empty() { None } else { Some(av_key.as_str()) };
        let _ = sqlx::query(
            "UPDATE customers
             SET name = ?, phone = ?, marketing_opt_in = ?, avatar = ?, avatar_file_id = ?, updated_at = NOW()
             WHERE id = ?",
        )
        .bind(name)
        .bind(if phone_clean.is_empty() { None } else { Some(&phone_clean) })
        .bind(marketing_opt_in)
        .bind(av_val)
        .bind(new_avatar_file_id)
        .bind(customer.id)
        .execute(&state.db)
        .await;
    } else {
        let _ = sqlx::query(
            "UPDATE customers
             SET name = ?, phone = ?, marketing_opt_in = ?, updated_at = NOW()
             WHERE id = ?",
        )
        .bind(name)
        .bind(if phone_clean.is_empty() { None } else { Some(&phone_clean) })
        .bind(marketing_opt_in)
        .bind(customer.id)
        .execute(&state.db)
        .await;
    }

    // Refresh session
    let mut updated_session = customer.clone();
    updated_session.name = name.to_string();
    updated_session.phone = if phone_clean.is_empty() { None } else { Some(phone_clean) };
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
    let customer = match SessionHelper::get_customer(&session).await {
        Some(c) => c,
        None => return Err(Redirect::to("/login?next=/account/security").into_response()),
    };

    let mut ctx = build_base_context(&state, &session, "account_security").await;
    ctx.insert("active_tab", "security");

    #[derive(Debug, Serialize, sqlx::FromRow)]
    struct DeviceRow {
        pub id: u64,
        pub ip: Option<String>,
        pub user_agent: Option<String>,
        pub created_at: Option<chrono::NaiveDateTime>,
    }

    let devices: Vec<DeviceRow> = sqlx::query_as(
        "SELECT id, ip, user_agent, created_at
         FROM customer_tokens
         WHERE customer_id = ? AND type = 'remember_me' AND expires_at > NOW()
         ORDER BY id DESC",
    )
    .bind(customer.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let devices_json: Vec<serde_json::Value> = devices
        .into_iter()
        .map(|d| {
            let ua = d.user_agent.unwrap_or_else(|| "Unknown Browser".to_string());
            let browser = if ua.contains("Chrome") {
                "Chrome on Desktop"
            } else if ua.contains("Firefox") {
                "Firefox on Desktop"
            } else if ua.contains("Safari") {
                "Safari on iOS/macOS"
            } else {
                "Web Browser"
            };
            serde_json::json!({
                "id": d.id,
                "browser": browser,
                "ip": d.ip.unwrap_or_else(|| "127.0.0.1".to_string()),
                "created_at": d.created_at.map(|t| t.format("%Y-%m-%d %H:%M").to_string()).unwrap_or_default(),
            })
        })
        .collect();

    ctx.insert("devices", &devices_json);

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
    headers: HeaderMap,
    Form(form): Form<SecurityForm>,
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

    match action {
        "change_password" => {
            let cur = form.current_password.as_deref().unwrap_or("");
            let new_pwd = form.new_password.as_deref().unwrap_or("");
            let conf = form.new_password_confirm.as_deref().unwrap_or("");

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
                SessionHelper::add_flash(&session, "danger", "The current password you entered is incorrect.").await;
                return Redirect::to("/account/security#password").into_response();
            }

            if new_pwd != conf {
                SessionHelper::add_flash(&session, "danger", "New passwords do not match.").await;
                return Redirect::to("/account/security#password").into_response();
            }

            if new_pwd.len() < 8 {
                SessionHelper::add_flash(&session, "danger", "Password must be at least 8 characters long.").await;
                return Redirect::to("/account/security#password").into_response();
            }

            let new_hash = match customer_password_hash(new_pwd) {
                Ok(h) => h,
                Err(e) => {
                    SessionHelper::add_flash(&session, "danger", &format!("Password error: {}", e)).await;
                    return Redirect::to("/account/security#password").into_response();
                }
            };

            let _ = sqlx::query(
                "UPDATE customers SET password_hash = ?, updated_at = NOW() WHERE id = ?",
            )
            .bind(&new_hash)
            .bind(customer.id)
            .execute(&state.db)
            .await;

            // Revoke all other remember-me tokens
            let _ = token_revoke_all(&state.db, customer.id, Some("remember_me")).await;

            // Queue notification email
            let site_name = state.settings.get_or("site_name", "Jinish Potro");
            let _ = state.mailer.queue_email(
                &c_rec.email,
                Some(&c_rec.name),
                &format!("Your {} password was changed", site_name),
                &format!(
                    "<p>Hi {},</p><p>Your password for {} was successfully changed.</p><p>If you did not perform this change, please reset your password immediately.</p>",
                    c_rec.name, site_name
                ),
                false,
            ).await;

            SessionHelper::add_flash(
                &session,
                "success",
                "Your password has been changed successfully. Other devices have been signed out.",
            )
            .await;
            Redirect::to("/account/security#password").into_response()
        }
        "change_email" => {
            let cur = form.email_change_password.as_deref().unwrap_or("");
            let new_email = form.new_email.as_deref().unwrap_or("").trim().to_lowercase();

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
                SessionHelper::add_flash(&session, "danger", "Password confirmation is required to change your email.").await;
                return Redirect::to("/account/security#email").into_response();
            }

            if !new_email.contains('@') || !new_email.contains('.') {
                SessionHelper::add_flash(&session, "danger", "Please enter a valid email address.").await;
                return Redirect::to("/account/security#email").into_response();
            }

            if new_email == c_rec.email.to_lowercase() {
                SessionHelper::add_flash(&session, "danger", "The new email must be different from your current email.").await;
                return Redirect::to("/account/security#email").into_response();
            }

            let existing: Option<(u64,)> = sqlx::query_as(
                "SELECT id FROM customers WHERE email = ? AND id != ? LIMIT 1",
            )
            .bind(&new_email)
            .bind(customer.id)
            .fetch_optional(&state.db)
            .await
            .unwrap_or(None);

            if existing.is_some() {
                SessionHelper::add_flash(&session, "danger", "This email address is already associated with another account.").await;
                return Redirect::to("/account/security#email").into_response();
            }

            // Revoke old email_change tokens
            let _ = token_revoke_all(&state.db, customer.id, Some("email_change")).await;

            let ip = headers
                .get("x-forwarded-for")
                .and_then(|h| h.to_str().ok())
                .unwrap_or("127.0.0.1");

            let token = match token_issue(&state.db, customer.id, "email_change", 3600, Some(&new_email), Some(ip), None).await {
                Ok(t) => t,
                Err(e) => {
                    SessionHelper::add_flash(&session, "danger", &format!("Token generation failed: {}", e)).await;
                    return Redirect::to("/account/security#email").into_response();
                }
            };

            let verify_url = format!("{}/verify-email?t={}", state.config.app_url, token);
            let site_name = state.settings.get_or("site_name", "Jinish Potro");

            let _ = state.mailer.queue_email(
                &new_email,
                Some(&c_rec.name),
                &format!("Confirm your new email address for {}", site_name),
                &format!(
                    "<p>Hi {},</p><p>Please confirm your new email address by clicking the link below:</p><p><a href=\"{}\">{}</a></p><p>This link expires in 1 hour.</p>",
                    c_rec.name, verify_url, verify_url
                ),
                false,
            ).await;

            SessionHelper::add_flash(
                &session,
                "info",
                &format!(
                    "A confirmation link has been sent to {}. Please check your inbox and click the link to confirm this change.",
                    new_email
                ),
            )
            .await;
            Redirect::to("/account/security#email").into_response()
        }
        "revoke_device" => {
            if let Some(tid) = form.token_id {
                let _ = sqlx::query(
                    "DELETE FROM customer_tokens WHERE id = ? AND customer_id = ? AND type = 'remember_me'",
                )
                .bind(tid)
                .bind(customer.id)
                .execute(&state.db)
                .await;
                SessionHelper::add_flash(&session, "success", "Device signed out successfully.").await;
            }
            Redirect::to("/account/security#devices").into_response()
        }
        "revoke_all_devices" => {
            let _ = token_revoke_all(&state.db, customer.id, Some("remember_me")).await;
            SessionHelper::add_flash(&session, "success", "All other signed-in devices have been logged out.").await;
            Redirect::to("/account/security#devices").into_response()
        }
        _ => Redirect::to("/account/security").into_response(),
    }
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
        SessionHelper::add_flash(&session, "danger", "Invalid or expired session. Please try again.").await;
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
        SessionHelper::add_flash(
            &session,
            "danger",
            "Incorrect password. Data export requires password confirmation for security.",
        )
        .await;
        return Redirect::to("/account/export").into_response();
    }

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
            (header::CONTENT_TYPE, "application/json; charset=utf-8"),
            (
                header::CONTENT_DISPOSITION,
                &format!("attachment; filename=\"{}\"", filename),
            ),
            (header::CACHE_CONTROL, "no-store, no-cache, must-revalidate"),
            (header::PRAGMA, "no-cache"),
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

    let allow_delete = state.settings.get_or("customer_allow_account_delete", "1") == "1";
    if !allow_delete {
        SessionHelper::add_flash(&session, "info", "Account deletion is currently disabled by store administration.").await;
        return Err(Redirect::to("/account/security").into_response());
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
        SessionHelper::add_flash(&session, "danger", "Invalid or expired session. Please try again.").await;
        return Redirect::to("/account/delete").into_response();
    }

    let allow_delete = state.settings.get_or("customer_allow_account_delete", "1") == "1";
    if !allow_delete {
        SessionHelper::add_flash(&session, "info", "Account deletion is currently disabled by store administration.").await;
        return Redirect::to("/account/security").into_response();
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

    // Clean avatar file from storage
    if let Some(fid) = cust_rec.avatar_file_id {
        let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
        let storage = StorageManager::new(uploads_dir);
        let _ = storage.delete_file(&state.db, fid).await;
    }

    // Atomic anonymization in transaction
    let mut tx = match state.db.begin().await {
        Ok(t) => t,
        Err(_) => {
            SessionHelper::add_flash(&session, "danger", "Database error. Please try again.").await;
            return Redirect::to("/account/delete").into_response();
        }
    };

    // 1. Unlink orders (retained for accounting and tax records)
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
         SET name = 'Deleted customer', email = ?, phone = NULL, avatar = NULL, avatar_file_id = NULL, status = 'blocked', deleted_at = NOW(), updated_at = NOW()
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
            "<p>Hi {},</p><p>As requested, your customer account with {} has been permanently deleted and your personal information has been anonymized.</p><p>If this was not done by you, please contact support immediately.</p>",
            cust_rec.name, site_name
        ),
        false,
    ).await;

    SessionHelper::remove_customer(&session).await;
    SessionHelper::add_flash(&session, "info", "Your account has been permanently deleted. We are sorry to see you go!").await;
    Redirect::to("/").into_response()
}
