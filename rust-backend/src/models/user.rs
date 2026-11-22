use chrono::NaiveDateTime;
use serde::{Deserialize, Serialize};
use sqlx::FromRow;

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct User {
    pub id: u64,
    pub name: String,
    pub email: String,
    pub password: String,
    pub role: String,
    pub permissions: Option<String>,
    pub status: String,
    pub last_login: Option<NaiveDateTime>,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct ActivityLog {
    pub id: u64,
    pub user_id: Option<u64>,
    pub action: String,
    pub details: Option<String>,
    pub ip_address: Option<String>,
    pub created_at: Option<NaiveDateTime>,
}
