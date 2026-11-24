use std::collections::HashMap;
use std::sync::Arc;
use chrono::Utc;
use serde::{Deserialize, Serialize};
use sqlx::{MySql, Pool};
use tera::{Context, Tera};
use tower_sessions::Session;

use common::config::Config;
use common::crypto::generate_csrf_token;
use common::money::format_money;
use db::queries::cart::{cart_count, cart_load};
use db::queries::catalog::{get_active_categories, resolve_image_url};
use db::settings::SettingsRepository;
use domain::models::customer::{Customer, CustomerSession};
use jobs::mailer::Mailer;

#[derive(Clone)]
pub struct AppState {
    pub db: Pool<MySql>,
    pub settings: SettingsRepository,
    pub tera: Arc<Tera>,
    pub config: Config,
    pub mailer: Arc<Mailer>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FlashMessage {
    #[serde(rename = "type")]
    pub kind: String,
    pub message: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AdminSession {
    pub id: u64,
    pub name: String,
    pub email: String,
    pub role: String,
    pub permissions: Vec<String>,
    pub avatar: Option<String>,
}

impl AdminSession {
    pub fn has_permission(&self, required: &str) -> bool {
        user_has_permission(&self.role, &self.permissions, &[required])
    }
}

/// Wildcard permission logic identical to PHP user_has_permission()
pub fn user_has_permission(role: &str, permissions: &[String], required: &[&str]) -> bool {
    if role == "admin" {
        return true;
    }
    if permissions.iter().any(|p| p == "*") {
        return true;
    }
    if required.is_empty() {
        return true;
    }
    for req in required {
        if *req == "*" || permissions.iter().any(|p| p == *req) {
            return true;
        }
        if let Some((module, _)) = req.split_once('.') {
            let wild = format!("{}.*", module);
            if permissions.iter().any(|p| p == &wild) {
                return true;
            }
        }
    }
    false
}

/// Session helper functions
pub struct SessionHelper;

impl SessionHelper {
    pub const KEY_CUSTOMER: &'static str = "customer";
    pub const KEY_USER: &'static str = "user";
    pub const KEY_CART: &'static str = "cart";
    pub const KEY_CSRF: &'static str = "_csrf";
    pub const KEY_CSRF_TOKEN: &'static str = "csrf_token";
    pub const KEY_FLASH: &'static str = "_flash";
    pub const KEY_OLD: &'static str = "_old";
    pub const KEY_LAST_PLACED_ORDER: &'static str = "last_placed_order";
    pub const KEY_CHECKOUT_TOKEN: &'static str = "checkout_token";
    pub const KEY_CHECKOUT_TIME: &'static str = "checkout_rendered_at";
    pub const KEY_APPLIED_COUPON: &'static str = "applied_coupon";
    pub const KEY_SHIPPING_METHOD: &'static str = "shipping_method";

    pub async fn get_customer(session: &Session) -> Option<CustomerSession> {
        session.get::<CustomerSession>(Self::KEY_CUSTOMER).await.ok().flatten()
    }

    pub async fn set_customer(session: &Session, customer: &CustomerSession) {
        let _ = session.insert(Self::KEY_CUSTOMER, customer).await;
    }

    pub async fn remove_customer(session: &Session) {
        let _ = session.remove::<CustomerSession>(Self::KEY_CUSTOMER).await;
    }

    pub async fn get_user(session: &Session) -> Option<AdminSession> {
        session.get::<AdminSession>(Self::KEY_USER).await.ok().flatten()
    }

    pub async fn set_user(session: &Session, user: &AdminSession) {
        let _ = session.insert(Self::KEY_USER, user).await;
    }

    pub async fn remove_user(session: &Session) {
        let _ = session.remove::<AdminSession>(Self::KEY_USER).await;
    }

    pub async fn cycle_session_id(session: &Session) {
        let _ = session.cycle_id().await;
    }

    pub async fn get_csrf(session: &Session) -> String {
        if let Ok(Some(token)) = session.get::<String>(Self::KEY_CSRF_TOKEN).await {
            if !token.is_empty() {
                return token;
            }
        }
        if let Ok(Some(token)) = session.get::<String>(Self::KEY_CSRF).await {
            if !token.is_empty() {
                return token;
            }
        }
        let new_token = generate_csrf_token();
        let _ = session.insert(Self::KEY_CSRF_TOKEN, &new_token).await;
        let _ = session.insert(Self::KEY_CSRF, &new_token).await;
        new_token
    }

    pub async fn verify_csrf(session: &Session, token: Option<&str>) -> bool {
        let expected = Self::get_csrf(session).await;
        match token {
            Some(t) => common::crypto::verify_csrf_token(t.trim(), &expected),
            None => false,
        }
    }

    pub async fn add_flash(session: &Session, kind: &str, message: &str) {
        let mut flashes: Vec<FlashMessage> = session
            .get::<Vec<FlashMessage>>(Self::KEY_FLASH)
            .await
            .ok()
            .flatten()
            .unwrap_or_default();
        flashes.push(FlashMessage {
            kind: kind.to_string(),
            message: message.to_string(),
        });
        let _ = session.insert(Self::KEY_FLASH, flashes).await;
    }

    pub async fn take_flashes(session: &Session) -> Vec<FlashMessage> {
        let flashes: Vec<FlashMessage> = session
            .get::<Vec<FlashMessage>>(Self::KEY_FLASH)
            .await
            .ok()
            .flatten()
            .unwrap_or_default();
        let _ = session.remove::<Vec<FlashMessage>>(Self::KEY_FLASH).await;
        flashes
    }

    pub async fn set_old(session: &Session, data: &HashMap<String, String>) {
        let _ = session.insert(Self::KEY_OLD, data).await;
    }

    pub async fn get_old(session: &Session) -> HashMap<String, String> {
        session
            .get::<HashMap<String, String>>(Self::KEY_OLD)
            .await
            .ok()
            .flatten()
            .unwrap_or_default()
    }

    pub async fn take_old(session: &Session) -> HashMap<String, String> {
        let data = Self::get_old(session).await;
        let _ = session.remove::<HashMap<String, String>>(Self::KEY_OLD).await;
        data
    }

    pub async fn set_last_placed_order(session: &Session, order_id: u64) {
        let _ = session.insert(Self::KEY_LAST_PLACED_ORDER, order_id).await;
    }

    pub async fn get_last_placed_order(session: &Session) -> Option<u64> {
        session.get::<u64>(Self::KEY_LAST_PLACED_ORDER).await.ok().flatten()
    }

    pub async fn get_cart(session: &Session) -> HashMap<String, i32> {
        session
            .get::<HashMap<String, i32>>(Self::KEY_CART)
            .await
            .ok()
            .flatten()
            .unwrap_or_default()
    }

    pub async fn set_cart(session: &Session, cart: &HashMap<String, i32>) {
        let _ = session.insert(Self::KEY_CART, cart).await;
    }

    pub async fn clear_cart(session: &Session) {
        let _ = session.remove::<HashMap<String, i32>>(Self::KEY_CART).await;
        let _ = session.remove::<String>(Self::KEY_APPLIED_COUPON).await;
    }
}

/// Helper to build common template context
pub async fn build_base_context(
    state: &AppState,
    session: &Session,
    current_route: &str,
) -> Context {
    let mut ctx = Context::new();

    ctx.insert("current_route", current_route);
    ctx.insert("base_url", &state.config.base_url);
    ctx.insert("site_url", &state.config.app_url);

    let site_name = state.settings.get_or("site_name", "Jinish Potro");
    let site_name = if site_name.is_empty() || site_name == "PHP Admin Panel" || site_name == "Store" {
        "Jinish Potro"
    } else {
        &site_name
    };
    ctx.insert("site_brand", site_name);
    ctx.insert("site_name", site_name);

    let full_title = format!("{} | Quality Essentials", site_name);
    ctx.insert("full_title", &full_title);

    let meta_desc = state.settings.get_or("meta_description", "Shop unique imported products in Bangladesh at Jinishpotro.store.");
    ctx.insert("meta_description", &meta_desc);

    let meta_keywords = state.settings.get_or("meta_keywords", "");
    ctx.insert("meta_keywords", &meta_keywords);

    ctx.insert("robots_allow", &true);

    let favicon_url = state.settings.get("site_favicon").map(|f| resolve_image_url(Some(&f)));
    ctx.insert("favicon_url", &favicon_url);

    let now_ts = Utc::now().timestamp();
    ctx.insert("now_ts", &now_ts);
    ctx.insert("current_year", &Utc::now().format("%Y").to_string());

    let site_logo = state.settings.get("site_logo");
    let logo_url = site_logo.as_deref().map(|l| resolve_image_url(Some(l)));
    ctx.insert("store_logo_url", &logo_url);

    let store_phone = state.settings.get_or("store_phone", "+880 1700-000000");
    ctx.insert("store_phone", &store_phone);

    let store_email = state.settings.get_or("store_email", "support@jinishpotro.store");
    ctx.insert("store_email", &store_email);

    let store_address = state.settings.get_or("store_address", "Dhaka, Bangladesh");
    ctx.insert("store_address", &store_address);

    let announcement_enabled = state.settings.get_or("announcement_enabled", "0") == "1";
    let announcement_text = state.settings.get_or("announcement_bar_text", "");
    ctx.insert("announcement_enabled", &announcement_enabled);
    ctx.insert("announcement_text", &announcement_text);
    ctx.insert("announcement_bar", &announcement_text);

    let theme_accent = state.settings.get_or("theme_accent_color", "#717fe0");
    let dark_mode = state.settings.get_or("theme_dark_mode", "0") == "1";
    ctx.insert("theme_accent", &theme_accent);
    ctx.insert("theme_accent_color", &theme_accent);
    ctx.insert("dark_mode", &dark_mode);

    let currency_sym = state.settings.get_or("currency_symbol", "৳");
    ctx.insert("currency_symbol", &currency_sym);

    let csrf_token = SessionHelper::get_csrf(session).await;
    ctx.insert("csrf_token", &csrf_token);
    ctx.insert("SHOP_CSRF_TOKEN", &csrf_token);
    ctx.insert("SHOP_BASE_URL", &state.config.app_url);
    ctx.insert("SHOP_CHECKOUT_URL", &format!("{}/checkout", state.config.app_url.trim_end_matches('/')));

    let flashes = SessionHelper::take_flashes(session).await;
    ctx.insert("flashes", &flashes);
    ctx.insert("flash_messages", &flashes);
    if let Some(first_flash) = flashes.first() {
        ctx.insert("flash", first_flash);
    }

    let old_inputs = SessionHelper::take_old(session).await;
    ctx.insert("old", &old_inputs);

    if let Some(admin) = SessionHelper::get_user(session).await {
        ctx.insert("user", &admin);
        ctx.insert("admin_user", &admin);
    }

    let customer = SessionHelper::get_customer(session).await;
    let mut wishlist_count: i64 = 0;

    if let Some(ref c) = customer {
        // Fetch fresh customer details if available for avatar/name
        let fresh_cust: Option<Customer> = sqlx::query_as(
            "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1",
        )
        .bind(c.id)
        .fetch_optional(&state.db)
        .await
        .ok()
        .flatten();

        let (avatar_url, initials) = if let Some(ref fc) = fresh_cust {
            let a_url = fc.avatar.as_deref().map(|a| resolve_image_url(Some(a)));
            let init = fc
                .name
                .chars()
                .next()
                .map(|ch| ch.to_uppercase().to_string())
                .unwrap_or_else(|| "C".to_string());
            (a_url, init)
        } else {
            (None, "C".to_string())
        };

        let cust_val = serde_json::json!({
            "id": c.id,
            "name": c.name,
            "email": c.email,
            "phone": c.phone,
            "status": c.status,
            "verified": c.verified,
            "avatar_url": avatar_url,
            "initials": initials,
        });
        ctx.insert("customer", &cust_val);

        let count: Option<i64> = sqlx::query_scalar(
            "SELECT COUNT(*) FROM wishlists WHERE customer_id = ?",
        )
        .bind(c.id)
        .fetch_optional(&state.db)
        .await
        .ok()
        .flatten();
        wishlist_count = count.unwrap_or(0);
    } else {
        ctx.insert("customer", &Option::<serde_json::Value>::None);
    }

    ctx.insert("CUSTOMER_LOGGED_IN", &customer.is_some());
    ctx.insert("logo", &logo_url);
    ctx.insert("wishlist_count", &wishlist_count);

    // Active categories for navigation
    let categories = get_active_categories(&state.db).await.unwrap_or_default();
    ctx.insert("categories", &categories);

    // Cart details
    let cart_raw = SessionHelper::get_cart(session).await;
    let currency_sym = state.settings.get_or("currency_symbol", "৳");
    let loaded_cart = cart_load(&state.db, &cart_raw, &currency_sym)
        .await
        .unwrap_or(db::queries::cart::HydratedCart {
            items: Vec::new(),
            subtotal: rust_decimal::Decimal::ZERO,
            formatted_subtotal: format_money(Some(rust_decimal::Decimal::ZERO), &currency_sym),
            total_count: 0,
            alerts: Vec::new(),
        });

    ctx.insert("cart_count", &cart_count(&loaded_cart));
    ctx.insert("cart_subtotal", &loaded_cart.formatted_subtotal);
    ctx.insert("cart_items", &loaded_cart.items);
    ctx.insert("cart", &loaded_cart);

    ctx
}

/// Helper to build admin layout template context with permissions, notifications, and telemetry health
pub async fn build_admin_context(
    state: &AppState,
    session: &Session,
    current_page: &str,
    page_title: &str,
) -> Context {
    let mut ctx = Context::new();

    let site_name = state.settings.get_or("site_name", "Jinish Potro");
    let site_name = if site_name.is_empty() || site_name == "PHP Admin Panel" || site_name == "Store" {
        "Jinish Potro"
    } else {
        &site_name
    };
    ctx.insert("site_name", site_name);
    ctx.insert("pageTitle", page_title);
    ctx.insert("page_title", page_title);
    ctx.insert("current", current_page);
    ctx.insert("current_page", current_page);
    ctx.insert("base_url", &state.config.app_url);

    let admin = SessionHelper::get_user(session).await.unwrap_or(AdminSession {
        id: 0,
        name: "Admin".to_string(),
        email: "admin@example.com".to_string(),
        role: "admin".to_string(),
        permissions: vec!["*".to_string()],
        avatar: None,
    });

    let avatar_url = admin.avatar.as_deref().map(|a| resolve_image_url(Some(a)));
    let me_val = serde_json::json!({
        "id": admin.id,
        "name": admin.name,
        "email": admin.email,
        "role": admin.role,
        "permissions": admin.permissions,
        "avatar": admin.avatar,
        "avatar_url": avatar_url,
    });
    ctx.insert("me", &me_val);
    ctx.insert("user", &me_val);

    // Permission flags for sidebar
    let can_see_ecommerce = admin.has_permission("analytics.view")
        || admin.has_permission("orders.view")
        || admin.has_permission("orders.manage")
        || admin.has_permission("customers.view")
        || admin.has_permission("customers.manage")
        || admin.has_permission("products.view")
        || admin.has_permission("products.create")
        || admin.has_permission("products.edit")
        || admin.has_permission("inventory.view")
        || admin.has_permission("inventory.manage")
        || admin.has_permission("categories.manage")
        || admin.has_permission("banners.manage")
        || admin.has_permission("reviews.view")
        || admin.has_permission("reviews.manage")
        || admin.role == "admin"
        || admin.role == "editor";

    let can_see_content = admin.has_permission("pages.manage")
        || admin.has_permission("messages.manage")
        || admin.has_permission("subscribers.manage")
        || admin.role == "admin"
        || admin.role == "editor";

    let can_see_config = admin.has_permission("users.view")
        || admin.has_permission("users.manage")
        || admin.has_permission("settings.general")
        || admin.has_permission("settings.storage")
        || admin.has_permission("mail.manage")
        || admin.has_permission("monitoring.view")
        || admin.role == "admin";

    ctx.insert("canSeeEcommerce", &can_see_ecommerce);
    ctx.insert("canSeeContent", &can_see_content);
    ctx.insert("canSeeConfig", &can_see_config);

    // Dynamic notification and health indicators
    let low_stock_thresh = state.settings.get_or("low_stock_threshold", "5").parse::<i64>().unwrap_or(5);
    let notifs = db::queries::admin::get_admin_notifications(&state.db, low_stock_thresh).await;

    ctx.insert("notifPendingOrders", &notifs.pending_orders);
    ctx.insert("notifLowStock", &notifs.low_stock);
    ctx.insert("notifFailedMail", &notifs.failed_mail);
    ctx.insert("rawNotifCount", &notifs.raw_count);
    ctx.insert("serverHealthColor", &notifs.health_color);
    ctx.insert("serverHealthTitle", &notifs.health_title);

    let csrf_token = SessionHelper::get_csrf(session).await;
    ctx.insert("csrf_token", &csrf_token);

    let flashes = SessionHelper::take_flashes(session).await;
    ctx.insert("flashes", &flashes);

    let old = SessionHelper::take_old(session).await;
    ctx.insert("old", &old);

    ctx
}
