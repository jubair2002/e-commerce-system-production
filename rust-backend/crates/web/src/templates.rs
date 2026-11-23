use std::collections::HashMap;
use tera::{Filter, Function, Result as TeraResult, Tera, Value};

use common::formatters::{escape_html, format_date, format_order_number, number_format, slugify};
use common::money::{format_money, parse_money};
use db::queries::catalog::resolve_image_url;
use db::SettingsRepository;
use rust_decimal::Decimal;
use std::str::FromStr;

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
    tera.register_filter("number_format", NumberFormatFilter);
    tera.register_filter("format_date", FormatDateFilter);
    tera.register_filter("date_format", FormatDateFilter);
    tera.register_filter("slugify", SlugifyFilter);
    tera.register_filter("order_number", OrderNumberFilter);
    tera.register_filter("e", EscapeHtmlFilter);

    // Register functions
    tera.register_function("url", UrlFunction {
        base_url: base_url.clone(),
    });
    tera.register_function("setting", SettingFunction {
        settings: settings.clone(),
    });
    tera.register_function("route", RouteFunction {
        base_url: base_url.clone(),
        settings: settings.clone(),
    });
    tera.register_function("icon", IconFunction {
        base_url: base_url.clone(),
    });
    tera.register_function("money", MoneyFunction {
        settings: settings.clone(),
    });
    tera.register_function("number_format", NumberFormatFunction);
    tera.register_function("format_date", FormatDateFunction);
    tera.register_function("slugify", SlugifyFunction);
    tera.register_function("order_number", OrderNumberFunction);
    tera.register_function("old", OldFunction);
    tera.register_function("csrf_token", CsrfTokenFunction);
    tera.register_function("admin_url", AdminUrlFunction);
    tera.register_function("avatar_url", AvatarUrlFunction);

    Ok(tera)
}

struct AdminUrlFunction;

impl Function for AdminUrlFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let path = args.get("path").and_then(|v| v.as_str()).unwrap_or("");
        let path_clean = path.trim_start_matches('/');
        let res = if path_clean.is_empty() {
            "/admin/dashboard.php".to_string()
        } else if path_clean.starts_with("admin/") {
            format!("/{}", path_clean)
        } else {
            format!("/admin/{}", path_clean)
        };
        Ok(Value::String(res))
    }
}

struct AvatarUrlFunction;

impl Function for AvatarUrlFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let avatar = args.get("avatar").and_then(|v| v.as_str());
        let name = args.get("name").and_then(|v| v.as_str()).unwrap_or("Admin");
        if let Some(av) = avatar.filter(|a| !a.trim().is_empty()) {
            if av.starts_with("http://") || av.starts_with("https://") || av.starts_with("data:") {
                return Ok(Value::String(av.to_string()));
            }
            return Ok(Value::String(resolve_image_url(Some(av))));
        }
        let initial = name.chars().next().unwrap_or('A').to_ascii_uppercase();
        let svg = format!(
            "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 100 100'><rect width='100%' height='100%' fill='%236366f1'/><text x='50%' y='55%' dominant-baseline='middle' text-anchor='middle' font-family='sans-serif' font-size='42' fill='%23ffffff' font-weight='bold'>{}</text></svg>",
            initial
        );
        Ok(Value::String(svg))
    }
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

struct MoneyFunction {
    settings: SettingsRepository,
}

impl Function for MoneyFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let sym = self.settings.get_or("currency_symbol", "৳");
        let dec = match args.get("value") {
            Some(Value::Number(n)) => parse_money(&n.to_string()),
            Some(Value::String(s)) => parse_money(s),
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

struct NumberFormatFilter;

impl Filter for NumberFormatFilter {
    fn filter(&self, value: &Value, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let decimals = args
            .get("decimals")
            .and_then(|v| v.as_u64())
            .unwrap_or(2) as u32;
        let dec_point = args
            .get("dec_point")
            .and_then(|v| v.as_str())
            .unwrap_or(".");
        let thousands_sep = args
            .get("thousands_sep")
            .and_then(|v| v.as_str())
            .unwrap_or(",");

        let dec = match value {
            Value::Number(n) => Decimal::from_str(&n.to_string()).unwrap_or_default(),
            Value::String(s) => Decimal::from_str(s).unwrap_or_default(),
            _ => Decimal::ZERO,
        };
        Ok(Value::String(number_format(dec, decimals, dec_point, thousands_sep)))
    }
}

struct NumberFormatFunction;

impl Function for NumberFormatFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let decimals = args
            .get("decimals")
            .and_then(|v| v.as_u64())
            .unwrap_or(2) as u32;
        let dec_point = args
            .get("dec_point")
            .and_then(|v| v.as_str())
            .unwrap_or(".");
        let thousands_sep = args
            .get("thousands_sep")
            .and_then(|v| v.as_str())
            .unwrap_or(",");

        let dec = match args.get("value") {
            Some(Value::Number(n)) => Decimal::from_str(&n.to_string()).unwrap_or_default(),
            Some(Value::String(s)) => Decimal::from_str(s).unwrap_or_default(),
            _ => Decimal::ZERO,
        };
        Ok(Value::String(number_format(dec, decimals, dec_point, thousands_sep)))
    }
}

struct FormatDateFilter;

impl Filter for FormatDateFilter {
    fn filter(&self, value: &Value, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let dt_str = match value {
            Value::String(s) => Some(s.as_str()),
            Value::Number(n) => Some(Box::leak(n.to_string().into_boxed_str()) as &str),
            _ => None,
        };
        let format = args.get("format").and_then(|v| v.as_str());
        Ok(Value::String(format_date(dt_str, format, None)))
    }
}

struct FormatDateFunction;

impl Function for FormatDateFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let dt_str = match args.get("value") {
            Some(Value::String(s)) => Some(s.as_str()),
            Some(Value::Number(n)) => Some(Box::leak(n.to_string().into_boxed_str()) as &str),
            _ => None,
        };
        let format = args.get("format").and_then(|v| v.as_str());
        Ok(Value::String(format_date(dt_str, format, None)))
    }
}

struct SlugifyFilter;

impl Filter for SlugifyFilter {
    fn filter(&self, value: &Value, _args: &HashMap<String, Value>) -> TeraResult<Value> {
        let s = value.as_str().unwrap_or("");
        Ok(Value::String(slugify(s)))
    }
}

struct SlugifyFunction;

impl Function for SlugifyFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let s = args.get("text").and_then(|v| v.as_str()).unwrap_or("");
        Ok(Value::String(slugify(s)))
    }
}

struct OrderNumberFilter;

impl Filter for OrderNumberFilter {
    fn filter(&self, value: &Value, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let seq = value.as_u64().unwrap_or(0);
        let prefix = args.get("prefix").and_then(|v| v.as_str()).unwrap_or("ORD");
        let ym = args.get("year_month").and_then(|v| v.as_str()).unwrap_or("202611");
        Ok(Value::String(format_order_number(prefix, ym, seq)))
    }
}

struct OrderNumberFunction;

impl Function for OrderNumberFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let seq = args.get("sequence").and_then(|v| v.as_u64()).unwrap_or(0);
        let prefix = args.get("prefix").and_then(|v| v.as_str()).unwrap_or("ORD");
        let ym = args.get("year_month").and_then(|v| v.as_str()).unwrap_or("202611");
        Ok(Value::String(format_order_number(prefix, ym, seq)))
    }
}

struct EscapeHtmlFilter;

impl Filter for EscapeHtmlFilter {
    fn filter(&self, value: &Value, _args: &HashMap<String, Value>) -> TeraResult<Value> {
        let s = value.as_str().unwrap_or("");
        Ok(Value::String(escape_html(s)))
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
    settings: SettingsRepository,
}

impl Function for RouteFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let name = match args.get("name").and_then(|v| v.as_str()) {
            Some(n) => n,
            None => return Err(tera::Error::msg("Missing 'name' argument in route()")),
        };

        let is_pretty = self.settings.get_bool("pretty_urls", false);

        // Map route name to (pretty_pattern, legacy_file) matching PHP get_route_map()
        let (pretty_pattern, legacy_file) = match name {
            "home" => ("", ""),
            "shop" => ("shop", "storefront/shop/index.php"),
            "category" => ("category/{slug}", "storefront/shop/category.php"),
            "product" => ("product/{slug}", "storefront/shop/product.php"),
            "cart" => ("cart", "storefront/cart/index.php"),
            "checkout" => ("checkout", "storefront/cart/checkout.php"),
            "order_confirmation" => ("order/confirmation", "storefront/cart/confirmation.php"),
            "track" => ("track", "storefront/track/index.php"),
            "login" => ("login", "storefront/auth/login.php"),
            "register" => ("register", "storefront/auth/register.php"),
            "logout" => ("logout", "storefront/auth/logout.php"),
            "forgot_password" => ("forgot-password", "storefront/auth/forgot-password.php"),
            "reset_password" => ("reset-password", "storefront/auth/reset-password.php"),
            "verify_email" => ("verify-email", "storefront/auth/verify-email.php"),
            "resend_verification" => ("resend-verification", "storefront/auth/resend-verification.php"),
            "account" => ("account", "account/index.php"),
            "account_orders" => ("account/orders", "account/orders.php"),
            "account_order" => ("account/order/{number}", "account/order.php"),
            "account_invoice" => ("account/invoice/{number}", "account/invoice.php"),
            "account_addresses" => ("account/addresses", "account/addresses.php"),
            "account_profile" => ("account/profile", "account/profile.php"),
            "account_security" => ("account/security", "account/security.php"),
            "account_wishlist" => ("account/wishlist", "account/wishlist.php"),
            "account_export" => ("account/export", "account/export.php"),
            "account_delete" => ("account/delete", "account/delete.php"),
            "page" => ("page/{slug}", "storefront/pages/view.php"),
            "contact" => ("contact", "storefront/pages/contact.php"),
            "newsletter" => ("newsletter", "storefront/pages/newsletter.php"),
            "sitemap" => ("sitemap.xml", "storefront/seo/sitemap.php"),
            "maintenance" => ("maintenance", "storefront/errors/maintenance.php"),
            "404" => ("404", "storefront/errors/404.php"),
            "500" => ("500", "storefront/errors/500.php"),
            custom => (custom, custom),
        };

        if is_pretty {
            let mut pattern = pretty_pattern.to_string();
            let mut query_params = Vec::new();

            for (k, v) in args {
                if k == "name" {
                    continue;
                }
                let val_str = match v {
                    Value::String(s) => s.clone(),
                    Value::Number(n) => n.to_string(),
                    Value::Bool(b) => b.to_string(),
                    _ => String::new(),
                };
                let placeholder = format!("{{{}}}", k);
                if pattern.contains(&placeholder) {
                    pattern = pattern.replace(&placeholder, &val_str);
                } else if !val_str.is_empty() {
                    query_params.push(format!("{}={}", k, val_str));
                }
            }

            let clean = pattern.trim_start_matches('/');
            let mut full = format!("{}{}", self.base_url, clean);
            if !query_params.is_empty() {
                full.push('?');
                full.push_str(&query_params.join("&"));
            }
            Ok(Value::String(full))
        } else {
            let file = legacy_file.trim_start_matches('/');
            let mut query_params = Vec::new();

            for (k, v) in args {
                if k == "name" {
                    continue;
                }
                let val_str = match v {
                    Value::String(s) => s.clone(),
                    Value::Number(n) => n.to_string(),
                    Value::Bool(b) => b.to_string(),
                    _ => String::new(),
                };
                if !val_str.is_empty() {
                    query_params.push(format!("{}={}", k, val_str));
                }
            }

            let mut full = format!("{}{}", self.base_url, file);
            if !query_params.is_empty() {
                full.push('?');
                full.push_str(&query_params.join("&"));
            }
            Ok(Value::String(full))
        }
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

struct OldFunction;

impl Function for OldFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let default = args.get("default").and_then(|v| v.as_str()).unwrap_or("");
        let val = args.get("value").and_then(|v| v.as_str()).unwrap_or(default);
        Ok(Value::String(escape_html(val)))
    }
}

struct CsrfTokenFunction;

impl Function for CsrfTokenFunction {
    fn call(&self, args: &HashMap<String, Value>) -> TeraResult<Value> {
        let token = args.get("token").and_then(|v| v.as_str()).unwrap_or("");
        Ok(Value::String(token.to_string()))
    }
}
