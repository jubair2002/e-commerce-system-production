use chrono::NaiveDateTime;
use moka::sync::Cache;
use rust_decimal::Decimal;
use sqlx::{MySqlPool, Row};
use std::str::FromStr;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::Arc;
use std::time::Duration;
use tokio::sync::RwLock;

#[derive(Clone, Debug)]
pub struct SettingsRepository {
    cache: Cache<String, String>,
    last_max_updated_at: Arc<RwLock<Option<NaiveDateTime>>>,
    last_checked_at: Arc<AtomicI64>,
}

impl SettingsRepository {
    pub async fn load(pool: &MySqlPool) -> Result<Self, sqlx::Error> {
        let cache = Cache::builder()
            .time_to_live(Duration::from_secs(10))
            .max_capacity(500)
            .build();

        let repo = Self {
            cache,
            last_max_updated_at: Arc::new(RwLock::new(None)),
            last_checked_at: Arc::new(AtomicI64::new(0)),
        };
        repo.refresh(pool).await?;
        Ok(repo)
    }

    pub async fn refresh(&self, pool: &MySqlPool) -> Result<(), sqlx::Error> {
        let rows = sqlx::query("SELECT key_name, value, updated_at FROM settings")
            .fetch_all(pool)
            .await?;

        let mut max_dt: Option<NaiveDateTime> = None;
        for row in rows {
            let key: String = row.try_get("key_name")?;
            let val: Option<String> = row.try_get("value")?;
            let updated_at: Option<NaiveDateTime> = row.try_get("updated_at").ok();

            if let Some(dt) = updated_at {
                max_dt = match max_dt {
                    Some(m) if dt > m => Some(dt),
                    None => Some(dt),
                    other => other,
                };
            }

            if let Some(v) = val {
                self.cache.insert(key, v);
            }
        }

        let mut lock = self.last_max_updated_at.write().await;
        *lock = max_dt;
        let now = chrono::Utc::now().timestamp();
        self.last_checked_at.store(now, Ordering::Relaxed);

        Ok(())
    }

    /// Check if settings were updated in MySQL (e.g. by PHP admin) and refresh if needed
    pub async fn check_updated_at(&self, pool: &MySqlPool) -> Result<(), sqlx::Error> {
        let now = chrono::Utc::now().timestamp();
        let last = self.last_checked_at.load(Ordering::Relaxed);
        // Throttle check query to at most once per 2 seconds
        if now - last < 2 {
            return Ok(());
        }
        self.last_checked_at.store(now, Ordering::Relaxed);

        let row = sqlx::query("SELECT MAX(updated_at) as max_up FROM settings")
            .fetch_optional(pool)
            .await?;

        if let Some(r) = row {
            let db_max: Option<NaiveDateTime> = r.try_get("max_up")?;
            let current_max = *self.last_max_updated_at.read().await;

            if db_max != current_max {
                self.refresh(pool).await?;
            }
        }

        Ok(())
    }

    pub fn get(&self, key: &str) -> Option<String> {
        self.cache.get(key)
    }

    pub fn get_or(&self, key: &str, default: &str) -> String {
        self.cache
            .get(key)
            .unwrap_or_else(|| default.to_string())
    }

    pub fn get_int(&self, key: &str, default: i64) -> i64 {
        self.cache
            .get(key)
            .and_then(|v| v.parse::<i64>().ok())
            .unwrap_or(default)
    }

    pub fn get_bool(&self, key: &str, default: bool) -> bool {
        self.cache
            .get(key)
            .map(|v| {
                let s = v.trim();
                s == "1" || s.eq_ignore_ascii_case("true") || s.eq_ignore_ascii_case("yes") || s.eq_ignore_ascii_case("on")
            })
            .unwrap_or(default)
    }

    pub fn get_decimal(&self, key: &str, default: Decimal) -> Decimal {
        self.cache
            .get(key)
            .and_then(|v| Decimal::from_str(v.trim()).ok())
            .unwrap_or(default)
    }

    pub async fn set(&self, pool: &MySqlPool, key: &str, value: &str) -> Result<(), sqlx::Error> {
        sqlx::query(
            "INSERT INTO settings (key_name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
        )
        .bind(key)
        .bind(value)
        .execute(pool)
        .await?;

        self.cache.insert(key.to_string(), value.to_string());
        Ok(())
    }
}
