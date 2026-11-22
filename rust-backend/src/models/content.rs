use chrono::NaiveDateTime;
use serde::{Deserialize, Serialize};
use sqlx::FromRow;

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct Page {
    pub id: u64,
    pub title: String,
    pub slug: String,
    pub content: Option<String>,
    pub meta_title: Option<String>,
    pub meta_description: Option<String>,
    pub meta_keywords: Option<String>,
    pub show_in_header: i8,
    pub show_in_footer: i8,
    pub position: i32,
    pub status: String,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct StorefrontBanner {
    pub id: u64,
    pub title: String,
    pub subtitle: Option<String>,
    pub button_text: Option<String>,
    pub button_url: Option<String>,
    pub image: Option<String>,
    pub file_id: Option<u64>,
    pub placement: String,
    pub position: i32,
    pub status: String,
    pub starts_at: Option<NaiveDateTime>,
    pub ends_at: Option<NaiveDateTime>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct ContactMessage {
    pub id: u64,
    pub name: String,
    pub email: String,
    pub phone: Option<String>,
    pub subject: Option<String>,
    pub message: String,
    pub status: String,
    pub ip_address: Option<String>,
    pub created_at: Option<NaiveDateTime>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct NewsletterSubscriber {
    pub id: u64,
    pub email: String,
    pub status: String,
    pub source: Option<String>,
    pub ip_address: Option<String>,
    pub created_at: Option<NaiveDateTime>,
}
