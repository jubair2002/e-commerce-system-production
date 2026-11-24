use axum::{
    extract::{Query, State},
    http::{header, HeaderValue},
    response::{Html, IntoResponse, Redirect, Response},
    Form,
};
use chrono::Utc;
use serde::Deserialize;
use tower_sessions::Session;

use crate::state::{build_base_context, AppState, SessionHelper};
use common::crypto::customer_password_hash;
use db::queries::customer_auth::{
    auth_rate_limited, build_customer_session, customer_authenticate, customer_register,
    password_ok, token_consume, token_issue, token_peek, token_revoke_all,
};
use domain::models::customer::Customer;

fn is_valid_bd_phone(phone: &str) -> bool {
    let clean: String = phone.chars().filter(|c| c.is_ascii_digit()).collect();
    if clean.len() == 11 && clean.starts_with("01") {
        let third = clean.chars().nth(2).unwrap_or('0');
        ('3'..='9').contains(&third)
    } else {
        clean.len() >= 7 && clean.len() <= 20
    }
}

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
    pub website_url: Option<String>,
    pub _form_rendered_at: Option<i64>,
    pub _render_ts: Option<i64>,
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

    match customer_authenticate(&state.db, &identifier, &password, Some("127.0.0.1")).await {
        Ok(customer) => {
            // Regenerate session id to protect against fixation
            SessionHelper::cycle_session_id(&session).await;

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

            let next_url = form
                .next
                .filter(|n| n.starts_with('/') && !n.starts_with("//"))
                .unwrap_or_else(|| "/account".to_string());

            let mut response = Redirect::to(&next_url).into_response();

            // Set remember-me cookie if requested
            let remember_checked = form
                .remember
                .map(|r| r == "1" || r.eq_ignore_ascii_case("on"))
                .unwrap_or(false);

            if remember_checked {
                if let Ok(raw_token) = token_issue(
                    &state.db,
                    customer.id,
                    "remember_me",
                    30 * 86400,
                    None,
                    Some("127.0.0.1"),
                    None,
                )
                .await
                {
                    let is_secure = state.config.site_url.starts_with("https://");
                    let secure_flag = if is_secure { "; Secure" } else { "" };
                    let cookie_val = format!(
                        "customer_remember={}; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax{}",
                        raw_token, secure_flag
                    );
                    if let Ok(hv) = HeaderValue::from_str(&cookie_val) {
                        response.headers_mut().append(header::SET_COOKIE, hv);
                    }
                }
            }

            response
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

    let mut ctx = build_base_context(&state, &session, "register").await;
    ctx.insert("_form_rendered_at", &Utc::now().timestamp());

    let reg_enabled = state.settings.get_or("customer_registration_enabled", "1") != "0";
    ctx.insert("registration_enabled", &reg_enabled);

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

    if state.settings.get_or("customer_registration_enabled", "1") == "0" {
        SessionHelper::add_flash(&session, "warning", "Customer registration is temporarily disabled. Please check back later.").await;
        return Redirect::to("/login").into_response();
    }

    // 1. Honeypot check: website_url must be empty
    if form.website_url.as_deref().map(|s| !s.trim().is_empty()).unwrap_or(false) {
        SessionHelper::add_flash(&session, "info", "Check your email to finish creating your account.").await;
        return Redirect::to("/login").into_response();
    }

    // 2. Time-trap check: form must take at least 2 seconds to complete
    let rendered_ts = form._form_rendered_at.or(form._render_ts).unwrap_or(0);
    if rendered_ts > 0 && (Utc::now().timestamp() - rendered_ts) < 2 {
        SessionHelper::add_flash(&session, "info", "Check your email to finish creating your account.").await;
        return Redirect::to("/login").into_response();
    }

    let name = form.name.as_deref().unwrap_or("").trim();
    let email = form.email.as_deref().unwrap_or("").trim().to_lowercase();
    let phone = form.phone.as_deref().map(|p| p.trim()).unwrap_or("");
    let password = form.password.as_deref().unwrap_or("");
    let confirm = form.password_confirmation.as_deref().unwrap_or("");

    // Validate Bangladeshi phone if provided
    if !phone.is_empty() && !is_valid_bd_phone(phone) {
        SessionHelper::add_flash(&session, "danger", "Please enter a valid contact phone number.").await;
        return Redirect::to("/register").into_response();
    }

    if password != confirm {
        SessionHelper::add_flash(&session, "danger", "Passwords do not match.").await;
        return Redirect::to("/register").into_response();
    }

    let min_len = state.settings.get_int("customer_password_min_length", 8) as usize;
    if let Err(msg) = password_ok(password, &email, name, min_len) {
        SessionHelper::add_flash(&session, "danger", &msg).await;
        return Redirect::to("/register").into_response();
    }

    let opt_in = form.marketing_opt_in.is_some();
    let require_verification = state.settings.get_bool("customer_require_email_verification", true);

    match customer_register(
        &state.db,
        name,
        &email,
        if phone.is_empty() { None } else { Some(phone) },
        password,
        opt_in,
        require_verification,
        "127.0.0.1",
    )
    .await
    {
        Ok(new_cust) => {
            if require_verification {
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
                SessionHelper::cycle_session_id(&session).await;
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
    SessionHelper::cycle_session_id(&session).await;
    SessionHelper::add_flash(&session, "info", "You have been successfully signed out.").await;

    let mut response = Redirect::to("/").into_response();
    if let Ok(hv) = HeaderValue::from_str("customer_remember=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax") {
        response.headers_mut().append(header::SET_COOKIE, hv);
    }
    response
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
    if !email.is_empty() && !auth_rate_limited(&state.db, "forgot", &email, "127.0.0.1").await {
        let cust: Option<Customer> = sqlx::query_as(
            "SELECT * FROM customers WHERE email = ? AND deleted_at IS NULL LIMIT 1",
        )
        .bind(&email)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if let Some(c) = cust {
            // 60-min TTL
            let ttl_sec = state.settings.get_int("customer_password_reset_minutes", 60) * 60;
            if let Ok(token) = token_issue(
                &state.db,
                c.id,
                "password_reset",
                ttl_sec,
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
                            "<p>Hi {},</p><p>You recently requested to reset your password. Click the link below to set a new password:</p><p><a href=\"{}\">{}</a></p><p>This link expires in 60 minutes.</p>",
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

    let peek_res = token_peek(&state.db, "password_reset", &token).await.unwrap_or(None);
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

    let consumed = token_consume(&state.db, "password_reset", token).await.unwrap_or(None);
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

    // Revoke all tokens for this customer (sessions and remember-me tokens)
    let _ = token_revoke_all(&state.db, token_record.customer_id, None).await;

    // Send password changed notification email
    let cust: Option<Customer> = sqlx::query_as("SELECT * FROM customers WHERE id = ? LIMIT 1")
        .bind(token_record.customer_id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

    if let Some(c) = cust {
        let site_name = state.settings.get_or("site_name", "Jinish Potro");
        let _ = state.mailer.queue_email(
            &c.email,
            Some(&c.name),
            &format!("Security Alert: Password Changed - {}", site_name),
            &format!(
                "<p>Hi {},</p><p>Your account password was just changed. If you did not make this change, please contact us immediately.</p>",
                c.name
            ),
            false,
        ).await;
    }

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

    if let Ok(Some(tok)) = token_consume(&state.db, "verify_email", &token).await {
        let _ = sqlx::query(
            "UPDATE customers SET email_verified_at = NOW(), status = 'active', updated_at = NOW() WHERE id = ?",
        )
        .bind(tok.customer_id)
        .execute(&state.db)
        .await;

        SessionHelper::add_flash(&session, "success", "Your email address has been verified successfully!").await;
        return Redirect::to("/login").into_response();
    }

    if let Ok(Some(tok)) = token_consume(&state.db, "email_change", &token).await {
        let new_email = tok.payload.unwrap_or_default();
        if new_email.contains('@') {
            let _ = sqlx::query(
                "UPDATE customers SET email = ?, updated_at = NOW() WHERE id = ?",
            )
            .bind(&new_email)
            .bind(tok.customer_id)
            .execute(&state.db)
            .await;

            if let Some(mut cust) = SessionHelper::get_customer(&session).await {
                if cust.id == tok.customer_id {
                    cust.email = new_email;
                    SessionHelper::set_customer(&session, &cust).await;
                }
            }

            SessionHelper::add_flash(&session, "success", "Your email address has been updated successfully!").await;
            return Redirect::to("/account").into_response();
        }
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
    if !email.is_empty() && !auth_rate_limited(&state.db, "resend", &email, "127.0.0.1").await {
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
