use axum::{
    extract::{Query, State},
    response::{IntoResponse, Json, Response},
    Form,
};
use chrono::Utc;
use db::queries::activity::log_activity;
use db::queries::inventory::stock_adjust;
use rust_decimal::Decimal;
use serde::Deserialize;
use serde_json::json;
use std::fs;
use tower_sessions::Session;

use crate::state::{AppState, SessionHelper};

// =================================================================
// 1. /admin/ajax/stock_update.php
// =================================================================

#[derive(Debug, Deserialize)]
pub struct StockGetQuery {
    pub action: Option<String>,
    pub product_id: Option<u64>,
}

#[derive(Debug, Deserialize)]
pub struct StockUpdateForm {
    pub product_id: Option<u64>,
    pub variant_id: Option<u64>,
    pub delta: Option<i32>,
    pub new_stock: Option<i32>,
    pub note: Option<String>,
}

pub async fn admin_ajax_stock_update_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<StockGetQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Json(json!({"ok": false, "error": "Unauthorized"})).into_response(),
    };

    if !admin.has_permission("inventory.view") && !admin.has_permission("inventory.manage") && !admin.has_permission("products.edit") && admin.role != "admin" {
        return Json(json!({"ok": false, "error": "Access denied"})).into_response();
    }

    let pid = query.product_id.unwrap_or(0);
    let prod_row: Option<(String, String, i32)> = sqlx::query_as(
        "SELECT name, product_type, stock FROM products WHERE id = ?"
    )
    .bind(pid)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let (pname, ptype, stock) = match prod_row {
        Some(p) => p,
        None => return Json(json!({"ok": false, "error": "Product not found."})).into_response(),
    };

    #[derive(sqlx::FromRow)]
    struct VariantStockRow {
        id: u64,
        sku: Option<String>,
        stock: i32,
        status: String,
    }

    let variants: Vec<VariantStockRow> = if ptype == "variable" {
        sqlx::query_as("SELECT id, sku, stock, status FROM product_variants WHERE product_id = ? ORDER BY position ASC, id ASC")
            .bind(pid)
            .fetch_all(&state.db)
            .await
            .unwrap_or_default()
    } else {
        Vec::new()
    };

    let total_stock: i64 = if ptype == "variable" {
        sqlx::query_scalar("SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = ? AND status = 'active'")
            .bind(pid)
            .fetch_one(&state.db)
            .await
            .unwrap_or(0)
    } else {
        stock as i64
    };

    let variants_json: Vec<serde_json::Value> = variants
        .into_iter()
        .map(|v| {
            json!({
                "id": v.id,
                "label": format!("Variant #{}", v.id),
                "sku": v.sku.unwrap_or_default(),
                "stock": v.stock,
                "status": v.status,
            })
        })
        .collect();

    Json(json!({
        "ok": true,
        "product_id": pid,
        "product_name": pname,
        "product_type": ptype,
        "stock": stock,
        "total_stock": total_stock,
        "variants": variants_json,
    }))
    .into_response()
}

pub async fn admin_ajax_stock_update_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<StockUpdateForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Json(json!({"ok": false, "error": "Unauthorized"})).into_response(),
    };

    if !admin.has_permission("inventory.manage") && !admin.has_permission("products.edit") && admin.role != "admin" {
        return Json(json!({"ok": false, "error": "Access denied"})).into_response();
    }

    let pid = form.product_id.unwrap_or(0);
    let vid = form.variant_id;
    let note = form.note.unwrap_or_else(|| "Quick stock update via admin modal".to_string());

    let prod_row: Option<(String, String, i32)> = sqlx::query_as(
        "SELECT name, product_type, stock FROM products WHERE id = ?"
    )
    .bind(pid)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let (_pname, ptype, current_pstock) = match prod_row {
        Some(p) => p,
        None => return Json(json!({"ok": false, "error": "Product not found."})).into_response(),
    };

    let delta = if let Some(d) = form.delta {
        d
    } else if let Some(target) = form.new_stock {
        let cur = if let Some(v_id) = vid {
            let v_stock: Option<(i32,)> = sqlx::query_as("SELECT stock FROM product_variants WHERE id = ?")
                .bind(v_id)
                .fetch_optional(&state.db)
                .await
                .unwrap_or(None);
            v_stock.map(|(s,)| s).unwrap_or(0)
        } else {
            current_pstock
        };
        target - cur
    } else {
        0
    };

    if delta == 0 {
        return Json(json!({
            "ok": true,
            "delta": 0,
            "message": "No change in stock."
        })).into_response();
    }

    let res = stock_adjust(
        &state.db,
        pid,
        vid,
        delta,
        "manual_adjust",
        None,
        Some(admin.id),
        Some(&note),
    )
    .await;

    match res {
        Ok(_) => {
            log_activity(
                &state.db,
                Some(admin.id),
                "stock.adjust",
                &format!("Adjusted stock for product #{} (variant {:?}) delta: {}", pid, vid, delta),
                None,
            )
            .await;

            let new_stock: i32 = if let Some(v_id) = vid {
                let v_s: Option<(i32,)> = sqlx::query_as("SELECT stock FROM product_variants WHERE id = ?")
                    .bind(v_id)
                    .fetch_optional(&state.db)
                    .await
                    .unwrap_or(None);
                v_s.map(|(s,)| s).unwrap_or(0)
            } else {
                let p_s: Option<(i32,)> = sqlx::query_as("SELECT stock FROM products WHERE id = ?")
                    .bind(pid)
                    .fetch_optional(&state.db)
                    .await
                    .unwrap_or(None);
                p_s.map(|(s,)| s).unwrap_or(0)
            };

            let total_stock: i64 = if ptype == "variable" {
                sqlx::query_scalar("SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = ? AND status = 'active'")
                    .bind(pid)
                    .fetch_one(&state.db)
                    .await
                    .unwrap_or(0)
            } else {
                new_stock as i64
            };

            Json(json!({
                "ok": true,
                "delta": delta,
                "new_stock": new_stock,
                "total_stock": total_stock,
            }))
            .into_response()
        }
        Err(e) => Json(json!({
            "ok": false,
            "error": format!("Failed to adjust stock: {}", e)
        })).into_response(),
    }
}

// =================================================================
// 2. /admin/ajax/option_presets.php
// =================================================================

#[derive(Debug, Deserialize)]
pub struct OptionPresetsQuery {
    pub action: Option<String>,
}

pub async fn admin_ajax_option_presets_handler(
    State(state): State<AppState>,
    session: Session,
    Query(_query): Query<OptionPresetsQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Json(json!({"ok": false, "error": "Unauthorized"})).into_response(),
    };

    if !admin.has_permission("products.create") && !admin.has_permission("products.edit") && admin.role != "admin" {
        return Json(json!({"ok": false, "error": "Access denied"})).into_response();
    }

    #[derive(sqlx::FromRow)]
    struct PresetRow {
        id: u64,
        name: String,
        display_type: String,
        position: i32,
    }

    #[derive(sqlx::FromRow)]
    struct PresetValRow {
        id: u64,
        preset_id: u64,
        value: String,
        swatch_hex: Option<String>,
        position: i32,
    }

    let presets = sqlx::query_as::<_, PresetRow>(
        "SELECT id, name, display_type, position FROM option_presets ORDER BY position ASC, id ASC"
    )
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let vals = sqlx::query_as::<_, PresetValRow>(
        "SELECT id, preset_id, value, swatch_hex, position FROM option_preset_values ORDER BY position ASC, id ASC"
    )
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let mut result = Vec::new();
    for p in presets {
        let pvals: Vec<serde_json::Value> = vals
            .iter()
            .filter(|v| v.preset_id == p.id)
            .map(|v| {
                json!({
                    "id": v.id,
                    "value": v.value,
                    "swatch_hex": v.swatch_hex,
                    "position": v.position,
                })
            })
            .collect();

        result.push(json!({
            "id": p.id,
            "name": p.name,
            "display_type": p.display_type,
            "position": p.position,
            "values": pvals,
        }));
    }

    Json(json!({
        "ok": true,
        "presets": result,
    }))
    .into_response()
}

// =================================================================
// 3. /admin/ajax/monitor_data.php
// =================================================================

#[derive(Debug, Deserialize)]
pub struct MonitorDataQuery {
    pub range: Option<String>,
    pub format: Option<String>,
}

pub async fn admin_ajax_monitor_data_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<MonitorDataQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Json(json!({"ok": false, "error": "Unauthorized"})).into_response(),
    };

    if !admin.has_permission("monitoring.view") && admin.role != "admin" {
        return Json(json!({"ok": false, "error": "Access denied"})).into_response();
    }

    // Read CPU loadavg
    let loadavg = fs::read_to_string("/proc/loadavg")
        .unwrap_or_else(|_| "0.00 0.00 0.00".to_string())
        .split_whitespace()
        .take(3)
        .map(|s| s.to_string())
        .collect::<Vec<_>>();

    // Read Meminfo
    let mut mem_total_kb = 0u64;
    let mut mem_avail_kb = 0u64;
    if let Ok(meminfo) = fs::read_to_string("/proc/meminfo") {
        for line in meminfo.lines() {
            if line.starts_with("MemTotal:") {
                mem_total_kb = line.split_whitespace().nth(1).and_then(|v| v.parse().ok()).unwrap_or(0);
            } else if line.starts_with("MemAvailable:") {
                mem_avail_kb = line.split_whitespace().nth(1).and_then(|v| v.parse().ok()).unwrap_or(0);
            }
        }
    }
    let mem_used_kb = mem_total_kb.saturating_sub(mem_avail_kb);
    let mem_pct = if mem_total_kb > 0 {
        (mem_used_kb as f64 / mem_total_kb as f64) * 100.0
    } else {
        0.0
    };

    // MySQL stats
    let threads_conn: String = sqlx::query_scalar("SHOW STATUS LIKE 'Threads_connected'")
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None)
        .unwrap_or_else(|| "1".to_string());

    let uptime: String = sqlx::query_scalar("SHOW STATUS LIKE 'Uptime'")
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None)
        .unwrap_or_else(|| "0".to_string());

    let slow_queries: String = sqlx::query_scalar("SHOW STATUS LIKE 'Slow_queries'")
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None)
        .unwrap_or_else(|| "0".to_string());

    Json(json!({
        "ok": true,
        "range": query.range.unwrap_or_else(|| "1h".to_string()),
        "timestamp": Utc::now().to_rfc3339(),
        "cpu": {
            "load_1m": loadavg.first().cloned().unwrap_or_default(),
            "load_5m": loadavg.get(1).cloned().unwrap_or_default(),
            "load_15m": loadavg.get(2).cloned().unwrap_or_default(),
        },
        "memory": {
            "total_mb": mem_total_kb / 1024,
            "used_mb": mem_used_kb / 1024,
            "percent": format!("{:.1}%", mem_pct),
        },
        "mysql": {
            "threads_connected": threads_conn,
            "uptime_seconds": uptime,
            "slow_queries": slow_queries,
        }
    }))
    .into_response()
}

// =================================================================
// 4. /admin/ajax/analytics_data.php
// =================================================================

#[derive(Debug, Deserialize)]
pub struct AnalyticsDataQuery {
    pub from: Option<String>,
    pub to: Option<String>,
    pub group_by: Option<String>,
}

pub async fn admin_ajax_analytics_data_handler(
    State(state): State<AppState>,
    session: Session,
    Query(_query): Query<AnalyticsDataQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Json(json!({"ok": false, "error": "Unauthorized"})).into_response(),
    };

    if !admin.has_permission("analytics.view") && admin.role != "admin" {
        return Json(json!({"ok": false, "error": "Access denied"})).into_response();
    }

    let total_sales: Decimal = sqlx::query_scalar("SELECT COALESCE(SUM(total), 0.00) FROM orders WHERE status != 'cancelled'")
        .fetch_one(&state.db)
        .await
        .unwrap_or(Decimal::ZERO);

    let total_orders: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM orders")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    let total_customers: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL")
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);

    Json(json!({
        "ok": true,
        "kpis": {
            "total_sales": total_sales.to_string(),
            "total_orders": total_orders,
            "total_customers": total_customers,
        }
    }))
    .into_response()
}
