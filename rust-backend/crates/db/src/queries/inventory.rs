use sqlx::{MySql, Pool};

/// Atomically adjust stock for a simple product or variant, recording an entry in inventory_movements.
#[allow(clippy::too_many_arguments)]
pub async fn stock_adjust(
    pool: &Pool<MySql>,
    product_id: u64,
    variant_id: Option<u64>,
    delta: i32,
    reason: &str,
    order_id: Option<u64>,
    user_id: Option<u64>,
    note: Option<&str>,
) -> Result<bool, sqlx::Error> {
    if delta == 0 {
        return Ok(true);
    }

    let allowed_reasons = ["order", "cancel_restock", "manual_adjust", "restock", "return", "correction"];
    let mapped_reason = match reason {
        "manual" => "manual_adjust",
        "cancel" => "cancel_restock",
        "adjust" => "manual_adjust",
        other if allowed_reasons.contains(&other) => other,
        _ => "manual_adjust",
    };

    let mut tx = pool.begin().await?;

    // Check product rules
    let product_row: Option<(i32, i8, i8)> = sqlx::query_as(
        "SELECT stock, track_inventory, allow_backorder FROM products WHERE id = ?"
    )
    .bind(product_id)
    .fetch_optional(&mut *tx)
    .await?;

    let (prod_stock, track_inv, allow_backorder) = match product_row {
        Some(r) => (r.0, r.1 != 0, r.2 != 0),
        None => return Ok(false),
    };

    let stock_after: i32;

    if let Some(vid) = variant_id {
        let var_row: Option<(i32,)> = sqlx::query_as(
            "SELECT stock FROM product_variants WHERE id = ? AND product_id = ?"
        )
        .bind(vid)
        .bind(product_id)
        .fetch_optional(&mut *tx)
        .await?;

        let var_current_stock = match var_row {
            Some(v) => v.0,
            None => return Ok(false),
        };

        if delta < 0 && track_inv && !allow_backorder {
            let res = sqlx::query(
                "UPDATE product_variants SET stock = stock + ? WHERE id = ? AND (stock + ?) >= 0"
            )
            .bind(delta)
            .bind(vid)
            .bind(delta)
            .execute(&mut *tx)
            .await?;

            if res.rows_affected() == 0 {
                return Ok(false);
            }
        } else {
            sqlx::query("UPDATE product_variants SET stock = stock + ? WHERE id = ?")
                .bind(delta)
                .bind(vid)
                .execute(&mut *tx)
                .await?;
        }

        stock_after = var_current_stock + delta;
    } else {
        if delta < 0 && track_inv && !allow_backorder {
            let res = sqlx::query(
                "UPDATE products SET stock = stock + ? WHERE id = ? AND (stock + ?) >= 0"
            )
            .bind(delta)
            .bind(product_id)
            .bind(delta)
            .execute(&mut *tx)
            .await?;

            if res.rows_affected() == 0 {
                return Ok(false);
            }
        } else {
            sqlx::query("UPDATE products SET stock = stock + ? WHERE id = ?")
                .bind(delta)
                .bind(product_id)
                .execute(&mut *tx)
                .await?;
        }

        stock_after = prod_stock + delta;
    }

    // Insert into inventory_movements
    sqlx::query(
        "INSERT INTO inventory_movements (product_id, variant_id, change_qty, stock_after, reason, order_id, user_id, note, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    )
    .bind(product_id)
    .bind(variant_id)
    .bind(delta)
    .bind(stock_after)
    .bind(mapped_reason)
    .bind(order_id)
    .bind(user_id)
    .bind(note)
    .execute(&mut *tx)
    .await?;

    tx.commit().await?;
    Ok(true)
}

#[derive(Debug, Clone, serde::Serialize, sqlx::FromRow)]
pub struct InventoryOverviewItem {
    pub product_id: u32,
    pub variant_id: Option<u32>,
    pub product_name: String,
    pub variant_label: Option<String>,
    pub sku: Option<String>,
    pub stock: i32,
    pub low_stock_threshold: i32,
    pub status: String,
    pub item_type: String,
    pub stock_condition: String,
}

/// Fetch inventory overview list (matching admin/inventory.php subquery)
pub async fn get_inventory_overview(
    pool: &Pool<MySql>,
    page: i64,
    per_page: i64,
    search: Option<&str>,
    stock_filter: Option<&str>,
) -> Result<(Vec<InventoryOverviewItem>, i64), sqlx::Error> {
    let offset = (page.max(1) - 1) * per_page;

    let subquery = "
        SELECT p.id as product_id, NULL as variant_id, p.name as product_name,
               '' as variant_label, p.sku, p.stock, COALESCE(p.low_stock_threshold, 5) as low_stock_threshold,
               p.status, 'simple' as item_type,
               CASE
                   WHEN p.stock <= 0 THEN 'out'
                   WHEN p.stock <= COALESCE(p.low_stock_threshold, 5) THEN 'low'
                   ELSE 'in'
               END as stock_condition
        FROM products p
        WHERE p.product_type = 'simple'

        UNION ALL

        SELECT v.product_id, v.id as variant_id, p.name as product_name,
               COALESCE(
                   (SELECT GROUP_CONCAT(CONCAT(po.name, ': ', pov.value) ORDER BY po.position SEPARATOR ' / ')
                    FROM product_variant_values pvv
                    JOIN product_options po ON po.id = pvv.option_id
                    JOIN product_option_values pov ON pov.id = pvv.option_value_id
                    WHERE pvv.variant_id = v.id),
                   v.sku
               ) as variant_label,
               v.sku, v.stock, COALESCE(p.low_stock_threshold, 5) as low_stock_threshold,
               v.status, 'variant' as item_type,
               CASE
                   WHEN v.stock <= 0 THEN 'out'
                   WHEN v.stock <= COALESCE(p.low_stock_threshold, 5) THEN 'low'
                   ELSE 'in'
               END as stock_condition
        FROM product_variants v
        JOIN products p ON p.id = v.product_id
        WHERE p.product_type = 'variable'
    ";

    let mut where_clause = " WHERE 1=1".to_string();
    if let Some(s) = search.filter(|s| !s.trim().is_empty()) {
        let pattern = format!("%{}%", s.trim());
        where_clause.push_str(&format!(" AND (t.product_name LIKE '{}' OR t.sku LIKE '{}' OR t.variant_label LIKE '{}')", pattern, pattern, pattern));
    }
    if let Some(f) = stock_filter {
        if f == "low" {
            where_clause.push_str(" AND t.stock_condition = 'low'");
        } else if f == "out" {
            where_clause.push_str(" AND t.stock_condition = 'out'");
        }
    }

    let count_sql = format!("SELECT COUNT(*) FROM ({}) t {}", subquery, where_clause);
    let data_sql = format!("SELECT * FROM ({}) t {} ORDER BY t.product_name ASC, t.variant_id ASC LIMIT {} OFFSET {}", subquery, where_clause, per_page, offset);

    let total: (i64,) = sqlx::query_as(&count_sql).fetch_one(pool).await?;
    let items: Vec<InventoryOverviewItem> = sqlx::query_as(&data_sql).fetch_all(pool).await?;

    Ok((items, total.0))
}

#[derive(Debug, Clone, serde::Serialize, sqlx::FromRow)]
pub struct InventoryMovementRow {
    pub id: u64,
    pub product_id: u32,
    pub product_name: String,
    pub variant_id: Option<u32>,
    pub change_qty: i32,
    pub stock_after: Option<i32>,
    pub reason: String,
    pub order_id: Option<u32>,
    pub user_id: Option<u32>,
    pub user_name: Option<String>,
    pub note: Option<String>,
    pub created_at: chrono::NaiveDateTime,
}

/// Fetch inventory movements ledger
pub async fn get_inventory_movements(
    pool: &Pool<MySql>,
    page: i64,
    per_page: i64,
    product_id: Option<u32>,
    reason: Option<&str>,
) -> Result<(Vec<InventoryMovementRow>, i64), sqlx::Error> {
    let offset = (page.max(1) - 1) * per_page;
    let mut where_parts = " WHERE 1=1".to_string();
    if let Some(pid) = product_id {
        where_parts.push_str(&format!(" AND m.product_id = {}", pid));
    }
    if let Some(r) = reason.filter(|r| !r.trim().is_empty()) {
        where_parts.push_str(&format!(" AND m.reason = '{}'", r.trim()));
    }

    let count_sql = format!("SELECT COUNT(*) FROM inventory_movements m {}", where_parts);
    let data_sql = format!(
        "SELECT m.id, m.product_id, p.name AS product_name, m.variant_id, m.change_qty,
                m.stock_after, m.reason, m.order_id, m.user_id, u.name AS user_name, m.note, m.created_at
         FROM inventory_movements m
         JOIN products p ON p.id = m.product_id
         LEFT JOIN users u ON u.id = m.user_id
         {} ORDER BY m.id DESC LIMIT {} OFFSET {}",
        where_parts, per_page, offset
    );

    let total: (i64,) = sqlx::query_as(&count_sql).fetch_one(pool).await?;
    let rows: Vec<InventoryMovementRow> = sqlx::query_as(&data_sql).fetch_all(pool).await?;

    Ok((rows, total.0))
}
