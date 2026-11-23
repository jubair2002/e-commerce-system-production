use axum::{
    extract::{Request, State},
    http::header,
    middleware::Next,
    response::Response,
};
use db::queries::customer_auth::{build_customer_session, customer_remember_restore};
use tower_sessions::Session;

use crate::state::{AppState, SessionHelper};

pub async fn remember_me_middleware(
    State(state): State<AppState>,
    session: Session,
    req: Request,
    next: Next,
) -> Response {
    let mut rotated_token = None;

    if SessionHelper::get_customer(&session).await.is_none() {
        if let Some(cookie_hdr) = req.headers().get(header::COOKIE).and_then(|h| h.to_str().ok()) {
            for pair in cookie_hdr.split(';') {
                let trimmed = pair.trim();
                if let Some(val) = trimmed.strip_prefix("customer_remember=") {
                    if !val.is_empty() {
                        let ip = req
                            .headers()
                            .get("x-forwarded-for")
                            .and_then(|v| v.to_str().ok())
                            .and_then(|s| s.split(',').next())
                            .map(|s| s.trim())
                            .unwrap_or("127.0.0.1");

                        if let Ok(Some((cust, new_tok))) =
                            customer_remember_restore(&state.db, val, Some(ip)).await
                        {
                            let sess = build_customer_session(&cust);
                            SessionHelper::set_customer(&session, &sess).await;
                            rotated_token = Some(new_tok);
                        }
                    }
                    break;
                }
            }
        }
    }

    let mut res = next.run(req).await;

    if let Some(new_tok) = rotated_token {
        let is_secure = state.config.site_url.starts_with("https://");
        let secure_flag = if is_secure { "; Secure" } else { "" };
        let cookie_val = format!(
            "customer_remember={}; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax{}",
            new_tok, secure_flag
        );
        if let Ok(hv) = header::HeaderValue::from_str(&cookie_val) {
            res.headers_mut().append(header::SET_COOKIE, hv);
        }
    }

    res
}
