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
    pub permissions: Option<sqlx::types::Json<Vec<String>>>,
    pub status: String,
    pub avatar: Option<String>,
    pub avatar_file_id: Option<u64>,
    pub phone: Option<String>,
    pub bio: Option<String>,
    #[sqlx(default)]
    pub last_login: Option<NaiveDateTime>,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
}

impl User {
    pub fn permission_list(&self) -> Vec<String> {
        let mut list = self
            .permissions
            .as_ref()
            .map(|p| p.0.clone())
            .unwrap_or_default();
        if self.role == "admin" && !list.iter().any(|p| p == "*") {
            list.push("*".to_string());
        }
        list
    }
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
