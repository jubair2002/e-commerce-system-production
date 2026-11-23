use dashmap::DashMap;
use sqlx::{MySqlPool, Row};
use std::sync::Arc;

#[derive(Clone, Debug)]
pub struct SettingsRepository {
    cache: Arc<DashMap<String, String>>,
}

impl SettingsRepository {
    pub async fn load(pool: &MySqlPool) -> Result<Self, sqlx::Error> {
        let repo = Self {
            cache: Arc::new(DashMap::new()),
        };
        repo.refresh(pool).await?;
        Ok(repo)
    }

    pub async fn refresh(&self, pool: &MySqlPool) -> Result<(), sqlx::Error> {
        let rows = sqlx::query("SELECT key_name, value FROM settings")
            .fetch_all(pool)
            .await?;

        self.cache.clear();
        for row in rows {
            let key: String = row.try_get("key_name")?;
            let val: Option<String> = row.try_get("value")?;
            if let Some(v) = val {
                self.cache.insert(key, v);
            }
        }
        Ok(())
    }

    pub fn get(&self, key: &str) -> Option<String> {
        self.cache.get(key).map(|v| v.clone())
    }

    pub fn get_or(&self, key: &str, default: &str) -> String {
        self.cache
            .get(key)
            .map(|v| v.clone())
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
                s == "1" || s.eq_ignore_ascii_case("true")
            })
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
