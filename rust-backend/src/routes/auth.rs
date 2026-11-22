use axum::{
    extract::{Query, State},
    response::{Html, IntoResponse, Redirect, Response},
    Form,
};
use serde::Deserialize;
use tower_sessions::Session;

use crate::crypto::customer_password_hash;
use crate::models::customer::Customer;
use crate::services::customer_auth::{
    build_customer_session, customer_authenticate, customer_register, token_consume, token_issue,
    token_peek,
};
use crate::state::{build_base_context, AppState, SessionHelper};

#[derive(Debug, Deserialize)]
pub struct NextQuery {
    pub next: Option<String>,
    pub add_wishlist: Option<u64>,
    pub token: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct LoginForm {
    pub _csrf: Option<String>,
    pub login: Option<String>,
    pub email: Option<String>,
    pub password: Option<String>,
    pub remember: Option<String>,
    pub next: Option<String>,
    pub add_wishlist: Option<u64>,
}

#[derive(Debug, Deserialize)]
pub struct RegisterForm {
    pub _csrf: Option<String>,
    pub name: Option<String>,
    pub email: Option<String>,
    pub phone: Option<String>,
    pub password: Option<String>,
    pub password_confirmation: Option<String>,
    pub agree_terms: Option<String>,
    pub marketing_opt_in: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct ForgotPasswordForm {
    pub _csrf: Option<String>,
    pub email: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct ResetPasswordForm {
    pub _csrf: Option<String>,
    pub token: Option<String>,
    pub password: Option<String>,
    pub password_confirmation: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct ResendVerificationForm {
    pub _csrf: Option<String>,
    pub email: Option<String>,
}

// -------------------------------------------------------------
// GET /login, GET /login.php
// -------------------------------------------------------------
pub async fn login_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<NextQuery>,
) -> Result<Html<String>, Response> {
    if SessionHelper::get_customer(&session).await.is_some() {
        return Err(Redirect::to("/account").into_response());
    }

    let mut ctx = build_base_context(&state, &session, "login").await;
    ctx.insert("next", &query.next.unwrap_or_default());
    ctx.insert("add_wishlist", &query.add_wishlist.unwrap_or(0));

    state
        .tera
        .render("auth/login.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// POST /login
// -------------------------------------------------------------
pub async fn login_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<LoginForm>,
) -> Response {
    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/login").into_response();
    }

    let identifier = form.login.or(form.email).unwrap_or_default();
    let password = form.password.unwrap_or_default();

    match customer_authenticate(&state.db, &identifier, &password, Some("127.0.0.1"), None).await {
        Ok(customer) => {
            let session_data = build_customer_session(&customer);
            SessionHelper::set_customer(&session, &session_data).await;

            if let Some(pid) = form.add_wishlist {
                if pid > 0 {
                    let _ = sqlx::query(
                        "INSERT IGNORE INTO wishlists (customer_id, product_id, created_at) VALUES (?, ?, NOW())",
                    )
                    .bind(customer.id)
                    .bind(pid)
                    .execute(&state.db)
                    .await;
                }
            }

            SessionHelper::add_flash(
                &session,
                "success",
                &format!("Welcome back, {}!", customer.name),
            )
            .await;

            if let Some(ref next_url) = form.next {
                if next_url.starts_with('/') && !next_url.starts_with("//") {
                    return Redirect::to(next_url).into_response();
                }
            }

            Redirect::to("/account").into_response()
        }
        Err(err_msg) => {
            SessionHelper::add_flash(&session, "danger", &err_msg).await;
            Redirect::to("/login").into_response()
        }
    }
}

// -------------------------------------------------------------
// GET /register, GET /register.php
// -------------------------------------------------------------
pub async fn register_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    if SessionHelper::get_customer(&session).await.is_some() {
        return Err(Redirect::to("/account").into_response());
    }

    let ctx = build_base_context(&state, &session, "register").await;
    state
        .tera
        .render("auth/register.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// POST /register
// -------------------------------------------------------------
pub async fn register_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<RegisterForm>,
) -> Response {
    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/register").into_response();
    }

    let name = form.name.as_deref().unwrap_or("").trim();
    let email = form.email.as_deref().unwrap_or("").trim();
    let phone = form.phone.as_deref().map(|p| p.trim());
    let password = form.password.as_deref().unwrap_or("");
    let confirm = form.password_confirmation.as_deref().unwrap_or("");

    if password != confirm {
        SessionHelper::add_flash(&session, "danger", "Passwords do not match.").await;
        return Redirect::to("/register").into_response();
    }

    let opt_in = form.marketing_opt_in.is_some();

    match customer_register(&state.db, name, email, phone, password, opt_in).await {
        Ok(new_cust) => {
            let verify_required = state.settings.get_or("customer_email_verification", "0") == "1";
            if verify_required {
                if let Ok(token) = token_issue(
                    &state.db,
                    new_cust.id,
                    "verify_email",
                    86400 * 3, // 3 days
                    None,
                    Some("127.0.0.1"),
                    None,
                )
                .await
                {
                    let verify_url = format!("{}/verify-email?token={}", state.config.app_url.trim_end_matches('/'), token);
                    let site_name = state.settings.get_or("site_name", "Jinish Potro");
                    let _ = state.mailer.queue_email(
                        &new_cust.email,
                        Some(&new_cust.name),
                        &format!("Verify your email - {}", site_name),
                        &format!(
                            "<p>Welcome {}!</p><p>Please verify your email address by clicking the link below:</p><p><a href=\"{}\">{}</a></p>",
                            new_cust.name, verify_url, verify_url
                        ),
                        false,
                    ).await;
                }
                SessionHelper::add_flash(&session, "info", "Account created! Please check your email to verify your address.").await;
            } else {
                let session_data = build_customer_session(&new_cust);
                SessionHelper::set_customer(&session, &session_data).await;
                SessionHelper::add_flash(&session, "success", "Welcome! Your account has been created.").await;
                return Redirect::to("/account").into_response();
            }

            Redirect::to("/login").into_response()
        }
        Err(err) => {
            SessionHelper::add_flash(&session, "danger", &err).await;
            Redirect::to("/register").into_response()
        }
    }
}

// -------------------------------------------------------------
// GET /logout, POST /logout, GET /logout.php
// -------------------------------------------------------------
pub async fn logout_handler(
    session: Session,
) -> Response {
    SessionHelper::remove_customer(&session).await;
    SessionHelper::add_flash(&session, "info", "You have been successfully signed out.").await;
    Redirect::to("/").into_response()
}

// -------------------------------------------------------------
// GET /forgot-password, GET /forgot_password.php
// -------------------------------------------------------------
pub async fn forgot_password_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    let ctx = build_base_context(&state, &session, "forgot_password").await;
    state
        .tera
        .render("auth/forgot_password.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// POST /forgot-password
// -------------------------------------------------------------
pub async fn forgot_password_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<ForgotPasswordForm>,
) -> Response {
    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/forgot-password").into_response();
    }

    let email = form.email.as_deref().unwrap_or("").trim().to_lowercase();
    if !email.is_empty() {
        let cust: Option<Customer> = sqlx::query_as(
            "SELECT * FROM customers WHERE email = ? AND deleted_at IS NULL LIMIT 1",
        )
        .bind(&email)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if let Some(c) = cust {
            if let Ok(token) = token_issue(
                &state.db,
                c.id,
                "password_reset",
                7200, // 2 hours
                None,
                Some("127.0.0.1"),
                None,
            )
            .await
            {
                let reset_url = format!("{}/reset-password?token={}", state.config.app_url.trim_end_matches('/'), token);
                let site_name = state.settings.get_or("site_name", "Jinish Potro");
                let _ = state.mailer.queue_email(
                    &c.email,
                    Some(&c.name),
                    &format!("Password Reset Request - {}", site_name),
                    &format!(
                        "<p>Hi {},</p><p>You recently requested to reset your password. Click the link below to set a new password:</p><p><a href=\"{}\">{}</a></p><p>This link expires in 2 hours.</p>",
                        c.name, reset_url, reset_url
                    ),
                    false,
                ).await;
            }
        }
    }

    // Always show same message to prevent email enumeration
    SessionHelper::add_flash(
        &session,
        "info",
        "If an account exists with that email address, password reset instructions have been sent.",
    )
    .await;

    Redirect::to("/login").into_response()
}

// -------------------------------------------------------------
// GET /reset-password, GET /reset_password.php
// -------------------------------------------------------------
pub async fn reset_password_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<NextQuery>,
) -> Result<Html<String>, Response> {
    let mut ctx = build_base_context(&state, &session, "reset_password").await;
    let token = query.token.unwrap_or_default();

    let peek_res = token_peek(&state.db, &token, "password_reset").await.unwrap_or(None);
    if peek_res.is_none() {
        SessionHelper::add_flash(&session, "danger", "This password reset link is invalid or has expired.").await;
        return Err(Redirect::to("/forgot-password").into_response());
    }

    ctx.insert("token", &token);
    state
        .tera
        .render("auth/reset_password.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// POST /reset-password
// -------------------------------------------------------------
pub async fn reset_password_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<ResetPasswordForm>,
) -> Response {
    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/forgot-password").into_response();
    }

    let token = form.token.as_deref().unwrap_or("").trim();
    let pwd = form.password.as_deref().unwrap_or("");
    let conf = form.password_confirmation.as_deref().unwrap_or("");

    if pwd.len() < 8 {
        SessionHelper::add_flash(&session, "danger", "Password must be at least 8 characters long.").await;
        return Redirect::to(&format!("/reset-password?token={}", token)).into_response();
    }

    if pwd != conf {
        SessionHelper::add_flash(&session, "danger", "Passwords do not match.").await;
        return Redirect::to(&format!("/reset-password?token={}", token)).into_response();
    }

    let consumed = token_consume(&state.db, token, "password_reset").await.unwrap_or(None);
    let token_record = match consumed {
        Some(t) => t,
        None => {
            SessionHelper::add_flash(&session, "danger", "This password reset link is invalid or has already been used.").await;
            return Redirect::to("/forgot-password").into_response();
        }
    };

    let new_hash = match customer_password_hash(pwd) {
        Ok(h) => h,
        Err(e) => {
            SessionHelper::add_flash(&session, "danger", &format!("Password error: {}", e)).await;
            return Redirect::to(&format!("/reset-password?token={}", token)).into_response();
        }
    };

    let _ = sqlx::query(
        "UPDATE customers SET password_hash = ?, failed_logins = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?",
    )
    .bind(&new_hash)
    .bind(token_record.customer_id)
    .execute(&state.db)
    .await;

    SessionHelper::add_flash(&session, "success", "Your password has been reset successfully. Please sign in.").await;
    Redirect::to("/login").into_response()
}

// -------------------------------------------------------------
// GET /verify-email, GET /verify_email.php
// -------------------------------------------------------------
pub async fn verify_email_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<NextQuery>,
) -> Response {
    let token = query.token.unwrap_or_default();

    if let Ok(Some(tok)) = token_consume(&state.db, &token, "verify_email").await {
        let _ = sqlx::query(
            "UPDATE customers SET email_verified_at = NOW(), updated_at = NOW() WHERE id = ?",
        )
        .bind(tok.customer_id)
        .execute(&state.db)
        .await;

        SessionHelper::add_flash(&session, "success", "Your email address has been verified successfully!").await;
        return Redirect::to("/login").into_response();
    }

    SessionHelper::add_flash(&session, "danger", "Invalid or expired verification link.").await;
    Redirect::to("/login").into_response()
}

// -------------------------------------------------------------
// GET /resend-verification, POST /resend-verification, GET /resend_verification.php
// -------------------------------------------------------------
pub async fn resend_verification_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    let ctx = build_base_context(&state, &session, "resend_verification").await;
    state
        .tera
        .render("auth/resend_verification.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

pub async fn resend_verification_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<ResendVerificationForm>,
) -> Response {
    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/resend-verification").into_response();
    }

    let email = form.email.as_deref().unwrap_or("").trim().to_lowercase();
    if !email.is_empty() {
        let cust: Option<Customer> = sqlx::query_as(
            "SELECT * FROM customers WHERE email = ? AND deleted_at IS NULL LIMIT 1",
        )
        .bind(&email)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if let Some(c) = cust {
            if c.email_verified_at.is_none() {
                if let Ok(token) = token_issue(
                    &state.db,
                    c.id,
                    "verify_email",
                    86400 * 3,
                    None,
                    Some("127.0.0.1"),
                    None,
                )
                .await
                {
                    let verify_url = format!("{}/verify-email?token={}", state.config.app_url.trim_end_matches('/'), token);
                    let site_name = state.settings.get_or("site_name", "Jinish Potro");
                    let _ = state.mailer.queue_email(
                        &c.email,
                        Some(&c.name),
                        &format!("Verify your email - {}", site_name),
                        &format!(
                            "<p>Hi {},</p><p>Please verify your email address by clicking the link below:</p><p><a href=\"{}\">{}</a></p>",
                            c.name, verify_url, verify_url
                        ),
                        false,
                    ).await;
                }
            }
        }
    }

    SessionHelper::add_flash(&session, "info", "If an unverified account exists with that email, a verification link has been sent.").await;
    Redirect::to("/login").into_response()
}
