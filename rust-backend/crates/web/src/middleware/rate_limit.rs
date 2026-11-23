use axum::{
    extract::Request,
    http::StatusCode,
    middleware::Next,
    response::{IntoResponse, Json, Response},
};
use governor::clock::DefaultClock;
use governor::state::keyed::DefaultKeyedStateStore;
use governor::{Quota, RateLimiter};
use std::net::SocketAddr;
use std::num::NonZeroU32;
use std::sync::Arc;

#[derive(Clone)]
pub struct RateLimitManager {
    track_limiter: Arc<RateLimiter<String, DefaultKeyedStateStore<String>, DefaultClock>>,
    availability_limiter: Arc<RateLimiter<String, DefaultKeyedStateStore<String>, DefaultClock>>,
}

impl Default for RateLimitManager {
    fn default() -> Self {
        Self::new()
    }
}

impl RateLimitManager {
    pub fn new() -> Self {
        let track_quota = Quota::per_minute(NonZeroU32::new(30).unwrap());
        let track_limiter = Arc::new(RateLimiter::keyed(track_quota));

        let avail_quota = Quota::per_minute(NonZeroU32::new(60).unwrap());
        let availability_limiter = Arc::new(RateLimiter::keyed(avail_quota));

        Self {
            track_limiter,
            availability_limiter,
        }
    }

    pub fn check_track(&self, ip: &str) -> bool {
        self.track_limiter.check_key(&ip.to_string()).is_ok()
    }

    pub fn check_availability(&self, ip: &str) -> bool {
        self.availability_limiter.check_key(&ip.to_string()).is_ok()
    }
}

fn client_ip(req: &Request) -> String {
    req.headers()
        .get("x-forwarded-for")
        .and_then(|v| v.to_str().ok())
        .and_then(|s| s.split(',').next())
        .map(|s| s.trim().to_string())
        .or_else(|| {
            req.headers()
                .get("x-real-ip")
                .and_then(|v| v.to_str().ok())
                .map(|s| s.trim().to_string())
        })
        .or_else(|| {
            req.extensions()
                .get::<SocketAddr>()
                .map(|addr| addr.ip().to_string())
        })
        .unwrap_or_else(|| "127.0.0.1".to_string())
}

pub async fn track_rate_limit_middleware(
    req: Request,
    next: Next,
) -> Response {
    let limiter = RateLimitManager::new();
    let ip = client_ip(&req);

    if !limiter.check_track(&ip) {
        return (
            StatusCode::TOO_MANY_REQUESTS,
            Json(serde_json::json!({
                "ok": false,
                "error": "Too many tracking lookups. Please wait a moment and try again."
            })),
        )
            .into_response();
    }

    next.run(req).await
}

pub async fn availability_rate_limit_middleware(
    req: Request,
    next: Next,
) -> Response {
    let limiter = RateLimitManager::new();
    let ip = client_ip(&req);

    if !limiter.check_availability(&ip) {
        return (
            StatusCode::TOO_MANY_REQUESTS,
            Json(serde_json::json!({
                "ok": false,
                "error": "Too many availability checks. Please slow down."
            })),
        )
            .into_response();
    }

    next.run(req).await
}
