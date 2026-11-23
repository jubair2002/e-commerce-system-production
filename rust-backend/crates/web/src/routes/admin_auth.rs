use axum::{
    extract::{Query, State},
    http::StatusCode,
    response::{Html, IntoResponse, Redirect, Response},
    Form,
};
use common::crypto::{admin_password_verify, dummy_admin_password_verify};
use domain::models::user::User;
use serde::Deserialize;
use tower_sessions::Session;

use crate::state::{AdminSession, AppState, SessionHelper};

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

/// GET /admin/login, GET /admin/login.php
pub async fn admin_login_get_handler(
    State(_state): State<AppState>,
    session: Session,
    Query(query): Query<AdminLoginQuery>,
) -> Response {
    if SessionHelper::get_user(&session).await.is_some() {
        return Redirect::to("/admin/").into_response();
    }

    let csrf = SessionHelper::get_csrf(&session).await;
    let next_val = query.next.unwrap_or_default();

    let html = format!(
        r#"<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Jinish Potro</title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/css/bootstrap.min.css">
    <style>
        body {{ background-color: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }}
        .card {{ border: none; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); width: 100%; max-width: 400px; }}
    </style>
</head>
<body>
    <div class="card p-4">
        <h3 class="text-center mb-4 font-weight-bold">Staff Login</h3>
        <form method="POST" action="/admin/login.php">
            <input type="hidden" name="_csrf" value="{}">
            <input type="hidden" name="next" value="{}">
            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2">Sign In</button>
        </form>
    </div>
</body>
</html>"#,
        csrf, next_val
    );

    Html(html).into_response()
}

/// POST /admin/login, POST /admin/login.php
pub async fn admin_login_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<AdminLoginForm>,
) -> Response {
    let email = form.email.as_deref().unwrap_or("").trim().to_lowercase();
    let password = form.password.as_deref().unwrap_or("");

    if email.is_empty() || password.is_empty() {
        SessionHelper::add_flash(&session, "danger", "Please provide email and password.").await;
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
            SessionHelper::add_flash(&session, "danger", "Invalid email or password.").await;
            return Redirect::to("/admin/login.php").into_response();
        }
    };

    if u.status != "active" {
        SessionHelper::add_flash(&session, "danger", "Your account is inactive. Contact administrator.").await;
        return Redirect::to("/admin/login.php").into_response();
    }

    let valid = admin_password_verify(password, &u.password);
    if !valid {
        SessionHelper::add_flash(&session, "danger", "Invalid email or password.").await;
        return Redirect::to("/admin/login.php").into_response();
    }

    // Regenerate session id to protect against fixation
    SessionHelper::cycle_session_id(&session).await;

    let perms = u.permission_list();
    let admin_session = AdminSession {
        id: u.id,
        name: u.name,
        email: u.email,
        role: u.role,
        permissions: perms,
        avatar: u.avatar,
    };

    SessionHelper::set_user(&session, &admin_session).await;

    // Update last_login in users table
    let _ = sqlx::query("UPDATE users SET last_login = NOW() WHERE id = ?")
        .bind(admin_session.id)
        .execute(&state.db)
        .await;

    let next = form
        .next
        .filter(|n| n.starts_with("/admin") && !n.starts_with("//"))
        .unwrap_or_else(|| "/admin/".to_string());

    Redirect::to(&next).into_response()
}

/// GET /admin/logout, POST /admin/logout
pub async fn admin_logout_handler(
    session: Session,
) -> Response {
    SessionHelper::remove_user(&session).await;
    SessionHelper::cycle_session_id(&session).await;
    Redirect::to("/admin/login.php").into_response()
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
