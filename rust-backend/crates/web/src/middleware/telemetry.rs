use axum::{
    extract::{Request, State},
    middleware::Next,
    response::Response,
};
use std::time::Instant;
use crate::state::AppState;

pub async fn telemetry_middleware(
    State(state): State<AppState>,
    req: Request,
    next: Next,
) -> Response {
    let path = req.uri().path().to_string();

    // Skip static assets and internal health checks
    if path.starts_with("/assets/") || path == "/health" {
        return next.run(req).await;
    }

    let method = req.method().to_string();
    let bytes_in = req
        .headers()
        .get("content-length")
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.parse::<u64>().ok())
        .unwrap_or(0);

    let start = Instant::now();
    let response = next.run(req).await;
    let duration = start.elapsed();
    let duration_ms = duration.as_millis() as u64;

    let status = response.status().as_u16();
    let bytes_out = response
        .headers()
        .get("content-length")
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.parse::<u64>().ok())
        .unwrap_or(512);

    let area = if path.contains("/admin") {
        "admin"
    } else if path.contains("/api") {
        "api"
    } else {
        "storefront"
    };

    let s2xx = if (200..300).contains(&status) { 1u32 } else { 0u32 };
    let s3xx = if (300..400).contains(&status) { 1u32 } else { 0u32 };
    let s4xx = if (400..500).contains(&status) { 1u32 } else { 0u32 };
    let s5xx = if status >= 500 { 1u32 } else { 0u32 };

    let pool = state.db.clone();
    let area_str = area.to_string();
    let path_str = path.clone();
    let method_str = method.clone();
    let slow_threshold = state.settings.get_int("monitor_slow_request_ms", 1500) as u64;

    // Asynchronously record traffic without delaying response delivery
    tokio::spawn(async move {
        let _ = sqlx::query(
            "INSERT INTO traffic_minute
              (bucket, area, requests, bytes_out, bytes_in, total_ms, max_ms, s2xx, s3xx, s4xx, s5xx)
             VALUES
              (DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:00'), ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
              requests  = requests + 1,
              bytes_out = bytes_out + VALUES(bytes_out),
              bytes_in  = bytes_in + VALUES(bytes_in),
              total_ms  = total_ms + VALUES(total_ms),
              max_ms    = GREATEST(max_ms, VALUES(max_ms)),
              s2xx      = s2xx + VALUES(s2xx),
              s3xx      = s3xx + VALUES(s3xx),
              s4xx      = s4xx + VALUES(s4xx),
              s5xx      = s5xx + VALUES(s5xx)",
        )
        .bind(&area_str)
        .bind(bytes_out)
        .bind(bytes_in)
        .bind(duration_ms)
        .bind(duration_ms)
        .bind(s2xx)
        .bind(s3xx)
        .bind(s4xx)
        .bind(s5xx)
        .execute(&pool)
        .await;

        if duration_ms >= slow_threshold {
            let _ = sqlx::query(
                "INSERT INTO slow_requests
                  (path, method, status_code, duration_ms, query_count, peak_mem, created_at)
                 VALUES
                  (?, ?, ?, ?, 0, 0, NOW())",
            )
            .bind(&path_str)
            .bind(&method_str)
            .bind(status)
            .bind(duration_ms as u32)
            .execute(&pool)
            .await;
        }
    });

    response
}
