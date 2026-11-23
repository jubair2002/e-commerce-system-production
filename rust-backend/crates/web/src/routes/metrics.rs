use axum::{
    extract::{Query, Request, State},
    http::{header, StatusCode},
    response::{IntoResponse, Response},
};
use rust_decimal::Decimal;
use serde::Deserialize;
use sqlx::Row;
use std::collections::HashMap;

use crate::state::AppState;

#[derive(Debug, Deserialize)]
pub struct MetricsQuery {
    pub token: Option<String>,
}

pub async fn metrics_handler(
    State(state): State<AppState>,
    Query(query): Query<MetricsQuery>,
    req: Request,
) -> Response {
    let required_token = std::env::var("METRICS_TOKEN").unwrap_or_default();
    if !required_token.is_empty() {
        let auth_header = req
            .headers()
            .get(header::AUTHORIZATION)
            .and_then(|h| h.to_str().ok())
            .unwrap_or("");

        let token = if let Some(stripped) = auth_header.strip_prefix("Bearer ") {
            stripped.trim()
        } else if let Some(ref t) = query.token {
            t.trim()
        } else {
            ""
        };

        if token != required_token {
            return (
                StatusCode::UNAUTHORIZED,
                [(header::CONTENT_TYPE, "text/plain; charset=utf-8")],
                "# Unauthorized: Invalid metrics token\n",
            )
                .into_response();
        }
    }

    let pool_size = state.db.size();
    let idle_conns = state.db.num_idle();

    let mut out = String::new();

    // 1. Service & DB up
    out.push_str("# HELP jinish_potro_up Whether the service is up\n# TYPE jinish_potro_up gauge\njinish_potro_up 1\n");
    out.push_str(&format!(
        "# HELP jinish_potro_db_pool_size Total connections in pool\n# TYPE jinish_potro_db_pool_size gauge\njinish_potro_db_pool_size {}\n\
         # HELP jinish_potro_db_pool_idle Idle connections in pool\n# TYPE jinish_potro_db_pool_idle gauge\njinish_potro_db_pool_idle {}\n",
        pool_size, idle_conns
    ));
    out.push_str("# HELP ecommerce_rust_info Rust runtime version metadata\n# TYPE ecommerce_rust_info gauge\necommerce_rust_info{version=\"0.1.0\",runtime=\"tokio-axum\"} 1\n");

    // 2. Query DB Metrics
    let db_ok = sqlx::query("SELECT 1").execute(&state.db).await.is_ok();
    out.push_str(&format!(
        "# HELP ecommerce_database_up MySQL database connection status (1 = connected, 0 = down)\n# TYPE ecommerce_database_up gauge\necommerce_database_up {}\n",
        if db_ok { 1 } else { 0 }
    ));

    if db_ok {
        // Orders by status
        let order_rows = sqlx::query("SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status")
            .fetch_all(&state.db)
            .await
            .unwrap_or_default();
        let mut order_counts: HashMap<String, i64> = HashMap::new();
        for r in order_rows {
            let st: String = r.try_get("status").unwrap_or_default();
            let c: i64 = r.try_get("cnt").unwrap_or(0);
            order_counts.insert(st, c);
        }

        out.push_str("# HELP ecommerce_orders_total Total count of orders by status.\n# TYPE ecommerce_orders_total gauge\n");
        for st in &["pending", "processing", "shipped", "completed", "cancelled"] {
            let cnt = order_counts.get(*st).copied().unwrap_or(0);
            out.push_str(&format!("ecommerce_orders_total{{status=\"{}\"}} {}\n", st, cnt));
        }

        // Revenue from completed orders
        let revenue: Decimal = sqlx::query_scalar(
            "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'completed'",
        )
        .fetch_one(&state.db)
        .await
        .unwrap_or(Decimal::ZERO);
        out.push_str(&format!(
            "# HELP ecommerce_revenue_total Total revenue from completed orders\n# TYPE ecommerce_revenue_total counter\necommerce_revenue_total{{currency=\"BDT\"}} {}\n",
            revenue
        ));

        // Customers total
        let customers: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM customers")
            .fetch_one(&state.db)
            .await
            .unwrap_or(0);
        out.push_str(&format!(
            "# HELP ecommerce_customers_total Total registered customer accounts\n# TYPE ecommerce_customers_total gauge\necommerce_customers_total {}\n",
            customers
        ));

        // Active products
        let products: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM products WHERE status = 'active'")
            .fetch_one(&state.db)
            .await
            .unwrap_or(0);
        out.push_str(&format!(
            "# HELP ecommerce_products_active Total active products in catalog\n# TYPE ecommerce_products_active gauge\necommerce_products_active {}\n",
            products
        ));

        // Low stock products
        let low_stock: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM products WHERE stock <= 5 AND status = 'active'")
            .fetch_one(&state.db)
            .await
            .unwrap_or(0);
        out.push_str(&format!(
            "# HELP ecommerce_products_low_stock Products with stock quantity <= 5\n# TYPE ecommerce_products_low_stock gauge\necommerce_products_low_stock {}\n",
            low_stock
        ));

        // Email queue counts
        let email_rows = sqlx::query("SELECT status, COUNT(*) AS cnt FROM email_queue GROUP BY status")
            .fetch_all(&state.db)
            .await
            .unwrap_or_default();
        let mut email_counts: HashMap<String, i64> = HashMap::new();
        for r in email_rows {
            let st: String = r.try_get("status").unwrap_or_default();
            let c: i64 = r.try_get("cnt").unwrap_or(0);
            email_counts.insert(st, c);
        }
        out.push_str("# HELP ecommerce_email_queue_total Email queue entries by status.\n# TYPE ecommerce_email_queue_total gauge\n");
        for st in &["pending", "sending", "sent", "failed"] {
            let cnt = email_counts.get(*st).copied().unwrap_or(0);
            out.push_str(&format!("ecommerce_email_queue_total{{status=\"{}\"}} {}\n", st, cnt));
        }

        // Storage files count
        let file_rows = sqlx::query("SELECT provider, COUNT(*) AS cnt FROM files WHERE status != 'deleted' GROUP BY provider")
            .fetch_all(&state.db)
            .await
            .unwrap_or_default();
        let mut file_counts: HashMap<String, i64> = HashMap::new();
        for r in file_rows {
            let p: String = r.try_get("provider").unwrap_or_default();
            let c: i64 = r.try_get("cnt").unwrap_or(0);
            file_counts.insert(p, c);
        }
        out.push_str("# HELP ecommerce_storage_files_total Stored media files by provider.\n# TYPE ecommerce_storage_files_total gauge\n");
        for prov in &["local", "s3"] {
            let cnt = file_counts.get(*prov).copied().unwrap_or(0);
            out.push_str(&format!("ecommerce_storage_files_total{{provider=\"{}\"}} {}\n", prov, cnt));
        }

        let pending_sync: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM files WHERE sync_status = 'pending' AND status != 'deleted'")
            .fetch_one(&state.db)
            .await
            .unwrap_or(0);
        out.push_str(&format!(
            "# HELP ecommerce_storage_sync_pending Files pending synchronization to primary storage\n# TYPE ecommerce_storage_sync_pending gauge\necommerce_storage_sync_pending {}\n",
            pending_sync
        ));
    }

    (
        [
            (header::CONTENT_TYPE, "text/plain; version=0.0.4; charset=utf-8"),
            (header::CACHE_CONTROL, "no-cache, no-store, must-revalidate"),
        ],
        out,
    )
        .into_response()
}
