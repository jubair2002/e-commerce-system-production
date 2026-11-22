use std::collections::HashMap;
use tera::{Filter, Function, Result as TeraResult, Tera, Value};

use crate::money::{format_money, parse_money};
use crate::services::catalog::resolve_image_url;
use crate::settings::SettingsRepository;

/// Create and configure the Tera template engine
pub fn init_templates(
    template_dir: &str,
    settings: SettingsRepository,
    base_url: String,
) -> Result<Tera, tera::Error> {
    let glob_path = format!("{}/**/*.html", template_dir.trim_end_matches('/'));
    let mut tera = Tera::new(&glob_path)?;

    // Register filters
    tera.register_filter("money", MoneyFilter {
        settings: settings.clone(),
    });
    tera.register_filter("product_img", ProductImgFilter);

    // Register functions
    tera.register_function("url", UrlFunction {
        base_url: base_url.clone(),
    });
    tera.register_function("setting", SettingFunction {
        settings: settings.clone(),
    });
    tera.register_function("route", RouteFunction {
        base_url: base_url.clone(),
    });
    tera.register_function("icon", IconFunction {
        base_url: base_url.clone(),
    });

    Ok(tera)
}

struct MoneyFilter {
    settings: SettingsRepository,
}

impl Filter for MoneyFilter {
    fn filter(&self, value: &Value, _args: &HashMap<String, Value>) -> TeraResult<Value> {
        let sym = self.settings.get_or("currency_symbol", "৳");
        let dec = match value {
            Value::Number(n) => parse_money(&n.to_string()),
            Value::String(s) => parse_money(s),
            _ => None,
        };
        Ok(Value::String(format_money(dec, &sym)))
    }
}

struct ProductImgFilter;

impl Filter for ProductImgFilter {
    fn filter(&self, value: &Value, _args: &HashMap<String, Value>) -> TeraResult<Value> {
        let s = match value {
            Value::String(s) => Some(s.as_str()),
            _ => None,
        };
        Ok(Value::String(resolve_image_url(s)))
    }
}

struct UrlFunction {
    base_url: String,
}

impl Function for UrlFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let path = args
            .get("path")
            .and_then(|v| v.as_str())
            .unwrap_or("");
        let clean = path.trim_start_matches('/');
        Ok(Value::String(format!("{}{}", self.base_url, clean)))
    }
}

struct SettingFunction {
    settings: SettingsRepository,
}

impl Function for SettingFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let key = match args.get("key").and_then(|v| v.as_str()) {
            Some(k) => k,
            None => return Err(tera::Error::msg("Missing 'key' argument")),
        };
        let default = args
            .get("default")
            .and_then(|v| v.as_str())
            .unwrap_or("");
        Ok(Value::String(self.settings.get_or(key, default)))
    }
}

struct RouteFunction {
    base_url: String,
}

impl Function for RouteFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let name = match args.get("name").and_then(|v| v.as_str()) {
            Some(n) => n,
            None => return Err(tera::Error::msg("Missing 'name' argument")),
        };

        let path = match name {
            "home" => "".to_string(),
            "shop" => "shop".to_string(),
            "cart" => "cart".to_string(),
            "checkout" => "checkout".to_string(),
            "order_confirmation" => "order/confirmation".to_string(),
            "track" => "track".to_string(),
            "login" => "login".to_string(),
            "register" => "register".to_string(),
            "logout" => "logout".to_string(),
            "forgot_password" => "forgot-password".to_string(),
            "reset_password" => "reset-password".to_string(),
            "verify_email" => "verify-email".to_string(),
            "resend_verification" => "resend-verification".to_string(),
            "account" => "account".to_string(),
            "account_orders" => "account/orders".to_string(),
            "account_order" => "account/order".to_string(),
            "account_invoice" => "account/invoice".to_string(),
            "account_addresses" => "account/addresses".to_string(),
            "account_profile" => "account/profile".to_string(),
            "account_security" => "account/security".to_string(),
            "account_wishlist" => "account/wishlist".to_string(),
            "account_export" => "account/export".to_string(),
            "account_delete" => "account/delete".to_string(),
            "contact" => "contact".to_string(),
            "newsletter" => "newsletter".to_string(),
            "sitemap" => "sitemap.xml".to_string(),
            "category" => {
                let slug = args.get("slug").and_then(|v| v.as_str()).unwrap_or("");
                format!("category/{}", slug)
            }
            "product" => {
                let slug = args.get("slug").and_then(|v| v.as_str()).unwrap_or("");
                format!("product/{}", slug)
            }
            "page" => {
                let slug = args.get("slug").and_then(|v| v.as_str()).unwrap_or("");
                format!("page/{}", slug)
            }
            other => other.to_string(),
        };

        let clean = path.trim_start_matches('/');
        Ok(Value::String(format!("{}{}", self.base_url, clean)))
    }
}

struct IconFunction {
    base_url: String,
}

impl Function for IconFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let name = match args.get("name").and_then(|v| v.as_str()) {
            Some(n) => n,
            None => return Err(tera::Error::msg("Missing 'name' argument in icon()")),
        };
        let class = args
            .get("class")
            .and_then(|v| v.as_str())
            .unwrap_or("");
        let title = args
            .get("title")
            .and_then(|v| v.as_str())
            .unwrap_or("");

        let safe_name: String = name.chars().filter(|c| c.is_alphanumeric() || *c == '_' || *c == '-').collect();
        let cls = format!("i {}", class).trim().to_string();
        let sprite_url = format!("{}assets/img/icons.svg", self.base_url);

        let html = if title.is_empty() {
            format!(
                r#"<svg class="{}" aria-hidden="true"><use href="{}#{}"></use></svg>"#,
                cls, sprite_url, safe_name
            )
        } else {
            format!(
                r#"<svg class="{}" role="img" aria-label="{}"><title>{}</title><use href="{}#{}"></use></svg>"#,
                cls, title, title, sprite_url, safe_name
            )
        };

        Ok(Value::String(html))
    }
}
