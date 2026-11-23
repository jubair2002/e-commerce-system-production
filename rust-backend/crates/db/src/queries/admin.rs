#![allow(clippy::type_complexity)]

use std::collections::HashMap;
use chrono::{NaiveDate, Utc};
use rust_decimal::Decimal;
use sqlx::{MySql, Pool};

#[derive(Debug, Clone, serde::Serialize)]
pub struct AdminDashboardStats {
    pub total_products: i64,
    pub active_products: i64,
    pub out_of_stock: i64,
    pub total_categories: i64,
    pub featured_count: i64,
    pub inventory_value: f64,
    pub low_stock: Vec<serde_json::Value>,
    pub recent_products: Vec<serde_json::Value>,
    pub total_orders: i64,
    pub pending_orders: i64,
    pub total_revenue: f64,
    pub today_revenue: f64,
    pub recent_orders: Vec<serde_json::Value>,
    pub rev_labels: Vec<String>,
    pub rev_values: Vec<f64>,
    pub order_values: Vec<i64>,
    pub total_customers: i64,
    pub new_customers_7d: i64,
    pub repeat_customers: i64,
    pub total_subscribers: i64,
    pub new_messages_count: i64,
    pub recent_activity: Vec<serde_json::Value>,
}

/// Fetch KPIs and charts for dashboard.php
pub async fn get_dashboard_stats(pool: &Pool<MySql>) -> Result<AdminDashboardStats, sqlx::Error> {
    let total_products: (i64,) = sqlx::query_as("SELECT COUNT(*) FROM products")
        .fetch_one(pool).await.unwrap_or((0,));
    let active_products: (i64,) = sqlx::query_as("SELECT COUNT(*) FROM products WHERE status = 'active'")
        .fetch_one(pool).await.unwrap_or((0,));
    let out_of_stock: (i64,) = sqlx::query_as("SELECT COUNT(*) FROM products WHERE stock <= 0")
        .fetch_one(pool).await.unwrap_or((0,));
    let total_categories: (i64,) = sqlx::query_as("SELECT COUNT(*) FROM categories")
        .fetch_one(pool).await.unwrap_or((0,));
    let featured_count: (i64,) = sqlx::query_as("SELECT COUNT(*) FROM products WHERE featured = 1")
        .fetch_one(pool).await.unwrap_or((0,));

    let inv_val_row: (Option<Decimal>,) = sqlx::query_as(
        "SELECT COALESCE(SUM(stock * COALESCE(sale_price, price)), 0) FROM products"
    ).fetch_one(pool).await.unwrap_or((None,));
    let inventory_value = inv_val_row.0.map(|d| d.to_string().parse::<f64>().unwrap_or(0.0)).unwrap_or(0.0);

    let low_stock_rows: Vec<(u32, String, Option<String>, i32, Option<String>)> = sqlx::query_as(
        "SELECT id, name, sku, stock, image FROM products WHERE stock > 0 AND stock <= 10 ORDER BY stock ASC LIMIT 6"
    ).fetch_all(pool).await.unwrap_or_default();
    let low_stock: Vec<serde_json::Value> = low_stock_rows.into_iter().map(|(id, name, sku, stock, image)| {
        serde_json::json!({
            "id": id, "name": name, "sku": sku, "stock": stock, "image": image
        })
    }).collect();

    let recent_prod_rows: Vec<(u32, String, Decimal, Option<Decimal>, i32, Option<String>, chrono::NaiveDateTime, Option<String>)> = sqlx::query_as(
        "SELECT p.id, p.name, p.price, p.sale_price, p.stock, p.image, p.created_at, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         ORDER BY p.created_at DESC LIMIT 6"
    ).fetch_all(pool).await.unwrap_or_default();
    let recent_products: Vec<serde_json::Value> = recent_prod_rows.into_iter().map(|(id, name, pr, sp, stock, img, cat, cname)| {
        serde_json::json!({
            "id": id, "name": name, "price": pr.to_string(), "sale_price": sp.map(|s| s.to_string()),
            "stock": stock, "image": img, "created_at": cat.format("%b %d, %Y").to_string(),
            "category_name": cname
        })
    }).collect();

    let total_orders: (i64,) = sqlx::query_as("SELECT COUNT(*) FROM orders")
        .fetch_one(pool).await.unwrap_or((0,));
    let pending_orders: (i64,) = sqlx::query_as("SELECT COUNT(*) FROM orders WHERE status = 'pending'")
        .fetch_one(pool).await.unwrap_or((0,));

    let total_rev_row: (Option<Decimal>,) = sqlx::query_as(
        "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status NOT IN ('cancelled')"
    ).fetch_one(pool).await.unwrap_or((None,));
    let total_revenue = total_rev_row.0.map(|d| d.to_string().parse::<f64>().unwrap_or(0.0)).unwrap_or(0.0);

    let today_rev_row: (Option<Decimal>,) = sqlx::query_as(
        "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status NOT IN ('cancelled') AND DATE(created_at) = CURDATE()"
    ).fetch_one(pool).await.unwrap_or((None,));
    let today_revenue = today_rev_row.0.map(|d| d.to_string().parse::<f64>().unwrap_or(0.0)).unwrap_or(0.0);

    let recent_order_rows: Vec<(u64, String, String, Decimal, String, chrono::NaiveDateTime)> = sqlx::query_as(
        "SELECT id, order_number, customer_name, total, status, created_at
         FROM orders ORDER BY created_at DESC LIMIT 6"
    ).fetch_all(pool).await.unwrap_or_default();
    let recent_orders: Vec<serde_json::Value> = recent_order_rows.into_iter().map(|(id, on, cn, tot, st, dt)| {
        serde_json::json!({
            "id": id, "order_number": on, "customer_name": cn, "total": tot.to_string(),
            "status": st, "created_at": dt.format("%b %d, %Y %H:%M").to_string()
        })
    }).collect();

    // 7-day revenue & order trends
    let rev_rows: Vec<(NaiveDate, Option<Decimal>, i64)> = sqlx::query_as(
        "SELECT DATE(created_at) AS d, COALESCE(SUM(total), 0) AS total_rev, COUNT(*) AS total_orders
         FROM orders
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
           AND status != 'cancelled'
         GROUP BY DATE(created_at)"
    ).fetch_all(pool).await.unwrap_or_default();

    let mut by_date_rev: HashMap<String, f64> = HashMap::new();
    let mut by_date_orders: HashMap<String, i64> = HashMap::new();
    for (d, rev, cnt) in rev_rows {
        let key = d.format("%Y-%m-%d").to_string();
        by_date_rev.insert(key.clone(), rev.map(|r| r.to_string().parse::<f64>().unwrap_or(0.0)).unwrap_or(0.0));
        by_date_orders.insert(key, cnt);
    }

    let mut rev_labels = Vec::new();
    let mut rev_values = Vec::new();
    let mut order_values = Vec::new();
    let today = Utc::now().date_naive();
    for i in (0..=6).rev() {
        let day = today - chrono::Duration::days(i);
        let key = day.format("%Y-%m-%d").to_string();
        rev_labels.push(day.format("%b %e").to_string());
        rev_values.push(*by_date_rev.get(&key).unwrap_or(&0.0));
        order_values.push(*by_date_orders.get(&key).unwrap_or(&0));
    }

    let total_customers: (i64,) = sqlx::query_as(
        "SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL"
    ).fetch_one(pool).await.unwrap_or((0,));

    let new_customers_7d: (i64,) = sqlx::query_as(
        "SELECT COUNT(*) FROM customers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL"
    ).fetch_one(pool).await.unwrap_or((0,));

    let repeat_customers: (i64,) = sqlx::query_as(
        "SELECT COUNT(*) FROM (
            SELECT customer_id FROM orders 
            WHERE customer_id IS NOT NULL AND status != 'cancelled'
            GROUP BY customer_id HAVING COUNT(*) >= 2
         ) t"
    ).fetch_one(pool).await.unwrap_or((0,));

    let total_subscribers: (i64,) = sqlx::query_as(
        "SELECT COUNT(*) FROM newsletter_subscribers WHERE status IN ('active', 'subscribed')"
    ).fetch_one(pool).await.unwrap_or((0,));

    let new_messages_count: (i64,) = sqlx::query_as(
        "SELECT COUNT(*) FROM contact_messages WHERE status = 'new'"
    ).fetch_one(pool).await.unwrap_or((0,));

    let act_rows: Vec<(u32, Option<u32>, Option<String>, String, Option<String>, chrono::NaiveDateTime)> = sqlx::query_as(
        "SELECT a.id, a.user_id, u.name AS user_name, a.action, a.description, a.created_at
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         ORDER BY a.id DESC LIMIT 8"
    ).fetch_all(pool).await.unwrap_or_default();
    let recent_activity: Vec<serde_json::Value> = act_rows.into_iter().map(|(id, uid, uname, act, desc, dt)| {
        serde_json::json!({
            "id": id, "user_id": uid, "user_name": uname.unwrap_or_else(|| "System".to_string()),
            "action": act, "description": desc.unwrap_or_default(),
            "created_at": dt.format("%b %d, %H:%M").to_string()
        })
    }).collect();

    Ok(AdminDashboardStats {
        total_products: total_products.0,
        active_products: active_products.0,
        out_of_stock: out_of_stock.0,
        total_categories: total_categories.0,
        featured_count: featured_count.0,
        inventory_value,
        low_stock,
        recent_products,
        total_orders: total_orders.0,
        pending_orders: pending_orders.0,
        total_revenue,
        today_revenue,
        recent_orders,
        rev_labels,
        rev_values,
        order_values,
        total_customers: total_customers.0,
        new_customers_7d: new_customers_7d.0,
        repeat_customers: repeat_customers.0,
        total_subscribers: total_subscribers.0,
        new_messages_count: new_messages_count.0,
        recent_activity,
    })
}

#[derive(Debug, Clone, serde::Serialize)]
pub struct AdminNotificationCounts {
    pub pending_orders: i64,
    pub low_stock: i64,
    pub failed_mail: i64,
    pub raw_count: i64,
    pub health_color: String,
    pub health_title: String,
}

/// Fetch real-time notification alerts and system health status for admin header
pub async fn get_admin_notifications(
    pool: &Pool<MySql>,
    low_stock_thresh: i64,
) -> AdminNotificationCounts {
    let pending_orders: i64 = sqlx::query_as::<_, (i64,)>("SELECT COUNT(*) FROM orders WHERE status = 'pending'")
        .fetch_one(pool).await.map(|r| r.0).unwrap_or(0);

    let low_stock: i64 = sqlx::query_as::<_, (i64,)>(
        "SELECT COUNT(*) FROM products WHERE status = 'active' AND track_inventory = 1 AND stock <= ?"
    )
    .bind(low_stock_thresh)
    .fetch_one(pool).await.map(|r| r.0).unwrap_or(0);

    let failed_mail: i64 = sqlx::query_as::<_, (i64,)>("SELECT COUNT(*) FROM email_queue WHERE status = 'failed'")
        .fetch_one(pool).await.map(|r| r.0).unwrap_or(0);

    let raw_count = pending_orders + low_stock + failed_mail;

    let mut health_color = "secondary".to_string();
    let mut health_title = "System Health: Initializing (Collector starting up...)".to_string();

    let sm_row: Option<(chrono::NaiveDateTime, Option<f32>, Option<u64>, Option<u64>, Option<u64>, Option<u64>)> = sqlx::query_as(
        "SELECT collected_at, cpu_percent, mem_used, mem_total, disk_used, disk_total FROM system_metrics ORDER BY id DESC LIMIT 1"
    ).fetch_optional(pool).await.unwrap_or(None);

    if let Some((collected_at, cpu, mem_used, mem_total, disk_used, disk_total)) = sm_row {
        let cpu_val = cpu.unwrap_or(0.0);
        let mem_t = mem_total.unwrap_or(1) as f32;
        let mem_u = mem_used.unwrap_or(0) as f32;
        let ram_pct = if mem_t > 0.0 { (mem_u / mem_t) * 100.0 } else { 0.0 };
        let disk_t = disk_total.unwrap_or(1) as f32;
        let disk_u = disk_used.unwrap_or(0) as f32;
        let disk_pct = if disk_t > 0.0 { (disk_u / disk_t) * 100.0 } else { 0.0 };

        let lag = Utc::now().naive_utc().signed_duration_since(collected_at).num_seconds();
        if lag > 300 {
            health_color = "warning".to_string();
            health_title = format!("System Health: Stale Data (Last collected {}m ago)", lag / 60);
        } else if cpu_val >= 90.0 || ram_pct >= 90.0 || disk_pct >= 95.0 {
            health_color = "danger".to_string();
            health_title = format!("System Health: Warning (CPU {}%, RAM {}%, Disk {}%)", cpu_val.round(), ram_pct.round(), disk_pct.round());
        } else if cpu_val >= 75.0 || ram_pct >= 75.0 || disk_pct >= 85.0 {
            health_color = "warning".to_string();
            health_title = format!("System Health: Elevated Load (CPU {}%, RAM {}%, Disk {}%)", cpu_val.round(), ram_pct.round(), disk_pct.round());
        } else {
            health_color = "success".to_string();
            health_title = format!("System Health: Good (CPU {}%, RAM {}%, Disk {}%)", cpu_val.round(), ram_pct.round(), disk_pct.round());
        }
    }

    AdminNotificationCounts {
        pending_orders,
        low_stock,
        failed_mail,
        raw_count,
        health_color,
        health_title,
    }
}

// -----------------------------------------------------------------
// Categories Administration
// -----------------------------------------------------------------

#[derive(Debug, Clone, serde::Serialize, sqlx::FromRow)]
pub struct AdminCategoryRow {
    pub id: u32,
    pub name: String,
    pub slug: String,
    pub parent_id: Option<u32>,
    pub description: Option<String>,
    pub status: String,
    pub product_count: i64,
    pub created_at: chrono::NaiveDateTime,
}

pub async fn get_categories_admin(
    pool: &Pool<MySql>,
    page: i64,
    per_page: i64,
    search: Option<&str>,
    status: Option<&str>,
) -> Result<(Vec<AdminCategoryRow>, i64), sqlx::Error> {
    let offset = (page.max(1) - 1) * per_page;
    let mut where_parts = " WHERE 1=1".to_string();
    if let Some(q) = search.filter(|s| !s.trim().is_empty()) {
        where_parts.push_str(&format!(" AND c.name LIKE '%{}%'", q.trim()));
    }
    if let Some(st) = status.filter(|s| *s == "active" || *s == "inactive") {
        where_parts.push_str(&format!(" AND c.status = '{}'", st));
    }

    let count_sql = format!("SELECT COUNT(*) FROM categories c {}", where_parts);
    let data_sql = format!(
        "SELECT c.id, c.name, c.slug, c.parent_id, c.description, c.status,
                (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count,
                c.created_at
         FROM categories c
         {} ORDER BY c.created_at DESC LIMIT {} OFFSET {}",
        where_parts, per_page, offset
    );

    let total: (i64,) = sqlx::query_as(&count_sql).fetch_one(pool).await?;
    let rows: Vec<AdminCategoryRow> = sqlx::query_as(&data_sql).fetch_all(pool).await?;
    Ok((rows, total.0))
}

pub async fn save_category(
    pool: &Pool<MySql>,
    id: Option<u32>,
    name: &str,
    slug: &str,
    parent_id: Option<u32>,
    description: Option<&str>,
    status: &str,
) -> Result<u32, sqlx::Error> {
    let final_slug = if slug.trim().is_empty() {
        common::formatters::slugify(name)
    } else {
        common::formatters::slugify(slug)
    };

    let unique_slug = get_unique_slug(pool, &final_slug, "categories", id.unwrap_or(0) as u64).await?;

    if let Some(cat_id) = id {
        sqlx::query(
            "UPDATE categories SET name = ?, slug = ?, parent_id = ?, description = ?, status = ?, updated_at = NOW() WHERE id = ?"
        )
        .bind(name)
        .bind(&unique_slug)
        .bind(parent_id)
        .bind(description)
        .bind(status)
        .bind(cat_id)
        .execute(pool)
        .await?;
        Ok(cat_id)
    } else {
        let res = sqlx::query(
            "INSERT INTO categories (name, slug, parent_id, description, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())"
        )
        .bind(name)
        .bind(&unique_slug)
        .bind(parent_id)
        .bind(description)
        .bind(status)
        .execute(pool)
        .await?;
        Ok(res.last_insert_id() as u32)
    }
}

pub async fn delete_category(pool: &Pool<MySql>, id: u32) -> Result<Option<String>, sqlx::Error> {
    let row: Option<(String,)> = sqlx::query_as("SELECT name FROM categories WHERE id = ?")
        .bind(id)
        .fetch_optional(pool)
        .await?;

    if let Some((name,)) = row {
        sqlx::query("DELETE FROM categories WHERE id = ?")
            .bind(id)
            .execute(pool)
            .await?;
        Ok(Some(name))
    } else {
        Ok(None)
    }
}

// -----------------------------------------------------------------
// Slug helper matching unique_slug()
// -----------------------------------------------------------------
pub async fn get_unique_slug(
    pool: &Pool<MySql>,
    base: &str,
    table: &str,
    exclude_id: u64,
) -> Result<String, sqlx::Error> {
    let mut slug = base.to_string();
    let mut i = 1;
    let query_str = format!("SELECT id FROM `{}` WHERE slug = ? AND id <> ? LIMIT 1", table);

    loop {
        let exists: Option<(u64,)> = sqlx::query_as(&query_str)
            .bind(&slug)
            .bind(exclude_id)
            .fetch_optional(pool)
            .await?;

        if exists.is_none() {
            return Ok(slug);
        }
        i += 1;
        slug = format!("{}-{}", base, i);
    }
}
