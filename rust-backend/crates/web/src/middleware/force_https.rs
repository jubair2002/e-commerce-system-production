use axum::{
    extract::{Request, State},
    http::StatusCode,
    middleware::Next,
    response::{IntoResponse, Redirect, Response},
};
use crate::state::AppState;

pub async fn force_https_middleware(
    State(_state): State<AppState>,
    req: Request,
    next: Next,
) -> Response {
    let force = std::env::var("FORCE_HTTPS")
        .map(|v| v.eq_ignore_ascii_case("true") || v == "1")
        .unwrap_or(false);

    if !force {
        return next.run(req).await;
    }

    let headers = req.headers();
    let host = headers
        .get("host")
        .and_then(|v| v.to_str().ok())
        .unwrap_or("localhost");

    // Don't redirect localhost / 127.0.0.1
    if host.starts_with("localhost") || host.starts_with("127.0.0.1") {
        return next.run(req).await;
    }

    let is_https = headers
        .get("x-forwarded-proto")
        .and_then(|v| v.to_str().ok())
        .map(|v| v.eq_ignore_ascii_case("https"))
        .unwrap_or(false)
        || headers
            .get("cloudfront-forwarded-proto")
            .and_then(|v| v.to_str().ok())
            .map(|v| v.eq_ignore_ascii_case("https"))
            .unwrap_or(false)
        || headers
            .get("x-forwarded-ssl")
            .and_then(|v| v.to_str().ok())
            .map(|v| v.eq_ignore_ascii_case("on"))
            .unwrap_or(false);

    if !is_https {
        let uri = req.uri();
        let path_and_query = uri.path_and_query().map(|pq| pq.as_str()).unwrap_or("/");
        let redirect_url = format!("https://{}{}", host, path_and_query);
        return (StatusCode::MOVED_PERMANENTLY, Redirect::permanent(&redirect_url)).into_response();
    }

    next.run(req).await
}
