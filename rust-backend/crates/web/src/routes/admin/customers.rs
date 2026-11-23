use axum::{
    extract::{Query, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{Html, IntoResponse, Redirect, Response},
    Form,
};
use chrono::Utc;
use db::queries::activity::log_activity;
use db::queries::storage::StorageManager;
use rust_decimal::Decimal;
use serde::Deserialize;
use std::path::PathBuf;
use tower_sessions::Session;

use crate::state::{build_admin_context, AppState, SessionHelper};

// =================================================================
// Query / Form Models
// =================================================================

#[derive(Debug, Deserialize)]
pub struct CustomersQuery {
    pub q: Option<String>,
    pub status: Option<String>,
    pub marketing_only: Option<String>,
    pub per_page: Option<i64>,
    pub page: Option<i64>,
    pub export: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct CustomerActionForm {
    pub action: Option<String>,
    pub bulk_action: Option<String>,
    pub id: Option<u64>,
    #[serde(default)]
    pub selected_ids: Vec<u64>,
}

#[derive(Debug, Deserialize)]
pub struct CustomerViewQuery {
    pub id: Option<u64>,
    pub tab: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct CustomerViewActionForm {
    pub action: Option<String>,
    pub admin_note: Option<String>,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct CustomerListItem {
    pub id: u64,
    pub name: String,
    pub email: String,
    pub phone: Option<String>,
    pub status: String,
    pub email_verified_at: Option<chrono::NaiveDateTime>,
    pub marketing_opt_in: i8,
    pub created_at: chrono::NaiveDateTime,
    pub order_count: i64,
    pub lifetime_spend: Decimal,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct CustomerDetailRow {
    pub id: u64,
    pub name: String,
    pub email: String,
    pub phone: Option<String>,
    pub status: String,
    pub email_verified_at: Option<chrono::NaiveDateTime>,
    pub marketing_opt_in: i8,
    pub avatar: Option<String>,
    pub avatar_file_id: Option<u64>,
    pub admin_note: Option<String>,
    pub failed_logins: i32,
    pub locked_until: Option<chrono::NaiveDateTime>,
    pub created_at: chrono::NaiveDateTime,
    pub updated_at: chrono::NaiveDateTime,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct CustomerAddressRow {
    pub id: u64,
    pub customer_id: u64,
    pub label: String,
    pub full_name: String,
    pub phone: String,
    pub address_line1: String,
    pub address_line2: Option<String>,
    pub city: String,
    pub district: Option<String>,
    pub postal_code: Option<String>,
    pub is_default_shipping: i8,
    pub is_default_billing: i8,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct CustomerOrderRow {
    pub id: u64,
    pub order_number: String,
    pub total: Decimal,
    pub payment_method: String,
    pub payment_status: String,
    pub status: String,
    pub created_at: chrono::NaiveDateTime,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct CustomerWishlistRow {
    pub id: u64,
    pub product_id: u64,
    pub product_name: String,
    pub product_slug: String,
    pub price: Decimal,
    pub image: Option<String>,
    pub created_at: chrono::NaiveDateTime,
}

// =================================================================
// 1. GET /admin/customers.php
// =================================================================

pub async fn admin_customers_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<CustomersQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("customers.view") && !admin.has_permission("customers.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let search = query.q.unwrap_or_default().trim().to_string();
    let status = query.status.unwrap_or_default().trim().to_string();
    let marketing_only = query.marketing_only.as_deref() == Some("1");
    let per_page = query.per_page.unwrap_or(15).clamp(5, 100);
    let page = query.page.unwrap_or(1).max(1);
    let offset = (page - 1) * per_page;

    let mut where_clauses = vec!["c.deleted_at IS NULL".to_string()];
    if marketing_only {
        where_clauses.push("c.marketing_opt_in = 1".to_string());
    }
    if !status.is_empty() && ["active", "pending", "blocked"].contains(&status.as_str()) {
        where_clauses.push("c.status = ?".to_string());
    }
    if !search.is_empty() {
        where_clauses.push("(c.name LIKE CONCAT('%', ?, '%') OR c.email LIKE CONCAT('%', ?, '%') OR c.phone LIKE CONCAT('%', ?, '%'))".to_string());
    }

    let where_str = where_clauses.join(" AND ");

    // Handle CSV Export
    if query.export.as_deref() == Some("csv") {
        if !admin.has_permission("analytics.export") && admin.role != "admin" {
            SessionHelper::add_flash(&session, "danger", "Access denied. You do not have permission to export customer data.").await;
            return Redirect::to("/admin/customers.php").into_response();
        }

        let export_sql = format!(
            "SELECT c.id, c.name, c.email, c.phone, c.status, c.email_verified_at,
                    c.marketing_opt_in, c.created_at,
                    COALESCE(o.order_count, 0) AS order_count,
                    COALESCE(o.lifetime_spend, 0.0) AS lifetime_spend
             FROM customers c
             LEFT JOIN (
                 SELECT customer_id,
                        COUNT(*) AS order_count,
                        SUM(CASE WHEN status != 'cancelled' THEN total ELSE 0 END) AS lifetime_spend
                 FROM orders
                 WHERE customer_id IS NOT NULL
                 GROUP BY customer_id
             ) o ON o.customer_id = c.id
             WHERE {}
             ORDER BY c.created_at DESC",
            where_str
        );

        let mut q_exp = sqlx::query_as::<_, CustomerListItem>(&export_sql);
        if !status.is_empty() && ["active", "pending", "blocked"].contains(&status.as_str()) {
            q_exp = q_exp.bind(&status);
        }
        if !search.is_empty() {
            q_exp = q_exp.bind(&search).bind(&search).bind(&search);
        }

        let rows = q_exp.fetch_all(&state.db).await.unwrap_or_default();

        let mut csv_data = vec![0xEF, 0xBB, 0xBF]; // UTF-8 BOM
        {
            let mut writer = csv::Writer::from_writer(&mut csv_data);
            let _ = writer.write_record(&[
                "ID",
                "Name",
                "Email",
                "Phone",
                "Status",
                "Verified",
                "Marketing Opt-In",
                "Orders Count",
                "Lifetime Spend (BDT)",
                "Joined Date",
            ]);

            for r in rows {
                let _ = writer.write_record(&[
                    r.id.to_string(),
                    r.name,
                    r.email,
                    r.phone.unwrap_or_default(),
                    r.status,
                    if r.email_verified_at.is_some() { "Yes".to_string() } else { "No".to_string() },
                    if r.marketing_opt_in == 1 { "Yes".to_string() } else { "No".to_string() },
                    r.order_count.to_string(),
                    r.lifetime_spend.to_string(),
                    r.created_at.format("%Y-%m-%d %H:%M:%S").to_string(),
                ]);
            }
            let _ = writer.flush();
        }

        let mut headers = HeaderMap::new();
        headers.insert(header::CONTENT_TYPE, HeaderValue::from_static("text/csv; charset=utf-8"));
        let cd = format!("attachment; filename=\"customers-export-{}.csv\"", Utc::now().format("%Y%m%d_%H%M%S"));
        if let Ok(v) = HeaderValue::from_str(&cd) {
            headers.insert(header::CONTENT_DISPOSITION, v);
        }
        return (headers, csv_data).into_response();
    }

    // Pagination & List
    let count_sql = format!(
        "SELECT COUNT(*) FROM customers c WHERE {}",
        where_str
    );
    let mut q_cnt = sqlx::query_scalar::<_, i64>(&count_sql);
    if !status.is_empty() && ["active", "pending", "blocked"].contains(&status.as_str()) {
        q_cnt = q_cnt.bind(&status);
    }
    if !search.is_empty() {
        q_cnt = q_cnt.bind(&search).bind(&search).bind(&search);
    }
    let total_customers = q_cnt.fetch_one(&state.db).await.unwrap_or(0);
    let total_pages = ((total_customers as f64) / (per_page as f64)).ceil() as i64;

    let list_sql = format!(
        "SELECT c.id, c.name, c.email, c.phone, c.status, c.email_verified_at,
                c.marketing_opt_in, c.created_at,
                COALESCE(o.order_count, 0) AS order_count,
                COALESCE(o.lifetime_spend, 0.0) AS lifetime_spend
         FROM customers c
         LEFT JOIN (
             SELECT customer_id,
                    COUNT(*) AS order_count,
                    SUM(CASE WHEN status != 'cancelled' THEN total ELSE 0 END) AS lifetime_spend
             FROM orders
             WHERE customer_id IS NOT NULL
             GROUP BY customer_id
         ) o ON o.customer_id = c.id
         WHERE {}
         ORDER BY c.created_at DESC
         LIMIT ? OFFSET ?",
        where_str
    );

    let mut q_list = sqlx::query_as::<_, CustomerListItem>(&list_sql);
    if !status.is_empty() && ["active", "pending", "blocked"].contains(&status.as_str()) {
        q_list = q_list.bind(&status);
    }
    if !search.is_empty() {
        q_list = q_list.bind(&search).bind(&search).bind(&search);
    }
    q_list = q_list.bind(per_page).bind(offset);

    let customers = q_list.fetch_all(&state.db).await.unwrap_or_default();

    // KPIs
    let total_active: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM customers WHERE status = 'active' AND deleted_at IS NULL")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let total_verified: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM customers WHERE email_verified_at IS NOT NULL AND deleted_at IS NULL")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let total_blocked: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM customers WHERE status = 'blocked' AND deleted_at IS NULL")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    let mut ctx = build_admin_context(&state, &session, "customers.php", "Customers").await;
    ctx.insert("customers", &customers);
    ctx.insert("total_customers", &total_customers);
    ctx.insert("total_active", &total_active);
    ctx.insert("total_verified", &total_verified);
    ctx.insert("total_blocked", &total_blocked);
    ctx.insert("page", &page);
    ctx.insert("per_page", &per_page);
    ctx.insert("total_pages", &total_pages);
    ctx.insert("search", &search);
    ctx.insert("status", &status);
    ctx.insert("marketing_only", &marketing_only);

    match state.tera.render("admin/customers.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

// =================================================================
// 2. POST /admin/customers.php
// =================================================================

pub async fn admin_customers_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<CustomerActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("customers.manage") && !admin.has_permission("customers.delete") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. customers.manage required.").await;
        return Redirect::to("/admin/customers.php").into_response();
    }

    let action = form.action.or(form.bulk_action).unwrap_or_default();

    // 1. Single delete / anonymize
    if action == "delete_single" {
        let cid = form.id.unwrap_or(0);
        let cust_row: Option<(u64, String, String, Option<String>, Option<u64>)> = sqlx::query_as(
            "SELECT id, name, email, avatar, avatar_file_id FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        )
        .bind(cid)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if let Some((id, name, email, _avatar, avatar_file_id)) = cust_row {
            let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
            let storage = StorageManager::new(uploads_dir);
            if let Some(fid) = avatar_file_id {
                let _ = storage.delete_file(&state.db, fid).await;
            }

            // Unlink orders
            let _ = sqlx::query("UPDATE orders SET customer_id = NULL WHERE customer_id = ?")
                .bind(id)
                .execute(&state.db)
                .await;

            // Delete addresses, wishlists, tokens
            let _ = sqlx::query("DELETE FROM customer_addresses WHERE customer_id = ?").bind(id).execute(&state.db).await;
            let _ = sqlx::query("DELETE FROM wishlists WHERE customer_id = ?").bind(id).execute(&state.db).await;
            let _ = sqlx::query("DELETE FROM customer_tokens WHERE customer_id = ?").bind(id).execute(&state.db).await;

            // Anonymize
            let anon_email = format!("deleted+{}@invalid", id);
            let _ = sqlx::query(
                "UPDATE customers
                 SET name = 'Deleted customer', email = ?, phone = NULL, avatar = NULL,
                     admin_note = CONCAT(COALESCE(admin_note, ''), ' [Deleted by admin on ', NOW(), ']'),
                     status = 'blocked', deleted_at = NOW(), updated_at = NOW()
                 WHERE id = ?"
            )
            .bind(&anon_email)
            .bind(id)
            .execute(&state.db)
            .await;

            log_activity(&state.db, Some(admin.id), "customer.delete", &format!("Admin deleted/anonymized customer #{} ({})", id, email), None).await;
            SessionHelper::add_flash(&session, "success", &format!("Customer #{} ('{}') was permanently deleted and anonymized.", id, name)).await;
        } else {
            SessionHelper::add_flash(&session, "warning", "Customer not found or already deleted.").await;
        }
        return Redirect::to("/admin/customers.php").into_response();
    }

    // 2. Bulk actions
    let selected_ids = form.selected_ids;
    if selected_ids.is_empty() {
        SessionHelper::add_flash(&session, "warning", "Please select at least one customer.").await;
        return Redirect::to("/admin/customers.php").into_response();
    }

    let placeholders = vec!["?"; selected_ids.len()].join(",");

    if action == "delete" {
        let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
        let storage = StorageManager::new(uploads_dir);

        // Delete avatars
        let sel_query = format!("SELECT avatar_file_id FROM customers WHERE id IN ({}) AND avatar_file_id IS NOT NULL", placeholders);
        let mut q_av = sqlx::query_as::<_, (u64,)>(&sel_query);
        for id in &selected_ids {
            q_av = q_av.bind(id);
        }
        let av_rows = q_av.fetch_all(&state.db).await.unwrap_or_default();
        for (fid,) in av_rows {
            let _ = storage.delete_file(&state.db, fid).await;
        }

        // Unlink orders
        let unl_sql = format!("UPDATE orders SET customer_id = NULL WHERE customer_id IN ({})", placeholders);
        let mut q_unl = sqlx::query(&unl_sql);
        for id in &selected_ids { q_unl = q_unl.bind(id); }
        let _ = q_unl.execute(&state.db).await;

        // Delete addresses, wishlists, tokens
        let del_ad_sql = format!("DELETE FROM customer_addresses WHERE customer_id IN ({})", placeholders);
        let del_w_sql = format!("DELETE FROM wishlists WHERE customer_id IN ({})", placeholders);
        let del_t_sql = format!("DELETE FROM customer_tokens WHERE customer_id IN ({})", placeholders);
        let mut q_ad = sqlx::query(&del_ad_sql);
        let mut q_w = sqlx::query(&del_w_sql);
        let mut q_t = sqlx::query(&del_t_sql);
        for id in &selected_ids {
            q_ad = q_ad.bind(id);
            q_w = q_w.bind(id);
            q_t = q_t.bind(id);
        }
        let _ = q_ad.execute(&state.db).await;
        let _ = q_w.execute(&state.db).await;
        let _ = q_t.execute(&state.db).await;

        // Anonymize
        for id in &selected_ids {
            let anon = format!("deleted+{}@invalid", id);
            let _ = sqlx::query(
                "UPDATE customers
                 SET name = 'Deleted customer', email = ?, phone = NULL, avatar = NULL,
                     admin_note = CONCAT(COALESCE(admin_note, ''), ' [Bulk deleted by admin on ', NOW(), ']'),
                     status = 'blocked', deleted_at = NOW(), updated_at = NOW()
                 WHERE id = ?"
            )
            .bind(&anon)
            .bind(id)
            .execute(&state.db)
            .await;
        }

        log_activity(&state.db, Some(admin.id), "customer.bulk_delete", &format!("Bulk deleted/anonymized {} customers", selected_ids.len()), None).await;
        SessionHelper::add_flash(&session, "success", &format!("{} customer(s) permanently deleted and anonymized.", selected_ids.len())).await;
    } else if action == "block" {
        let blk_sql = format!("UPDATE customers SET status = 'blocked', updated_at = NOW() WHERE id IN ({})", placeholders);
        let mut q_blk = sqlx::query(&blk_sql);
        for id in &selected_ids { q_blk = q_blk.bind(id); }
        let _ = q_blk.execute(&state.db).await;

        log_activity(&state.db, Some(admin.id), "customer.bulk_block", &format!("Bulk blocked {} customers", selected_ids.len()), None).await;
        SessionHelper::add_flash(&session, "success", &format!("{} customer(s) blocked.", selected_ids.len())).await;
    } else if action == "activate" {
        let act_sql = format!("UPDATE customers SET status = 'active', updated_at = NOW() WHERE id IN ({})", placeholders);
        let mut q_act = sqlx::query(&act_sql);
        for id in &selected_ids { q_act = q_act.bind(id); }
        let _ = q_act.execute(&state.db).await;

        log_activity(&state.db, Some(admin.id), "customer.bulk_activate", &format!("Bulk activated {} customers", selected_ids.len()), None).await;
        SessionHelper::add_flash(&session, "success", &format!("{} customer(s) activated.", selected_ids.len())).await;
    }

    Redirect::to("/admin/customers.php").into_response()
}

// =================================================================
// 3. GET /admin/customer_view.php
// =================================================================

pub async fn admin_customer_view_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<CustomerViewQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("customers.view") && !admin.has_permission("customers.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let cid = query.id.unwrap_or(0);
    let cust_opt = sqlx::query_as::<_, CustomerDetailRow>(
        "SELECT id, name, email, phone, status, email_verified_at, marketing_opt_in,
                avatar, avatar_file_id, admin_note, failed_logins, locked_until,
                created_at, updated_at
         FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1"
    )
    .bind(cid)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let customer = match cust_opt {
        Some(c) => c,
        None => {
            SessionHelper::add_flash(&session, "danger", "Customer not found or has been permanently removed.").await;
            return Redirect::to("/admin/customers.php").into_response();
        }
    };

    // Unlinked guest orders with matching email
    let unlinked_count: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM orders WHERE customer_id IS NULL AND LOWER(TRIM(customer_email)) = ?"
    )
    .bind(customer.email.trim().to_lowercase())
    .fetch_one(&state.db)
    .await
    .unwrap_or(0);

    let orders = sqlx::query_as::<_, CustomerOrderRow>(
        "SELECT id, order_number, total, payment_method, payment_status, status, created_at
         FROM orders WHERE customer_id = ? ORDER BY created_at DESC"
    )
    .bind(cid)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let addresses = sqlx::query_as::<_, CustomerAddressRow>(
        "SELECT id, customer_id, label, full_name, phone, address_line1, address_line2,
                city, district, postal_code, is_default_shipping, is_default_billing
         FROM customer_addresses WHERE customer_id = ? ORDER BY is_default_shipping DESC, id ASC"
    )
    .bind(cid)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let wishlist = sqlx::query_as::<_, CustomerWishlistRow>(
        "SELECT w.id, w.product_id, p.name as product_name, p.slug as product_slug,
                p.price, p.image, w.created_at
         FROM wishlists w
         JOIN products p ON p.id = w.product_id
         WHERE w.customer_id = ?
         ORDER BY w.created_at DESC"
    )
    .bind(cid)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let active_tab = query.tab.unwrap_or_else(|| "profile".to_string());

    let mut ctx = build_admin_context(&state, &session, "customer_view.php", &format!("Customer #{} - {}", customer.id, customer.name)).await;
    ctx.insert("customer", &customer);
    ctx.insert("orders", &orders);
    ctx.insert("addresses", &addresses);
    ctx.insert("wishlist", &wishlist);
    ctx.insert("unlinked_count", &unlinked_count);
    ctx.insert("active_tab", &active_tab);

    match state.tera.render("admin/customer_view.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

// =================================================================
// 4. POST /admin/customer_view.php
// =================================================================

pub async fn admin_customer_view_post_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<CustomerViewQuery>,
    Form(form): Form<CustomerViewActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    let cid = query.id.unwrap_or(0);
    let cust_opt = sqlx::query_as::<_, CustomerDetailRow>(
        "SELECT id, name, email, phone, status, email_verified_at, marketing_opt_in,
                avatar, avatar_file_id, admin_note, failed_logins, locked_until,
                created_at, updated_at
         FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1"
    )
    .bind(cid)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let customer = match cust_opt {
        Some(c) => c,
        None => {
            SessionHelper::add_flash(&session, "danger", "Customer not found.").await;
            return Redirect::to("/admin/customers.php").into_response();
        }
    };

    let action = form.action.unwrap_or_default();

    // 1. Save Admin Note
    if action == "save_note" {
        let note = form.admin_note.unwrap_or_default().trim().to_string();
        if note.len() > 500 {
            SessionHelper::add_flash(&session, "danger", "Admin note cannot exceed 500 characters.").await;
        } else {
            let _ = sqlx::query("UPDATE customers SET admin_note = ?, updated_at = NOW() WHERE id = ?")
                .bind(if note.is_empty() { None } else { Some(&note) })
                .bind(cid)
                .execute(&state.db)
                .await;

            log_activity(&state.db, Some(admin.id), "customer.update_note", &format!("Updated note for customer #{}", cid), None).await;
            SessionHelper::add_flash(&session, "success", "Customer note updated.").await;
        }
        return Redirect::to(&format!("/admin/customer_view.php?id={}&tab=notes", cid)).into_response();
    }

    // Role check for administrative actions
    if admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Permission denied. Only administrators can perform this action.").await;
        return Redirect::to(&format!("/admin/customer_view.php?id={}", cid)).into_response();
    }

    // 2. Toggle Block
    if action == "toggle_block" {
        let new_status = if customer.status == "blocked" { "active" } else { "blocked" };
        let _ = sqlx::query("UPDATE customers SET status = ?, updated_at = NOW() WHERE id = ?")
            .bind(new_status)
            .bind(cid)
            .execute(&state.db)
            .await;

        if new_status == "blocked" {
            let _ = sqlx::query("DELETE FROM customer_tokens WHERE customer_id = ? AND type = 'remember_me'")
                .bind(cid)
                .execute(&state.db)
                .await;
            log_activity(&state.db, Some(admin.id), "customer.block", &format!("Blocked customer #{} ({})", cid, customer.email), None).await;
            SessionHelper::add_flash(&session, "warning", &format!("Customer #{} has been blocked.", cid)).await;
        } else {
            log_activity(&state.db, Some(admin.id), "customer.unblock", &format!("Unblocked customer #{} ({})", cid, customer.email), None).await;
            SessionHelper::add_flash(&session, "success", &format!("Customer #{} has been activated.", cid)).await;
        }
        return Redirect::to(&format!("/admin/customer_view.php?id={}", cid)).into_response();
    }

    // 3. Unlock Account
    if action == "unlock" {
        let _ = sqlx::query("UPDATE customers SET failed_logins = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?")
            .bind(cid)
            .execute(&state.db)
            .await;
        log_activity(&state.db, Some(admin.id), "customer.unlock", &format!("Unlocked customer #{} ({})", cid, customer.email), None).await;
        SessionHelper::add_flash(&session, "success", "Account unlocked and failed login counter reset.").await;
        return Redirect::to(&format!("/admin/customer_view.php?id={}", cid)).into_response();
    }

    // 4. Mark Verified & Auto-claim guest orders
    if action == "mark_verified" {
        let _ = sqlx::query("UPDATE customers SET email_verified_at = NOW(), status = IF(status = 'pending', 'active', status), updated_at = NOW() WHERE id = ?")
            .bind(cid)
            .execute(&state.db)
            .await;

        let claimed_res = sqlx::query(
            "UPDATE orders
             SET customer_id = ?
             WHERE customer_id IS NULL AND LOWER(TRIM(customer_email)) = ?"
        )
        .bind(cid)
        .bind(customer.email.trim().to_lowercase())
        .execute(&state.db)
        .await;

        let claimed = claimed_res.map(|r| r.rows_affected()).unwrap_or(0);

        log_activity(&state.db, Some(admin.id), "customer.mark_verified", &format!("Admin marked email verified for customer #{} (claimed {} orders)", cid, claimed), None).await;
        let msg = format!("Email marked as verified.{}", if claimed > 0 { format!(" Claimed {} past guest order(s).", claimed) } else { "".to_string() });
        SessionHelper::add_flash(&session, "success", &msg).await;
        return Redirect::to(&format!("/admin/customer_view.php?id={}", cid)).into_response();
    }

    // 5. Link Guest Orders
    if action == "link_guest_orders" {
        let res = sqlx::query(
            "UPDATE orders
             SET customer_id = ?
             WHERE customer_id IS NULL AND LOWER(TRIM(customer_email)) = ?"
        )
        .bind(cid)
        .bind(customer.email.trim().to_lowercase())
        .execute(&state.db)
        .await;

        let count = res.map(|r| r.rows_affected()).unwrap_or(0);
        log_activity(&state.db, Some(admin.id), "customer.link_orders", &format!("Linked {} guest orders to customer #{}", count, cid), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Successfully linked {} past guest order(s) to this customer.", count)).await;
        return Redirect::to(&format!("/admin/customer_view.php?id={}&tab=orders", cid)).into_response();
    }

    // 6. Delete / Anonymize Customer Account
    if action == "delete" {
        let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
        let storage = StorageManager::new(uploads_dir);
        if let Some(fid) = customer.avatar_file_id {
            let _ = storage.delete_file(&state.db, fid).await;
        }

        let _ = sqlx::query("UPDATE orders SET customer_id = NULL WHERE customer_id = ?").bind(cid).execute(&state.db).await;
        let _ = sqlx::query("DELETE FROM customer_addresses WHERE customer_id = ?").bind(cid).execute(&state.db).await;
        let _ = sqlx::query("DELETE FROM wishlists WHERE customer_id = ?").bind(cid).execute(&state.db).await;
        let _ = sqlx::query("DELETE FROM customer_tokens WHERE customer_id = ?").bind(cid).execute(&state.db).await;

        let anon_email = format!("deleted+{}@invalid", cid);
        let _ = sqlx::query(
            "UPDATE customers
             SET name = 'Deleted customer', email = ?, phone = NULL, avatar = NULL,
                 admin_note = CONCAT(COALESCE(admin_note, ''), ' [Deleted by admin on ', NOW(), ']'),
                 status = 'blocked', deleted_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        )
        .bind(&anon_email)
        .bind(cid)
        .execute(&state.db)
        .await;

        log_activity(&state.db, Some(admin.id), "customer.delete", &format!("Admin deleted customer #{} ({})", cid, customer.email), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Customer #{} was permanently deleted and anonymized.", cid)).await;
        return Redirect::to("/admin/customers.php").into_response();
    }

    Redirect::to(&format!("/admin/customer_view.php?id={}", cid)).into_response()
}
