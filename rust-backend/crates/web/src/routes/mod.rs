pub mod account;
pub mod admin_auth;
pub mod api;
pub mod auth;
pub mod media;
pub mod metrics;
pub mod storefront;

use axum::{
    http::StatusCode,
    middleware as axum_mw,
    response::{Html, IntoResponse, Json, Response},
    routing::{get, post},
    Router,
};
use tower_http::services::ServeDir;

use crate::middleware::{
    availability_rate_limit_middleware, csrf_middleware, force_https_middleware,
    maintenance_middleware, remember_me_middleware, security_headers_middleware,
    telemetry_middleware, track_rate_limit_middleware,
};
use crate::state::AppState;

pub fn build_router(state: AppState) -> Router {
    let app_router = Router::new()
        // Storefront
        .route("/", get(storefront::home_handler))
        .route("/index.php", get(storefront::home_handler))
        .route("/shop", get(storefront::shop_handler))
        .route("/shop.php", get(storefront::shop_handler))
        .route("/category/:slug", get(storefront::category_slug_handler))
        .route("/category.php", get(storefront::category_php_handler))
        .route("/product/:slug", get(storefront::product_slug_handler))
        .route("/product.php", get(storefront::product_php_handler))
        .route("/cart", get(storefront::cart_get_handler).post(storefront::cart_post_handler))
        .route("/cart.php", get(storefront::cart_get_handler).post(storefront::cart_post_handler))
        .route("/checkout", get(storefront::checkout_get_handler).post(storefront::checkout_post_handler))
        .route("/checkout.php", get(storefront::checkout_get_handler).post(storefront::checkout_post_handler))
        .route("/order/confirmation", get(storefront::order_confirmation_handler))
        .route("/order_confirmation.php", get(storefront::order_confirmation_handler))
        .route(
            "/track",
            get(storefront::track_get_handler)
                .post(storefront::track_post_handler)
                .layer(axum_mw::from_fn(track_rate_limit_middleware)),
        )
        .route(
            "/track.php",
            get(storefront::track_get_handler)
                .post(storefront::track_post_handler)
                .layer(axum_mw::from_fn(track_rate_limit_middleware)),
        )
        .route("/page/:slug", get(storefront::page_handler))
        .route("/contact", get(storefront::contact_get_handler).post(storefront::contact_post_handler))
        .route("/contact.php", get(storefront::contact_get_handler).post(storefront::contact_post_handler))
        .route("/sitemap.xml", get(storefront::sitemap_handler))
        .route("/sitemap.php", get(storefront::sitemap_handler))

        // Auth
        .route("/login", get(auth::login_get_handler).post(auth::login_post_handler))
        .route("/login.php", get(auth::login_get_handler).post(auth::login_post_handler))
        .route("/register", get(auth::register_get_handler).post(auth::register_post_handler))
        .route("/register.php", get(auth::register_get_handler).post(auth::register_post_handler))
        .route("/logout", get(auth::logout_handler).post(auth::logout_handler))
        .route("/logout.php", get(auth::logout_handler).post(auth::logout_handler))
        .route("/forgot-password", get(auth::forgot_password_get_handler).post(auth::forgot_password_post_handler))
        .route("/forgot_password.php", get(auth::forgot_password_get_handler).post(auth::forgot_password_post_handler))
        .route("/reset-password", get(auth::reset_password_get_handler).post(auth::reset_password_post_handler))
        .route("/reset_password.php", get(auth::reset_password_get_handler).post(auth::reset_password_post_handler))
        .route("/verify-email", get(auth::verify_email_handler))
        .route("/verify_email.php", get(auth::verify_email_handler))
        .route("/resend-verification", get(auth::resend_verification_get_handler).post(auth::resend_verification_post_handler))
        .route("/resend_verification.php", get(auth::resend_verification_get_handler).post(auth::resend_verification_post_handler))

        // Admin Auth
        .route("/admin/login", get(admin_auth::admin_login_get_handler).post(admin_auth::admin_login_post_handler))
        .route("/admin/login.php", get(admin_auth::admin_login_get_handler).post(admin_auth::admin_login_post_handler))
        .route("/admin/logout", get(admin_auth::admin_logout_handler).post(admin_auth::admin_logout_handler))
        .route("/admin/logout.php", get(admin_auth::admin_logout_handler).post(admin_auth::admin_logout_handler))

        // Customer Account
        .route("/account", get(account::account_index_handler))
        .route("/account/index.php", get(account::account_index_handler))
        .route("/account/orders", get(account::account_orders_handler))
        .route("/account/orders.php", get(account::account_orders_handler))
        .route("/account/order/:number", get(account::account_order_number_handler).post(account::account_order_post_handler))
        .route("/account/order.php", get(account::account_order_php_handler).post(account::account_order_post_handler))
        .route("/account/invoice/:number", get(account::account_invoice_number_handler))
        .route("/account/invoice.php", get(account::account_invoice_php_handler))
        .route("/account/orders/:number/invoice", get(account::account_invoice_number_handler))
        .route("/account/addresses", get(account::account_addresses_get_handler).post(account::account_addresses_post_handler))
        .route("/account/addresses.php", get(account::account_addresses_get_handler).post(account::account_addresses_post_handler))
        .route("/account/profile", get(account::account_profile_get_handler).post(account::account_profile_post_handler))
        .route("/account/profile.php", get(account::account_profile_get_handler).post(account::account_profile_post_handler))
        .route("/account/security", get(account::account_security_get_handler).post(account::account_security_post_handler))
        .route("/account/security.php", get(account::account_security_get_handler).post(account::account_security_post_handler))
        .route("/account/wishlist", get(account::account_wishlist_get_handler).post(account::account_wishlist_post_handler))
        .route("/account/wishlist.php", get(account::account_wishlist_get_handler).post(account::account_wishlist_post_handler))
        .route("/account/export", get(account::account_export_get_handler).post(account::account_export_post_handler))
        .route("/account/export.php", get(account::account_export_get_handler).post(account::account_export_post_handler))
        .route("/account/delete", get(account::account_delete_get_handler).post(account::account_delete_post_handler))
        .route("/account/delete.php", get(account::account_delete_get_handler).post(account::account_delete_post_handler))

        // API
        .route("/api/cart_ajax.php", get(api::cart_ajax_get_handler).post(api::cart_ajax_post_handler))
        .route("/api/cart", get(api::cart_ajax_get_handler).post(api::cart_ajax_post_handler))
        .route(
            "/api/availability.php",
            get(api::availability_handler).layer(axum_mw::from_fn(availability_rate_limit_middleware)),
        )
        .route(
            "/api/availability",
            get(api::availability_handler).layer(axum_mw::from_fn(availability_rate_limit_middleware)),
        )
        .route("/api/availability_stream.php", get(api::availability_stream_handler))
        .route("/api/search_suggest.php", get(api::search_suggest_handler))
        .route("/api/search/suggest", get(api::search_suggest_handler))
        .route("/api/newsletter.php", post(api::newsletter_api_handler))
        .route("/api/newsletter", post(api::newsletter_api_handler))
        .route("/api/wishlist.php", get(api::wishlist_api_get_handler).post(api::wishlist_api_post_handler))
        .route("/api/wishlist", get(api::wishlist_api_get_handler).post(api::wishlist_api_post_handler))
        .route("/api/review.php", post(api::review_api_handler))
        .route("/api/review", post(api::review_api_handler))

        // Media delivery
        .route("/uploads/*path", get(media::media_handler))
        .route("/assets/uploads/*path", get(media::media_handler))
        .route("/uploads.php", get(media::media_handler))

        // Robots
        .route("/robots.txt", get(storefront::robots_txt_handler))

        // Metrics & Health
        .route("/metrics", get(metrics::metrics_handler))
        .route("/health", get(health_handler))

        // Static files under assets/ with 1-year immutable caching
        .nest_service("/assets", {
            let assets_dir = std::env::var("ASSETS_DIR").unwrap_or_else(|_| {
                if std::path::Path::new("assets").is_dir() {
                    "assets".to_string()
                } else if std::path::Path::new("../../assets").is_dir() {
                    "../../assets".to_string()
                } else {
                    "assets".to_string()
                }
            });
            Router::new()
                .fallback_service(ServeDir::new(assets_dir))
                .layer(tower_http::set_header::SetResponseHeaderLayer::overriding(
                    axum::http::header::CACHE_CONTROL,
                    axum::http::HeaderValue::from_static("public, max-age=31536000, immutable"),
                ))
        })

        // Global Middlewares (applied from bottom to top execution order)
        .layer(tower_http::compression::CompressionLayer::new())
        .layer(axum_mw::from_fn(csrf_middleware))
        .layer(axum_mw::from_fn_with_state(state.clone(), remember_me_middleware))
        .layer(axum_mw::from_fn_with_state(state.clone(), maintenance_middleware))
        .layer(axum_mw::from_fn_with_state(state.clone(), force_https_middleware))
        .layer(axum_mw::from_fn(security_headers_middleware))
        .layer(axum_mw::from_fn_with_state(state.clone(), telemetry_middleware))

        // Error pages fallback (404)
        .fallback(fallback_handler)
        .with_state(state);

    app_router
}

async fn health_handler(axum::extract::State(state): axum::extract::State<AppState>) -> Response {
    let db_ok = sqlx::query("SELECT 1").execute(&state.db).await.is_ok();
    if db_ok {
        (
            StatusCode::OK,
            Json(serde_json::json!({
                "status": "ok",
                "db": "ok"
            })),
        )
            .into_response()
    } else {
        (
            StatusCode::SERVICE_UNAVAILABLE,
            Json(serde_json::json!({
                "status": "error",
                "db": "failed"
            })),
        )
            .into_response()
    }
}

async fn fallback_handler(axum::extract::State(state): axum::extract::State<AppState>) -> Response {
    let ctx = tera::Context::new();
    let body = state
        .tera
        .render("errors/404.html", &ctx)
        .unwrap_or_else(|_| "<h1>404 Not Found</h1>".to_string());

    (StatusCode::NOT_FOUND, Html(body)).into_response()
}
