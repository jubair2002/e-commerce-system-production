use chrono::Utc;
use serde::{Deserialize, Serialize};
use sqlx::{MySql, Pool, Row};

use crate::models::order::Order;
use crate::services::cart::{CartTotals, HydratedCart};

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct CheckoutFormData {
    pub customer_name: String,
    pub customer_email: String,
    pub customer_phone: Option<String>,
    pub shipping_address: String,
    pub shipping_city: Option<String>,
    pub shipping_zip: Option<String>,
    pub shipping_country: Option<String>,
    pub shipping_method_id: Option<u64>,
    pub payment_method: String,
    pub coupon_code: Option<String>,
    pub notes: Option<String>,
}

pub fn get_shipping_fee(settings: &crate::settings::SettingsRepository, subtotal: rust_decimal::Decimal) -> rust_decimal::Decimal {
    use std::str::FromStr;
    let free_thresh = rust_decimal::Decimal::from_str(&settings.get_or("free_shipping_threshold", "0")).unwrap_or(rust_decimal::Decimal::ZERO);
    if free_thresh > rust_decimal::Decimal::ZERO && subtotal >= free_thresh {
        return rust_decimal::Decimal::ZERO;
    }
    rust_decimal::Decimal::from_str(&settings.get_or("flat_shipping_rate", "60")).unwrap_or(rust_decimal::Decimal::from(60))
}

/// Generate unique order number matching ORD-YYYYMM-XXXX
pub async fn generate_order_number(
    pool: &Pool<MySql>,
    prefix: &str,
) -> Result<String, sqlx::Error> {
    let now = Utc::now();
    let month_prefix = format!("{}-{}-", prefix, now.format("%Y%m"));

    let last_order_num: Option<String> = sqlx::query_scalar(
        "SELECT order_number FROM orders WHERE order_number LIKE ? ORDER BY id DESC LIMIT 1",
    )
    .bind(format!("{}%", month_prefix))
    .fetch_optional(pool)
    .await?;

    let next_num = match last_order_num {
        Some(s) => {
            if let Some(pos) = s.rfind('-') {
                s[pos + 1..].parse::<u32>().unwrap_or(0) + 1
            } else {
                1
            }
        }
        None => 1,
    };

    Ok(format!("{}{:04}", month_prefix, next_num))
}

/// Execute checkout placing order in a single transaction with row-level locks
pub async fn place_order(
    pool: &Pool<MySql>,
    form: CheckoutFormData,
    cart: &HydratedCart,
    totals: &CartTotals,
    customer_id: Option<u64>,
    order_prefix: &str,
    ip: Option<&str>,
) -> Result<Order, String> {
    if cart.items.is_empty() {
        return Err("Your cart is empty.".to_string());
    }

    let mut tx = pool.begin().await.map_err(|e| e.to_string())?;

    // 1. Verify stock with FOR UPDATE and decrement stock
    for item in &cart.items {
        if let Some(vid) = item.variant_id {
            let row = sqlx::query(
                "SELECT stock FROM product_variants WHERE id = ? AND product_id = ? FOR UPDATE",
            )
            .bind(vid)
            .bind(item.product_id)
            .fetch_optional(&mut *tx)
            .await
            .map_err(|e| e.to_string())?;

            let current_stock: i32 = match row {
                Some(r) => r.try_get("stock").unwrap_or(0),
                None => {
                    return Err(format!(
                        "Variant for '{}' is no longer available.",
                        item.product_name
                    ));
                }
            };

            // Check product tracking
            let p_row = sqlx::query(
                "SELECT track_inventory, allow_backorder FROM products WHERE id = ? FOR UPDATE",
            )
            .bind(item.product_id)
            .fetch_optional(&mut *tx)
            .await
            .map_err(|e| e.to_string())?;

            let (track_inv, allow_bo) = match p_row {
                Some(r) => (
                    r.try_get::<i8, _>("track_inventory").unwrap_or(1),
                    r.try_get::<i8, _>("allow_backorder").unwrap_or(0),
                ),
                None => (1, 0),
            };

            if track_inv != 0 && allow_bo == 0 && current_stock < item.qty {
                return Err(format!(
                    "Insufficient stock for '{}' (requested: {}, available: {}).",
                    item.product_name, item.qty, current_stock
                ));
            }

            let new_stock = current_stock - item.qty;
            sqlx::query("UPDATE product_variants SET stock = ? WHERE id = ?")
                .bind(new_stock)
                .bind(vid)
                .execute(&mut *tx)
                .await
                .map_err(|e| e.to_string())?;

            // Also decrement parent product stock if needed
            sqlx::query("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?")
                .bind(item.qty)
                .bind(item.product_id)
                .execute(&mut *tx)
                .await
                .map_err(|e| e.to_string())?;
        } else {
            let row = sqlx::query(
                "SELECT stock, track_inventory, allow_backorder FROM products WHERE id = ? FOR UPDATE",
            )
            .bind(item.product_id)
            .fetch_optional(&mut *tx)
            .await
            .map_err(|e| e.to_string())?;

            let (current_stock, track_inv, allow_bo) = match row {
                Some(r) => (
                    r.try_get::<i32, _>("stock").unwrap_or(0),
                    r.try_get::<i8, _>("track_inventory").unwrap_or(1),
                    r.try_get::<i8, _>("allow_backorder").unwrap_or(0),
                ),
                None => {
                    return Err(format!("'{}' is no longer available.", item.product_name));
                }
            };

            if track_inv != 0 && allow_bo == 0 && current_stock < item.qty {
                return Err(format!(
                    "Insufficient stock for '{}' (requested: {}, available: {}).",
                    item.product_name, item.qty, current_stock
                ));
            }

            let new_stock = current_stock - item.qty;
            sqlx::query("UPDATE products SET stock = ? WHERE id = ?")
                .bind(new_stock)
                .bind(item.product_id)
                .execute(&mut *tx)
                .await
                .map_err(|e| e.to_string())?;
        }
    }

    // 2. Generate order number
    let order_number = generate_order_number(pool, order_prefix)
        .await
        .map_err(|e| e.to_string())?;

    let shipping_method_name = totals
        .shipping_method
        .as_ref()
        .map(|m| m.name.clone());

    let res = sqlx::query(
        "INSERT INTO orders
          (order_number, customer_id, customer_name, customer_email, customer_phone,
           shipping_address, shipping_city, shipping_zip, shipping_country,
           subtotal, shipping_fee, total, payment_method, status, notes,
           created_at, updated_at, payment_status, cod_fee, discount_total,
           coupon_code, shipping_method_id, shipping_method_name, stock_restored, ip_address)
         VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW(), 'unpaid', ?, ?, ?, ?, ?, 0, ?)",
    )
    .bind(&order_number)
    .bind(customer_id)
    .bind(&form.customer_name)
    .bind(&form.customer_email)
    .bind(&form.customer_phone)
    .bind(&form.shipping_address)
    .bind(&form.shipping_city)
    .bind(&form.shipping_zip)
    .bind(form.shipping_country.unwrap_or_else(|| "Bangladesh".to_string()))
    .bind(totals.subtotal)
    .bind(totals.shipping_fee)
    .bind(totals.total)
    .bind(&form.payment_method)
    .bind(&form.notes)
    .bind(totals.cod_fee)
    .bind(totals.discount)
    .bind(&form.coupon_code)
    .bind(form.shipping_method_id)
    .bind(shipping_method_name)
    .bind(ip.unwrap_or("127.0.0.1"))
    .execute(&mut *tx)
    .await
    .map_err(|e| format!("Failed to insert order: {}", e))?;

    let order_id = res.last_insert_id();

    // 3. Insert order items & record inventory movement
    for item in &cart.items {
        sqlx::query(
            "INSERT INTO order_items
              (order_id, product_id, variant_id, product_name, product_image,
               unit_price, quantity, line_total, variant_label)
             VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        )
        .bind(order_id)
        .bind(item.product_id)
        .bind(item.variant_id)
        .bind(&item.product_name)
        .bind(&item.image)
        .bind(item.unit_price)
        .bind(item.qty)
        .bind(item.line_total)
        .bind(&item.variant_label)
        .execute(&mut *tx)
        .await
        .map_err(|e| format!("Failed to insert order item: {}", e))?;

        // Inventory movement log
        let _ = sqlx::query(
            "INSERT INTO inventory_movements
              (product_id, variant_id, change_qty, reason, order_id, created_at)
             VALUES
              (?, ?, ?, 'order', ?, NOW())",
        )
        .bind(item.product_id)
        .bind(item.variant_id)
        .bind(-item.qty)
        .bind(order_id)
        .execute(&mut *tx)
        .await;
    }

    // 4. Insert order status history
    let _ = sqlx::query(
        "INSERT INTO order_status_history
          (order_id, old_status, new_status, comment, notify_customer, created_at)
         VALUES
          (?, NULL, 'pending', 'Order placed by customer', 1, NOW())",
    )
    .bind(order_id)
    .execute(&mut *tx)
    .await;

    // 5. Update coupon usage
    if let Some(ref c) = totals.coupon {
        let _ = sqlx::query("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?")
            .bind(c.id)
            .execute(&mut *tx)
            .await;
    }

    // 6. Record sales summary
    for item in &cart.items {
        let vid = item.variant_id.unwrap_or(0);
        let _ = sqlx::query(
            "INSERT INTO product_sales_summary (product_id, variant_id, variant_label, units, revenue, orders_count, last_sale)
             VALUES (?, ?, ?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               units = units + VALUES(units),
               revenue = revenue + VALUES(revenue),
               orders_count = orders_count + 1,
               last_sale = NOW()",
        )
        .bind(item.product_id)
        .bind(vid)
        .bind(&item.variant_label)
        .bind(item.qty)
        .bind(item.line_total)
        .execute(&mut *tx)
        .await;
    }

    tx.commit().await.map_err(|e| e.to_string())?;

    // Load created order
    let order: Order = sqlx::query_as("SELECT * FROM orders WHERE id = ?")
        .bind(order_id)
        .fetch_one(pool)
        .await
        .map_err(|e| format!("Failed to load created order: {}", e))?;

    Ok(order)
}
