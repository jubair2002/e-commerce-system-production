use sqlx::{MySql, Pool};

/// Write an event into activity_log matching PHP log_activity().
/// Silent on error so logging failures never break the user request.
pub async fn log_activity(
    pool: &Pool<MySql>,
    user_id: Option<u64>,
    action: &str,
    description: &str,
    ip_address: Option<&str>,
) {
    let _ = sqlx::query(
        "INSERT INTO activity_log (user_id, action, description, ip_address, created_at)
         VALUES (?, ?, ?, ?, NOW())"
    )
    .bind(user_id)
    .bind(action)
    .bind(description)
    .bind(ip_address)
    .execute(pool)
    .await;
}

#[derive(Debug, Clone, serde::Serialize, sqlx::FromRow)]
pub struct ActivityLogRow {
    pub id: u32,
    pub user_id: Option<u32>,
    pub user_name: Option<String>,
    pub user_email: Option<String>,
    pub action: String,
    pub description: Option<String>,
    pub ip_address: Option<String>,
    pub created_at: chrono::NaiveDateTime,
}

/// Fetch paginated activity log records
#[allow(clippy::too_many_arguments)]
pub async fn get_activity_logs(
    pool: &Pool<MySql>,
    page: i64,
    per_page: i64,
    search: Option<&str>,
    action_filter: Option<&str>,
    user_id_filter: Option<u32>,
    date_from: Option<&str>,
    date_to: Option<&str>,
) -> Result<(Vec<ActivityLogRow>, i64), sqlx::Error> {
    let offset = (page.max(1) - 1) * per_page;

    let mut count_sql = "SELECT COUNT(*) FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE 1=1".to_string();
    let mut data_sql = "SELECT a.id, a.user_id, u.name AS user_name, u.email AS user_email, a.action, a.description, a.ip_address, a.created_at
                        FROM activity_log a
                        LEFT JOIN users u ON u.id = a.user_id
                        WHERE 1=1".to_string();

    let mut conditions = String::new();
    if let Some(s) = search.filter(|s| !s.trim().is_empty()) {
        let pattern = format!("%{}%", s.trim());
        conditions.push_str(&format!(" AND (a.action LIKE '{}' OR a.description LIKE '{}' OR u.name LIKE '{}')", pattern, pattern, pattern));
    }
    if let Some(act) = action_filter.filter(|a| !a.trim().is_empty()) {
        conditions.push_str(&format!(" AND a.action = '{}'", act.trim()));
    }
    if let Some(uid) = user_id_filter {
        conditions.push_str(&format!(" AND a.user_id = {}", uid));
    }
    if let Some(df) = date_from.filter(|d| !d.trim().is_empty()) {
        conditions.push_str(&format!(" AND a.created_at >= '{} 00:00:00'", df.trim()));
    }
    if let Some(dt) = date_to.filter(|d| !d.trim().is_empty()) {
        conditions.push_str(&format!(" AND a.created_at <= '{} 23:59:59'", dt.trim()));
    }

    count_sql.push_str(&conditions);
    data_sql.push_str(&conditions);
    data_sql.push_str(&format!(" ORDER BY a.id DESC LIMIT {} OFFSET {}", per_page, offset));

    let total: (i64,) = sqlx::query_as(&count_sql).fetch_one(pool).await?;
    let rows: Vec<ActivityLogRow> = sqlx::query_as(&data_sql).fetch_all(pool).await?;

    Ok((rows, total.0))
}
