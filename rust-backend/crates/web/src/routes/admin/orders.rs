use axum::{
    extract::{Query, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{Html, IntoResponse, Redirect, Response},
    Form,
};
use chrono::Utc;
use db::queries::activity::log_activity;
use db::queries::checkout::{
    is_valid_status_transition, product_sales_summary_record_order,
    product_sales_summary_reverse_order, stock_restore_for_order,
};
use rust_decimal::Decimal;
use serde::Deserialize;
use tower_sessions::Session;

use crate::state::{build_admin_context, AppState, SessionHelper};

// =================================================================
// Query / Form Models
// =================================================================

#[derive(Debug, Deserialize)]
pub struct OrdersQuery {
    pub q: Option<String>,
    pub status: Option<String>,
    pub payment_status: Option<String>,
    pub from: Option<String>,
    pub to: Option<String>,
    pub per_page: Option<i64>,
    pub page: Option<i64>,
    pub export: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct OrderActionForm {
    pub action: Option<String>,
    pub id: Option<u64>,
    pub bulk_status_target: Option<String>,
    #[serde(default)]
    pub selected_orders: Vec<u64>,
}

#[derive(Debug, Deserialize)]
pub struct OrderViewQuery {
    pub id: Option<u64>,
}

#[derive(Debug, Deserialize)]
pub struct OrderViewActionForm {
    pub action: Option<String>,
    pub status: Option<String>,
    pub note: Option<String>,
    pub courier: Option<String>,
    pub tracking_number: Option<String>,
    pub payment_status: Option<String>,
    pub notes: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct OrderPrintQuery {
    pub id: Option<u64>,
    pub order: Option<String>,
    pub order_number: Option<String>,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct OrderListItem {
    pub id: u64,
    pub order_number: String,
    pub customer_id: Option<u64>,
    pub customer_name: String,
    pub customer_email: String,
    pub customer_phone: String,
    pub shipping_address: String,
    pub shipping_city: String,
    pub subtotal: Decimal,
    pub discount_total: Decimal,
    pub shipping_fee: Decimal,
    pub cod_fee: Decimal,
    pub total: Decimal,
    pub payment_method: String,
    pub payment_status: String,
    pub status: String,
    pub courier: Option<String>,
    pub tracking_number: Option<String>,
    pub notes: Option<String>,
    pub created_at: chrono::NaiveDateTime,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct OrderDetailRow {
    pub id: u64,
    pub order_number: String,
    pub customer_id: Option<u64>,
    pub customer_name: String,
    pub customer_email: String,
    pub customer_phone: String,
    pub shipping_address: String,
    pub shipping_city: String,
    pub shipping_district: Option<String>,
    pub shipping_postal_code: Option<String>,
    pub subtotal: Decimal,
    pub discount_total: Decimal,
    pub shipping_fee: Decimal,
    pub cod_fee: Decimal,
    pub total: Decimal,
    pub payment_method: String,
    pub payment_status: String,
    pub status: String,
    pub courier: Option<String>,
    pub tracking_number: Option<String>,
    pub notes: Option<String>,
    pub stock_restored: i8,
    pub created_at: chrono::NaiveDateTime,
    pub updated_at: chrono::NaiveDateTime,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct OrderItemRow {
    pub id: u64,
    pub order_id: u64,
    pub product_id: u64,
    pub variant_id: Option<u64>,
    pub product_name: String,
    pub variant_name: Option<String>,
    pub sku: Option<String>,
    pub price: Decimal,
    pub quantity: i32,
    pub subtotal: Decimal,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct OrderHistoryRow {
    pub id: u64,
    pub order_id: u64,
    pub status: String,
    pub note: Option<String>,
    pub user_id: Option<u64>,
    pub admin_name: Option<String>,
    pub created_at: chrono::NaiveDateTime,
}

// =================================================================
// 1. GET /admin/orders.php
// =================================================================

pub async fn admin_orders_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<OrdersQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("orders.view") && !admin.has_permission("orders.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let search = query.q.unwrap_or_default().trim().to_string();
    let status_filter = query.status.unwrap_or_default().trim().to_string();
    let pay_status = query.payment_status.unwrap_or_default().trim().to_string();
    let date_from = query.from.unwrap_or_default().trim().to_string();
    let date_to = query.to.unwrap_or_default().trim().to_string();
    let per_page = query.per_page.unwrap_or(15).clamp(5, 100);
    let page = query.page.unwrap_or(1).max(1);
    let offset = (page - 1) * per_page;

    let allowed_statuses = ["pending", "processing", "shipped", "completed", "cancelled"];
    let allowed_pay_statuses = ["unpaid", "paid", "refunded"];

    let mut conditions = Vec::new();

    if !search.is_empty() {
        conditions.push(
            "(order_number LIKE CONCAT('%', ?, '%') OR customer_name LIKE CONCAT('%', ?, '%') OR customer_email LIKE CONCAT('%', ?, '%') OR customer_phone LIKE CONCAT('%', ?, '%'))".to_string()
        );
    }
    if allowed_statuses.contains(&status_filter.as_str()) {
        conditions.push("status = ?".to_string());
    }
    if allowed_pay_statuses.contains(&pay_status.as_str()) {
        conditions.push("payment_status = ?".to_string());
    }
    if !date_from.is_empty() && date_from.len() == 10 {
        conditions.push("created_at >= ?".to_string());
    }
    if !date_to.is_empty() && date_to.len() == 10 {
        conditions.push("created_at <= ?".to_string());
    }

    let where_clause = if conditions.is_empty() {
        String::new()
    } else {
        format!("WHERE {}", conditions.join(" AND "))
    };

    // CSV Export
    if query.export.as_deref() == Some("csv") {
        let export_sql = format!("SELECT * FROM orders {} ORDER BY created_at DESC", where_clause);
        let mut q = sqlx::query_as::<_, OrderListItem>(&export_sql);

        if !search.is_empty() {
            q = q.bind(&search).bind(&search).bind(&search).bind(&search);
        }
        if allowed_statuses.contains(&status_filter.as_str()) {
            q = q.bind(&status_filter);
        }
        if allowed_pay_statuses.contains(&pay_status.as_str()) {
            q = q.bind(&pay_status);
        }
        if !date_from.is_empty() && date_from.len() == 10 {
            q = q.bind(format!("{} 00:00:00", date_from));
        }
        if !date_to.is_empty() && date_to.len() == 10 {
            q = q.bind(format!("{} 23:59:59", date_to));
        }

        let rows = q.fetch_all(&state.db).await.unwrap_or_default();

        let mut csv_data = vec![0xEF, 0xBB, 0xBF]; // UTF-8 BOM
        {
            let mut writer = csv::Writer::from_writer(&mut csv_data);
            let _ = writer.write_record(&[
                "Order Number",
                "Date Placed",
                "Customer Name",
                "Phone",
                "Email",
                "Shipping Address",
                "City",
                "Subtotal",
                "Discount",
                "Shipping Fee",
                "COD Fee",
                "Total",
                "Payment Method",
                "Payment Status",
                "Order Status",
                "Courier",
                "Tracking Number",
            ]);

            for r in rows {
                let _ = writer.write_record(&[
                    r.order_number,
                    r.created_at.format("%Y-%m-%d %H:%M:%S").to_string(),
                    r.customer_name,
                    r.customer_phone,
                    r.customer_email,
                    r.shipping_address,
                    r.shipping_city,
                    r.subtotal.to_string(),
                    r.discount_total.to_string(),
                    r.shipping_fee.to_string(),
                    r.cod_fee.to_string(),
                    r.total.to_string(),
                    r.payment_method.to_uppercase(),
                    r.payment_status.to_uppercase(),
                    r.status,
                    r.courier.unwrap_or_default(),
                    r.tracking_number.unwrap_or_default(),
                ]);
            }
            let _ = writer.flush();
        }

        let mut headers = HeaderMap::new();
        headers.insert(header::CONTENT_TYPE, HeaderValue::from_static("text/csv; charset=utf-8"));
        let cd = format!("attachment; filename=\"orders_{}.csv\"", Utc::now().format("%Y%m%d_%H%M%S"));
        if let Ok(v) = HeaderValue::from_str(&cd) {
            headers.insert(header::CONTENT_DISPOSITION, v);
        }
        return (headers, csv_data).into_response();
    }

    // Normal Pagination & Fetch
    let count_sql = format!("SELECT COUNT(*) FROM orders {}", where_clause);
    let mut q_count = sqlx::query_scalar::<_, i64>(&count_sql);

    if !search.is_empty() {
        q_count = q_count.bind(&search).bind(&search).bind(&search).bind(&search);
    }
    if allowed_statuses.contains(&status_filter.as_str()) {
        q_count = q_count.bind(&status_filter);
    }
    if allowed_pay_statuses.contains(&pay_status.as_str()) {
        q_count = q_count.bind(&pay_status);
    }
    if !date_from.is_empty() && date_from.len() == 10 {
        q_count = q_count.bind(format!("{} 00:00:00", date_from));
    }
    if !date_to.is_empty() && date_to.len() == 10 {
        q_count = q_count.bind(format!("{} 23:59:59", date_to));
    }

    let total_records = q_count.fetch_one(&state.db).await.unwrap_or(0);
    let total_pages = ((total_records as f64) / (per_page as f64)).ceil() as i64;

    let select_sql = format!(
        "SELECT * FROM orders {} ORDER BY created_at DESC LIMIT ? OFFSET ?",
        where_clause
    );
    let mut q_select = sqlx::query_as::<_, OrderListItem>(&select_sql);

    if !search.is_empty() {
        q_select = q_select.bind(&search).bind(&search).bind(&search).bind(&search);
    }
    if allowed_statuses.contains(&status_filter.as_str()) {
        q_select = q_select.bind(&status_filter);
    }
    if allowed_pay_statuses.contains(&pay_status.as_str()) {
        q_select = q_select.bind(&pay_status);
    }
    if !date_from.is_empty() && date_from.len() == 10 {
        q_select = q_select.bind(format!("{} 00:00:00", date_from));
    }
    if !date_to.is_empty() && date_to.len() == 10 {
        q_select = q_select.bind(format!("{} 23:59:59", date_to));
    }
    q_select = q_select.bind(per_page).bind(offset);

    let orders = q_select.fetch_all(&state.db).await.unwrap_or_default();

    // Counts for status pills
    let count_pending: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM orders WHERE status = 'pending'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_processing: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM orders WHERE status = 'processing'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_shipped: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM orders WHERE status = 'shipped'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_completed: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM orders WHERE status = 'completed'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_cancelled: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM orders WHERE status = 'cancelled'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    let mut ctx = build_admin_context(&state, &session, "orders.php", "Orders").await;
    ctx.insert("orders", &orders);
    ctx.insert("total_orders", &total_records);
    ctx.insert("page", &page);
    ctx.insert("per_page", &per_page);
    ctx.insert("total_pages", &total_pages);
    ctx.insert("search", &search);
    ctx.insert("status_filter", &status_filter);
    ctx.insert("pay_status", &pay_status);
    ctx.insert("date_from", &date_from);
    ctx.insert("date_to", &date_to);
    ctx.insert("count_pending", &count_pending);
    ctx.insert("count_processing", &count_processing);
    ctx.insert("count_shipped", &count_shipped);
    ctx.insert("count_completed", &count_completed);
    ctx.insert("count_cancelled", &count_cancelled);

    match state.tera.render("admin/orders.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

// =================================================================
// 2. POST /admin/orders.php
// =================================================================

pub async fn admin_orders_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<OrderActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    let action = form.action.unwrap_or_default();

    if action == "delete" {
        if !admin.has_permission("orders.delete") && admin.role != "admin" {
            SessionHelper::add_flash(&session, "danger", "Access denied. orders.delete required.").await;
            return Redirect::to("/admin/orders.php").into_response();
        }

        let oid = form.id.unwrap_or(0);
        let ord_opt = sqlx::query_as::<_, (String,)>("SELECT order_number FROM orders WHERE id = ?")
            .bind(oid)
            .fetch_optional(&state.db)
            .await
            .unwrap_or(None);

        if let Some((order_num,)) = ord_opt {
            let _ = sqlx::query("DELETE FROM orders WHERE id = ?")
                .bind(oid)
                .execute(&state.db)
                .await;

            log_activity(&state.db, Some(admin.id), "order.delete", &format!("Deleted order {}", order_num), None).await;
            SessionHelper::add_flash(&session, "success", &format!("Order {} deleted.", order_num)).await;
        } else {
            SessionHelper::add_flash(&session, "warning", "Order not found.").await;
        }
        return Redirect::to("/admin/orders.php").into_response();
    }

    if action == "bulk_status" {
        if !admin.has_permission("orders.manage") && admin.role != "admin" {
            SessionHelper::add_flash(&session, "danger", "Access denied. orders.manage required.").await;
            return Redirect::to("/admin/orders.php").into_response();
        }

        let target_status = form.bulk_status_target.unwrap_or_default().trim().to_lowercase();
        let allowed = ["pending", "processing", "shipped", "completed", "cancelled"];
        if !allowed.contains(&target_status.as_str()) {
            SessionHelper::add_flash(&session, "danger", "Invalid target status.").await;
            return Redirect::to("/admin/orders.php").into_response();
        }

        let order_ids = form.selected_orders;
        if order_ids.is_empty() {
            SessionHelper::add_flash(&session, "warning", "No orders were selected.").await;
            return Redirect::to("/admin/orders.php").into_response();
        }

        let mut updated_count = 0;
        let mut skipped_count = 0;

        for oid in order_ids {
            let mut tx = match state.db.begin().await {
                Ok(t) => t,
                Err(_) => {
                    skipped_count += 1;
                    continue;
                }
            };

            let ord_row: Option<(u64, String, String, String, String, i8)> = sqlx::query_as(
                "SELECT id, order_number, status, payment_method, payment_status, stock_restored
                 FROM orders WHERE id = ? FOR UPDATE"
            )
            .bind(oid)
            .fetch_optional(&mut *tx)
            .await
            .unwrap_or(None);

            let ord = match ord_row {
                Some(o) => o,
                None => {
                    let _ = tx.rollback().await;
                    skipped_count += 1;
                    continue;
                }
            };

            let old_status = ord.2;
            let payment_method = ord.3;

            if old_status == target_status {
                let _ = tx.rollback().await;
                continue;
            }

            if !is_valid_status_transition(&old_status, &target_status) {
                let _ = tx.rollback().await;
                skipped_count += 1;
                continue;
            }

            let mut update_sql = "UPDATE orders SET status = ?".to_string();
            if target_status == "completed" && payment_method == "cod" {
                update_sql.push_str(", payment_status = 'paid'");
            }
            update_sql.push_str(" WHERE id = ?");

            let res = sqlx::query(&update_sql)
                .bind(&target_status)
                .bind(oid)
                .execute(&mut *tx)
                .await;

            if res.is_err() {
                let _ = tx.rollback().await;
                skipped_count += 1;
                continue;
            }

            // If cancelled -> stock restore once
            if target_status == "cancelled" {
                let _ = stock_restore_for_order(&state.db, oid).await;
            }

            // Product sales summary
            if target_status == "completed" && old_status != "completed" {
                let _ = product_sales_summary_record_order(&state.db, oid).await;
            } else if target_status == "cancelled" && old_status == "completed" {
                let _ = product_sales_summary_reverse_order(&state.db, oid).await;
            }

            // Order status history
            let note = format!("Bulk status updated to {} by admin", target_status);
            let _ = sqlx::query(
                "INSERT INTO order_status_history (order_id, status, note, user_id, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            )
            .bind(oid)
            .bind(&target_status)
            .bind(&note)
            .bind(Some(admin.id))
            .execute(&mut *tx)
            .await;

            if tx.commit().await.is_ok() {
                updated_count += 1;
            } else {
                skipped_count += 1;
            }
        }

        log_activity(
            &state.db,
            Some(admin.id),
            "order.bulk_status",
            &format!("Bulk updated {} orders to {}", updated_count, target_status),
            None,
        )
        .await;

        let msg = format!(
            "Updated {} order(s) to {}{}",
            updated_count,
            target_status,
            if skipped_count > 0 {
                format!(" ({} skipped due to invalid transition rules).", skipped_count)
            } else {
                ".".to_string()
            }
        );
        SessionHelper::add_flash(&session, "success", &msg).await;
    }

    Redirect::to("/admin/orders.php").into_response()
}

// =================================================================
// 3. GET /admin/order_view.php
// =================================================================

pub async fn admin_order_view_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<OrderViewQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("orders.view") && !admin.has_permission("orders.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let oid = query.id.unwrap_or(0);
    let order_opt = sqlx::query_as::<_, OrderDetailRow>(
        "SELECT id, order_number, customer_id, customer_name, customer_email, customer_phone,
                shipping_address, shipping_city, shipping_district, shipping_postal_code,
                subtotal, discount_total, shipping_fee, cod_fee, total,
                payment_method, payment_status, status, courier, tracking_number, notes,
                stock_restored, created_at, updated_at
         FROM orders WHERE id = ? LIMIT 1"
    )
    .bind(oid)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let order = match order_opt {
        Some(o) => o,
        None => {
            SessionHelper::add_flash(&session, "warning", "Order not found.").await;
            return Redirect::to("/admin/orders.php").into_response();
        }
    };

    let items = sqlx::query_as::<_, OrderItemRow>(
        "SELECT id, order_id, product_id, variant_id, product_name, variant_name, sku,
                price, quantity, subtotal
         FROM order_items WHERE order_id = ? ORDER BY id ASC"
    )
    .bind(oid)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let timeline = sqlx::query_as::<_, OrderHistoryRow>(
        "SELECT h.id, h.order_id, h.status, h.note, h.user_id, u.name as admin_name, h.created_at
         FROM order_status_history h
         LEFT JOIN users u ON u.id = h.user_id
         WHERE h.order_id = ?
         ORDER BY h.created_at ASC"
    )
    .bind(oid)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let valid_next = match order.status.as_str() {
        "pending" => vec!["processing", "cancelled"],
        "processing" => vec!["shipped", "cancelled"],
        "shipped" => vec!["completed", "cancelled"],
        "completed" => vec!["cancelled"],
        _ => Vec::new(),
    };

    let mut ctx = build_admin_context(&state, &session, "order_view.php", &format!("Order {}", order.order_number)).await;
    ctx.insert("order", &order);
    ctx.insert("items", &items);
    ctx.insert("timeline", &timeline);
    ctx.insert("valid_next", &valid_next);

    match state.tera.render("admin/order_view.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

// =================================================================
// 4. POST /admin/order_view.php
// =================================================================

pub async fn admin_order_view_post_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<OrderViewQuery>,
    Form(form): Form<OrderViewActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("orders.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. orders.manage required.").await;
        return Redirect::to("/admin/orders.php").into_response();
    }

    let oid = query.id.unwrap_or(0);
    let order_opt = sqlx::query_as::<_, OrderDetailRow>(
        "SELECT id, order_number, customer_id, customer_name, customer_email, customer_phone,
                shipping_address, shipping_city, shipping_district, shipping_postal_code,
                subtotal, discount_total, shipping_fee, cod_fee, total,
                payment_method, payment_status, status, courier, tracking_number, notes,
                stock_restored, created_at, updated_at
         FROM orders WHERE id = ? LIMIT 1"
    )
    .bind(oid)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let order = match order_opt {
        Some(o) => o,
        None => {
            SessionHelper::add_flash(&session, "warning", "Order not found.").await;
            return Redirect::to("/admin/orders.php").into_response();
        }
    };

    let action = form.action.unwrap_or_default();

    if action == "update_status" {
        let new_status = form.status.unwrap_or_default().trim().to_lowercase();
        let note = form.note.unwrap_or_default().trim().to_string();

        if new_status == order.status {
            SessionHelper::add_flash(&session, "info", "Status remains unchanged.").await;
            return Redirect::to(&format!("/admin/order_view.php?id={}", oid)).into_response();
        }

        if !is_valid_status_transition(&order.status, &new_status) {
            SessionHelper::add_flash(
                &session,
                "danger",
                &format!("Invalid status transition from {} to {}.", order.status, new_status),
            )
            .await;
            return Redirect::to(&format!("/admin/order_view.php?id={}", oid)).into_response();
        }

        let mut tx = match state.db.begin().await {
            Ok(t) => t,
            Err(e) => {
                SessionHelper::add_flash(&session, "danger", &format!("Database error: {}", e)).await;
                return Redirect::to(&format!("/admin/order_view.php?id={}", oid)).into_response();
            }
        };

        let mut update_sql = "UPDATE orders SET status = ?".to_string();
        if new_status == "completed" && order.payment_method == "cod" {
            update_sql.push_str(", payment_status = 'paid'");
        }
        update_sql.push_str(" WHERE id = ?");

        let upd_res = sqlx::query(&update_sql)
            .bind(&new_status)
            .bind(oid)
            .execute(&mut *tx)
            .await;

        if let Err(e) = upd_res {
            let _ = tx.rollback().await;
            SessionHelper::add_flash(&session, "danger", &format!("Error updating status: {}", e)).await;
            return Redirect::to(&format!("/admin/order_view.php?id={}", oid)).into_response();
        }

        // Cancelled -> atomic stock restore
        if new_status == "cancelled" {
            let _ = stock_restore_for_order(&state.db, oid).await;
        }

        // Product sales summary
        if new_status == "completed" && order.status != "completed" {
            let _ = product_sales_summary_record_order(&state.db, oid).await;
        } else if new_status == "cancelled" && order.status == "completed" {
            let _ = product_sales_summary_reverse_order(&state.db, oid).await;
        }

        // Timeline entry
        let hist_note = if note.is_empty() {
            format!("Status changed from {} to {}", order.status, new_status)
        } else {
            note
        };

        let _ = sqlx::query(
            "INSERT INTO order_status_history (order_id, status, note, user_id, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        )
        .bind(oid)
        .bind(&new_status)
        .bind(&hist_note)
        .bind(Some(admin.id))
        .execute(&mut *tx)
        .await;

        let _ = tx.commit().await;

        log_activity(
            &state.db,
            Some(admin.id),
            "order.status",
            &format!("Order {} status updated: {} -> {}", order.order_number, order.status, new_status),
            None,
        )
        .await;

        SessionHelper::add_flash(&session, "success", &format!("Order status successfully updated to {}.", new_status)).await;
        return Redirect::to(&format!("/admin/order_view.php?id={}", oid)).into_response();
    }

    if action == "update_tracking" {
        let courier = form.courier.unwrap_or_default().trim().to_string();
        let tracking_number = form.tracking_number.unwrap_or_default().trim().to_string();
        let pay_st = form.payment_status.unwrap_or(order.payment_status.clone());
        let notes = form.notes.unwrap_or_default().trim().to_string();

        let allowed_pay = ["unpaid", "paid", "refunded"];
        let final_pay_st = if allowed_pay.contains(&pay_st.as_str()) {
            pay_st
        } else {
            order.payment_status.clone()
        };

        let _ = sqlx::query(
            "UPDATE orders
             SET courier = ?, tracking_number = ?, payment_status = ?, notes = ?, updated_at = NOW()
             WHERE id = ?"
        )
        .bind(if courier.is_empty() { None } else { Some(&courier) })
        .bind(if tracking_number.is_empty() { None } else { Some(&tracking_number) })
        .bind(&final_pay_st)
        .bind(if notes.is_empty() { None } else { Some(&notes) })
        .bind(oid)
        .execute(&state.db)
        .await;

        log_activity(
            &state.db,
            Some(admin.id),
            "order.update_details",
            &format!("Updated tracking / notes for order {}", order.order_number),
            None,
        )
        .await;

        SessionHelper::add_flash(&session, "success", "Order details updated.").await;
        return Redirect::to(&format!("/admin/order_view.php?id={}", oid)).into_response();
    }

    Redirect::to(&format!("/admin/order_view.php?id={}", oid)).into_response()
}

// =================================================================
// 5. GET /admin/order_print.php
// =================================================================

pub async fn admin_order_print_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<OrderPrintQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("orders.print") && !admin.has_permission("orders.view") && admin.role != "admin" {
        return (StatusCode::FORBIDDEN, "Access denied").into_response();
    }

    let id = query.id.unwrap_or(0);
    let order_num = query.order.or(query.order_number).unwrap_or_default().trim().to_string();

    let order_opt = if id > 0 {
        sqlx::query_as::<_, OrderDetailRow>(
            "SELECT id, order_number, customer_id, customer_name, customer_email, customer_phone,
                    shipping_address, shipping_city, shipping_district, shipping_postal_code,
                    subtotal, discount_total, shipping_fee, cod_fee, total,
                    payment_method, payment_status, status, courier, tracking_number, notes,
                    stock_restored, created_at, updated_at
             FROM orders WHERE id = ? LIMIT 1"
        )
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None)
    } else if !order_num.is_empty() {
        sqlx::query_as::<_, OrderDetailRow>(
            "SELECT id, order_number, customer_id, customer_name, customer_email, customer_phone,
                    shipping_address, shipping_city, shipping_district, shipping_postal_code,
                    subtotal, discount_total, shipping_fee, cod_fee, total,
                    payment_method, payment_status, status, courier, tracking_number, notes,
                    stock_restored, created_at, updated_at
             FROM orders WHERE order_number = ? LIMIT 1"
        )
        .bind(&order_num)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None)
    } else {
        None
    };

    let order = match order_opt {
        Some(o) => o,
        None => {
            return (
                StatusCode::NOT_FOUND,
                Html(r#"<!DOCTYPE html>
<html>
<head><title>Order Not Found</title></head>
<body style="font-family:sans-serif;text-align:center;padding:50px;">
  <h2>Order Not Found</h2>
  <p>The requested packing slip or invoice cannot be found.</p>
  <a href="/admin/orders.php">Back to Orders</a>
</body>
</html>"#),
            )
                .into_response();
        }
    };

    let items = sqlx::query_as::<_, OrderItemRow>(
        "SELECT id, order_id, product_id, variant_id, product_name, variant_name, sku,
                price, quantity, subtotal
         FROM order_items WHERE order_id = ? ORDER BY id ASC"
    )
    .bind(order.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let mut ctx = build_admin_context(&state, &session, "order_print.php", &format!("Invoice - {}", order.order_number)).await;
    ctx.insert("order", &order);
    ctx.insert("items", &items);

    match state.tera.render("admin/order_print.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}
