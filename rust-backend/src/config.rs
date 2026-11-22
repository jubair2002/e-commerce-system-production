use std::env;

#[derive(Clone, Debug)]
pub struct AppConfig {
    pub db_host: String,
    pub db_port: u16,
    pub db_name: String,
    pub db_user: String,
    pub db_pass: String,
    pub db_charset: String,
    pub app_name: String,
    pub app_env: String,
    pub app_key: String,
    pub app_timezone: String,
    pub base_url: String,
    pub site_url: String,
    pub app_url: String,
    pub uploads_dir: String,
    pub storage_primary: String,
    pub storage_fallback: String,
    pub aws_region: String,
    pub s3_bucket: String,
    pub aws_access_key_id: String,
    pub aws_secret_access_key: String,
    pub s3_public_base_url: String,
    pub s3_key_prefix: String,
    pub server_port: u16,
    pub server_host: String,
    pub port: u16,
    pub host: String,
    pub smtp_host: String,
    pub smtp_port: u16,
    pub smtp_user: String,
    pub smtp_password: String,
    pub smtp_from: String,
}

pub type Config = AppConfig;

impl AppConfig {
    pub fn from_env() -> Self {
        Self::load()
    }

    pub fn load() -> Self {
        dotenvy::dotenv().ok();

        let db_host = env::var("DB_HOST").unwrap_or_else(|_| "127.0.0.1".to_string());
        let db_port = env::var("DB_PORT")
            .ok()
            .and_then(|p| p.parse::<u16>().ok())
            .unwrap_or(3306);
        let db_name = env::var("DB_NAME").unwrap_or_else(|_| "php_admin_panel".to_string());
        let db_user = env::var("DB_USER").unwrap_or_else(|_| "root".to_string());
        let db_pass = env::var("DB_PASS").unwrap_or_default();
        let db_charset = env::var("DB_CHARSET").unwrap_or_else(|_| "utf8mb4".to_string());

        let app_name = env::var("APP_NAME").unwrap_or_else(|_| "Jinish Potro".to_string());
        let app_env = env::var("APP_ENV").unwrap_or_else(|_| "development".to_string());
        let app_key = env::var("APP_KEY").unwrap_or_else(|_| "CHANGE_ME_TO_64_RANDOM_CHARS".to_string());
        let app_timezone = env::var("APP_TIMEZONE").unwrap_or_else(|_| "Asia/Dhaka".to_string());

        let raw_base_url = env::var("BASE_URL").unwrap_or_else(|_| "/".to_string());
        let base_url = if raw_base_url.is_empty() {
            "/".to_string()
        } else if !raw_base_url.ends_with('/') {
            format!("{}/", raw_base_url)
        } else {
            raw_base_url
        };

        let site_url = env::var("SITE_URL").unwrap_or_else(|_| "http://localhost:8080".to_string());
        let app_url = env::var("APP_URL").unwrap_or_else(|_| site_url.clone());
        let uploads_dir = env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string());

        let storage_primary = env::var("STORAGE_PRIMARY").unwrap_or_else(|_| "local".to_string());
        let storage_fallback = env::var("STORAGE_FALLBACK").unwrap_or_else(|_| "none".to_string());
        let aws_region = env::var("AWS_REGION").unwrap_or_else(|_| "us-east-1".to_string());
        let s3_bucket = env::var("S3_BUCKET").unwrap_or_default();
        let aws_access_key_id = env::var("AWS_ACCESS_KEY_ID").unwrap_or_default();
        let aws_secret_access_key = env::var("AWS_SECRET_ACCESS_KEY").unwrap_or_default();
        let s3_public_base_url = env::var("S3_PUBLIC_BASE_URL").unwrap_or_default();
        let s3_key_prefix = env::var("S3_KEY_PREFIX").unwrap_or_default();

        let server_port = env::var("PORT")
            .or_else(|_| env::var("RUST_PORT"))
            .ok()
            .and_then(|p| p.parse::<u16>().ok())
            .unwrap_or(8080);
        let server_host = env::var("HOST").unwrap_or_else(|_| "0.0.0.0".to_string());

        let smtp_host = env::var("MAIL_HOST").unwrap_or_else(|_| "localhost".to_string());
        let smtp_port = env::var("MAIL_PORT")
            .ok()
            .and_then(|p| p.parse::<u16>().ok())
            .unwrap_or(587);
        let smtp_user = env::var("MAIL_USERNAME").unwrap_or_default();
        let smtp_password = env::var("MAIL_PASSWORD").unwrap_or_default();
        let smtp_from = env::var("MAIL_FROM_ADDRESS").unwrap_or_else(|_| "noreply@jinishpotro.com".to_string());

        Self {
            db_host,
            db_port,
            db_name,
            db_user,
            db_pass,
            db_charset,
            app_name,
            app_env,
            app_key,
            app_timezone,
            base_url,
            site_url,
            app_url,
            uploads_dir,
            storage_primary,
            storage_fallback,
            aws_region,
            s3_bucket,
            aws_access_key_id,
            aws_secret_access_key,
            s3_public_base_url,
            s3_key_prefix,
            server_port,
            server_host: server_host.clone(),
            port: server_port,
            host: server_host,
            smtp_host,
            smtp_port,
            smtp_user,
            smtp_password,
            smtp_from,
        }
    }

    pub fn database_url(&self) -> String {
        format!(
            "mysql://{}:{}@{}:{}/{}?charset={}",
            self.db_user, self.db_pass, self.db_host, self.db_port, self.db_name, self.db_charset
        )
    }
}
