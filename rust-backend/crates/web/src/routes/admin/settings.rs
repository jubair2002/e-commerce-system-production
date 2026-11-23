use axum::{
    extract::{Query, State},
    http::StatusCode,
    response::{Html, IntoResponse, Redirect, Response},
    Form,
};
use chrono::Utc;
use common::crypto::app_encrypt;
use db::queries::activity::log_activity;
use rust_decimal::Decimal;
use serde::Deserialize;
use std::collections::HashMap;
use tower_sessions::Session;

use crate::state::{build_admin_context, AppState, SessionHelper};

// =================================================================
// 1. Settings (settings.php)
// =================================================================

#[derive(Debug, Deserialize)]
pub struct SettingsQuery {
    pub tab: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct SettingsForm {
    pub action: Option<String>,
    pub tab: Option<String>,
    // Coupon fields
    pub id: Option<u64>,
    pub code: Option<String>,
    pub r#type: Option<String>,
    pub value: Option<Decimal>,
    pub min_subtotal: Option<Decimal>,
    pub max_discount: Option<Decimal>,
    pub usage_limit: Option<i32>,
    pub starts_at: Option<String>,
    pub expires_at: Option<String>,
    pub status: Option<String>,
    // Dynamic settings map
    #[serde(flatten)]
    pub extra: HashMap<String, String>,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct CouponItem {
    pub id: u64,
    pub code: String,
    pub r#type: String,
    pub value: Decimal,
    pub min_subtotal: Decimal,
    pub max_discount: Option<Decimal>,
    pub usage_limit: Option<i32>,
    pub used_count: i32,
    pub starts_at: Option<chrono::NaiveDateTime>,
    pub expires_at: Option<chrono::NaiveDateTime>,
    pub status: String,
    pub created_at: chrono::NaiveDateTime,
}

pub async fn admin_settings_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<SettingsQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("settings.general") && !admin.has_permission("settings.storage") && !admin.has_permission("coupons.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let raw_tab = query.tab.unwrap_or_else(|| "general".to_string());
    let allowed_tabs = ["general", "storefront", "inventory", "checkout", "shipping", "coupons", "seo", "customers", "storage"];
    let active_tab = if allowed_tabs.contains(&raw_tab.as_str()) {
        raw_tab
    } else {
        "general".to_string()
    };

    // Load all settings
    let rows: Vec<(String, String)> = sqlx::query_as("SELECT key_name, value FROM settings")
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();

    let mut settings_map: HashMap<String, String> = HashMap::new();
    for (k, v) in rows {
        settings_map.insert(k, v);
    }

    // Mask storage secret key if present
    if let Some(sec) = settings_map.get_mut("storage_s3_secret_key") {
        if !sec.is_empty() {
            *sec = "••••••••••••••••".to_string();
        }
    }

    let coupons = if active_tab == "coupons" {
        sqlx::query_as::<_, CouponItem>(
            "SELECT id, code, type, value, min_subtotal, max_discount, usage_limit,
                    used_count, starts_at, expires_at, status, created_at
             FROM coupons ORDER BY id DESC"
        )
        .fetch_all(&state.db)
        .await
        .unwrap_or_default()
    } else {
        Vec::new()
    };

    let mut ctx = build_admin_context(&state, &session, "settings.php", "Settings & CMS").await;
    ctx.insert("active_tab", &active_tab);
    ctx.insert("settings", &settings_map);
    ctx.insert("coupons", &coupons);

    match state.tera.render("admin/settings.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_settings_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<SettingsForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("settings.general") && !admin.has_permission("settings.storage") && !admin.has_permission("coupons.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. settings required.").await;
        return Redirect::to("/admin/settings.php").into_response();
    }

    let tab = form.tab.unwrap_or_else(|| "general".to_string());
    let action = form.action.unwrap_or_default();

    // 1. Coupons Actions
    if action == "add_coupon" {
        let code = form.code.unwrap_or_default().trim().to_uppercase();
        let ctype = form.r#type.unwrap_or_else(|| "percent".to_string());
        let val = form.value.unwrap_or(Decimal::ZERO);
        let min_sub = form.min_subtotal.unwrap_or(Decimal::ZERO);
        let max_disc = form.max_discount;
        let limit = form.usage_limit;
        let starts_at = form.starts_at.filter(|s| !s.trim().is_empty());
        let expires_at = form.expires_at.filter(|s| !s.trim().is_empty());
        let status = form.status.unwrap_or_else(|| "active".to_string());

        if code.is_empty() {
            SessionHelper::add_flash(&session, "danger", "Coupon code is required.").await;
        } else {
            let _ = sqlx::query(
                "INSERT INTO coupons
                    (code, type, value, min_subtotal, max_discount, usage_limit, starts_at, expires_at, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            )
            .bind(&code)
            .bind(&ctype)
            .bind(val)
            .bind(min_sub)
            .bind(max_disc)
            .bind(limit)
            .bind(starts_at)
            .bind(expires_at)
            .bind(&status)
            .execute(&state.db)
            .await;

            log_activity(&state.db, Some(admin.id), "coupon.create", &format!("Created coupon {}", code), None).await;
            SessionHelper::add_flash(&session, "success", &format!("Coupon {} created.", code)).await;
        }
        return Redirect::to("/admin/settings.php?tab=coupons").into_response();
    }

    if action == "delete_coupon" {
        let cid = form.id.unwrap_or(0);
        let _ = sqlx::query("DELETE FROM coupons WHERE id = ?").bind(cid).execute(&state.db).await;
        log_activity(&state.db, Some(admin.id), "coupon.delete", &format!("Deleted coupon #{}", cid), None).await;
        SessionHelper::add_flash(&session, "success", "Coupon deleted.").await;
        return Redirect::to("/admin/settings.php?tab=coupons").into_response();
    }

    if action == "toggle_coupon" {
        let cid = form.id.unwrap_or(0);
        let cur_row: Option<(String,)> = sqlx::query_as("SELECT status FROM coupons WHERE id = ?")
            .bind(cid)
            .fetch_optional(&state.db)
            .await
            .unwrap_or(None);

        if let Some((st,)) = cur_row {
            let next_st = if st == "active" { "inactive" } else { "active" };
            let _ = sqlx::query("UPDATE coupons SET status = ? WHERE id = ?")
                .bind(next_st)
                .bind(cid)
                .execute(&state.db)
                .await;
            SessionHelper::add_flash(&session, "success", &format!("Coupon status updated to {}.", next_st)).await;
        }
        return Redirect::to("/admin/settings.php?tab=coupons").into_response();
    }

    // 2. Tab Settings Save
    let mut updated_keys = Vec::new();

    for (k, mut v) in form.extra {
        if k.starts_with('_') || k == "action" || k == "tab" {
            continue;
        }

        // Special handling for secrets: if masked or empty and already set, don't overwrite with masked string
        if k == "storage_s3_secret_key" {
            if v == "••••••••••••••••" || v.trim().is_empty() {
                continue;
            }
            v = app_encrypt(&v, &state.config.app_key);
        }

        let _ = sqlx::query(
            "INSERT INTO settings (key_name, value, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()"
        )
        .bind(&k)
        .bind(&v)
        .execute(&state.db)
        .await;

        updated_keys.push(k);
    }

    // Invalidate cached settings
    state.settings.invalidate();

    log_activity(
        &state.db,
        Some(admin.id),
        "settings.update",
        &format!("Updated {} settings in tab {}", updated_keys.len(), tab),
        None,
    )
    .await;

    SessionHelper::add_flash(&session, "success", "Settings saved successfully.").await;
    Redirect::to(&format!("/admin/settings.php?tab={}", tab)).into_response()
}

// =================================================================
// 2. Mail Settings (mail_settings.php)
// =================================================================

#[derive(Debug, Deserialize)]
pub struct MailSettingsForm {
    pub action: Option<String>,
    pub smtp_host: Option<String>,
    pub smtp_port: Option<String>,
    pub smtp_user: Option<String>,
    pub smtp_pass: Option<String>,
    pub smtp_encryption: Option<String>,
    pub mail_from_address: Option<String>,
    pub mail_from_name: Option<String>,
    pub mail_driver: Option<String>,
    // Test email
    pub recipient: Option<String>,
}

pub async fn admin_mail_settings_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("mail.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let rows: Vec<(String, String)> = sqlx::query_as("SELECT key_name, value FROM settings WHERE key_name LIKE 'mail_%' OR key_name LIKE 'smtp_%'")
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();

    let mut mail_cfg: HashMap<String, String> = HashMap::new();
    for (k, v) in rows {
        mail_cfg.insert(k, v);
    }

    if let Some(p) = mail_cfg.get_mut("smtp_pass") {
        if !p.is_empty() {
            *p = "••••••••••••••••".to_string();
        }
    }

    let queue_pending: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let queue_sent: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM email_queue WHERE status = 'sent'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let queue_failed: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM email_queue WHERE status = 'failed'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    let last_sent: Option<chrono::NaiveDateTime> = sqlx::query_scalar("SELECT sent_at FROM email_queue WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1")
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

    let mut ctx = build_admin_context(&state, &session, "mail_settings.php", "Mail Configuration").await;
    ctx.insert("mail_cfg", &mail_cfg);
    ctx.insert("queue_pending", &queue_pending);
    ctx.insert("queue_sent", &queue_sent);
    ctx.insert("queue_failed", &queue_failed);
    ctx.insert("last_sent", &last_sent);

    match state.tera.render("admin/mail_settings.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_mail_settings_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<MailSettingsForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("mail.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. mail.manage required.").await;
        return Redirect::to("/admin/mail_settings.php").into_response();
    }

    let action = form.action.unwrap_or_default();

    if action == "send_test_email" {
        let recipient = form.recipient.unwrap_or_default().trim().to_string();
        if recipient.is_empty() || !recipient.contains('@') {
            SessionHelper::add_flash(&session, "danger", "Please enter a valid recipient email address.").await;
            return Redirect::to("/admin/mail_settings.php").into_response();
        }

        let site_name = state.settings.get("site_name").unwrap_or_else(|| "Jinish Potro".to_string());
        let html_body = format!(
            "<h3>Test Email Successful!</h3><p>Your mail server settings are configured properly. Sent at <strong>{}</strong>.</p>",
            Utc::now().format("%Y-%m-%d %H:%M:%S")
        );

        let _ = sqlx::query(
            "INSERT INTO email_queue (template_slug, to_email, to_name, subject, body_html, status, attempts, scheduled_at, created_at)
             VALUES ('test_email', ?, 'Admin', ?, ?, 'pending', 0, NOW(), NOW())"
        )
        .bind(&recipient)
        .bind(format!("Test email from {}", site_name))
        .bind(&html_body)
        .execute(&state.db)
        .await;

        log_activity(&state.db, Some(admin.id), "mail.test_sent", &format!("Queued test email to {}", recipient), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Test email queued for delivery to {}.", recipient)).await;
        return Redirect::to("/admin/mail_settings.php").into_response();
    }

    // Save mail settings
    let pairs = vec![
        ("smtp_host", form.smtp_host),
        ("smtp_port", form.smtp_port),
        ("smtp_user", form.smtp_user),
        ("smtp_encryption", form.smtp_encryption),
        ("mail_from_address", form.mail_from_address),
        ("mail_from_name", form.mail_from_name),
        ("mail_driver", form.mail_driver),
    ];

    for (k, val_opt) in pairs {
        if let Some(v) = val_opt {
            let _ = sqlx::query(
                "INSERT INTO settings (key_name, value, updated_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()"
            )
            .bind(k)
            .bind(v.trim())
            .execute(&state.db)
            .await;
        }
    }

    // Handle password
    if let Some(pw) = form.smtp_pass {
        if pw != "••••••••••••••••" && !pw.trim().is_empty() {
            let enc_pw = app_encrypt(pw.trim(), &state.config.app_key);
            let _ = sqlx::query(
                "INSERT INTO settings (key_name, value, updated_at)
                 VALUES ('smtp_pass', ?, NOW())
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()"
            )
            .bind(enc_pw)
            .execute(&state.db)
            .await;
        }
    }

    state.settings.invalidate();
    log_activity(&state.db, Some(admin.id), "mail.settings_update", "Updated SMTP mail configuration", None).await;
    SessionHelper::add_flash(&session, "success", "Mail configuration updated successfully.").await;

    Redirect::to("/admin/mail_settings.php").into_response()
}

// =================================================================
// 3. Mail Templates (mail_templates.php)
// =================================================================

#[derive(Debug, Clone, sqlx::FromRow, serde::Serialize)]
pub struct MailTemplateItem {
    pub id: u64,
    pub slug: String,
    pub name: String,
    pub audience: String,
    pub subject: String,
    pub body_html: String,
    pub body_text: Option<String>,
    pub variables: Option<String>,
    pub enabled: i8,
    pub updated_at: chrono::NaiveDateTime,
}

#[derive(Debug, Deserialize)]
pub struct MailTemplateQuery {
    pub slug: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct MailTemplateForm {
    pub slug: String,
    pub subject: String,
    pub body_html: String,
    pub enabled: Option<String>,
}

pub async fn admin_mail_templates_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<MailTemplateQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("mail.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let templates = sqlx::query_as::<_, MailTemplateItem>(
        "SELECT id, slug, name, audience, subject, body_html, body_text, variables, enabled, updated_at
         FROM email_templates ORDER BY audience ASC, name ASC"
    )
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let active_slug = query.slug.unwrap_or_else(|| {
        templates.first().map(|t| t.slug.clone()).unwrap_or_default()
    });

    let current_template = templates.iter().find(|t| t.slug == active_slug).cloned();

    let mut ctx = build_admin_context(&state, &session, "mail_templates.php", "Email Templates").await;
    ctx.insert("templates", &templates);
    ctx.insert("active_slug", &active_slug);
    ctx.insert("current_template", &current_template);

    match state.tera.render("admin/mail_templates.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_mail_templates_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<MailTemplateForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("mail.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. mail.manage required.").await;
        return Redirect::to("/admin/mail_templates.php").into_response();
    }

    let enabled = if form.enabled.as_deref() == Some("1") || form.enabled.as_deref() == Some("on") { 1 } else { 0 };

    let _ = sqlx::query(
        "UPDATE email_templates
         SET subject = ?, body_html = ?, enabled = ?, updated_at = NOW()
         WHERE slug = ?"
    )
    .bind(&form.subject)
    .bind(&form.body_html)
    .bind(enabled)
    .bind(&form.slug)
    .execute(&state.db)
    .await;

    log_activity(&state.db, Some(admin.id), "mail.template_update", &format!("Updated email template {}", form.slug), None).await;
    SessionHelper::add_flash(&session, "success", &format!("Template '{}' updated.", form.slug)).await;

    Redirect::to(&format!("/admin/mail_templates.php?slug={}", form.slug)).into_response()
}

// =================================================================
// 4. Mail Queue (mail_queue.php)
// =================================================================

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct MailQueueItem {
    pub id: u64,
    pub template_slug: Option<String>,
    pub to_email: String,
    pub to_name: Option<String>,
    pub subject: String,
    pub status: String,
    pub attempts: u8,
    pub last_error: Option<String>,
    pub scheduled_at: chrono::NaiveDateTime,
    pub sent_at: Option<chrono::NaiveDateTime>,
    pub created_at: chrono::NaiveDateTime,
}

#[derive(Debug, Deserialize)]
pub struct MailQueueQuery {
    pub status: Option<String>,
    pub page: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct MailQueueActionForm {
    pub action: Option<String>,
    pub id: Option<u64>,
}

pub async fn admin_mail_queue_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<MailQueueQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("mail.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let status_filter = query.status.unwrap_or_else(|| "all".to_string());
    let page = query.page.unwrap_or(1).max(1);
    let per_page = 25i64;
    let offset = (page - 1) * per_page;

    let mut where_clause = String::new();
    if status_filter != "all" && ["pending", "sending", "sent", "failed"].contains(&status_filter.as_str()) {
        where_clause = format!("WHERE status = '{}'", status_filter);
    }

    let count_sql = format!("SELECT COUNT(*) FROM email_queue {}", where_clause);
    let total_items: i64 = sqlx::query_scalar(&count_sql).fetch_one(&state.db).await.unwrap_or(0);
    let total_pages = ((total_items as f64) / (per_page as f64)).ceil() as i64;

    let list_sql = format!(
        "SELECT id, template_slug, to_email, to_name, subject, status, attempts,
                last_error, scheduled_at, sent_at, created_at
         FROM email_queue {} ORDER BY id DESC LIMIT ? OFFSET ?",
        where_clause
    );

    let queue_items = sqlx::query_as::<_, MailQueueItem>(&list_sql)
        .bind(per_page)
        .bind(offset)
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();

    let count_pending: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_sent: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM email_queue WHERE status = 'sent'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_failed: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM email_queue WHERE status = 'failed'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    let mut ctx = build_admin_context(&state, &session, "mail_queue.php", "Mail Queue & Logs").await;
    ctx.insert("queue_items", &queue_items);
    ctx.insert("status_filter", &status_filter);
    ctx.insert("page", &page);
    ctx.insert("total_pages", &total_pages);
    ctx.insert("total_items", &total_items);
    ctx.insert("count_pending", &count_pending);
    ctx.insert("count_sent", &count_sent);
    ctx.insert("count_failed", &count_failed);

    match state.tera.render("admin/mail_queue.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_mail_queue_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<MailQueueActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("mail.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. mail.manage required.").await;
        return Redirect::to("/admin/mail_queue.php").into_response();
    }

    let action = form.action.unwrap_or_default();
    let qid = form.id.unwrap_or(0);

    if action == "retry" {
        let _ = sqlx::query(
            "UPDATE email_queue SET status = 'pending', scheduled_at = NOW(), last_error = NULL WHERE id = ?"
        )
        .bind(qid)
        .execute(&state.db)
        .await;

        log_activity(&state.db, Some(admin.id), "mail.retry", &format!("Manually rescheduled queue item #{}", qid), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Message #{} reset to pending.", qid)).await;
    } else if action == "delete" {
        let _ = sqlx::query("DELETE FROM email_queue WHERE id = ?").bind(qid).execute(&state.db).await;
        log_activity(&state.db, Some(admin.id), "mail.delete", &format!("Deleted queue item #{}", qid), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Queue item #{} deleted.", qid)).await;
    } else if action == "retry_all_failed" {
        let res = sqlx::query(
            "UPDATE email_queue SET status = 'pending', scheduled_at = NOW(), attempts = 0, last_error = NULL WHERE status = 'failed'"
        )
        .execute(&state.db)
        .await;

        let cnt = res.map(|r| r.rows_affected()).unwrap_or(0);
        log_activity(&state.db, Some(admin.id), "mail.retry_all", &format!("Reset {} failed emails to pending", cnt), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Reset {} failed message(s) to pending for re-delivery.", cnt)).await;
    } else if action == "purge_sent" {
        let res = sqlx::query("DELETE FROM email_queue WHERE status = 'sent'").execute(&state.db).await;
        let cnt = res.map(|r| r.rows_affected()).unwrap_or(0);
        log_activity(&state.db, Some(admin.id), "mail.purge", &format!("Purged {} sent emails from queue", cnt), None).await;
        SessionHelper::add_flash(&session, "info", &format!("Purged {} sent email logs.", cnt)).await;
    }

    Redirect::to("/admin/mail_queue.php").into_response()
}

// =================================================================
// 5. Monitoring & Analytics (monitoring.php, analytics.php, analytics_product.php)
// =================================================================

pub async fn admin_monitoring_handler(
    State(state): State<AppState>,
    session: Session,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("monitoring.view") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let ctx = build_admin_context(&state, &session, "monitoring.php", "System Health & Monitoring").await;

    match state.tera.render("admin/monitoring.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_analytics_handler(
    State(state): State<AppState>,
    session: Session,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("analytics.view") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let ctx = build_admin_context(&state, &session, "analytics.php", "Store Analytics").await;

    match state.tera.render("admin/analytics.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}

pub async fn admin_analytics_product_handler(
    State(state): State<AppState>,
    session: Session,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("analytics.view") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let ctx = build_admin_context(&state, &session, "analytics_product.php", "Product Performance Analytics").await;

    match state.tera.render("admin/analytics_product.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {}", e)).into_response(),
    }
}
