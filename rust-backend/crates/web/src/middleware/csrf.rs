use axum::{
    body::{to_bytes, Body},
    extract::Request,
    http::{Method, StatusCode},
    middleware::Next,
    response::{Html, IntoResponse, Json, Response},
};
use common::crypto::verify_csrf_token;
use std::collections::HashMap;
use tower_sessions::Session;

use crate::state::SessionHelper;

const MAX_BODY_SIZE: usize = 10 * 1024 * 1024; // 10MB

pub async fn csrf_middleware(
    session: Session,
    req: Request,
    next: Next,
) -> Response {
    let method = req.method().clone();
    if method == Method::GET || method == Method::HEAD || method == Method::OPTIONS {
        return next.run(req).await;
    }

    // Whitelist installer routes
    let path = req.uri().path();
    if path == "/admin/install" || path == "/admin/install.php" {
        return next.run(req).await;
    }

    let expected_token = SessionHelper::get_csrf(&session).await;

    // 1. Check header: X-CSRF-Token or X-Csrf-Token
    if let Some(header_val) = req
        .headers()
        .get("X-CSRF-Token")
        .or_else(|| req.headers().get("x-csrf-token"))
        .and_then(|h| h.to_str().ok())
    {
        if verify_csrf_token(header_val.trim(), &expected_token) {
            return next.run(req).await;
        } else {
            return reject_csrf(&req);
        }
    }

    // 2. Read body bytes and inspect _csrf or csrf_token field
    let (parts, body) = req.into_parts();
    let bytes = match to_bytes(body, MAX_BODY_SIZE).await {
        Ok(b) => b,
        Err(_) => return StatusCode::PAYLOAD_TOO_LARGE.into_response(),
    };

    let mut token_found = None;

    // Try parsing as form urlencoded
    if let Ok(form_data) = serde_urlencoded::from_bytes::<HashMap<String, String>>(&bytes) {
        if let Some(t) = form_data.get("_csrf").or_else(|| form_data.get("csrf_token")) {
            token_found = Some(t.clone());
        }
    }

    // Try parsing as JSON if Content-Type is application/json
    if token_found.is_none() {
        if let Ok(json_data) = serde_json::from_slice::<serde_json::Value>(&bytes) {
            if let Some(t) = json_data.get("_csrf").or_else(|| json_data.get("csrf_token")).and_then(|v| v.as_str()) {
                token_found = Some(t.to_string());
            }
        }
    }

    // Try parsing as multipart form data
    if token_found.is_none() {
        token_found = extract_csrf_from_multipart(&bytes);
    }

    let is_valid = match token_found {
        Some(ref t) => verify_csrf_token(t.trim(), &expected_token),
        None => false,
    };

    let reconstructed_req = Request::from_parts(parts, Body::from(bytes));

    if is_valid {
        next.run(reconstructed_req).await
    } else {
        reject_csrf(&reconstructed_req)
    }
}

fn extract_csrf_from_multipart(bytes: &[u8]) -> Option<String> {
    for needle in [
        b"name=\"_csrf\"".as_slice(),
        b"name=\"csrf_token\"".as_slice(),
        b"name='_csrf'".as_slice(),
        b"name='csrf_token'".as_slice(),
    ] {
        if let Some(pos) = bytes.windows(needle.len()).position(|w| w == needle) {
            let after = &bytes[pos + needle.len()..];
            let value_start = if let Some(sep) = after.windows(4).position(|w| w == b"\r\n\r\n") {
                sep + 4
            } else if let Some(sep) = after.windows(2).position(|w| w == b"\n\n") {
                sep + 2
            } else {
                continue;
            };
            let content = &after[value_start..];
            let value_end = if let Some(end) = content.windows(2).position(|w| w == b"\r\n") {
                end
            } else if let Some(end) = content.windows(1).position(|w| w == b"\n") {
                end
            } else {
                content.len()
            };
            if let Ok(s) = std::str::from_utf8(&content[..value_end]) {
                let trimmed = s.trim();
                if !trimmed.is_empty() {
                    return Some(trimmed.to_string());
                }
            }
        }
    }
    None
}

fn reject_csrf(req: &Request) -> Response {
    let path = req.uri().path();
    let query = req.uri().query().unwrap_or("");
    let is_ajax = req
        .headers()
        .get("x-requested-with")
        .and_then(|h| h.to_str().ok())
        .map(|v| v.eq_ignore_ascii_case("xmlhttprequest"))
        .unwrap_or(false);
    let accepts_json = req
        .headers()
        .get("accept")
        .and_then(|h| h.to_str().ok())
        .map(|a| a.contains("application/json"))
        .unwrap_or(false);
    let is_json_content = req
        .headers()
        .get("content-type")
        .and_then(|h| h.to_str().ok())
        .map(|c| c.contains("application/json"))
        .unwrap_or(false);

    let is_api = path.starts_with("/api")
        || path.contains("/ajax/")
        || query.contains("ajax=1")
        || is_ajax
        || accepts_json
        || is_json_content;

    if is_api {
        (
            StatusCode::FORBIDDEN,
            Json(serde_json::json!({
                "ok": false,
                "error": "Invalid or expired CSRF security token. Please refresh and try again."
            })),
        )
            .into_response()
    } else {
        (
            StatusCode::FORBIDDEN,
            Html("<h1>403 Forbidden</h1><p>Invalid or expired CSRF token. Please refresh the page and submit again.</p>".to_string()),
        )
            .into_response()
    }
}
