use sqlx::mysql::{MySqlPool, MySqlPoolOptions};
use std::time::Duration;

pub async fn create_pool(database_url: &str) -> Result<MySqlPool, sqlx::Error> {
    create_pool_with_tz(database_url, "+06:00").await
}

pub async fn create_pool_with_tz(database_url: &str, tz_offset: &str) -> Result<MySqlPool, sqlx::Error> {
    let tz = tz_offset.to_string();
    MySqlPoolOptions::new()
        .max_connections(25)
        .min_connections(5)
        .acquire_timeout(Duration::from_secs(10))
        .idle_timeout(Duration::from_secs(600))
        .after_connect(move |conn, _meta| {
            let tz_cmd = format!("SET time_zone = '{tz}'");
            Box::pin(async move {
                sqlx::query(&tz_cmd).execute(conn).await?;
                Ok(())
            })
        })
        .connect(database_url)
        .await
}
