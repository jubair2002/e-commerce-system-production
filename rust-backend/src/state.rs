use std::collections::HashMap;
use std::sync::Arc;
use chrono::Utc;
use serde::{Deserialize, Serialize};
use sqlx::{MySql, Pool};
use tera::{Context, Tera};
use tower_sessions::Session;

use crate::config::Config;
use crate::crypto::generate_csrf_token;
use crate::models::customer::{Customer, CustomerSession};
use crate::services::cart::{cart_count, cart_load};
use crate::services::catalog::{get_active_categories, resolve_image_url};
use crate::services::mailer::Mailer;
use crate::settings::SettingsRepository;

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

/// Session helper functions
pub struct SessionHelper;

impl SessionHelper {
    pub const KEY_CUSTOMER: &'static str = "customer";
    pub const KEY_CART: &'static str = "cart";
    pub const KEY_CSRF: &'static str = "_csrf";
    pub const KEY_FLASH: &'static str = "_flash";
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

    pub async fn get_csrf(session: &Session) -> String {
        if let Ok(Some(token)) = session.get::<String>(Self::KEY_CSRF).await {
            if !token.is_empty() {
                return token;
            }
        }
        let new_token = generate_csrf_token();
        let _ = session.insert(Self::KEY_CSRF, &new_token).await;
        new_token
    }

    pub async fn verify_csrf(session: &Session, token: Option<&str>) -> bool {
        let expected = Self::get_csrf(session).await;
        match token {
            Some(t) => !t.is_empty() && t == expected,
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
    ctx.insert("base_url", &state.config.app_url);
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

    let csrf_token = SessionHelper::get_csrf(session).await;
    ctx.insert("csrf_token", &csrf_token);

    let flashes = SessionHelper::take_flashes(session).await;
    ctx.insert("flashes", &flashes);
    if let Some(first_flash) = flashes.first() {
        ctx.insert("flash", first_flash);
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
    }

    ctx.insert("wishlist_count", &wishlist_count);

    // Active categories for navigation
    let categories = get_active_categories(&state.db).await.unwrap_or_default();
    ctx.insert("categories", &categories);

    // Cart details
    let cart_raw = SessionHelper::get_cart(session).await;
    let currency_sym = state.settings.get_or("currency_symbol", "৳");
    let loaded_cart = cart_load(&state.db, &cart_raw, &currency_sym)
        .await
        .unwrap_or(crate::services::cart::HydratedCart {
            items: Vec::new(),
            subtotal: rust_decimal::Decimal::ZERO,
            formatted_subtotal: crate::money::format_money(Some(rust_decimal::Decimal::ZERO), &currency_sym),
            total_count: 0,
            alerts: Vec::new(),
        });

    ctx.insert("cart_count", &cart_count(&loaded_cart));
    ctx.insert("cart_subtotal", &loaded_cart.formatted_subtotal);
    ctx.insert("cart_items", &loaded_cart.items);
    ctx.insert("cart", &loaded_cart);

    ctx
}
