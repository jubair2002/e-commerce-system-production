use chrono::NaiveDateTime;
use rust_decimal::Decimal;
use serde::{Deserialize, Serialize};
use sqlx::FromRow;

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct Order {
    pub id: u64,
    pub order_number: String,
    pub customer_id: Option<u64>,
    pub customer_name: String,
    pub customer_email: String,
    pub customer_phone: Option<String>,
    pub shipping_address: String,
    pub shipping_city: Option<String>,
    pub shipping_zip: Option<String>,
    pub shipping_country: Option<String>,
    pub subtotal: Decimal,
    pub shipping_fee: Decimal,
    pub total: Decimal,
    pub payment_method: String,
    pub status: String,
    pub notes: Option<String>,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
    pub payment_status: String,
    pub cod_fee: Decimal,
    pub discount_total: Decimal,
    pub coupon_code: Option<String>,
    pub shipping_method_id: Option<u64>,
    pub shipping_method_name: Option<String>,
    pub courier: Option<String>,
    pub tracking_number: Option<String>,
    pub stock_restored: i8,
    pub ip_address: Option<String>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct OrderItem {
    pub id: u64,
    pub order_id: u64,
    pub product_id: Option<u64>,
    pub variant_id: Option<u64>,
    pub product_name: String,
    pub product_sku: Option<String>,
    pub product_image: Option<String>,
    pub file_id: Option<u64>,
    pub unit_price: Decimal,
    pub quantity: i32,
    pub line_total: Decimal,
    pub variant_sku: Option<String>,
    pub variant_label: Option<String>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct OrderStatusHistory {
    pub id: u64,
    pub order_id: u64,
    pub old_status: Option<String>,
    pub new_status: String,
    pub comment: Option<String>,
    pub notify_customer: i8,
    pub created_by: Option<u64>,
    pub created_at: Option<NaiveDateTime>,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct ShippingMethod {
    pub id: u64,
    pub name: String,
    pub zone: String,
    pub cost: Decimal,
    pub free_over: Option<Decimal>,
    pub min_days: Option<i8>,
    pub max_days: Option<i8>,
    pub cod_allowed: i8,
    pub status: String,
    pub position: i32,
}

#[derive(Clone, Debug, Serialize, Deserialize, FromRow)]
pub struct Coupon {
    pub id: u64,
    pub code: String,
    #[sqlx(rename = "type")]
    pub coupon_type: String, // 'fixed' or 'percent'
    pub value: Decimal,
    pub min_subtotal: Decimal,
    pub max_discount: Option<Decimal>,
    pub usage_limit: Option<i32>,
    pub used_count: i32,
    pub starts_at: Option<NaiveDateTime>,
    pub expires_at: Option<NaiveDateTime>,
    pub status: String,
    pub created_at: Option<NaiveDateTime>,
}
