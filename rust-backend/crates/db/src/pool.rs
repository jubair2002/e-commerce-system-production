use sqlx::mysql::{MySqlPool, MySqlPoolOptions};
use std::time::Duration;

pub async fn create_pool(database_url: &str) -> Result<MySqlPool, sqlx::Error> {
    MySqlPoolOptions::new()
        .max_connections(25)
        .min_connections(5)
        .acquire_timeout(Duration::from_secs(10))
        .idle_timeout(Duration::from_secs(600))
        .connect(database_url)
        .await
}
