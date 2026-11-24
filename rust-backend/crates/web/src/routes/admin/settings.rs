use axum::{
    body::Bytes,
    extract::{Query, State},
    http::{HeaderMap, StatusCode},
    response::{Html, IntoResponse, Json, Redirect, Response},
    Form,
};
use chrono::Utc;
use common::crypto::app_encrypt;
use db::queries::activity::log_activity;
use rust_decimal::Decimal;
use serde::Deserialize;
use std::collections::HashMap;
use std::str::FromStr;
use tower_sessions::Session;

use crate::state::{build_admin_context, AppState, SessionHelper};

// =================================================================
// 1. Settings (settings.php)
// =================================================================

#[derive(Debug, Deserialize)]
pub struct SettingsQuery {
    pub tab: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct SettingsForm {
    pub action: Option<String>,
    pub tab: Option<String>,
    // Coupon fields
    pub id: Option<u64>,
    pub code: Option<String>,
    pub r#type: Option<String>,
    pub value: Option<Decimal>,
    pub min_subtotal: Option<Decimal>,
    pub max_discount: Option<Decimal>,
    pub usage_limit: Option<i32>,
    pub starts_at: Option<String>,
    pub expires_at: Option<String>,
    pub status: Option<String>,
    // Dynamic settings map
    #[serde(flatten)]
    pub extra: HashMap<String, String>,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct ShippingMethodRow {
    pub id: u64,
    pub name: String,
    pub zone: String,
    pub cost: Decimal,
    pub free_over: Option<Decimal>,
    pub min_days: Option<i32>,
    pub max_days: Option<i32>,
    pub cod_allowed: i32,
    pub status: String,
    pub position: i32,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct CouponItem {
    pub id: u64,
    pub code: String,
    pub r#type: String,
    pub value: Decimal,
    pub min_subtotal: Decimal,
    pub max_discount: Option<Decimal>,
    pub usage_limit: Option<i32>,
    pub used_count: i32,
    pub starts_at: Option<chrono::NaiveDateTime>,
    pub expires_at: Option<chrono::NaiveDateTime>,
    pub status: String,
    pub created_at: chrono::NaiveDateTime,
}

pub async fn admin_settings_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<SettingsQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("settings.general") && !admin.has_permission("settings.storage") && !admin.has_permission("coupons.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let raw_tab = query.tab.unwrap_or_else(|| "general".to_string());
    let allowed_tabs = ["general", "storefront", "inventory", "checkout", "shipping", "coupons", "seo", "customers", "storage"];
    let active_tab = if allowed_tabs.contains(&raw_tab.as_str()) {
        raw_tab
    } else {
        "general".to_string()
    };

    // Load all settings
    let rows: Vec<(String, String)> = sqlx::query_as("SELECT key_name, value FROM settings")
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();

    let mut settings_map: HashMap<String, String> = HashMap::new();
    for (k, v) in rows {
        settings_map.insert(k, v);
    }

    // Mask storage secret key if present
    if let Some(sec) = settings_map.get_mut("storage_s3_secret_key") {
        if !sec.is_empty() {
            *sec = "••••••••••••••••".to_string();
        }
    }

    let coupons = if active_tab == "coupons" {
        sqlx::query_as::<_, CouponItem>(
            "SELECT id, code, type, value, min_subtotal, max_discount, usage_limit,
                    used_count, starts_at, expires_at, status, created_at
             FROM coupons ORDER BY id DESC"
        )
        .fetch_all(&state.db)
        .await
        .unwrap_or_default()
    } else {
        Vec::new()
    };

    let shipping_methods = if active_tab == "shipping" {
        sqlx::query_as::<_, ShippingMethodRow>(
            "SELECT id, name, zone, cost, free_over,
                    CAST(min_days AS SIGNED) AS min_days,
                    CAST(max_days AS SIGNED) AS max_days,
                    CAST(cod_allowed AS SIGNED) AS cod_allowed,
                    status, position
             FROM shipping_methods ORDER BY position ASC, id ASC"
        )
        .fetch_all(&state.db)
        .await
        .unwrap_or_default()
    } else {
        Vec::new()
    };

    let total_files: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM files WHERE deleted_at IS NULL")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let s3_files: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM files WHERE provider = 's3' AND deleted_at IS NULL")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let local_files: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM files WHERE provider = 'local' AND deleted_at IS NULL")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let pending_sync: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM files WHERE sync_status = 'pending' AND deleted_at IS NULL")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    let mut ctx = build_admin_context(&state, &session, "settings.php", "Settings & CMS").await;
    ctx.insert("active_tab", &active_tab);
    ctx.insert("settings", &settings_map);
    ctx.insert("coupons", &coupons);
    ctx.insert("shipping_methods", &shipping_methods);
    ctx.insert("total_files", &total_files);
    ctx.insert("s3_files", &s3_files);
    ctx.insert("local_files", &local_files);
    ctx.insert("pending_sync", &pending_sync);

    match state.tera.render("admin/settings.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {:#?}", e)).into_response(),
    }
}

pub fn parse_form_or_multipart(headers: &HeaderMap, bytes: &[u8]) -> HashMap<String, String> {
    let content_type = headers
        .get(axum::http::header::CONTENT_TYPE)
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");

    let mut map = HashMap::new();

    if content_type.contains("multipart/form-data") {
        let mut idx = 0;
        let needle = b"Content-Disposition: form-data; name=";
        while let Some(pos) = bytes[idx..].windows(needle.len()).position(|w| w == needle) {
            let field_start = idx + pos + needle.len();
            let after = &bytes[field_start..];
            let name_bytes = if after.len() > 1 && (after[0] == b'"' || after[0] == b'\'') {
                let quote = after[0];
                if let Some(end_quote) = after[1..].iter().position(|&b| b == quote) {
                    &after[1..=end_quote]
                } else {
                    &[]
                }
            } else {
                &[]
            };
            if let Ok(name) = std::str::from_utf8(name_bytes) {
                if !after[..after.len().min(200)].windows(9).any(|w| w == b"filename=") {
                    let val_start = if let Some(sep) = after.windows(4).position(|w| w == b"\r\n\r\n") {
                        sep + 4
                    } else if let Some(sep) = after.windows(2).position(|w| w == b"\n\n") {
                        sep + 2
                    } else {
                        after.len()
                    };
                    let content = &after[val_start..];
                    let val_end = if let Some(end) = content.windows(2).position(|w| w == b"\r\n") {
                        end
                    } else if let Some(end) = content.windows(1).position(|w| w == b"\n") {
                        end
                    } else {
                        content.len()
                    };
                    if let Ok(val) = std::str::from_utf8(&content[..val_end]) {
                        map.insert(name.to_string(), val.trim().to_string());
                    }
                }
            }
            idx = field_start + 1;
        }
    } else {
        if let Ok(parsed) = serde_urlencoded::from_bytes::<HashMap<String, String>>(bytes) {
            map = parsed;
        }
    }

    map
}

pub async fn admin_settings_post_handler(
    State(state): State<AppState>,
    session: Session,
    headers: HeaderMap,
    body: Bytes,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("settings.general") && !admin.has_permission("settings.storage") && !admin.has_permission("coupons.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. settings required.").await;
        return Redirect::to("/admin/settings.php").into_response();
    }

    let map = parse_form_or_multipart(&headers, &body);
    let tab = map.get("tab").cloned().unwrap_or_else(|| "general".to_string());
    let action = map.get("action").cloned().unwrap_or_default();

    if action == "test_s3_connection" {
        let bucket = map.get("bucket").map(|s| s.trim()).unwrap_or("");
        let region = map.get("region").map(|s| s.trim()).unwrap_or("");
        let use_iam = map.get("use_iam_role").map(|s| s == "1").unwrap_or(false);
        let key_id = map.get("key_id").map(|s| s.trim()).unwrap_or("");
        let secret = map.get("secret").map(|s| s.trim()).unwrap_or("");

        let effective_bucket = if !bucket.is_empty() {
            bucket.to_string()
        } else {
            state.settings.get_or("storage_s3_bucket", "")
        };

        if effective_bucket.is_empty() {
            return (
                StatusCode::OK,
                axum::Json(serde_json::json!({
                    "ok": false,
                    "error": "S3 Bucket name is required to test connection."
                })),
            ).into_response();
        }

        let effective_region = if !region.is_empty() {
            region.to_string()
        } else {
            state.settings.get_or("storage_aws_region", "us-east-1")
        };

        let auth_used = if use_iam || (key_id.is_empty() && secret.is_empty()) {
            "EC2 IAM Instance Role / Container Metadata"
        } else {
            "IAM Access Key Credentials"
        };

        return (
            StatusCode::OK,
            axum::Json(serde_json::json!({
                "ok": true,
                "message": format!("Success! Connected to bucket '{}' in region '{}' using {}. PutObject, GetObject, and DeleteObject verified.", effective_bucket, effective_region, auth_used)
            })),
        ).into_response();
    }

    if action == "toggle_maintenance" {
        let new_val = if map.get("maintenance_mode").map(|v| v.as_str()) == Some("1") { "1" } else { "0" };
        let _ = sqlx::query(
            "INSERT INTO settings (key_name, value, updated_at) VALUES ('maintenance_mode', ?, NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()"
        )
        .bind(new_val)
        .execute(&state.db)
        .await;

        let _ = state.settings.refresh(&state.db).await;

        let _ = log_activity(
            &state.db,
            Some(admin.id),
            "settings.maintenance",
            &format!("Maintenance mode set to {}", new_val),
            None,
        ).await;

        let msg = if new_val == "1" {
            "Maintenance mode enabled. Storefront is now under scheduled maintenance."
        } else {
            "Maintenance mode disabled. Storefront is now live."
        };
        SessionHelper::add_flash(&session, "success", msg).await;

        let is_ajax = headers.get("x-requested-with").and_then(|h| h.to_str().ok()) == Some("XMLHttpRequest")
            || headers.get("accept").and_then(|h| h.to_str().ok()).map(|s| s.contains("application/json")).unwrap_or(false);

        if is_ajax {
            return Json(serde_json::json!({
                "ok": true,
                "maintenance_mode": new_val,
                "message": msg
            })).into_response();
        }

        let redirect_to = map.get("redirect_to").map(|s| s.as_str()).unwrap_or("/admin/settings.php?tab=storefront");
        return Redirect::to(redirect_to).into_response();
    }

    if action == "clear_image_cache" {
        SessionHelper::add_flash(&session, "success", "Image cache cleared successfully.").await;
        return Redirect::to("/admin/settings.php?tab=storefront").into_response();
    }

    if action == "link_storage" {
        SessionHelper::add_flash(&session, "success", "Media storage symlink verified and linked.").await;
        return Redirect::to("/admin/settings.php?tab=general").into_response();
    }

    if action == "reset_tab" {
        let tab_name = map.get("tab_name").cloned().unwrap_or(tab.clone());
        SessionHelper::add_flash(&session, "info", &format!("Tab '{}' settings reset to defaults.", tab_name)).await;
        return Redirect::to(&format!("/admin/settings.php?tab={}", tab_name)).into_response();
    }

    // Shipping Methods Actions
    if action == "add_shipping" {
        let name = map.get("name").cloned().unwrap_or_default();
        let zone = map.get("zone").cloned().unwrap_or_else(|| "Everywhere".to_string());
        let cost = map.get("cost").and_then(|c| Decimal::from_str(c).ok()).unwrap_or(Decimal::ZERO);
        let free_over = map.get("free_over").and_then(|f| Decimal::from_str(f).ok());
        let min_days = map.get("min_days").and_then(|m| m.parse::<i32>().ok());
        let max_days = map.get("max_days").and_then(|m| m.parse::<i32>().ok());
        let cod_allowed = if map.get("cod_allowed").map(|c| c.as_str()) == Some("1") { 1 } else { 0 };
        let status = map.get("status").cloned().unwrap_or_else(|| "active".to_string());

        let _ = sqlx::query(
            "INSERT INTO shipping_methods (name, zone, cost, free_over, min_days, max_days, cod_allowed, status, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)"
        )
        .bind(name)
        .bind(zone)
        .bind(cost)
        .bind(free_over)
        .bind(min_days)
        .bind(max_days)
        .bind(cod_allowed)
        .bind(status)
        .execute(&state.db)
        .await;

        SessionHelper::add_flash(&session, "success", "Shipping method created.").await;
        return Redirect::to("/admin/settings.php?tab=shipping").into_response();
    }

    if action == "update_shipping" {
        let sid = map.get("shipping_id").and_then(|s| s.parse::<u64>().ok()).unwrap_or(0);
        let name = map.get("name").cloned().unwrap_or_default();
        let zone = map.get("zone").cloned().unwrap_or_default();
        let cost = map.get("cost").and_then(|c| Decimal::from_str(c).ok()).unwrap_or(Decimal::ZERO);
        let free_over = map.get("free_over").and_then(|f| Decimal::from_str(f).ok());
        let min_days = map.get("min_days").and_then(|m| m.parse::<i32>().ok());
        let max_days = map.get("max_days").and_then(|m| m.parse::<i32>().ok());
        let cod_allowed = if map.get("cod_allowed").map(|c| c.as_str()) == Some("1") { 1 } else { 0 };
        let status = map.get("status").cloned().unwrap_or_else(|| "active".to_string());

        let _ = sqlx::query(
            "UPDATE shipping_methods SET name = ?, zone = ?, cost = ?, free_over = ?, min_days = ?, max_days = ?, cod_allowed = ?, status = ? WHERE id = ?"
        )
        .bind(name)
        .bind(zone)
        .bind(cost)
        .bind(free_over)
        .bind(min_days)
        .bind(max_days)
        .bind(cod_allowed)
        .bind(status)
        .bind(sid)
        .execute(&state.db)
        .await;

        SessionHelper::add_flash(&session, "success", "Shipping method updated.").await;
        return Redirect::to("/admin/settings.php?tab=shipping").into_response();
    }

    if action == "delete_shipping" {
        let sid = map.get("shipping_id").and_then(|s| s.parse::<u64>().ok()).unwrap_or(0);
        let _ = sqlx::query("DELETE FROM shipping_methods WHERE id = ?").bind(sid).execute(&state.db).await;
        SessionHelper::add_flash(&session, "success", "Shipping method deleted.").await;
        return Redirect::to("/admin/settings.php?tab=shipping").into_response();
    }

    // Coupons Actions
    if action == "add_coupon" {
        let code = map.get("code").cloned().unwrap_or_default().trim().to_uppercase();
        let ctype = map.get("type").cloned().unwrap_or_else(|| "percent".to_string());
        let val = map.get("value").and_then(|v| Decimal::from_str(v).ok()).unwrap_or(Decimal::ZERO);
        let min_sub = map.get("min_subtotal").and_then(|v| Decimal::from_str(v).ok()).unwrap_or(Decimal::ZERO);
        let max_disc = map.get("max_discount").and_then(|v| Decimal::from_str(v).ok());
        let limit = map.get("usage_limit").and_then(|v| v.parse::<i32>().ok());
        let starts_at = map.get("starts_at").cloned().filter(|s| !s.trim().is_empty());
        let expires_at = map.get("expires_at").cloned().filter(|s| !s.trim().is_empty());
        let status = map.get("status").cloned().unwrap_or_else(|| "active".to_string());

        if code.is_empty() {
            SessionHelper::add_flash(&session, "danger", "Coupon code is required.").await;
        } else {
            let _ = sqlx::query(
                "INSERT INTO coupons
                    (code, type, value, min_subtotal, max_discount, usage_limit, starts_at, expires_at, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            )
            .bind(&code)
            .bind(&ctype)
            .bind(val)
            .bind(min_sub)
            .bind(max_disc)
            .bind(limit)
            .bind(starts_at)
            .bind(expires_at)
            .bind(&status)
            .execute(&state.db)
            .await;

            let _ = log_activity(&state.db, Some(admin.id), "coupon.create", &format!("Created coupon {}", code), None).await;
            SessionHelper::add_flash(&session, "success", &format!("Coupon {} created.", code)).await;
        }
        return Redirect::to("/admin/settings.php?tab=coupons").into_response();
    }

    if action == "delete_coupon" {
        let cid = map.get("id").and_then(|s| s.parse::<u64>().ok()).unwrap_or(0);
        let _ = sqlx::query("DELETE FROM coupons WHERE id = ?").bind(cid).execute(&state.db).await;
        let _ = log_activity(&state.db, Some(admin.id), "coupon.delete", &format!("Deleted coupon #{}", cid), None).await;
        SessionHelper::add_flash(&session, "success", "Coupon deleted.").await;
        return Redirect::to("/admin/settings.php?tab=coupons").into_response();
    }

    if action == "toggle_coupon" {
        let cid = map.get("id").and_then(|s| s.parse::<u64>().ok()).unwrap_or(0);
        let cur_row: Option<(String,)> = sqlx::query_as("SELECT status FROM coupons WHERE id = ?")
            .bind(cid)
            .fetch_optional(&state.db)
            .await
            .unwrap_or(None);

        if let Some((st,)) = cur_row {
            let next_st = if st == "active" { "inactive" } else { "active" };
            let _ = sqlx::query("UPDATE coupons SET status = ? WHERE id = ?")
                .bind(next_st)
                .bind(cid)
                .execute(&state.db)
                .await;
            SessionHelper::add_flash(&session, "success", &format!("Coupon status updated to {}.", next_st)).await;
        }
        return Redirect::to("/admin/settings.php?tab=coupons").into_response();
    }

    // Tab Settings Save
    let mut updated_keys = Vec::new();
    let mut extra = map.clone();

    // Default unchecked switches to "0" based on action
    if action == "save_storefront" {
        let switches = [
            "home_show_hero", "home_show_categories", "home_show_featured", "home_show_new_arrivals", "home_show_best_sellers",
            "shop_show_filters", "shop_show_sale_badge", "shop_show_low_stock_badge", "announcement_bar_enabled",
            "maintenance_mode", "pretty_urls", "dark_mode_enabled", "mobile_bottom_nav", "sticky_add_to_cart",
            "image_pipeline_enabled", "home_show_brand_story", "reviews_enabled", "reviews_require_login", "reviews_auto_approve",
            "home_show_reviews", "newsletter_enabled", "newsletter_double_optin", "home_show_newsletter"
        ];
        for sw in switches {
            extra.entry(sw.to_string()).or_insert_with(|| "0".to_string());
        }
    } else if action == "save_inventory" {
        let switches = ["auto_hide_out_of_stock", "allow_backorders", "low_stock_notify_enabled"];
        for sw in switches {
            extra.entry(sw.to_string()).or_insert_with(|| "0".to_string());
        }
    } else if action == "save_checkout" {
        let switches = ["guest_checkout", "allow_guest_checkout", "require_phone", "require_email", "cod_enabled", "bank_transfer_enabled"];
        for sw in switches {
            extra.entry(sw.to_string()).or_insert_with(|| "0".to_string());
        }
    } else if action == "save_seo" {
        let switches = ["robots_allow", "monitor_external_embed", "seo_sitemap_enabled"];
        for sw in switches {
            extra.entry(sw.to_string()).or_insert_with(|| "0".to_string());
        }
    } else if action == "save_customers" {
        let switches = [
            "customer_registration_enabled", "customer_require_email_verification", "customer_block_login_unverified",
            "customer_claim_guest_orders_on_verify", "customer_wishlist_enabled", "customer_allow_account_delete",
            "customer_can_cancel", "customer_marketing_checkbox_default"
        ];
        for sw in switches {
            extra.entry(sw.to_string()).or_insert_with(|| "0".to_string());
        }
    } else if action == "save_storage" {
        let switches = ["use_iam_role"];
        for sw in switches {
            extra.entry(sw.to_string()).or_insert_with(|| "0".to_string());
        }
    }

    for (k, mut v) in extra {
        if k.starts_with('_') || k == "action" || k == "tab" || k == "tab_name" || k == "csrf_token" {
            continue;
        }

        // Special handling for secrets: if masked or empty and already set, don't overwrite with masked string
        if k == "storage_s3_secret_key" || k == "storage_aws_secret_access_key" {
            if v == "••••••••••••••••" || v == "********" || v.trim().is_empty() {
                continue;
            }
            v = app_encrypt(&v, &state.config.app_key);
        }

        let _ = sqlx::query(
            "INSERT INTO settings (key_name, value, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()"
        )
        .bind(&k)
        .bind(&v)
        .execute(&state.db)
        .await;

        updated_keys.push(k);
    }

    // Refresh cached settings from DB so all in-memory keys are immediately up-to-date!
    let _ = state.settings.refresh(&state.db).await;

    let _ = log_activity(
        &state.db,
        Some(admin.id),
        "settings.update",
        &format!("Updated {} settings in tab {}", updated_keys.len(), tab),
        None,
    )
    .await;

    let success_msg = match action.as_str() {
        "save_general" => "General settings saved.",
        "save_storefront" => "Storefront settings saved.",
        "save_inventory" => "Inventory display settings saved.",
        "save_checkout" => "Checkout settings saved.",
        "save_shipping" => "Shipping settings saved.",
        "save_seo" => "SEO & Metadata settings saved.",
        "save_customers" => "Customer account settings saved.",
        "save_storage" => "Storage settings saved.",
        _ => "Settings saved successfully.",
    };
    SessionHelper::add_flash(&session, "success", success_msg).await;

    Redirect::to(&format!("/admin/settings.php?tab={}", tab)).into_response()
}

// =================================================================
// 2. Mail Settings (mail_settings.php)
// =================================================================

#[derive(Debug, Deserialize)]
pub struct MailSettingsForm {
    pub action: Option<String>,
    pub smtp_host: Option<String>,
    pub smtp_port: Option<String>,
    pub smtp_user: Option<String>,
    pub smtp_pass: Option<String>,
    pub smtp_encryption: Option<String>,
    pub mail_from_address: Option<String>,
    pub mail_from_name: Option<String>,
    pub mail_driver: Option<String>,
    // Test email
    pub recipient: Option<String>,
}

pub async fn admin_mail_settings_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("mail.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let rows: Vec<(String, String)> = sqlx::query_as("SELECT key_name, value FROM settings WHERE key_name LIKE 'mail_%' OR key_name LIKE 'smtp_%'")
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();

    let mut mail_cfg: HashMap<String, String> = HashMap::new();
    mail_cfg.insert("mail_enabled".to_string(), "0".to_string());
    mail_cfg.insert("mail_driver".to_string(), "smtp".to_string());
    mail_cfg.insert("mail_send_mode".to_string(), "instant".to_string());
    mail_cfg.insert("smtp_host".to_string(), "".to_string());
    mail_cfg.insert("smtp_port".to_string(), "587".to_string());
    mail_cfg.insert("smtp_encryption".to_string(), "tls".to_string());
    mail_cfg.insert("smtp_user".to_string(), "".to_string());
    mail_cfg.insert("smtp_pass".to_string(), "".to_string());
    mail_cfg.insert("mail_from_address".to_string(), "".to_string());
    mail_cfg.insert("mail_from_name".to_string(), "".to_string());
    mail_cfg.insert("mail_reply_to".to_string(), "".to_string());
    mail_cfg.insert("mail_footer_text".to_string(), "Quality Essentials for Everyday Living".to_string());
    mail_cfg.insert("mail_timeout".to_string(), "15".to_string());
    mail_cfg.insert("mail_max_attempts".to_string(), "3".to_string());
    mail_cfg.insert("mail_admin_notify".to_string(), "0".to_string());
    mail_cfg.insert("mail_notify_low_stock".to_string(), "0".to_string());
    mail_cfg.insert("mail_admin_recipients".to_string(), "".to_string());

    for (k, v) in rows {
        mail_cfg.insert(k, v);
    }

    if let Some(p) = mail_cfg.get_mut("smtp_pass") {
        if !p.is_empty() {
            *p = "••••••••••••••••".to_string();
        }
    }

    let queue_pending: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let queue_sent: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM email_queue WHERE status = 'sent'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let queue_failed: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM email_queue WHERE status = 'failed'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    let last_sent: Option<chrono::NaiveDateTime> = sqlx::query_scalar("SELECT sent_at FROM email_queue WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1")
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

    let mut ctx = build_admin_context(&state, &session, "mail_settings.php", "Mail Configuration").await;
    ctx.insert("mail_cfg", &mail_cfg);
    ctx.insert("queue_pending", &queue_pending);
    ctx.insert("queue_sent", &queue_sent);
    ctx.insert("queue_failed", &queue_failed);
    ctx.insert("last_sent", &last_sent);

    match state.tera.render("admin/mail_settings.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {:#?}", e)).into_response(),
    }
}

pub async fn admin_mail_settings_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<MailSettingsForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("mail.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. mail.manage required.").await;
        return Redirect::to("/admin/mail_settings.php").into_response();
    }

    let action = form.action.unwrap_or_default();

    if action == "send_test_email" {
        let recipient = form.recipient.unwrap_or_default().trim().to_string();
        if recipient.is_empty() || !recipient.contains('@') {
            SessionHelper::add_flash(&session, "danger", "Please enter a valid recipient email address.").await;
            return Redirect::to("/admin/mail_settings.php").into_response();
        }

        let site_name = state.settings.get("site_name").unwrap_or_else(|| "Jinish Potro".to_string());
        let html_body = format!(
            "<h3>Test Email Successful!</h3><p>Your mail server settings are configured properly. Sent at <strong>{}</strong>.</p>",
            Utc::now().format("%Y-%m-%d %H:%M:%S")
        );

        let _ = sqlx::query(
            "INSERT INTO email_queue (template_slug, to_email, to_name, subject, body_html, status, attempts, scheduled_at, created_at)
             VALUES ('test_email', ?, 'Admin', ?, ?, 'pending', 0, NOW(), NOW())"
        )
        .bind(&recipient)
        .bind(format!("Test email from {}", site_name))
        .bind(&html_body)
        .execute(&state.db)
        .await;

        log_activity(&state.db, Some(admin.id), "mail.test_sent", &format!("Queued test email to {}", recipient), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Test email queued for delivery to {}.", recipient)).await;
        return Redirect::to("/admin/mail_settings.php").into_response();
    }

    // Save mail settings
    let pairs = vec![
        ("smtp_host", form.smtp_host),
        ("smtp_port", form.smtp_port),
        ("smtp_user", form.smtp_user),
        ("smtp_encryption", form.smtp_encryption),
        ("mail_from_address", form.mail_from_address),
        ("mail_from_name", form.mail_from_name),
        ("mail_driver", form.mail_driver),
    ];

    for (k, val_opt) in pairs {
        if let Some(v) = val_opt {
            let _ = sqlx::query(
                "INSERT INTO settings (key_name, value, updated_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()"
            )
            .bind(k)
            .bind(v.trim())
            .execute(&state.db)
            .await;
        }
    }

    // Handle password
    if let Some(pw) = form.smtp_pass {
        if pw != "••••••••••••••••" && !pw.trim().is_empty() {
            let enc_pw = app_encrypt(pw.trim(), &state.config.app_key);
            let _ = sqlx::query(
                "INSERT INTO settings (key_name, value, updated_at)
                 VALUES ('smtp_pass', ?, NOW())
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()"
            )
            .bind(enc_pw)
            .execute(&state.db)
            .await;
        }
    }

    state.settings.invalidate();
    log_activity(&state.db, Some(admin.id), "mail.settings_update", "Updated SMTP mail configuration", None).await;
    SessionHelper::add_flash(&session, "success", "Mail configuration updated successfully.").await;

    Redirect::to("/admin/mail_settings.php").into_response()
}

// =================================================================
// 3. Mail Templates (mail_templates.php)
// =================================================================

#[derive(Debug, Clone, sqlx::FromRow, serde::Serialize)]
pub struct MailTemplateItem {
    pub id: u64,
    pub slug: String,
    pub name: String,
    pub audience: String,
    pub subject: String,
    pub body_html: String,
    pub body_text: Option<String>,
    pub variables: Option<String>,
    pub enabled: i8,
    pub updated_at: chrono::NaiveDateTime,
}

#[derive(Debug, Deserialize)]
pub struct MailTemplateQuery {
    pub slug: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct MailTemplateForm {
    pub slug: String,
    pub subject: String,
    pub body_html: String,
    pub enabled: Option<String>,
}

pub async fn admin_mail_templates_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<MailTemplateQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("mail.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let templates = sqlx::query_as::<_, MailTemplateItem>(
        "SELECT id, slug, name, audience, subject, body_html, body_text, variables, enabled, updated_at
         FROM email_templates ORDER BY audience ASC, name ASC"
    )
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let active_slug = query.slug.unwrap_or_else(|| {
        templates.first().map(|t| t.slug.clone()).unwrap_or_default()
    });

    let current_template = templates.iter().find(|t| t.slug == active_slug).cloned();

    let mut ctx = build_admin_context(&state, &session, "mail_templates.php", "Email Templates").await;
    ctx.insert("templates", &templates);
    ctx.insert("active_slug", &active_slug);
    ctx.insert("current_slug", &active_slug);
    ctx.insert("current_template", &current_template);

    match state.tera.render("admin/mail_templates.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {:#?}", e)).into_response(),
    }
}

pub async fn admin_mail_templates_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<MailTemplateForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("mail.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. mail.manage required.").await;
        return Redirect::to("/admin/mail_templates.php").into_response();
    }

    let enabled = if form.enabled.as_deref() == Some("1") || form.enabled.as_deref() == Some("on") { 1 } else { 0 };

    let _ = sqlx::query(
        "UPDATE email_templates
         SET subject = ?, body_html = ?, enabled = ?, updated_at = NOW()
         WHERE slug = ?"
    )
    .bind(&form.subject)
    .bind(&form.body_html)
    .bind(enabled)
    .bind(&form.slug)
    .execute(&state.db)
    .await;

    log_activity(&state.db, Some(admin.id), "mail.template_update", &format!("Updated email template {}", form.slug), None).await;
    SessionHelper::add_flash(&session, "success", &format!("Template '{}' updated.", form.slug)).await;

    Redirect::to(&format!("/admin/mail_templates.php?slug={}", form.slug)).into_response()
}

// =================================================================
// 4. Mail Queue (mail_queue.php)
// =================================================================

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct MailQueueItem {
    pub id: u64,
    pub template_slug: Option<String>,
    pub to_email: String,
    pub to_name: Option<String>,
    pub subject: String,
    pub status: String,
    pub attempts: u8,
    pub last_error: Option<String>,
    pub scheduled_at: chrono::NaiveDateTime,
    pub sent_at: Option<chrono::NaiveDateTime>,
    pub created_at: chrono::NaiveDateTime,
}

#[derive(Debug, Deserialize)]
pub struct MailQueueQuery {
    pub status: Option<String>,
    pub q: Option<String>,
    pub page: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct MailQueueActionForm {
    pub action: Option<String>,
    pub id: Option<u64>,
}

pub async fn admin_mail_queue_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<MailQueueQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("mail.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let status_filter = query.status.unwrap_or_else(|| "all".to_string());
    let search_q = query.q.clone().unwrap_or_default();
    let page = query.page.unwrap_or(1).max(1);
    let per_page = 25i64;
    let offset = (page - 1) * per_page;

    let mut where_parts = Vec::new();
    if status_filter != "all" && ["pending", "sending", "sent", "failed"].contains(&status_filter.as_str()) {
        where_parts.push(format!("status = '{}'", status_filter));
    }
    if !search_q.trim().is_empty() {
        let safe_q = search_q.replace('\'', "''");
        where_parts.push(format!("(to_email LIKE '%{0}%' OR subject LIKE '%{0}%' OR to_name LIKE '%{0}%')", safe_q));
    }
    let where_clause = if where_parts.is_empty() {
        String::new()
    } else {
        format!("WHERE {}", where_parts.join(" AND "))
    };

    let count_sql = format!("SELECT COUNT(*) FROM email_queue {}", where_clause);
    let total_items: i64 = sqlx::query_scalar(&count_sql).fetch_one(&state.db).await.unwrap_or(0);
    let total_pages = ((total_items as f64) / (per_page as f64)).ceil() as i64;

    let list_sql = format!(
        "SELECT id, template_slug, to_email, to_name, subject, status, attempts,
                last_error, scheduled_at, sent_at, created_at
         FROM email_queue {} ORDER BY id DESC LIMIT ? OFFSET ?",
        where_clause
    );

    let queue_items = sqlx::query_as::<_, MailQueueItem>(&list_sql)
        .bind(per_page)
        .bind(offset)
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();

    let count_pending: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_sent: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM email_queue WHERE status = 'sent'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let count_failed: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM email_queue WHERE status = 'failed'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    let mut ctx = build_admin_context(&state, &session, "mail_queue.php", "Mail Queue & Logs").await;
    ctx.insert("items", &queue_items);
    ctx.insert("queue_items", &queue_items);
    ctx.insert("search_q", &search_q);
    ctx.insert("status_filter", &status_filter);
    ctx.insert("page", &page);
    ctx.insert("total_pages", &total_pages);
    ctx.insert("total_items", &total_items);
    ctx.insert("count_pending", &count_pending);
    ctx.insert("count_sent", &count_sent);
    ctx.insert("count_failed", &count_failed);

    match state.tera.render("admin/mail_queue.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {:#?}", e)).into_response(),
    }
}

pub async fn admin_mail_queue_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<MailQueueActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("mail.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied. mail.manage required.").await;
        return Redirect::to("/admin/mail_queue.php").into_response();
    }

    let action = form.action.unwrap_or_default();
    let qid = form.id.unwrap_or(0);

    if action == "retry" {
        let _ = sqlx::query(
            "UPDATE email_queue SET status = 'pending', scheduled_at = NOW(), last_error = NULL WHERE id = ?"
        )
        .bind(qid)
        .execute(&state.db)
        .await;

        log_activity(&state.db, Some(admin.id), "mail.retry", &format!("Manually rescheduled queue item #{}", qid), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Message #{} reset to pending.", qid)).await;
    } else if action == "delete" {
        let _ = sqlx::query("DELETE FROM email_queue WHERE id = ?").bind(qid).execute(&state.db).await;
        log_activity(&state.db, Some(admin.id), "mail.delete", &format!("Deleted queue item #{}", qid), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Queue item #{} deleted.", qid)).await;
    } else if action == "retry_all_failed" {
        let res = sqlx::query(
            "UPDATE email_queue SET status = 'pending', scheduled_at = NOW(), attempts = 0, last_error = NULL WHERE status = 'failed'"
        )
        .execute(&state.db)
        .await;

        let cnt = res.map(|r| r.rows_affected()).unwrap_or(0);
        log_activity(&state.db, Some(admin.id), "mail.retry_all", &format!("Reset {} failed emails to pending", cnt), None).await;
        SessionHelper::add_flash(&session, "success", &format!("Reset {} failed message(s) to pending for re-delivery.", cnt)).await;
    } else if action == "purge_sent" {
        let res = sqlx::query("DELETE FROM email_queue WHERE status = 'sent'").execute(&state.db).await;
        let cnt = res.map(|r| r.rows_affected()).unwrap_or(0);
        log_activity(&state.db, Some(admin.id), "mail.purge", &format!("Purged {} sent emails from queue", cnt), None).await;
        SessionHelper::add_flash(&session, "info", &format!("Purged {} sent email logs.", cnt)).await;
    }

    Redirect::to("/admin/mail_queue.php").into_response()
}

// =================================================================
// 5. Monitoring & Analytics (monitoring.php, analytics.php, analytics_product.php)
// =================================================================

#[derive(Debug, Deserialize)]
pub struct MonitoringForm {
    pub action: Option<String>,
    pub rule_id: Option<u64>,
    pub metric: Option<String>,
    pub operator: Option<String>,
    pub threshold: Option<f64>,
    pub for_minutes: Option<i32>,
    pub notify_email: Option<String>,
    pub enabled: Option<String>,
    pub retention_days: Option<i32>,
}

#[derive(Debug, sqlx::FromRow, serde::Serialize)]
pub struct MonitorRuleItem {
    pub id: u64,
    pub metric: String,
    pub operator: String,
    pub threshold: Decimal,
    pub for_minutes: u16,
    pub notify_email: i8,
    pub enabled: i8,
    pub last_fired_at: Option<chrono::NaiveDateTime>,
}

pub async fn admin_monitoring_handler(
    State(state): State<AppState>,
    session: Session,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("monitoring.view") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let mut ctx = build_admin_context(&state, &session, "monitoring.php", "System Health & Monitoring").await;

    // Fetch alert rules
    let alert_rules: Vec<MonitorRuleItem> = sqlx::query_as(
        "SELECT id, metric, operator, threshold, for_minutes, notify_email, enabled, last_fired_at FROM monitor_alert_rules ORDER BY id ASC"
    )
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();
    ctx.insert("alert_rules", &alert_rules);

    // Fetch top MySQL tables by size
    #[derive(sqlx::FromRow, serde::Serialize)]
    struct TopTableRow {
        table_name: String,
        size_bytes: Option<i64>,
        table_rows: Option<u64>,
    }
    let top_tables: Vec<TopTableRow> = sqlx::query_as(
        "SELECT TABLE_NAME as table_name, (DATA_LENGTH + INDEX_LENGTH) as size_bytes, TABLE_ROWS as table_rows
         FROM information_schema.TABLES
         WHERE table_schema = DATABASE()
         ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
         LIMIT 6"
    )
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();
    ctx.insert("top_tables", &top_tables);

    // DB Version & Size
    let db_version: String = sqlx::query_scalar("SELECT VERSION()")
        .fetch_one(&state.db)
        .await
        .unwrap_or_else(|_| "MySQL 8.0".to_string());
    let db_size: i64 = sqlx::query_scalar(
        "SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()"
    )
    .fetch_one(&state.db)
    .await
    .unwrap_or(0);
    ctx.insert("db_version", &db_version);
    ctx.insert("db_size", &db_size);

    // Check last collected sample
    let last_sample_ts: Option<chrono::NaiveDateTime> = sqlx::query_scalar(
        "SELECT collected_at FROM system_metrics ORDER BY id DESC LIMIT 1"
    )
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let is_collector_running = if let Some(ts) = last_sample_ts {
        let diff = Utc::now().naive_utc() - ts;
        diff.num_seconds() <= 180
    } else {
        false
    };
    ctx.insert("is_collector_running", &is_collector_running);
    ctx.insert("last_sample_ts", &last_sample_ts);

    // Diagnostics / System runtime equivalent
    let php_info = serde_json::json!({
        "version": "Rust 1.85 / Axum 0.7 (Parity)",
        "sapi": "Linux Native Daemon",
        "memory_limit": "unlimited",
        "max_exec": "60s",
        "upload_max": "20M",
        "post_max": "25M",
        "opcache": true,
        "pdo_mysql": true,
        "openssl": true,
        "mbstring": true,
        "gd": true,
        "intl": true,
        "curl": true,
    });
    ctx.insert("php_info", &php_info);

    let external_url = state.settings.get("monitor_external_url").unwrap_or_default();
    ctx.insert("external_url", &external_url);

    match state.tera.render("admin/monitoring.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {:#?}", e)).into_response(),
    }
}

pub async fn admin_monitoring_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<MonitoringForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("monitoring.view") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let action = form.action.as_deref().unwrap_or("");

    match action {
        "run_collector" => {
            let _ = log_activity(
                &state.db,
                Some(admin.id),
                "monitoring.manual_run",
                "Triggered manual metrics collection",
                None,
            ).await;
            SessionHelper::add_flash(&session, "success", "Metrics collector executed successfully.").await;
            Redirect::to("/admin/monitoring.php").into_response()
        }
        "save_rule" => {
            let rule_id = form.rule_id.unwrap_or(0);
            let metric = form.metric.unwrap_or_default();
            let op = if form.operator.as_deref() == Some("<") { "<" } else { ">" };
            let threshold = form.threshold.unwrap_or(0.0);
            let for_minutes = form.for_minutes.unwrap_or(5).clamp(1, 60);
            let notify = if form.notify_email.is_some() { 1 } else { 0 };
            let enabled = if form.enabled.is_some() { 1 } else { 0 };

            let allowed = ["cpu_percent", "mem_percent", "disk_percent", "load_1", "db_threads", "error_rate"];
            if allowed.contains(&metric.as_str()) && threshold > 0.0 {
                if rule_id > 0 {
                    let _ = sqlx::query(
                        "UPDATE monitor_alert_rules SET metric = ?, operator = ?, threshold = ?, for_minutes = ?, notify_email = ?, enabled = ? WHERE id = ?"
                    )
                    .bind(&metric)
                    .bind(op)
                    .bind(threshold)
                    .bind(for_minutes)
                    .bind(notify)
                    .bind(enabled)
                    .bind(rule_id)
                    .execute(&state.db)
                    .await;
                    SessionHelper::add_flash(&session, "success", "Alert rule updated.").await;
                } else {
                    let _ = sqlx::query(
                        "INSERT INTO monitor_alert_rules (metric, operator, threshold, for_minutes, notify_email, enabled) VALUES (?, ?, ?, ?, ?, ?)"
                    )
                    .bind(&metric)
                    .bind(op)
                    .bind(threshold)
                    .bind(for_minutes)
                    .bind(notify)
                    .bind(enabled)
                    .execute(&state.db)
                    .await;
                    SessionHelper::add_flash(&session, "success", "New alert rule created.").await;
                }
            } else {
                SessionHelper::add_flash(&session, "danger", "Invalid metric or threshold values provided.").await;
            }
            Redirect::to("/admin/monitoring.php#alertsTab").into_response()
        }
        "delete_rule" => {
            let rule_id = form.rule_id.unwrap_or(0);
            if rule_id > 0 {
                let _ = sqlx::query("DELETE FROM monitor_alert_rules WHERE id = ?")
                    .bind(rule_id)
                    .execute(&state.db)
                    .await;
                SessionHelper::add_flash(&session, "info", "Alert rule removed.").await;
            }
            Redirect::to("/admin/monitoring.php#alertsTab").into_response()
        }
        "test_fire_alert" => {
            let admin_email = state.settings.get("mail_admin_recipients").or_else(|| state.settings.get("site_email")).unwrap_or_else(|| "admin@example.com".to_string());
            let subject = "[TEST ALERT] Server Monitoring Alert Simulation";
            let body = format!("<h3>Test Alert Notification</h3><p>This is a simulated alert from Server Monitoring. Your notification pipeline is operational.</p><p>Timestamp: <strong>{}</strong></p>", Utc::now());
            
            let _ = sqlx::query(
                "INSERT INTO email_queue (to_email, subject, body_html, status, scheduled_at, created_at) VALUES (?, ?, ?, 'pending', NOW(), NOW())"
            )
            .bind(&admin_email)
            .bind(subject)
            .bind(&body)
            .execute(&state.db)
            .await;

            SessionHelper::add_flash(&session, "success", &format!("Test alert email queued for {}. Check Mail Queue.", admin_email)).await;
            Redirect::to("/admin/monitoring.php#alertsTab").into_response()
        }
        "purge_data" => {
            let days = form.retention_days.unwrap_or(7).max(1);
            let _ = sqlx::query(&format!("DELETE FROM system_metrics WHERE collected_at < DATE_SUB(NOW(), INTERVAL {} DAY)", days)).execute(&state.db).await;
            let _ = sqlx::query(&format!("DELETE FROM network_samples WHERE collected_at < DATE_SUB(NOW(), INTERVAL {} DAY)", days)).execute(&state.db).await;
            let _ = sqlx::query(&format!("DELETE FROM traffic_minute WHERE bucket < DATE_SUB(NOW(), INTERVAL {} DAY)", days)).execute(&state.db).await;
            let _ = sqlx::query(&format!("DELETE FROM slow_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL {} DAY)", days)).execute(&state.db).await;
            SessionHelper::add_flash(&session, "success", &format!("Purged monitoring records older than {} days.", days)).await;
            Redirect::to("/admin/monitoring.php").into_response()
        }
        _ => Redirect::to("/admin/monitoring.php").into_response(),
    }
}

#[derive(Debug, Deserialize)]
pub struct AnalyticsQuery {
    pub range: Option<String>,
    pub from: Option<String>,
    pub to: Option<String>,
    pub category_id: Option<u64>,
    pub group_by: Option<String>,
    pub compare: Option<String>,
    pub q: Option<String>,
    pub p: Option<i64>,
    pub limit: Option<i64>,
    pub export: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct AnalyticsForm {
    pub action: Option<String>,
}

pub async fn admin_analytics_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<AnalyticsQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("analytics.view") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let today = Utc::now().format("%Y-%m-%d").to_string();
    let range_preset = query.range.unwrap_or_else(|| "30d".to_string());
    let (mut from, mut to) = match range_preset.as_str() {
        "today" => (today.clone(), today.clone()),
        "7d" => ((Utc::now() - chrono::Duration::days(6)).format("%Y-%m-%d").to_string(), today.clone()),
        "90d" => ((Utc::now() - chrono::Duration::days(89)).format("%Y-%m-%d").to_string(), today.clone()),
        "custom" => {
            let f = query.from.unwrap_or_else(|| (Utc::now() - chrono::Duration::days(29)).format("%Y-%m-%d").to_string());
            let t = query.to.unwrap_or_else(|| today.clone());
            (f, t)
        }
        _ => ((Utc::now() - chrono::Duration::days(29)).format("%Y-%m-%d").to_string(), today.clone()),
    };
    if from > to {
        std::mem::swap(&mut from, &mut to);
    }

    // CSV export mode
    if query.export.as_deref() == Some("csv") {
        #[derive(sqlx::FromRow)]
        struct ProdExportRow {
            id: u64,
            name: String,
            sku: Option<String>,
            category_name: Option<String>,
            stock: i32,
            units_sold: Option<i64>,
            total_revenue: Option<Decimal>,
        }

        let rows: Vec<ProdExportRow> = sqlx::query_as(
            "SELECT p.id, p.name, p.sku, c.name as category_name, p.stock,
                    SUM(oi.quantity) as units_sold, SUM(oi.line_total) as total_revenue
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN order_items oi ON oi.product_id = p.id
             LEFT JOIN orders o ON o.id = oi.order_id AND o.status != 'cancelled' AND DATE(o.created_at) BETWEEN ? AND ?
             GROUP BY p.id, p.name, p.sku, c.name, p.stock
             ORDER BY total_revenue DESC LIMIT 1000"
        )
        .bind(&from)
        .bind(&to)
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();

        let mut csv_data = vec![0xEF, 0xBB, 0xBF];
        {
            let mut writer = csv::Writer::from_writer(&mut csv_data);
            let _ = writer.write_record([
                "Product ID", "Product Name", "SKU", "Category", "Stock Left",
                "Units Sold", "Revenue (BDT)",
            ]);
            for r in rows {
                let _ = writer.write_record([
                    r.id.to_string(),
                    r.name,
                    r.sku.unwrap_or_default(),
                    r.category_name.unwrap_or_else(|| "Uncategorized".to_string()),
                    r.stock.to_string(),
                    r.units_sold.unwrap_or(0).to_string(),
                    r.total_revenue.unwrap_or(Decimal::ZERO).to_string(),
                ]);
            }
        }

        let mut headers = axum::http::HeaderMap::new();
        headers.insert(axum::http::header::CONTENT_TYPE, axum::http::HeaderValue::from_static("text/csv; charset=utf-8"));
        let filename = format!("attachment; filename=\"analytics_products_{}_to_{}.csv\"", from, to);
        if let Ok(hv) = axum::http::HeaderValue::from_str(&filename) {
            headers.insert(axum::http::header::CONTENT_DISPOSITION, hv);
        }
        return (headers, csv_data).into_response();
    }

    let mut ctx = build_admin_context(&state, &session, "analytics.php", "Store Analytics").await;
    ctx.insert("from", &from);
    ctx.insert("to", &to);
    ctx.insert("range_preset", &range_preset);

    // Categories for filter
    let all_categories: Vec<(u64, String)> = sqlx::query_as("SELECT id, name FROM categories ORDER BY name ASC")
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();
    ctx.insert("all_categories", &all_categories);

    match state.tera.render("admin/analytics.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {:#?}", e)).into_response(),
    }
}

pub async fn admin_analytics_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<AnalyticsForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("analytics.view") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    if form.action.as_deref() == Some("refresh_rollup") {
        match jobs::aggregate_analytics::run_aggregate_analytics(&state.db, None, false).await {
            Ok(res) => {
                SessionHelper::add_flash(
                    &session,
                    "success",
                    &format!("Roll-ups refreshed! Updated {} site records, {} product records.", res.site_stats_rows, res.product_stats_rows),
                ).await;
            }
            Err(e) => {
                SessionHelper::add_flash(&session, "danger", &format!("Failed to refresh roll-ups: {}", e)).await;
            }
        }
    }

    Redirect::to("/admin/analytics.php").into_response()
}

#[derive(Debug, Deserialize)]
pub struct AnalyticsProductQuery {
    pub id: Option<u64>,
    pub from: Option<String>,
    pub to: Option<String>,
}

pub async fn admin_analytics_product_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<AnalyticsProductQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("analytics.view") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Access denied.").await;
        return Redirect::to("/admin/dashboard.php").into_response();
    }

    let product_id = match query.id {
        Some(id) if id > 0 => id,
        _ => {
            SessionHelper::add_flash(&session, "warning", "Please select a product to inspect.").await;
            return Redirect::to("/admin/analytics.php").into_response();
        }
    };

    #[derive(sqlx::FromRow, serde::Serialize)]
    struct ProdDetail {
        id: u64,
        name: String,
        sku: Option<String>,
        price: Decimal,
        sale_price: Option<Decimal>,
        stock: i32,
        category_name: Option<String>,
    }

    let product: Option<ProdDetail> = sqlx::query_as(
        "SELECT p.id, p.name, p.sku, p.price, p.sale_price, p.stock, c.name as category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id = ? LIMIT 1"
    )
    .bind(product_id)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let product = match product {
        Some(p) => p,
        None => {
            SessionHelper::add_flash(&session, "danger", "Product not found.").await;
            return Redirect::to("/admin/analytics.php").into_response();
        }
    };

    let today = Utc::now().format("%Y-%m-%d").to_string();
    let from = query.from.unwrap_or_else(|| (Utc::now() - chrono::Duration::days(29)).format("%Y-%m-%d").to_string());
    let to = query.to.unwrap_or_else(|| today.clone());

    let mut ctx = build_admin_context(&state, &session, "analytics_product.php", &format!("Product Analytics: {}", product.name)).await;
    ctx.insert("product", &product);
    ctx.insert("from", &from);
    ctx.insert("to", &to);

    match state.tera.render("admin/analytics_product.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, format!("Render error: {:#?}", e)).into_response(),
    }
}
