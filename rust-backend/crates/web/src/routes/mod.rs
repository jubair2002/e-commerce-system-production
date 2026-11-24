pub mod account;
pub mod admin;
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
        .route("/admin", get(admin_auth::admin_index_handler))
        .route("/admin/", get(admin_auth::admin_index_handler))
        .route("/admin/index.php", get(admin_auth::admin_index_handler))
        .route("/admin/install", get(admin_auth::admin_install_get_handler).post(admin_auth::admin_install_post_handler))
        .route("/admin/install.php", get(admin_auth::admin_install_get_handler).post(admin_auth::admin_install_post_handler))
        .route("/admin/login", get(admin_auth::admin_login_get_handler).post(admin_auth::admin_login_post_handler))
        .route("/admin/login.php", get(admin_auth::admin_login_get_handler).post(admin_auth::admin_login_post_handler))
        .route("/admin/logout", get(admin_auth::admin_logout_handler).post(admin_auth::admin_logout_handler))
        .route("/admin/logout.php", get(admin_auth::admin_logout_handler).post(admin_auth::admin_logout_handler))
        .route("/admin/dashboard", get(admin_auth::admin_dashboard_handler))
        .route("/admin/dashboard.php", get(admin_auth::admin_dashboard_handler))
        .route("/admin/profile", get(admin_auth::admin_profile_get_handler).post(admin_auth::admin_profile_post_handler))
        .route("/admin/profile.php", get(admin_auth::admin_profile_get_handler).post(admin_auth::admin_profile_post_handler))
        .route("/admin/activity", get(admin_auth::admin_activity_get_handler))
        .route("/admin/activity.php", get(admin_auth::admin_activity_get_handler))

        // Admin Catalog & Inventory (Prompt 11)
        .route("/admin/products", get(admin::catalog::admin_products_get_handler).post(admin::catalog::admin_products_post_handler))
        .route("/admin/products.php", get(admin::catalog::admin_products_get_handler).post(admin::catalog::admin_products_post_handler))
        .route("/admin/product_form", get(admin::catalog::admin_product_form_get_handler).post(admin::catalog::admin_product_form_post_handler))
        .route("/admin/product_form.php", get(admin::catalog::admin_product_form_get_handler).post(admin::catalog::admin_product_form_post_handler))
        .route("/admin/product_import", get(admin::import::admin_product_import_get_handler).post(admin::import::admin_product_import_post_handler))
        .route("/admin/product_import.php", get(admin::import::admin_product_import_get_handler).post(admin::import::admin_product_import_post_handler))
        .route("/admin/categories", get(admin::catalog::admin_categories_get_handler).post(admin::catalog::admin_categories_post_handler))
        .route("/admin/categories.php", get(admin::catalog::admin_categories_get_handler).post(admin::catalog::admin_categories_post_handler))
        .route("/admin/category_form", get(admin::catalog::admin_category_form_get_handler).post(admin::catalog::admin_category_form_post_handler))
        .route("/admin/category_form.php", get(admin::catalog::admin_category_form_get_handler).post(admin::catalog::admin_category_form_post_handler))
        .route("/admin/inventory", get(admin::catalog::admin_inventory_get_handler).post(admin::catalog::admin_inventory_post_handler))
        .route("/admin/inventory.php", get(admin::catalog::admin_inventory_get_handler).post(admin::catalog::admin_inventory_post_handler))

        // Admin Orders, Customers, Marketing & Content (Prompt 12)
        .route("/admin/orders", get(admin::orders::admin_orders_get_handler).post(admin::orders::admin_orders_post_handler))
        .route("/admin/orders.php", get(admin::orders::admin_orders_get_handler).post(admin::orders::admin_orders_post_handler))
        .route("/admin/order_view", get(admin::orders::admin_order_view_get_handler).post(admin::orders::admin_order_view_post_handler))
        .route("/admin/order_view.php", get(admin::orders::admin_order_view_get_handler).post(admin::orders::admin_order_view_post_handler))
        .route("/admin/order_print", get(admin::orders::admin_order_print_handler))
        .route("/admin/order_print.php", get(admin::orders::admin_order_print_handler))
        .route("/admin/customers", get(admin::customers::admin_customers_get_handler).post(admin::customers::admin_customers_post_handler))
        .route("/admin/customers.php", get(admin::customers::admin_customers_get_handler).post(admin::customers::admin_customers_post_handler))
        .route("/admin/customer_view", get(admin::customers::admin_customer_view_get_handler).post(admin::customers::admin_customer_view_post_handler))
        .route("/admin/customer_view.php", get(admin::customers::admin_customer_view_get_handler).post(admin::customers::admin_customer_view_post_handler))
        .route("/admin/reviews", get(admin::marketing::admin_reviews_get_handler).post(admin::marketing::admin_reviews_post_handler))
        .route("/admin/reviews.php", get(admin::marketing::admin_reviews_get_handler).post(admin::marketing::admin_reviews_post_handler))
        .route("/admin/messages", get(admin::marketing::admin_messages_get_handler).post(admin::marketing::admin_messages_post_handler))
        .route("/admin/messages.php", get(admin::marketing::admin_messages_get_handler).post(admin::marketing::admin_messages_post_handler))
        .route("/admin/subscribers", get(admin::marketing::admin_subscribers_get_handler).post(admin::marketing::admin_subscribers_post_handler))
        .route("/admin/subscribers.php", get(admin::marketing::admin_subscribers_get_handler).post(admin::marketing::admin_subscribers_post_handler))
        .route("/admin/banners", get(admin::marketing::admin_banners_get_handler).post(admin::marketing::admin_banners_post_handler))
        .route("/admin/banners.php", get(admin::marketing::admin_banners_get_handler).post(admin::marketing::admin_banners_post_handler))
        .route("/admin/banner_form", get(admin::marketing::admin_banner_form_get_handler).post(admin::marketing::admin_banner_form_post_handler))
        .route("/admin/banner_form.php", get(admin::marketing::admin_banner_form_get_handler).post(admin::marketing::admin_banner_form_post_handler))
        .route("/admin/pages", get(admin::marketing::admin_pages_get_handler).post(admin::marketing::admin_pages_post_handler))
        .route("/admin/pages.php", get(admin::marketing::admin_pages_get_handler).post(admin::marketing::admin_pages_post_handler))
        .route("/admin/page_form", get(admin::marketing::admin_page_form_get_handler).post(admin::marketing::admin_page_form_post_handler))
        .route("/admin/page_form.php", get(admin::marketing::admin_page_form_get_handler).post(admin::marketing::admin_page_form_post_handler))
        .route("/admin/users", get(admin::marketing::admin_users_get_handler).post(admin::marketing::admin_users_post_handler))
        .route("/admin/users.php", get(admin::marketing::admin_users_get_handler).post(admin::marketing::admin_users_post_handler))
        .route("/admin/user_form", get(admin::marketing::admin_user_form_get_handler).post(admin::marketing::admin_user_form_post_handler))
        .route("/admin/user_form.php", get(admin::marketing::admin_user_form_get_handler).post(admin::marketing::admin_user_form_post_handler))

        // Admin Settings, Mail, Monitoring & Analytics (Prompt 13)
        .route("/admin/settings", get(admin::settings::admin_settings_get_handler).post(admin::settings::admin_settings_post_handler))
        .route("/admin/settings.php", get(admin::settings::admin_settings_get_handler).post(admin::settings::admin_settings_post_handler))
        .route("/admin/mail_settings", get(admin::settings::admin_mail_settings_get_handler).post(admin::settings::admin_mail_settings_post_handler))
        .route("/admin/mail_settings.php", get(admin::settings::admin_mail_settings_get_handler).post(admin::settings::admin_mail_settings_post_handler))
        .route("/admin/mail_templates", get(admin::settings::admin_mail_templates_get_handler).post(admin::settings::admin_mail_templates_post_handler))
        .route("/admin/mail_templates.php", get(admin::settings::admin_mail_templates_get_handler).post(admin::settings::admin_mail_templates_post_handler))
        .route("/admin/mail_queue", get(admin::settings::admin_mail_queue_get_handler).post(admin::settings::admin_mail_queue_post_handler))
        .route("/admin/mail_queue.php", get(admin::settings::admin_mail_queue_get_handler).post(admin::settings::admin_mail_queue_post_handler))
        .route("/admin/monitoring", get(admin::settings::admin_monitoring_handler).post(admin::settings::admin_monitoring_post_handler))
        .route("/admin/monitoring.php", get(admin::settings::admin_monitoring_handler).post(admin::settings::admin_monitoring_post_handler))
        .route("/admin/analytics", get(admin::settings::admin_analytics_handler).post(admin::settings::admin_analytics_post_handler))
        .route("/admin/analytics.php", get(admin::settings::admin_analytics_handler).post(admin::settings::admin_analytics_post_handler))
        .route("/admin/analytics_product", get(admin::settings::admin_analytics_product_handler))
        .route("/admin/analytics_product.php", get(admin::settings::admin_analytics_product_handler))

        // Admin AJAX Endpoints
        .route("/admin/ajax/stock_update.php", get(admin::ajax::admin_ajax_stock_update_get_handler).post(admin::ajax::admin_ajax_stock_update_post_handler))
        .route("/admin/ajax/stock_update", get(admin::ajax::admin_ajax_stock_update_get_handler).post(admin::ajax::admin_ajax_stock_update_post_handler))
        .route("/admin/ajax/option_presets.php", get(admin::ajax::admin_ajax_option_presets_handler))
        .route("/admin/ajax/option_presets", get(admin::ajax::admin_ajax_option_presets_handler))
        .route("/admin/ajax/monitor_data.php", get(admin::ajax::admin_ajax_monitor_data_handler))
        .route("/admin/ajax/monitor_data", get(admin::ajax::admin_ajax_monitor_data_handler))
        .route("/admin/ajax/analytics_data.php", get(admin::ajax::admin_ajax_analytics_data_handler))
        .route("/admin/ajax/analytics_data", get(admin::ajax::admin_ajax_analytics_data_handler))

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
        .route(
            "/assets/coza/images/product-placeholder.svg",
            get(|| async {
                (
                    [
                        (axum::http::header::CONTENT_TYPE, "image/svg+xml"),
                        (axum::http::header::CACHE_CONTROL, "public, max-age=31536000, immutable"),
                    ],
                    r##"<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 250" width="200" height="250"><rect width="200" height="250" fill="#f4f4f5"/><path d="M70 105 L130 105 L125 160 L75 160 Z" fill="none" stroke="#d4d4d8" stroke-width="2.5" stroke-linejoin="round"/><path d="M85 105 C85 90 115 90 115 105" fill="none" stroke="#d4d4d8" stroke-width="2.5"/></svg>"##,
                )
            }),
        )

        // Legacy storefront subpaths (exact PHP parity when pretty_urls = 0)
        .route("/storefront/shop/index.php", get(storefront::shop_handler))
        .route("/storefront/shop/category.php", get(storefront::category_php_handler))
        .route("/storefront/shop/product.php", get(storefront::product_php_handler))
        .route("/storefront/cart/index.php", get(storefront::cart_get_handler).post(storefront::cart_post_handler))
        .route("/storefront/cart/checkout.php", get(storefront::checkout_get_handler).post(storefront::checkout_post_handler))
        .route("/storefront/cart/confirmation.php", get(storefront::order_confirmation_handler))
        .route("/storefront/track/index.php", get(storefront::track_get_handler).post(storefront::track_post_handler))
        .route("/storefront/auth/login.php", get(auth::login_get_handler).post(auth::login_post_handler))
        .route("/storefront/auth/register.php", get(auth::register_get_handler).post(auth::register_post_handler))
        .route("/storefront/auth/logout.php", get(auth::logout_handler).post(auth::logout_handler))
        .route("/storefront/auth/forgot-password.php", get(auth::forgot_password_get_handler).post(auth::forgot_password_post_handler))
        .route("/storefront/auth/reset-password.php", get(auth::reset_password_get_handler).post(auth::reset_password_post_handler))
        .route("/storefront/auth/verify-email.php", get(auth::verify_email_handler))
        .route("/storefront/auth/resend-verification.php", get(auth::resend_verification_get_handler).post(auth::resend_verification_post_handler))
        .route("/storefront/pages/view.php", get(storefront::page_handler))
        .route("/storefront/pages/contact.php", get(storefront::contact_get_handler).post(storefront::contact_post_handler))

        // Maintenance
        .route("/maintenance", get(storefront::maintenance_get_handler))
        .route("/storefront/errors/maintenance.php", get(storefront::maintenance_get_handler))

        // Robots
        .route("/robots.txt", get(storefront::robots_txt_handler))

        // Metrics & Health
        .route("/metrics", get(metrics::metrics_handler))
        .route("/health", get(health_handler))

        // Static files under assets/ with 1-year immutable caching
        .nest_service("/assets", {
            let assets_dir = std::env::var("ASSETS_DIR").unwrap_or_else(|_| {
                for candidate in &["assets", "../assets", "../../assets", "rust-backend/assets"] {
                    if std::path::Path::new(candidate).is_dir() {
                        return candidate.to_string();
                    }
                }
                "assets".to_string()
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
