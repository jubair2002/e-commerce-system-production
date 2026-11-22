use chrono::NaiveDateTime;
use rust_decimal::Decimal;
use serde::{Deserialize, Serialize};
use sqlx::FromRow;

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct Product {
    pub id: u64,
    pub category_id: Option<u64>,
    pub name: String,
    pub slug: String,
    pub sku: Option<String>,
    pub price: Decimal,
    pub sale_price: Option<Decimal>,
    pub stock: i32,
    pub low_stock_threshold: Option<i32>,
    pub allow_backorder: i8,
    pub track_inventory: i8,
    pub image: Option<String>,
    pub description: Option<String>,
    pub short_description: Option<String>,
    pub status: String,
    pub featured: i8,
    pub product_type: String,
    pub tags: Option<String>,
    pub rating_avg: Option<Decimal>,
    pub rating_count: u32,
    pub sold_count: u32,
    pub meta_title: Option<String>,
    pub meta_description: Option<String>,
    pub meta_keywords: Option<String>,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct Category {
    pub id: u64,
    pub name: String,
    pub slug: String,
    pub description: Option<String>,
    pub image: Option<String>,
    pub parent_id: Option<u64>,
    pub status: String,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct CategoryWithCount {
    pub id: u64,
    pub name: String,
    pub slug: String,
    pub description: Option<String>,
    pub image: Option<String>,
    pub product_count: i64,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct ProductVariant {
    pub id: u64,
    pub product_id: u64,
    pub option_hash: Option<String>,
    pub sku: Option<String>,
    pub barcode: Option<String>,
    pub price: Option<Decimal>,
    pub sale_price: Option<Decimal>,
    pub cost_price: Option<Decimal>,
    pub stock: i32,
    pub weight_g: Option<i32>,
    pub image: Option<String>,
    pub file_id: Option<u64>,
    pub status: String,
    pub position: i32,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct ProductOption {
    pub id: u64,
    pub product_id: u64,
    pub name: String,
    pub display_type: String,
    pub position: i32,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct ProductOptionValue {
    pub id: u64,
    pub option_id: u64,
    pub value: String,
    pub swatch_hex: Option<String>,
    pub position: i32,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct ProductImage {
    pub id: u64,
    pub product_id: u64,
    pub variant_id: Option<u64>,
    pub filename: String,
    pub file_id: Option<u64>,
    pub alt_text: Option<String>,
    pub position: i32,
    pub is_primary: i8,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct ProductReview {
    pub id: u64,
    pub product_id: u64,
    pub customer_id: Option<u64>,
    pub author_name: String,
    pub author_email: String,
    pub rating: i32,
    pub title: Option<String>,
    pub body: String,
    pub status: String,
    pub created_at: Option<NaiveDateTime>,
}

/// Decorated product card representation for Tera storefront templates
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct ProductCardItem {
    pub id: u64,
    pub category_id: Option<u64>,
    pub name: String,
    pub slug: String,
    pub sku: Option<String>,
    pub price: Decimal,
    pub sale_price: Option<Decimal>,
    pub effective_price: Decimal,
    pub is_on_sale: bool,
    pub discount_percent: Option<u32>,
    pub stock: i32,
    pub in_stock: bool,
    pub allow_backorder: bool,
    pub image: Option<String>,
    pub image_url: String,
    pub category_name: Option<String>,
    pub category_slug: Option<String>,
    pub rating_avg: Decimal,
    pub rating_count: u32,
    pub is_new: bool,
    pub product_type: String,
    pub colors: Vec<ProductColorSwatch>,
    pub formatted_price: String,
    pub formatted_sale_price: Option<String>,
    pub formatted_original_price: Option<String>,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct ProductColorSwatch {
    pub name: String,
    pub color_code: Option<String>,
}
