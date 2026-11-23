use axum::{
    extract::{Request, State},
    http::StatusCode,
    middleware::Next,
    response::{Html, IntoResponse, Json, Response},
};
use crate::state::AppState;
use tera::Context;

pub async fn maintenance_middleware(
    State(state): State<AppState>,
    req: Request,
    next: Next,
) -> Response {
    let path = req.uri().path();

    // Never block admin, health checks, or static asset endpoints
    if path.starts_with("/admin") || path == "/health" || path.starts_with("/assets") {
        return next.run(req).await;
    }

    let query = req.uri().query().unwrap_or("");
    let preview = query.contains("preview_maintenance=1");
    let is_maintenance = state.settings.get_bool("maintenance_mode", false) || preview;

    if is_maintenance {
        if path.starts_with("/api") {
            return (
                StatusCode::SERVICE_UNAVAILABLE,
                Json(serde_json::json!({
                    "error": "Store is currently under scheduled maintenance."
                })),
            )
                .into_response();
        }

        let mut ctx = Context::new();
        ctx.insert("site_name", &state.settings.get_or("site_name", "Jinish Potro"));
        ctx.insert("base_url", &state.config.app_url);

        match state.tera.render("errors/maintenance.html", &ctx) {
            Ok(html) => return (StatusCode::SERVICE_UNAVAILABLE, Html(html)).into_response(),
            Err(e) => {
                tracing::error!("Failed to render maintenance.html: {}", e);
                return (
                    StatusCode::SERVICE_UNAVAILABLE,
                    Html("<h1>503 Service Unavailable</h1><p>Our store is currently undergoing scheduled maintenance.</p>".to_string()),
                )
                    .into_response();
            }
        }
    }

    next.run(req).await
}
