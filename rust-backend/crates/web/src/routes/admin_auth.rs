use std::collections::HashMap;
use axum::{
    extract::{Multipart, Query, State},
    http::StatusCode,
    response::{Html, IntoResponse, Redirect, Response},
    Form,
};
use common::crypto::{admin_password_hash, admin_password_verify, dummy_admin_password_verify};
use db::queries::activity::log_activity;
use db::queries::storage::{StorageManager, StoreOptions};
use domain::models::user::User;
use serde::Deserialize;
use std::path::PathBuf;
use tower_sessions::Session;

use crate::state::{build_admin_context, AdminSession, AppState, SessionHelper};

#[derive(Debug, Deserialize)]
pub struct AdminLoginForm {
    pub _csrf: Option<String>,
    pub email: Option<String>,
    pub password: Option<String>,
    pub next: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct AdminLoginQuery {
    pub next: Option<String>,
}

/// GET /admin, GET /admin/index.php
pub async fn admin_index_handler(
    session: Session,
) -> Response {
    if SessionHelper::get_user(&session).await.is_some() {
        Redirect::to("/admin/dashboard.php").into_response()
    } else {
        Redirect::to("/admin/login.php").into_response()
    }
}

/// GET /admin/login, GET /admin/login.php
pub async fn admin_login_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<AdminLoginQuery>,
) -> Response {
    if SessionHelper::get_user(&session).await.is_some() {
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let mut ctx = tera::Context::new();
    let site_name = state.settings.get_or("site_name", "Jinish Potro");
    let site_name = if site_name.is_empty() || site_name == "PHP Admin Panel" || site_name == "Store" {
        "Jinish Potro"
    } else {
        &site_name
    };
    ctx.insert("site_name", site_name);

    let csrf = SessionHelper::get_csrf(&session).await;
    ctx.insert("csrf_token", &csrf);

    let flashes = SessionHelper::take_flashes(&session).await;
    ctx.insert("flashes", &flashes);

    let old = SessionHelper::take_old(&session).await;
    ctx.insert("old", &old);

    let next_val = query.next.unwrap_or_default();
    ctx.insert("next", &next_val);

    let empty_errors: Vec<String> = Vec::new();
    ctx.insert("errors", &empty_errors);

    match state.tera.render("admin/login.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(err) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            format!("Template error: {}", err),
        )
            .into_response(),
    }
}

/// POST /admin/login, POST /admin/login.php
pub async fn admin_login_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<AdminLoginForm>,
) -> Response {
    let email = form.email.as_deref().unwrap_or("").trim().to_lowercase();
    let password = form.password.as_deref().unwrap_or("");

    let mut old = HashMap::new();
    old.insert("email".to_string(), email.clone());
    SessionHelper::set_old(&session, &old).await;

    if email.is_empty() || password.is_empty() {
        SessionHelper::add_flash(&session, "danger", "Email and password are required.").await;
        return Redirect::to("/admin/login.php").into_response();
    }

    let user: Option<User> = sqlx::query_as("SELECT * FROM users WHERE email = ? LIMIT 1")
        .bind(&email)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

    let u = match user {
        Some(u) => u,
        None => {
            dummy_admin_password_verify(password);
            SessionHelper::add_flash(&session, "danger", "Invalid credentials, or your account is disabled.").await;
            return Redirect::to("/admin/login.php").into_response();
        }
    };

    if u.status != "active" {
        SessionHelper::add_flash(&session, "danger", "Invalid credentials, or your account is disabled.").await;
        return Redirect::to("/admin/login.php").into_response();
    }

    let valid = admin_password_verify(password, &u.password);
    if !valid {
        SessionHelper::add_flash(&session, "danger", "Invalid credentials, or your account is disabled.").await;
        return Redirect::to("/admin/login.php").into_response();
    }

    // Regenerate session id to protect against fixation
    SessionHelper::cycle_session_id(&session).await;

    let perms = u.permission_list();
    let admin_session = AdminSession {
        id: u.id,
        name: u.name.clone(),
        email: u.email,
        role: u.role,
        permissions: perms,
        avatar: u.avatar,
    };

    SessionHelper::set_user(&session, &admin_session).await;

    // Log activity
    log_activity(&state.db, Some(admin_session.id), "login", "User logged in", None).await;

    // Update updated_at in users table
    let _ = sqlx::query("UPDATE users SET updated_at = NOW() WHERE id = ?")
        .bind(admin_session.id)
        .execute(&state.db)
        .await;

    SessionHelper::add_flash(&session, "success", &format!("Welcome back, {}!", u.name)).await;

    let next = form
        .next
        .filter(|n| n.starts_with("/admin") && !n.starts_with("//"))
        .unwrap_or_else(|| "/admin/dashboard.php".to_string());

    Redirect::to(&next).into_response()
}

/// GET /admin/logout, POST /admin/logout, GET /admin/logout.php
pub async fn admin_logout_handler(
    State(state): State<AppState>,
    session: Session,
) -> Response {
    if let Some(admin) = SessionHelper::get_user(&session).await {
        log_activity(&state.db, Some(admin.id), "logout", "User logged out", None).await;
    }
    SessionHelper::remove_user(&session).await;
    SessionHelper::cycle_session_id(&session).await;
    Redirect::to("/admin/login.php").into_response()
}

/// GET /admin/dashboard, GET /admin/dashboard.php
pub async fn admin_dashboard_handler(
    State(state): State<AppState>,
    session: Session,
) -> Response {
    let _admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    let stats = match db::queries::admin::get_dashboard_stats(&state.db).await {
        Ok(s) => s,
        Err(e) => {
            return (
                StatusCode::INTERNAL_SERVER_ERROR,
                format!("Database error loading dashboard: {}", e),
            )
                .into_response()
        }
    };

    let mut ctx = build_admin_context(&state, &session, "dashboard.php", "Dashboard").await;
    ctx.insert("stats", &stats);
    ctx.insert("totalRevenue", &stats.total_revenue);
    ctx.insert("todayRevenue", &stats.today_revenue);
    ctx.insert("totalOrders", &stats.total_orders);
    ctx.insert("pendingOrders", &stats.pending_orders);
    ctx.insert("activeProducts", &stats.active_products);
    ctx.insert("totalProducts", &stats.total_products);
    ctx.insert("totalCategories", &stats.total_categories);
    ctx.insert("totalCustomers", &stats.total_customers);
    ctx.insert("newCustomers7d", &stats.new_customers_7d);
    ctx.insert("inventoryValue", &stats.inventory_value);
    ctx.insert("outOfStock", &stats.out_of_stock);
    ctx.insert("totalSubscribers", &stats.total_subscribers);
    ctx.insert("repeatCustomers", &stats.repeat_customers);
    ctx.insert("lowStock", &stats.low_stock);
    ctx.insert("recentOrders", &stats.recent_orders);
    ctx.insert("recentActivity", &stats.recent_activity);

    match state.tera.render("admin/dashboard.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            format!("Template error: {}", e),
        )
            .into_response(),
    }
}

/// GET /admin/profile, GET /admin/profile.php
pub async fn admin_profile_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    let user_row: Option<User> = sqlx::query_as("SELECT * FROM users WHERE id = ? LIMIT 1")
        .bind(admin.id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

    let user = match user_row {
        Some(u) => u,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    let mut ctx = build_admin_context(&state, &session, "profile.php", "My Profile").await;
    ctx.insert("profile_user", &user);
    let empty: Vec<String> = Vec::new();
    ctx.insert("errors", &empty);
    ctx.insert("pwErrors", &empty);

    match state.tera.render("admin/profile.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            format!("Template error: {}", e),
        )
            .into_response(),
    }
}

/// POST /admin/profile, POST /admin/profile.php
pub async fn admin_profile_post_handler(
    State(state): State<AppState>,
    session: Session,
    mut multipart: Multipart,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    let user_row: Option<User> = sqlx::query_as("SELECT * FROM users WHERE id = ? LIMIT 1")
        .bind(admin.id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

    let user = match user_row {
        Some(u) => u,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    let mut form_type = String::new();
    let mut name = String::new();
    let mut email = String::new();
    let mut phone = String::new();
    let mut bio = String::new();
    let mut current_password = String::new();
    let mut new_password = String::new();
    let mut confirm_password = String::new();
    let mut avatar_bytes: Option<(String, Vec<u8>)> = None;

    while let Ok(Some(field)) = multipart.next_field().await {
        let field_name = field.name().unwrap_or("").to_string();
        if field_name == "form" {
            form_type = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "name" {
            name = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "email" {
            email = field.text().await.unwrap_or_default().trim().to_lowercase();
        } else if field_name == "phone" {
            phone = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "bio" {
            bio = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "current_password" {
            current_password = field.text().await.unwrap_or_default();
        } else if field_name == "new_password" {
            new_password = field.text().await.unwrap_or_default();
        } else if field_name == "confirm_password" {
            confirm_password = field.text().await.unwrap_or_default();
        } else if field_name == "avatar" {
            let file_name = field.file_name().unwrap_or("").to_string();
            if let Ok(bytes) = field.bytes().await {
                if !bytes.is_empty() && !file_name.is_empty() {
                    avatar_bytes = Some((file_name, bytes.to_vec()));
                }
            }
        }
    }

    if form_type == "profile" {
        let mut errors = Vec::new();
        if name.is_empty() {
            errors.push("Name is required.".to_string());
        }
        if !email.contains('@') || !email.contains('.') {
            errors.push("Valid email required.".to_string());
        }

        // Email uniqueness check
        let email_exists: Option<(u64,)> = sqlx::query_as(
            "SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1"
        )
        .bind(&email)
        .bind(admin.id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if email_exists.is_some() {
            errors.push("That email is already in use.".to_string());
        }

        let mut avatar_key = user.avatar.clone();
        let mut avatar_file_id = user.avatar_file_id;

        if let Some((original_name, bytes)) = avatar_bytes {
            let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
            let storage = StorageManager::new(uploads_dir);
            match storage
                .store_file(
                    &state.db,
                    "avatar",
                    &original_name,
                    &bytes,
                    StoreOptions {
                        owner_type: Some("user".to_string()),
                        owner_id: Some(admin.id),
                        visibility: Some("public".to_string()),
                        is_test: false,
                    },
                )
                .await
            {
                Ok(info) => {
                    avatar_key = Some(info.storage_key);
                    avatar_file_id = Some(info.id);

                    // Delete old avatar
                    if let Some(old_fid) = user.avatar_file_id {
                        let _ = storage.delete_file(&state.db, old_fid).await;
                    }
                }
                Err(e) => {
                    errors.push(format!("Avatar upload failed: {}", e));
                }
            }
        }

        if !errors.is_empty() {
            for err in errors {
                SessionHelper::add_flash(&session, "danger", &err).await;
            }
            return Redirect::to("/admin/profile.php").into_response();
        }

        let _ = sqlx::query(
            "UPDATE users SET name = ?, email = ?, phone = ?, bio = ?, avatar = ?, avatar_file_id = ?, updated_at = NOW() WHERE id = ?"
        )
        .bind(&name)
        .bind(&email)
        .bind(if phone.is_empty() { None } else { Some(&phone) })
        .bind(if bio.is_empty() { None } else { Some(&bio) })
        .bind(&avatar_key)
        .bind(avatar_file_id)
        .bind(admin.id)
        .execute(&state.db)
        .await;

        log_activity(&state.db, Some(admin.id), "profile.update", "Updated own profile", None).await;

        // Refresh admin session
        let updated_admin = AdminSession {
            id: admin.id,
            name,
            email,
            role: admin.role,
            permissions: admin.permissions,
            avatar: avatar_key,
        };
        SessionHelper::set_user(&session, &updated_admin).await;

        SessionHelper::add_flash(&session, "success", "Profile updated.").await;
        return Redirect::to("/admin/profile.php").into_response();
    } else if form_type == "password" {
        let mut pw_errors = Vec::new();
        if !admin_password_verify(&current_password, &user.password) {
            pw_errors.push("Current password is incorrect.".to_string());
        }
        if new_password.len() < 6 {
            pw_errors.push("New password must be at least 6 characters.".to_string());
        }
        if new_password != confirm_password {
            pw_errors.push("New passwords do not match.".to_string());
        }

        if !pw_errors.is_empty() {
            for err in pw_errors {
                SessionHelper::add_flash(&session, "danger", &err).await;
            }
            return Redirect::to("/admin/profile.php").into_response();
        }

        if let Ok(new_hash) = admin_password_hash(&new_password) {
            let _ = sqlx::query("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?")
                .bind(new_hash)
                .bind(admin.id)
                .execute(&state.db)
                .await;

            log_activity(&state.db, Some(admin.id), "password.change", "Changed own password", None).await;
            SessionHelper::add_flash(&session, "success", "Password updated.").await;
        }

        return Redirect::to("/admin/profile.php").into_response();
    }

    Redirect::to("/admin/profile.php").into_response()
}

#[derive(Debug, Deserialize)]
pub struct ActivityQuery {
    pub page: Option<i64>,
    pub per_page: Option<i64>,
    pub q: Option<String>,
    pub action: Option<String>,
    pub user_id: Option<u32>,
    pub from: Option<String>,
    pub to: Option<String>,
}

/// GET /admin/activity, GET /admin/activity.php
pub async fn admin_activity_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<ActivityQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("activity.view") && admin.role != "admin" {
        return (
            StatusCode::FORBIDDEN,
            Html("<h1>403 Forbidden</h1><p>Access denied to activity log.</p>"),
        )
            .into_response();
    }

    let page = query.page.unwrap_or(1).max(1);
    let per_page = query.per_page.unwrap_or(15).clamp(5, 100);

    let (rows, total) = db::queries::activity::get_activity_logs(
        &state.db,
        page,
        per_page,
        query.q.as_deref(),
        query.action.as_deref(),
        query.user_id,
        query.from.as_deref(),
        query.to.as_deref(),
    )
    .await
    .unwrap_or((Vec::new(), 0));

    let total_pages = (total as f64 / per_page as f64).ceil() as i64;

    let mut ctx = build_admin_context(&state, &session, "activity.php", "Activity log").await;
    ctx.insert("rows", &rows);
    ctx.insert("total", &total);
    ctx.insert("current_page", &page);
    ctx.insert("total_pages", &total_pages.max(1));

    match state.tera.render("admin/activity.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            format!("Template error: {}", e),
        )
            .into_response(),
    }
}

/// Guard helper for requiring a specific admin permission
pub fn verify_admin_permission(admin: &AdminSession, required: &str) -> Result<(), Response> {
    if admin.has_permission(required) {
        Ok(())
    } else {
        Err((
            StatusCode::FORBIDDEN,
            Html(format!(
                "<h1>403 Forbidden</h1><p>You do not have permission to access '{}'.</p>",
                required
            )),
        )
            .into_response())
    }
}
