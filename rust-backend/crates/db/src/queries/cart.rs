use rust_decimal::Decimal;
use serde::{Deserialize, Serialize};
use sqlx::{MySql, Pool, Row};
use std::collections::HashMap;

use crate::queries::catalog::resolve_image_url;
use crate::settings::SettingsRepository;
use common::money::{format_money, round_money};
use domain::models::order::{Coupon, ShippingMethod};
use domain::models::product::{Product, ProductVariant};

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct CartItem {
    pub cart_key: String,
    pub product_id: u64,
    pub variant_id: Option<u64>,
    pub qty: i32,
    pub unit_price: Decimal,
    pub line_total: Decimal,
    pub product_name: String,
    pub variant_label: Option<String>,
    pub image: Option<String>,
    pub image_url: String,
    pub slug: String,
    pub available_qty: i32,
    pub formatted_price: String,
    pub formatted_line_total: String,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct HydratedCart {
    pub items: Vec<CartItem>,
    pub subtotal: Decimal,
    pub formatted_subtotal: String,
    pub total_count: i32,
    pub alerts: Vec<String>,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct CouponValidationResult {
    pub valid: bool,
    pub coupon: Option<Coupon>,
    pub discount: Decimal,
    pub error: Option<String>,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct CartTotals {
    pub subtotal: Decimal,
    pub discount: Decimal,
    pub discounted_subtotal: Decimal,
    pub coupon: Option<Coupon>,
    pub coupon_error: Option<String>,
    pub shipping_method: Option<ShippingMethod>,
    pub shipping_fee: Decimal,
    pub cod_fee: Decimal,
    pub total: Decimal,
    pub formatted_subtotal: String,
    pub formatted_discount: String,
    pub formatted_shipping_fee: String,
    pub formatted_cod_fee: String,
    pub formatted_total: String,
}

/// Normalize key to "productId:variantId"
pub fn cart_key(product_id: u64, variant_id: Option<u64>) -> String {
    let vid = variant_id.unwrap_or(0);
    format!("{}:{}", product_id, vid)
}

/// Parse "productId:variantId" back to (productId, Option<variantId>)
pub fn cart_parse_key(key: &str) -> (u64, Option<u64>) {
    let parts: Vec<&str> = key.splitn(2, ':').collect();
    let pid = parts.first().and_then(|p| p.parse::<u64>().ok()).unwrap_or(0);
    let vid = parts.get(1).and_then(|v| v.parse::<u64>().ok()).unwrap_or(0);
    (pid, if vid > 0 { Some(vid) } else { None })
}

/// Hydrate raw session cart map {"productId:variantId": qty} from database
pub async fn cart_load(
    pool: &Pool<MySql>,
    raw_cart: &HashMap<String, i32>,
    currency_symbol: &str,
) -> Result<HydratedCart, sqlx::Error> {
    if raw_cart.is_empty() {
        return Ok(HydratedCart {
            items: Vec::new(),
            subtotal: Decimal::ZERO,
            formatted_subtotal: format_money(Some(Decimal::ZERO), currency_symbol),
            total_count: 0,
            alerts: Vec::new(),
        });
    }

    let mut items = Vec::new();
    let mut subtotal = Decimal::ZERO;
    let mut total_count = 0;
    let mut alerts = Vec::new();

    for (k, &raw_qty) in raw_cart {
        if raw_qty <= 0 {
            continue;
        }

        let (pid, vid) = cart_parse_key(k);
        if pid == 0 {
            continue;
        }

        let product: Option<Product> = sqlx::query_as(
            "SELECT * FROM products WHERE id = ? AND status = 'active' LIMIT 1",
        )
        .bind(pid)
        .fetch_optional(pool)
        .await?;

        let p = match product {
            Some(prod) => prod,
            None => {
                alerts.push("An item in your cart is no longer available.".to_string());
                continue;
            }
        };

        let mut unit_price = p.sale_price.unwrap_or(p.price);
        let mut variant_label = None;
        let mut variant_image = None;
        let mut avail_qty = p.stock;

        if let Some(v_id) = vid {
            let variant: Option<ProductVariant> = sqlx::query_as(
                "SELECT * FROM product_variants WHERE id = ? AND product_id = ? AND status = 'active' LIMIT 1",
            )
            .bind(v_id)
            .bind(pid)
            .fetch_optional(pool)
            .await?;

            if let Some(v) = variant {
                if let Some(sp) = v.sale_price {
                    unit_price = sp;
                } else if let Some(vp) = v.price {
                    unit_price = vp;
                }
                avail_qty = v.stock;
                variant_image = v.image;

                // Load variant label
                let vrows = sqlx::query(
                    "SELECT po.name AS option_name, pov.value AS option_value
                     FROM product_variant_values pvv
                     JOIN product_options po ON po.id = pvv.option_id
                     JOIN product_option_values pov ON pov.id = pvv.option_value_id
                     WHERE pvv.variant_id = ?
                     ORDER BY po.position ASC, po.id ASC",
                )
                .bind(v_id)
                .fetch_all(pool)
                .await?;

                let labels: Vec<String> = vrows
                    .into_iter()
                    .filter_map(|r| {
                        let opt: String = r.try_get("option_name").ok()?;
                        let val: String = r.try_get("option_value").ok()?;
                        Some(format!("{}: {}", opt, val))
                    })
                    .collect();

                if !labels.is_empty() {
                    variant_label = Some(labels.join(" / "));
                }
            } else {
                alerts.push(format!("The selected variant for '{}' is no longer available.", p.name));
                continue;
            }
        }

        if p.track_inventory == 0 || p.allow_backorder == 1 {
            avail_qty = 999999;
        }

        let qty = if avail_qty < raw_qty {
            if avail_qty <= 0 {
                alerts.push(format!("'{}' is currently out of stock.", p.name));
                continue;
            } else {
                alerts.push(format!(
                    "Quantity for '{}' was adjusted to {} based on available stock.",
                    p.name, avail_qty
                ));
                avail_qty
            }
        } else {
            raw_qty
        };

        let unit_price = round_money(unit_price);
        let line_total = round_money(unit_price * Decimal::from(qty));
        subtotal += line_total;
        total_count += qty;

        let display_image = variant_image.or(p.image);
        let img_url = resolve_image_url(display_image.as_deref());

        items.push(CartItem {
            cart_key: k.clone(),
            product_id: pid,
            variant_id: vid,
            qty,
            unit_price,
            line_total,
            product_name: p.name,
            variant_label,
            image: display_image,
            image_url: img_url,
            slug: p.slug,
            available_qty: avail_qty,
            formatted_price: format_money(Some(unit_price), currency_symbol),
            formatted_line_total: format_money(Some(line_total), currency_symbol),
        });
    }

    subtotal = round_money(subtotal);

    Ok(HydratedCart {
        items,
        subtotal,
        formatted_subtotal: format_money(Some(subtotal), currency_symbol),
        total_count,
        alerts,
    })
}

/// Validate coupon against database
pub async fn validate_coupon(
    pool: &Pool<MySql>,
    code: &str,
    subtotal: Decimal,
) -> Result<CouponValidationResult, sqlx::Error> {
    let clean = code.trim().to_uppercase();
    if clean.is_empty() {
        return Ok(CouponValidationResult {
            valid: false,
            coupon: None,
            discount: Decimal::ZERO,
            error: Some("Please enter a coupon code.".to_string()),
        });
    }

    let coupon: Option<Coupon> = sqlx::query_as(
        "SELECT * FROM coupons
         WHERE code = ? AND status = 'active'
           AND (starts_at IS NULL OR starts_at <= NOW())
           AND (expires_at IS NULL OR expires_at >= NOW())
         LIMIT 1",
    )
    .bind(&clean)
    .fetch_optional(pool)
    .await?;

    let c = match coupon {
        Some(cp) => cp,
        None => {
            return Ok(CouponValidationResult {
                valid: false,
                coupon: None,
                discount: Decimal::ZERO,
                error: Some("Invalid or expired coupon code.".to_string()),
            });
        }
    };

    if let Some(limit) = c.usage_limit {
        if c.used_count >= limit {
            return Ok(CouponValidationResult {
                valid: false,
                coupon: None,
                discount: Decimal::ZERO,
                error: Some("This coupon has reached its usage limit.".to_string()),
            });
        }
    }

    if subtotal < c.min_subtotal {
        return Ok(CouponValidationResult {
            valid: false,
            coupon: None,
            discount: Decimal::ZERO,
            error: Some(format!(
                "Minimum spend of {} is required for this coupon.",
                c.min_subtotal
            )),
        });
    }

    let mut discount = if c.coupon_type == "percent" {
        round_money(subtotal * (c.value / Decimal::from(100)))
    } else {
        round_money(c.value.min(subtotal))
    };

    if let Some(max_disc) = c.max_discount {
        if discount > max_disc {
            discount = max_disc;
        }
    }

    Ok(CouponValidationResult {
        valid: true,
        coupon: Some(c),
        discount,
        error: None,
    })
}

/// Calculate cart totals with coupon, shipping method, and COD fee
pub async fn calculate_totals(
    pool: &Pool<MySql>,
    cart: &HydratedCart,
    shipping_method_id: Option<u64>,
    coupon_code: Option<&str>,
    payment_method: &str,
    settings: &SettingsRepository,
) -> Result<CartTotals, sqlx::Error> {
    let currency_symbol = settings.get_or("currency_symbol", "৳");
    let subtotal = cart.subtotal;

    let mut discount = Decimal::ZERO;
    let mut coupon = None;
    let mut coupon_error = None;

    if let Some(code) = coupon_code {
        if !code.trim().is_empty() {
            let res = validate_coupon(pool, code, subtotal).await?;
            if res.valid {
                discount = res.discount;
                coupon = res.coupon;
            } else {
                coupon_error = res.error;
            }
        }
    }

    let discounted_subtotal = if subtotal > discount {
        subtotal - discount
    } else {
        Decimal::ZERO
    };

    // Shipping method lookup
    let methods: Vec<ShippingMethod> = sqlx::query_as(
        "SELECT * FROM shipping_methods WHERE status = 'active' ORDER BY position ASC, id ASC",
    )
    .fetch_all(pool)
    .await?;

    let mut selected_method = None;
    let mut shipping_fee = Decimal::ZERO;

    if !methods.is_empty() {
        if let Some(sm_id) = shipping_method_id {
            selected_method = methods.iter().find(|m| m.id == sm_id).cloned();
        }
        if selected_method.is_none() {
            selected_method = methods.first().cloned();
        }

        if let Some(ref m) = selected_method {
            shipping_fee = m.cost;
            if let Some(free_over) = m.free_over {
                if free_over > Decimal::ZERO && discounted_subtotal >= free_over {
                    shipping_fee = Decimal::ZERO;
                }
            }
        }
    } else {
        shipping_fee = Decimal::from_str_exact(&settings.get_or("shipping_fee", "0")).unwrap_or(Decimal::ZERO);
    }

    // COD fee
    let mut cod_fee = Decimal::ZERO;
    if payment_method == "cod" && settings.get_bool("cod_enabled", true) {
        cod_fee = Decimal::from_str_exact(&settings.get_or("cod_fee", "0.00")).unwrap_or(Decimal::ZERO);
    }

    let total = round_money(discounted_subtotal + shipping_fee + cod_fee);

    Ok(CartTotals {
        subtotal,
        discount,
        discounted_subtotal,
        coupon,
        coupon_error,
        shipping_method: selected_method,
        shipping_fee: round_money(shipping_fee),
        cod_fee: round_money(cod_fee),
        total,
        formatted_subtotal: format_money(Some(subtotal), &currency_symbol),
        formatted_discount: format_money(Some(discount), &currency_symbol),
        formatted_shipping_fee: format_money(Some(shipping_fee), &currency_symbol),
        formatted_cod_fee: format_money(Some(cod_fee), &currency_symbol),
        formatted_total: format_money(Some(total), &currency_symbol),
    })
}

pub fn cart_add(raw_cart: &mut HashMap<String, i32>, product_id: u64, variant_id: Option<u64>, qty: i32) {
    let key = cart_key(product_id, variant_id);
    let entry = raw_cart.entry(key).or_insert(0);
    *entry += qty;
}

pub fn cart_set_key(raw_cart: &mut HashMap<String, i32>, key: &str, qty: i32) {
    if qty <= 0 {
        raw_cart.remove(key);
    } else {
        raw_cart.insert(key.to_string(), qty);
    }
}

pub fn cart_remove_key(raw_cart: &mut HashMap<String, i32>, key: &str) {
    raw_cart.remove(key);
}

pub fn cart_clear(raw_cart: &mut HashMap<String, i32>) {
    raw_cart.clear();
}

pub fn cart_count(cart: &HydratedCart) -> i32 {
    cart.total_count
}

