use chrono::Utc;
use serde::{Deserialize, Serialize};
use sqlx::{MySql, Pool, Row};
use std::time::Duration;

use crate::queries::cart::{CartTotals, HydratedCart};
use crate::settings::SettingsRepository;
use domain::models::order::Order;

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

pub fn get_shipping_fee(settings: &SettingsRepository, subtotal: rust_decimal::Decimal) -> rust_decimal::Decimal {
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

/// Check if error is a MySQL Deadlock (code 1213)
fn is_deadlock_error(err: &sqlx::Error) -> bool {
    if let sqlx::Error::Database(ref db_err) = err {
        if let Some(code) = db_err.code() {
            if code == "1213" {
                return true;
            }
        }
        let msg = db_err.message();
        if msg.contains("Deadlock") || msg.contains("1213") {
            return true;
        }
    }
    false
}

/// Check if error is a MySQL duplicate entry on order_number (code 1062)
fn is_duplicate_order_number_error(err: &sqlx::Error) -> bool {
    if let sqlx::Error::Database(ref db_err) = err {
        if let Some(code) = db_err.code() {
            if code == "1062" || code == "23000" {
                return true;
            }
        }
        let msg = db_err.message();
        if msg.contains("Duplicate entry") || msg.contains("1062") {
            return true;
        }
    }
    false
}

/// Execute checkout placing order in a single transaction with deadlock avoidance and retry loops
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

    // Sort order lines by (product_id, variant_id) to avoid deadlock when locking rows
    let mut sorted_items = cart.items.clone();
    sorted_items.sort_by_key(|item| (item.product_id, item.variant_id.unwrap_or(0)));

    let mut deadlock_retries = 3;
    let mut dup_order_retries = 5;

    loop {
        match execute_checkout_tx(
            pool,
            &form,
            &sorted_items,
            totals,
            customer_id,
            order_prefix,
            ip,
        )
        .await
        {
            Ok(order) => return Ok(order),
            Err(e) => {
                if is_deadlock_error(&e) && deadlock_retries > 0 {
                    deadlock_retries -= 1;
                    tokio::time::sleep(Duration::from_millis(50 * (4 - deadlock_retries))).await;
                    continue;
                }
                if is_duplicate_order_number_error(&e) && dup_order_retries > 0 {
                    dup_order_retries -= 1;
                    tokio::time::sleep(Duration::from_millis(30)).await;
                    continue;
                }
                return Err(e.to_string());
            }
        }
    }
}

async fn execute_checkout_tx(
    pool: &Pool<MySql>,
    form: &CheckoutFormData,
    sorted_items: &[crate::queries::cart::CartItem],
    totals: &CartTotals,
    customer_id: Option<u64>,
    order_prefix: &str,
    ip: Option<&str>,
) -> Result<Order, sqlx::Error> {
    let mut tx = pool.begin().await?;

    #[allow(dead_code)]
    struct LockedStock {
        pub product_id: u64,
        pub variant_id: Option<u64>,
        pub new_stock: i32,
    }

    let mut stock_updates = Vec::new();

    // 1. Lock and verify stock in strictly sorted order
    for item in sorted_items {
        if let Some(vid) = item.variant_id {
            let row = sqlx::query(
                "SELECT stock FROM product_variants WHERE id = ? AND product_id = ? FOR UPDATE",
            )
            .bind(vid)
            .bind(item.product_id)
            .fetch_optional(&mut *tx)
            .await?;

            let current_stock: i32 = match row {
                Some(r) => r.try_get("stock").unwrap_or(0),
                None => {
                    return Err(sqlx::Error::Protocol(format!(
                        "Variant for '{}' is no longer available.",
                        item.product_name
                    )));
                }
            };

            let p_row = sqlx::query(
                "SELECT track_inventory, allow_backorder FROM products WHERE id = ? FOR UPDATE",
            )
            .bind(item.product_id)
            .fetch_optional(&mut *tx)
            .await?;

            let (track_inv, allow_bo) = match p_row {
                Some(r) => (
                    r.try_get::<i8, _>("track_inventory").unwrap_or(1),
                    r.try_get::<i8, _>("allow_backorder").unwrap_or(0),
                ),
                None => (1, 0),
            };

            if track_inv != 0 && allow_bo == 0 && current_stock < item.qty {
                return Err(sqlx::Error::Protocol(format!(
                    "Insufficient stock for '{}' (requested: {}, available: {}).",
                    item.product_name, item.qty, current_stock
                )));
            }

            let new_stock = current_stock - item.qty;
            stock_updates.push(LockedStock {
                product_id: item.product_id,
                variant_id: Some(vid),
                new_stock,
            });

            sqlx::query("UPDATE product_variants SET stock = ? WHERE id = ?")
                .bind(new_stock)
                .bind(vid)
                .execute(&mut *tx)
                .await?;

            sqlx::query("UPDATE products SET stock = GREATEST(0, stock - ?), sold_count = sold_count + ? WHERE id = ?")
                .bind(item.qty)
                .bind(item.qty)
                .bind(item.product_id)
                .execute(&mut *tx)
                .await?;
        } else {
            let row = sqlx::query(
                "SELECT stock, track_inventory, allow_backorder FROM products WHERE id = ? FOR UPDATE",
            )
            .bind(item.product_id)
            .fetch_optional(&mut *tx)
            .await?;

            let (current_stock, track_inv, allow_bo) = match row {
                Some(r) => (
                    r.try_get::<i32, _>("stock").unwrap_or(0),
                    r.try_get::<i8, _>("track_inventory").unwrap_or(1),
                    r.try_get::<i8, _>("allow_backorder").unwrap_or(0),
                ),
                None => {
                    return Err(sqlx::Error::Protocol(format!(
                        "'{}' is no longer available.",
                        item.product_name
                    )));
                }
            };

            if track_inv != 0 && allow_bo == 0 && current_stock < item.qty {
                return Err(sqlx::Error::Protocol(format!(
                    "Insufficient stock for '{}' (requested: {}, available: {}).",
                    item.product_name, item.qty, current_stock
                )));
            }

            let new_stock = current_stock - item.qty;
            stock_updates.push(LockedStock {
                product_id: item.product_id,
                variant_id: None,
                new_stock,
            });

            sqlx::query("UPDATE products SET stock = ?, sold_count = sold_count + ? WHERE id = ?")
                .bind(new_stock)
                .bind(item.qty)
                .bind(item.product_id)
                .execute(&mut *tx)
                .await?;
        }
    }

    // 2. Generate unique order number
    let order_number = generate_order_number(pool, order_prefix).await?;

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
    .bind(form.shipping_country.clone().unwrap_or_else(|| "Bangladesh".to_string()))
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
    .await?;

    let order_id = res.last_insert_id();

    // 3. Insert order_items and record inventory_movements
    for (i, item) in sorted_items.iter().enumerate() {
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
        .await?;

        let stock_after = stock_updates.get(i).map(|s| s.new_stock).unwrap_or(0);

        let _ = sqlx::query(
            "INSERT INTO inventory_movements
              (product_id, variant_id, change_qty, stock_after, reason, order_id, created_at)
             VALUES
              (?, ?, ?, ?, 'order', ?, NOW())",
        )
        .bind(item.product_id)
        .bind(item.variant_id)
        .bind(-item.qty)
        .bind(stock_after)
        .bind(order_id)
        .execute(&mut *tx)
        .await;
    }

    // 4. Record order_status_history
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
    for item in sorted_items {
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

    tx.commit().await?;

    let order: Order = sqlx::query_as("SELECT * FROM orders WHERE id = ?")
        .bind(order_id)
        .fetch_one(pool)
        .await?;

    Ok(order)
}

/// Order status transition validation matching PHP is_valid_status_transition()
pub fn is_valid_status_transition(from: &str, to: &str) -> bool {
    if from == to {
        return true;
    }
    match from {
        "pending" => to == "processing" || to == "cancelled",
        "processing" => to == "shipped" || to == "cancelled",
        "shipped" => to == "completed" || to == "cancelled",
        "completed" => to == "cancelled",
        _ => false,
    }
}

/// Restore stock for a cancelled order exactly once
pub async fn stock_restore_for_order(pool: &Pool<MySql>, order_id: u64) -> Result<bool, sqlx::Error> {
    let mut tx = pool.begin().await?;

    let ord_row = sqlx::query("SELECT id, status, stock_restored FROM orders WHERE id = ? FOR UPDATE")
        .bind(order_id)
        .fetch_optional(&mut *tx)
        .await?;

    let ord = match ord_row {
        Some(r) => r,
        None => {
            tx.rollback().await?;
            return Ok(false);
        }
    };

    let stock_restored: i8 = ord.try_get("stock_restored").unwrap_or(0);
    if stock_restored != 0 {
        tx.rollback().await?;
        return Ok(false);
    }

    #[derive(Debug, sqlx::FromRow)]
    struct OrderItemRestock {
        pub product_id: u64,
        pub variant_id: Option<u64>,
        pub quantity: i32,
    }

    let items: Vec<OrderItemRestock> = sqlx::query_as(
        "SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = ? ORDER BY id ASC"
    )
    .bind(order_id)
    .fetch_all(&mut *tx)
    .await?;

    for item in items {
        if item.quantity <= 0 {
            continue;
        }

        let stock_after = if let Some(vid) = item.variant_id {
            sqlx::query("UPDATE product_variants SET stock = stock + ? WHERE id = ?")
                .bind(item.quantity)
                .bind(vid)
                .execute(&mut *tx)
                .await?;

            sqlx::query("UPDATE products SET stock = stock + ?, sold_count = CASE WHEN sold_count >= ? THEN sold_count - ? ELSE 0 END WHERE id = ?")
                .bind(item.quantity)
                .bind(item.quantity)
                .bind(item.quantity)
                .bind(item.product_id)
                .execute(&mut *tx)
                .await?;

            sqlx::query_scalar("SELECT stock FROM product_variants WHERE id = ?")
                .bind(vid)
                .fetch_optional(&mut *tx)
                .await?
                .unwrap_or(0)
        } else {
            sqlx::query("UPDATE products SET stock = stock + ?, sold_count = CASE WHEN sold_count >= ? THEN sold_count - ? ELSE 0 END WHERE id = ?")
                .bind(item.quantity)
                .bind(item.quantity)
                .bind(item.quantity)
                .bind(item.product_id)
                .execute(&mut *tx)
                .await?;

            sqlx::query_scalar("SELECT stock FROM products WHERE id = ?")
                .bind(item.product_id)
                .fetch_optional(&mut *tx)
                .await?
                .unwrap_or(0)
        };

        let _ = sqlx::query(
            "INSERT INTO inventory_movements (product_id, variant_id, change_qty, stock_after, reason, order_id, created_at)
             VALUES (?, ?, ?, ?, 'cancel_restock', ?, NOW())"
        )
        .bind(item.product_id)
        .bind(item.variant_id)
        .bind(item.quantity)
        .bind(stock_after)
        .bind(order_id)
        .execute(&mut *tx)
        .await;
    }

    sqlx::query("UPDATE orders SET stock_restored = 1 WHERE id = ?")
        .bind(order_id)
        .execute(&mut *tx)
        .await?;

    tx.commit().await?;
    Ok(true)
}

/// Record product sales summary when order completes
pub async fn product_sales_summary_record_order(pool: &Pool<MySql>, order_id: u64) -> Result<(), sqlx::Error> {
    sqlx::query(
        "INSERT INTO product_sales_summary (product_id, variant_id, variant_label, units, revenue, orders_count, last_sale)
         SELECT oi.product_id, COALESCE(oi.variant_id, 0), MAX(oi.variant_label),
                COALESCE(SUM(oi.quantity), 0), COALESCE(SUM(oi.line_total), 0.00), 1, MAX(o.created_at)
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE oi.order_id = ? AND oi.product_id IS NOT NULL
         GROUP BY oi.product_id, COALESCE(oi.variant_id, 0)
         ON DUPLICATE KEY UPDATE
            units        = product_sales_summary.units + VALUES(units),
            revenue      = product_sales_summary.revenue + VALUES(revenue),
            orders_count = product_sales_summary.orders_count + 1,
            last_sale    = GREATEST(COALESCE(product_sales_summary.last_sale, '1970-01-01'), VALUES(last_sale))",
    )
    .bind(order_id)
    .execute(pool)
    .await?;

    Ok(())
}

/// Reverse product sales summary if order cancelled
pub async fn product_sales_summary_reverse_order(pool: &Pool<MySql>, order_id: u64) -> Result<(), sqlx::Error> {
    sqlx::query(
        "UPDATE product_sales_summary pss
         JOIN (
             SELECT product_id, COALESCE(variant_id, 0) AS vid,
                    SUM(quantity) AS units, SUM(line_total) AS revenue
             FROM order_items
             WHERE order_id = ? AND product_id IS NOT NULL
             GROUP BY product_id, COALESCE(variant_id, 0)
         ) oi ON oi.product_id = pss.product_id AND oi.vid = pss.variant_id
         SET pss.units = GREATEST(0, CAST(pss.units AS SIGNED) - oi.units),
             pss.revenue = GREATEST(0.00, pss.revenue - oi.revenue),
             pss.orders_count = GREATEST(0, CAST(pss.orders_count AS SIGNED) - 1)",
    )
    .bind(order_id)
    .execute(pool)
    .await?;

    Ok(())
}

/// Update order status with transition check and automated post-transitions
pub async fn update_order_status(
    pool: &Pool<MySql>,
    order_id: u64,
    target_status: &str,
    user_id: Option<u64>,
    note: Option<&str>,
) -> Result<bool, String> {
    let mut tx = pool.begin().await.map_err(|e| e.to_string())?;

    let ord_row = sqlx::query("SELECT id, status, payment_method FROM orders WHERE id = ? FOR UPDATE")
        .bind(order_id)
        .fetch_optional(&mut *tx)
        .await
        .map_err(|e| e.to_string())?;

    let ord = match ord_row {
        Some(r) => r,
        None => return Ok(false),
    };

    let old_status: String = ord.try_get("status").unwrap_or_default();
    let payment_method: String = ord.try_get("payment_method").unwrap_or_default();

    if old_status == target_status {
        tx.rollback().await.map_err(|e| e.to_string())?;
        return Ok(true);
    }

    if !is_valid_status_transition(&old_status, target_status) {
        tx.rollback().await.map_err(|e| e.to_string())?;
        return Err(format!("Invalid status transition from '{}' to '{}'.", old_status, target_status));
    }

    let mut update_sql = "UPDATE orders SET status = ?, updated_at = NOW()".to_string();
    if target_status == "completed" && payment_method == "cod" {
        update_sql.push_str(", payment_status = 'paid'");
    }
    update_sql.push_str(" WHERE id = ?");

    sqlx::query(&update_sql)
        .bind(target_status)
        .bind(order_id)
        .execute(&mut *tx)
        .await
        .map_err(|e| e.to_string())?;

    // Record order status history
    let comment = note.unwrap_or("Status updated");
    sqlx::query(
        "INSERT INTO order_status_history (order_id, old_status, new_status, comment, notify_customer, user_id, created_at)
         VALUES (?, ?, ?, ?, 1, ?, NOW())"
    )
    .bind(order_id)
    .bind(&old_status)
    .bind(target_status)
    .bind(comment)
    .bind(user_id)
    .execute(&mut *tx)
    .await
    .map_err(|e| e.to_string())?;

    tx.commit().await.map_err(|e| e.to_string())?;

    // Post-commit actions:
    if target_status == "cancelled" {
        let _ = stock_restore_for_order(pool, order_id).await;
    }

    if target_status == "completed" && old_status != "completed" {
        let _ = product_sales_summary_record_order(pool, order_id).await;
    } else if target_status == "cancelled" && old_status == "completed" {
        let _ = product_sales_summary_reverse_order(pool, order_id).await;
    }

    Ok(true)
}
