use chrono::NaiveDateTime;
use serde::{Deserialize, Serialize};
use sqlx::FromRow;

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct Customer {
    pub id: u64,
    pub name: String,
    pub email: String,
    pub phone: Option<String>,
    pub password_hash: String,
    pub status: String, // 'active', 'pending', 'blocked'
    pub email_verified_at: Option<NaiveDateTime>,
    pub avatar: Option<String>,
    pub avatar_file_id: Option<u64>,
    pub marketing_opt_in: i8,
    pub failed_logins: u16,
    pub locked_until: Option<NaiveDateTime>,
    pub last_login_at: Option<NaiveDateTime>,
    pub last_login_ip: Option<String>,
    pub admin_note: Option<String>,
    pub deleted_at: Option<NaiveDateTime>,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct CustomerAddress {
    pub id: u64,
    pub customer_id: u64,
    pub label: String,
    pub full_name: String,
    pub phone: String,
    pub address: String,
    pub city: String,
    pub area: Option<String>,
    pub zip: Option<String>,
    pub country: String,
    pub is_default: i8,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct CustomerToken {
    pub id: u64,
    pub customer_id: u64,
    #[sqlx(rename = "type")]
    pub token_type: String, // 'verify_email', 'password_reset', 'remember_me', 'email_change'
    pub selector: String,
    pub token_hash: String,
    pub payload: Option<String>,
    pub expires_at: NaiveDateTime,
    pub used_at: Option<NaiveDateTime>,
    pub ip: Option<String>,
    pub user_agent: Option<String>,
    pub created_at: Option<NaiveDateTime>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct WishlistItem {
    pub id: u64,
    pub customer_id: u64,
    pub product_id: u64,
    pub variant_id: Option<u64>,
    pub created_at: Option<NaiveDateTime>,
}

/// Session representation of logged in customer
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct CustomerSession {
    pub id: u64,
    pub name: String,
    pub email: String,
    pub phone: Option<String>,
    pub status: String,
    pub verified: bool,
    pub pwd_stamp: String,
    pub last_activity: i64,
    pub last_validated: i64,
}
