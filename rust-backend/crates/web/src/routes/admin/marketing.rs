use axum::{
    extract::{Multipart, Query, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{Html, IntoResponse, Redirect, Response},
    Form,
};
use chrono::Utc;
use common::crypto::admin_password_hash;
use common::formatters::slugify;
use db::queries::activity::log_activity;
use db::queries::admin::get_unique_slug;
use db::queries::storage::{StorageManager, StoreOptions};
use rust_decimal::Decimal;
use serde::Deserialize;
use std::path::PathBuf;
use tower_sessions::Session;

use crate::state::{build_admin_context, AppState, SessionHelper};

// =================================================================
// 0. Helper: product_recalculate_rating
// =================================================================

pub async fn product_recalculate_rating(pool: &sqlx::MySqlPool, product_id: u64) {
    let row: Option<(i64, Option<Decimal>)> = sqlx::query_as(
        "SELECT COUNT(*) as cnt, AVG(rating) as avg_score
         FROM product_reviews
         WHERE product_id = ? AND status = 'approved'",
    )
    .bind(product_id)
    .fetch_optional(pool)
    .await
    .unwrap_or(None);

    let (cnt, avg) = match row {
        Some((c, Some(a))) if c > 0 => (c as u32, a),
        _ => (0, Decimal::ZERO),
    };

    let _ = sqlx::query("UPDATE products SET rating_count = ?, rating_avg = ? WHERE id = ?")
        .bind(cnt)
        .bind(avg)
        .bind(product_id)
        .execute(pool)
        .await;
}

// =================================================================
// 1. Product Reviews (reviews.php)
// =================================================================

#[derive(Debug, Deserialize)]
pub struct ReviewsQuery {
    pub status: Option<String>,
    pub q: Option<String>,
    pub page: Option<i64>,
    pub per_page: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct ReviewActionForm {
    pub action: Option<String>,
    pub id: Option<u64>,
    pub status: Option<String>,
    pub admin_reply: Option<String>,
    pub bulk_op: Option<String>,
    #[serde(default)]
    pub selected_ids: Vec<u64>,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct ReviewListItem {
    pub id: u64,
    pub product_id: u64,
    pub product_name: String,
    pub product_image: Option<String>,
    pub customer_id: Option<u64>,
    pub author_name: String,
    pub rating: u8,
    pub title: Option<String>,
    pub body: String,
    pub status: String,
    pub admin_reply: Option<String>,
    pub ip: Option<String>,
    pub created_at: chrono::NaiveDateTime,
}

pub async fn admin_reviews_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<ReviewsQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("reviews.view") && !admin.has_permission("reviews.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let status_filter = query.status.unwrap_or_else(|| "all".to_string());
    let search = query.q.unwrap_or_default().trim().to_string();
    let per_page = query.per_page.unwrap_or(20).clamp(5, 100);
    let page = query.page.unwrap_or(1).max(1);
    let offset = (page - 1) * per_page;

    let mut where_clauses = Vec::new();
    if status_filter != "all" && ["pending", "approved", "rejected"].contains(&status_filter.as_str()) {
        where_clauses.push("r.status = ?".to_string());
    }
    if !search.is_empty() {
        where_clauses.push("(r.author_name LIKE CONCAT('%', ?, '%') OR r.title LIKE CONCAT('%', ?, '%') OR r.body LIKE CONCAT('%', ?, '%') OR p.name LIKE CONCAT('%', ?, '%'))".to_string());
    }

    let where_sql = if where_clauses.is_empty() {
        String::new()
    } else {
        format!("WHERE {}", where_clauses.join(" AND "))
    };

    let count_sql = format!(
        "SELECT COUNT(*) FROM product_reviews r JOIN products p ON p.id = r.product_id {}",
        where_sql
    );
    let mut q_cnt = sqlx::query_scalar::<_, i64>(&count_sql);
    if status_filter != "all" && ["pending", "approved", "rejected"].contains(&status_filter.as_str()) {
        q_cnt = q_cnt.bind(&status_filter);
    }
    if !search.is_empty() {
        q_cnt = q_cnt.bind(&search).bind(&search).bind(&search).bind(&search);
    }
    let total_reviews = q_cnt.fetch_one(&state.db).await.unwrap_or(0);
    let total_pages = ((total_reviews as f64) / (per_page as f64)).ceil() as i64;

    let list_sql = format!(
        "SELECT r.id, r.product_id, p.name as product_name, p.image as product_image,
                r.customer_id, r.author_name, r.rating, r.title, r.body, r.status,
                r.admin_reply, r.ip, r.created_at
         FROM product_reviews r
         JOIN products p ON p.id = r.product_id
         {}
         ORDER BY r.id DESC
         LIMIT ? OFFSET ?",
        where_sql
    );
    let mut q_list = sqlx::query_as::<_, ReviewListItem>(&list_sql);
    if status_filter != "all" && ["pending", "approved", "rejected"].contains(&status_filter.as_str()) {
        q_list = q_list.bind(&status_filter);
    }
    if !search.is_empty() {
        q_list = q_list.bind(&search).bind(&search).bind(&search).bind(&search);
    }
    q_list = q_list.bind(per_page).bind(offset);

    let reviews = q_list.fetch_all(&state.db).await.unwrap_or_default();

    let count_pending: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM product_reviews WHERE status = 'pending'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_approved: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM product_reviews WHERE status = 'approved'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_rejected: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM product_reviews WHERE status = 'rejected'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    let mut ctx = build_admin_context(&state, &session, "reviews.php", "Product Reviews").await;
    ctx.insert("reviews", &reviews);
    ctx.insert("status_filter", &status_filter);
    ctx.insert("search", &search);
    ctx.insert("page", &page);
    ctx.insert("per_page", &per_page);
    ctx.insert("total_pages", &total_pages);
    ctx.insert("total_reviews", &total_reviews);
    ctx.insert("count_pending", &count_pending);
    ctx.insert("count_approved", &count_approved);
    ctx.insert("count_rejected", &count_rejected);

    match state.tera.render("admin/reviews.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_reviews_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<ReviewActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("reviews.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. reviews.manage required.").await;
        return Redirect::to("/admin/reviews.php").into_response();
    }

    let action = form.action.unwrap_or_default();

    if action == "set_status" {
        let rid = form.id.unwrap_or(0);
        let status = form.status.unwrap_or_default();
        let target_st = if ["approved", "rejected", "pending"].contains(&status.as_str()) {
            status
        } else {
            "pending".to_string()
        };

        let prod_row: Option<(u64,)> = sqlx::query_as("SELECT product_id FROM product_reviews WHERE id = ?")
            .bind(rid)
            .fetch_optional(&state.db)
            .await
            .unwrap_or(None);

        if let Some((pid,)) = prod_row {
            let _ = sqlx::query("UPDATE product_reviews SET status = ?, updated_at = NOW() WHERE id = ?")
                .bind(&target_st)
                .bind(rid)
                .execute(&state.db)
                .await;

            product_recalculate_rating(&state.db, pid).await;
            log_activity(&state.db, Some(admin.id), "review.status", &format!("Set review #{} status to {}", rid, target_st), None).await;
            SessionHelper::add_flash(&session, "success", &format!("Review #{} status set to {}.", rid, target_st)).await;
        }
        return Redirect::to("/admin/reviews.php").into_response();
    }

    if action == "reply" {
        let rid = form.id.unwrap_or(0);
        let reply = form.admin_reply.unwrap_or_default().trim().to_string();

        let _ = sqlx::query("UPDATE product_reviews SET admin_reply = ?, updated_at = NOW() WHERE id = ?")
            .bind(if reply.is_empty() { None } else { Some(&reply) })
            .bind(rid)
            .execute(&state.db)
            .await;

        log_activity(&state.db, Some(admin.id), "review.reply", &format!("Replied to review #{}", rid), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Reply saved for review #{}.", rid)).await;
        return Redirect::to("/admin/reviews.php").into_response();
    }

    if action == "delete" {
        let rid = form.id.unwrap_or(0);
        let prod_row: Option<(u64,)> = sqlx::query_as("SELECT product_id FROM product_reviews WHERE id = ?")
            .bind(rid)
            .fetch_optional(&state.db)
            .await
            .unwrap_or(None);

        if let Some((pid,)) = prod_row {
            let _ = sqlx::query("DELETE FROM product_reviews WHERE id = ?")
                .bind(rid)
                .execute(&state.db)
                .await;

            product_recalculate_rating(&state.db, pid).await;
            log_activity(&state.db, Some(admin.id), "review.delete", &format!("Deleted review #{}", rid), None).await;
            SessionHelper::add_flash(&session, "success", &format!("Review #{} deleted.", rid)).await;
        }
        return Redirect::to("/admin/reviews.php").into_response();
    }

    if action == "bulk" {
        let bulk_op = form.bulk_op.unwrap_or_default();
        let selected_ids = form.selected_ids;

        if !selected_ids.is_empty() {
            let placeholders = vec!["?"; selected_ids.len()].join(",");
            let p_sql = format!("SELECT DISTINCT product_id FROM product_reviews WHERE id IN ({})", placeholders);
            let mut q_p = sqlx::query_as::<_, (u64,)>(&p_sql);
            for id in &selected_ids { q_p = q_p.bind(id); }
            let pids: Vec<u64> = q_p.fetch_all(&state.db).await.unwrap_or_default().into_iter().map(|(p,)| p).collect();

            if bulk_op == "approve" {
                let up_sql = format!("UPDATE product_reviews SET status = 'approved', updated_at = NOW() WHERE id IN ({})", placeholders);
                let mut q_up = sqlx::query(&up_sql);
                for id in &selected_ids { q_up = q_up.bind(id); }
                let _ = q_up.execute(&state.db).await;
                SessionHelper::add_flash(&session, "success", &format!("{} review(s) approved.", selected_ids.len())).await;
            } else if bulk_op == "reject" {
                let up_sql = format!("UPDATE product_reviews SET status = 'rejected', updated_at = NOW() WHERE id IN ({})", placeholders);
                let mut q_up = sqlx::query(&up_sql);
                for id in &selected_ids { q_up = q_up.bind(id); }
                let _ = q_up.execute(&state.db).await;
                SessionHelper::add_flash(&session, "success", &format!("{} review(s) rejected.", selected_ids.len())).await;
            } else if bulk_op == "delete" && admin.role == "admin" {
                let del_sql = format!("DELETE FROM product_reviews WHERE id IN ({})", placeholders);
                let mut q_del = sqlx::query(&del_sql);
                for id in &selected_ids { q_del = q_del.bind(id); }
                let _ = q_del.execute(&state.db).await;
                SessionHelper::add_flash(&session, "success", &format!("{} review(s) deleted.", selected_ids.len())).await;
            }

            for pid in pids {
                product_recalculate_rating(&state.db, pid).await;
            }
        }
    }

    Redirect::to("/admin/reviews.php").into_response()
}

// =================================================================
// 2. Customer Messages (messages.php)
// =================================================================

#[derive(Debug, Deserialize)]
pub struct MessagesQuery {
    pub status: Option<String>,
    pub q: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct MessageActionForm {
    pub action: Option<String>,
    pub id: Option<u64>,
    pub status: Option<String>,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct ContactMessageItem {
    pub id: u64,
    pub name: String,
    pub email: String,
    pub phone: Option<String>,
    pub subject: Option<String>,
    pub message: String,
    pub order_number: Option<String>,
    pub status: String,
    pub ip: Option<String>,
    pub created_at: chrono::NaiveDateTime,
}

pub async fn admin_messages_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<MessagesQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("messages.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let status_filter = query.status.unwrap_or_else(|| "all".to_string());
    let search = query.q.unwrap_or_default().trim().to_string();

    let mut where_clauses = Vec::new();
    if status_filter != "all" && ["new", "read", "replied", "spam"].contains(&status_filter.as_str()) {
        where_clauses.push("status = ?".to_string());
    }
    if !search.is_empty() {
        where_clauses.push("(name LIKE CONCAT('%', ?, '%') OR email LIKE CONCAT('%', ?, '%') OR phone LIKE CONCAT('%', ?, '%') OR subject LIKE CONCAT('%', ?, '%') OR message LIKE CONCAT('%', ?, '%') OR order_number LIKE CONCAT('%', ?, '%'))".to_string());
    }

    let where_sql = if where_clauses.is_empty() {
        String::new()
    } else {
        format!("WHERE {}", where_clauses.join(" AND "))
    };

    let count_new: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_read: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM contact_messages WHERE status = 'read'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_replied: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM contact_messages WHERE status = 'replied'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_total: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM contact_messages")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    let list_sql = format!(
        "SELECT id, name, email, phone, subject, message, order_number, status, ip, created_at
         FROM contact_messages {} ORDER BY id DESC LIMIT 100",
        where_sql
    );
    let mut q = sqlx::query_as::<_, ContactMessageItem>(&list_sql);
    if status_filter != "all" && ["new", "read", "replied", "spam"].contains(&status_filter.as_str()) {
        q = q.bind(&status_filter);
    }
    if !search.is_empty() {
        q = q.bind(&search).bind(&search).bind(&search).bind(&search).bind(&search).bind(&search);
    }

    let messages = q.fetch_all(&state.db).await.unwrap_or_default();

    let mut ctx = build_admin_context(&state, &session, "messages.php", "Customer Messages").await;
    ctx.insert("messages", &messages);
    ctx.insert("status_filter", &status_filter);
    ctx.insert("search", &search);
    ctx.insert("count_new", &count_new);
    ctx.insert("count_read", &count_read);
    ctx.insert("count_replied", &count_replied);
    ctx.insert("count_total", &count_total);

    match state.tera.render("admin/messages.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_messages_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<MessageActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("messages.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. messages.manage required.").await;
        return Redirect::to("/admin/messages.php").into_response();
    }

    let action = form.action.unwrap_or_default();

    if action == "set_status" {
        let mid = form.id.unwrap_or(0);
        let raw_st = form.status.unwrap_or_default();
        let target_st = if ["new", "read", "replied", "spam"].contains(&raw_st.as_str()) {
            raw_st
        } else {
            "read".to_string()
        };

        let _ = sqlx::query("UPDATE contact_messages SET status = ? WHERE id = ?")
            .bind(&target_st)
            .bind(mid)
            .execute(&state.db)
            .await;

        SessionHelper::add_flash(&session, "success", &format!("Message #{} marked as {}.", mid, target_st)).await;
    } else if action == "delete" {
        let mid = form.id.unwrap_or(0);
        let _ = sqlx::query("DELETE FROM contact_messages WHERE id = ?").bind(mid).execute(&state.db).await;
        log_activity(&state.db, Some(admin.id), "message.delete", &format!("Deleted contact message #{}", mid), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Message #{} deleted.", mid)).await;
    }

    Redirect::to("/admin/messages.php").into_response()
}

// =================================================================
// 3. Newsletter Subscribers (subscribers.php)
// =================================================================

#[derive(Debug, Deserialize)]
pub struct SubscribersQuery {
    pub status: Option<String>,
    pub export: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct SubscriberActionForm {
    pub action: Option<String>,
    pub id: Option<u64>,
    pub status: Option<String>,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct SubscriberItem {
    pub id: u64,
    pub email: String,
    pub status: String,
    pub source: Option<String>,
    pub ip: Option<String>,
    pub created_at: chrono::NaiveDateTime,
    pub confirmed_at: Option<chrono::NaiveDateTime>,
}

pub async fn admin_subscribers_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<SubscribersQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("subscribers.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let status_filter = query.status.unwrap_or_else(|| "all".to_string());

    let mut where_clause = String::new();
    if status_filter == "active" || status_filter == "subscribed" {
        where_clause = "WHERE status IN ('active', 'subscribed')".to_string();
    } else if ["pending", "unsubscribed"].contains(&status_filter.as_str()) {
        where_clause = format!("WHERE status = '{}'", status_filter);
    }

    // CSV Export
    if query.export.as_deref() == Some("csv") {
        let export_sql = format!(
            "SELECT id, email, status, source, ip, created_at, confirmed_at
             FROM newsletter_subscribers {} ORDER BY id DESC",
            where_clause
        );
        let subs = sqlx::query_as::<_, SubscriberItem>(&export_sql)
            .fetch_all(&state.db)
            .await
            .unwrap_or_default();

        let mut csv_data = vec![0xEF, 0xBB, 0xBF];
        {
            let mut writer = csv::Writer::from_writer(&mut csv_data);
            let _ = writer.write_record(&["ID", "Email", "Status", "Source", "IP Address", "Subscribed At", "Confirmed At"]);
            for s in subs {
                let _ = writer.write_record(&[
                    s.id.to_string(),
                    s.email,
                    s.status,
                    s.source.unwrap_or_default(),
                    s.ip.unwrap_or_default(),
                    s.created_at.format("%Y-%m-%d %H:%M:%S").to_string(),
                    s.confirmed_at.map(|c| c.format("%Y-%m-%d %H:%M:%S").to_string()).unwrap_or_default(),
                ]);
            }
            let _ = writer.flush();
        }

        let mut headers = HeaderMap::new();
        headers.insert(header::CONTENT_TYPE, HeaderValue::from_static("text/csv; charset=utf-8"));
        let cd = format!("attachment; filename=\"newsletter_subscribers_{}.csv\"", Utc::now().format("%Y%m%d_%H%M%S"));
        if let Ok(v) = HeaderValue::from_str(&cd) {
            headers.insert(header::CONTENT_DISPOSITION, v);
        }
        return (headers, csv_data).into_response();
    }

    let list_sql = format!(
        "SELECT id, email, status, source, ip, created_at, confirmed_at
         FROM newsletter_subscribers {} ORDER BY id DESC LIMIT 200",
        where_clause
    );
    let subscribers = sqlx::query_as::<_, SubscriberItem>(&list_sql)
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();

    let count_total: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM newsletter_subscribers")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_subscribed: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM newsletter_subscribers WHERE status IN ('active', 'subscribed')")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_pending: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'pending'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_unsubscribed: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'unsubscribed'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    let mut ctx = build_admin_context(&state, &session, "subscribers.php", "Newsletter Subscribers").await;
    ctx.insert("subscribers", &subscribers);
    ctx.insert("status_filter", &status_filter);
    ctx.insert("count_total", &count_total);
    ctx.insert("count_subscribed", &count_subscribed);
    ctx.insert("count_pending", &count_pending);
    ctx.insert("count_unsubscribed", &count_unsubscribed);

    match state.tera.render("admin/subscribers.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_subscribers_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<SubscriberActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("subscribers.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. subscribers.manage required.").await;
        return Redirect::to("/admin/subscribers.php").into_response();
    }

    let action = form.action.unwrap_or_default();

    if action == "set_status" {
        let sid = form.id.unwrap_or(0);
        let raw_st = form.status.unwrap_or_default();
        let target_st = if ["active", "subscribed", "pending", "unsubscribed"].contains(&raw_st.as_str()) {
            raw_st
        } else {
            "subscribed".to_string()
        };

        let _ = sqlx::query("UPDATE newsletter_subscribers SET status = ? WHERE id = ?")
            .bind(&target_st)
            .bind(sid)
            .execute(&state.db)
            .await;

        SessionHelper::add_flash(&session, "success", &format!("Subscriber #{} status set to {}.", sid, target_st)).await;
    } else if action == "delete" {
        let sid = form.id.unwrap_or(0);
        let _ = sqlx::query("DELETE FROM newsletter_subscribers WHERE id = ?").bind(sid).execute(&state.db).await;
        log_activity(&state.db, Some(admin.id), "subscriber.delete", &format!("Deleted subscriber #{}", sid), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Subscriber #{} deleted.", sid)).await;
    }

    Redirect::to("/admin/subscribers.php").into_response()
}

// =================================================================
// 4. Storefront Banners (banners.php & banner_form.php)
// =================================================================

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct BannerItem {
    pub id: u64,
    pub title: String,
    pub subtitle: Option<String>,
    pub button_text: Option<String>,
    pub button_url: Option<String>,
    pub image: Option<String>,
    pub file_id: Option<u64>,
    pub placement: String,
    pub position: i32,
    pub status: String,
    pub starts_at: Option<chrono::NaiveDateTime>,
    pub ends_at: Option<chrono::NaiveDateTime>,
}

#[derive(Debug, Deserialize)]
pub struct BannerActionForm {
    pub action: Option<String>,
    pub id: Option<u64>,
}

pub async fn admin_banners_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("banners.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let banners = sqlx::query_as::<_, BannerItem>(
        "SELECT id, title, subtitle, button_text, button_url, image, file_id,
                placement, position, status, starts_at, ends_at
         FROM storefront_banners
         ORDER BY placement ASC, position ASC, id DESC"
    )
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let mut ctx = build_admin_context(&state, &session, "banners.php", "Storefront Banners").await;
    ctx.insert("banners", &banners);

    match state.tera.render("admin/banners.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_banners_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<BannerActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("banners.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/banners.php").into_response();
    }

    if form.action.as_deref() == Some("delete") {
        let bid = form.id.unwrap_or(0);
        let banner_row: Option<(String, Option<u64>)> = sqlx::query_as(
            "SELECT title, file_id FROM storefront_banners WHERE id = ?"
        )
        .bind(bid)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if let Some((title, file_id)) = banner_row {
            let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
            let storage = StorageManager::new(uploads_dir);
            if let Some(fid) = file_id {
                let _ = storage.delete_file(&state.db, fid).await;
            }

            let _ = sqlx::query("DELETE FROM storefront_banners WHERE id = ?")
                .bind(bid)
                .execute(&state.db)
                .await;

            log_activity(&state.db, Some(admin.id), "banner.delete", &format!("Deleted banner #{}: {}", bid, title), None).await;
            SessionHelper::add_flash(&session, "success", &format!("Banner \"{}\" deleted.", title)).await;
        }
    }

    Redirect::to("/admin/banners.php").into_response()
}

#[derive(Debug, Deserialize)]
pub struct BannerFormQuery {
    pub id: Option<u64>,
}

pub async fn admin_banner_form_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<BannerFormQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("banners.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/banners.php").into_response();
    }

    let bid = query.id.unwrap_or(0);
    let banner = if bid > 0 {
        sqlx::query_as::<_, BannerItem>(
            "SELECT id, title, subtitle, button_text, button_url, image, file_id,
                    placement, position, status, starts_at, ends_at
             FROM storefront_banners WHERE id = ? LIMIT 1"
        )
        .bind(bid)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None)
    } else {
        None
    };

    let title_str = if bid > 0 { "Edit Banner" } else { "Add New Banner" };
    let mut ctx = build_admin_context(&state, &session, "banner_form.php", title_str).await;
    let banner_data = match banner {
        Some(b) => serde_json::to_value(b).unwrap_or_default(),
        None => serde_json::json!({
            "id": 0, "title": "", "subtitle": "", "button_text": "", "button_url": "",
            "image": null, "placement": "hero", "position": 0, "status": "active",
            "starts_at": null, "ends_at": null
        }),
    };
    ctx.insert("banner", &banner_data);
    ctx.insert("id", &bid);

    match state.tera.render("admin/banner_form.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_banner_form_post_handler(
    State(state): State<AppState>,
    session: Session,
    mut multipart: Multipart,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("banners.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/banners.php").into_response();
    }

    let mut id = 0u64;
    let mut title = String::new();
    let mut subtitle = String::new();
    let mut button_text = String::new();
    let mut button_url = String::new();
    let mut placement = "hero".to_string();
    let mut position = 0i32;
    let mut status = "active".to_string();
    let mut uploaded_file: Option<(String, Vec<u8>)> = None;

    while let Ok(Some(field)) = multipart.next_field().await {
        let name = field.name().unwrap_or("").to_string();
        if name == "image" {
            let filename = field.file_name().unwrap_or("banner.jpg").to_string();
            if let Ok(bytes) = field.bytes().await {
                if !bytes.is_empty() {
                    uploaded_file = Some((filename, bytes.to_vec()));
                }
            }
        } else if let Ok(val) = field.text().await {
            match name.as_str() {
                "id" => id = val.parse().unwrap_or(0),
                "title" => title = val.trim().to_string(),
                "subtitle" => subtitle = val.trim().to_string(),
                "button_text" => button_text = val.trim().to_string(),
                "button_url" => button_url = val.trim().to_string(),
                "placement" => placement = val.trim().to_string(),
                "position" => position = val.parse().unwrap_or(0),
                "status" => status = val.trim().to_string(),
                _ => {}
            }
        }
    }

    if title.is_empty() {
        SessionHelper::add_flash(&session, "danger", "Title is required.").await;
        return Redirect::to("/admin/banners.php").into_response();
    }

    let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
    let storage = StorageManager::new(uploads_dir);

    let mut new_img_key: Option<String> = None;
    let mut new_fid: Option<u64> = None;

    if let Some((fname, bytes)) = uploaded_file {
        if let Ok(info) = storage
            .store_file(
                &state.db,
                "banner",
                &fname,
                &bytes,
                StoreOptions {
                    owner_type: Some("banner".to_string()),
                    owner_id: if id > 0 { Some(id) } else { None },
                    visibility: Some("public".to_string()),
                    is_test: false,
                },
            )
            .await
        {
            new_img_key = Some(info.storage_key);
            new_fid = Some(info.id);
        }
    }

    if id > 0 {
        if let (Some(k), Some(fid)) = (new_img_key, new_fid) {
            let _ = sqlx::query(
                "UPDATE storefront_banners
                 SET title = ?, subtitle = ?, button_text = ?, button_url = ?,
                     image = ?, file_id = ?, placement = ?, position = ?, status = ?
                 WHERE id = ?"
            )
            .bind(&title)
            .bind(if subtitle.is_empty() { None } else { Some(&subtitle) })
            .bind(if button_text.is_empty() { None } else { Some(&button_text) })
            .bind(if button_url.is_empty() { None } else { Some(&button_url) })
            .bind(&k)
            .bind(fid)
            .bind(&placement)
            .bind(position)
            .bind(&status)
            .bind(id)
            .execute(&state.db)
            .await;
        } else {
            let _ = sqlx::query(
                "UPDATE storefront_banners
                 SET title = ?, subtitle = ?, button_text = ?, button_url = ?,
                     placement = ?, position = ?, status = ?
                 WHERE id = ?"
            )
            .bind(&title)
            .bind(if subtitle.is_empty() { None } else { Some(&subtitle) })
            .bind(if button_text.is_empty() { None } else { Some(&button_text) })
            .bind(if button_url.is_empty() { None } else { Some(&button_url) })
            .bind(&placement)
            .bind(position)
            .bind(&status)
            .bind(id)
            .execute(&state.db)
            .await;
        }

        log_activity(&state.db, Some(admin.id), "banner.update", &format!("Updated banner #{}: {}", id, title), None).await;
        SessionHelper::add_flash(&session, "success", "Banner updated successfully.").await;
    } else {
        let _ = sqlx::query(
            "INSERT INTO storefront_banners
                (title, subtitle, button_text, button_url, image, file_id, placement, position, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )
        .bind(&title)
        .bind(if subtitle.is_empty() { None } else { Some(&subtitle) })
        .bind(if button_text.is_empty() { None } else { Some(&button_text) })
        .bind(if button_url.is_empty() { None } else { Some(&button_url) })
        .bind(&new_img_key)
        .bind(new_fid)
        .bind(&placement)
        .bind(position)
        .bind(&status)
        .execute(&state.db)
        .await;

        log_activity(&state.db, Some(admin.id), "banner.create", &format!("Created banner: {}", title), None).await;
        SessionHelper::add_flash(&session, "success", "Banner created successfully.").await;
    }

    Redirect::to("/admin/banners.php").into_response()
}

// =================================================================
// 5. Content Pages (pages.php & page_form.php)
// =================================================================

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct PageItem {
    pub id: u64,
    pub slug: String,
    pub title: String,
    pub content: String,
    pub meta_title: Option<String>,
    pub meta_description: Option<String>,
    pub show_in_footer: i8,
    pub show_in_header: i8,
    pub position: i32,
    pub status: String,
    pub created_at: chrono::NaiveDateTime,
    pub updated_at: chrono::NaiveDateTime,
}

#[derive(Debug, Deserialize)]
pub struct PageActionForm {
    pub action: Option<String>,
    pub id: Option<u64>,
}

pub async fn admin_pages_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("pages.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let pages = sqlx::query_as::<_, PageItem>(
        "SELECT id, slug, title, content, meta_title, meta_description,
                show_in_footer, show_in_header, position, status, created_at, updated_at
         FROM pages ORDER BY position ASC, id ASC"
    )
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let mut ctx = build_admin_context(&state, &session, "pages.php", "Content Pages").await;
    ctx.insert("pages", &pages);

    match state.tera.render("admin/pages.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_pages_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<PageActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("pages.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/pages.php").into_response();
    }

    let action = form.action.unwrap_or_default();
    let pid = form.id.unwrap_or(0);

    if action == "delete" {
        let title_opt: Option<(String,)> = sqlx::query_as("SELECT title FROM pages WHERE id = ?")
            .bind(pid)
            .fetch_optional(&state.db)
            .await
            .unwrap_or(None);

        if let Some((title,)) = title_opt {
            let _ = sqlx::query("DELETE FROM pages WHERE id = ?").bind(pid).execute(&state.db).await;
            log_activity(&state.db, Some(admin.id), "page.delete", &format!("Deleted page '{}'", title), None).await;
            SessionHelper::add_flash(&session, "success", &format!("Page '{}' deleted successfully.", title)).await;
        }
    } else if action == "toggle_status" {
        let st_opt: Option<(String,)> = sqlx::query_as("SELECT status FROM pages WHERE id = ?")
            .bind(pid)
            .fetch_optional(&state.db)
            .await
            .unwrap_or(None);

        if let Some((current_st,)) = st_opt {
            let next_st = if current_st == "published" { "draft" } else { "published" };
            let _ = sqlx::query("UPDATE pages SET status = ?, updated_at = NOW() WHERE id = ?")
                .bind(next_st)
                .bind(pid)
                .execute(&state.db)
                .await;
            SessionHelper::add_flash(&session, "success", &format!("Page status updated to {}.", next_st)).await;
        }
    }

    Redirect::to("/admin/pages.php").into_response()
}

#[derive(Debug, Deserialize)]
pub struct PageFormQuery {
    pub id: Option<u64>,
}

#[derive(Debug, Deserialize)]
pub struct PageSaveForm {
    pub id: Option<u64>,
    pub title: Option<String>,
    pub slug: Option<String>,
    pub content: Option<String>,
    pub meta_title: Option<String>,
    pub meta_description: Option<String>,
    pub position: Option<i32>,
    pub status: Option<String>,
    pub show_in_footer: Option<String>,
    pub show_in_header: Option<String>,
}

pub async fn admin_page_form_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<PageFormQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("pages.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/pages.php").into_response();
    }

    let pid = query.id.unwrap_or(0);
    let page = if pid > 0 {
        sqlx::query_as::<_, PageItem>(
            "SELECT id, slug, title, content, meta_title, meta_description,
                    show_in_footer, show_in_header, position, status, created_at, updated_at
             FROM pages WHERE id = ? LIMIT 1"
        )
        .bind(pid)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None)
    } else {
        None
    };

    let title_str = if pid > 0 { "Edit Page" } else { "Add New Page" };
    let mut ctx = build_admin_context(&state, &session, "page_form.php", title_str).await;
    let page_data = match page {
        Some(p) => serde_json::to_value(p).unwrap_or_default(),
        None => serde_json::json!({
            "id": 0, "slug": "", "title": "", "content": "", "meta_title": "", "meta_description": "",
            "show_in_footer": 0, "show_in_header": 0, "position": 0, "status": "active"
        }),
    };
    ctx.insert("page", &page_data);
    ctx.insert("id", &pid);

    match state.tera.render("admin/page_form.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_page_form_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<PageSaveForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("pages.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/pages.php").into_response();
    }

    let id = form.id.unwrap_or(0);
    let title = form.title.unwrap_or_default().trim().to_string();
    if title.is_empty() {
        SessionHelper::add_flash(&session, "danger", "Page title is required.").await;
        return Redirect::to("/admin/pages.php").into_response();
    }

    let base_slug = if let Some(s) = form.slug.filter(|s| !s.trim().is_empty()) {
        slugify(&s)
    } else {
        slugify(&title)
    };

    let final_slug = get_unique_slug(&state.db, &base_slug, "pages", id)
        .await
        .unwrap_or(base_slug);

    let content = form.content.unwrap_or_default();
    let meta_title = form.meta_title.unwrap_or_default().trim().to_string();
    let meta_desc = form.meta_description.unwrap_or_default().trim().to_string();
    let position = form.position.unwrap_or(0);
    let status = form.status.unwrap_or_else(|| "published".to_string());
    let show_in_footer = if form.show_in_footer.as_deref() == Some("1") || form.show_in_footer.as_deref() == Some("on") { 1 } else { 0 };
    let show_in_header = if form.show_in_header.as_deref() == Some("1") || form.show_in_header.as_deref() == Some("on") { 1 } else { 0 };

    if id > 0 {
        let _ = sqlx::query(
            "UPDATE pages
             SET slug = ?, title = ?, content = ?, meta_title = ?, meta_description = ?,
                 show_in_footer = ?, show_in_header = ?, position = ?, status = ?, updated_at = NOW()
             WHERE id = ?"
        )
        .bind(&final_slug)
        .bind(&title)
        .bind(&content)
        .bind(if meta_title.is_empty() { None } else { Some(&meta_title) })
        .bind(if meta_desc.is_empty() { None } else { Some(&meta_desc) })
        .bind(show_in_footer)
        .bind(show_in_header)
        .bind(position)
        .bind(&status)
        .bind(id)
        .execute(&state.db)
        .await;

        log_activity(&state.db, Some(admin.id), "page.update", &format!("Updated page '{}'", title), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Page '{}' updated.", title)).await;
    } else {
        let _ = sqlx::query(
            "INSERT INTO pages
                (slug, title, content, meta_title, meta_description, show_in_footer, show_in_header, position, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        )
        .bind(&final_slug)
        .bind(&title)
        .bind(&content)
        .bind(if meta_title.is_empty() { None } else { Some(&meta_title) })
        .bind(if meta_desc.is_empty() { None } else { Some(&meta_desc) })
        .bind(show_in_footer)
        .bind(show_in_header)
        .bind(position)
        .bind(&status)
        .execute(&state.db)
        .await;

        log_activity(&state.db, Some(admin.id), "page.create", &format!("Created page '{}'", title), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Page '{}' created.", title)).await;
    }

    Redirect::to("/admin/pages.php").into_response()
}

// =================================================================
// 6. Users & Roles (users.php & user_form.php)
// =================================================================

pub fn get_permissions_catalog() -> serde_json::Value {
    serde_json::json!({
        "orders": {
            "label": "Orders & Fulfillment",
            "icon": "bi-receipt",
            "permissions": {
                "orders.view": { "label": "View Orders", "description": "Browse order lists, search, filter, and inspect detailed order items and customer info." },
                "orders.manage": { "label": "Manage Orders", "description": "Update order fulfillment status, edit shipping tracking numbers, and add notes." },
                "orders.delete": { "label": "Delete Orders", "description": "Permanently delete orders and order history records." },
                "orders.print": { "label": "Print Invoices", "description": "Generate and print customer invoices, packing slips, and delivery receipts." }
            }
        },
        "catalog": {
            "label": "Products & Catalog",
            "icon": "bi-box-seam",
            "permissions": {
                "products.view": { "label": "View Products", "description": "Browse product list, view SKUs, pricing, stock levels, and variants." },
                "products.create": { "label": "Create Products", "description": "Add new simple and configurable/variable products." },
                "products.edit": { "label": "Edit Products", "description": "Edit product details, pricing, variants, gallery images, attributes, and SEO." },
                "products.delete": { "label": "Delete Products", "description": "Delete products and bulk-remove catalog items." },
                "categories.manage": { "label": "Manage Categories", "description": "Create, edit, organize category trees, and delete categories." }
            }
        },
        "inventory": {
            "label": "Inventory & Warehouse",
            "icon": "bi-boxes",
            "permissions": {
                "inventory.view": { "label": "View Inventory", "description": "Inspect stock availability, low-stock thresholds, and stock movement history." },
                "inventory.manage": { "label": "Manage Inventory", "description": "Perform quick stock adjustments and update warehouse quantities." }
            }
        },
        "customers": {
            "label": "Customers & Reviews",
            "icon": "bi-people",
            "permissions": {
                "customers.view": { "label": "View Customers", "description": "Browse customer directory, inspect profiles, addresses, and order history." },
                "customers.manage": { "label": "Manage Customers", "description": "Edit customer details, reset passwords, unlock accounts, and verify emails." },
                "customers.delete": { "label": "Delete / Ban Customers", "description": "Permanently delete customer accounts or soft-delete/ban users." },
                "reviews.view": { "label": "View Reviews", "description": "Read customer product ratings and reviews." },
                "reviews.manage": { "label": "Moderate Reviews", "description": "Approve, reject, feature, or delete submitted product reviews." }
            }
        },
        "marketing": {
            "label": "Marketing, CMS & Communications",
            "icon": "bi-megaphone",
            "permissions": {
                "analytics.view": { "label": "View Analytics", "description": "Access revenue charts, visitor analytics, sales statistics, and top product metrics." },
                "analytics.export": { "label": "Export Reports", "description": "Export revenue and order data to CSV / Excel." },
                "banners.manage": { "label": "Manage Banners", "description": "Create, edit, schedule, and delete homepage and storefront promo banners." },
                "pages.manage": { "label": "Manage Pages (CMS)", "description": "Create, edit, and publish static CMS store pages (About Us, Terms, FAQ)." },
                "messages.manage": { "label": "Contact Messages", "description": "View customer contact submissions, mark resolved, and reply." },
                "subscribers.manage": { "label": "Newsletter Subscribers", "description": "View and export newsletter subscriber lists." },
                "coupons.manage": { "label": "Coupons & Discounts", "description": "Create and configure promo discount codes, expiration dates, and minimum spend." }
            }
        },
        "settings": {
            "label": "Settings & Infrastructure",
            "icon": "bi-gear",
            "permissions": {
                "settings.general": { "label": "Store Settings", "description": "Configure store details, currency, taxes, and storefront appearance." },
                "settings.storage": { "label": "Storage / AWS S3", "description": "Configure cloud S3 storage, credentials, and CloudFront CDN." },
                "mail.manage": { "label": "Mail System", "description": "Manage SMTP mail settings, email templates, and failed queue items." },
                "monitoring.view": { "label": "System Monitoring", "description": "Inspect server CPU, memory, disk usage, background jobs, and system health." },
                "activity.view": { "label": "Activity Log", "description": "Review the administrative audit log of all staff and system events." }
            }
        },
        "users": {
            "label": "Users & Permissions",
            "icon": "bi-shield-lock",
            "permissions": {
                "users.view": { "label": "View Users & Permissions", "description": "View user accounts and their assigned role and permissions." },
                "users.manage": { "label": "Manage Users & Permissions", "description": "Create new user accounts, edit credentials, roles, and configure permission sets." },
                "users.delete": { "label": "Delete Users", "description": "Delete user accounts." }
            }
        }
    })
}

pub fn get_roles_catalog() -> serde_json::Value {
    serde_json::json!({
        "admin": {
            "label": "Super Administrator",
            "badge_class": "bg-primary-subtle text-primary border border-primary-subtle",
            "description": "Unrestricted access to all features, settings, user management, and server tools.",
            "permissions": ["*"]
        },
        "manager": {
            "label": "Store Manager",
            "badge_class": "bg-success-subtle text-success border border-success-subtle",
            "description": "Full access to sales, catalog, customers, inventory, analytics, and marketing.",
            "permissions": [
                "orders.view", "orders.manage", "orders.print",
                "products.view", "products.create", "products.edit", "products.delete", "categories.manage",
                "inventory.view", "inventory.manage",
                "customers.view", "customers.manage", "reviews.view", "reviews.manage",
                "analytics.view", "analytics.export", "banners.manage", "pages.manage",
                "messages.manage", "subscribers.manage", "coupons.manage",
                "activity.view"
            ]
        },
        "editor": {
            "label": "Catalog & Content Editor",
            "badge_class": "bg-info-subtle text-info border border-info-subtle",
            "description": "Manage products, categories, reviews, banners, and static store pages.",
            "permissions": [
                "products.view", "products.create", "products.edit", "categories.manage",
                "inventory.view", "reviews.view", "reviews.manage",
                "banners.manage", "pages.manage"
            ]
        },
        "support": {
            "label": "Customer Support",
            "badge_class": "bg-warning-subtle text-warning border border-warning-subtle",
            "description": "Handle order fulfillment, inspect customer accounts, view reviews, and reply to messages.",
            "permissions": [
                "orders.view", "orders.manage", "orders.print",
                "customers.view", "customers.manage", "reviews.view",
                "messages.manage", "products.view"
            ]
        },
        "analyst": {
            "label": "Reports & Analyst",
            "badge_class": "bg-secondary-subtle text-secondary border border-secondary-subtle",
            "description": "Access sales analytics, report exports, inventory views, and monitor system health.",
            "permissions": [
                "analytics.view", "analytics.export", "inventory.view", "monitoring.view"
            ]
        },
        "custom": {
            "label": "Custom Role",
            "badge_class": "bg-dark-subtle text-dark border border-dark-subtle",
            "description": "Manually selected custom permission set tailored to this specific user.",
            "permissions": []
        }
    })
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct StaffUserItem {
    pub id: u64,
    pub name: String,
    pub email: String,
    pub role: String,
    pub status: String,
    pub permissions: Option<String>,
    pub avatar: Option<String>,
    pub phone: Option<String>,
    pub created_at: chrono::NaiveDateTime,
}

#[derive(Debug, Deserialize, Default)]
pub struct UsersFilterQuery {
    pub q: Option<String>,
    pub role: Option<String>,
    pub status: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct UserActionForm {
    pub action: Option<String>,
    pub id: Option<u64>,
}

pub async fn admin_users_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<UsersFilterQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("users.view") && !admin.has_permission("users.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let stats_total: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM users").fetch_one(&state.db).await.unwrap_or(0);
    let stats_admins: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM users WHERE role = 'admin'").fetch_one(&state.db).await.unwrap_or(0);
    let stats_staff: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM users WHERE role != 'admin'").fetch_one(&state.db).await.unwrap_or(0);
    let stats_active: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM users WHERE status = 'active'").fetch_one(&state.db).await.unwrap_or(0);

    let q_param = query.q.clone().unwrap_or_default().trim().to_string();
    let role_param = query.role.clone().unwrap_or_default().trim().to_string();
    let status_param = query.status.clone().unwrap_or_default().trim().to_string();

    let mut sql = "SELECT * FROM users WHERE 1=1".to_string();
    if !q_param.is_empty() {
        let safe_q = q_param.replace('\'', "''");
        sql.push_str(&format!(" AND (name LIKE '%{0}%' OR email LIKE '%{0}%' OR phone LIKE '%{0}%')", safe_q));
    }
    if !role_param.is_empty() {
        let safe_role = role_param.replace('\'', "''");
        sql.push_str(&format!(" AND role = '{}'", safe_role));
    }
    if status_param == "active" || status_param == "inactive" {
        sql.push_str(&format!(" AND status = '{}'", status_param));
    }
    sql.push_str(" ORDER BY (role = 'admin') DESC, id ASC");

    let users = sqlx::query_as::<_, domain::models::user::User>(&sql)
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();

    let mut ctx = build_admin_context(&state, &session, "users.php", "Staff & Admin Users").await;
    ctx.insert("users", &users);
    ctx.insert("current_user_id", &admin.id);
    ctx.insert("stats_total", &stats_total);
    ctx.insert("stats_admins", &stats_admins);
    ctx.insert("stats_staff", &stats_staff);
    ctx.insert("stats_active", &stats_active);
    ctx.insert("q", &q_param);
    ctx.insert("f_role", &role_param);
    ctx.insert("f_status", &status_param);
    ctx.insert("roles_catalog", &get_roles_catalog());
    ctx.insert("permissions_catalog", &get_permissions_catalog());

    match state.tera.render("admin/users.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_users_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<UserActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("users.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. users.manage required.").await;
        return Redirect::to("/admin/users.php").into_response();
    }

    let target_id = form.id.unwrap_or(0);
    if target_id == admin.id {
        SessionHelper::add_flash(&session, "danger", "You cannot change your own account status or delete yourself.").await;
        return Redirect::to("/admin/users.php").into_response();
    }

    let user_row: Option<(String, String, String)> = sqlx::query_as(
        "SELECT name, role, status FROM users WHERE id = ?"
    )
    .bind(target_id)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let (name, role, status) = match user_row {
        Some(u) => u,
        None => {
            SessionHelper::add_flash(&session, "warning", "User not found.").await;
            return Redirect::to("/admin/users.php").into_response();
        }
    };

    let action = form.action.unwrap_or_default();

    if action == "toggle_status" {
        let new_status = if status == "active" { "inactive" } else { "active" };

        if role == "admin" && new_status == "inactive" {
            let active_admins: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")
                .fetch_one(&state.db)
                .await
                .unwrap_or(0);
            if active_admins <= 1 {
                SessionHelper::add_flash(&session, "danger", "Cannot deactivate the last remaining active Super Administrator.").await;
                return Redirect::to("/admin/users.php").into_response();
            }
        }

        let _ = sqlx::query("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?")
            .bind(new_status)
            .bind(target_id)
            .execute(&state.db)
            .await;

        log_activity(&state.db, Some(admin.id), "user.toggle_status", &format!("Changed status of user #{} ({}) to {}", target_id, name, new_status), None).await;
        SessionHelper::add_flash(&session, "success", &format!("User {} is now {}.", name, new_status)).await;
    } else if action == "delete" {
        if role == "admin" {
            let active_admins: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM users WHERE role = 'admin'")
                .fetch_one(&state.db)
                .await
                .unwrap_or(0);
            if active_admins <= 1 {
                SessionHelper::add_flash(&session, "danger", "Cannot delete the last remaining Super Administrator.").await;
                return Redirect::to("/admin/users.php").into_response();
            }
        }

        let _ = sqlx::query("DELETE FROM users WHERE id = ?").bind(target_id).execute(&state.db).await;
        log_activity(&state.db, Some(admin.id), "user.delete", &format!("Deleted user #{} ({})", target_id, name), None).await;
        SessionHelper::add_flash(&session, "success", &format!("User {} deleted.", name)).await;
    }

    Redirect::to("/admin/users.php").into_response()
}

#[derive(Debug, Deserialize)]
pub struct UserFormQuery {
    pub id: Option<u64>,
}

pub async fn admin_user_form_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<UserFormQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("users.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/users.php").into_response();
    }

    let uid = query.id.unwrap_or(0);
    let user = if uid > 0 {
        sqlx::query_as::<_, StaffUserItem>(
            "SELECT id, name, email, role, status, permissions, avatar, phone, created_at
             FROM users WHERE id = ? LIMIT 1"
        )
        .bind(uid)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None)
    } else {
        None
    };

    let title_str = if uid > 0 { "Edit User" } else { "Create New User" };
    let mut ctx = build_admin_context(&state, &session, "user_form.php", title_str).await;
    let user_perms: Vec<String> = if let Some(ref u) = user {
        if u.role == "admin" {
            vec!["*".to_string()]
        } else if let Some(ref p_str) = u.permissions {
            serde_json::from_str::<Vec<String>>(p_str).unwrap_or_default()
        } else {
            Vec::new()
        }
    } else {
        vec![
            "products.view".to_string(), "products.create".to_string(), "products.edit".to_string(),
            "categories.manage".to_string(), "inventory.view".to_string(), "reviews.view".to_string(),
            "reviews.manage".to_string(), "banners.manage".to_string(), "pages.manage".to_string()
        ]
    };

    let user_data = match user {
        Some(u) => serde_json::to_value(u).unwrap_or_default(),
        None => serde_json::json!({
            "id": 0, "name": "", "email": "", "role": "editor", "status": "active",
            "permissions": "[]", "avatar": null, "phone": null
        }),
    };
    ctx.insert("user", &user_data);
    ctx.insert("id", &uid);
    ctx.insert("user_permissions", &user_perms);
    ctx.insert("roles_catalog", &get_roles_catalog());
    ctx.insert("permissions_catalog", &get_permissions_catalog());

    match state.tera.render("admin/user_form.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_user_form_post_handler(
    State(state): State<AppState>,
    session: Session,
    mut multipart: Multipart,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("users.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. users.manage required.").await;
        return Redirect::to("/admin/users.php").into_response();
    }

    let mut id = 0u64;
    let mut name = String::new();
    let mut email = String::new();
    let mut password = String::new();
    let mut role = "editor".to_string();
    let mut status = "active".to_string();
    let mut phone = String::new();
    let mut permissions_vec: Vec<String> = Vec::new();
    let mut uploaded_avatar: Option<(String, Vec<u8>)> = None;

    while let Ok(Some(field)) = multipart.next_field().await {
        let field_name = field.name().unwrap_or("").to_string();
        if field_name == "avatar" {
            let fname = field.file_name().unwrap_or("avatar.jpg").to_string();
            if let Ok(bytes) = field.bytes().await {
                if !bytes.is_empty() {
                    uploaded_avatar = Some((fname, bytes.to_vec()));
                }
            }
        } else if let Ok(val) = field.text().await {
            match field_name.as_str() {
                "id" => id = val.parse().unwrap_or(0),
                "name" => name = val.trim().to_string(),
                "email" => email = val.trim().to_lowercase(),
                "password" => password = val.trim().to_string(),
                "role" => role = val.trim().to_string(),
                "status" => status = val.trim().to_string(),
                "phone" => phone = val.trim().to_string(),
                "permissions[]" | "permissions" => {
                    if !val.trim().is_empty() {
                        permissions_vec.push(val.trim().to_string());
                    }
                }
                _ => {}
            }
        }
    }

    if name.is_empty() || email.is_empty() {
        SessionHelper::add_flash(&session, "danger", "Name and email are required.").await;
        return Redirect::to("/admin/users.php").into_response();
    }

    let perms_json = if role == "admin" {
        serde_json::to_string(&vec!["*"]).unwrap_or_else(|_| "[\"*\"]".to_string())
    } else {
        serde_json::to_string(&permissions_vec).unwrap_or_else(|_| "[]".to_string())
    };

    let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
    let storage = StorageManager::new(uploads_dir);
    let mut avatar_key: Option<String> = None;
    let mut avatar_fid: Option<u64> = None;

    if let Some((fname, bytes)) = uploaded_avatar {
        if let Ok(info) = storage
            .store_file(
                &state.db,
                "avatar",
                &fname,
                &bytes,
                StoreOptions {
                    owner_type: Some("user".to_string()),
                    owner_id: if id > 0 { Some(id) } else { None },
                    visibility: Some("public".to_string()),
                    is_test: false,
                },
            )
            .await
        {
            avatar_key = Some(info.storage_key);
            avatar_fid = Some(info.id);
        }
    }

    if id > 0 {
        // Update user
        let mut upd_sql = "UPDATE users SET name = ?, email = ?, role = ?, status = ?, permissions = ?, phone = ?, updated_at = NOW()".to_string();
        if !password.is_empty() {
            if let Ok(hashed) = admin_password_hash(&password) {
                upd_sql.push_str(&format!(", password = '{}'", hashed));
            }
        }
        if let (Some(k), Some(fid)) = (&avatar_key, avatar_fid) {
            upd_sql.push_str(&format!(", avatar = '{}', avatar_file_id = {}", k, fid));
        }
        upd_sql.push_str(" WHERE id = ?");

        let _ = sqlx::query(&upd_sql)
            .bind(&name)
            .bind(&email)
            .bind(&role)
            .bind(&status)
            .bind(&perms_json)
            .bind(if phone.is_empty() { None } else { Some(&phone) })
            .bind(id)
            .execute(&state.db)
            .await;

        log_activity(&state.db, Some(admin.id), "user.update", &format!("Updated staff user #{}: {}", id, name), None).await;
        SessionHelper::add_flash(&session, "success", &format!("User {} updated.", name)).await;
    } else {
        let pw_hash = if !password.is_empty() {
            admin_password_hash(&password).unwrap_or_default()
        } else {
            admin_password_hash("password123").unwrap_or_default()
        };

        let _ = sqlx::query(
            "INSERT INTO users (name, email, password, role, status, permissions, phone, avatar, avatar_file_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        )
        .bind(&name)
        .bind(&email)
        .bind(&pw_hash)
        .bind(&role)
        .bind(&status)
        .bind(&perms_json)
        .bind(if phone.is_empty() { None } else { Some(&phone) })
        .bind(&avatar_key)
        .bind(avatar_fid)
        .execute(&state.db)
        .await;

        log_activity(&state.db, Some(admin.id), "user.create", &format!("Created staff user: {} ({})", name, email), None).await;
        SessionHelper::add_flash(&session, "success", &format!("User {} created.", name)).await;
    }

    Redirect::to("/admin/users.php").into_response()
}
