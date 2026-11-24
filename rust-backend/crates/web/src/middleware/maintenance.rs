use axum::{
    extract::{Request, State},
    http::StatusCode,
    middleware::Next,
    response::{Html, IntoResponse, Json, Response},
};
use crate::state::{AppState, SessionHelper};
use tera::Context;
use tower_sessions::Session;

pub async fn maintenance_middleware(
    State(state): State<AppState>,
    session: Session,
    req: Request,
    next: Next,
) -> Response {
    let path = req.uri().path();

    // Never block admin, health checks, static asset endpoints, or maintenance preview routes
    if path.starts_with("/admin")
        || path == "/health"
        || path.starts_with("/assets")
        || path == "/maintenance"
        || path == "/storefront/errors/maintenance.php"
    {
        return next.run(req).await;
    }

    // Dynamic settings refresh from MySQL
    let _ = state.settings.check_updated_at(&state.db).await;

    let query = req.uri().query().unwrap_or("");
    let preview = query.contains("preview_maintenance=1");
    let is_maintenance = state.settings.get_bool("maintenance_mode", false) || preview;

    if is_maintenance {
        // Allow admin/editor bypass ONLY when explicitly requested (?bypass=1 or ?admin_bypass=1)
        if let Some(user) = SessionHelper::get_user(&session).await {
            if (user.role == "admin" || user.role == "editor") && (query.contains("bypass=1") || query.contains("admin_bypass=1")) {
                return next.run(req).await;
            }
        }

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
        if let Some(user) = SessionHelper::get_user(&session).await {
            ctx.insert("user", &user);
        }

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
