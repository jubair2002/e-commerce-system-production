use std::collections::HashMap;
use std::path::PathBuf;
use axum::{
    extract::{Multipart, Query, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{Html, IntoResponse, Redirect, Response},
    Form,
};
use chrono::Utc;
use common::formatters::slugify;
use db::queries::activity::log_activity;
use db::queries::admin::{delete_category, get_categories_admin, get_unique_slug, save_category};
use db::queries::inventory::{get_inventory_movements, get_inventory_overview, stock_adjust};
use db::queries::storage::{StorageManager, StoreOptions};
use rust_decimal::Decimal;
use serde::Deserialize;
use tower_sessions::Session;

use crate::state::{build_admin_context, AppState, SessionHelper};

// =================================================================
// 1. Products List
// =================================================================

#[derive(Debug, Deserialize)]
pub struct ProductsQuery {
    pub q: Option<String>,
    pub category: Option<u32>,
    pub status: Option<String>,
    pub r#type: Option<String>,
    pub stock_status: Option<String>,
    pub featured: Option<String>,
    pub page: Option<i64>,
    pub per_page: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct ProductActionForm {
    pub action: Option<String>,
    pub id: Option<u32>,
    pub bulk_op: Option<String>,
    #[serde(default)]
    pub selected_ids: Vec<u32>,
}

pub async fn admin_products_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<ProductsQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("products.view")
        && !admin.has_permission("products.create")
        && !admin.has_permission("products.edit")
        && admin.role != "admin"
        && admin.role != "editor"
    {
        return (StatusCode::FORBIDDEN, Html("<h1>403 Forbidden</h1>")).into_response();
    }

    let search = query.q.as_deref().unwrap_or("").trim();
    let cat_id = query.category.unwrap_or(0);
    let status_filter = query.status.as_deref().unwrap_or("").trim();
    let type_filter = query.r#type.as_deref().unwrap_or("").trim();
    let stock_filter = query.stock_status.as_deref().unwrap_or("").trim();
    let featured_filter = query.featured.as_deref().unwrap_or("").trim();

    let page = query.page.unwrap_or(1).max(1);
    let per_page = query.per_page.unwrap_or(15).clamp(5, 100);
    let offset = (page - 1) * per_page;

    let mut where_parts = " WHERE 1=1".to_string();
    if !search.is_empty() {
        where_parts.push_str(&format!(
            " AND (p.name LIKE '%{}%' OR p.sku LIKE '%{}%')",
            search, search
        ));
    }
    if cat_id > 0 {
        where_parts.push_str(&format!(" AND p.category_id = {}", cat_id));
    }
    if status_filter == "active" || status_filter == "inactive" || status_filter == "draft" {
        where_parts.push_str(&format!(" AND p.status = '{}'", status_filter));
    }
    if type_filter == "simple" || type_filter == "variable" {
        where_parts.push_str(&format!(" AND p.product_type = '{}'", type_filter));
    }
    if featured_filter == "1" {
        where_parts.push_str(" AND p.featured = 1");
    }

    if stock_filter == "out" {
        where_parts.push_str(" AND COALESCE(vps.total_stock, p.stock) <= 0");
    } else if stock_filter == "low" {
        where_parts.push_str(" AND COALESCE(vps.total_stock, p.stock) > 0 AND COALESCE(vps.total_stock, p.stock) <= COALESCE(p.low_stock_threshold, 5)");
    } else if stock_filter == "in" {
        where_parts.push_str(" AND COALESCE(vps.total_stock, p.stock) > COALESCE(p.low_stock_threshold, 5)");
    }

    let count_joins = if where_parts.contains("vps.") {
        " LEFT JOIN v_product_stock vps ON vps.product_id = p.id"
    } else {
        ""
    };

    let count_sql = format!(
        "SELECT COUNT(p.id) FROM products p {} {}",
        count_joins, where_parts
    );
    let total: i64 = sqlx::query_scalar(&count_sql)
        .fetch_one(&state.db)
        .await
        .unwrap_or(0);
    let total_pages = (total as f64 / per_page as f64).ceil() as i64;

    let select_sql = format!(
        "SELECT p.id, p.name, p.slug, p.sku, p.price, p.sale_price, p.stock, p.image,
                p.status, p.featured, p.product_type, p.low_stock_threshold,
                c.name AS category_name,
                CAST(COALESCE(vps.total_stock, p.stock) AS SIGNED) AS calculated_stock,
                CAST(COALESCE(vps.variant_count, 1) AS SIGNED) AS variant_count
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN v_product_stock vps ON vps.product_id = p.id
         {} ORDER BY p.created_at DESC LIMIT {} OFFSET {}",
        where_parts, per_page, offset
    );

    let rows: Vec<(
        u32,
        String,
        String,
        Option<String>,
        Decimal,
        Option<Decimal>,
        i32,
        Option<String>,
        String,
        i8,
        String,
        i32,
        Option<String>,
        i64,
        i64,
    )> = match sqlx::query_as(&select_sql).fetch_all(&state.db).await {
        Ok(r) => r,
        Err(e) => {
            tracing::error!("SQLx error fetching products: {:?}", e);
            eprintln!("SQLx error fetching products: {:?}", e);
            Vec::new()
        }
    };

    let products: Vec<serde_json::Value> = rows
        .into_iter()
        .map(|r| {
            serde_json::json!({
                "id": r.0,
                "name": r.1,
                "slug": r.2,
                "sku": r.3,
                "price": r.4.to_string(),
                "sale_price": r.5.map(|s| s.to_string()),
                "stock": r.6,
                "image": r.7,
                "status": r.8,
                "featured": r.9,
                "product_type": r.10,
                "low_stock_threshold": r.11,
                "category_name": r.12,
                "calculated_stock": r.13,
                "variant_count": r.14,
            })
        })
        .collect();

    let categories_rows: Vec<(u32, String)> =
        sqlx::query_as("SELECT id, name FROM categories ORDER BY name ASC")
            .fetch_all(&state.db)
            .await
            .unwrap_or_default();

    let categories: Vec<serde_json::Value> = categories_rows
        .into_iter()
        .map(|(cid, cname)| serde_json::json!({ "id": cid, "name": cname }))
        .collect();

    let mut ctx = build_admin_context(&state, &session, "products.php", "Products").await;
    ctx.insert("products", &products);
    ctx.insert("categories", &categories);
    ctx.insert("total", &total);
    ctx.insert("current_page", &page);
    ctx.insert("total_pages", &total_pages.max(1));
    ctx.insert("search", search);
    ctx.insert("catId", &cat_id);
    ctx.insert("status", status_filter);
    ctx.insert("typeFilter", type_filter);
    ctx.insert("stockFilter", stock_filter);
    ctx.insert("featured", featured_filter);

    match state.tera.render("admin/products.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => {
            let mut err_msg = format!("Template error: {}", e);
            let mut cur: &dyn std::error::Error = &e;
            while let Some(source) = cur.source() {
                err_msg.push_str(&format!(" -> {}", source));
                cur = source;
            }
            (StatusCode::INTERNAL_SERVER_ERROR, err_msg).into_response()
        }
    }
}

pub async fn admin_products_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<ProductActionForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    let action = form.action.as_deref().unwrap_or("");

    if action == "delete" {
        if !admin.has_permission("products.delete") && admin.role != "admin" {
            SessionHelper::add_flash(&session, "danger", "Permission denied.").await;
            return Redirect::to("/admin/products.php").into_response();
        }

        let pid = form.id.unwrap_or(0);
        let prod_row: Option<(String, Option<String>, Option<u64>)> = sqlx::query_as(
            "SELECT name, image, file_id FROM products WHERE id = ? LIMIT 1",
        )
        .bind(pid)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if let Some((pname, _img, file_id)) = prod_row {
            let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
            let storage = StorageManager::new(uploads_dir);
            if let Some(fid) = file_id {
                let _ = storage.delete_file(&state.db, fid).await;
            }

            // Clean up gallery images
            let gal_fids: Vec<(Option<u64>,)> =
                sqlx::query_as("SELECT file_id FROM product_images WHERE product_id = ?")
                    .bind(pid)
                    .fetch_all(&state.db)
                    .await
                    .unwrap_or_default();
            for (gfid,) in gal_fids {
                if let Some(fid) = gfid {
                    let _ = storage.delete_file(&state.db, fid).await;
                }
            }

            let _ = sqlx::query("DELETE FROM products WHERE id = ?")
                .bind(pid)
                .execute(&state.db)
                .await;

            log_activity(
                &state.db,
                Some(admin.id),
                "product.delete",
                &format!("Deleted product {}", pname),
                None,
            )
            .await;
            SessionHelper::add_flash(&session, "success", "Product deleted.").await;
        } else {
            SessionHelper::add_flash(&session, "warning", "Product not found.").await;
        }

        return Redirect::to("/admin/products.php").into_response();
    }

    if action == "bulk" {
        let op = form.bulk_op.as_deref().unwrap_or("");
        let ids = form.selected_ids;
        if ids.is_empty() {
            SessionHelper::add_flash(&session, "warning", "No products selected.").await;
            return Redirect::to("/admin/products.php").into_response();
        }

        // Build safe parameterized IN clause
        let placeholders = ids.iter().map(|_| "?").collect::<Vec<_>>().join(",");

        if op == "activate" {
            let query_str = format!(
                "UPDATE products SET status = 'active' WHERE id IN ({})",
                placeholders
            );
            let mut q = sqlx::query(&query_str);
            for id in &ids {
                q = q.bind(id);
            }
            let _ = q.execute(&state.db).await;

            log_activity(
                &state.db,
                Some(admin.id),
                "product.bulk_activate",
                &format!("Activated {} products", ids.len()),
                None,
            )
            .await;
            SessionHelper::add_flash(
                &session,
                "success",
                &format!("{} products activated.", ids.len()),
            )
            .await;
        } else if op == "deactivate" {
            let query_str = format!(
                "UPDATE products SET status = 'inactive' WHERE id IN ({})",
                placeholders
            );
            let mut q = sqlx::query(&query_str);
            for id in &ids {
                q = q.bind(id);
            }
            let _ = q.execute(&state.db).await;

            log_activity(
                &state.db,
                Some(admin.id),
                "product.bulk_deactivate",
                &format!("Deactivated {} products", ids.len()),
                None,
            )
            .await;
            SessionHelper::add_flash(
                &session,
                "success",
                &format!("{} products deactivated.", ids.len()),
            )
            .await;
        } else if op == "delete" {
            if !admin.has_permission("products.delete") && admin.role != "admin" {
                SessionHelper::add_flash(&session, "danger", "Permission denied.").await;
                return Redirect::to("/admin/products.php").into_response();
            }

            let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
            let storage = StorageManager::new(uploads_dir);
            let select_imgs = format!(
                "SELECT file_id FROM products WHERE id IN ({}) AND file_id IS NOT NULL",
                placeholders
            );
            let mut q_imgs = sqlx::query_as::<_, (u64,)>(&select_imgs);
            for id in &ids {
                q_imgs = q_imgs.bind(id);
            }
            let fids = q_imgs.fetch_all(&state.db).await.unwrap_or_default();
            for (fid,) in fids {
                let _ = storage.delete_file(&state.db, fid).await;
            }

            let del_query = format!("DELETE FROM products WHERE id IN ({})", placeholders);
            let mut q_del = sqlx::query(&del_query);
            for id in &ids {
                q_del = q_del.bind(id);
            }
            let _ = q_del.execute(&state.db).await;

            log_activity(
                &state.db,
                Some(admin.id),
                "product.bulk_delete",
                &format!("Deleted {} products", ids.len()),
                None,
            )
            .await;
            SessionHelper::add_flash(
                &session,
                "success",
                &format!("{} products deleted.", ids.len()),
            )
            .await;
        }
    }

    Redirect::to("/admin/products.php").into_response()
}

// =================================================================
// 2. Product Form (Add / Edit)
// =================================================================

#[derive(Debug, Deserialize)]
pub struct ProductFormQuery {
    pub id: Option<u32>,
}

#[derive(sqlx::FromRow)]
struct ProductEditRow {
    id: u32,
    category_id: Option<u32>,
    name: String,
    slug: String,
    sku: Option<String>,
    product_type: String,
    short_description: Option<String>,
    description: Option<String>,
    price: Decimal,
    sale_price: Option<Decimal>,
    stock: i32,
    image: Option<String>,
    status: String,
    featured: i8,
    track_inventory: i8,
    allow_backorder: i8,
    #[sqlx(default)]
    is_preorder: i8,
    low_stock_threshold: i32,
    show_stock_count: i8,
    weight_g: Option<i32>,
    brand: Option<String>,
    tags: Option<String>,
    meta_title: Option<String>,
    meta_description: Option<String>,
    meta_keywords: Option<String>,
    updated_at: chrono::NaiveDateTime,
}

#[derive(sqlx::FromRow)]
struct ProductOptionRow {
    id: u32,
    name: String,
    display_type: String,
    position: i32,
}

#[derive(sqlx::FromRow)]
struct ProductOptionValRow {
    id: u32,
    value: String,
    swatch_hex: Option<String>,
    position: i32,
}

#[derive(sqlx::FromRow)]
struct ProductVariantRow {
    id: u32,
    sku: Option<String>,
    barcode: Option<String>,
    price: Option<Decimal>,
    sale_price: Option<Decimal>,
    stock: i32,
    weight_g: Option<i32>,
    image: Option<String>,
    status: String,
    position: i32,
}

#[derive(sqlx::FromRow)]
struct ProductVariantLinkRow {
    option_name: String,
    option_value: String,
}

#[derive(sqlx::FromRow)]
struct ProductImageRow {
    id: u32,
    filename: String,
    alt_text: Option<String>,
    position: i32,
    is_primary: i8,
}

pub async fn admin_product_form_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<ProductFormQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("products.create")
        && !admin.has_permission("products.edit")
        && admin.role != "admin"
        && admin.role != "editor"
    {
        return (StatusCode::FORBIDDEN, Html("<h1>403 Forbidden</h1>")).into_response();
    }

    let id = query.id.unwrap_or(0);
    let page_title = if id > 0 { "Edit product" } else { "Add product" };

    let mut product_data = serde_json::json!({
        "id": 0, "category_id": null, "name": "", "slug": "", "sku": "",
        "product_type": "simple", "short_description": "", "description": "",
        "price": "0.00", "sale_price": "", "stock": 0, "image": null, "status": "active",
        "featured": 0, "track_inventory": 1, "allow_backorder": 0, "is_preorder": 0, "low_stock_threshold": 5,
        "show_stock_count": 1, "weight_g": "", "brand": "", "tags": "",
        "meta_title": "", "meta_description": "", "meta_keywords": "", "updated_at": ""
    });

    let mut existing_options: Vec<serde_json::Value> = Vec::new();
    let mut existing_variants: Vec<serde_json::Value> = Vec::new();
    let mut existing_images: Vec<serde_json::Value> = Vec::new();

    if id > 0 {
        let p_row = sqlx::query_as::<_, ProductEditRow>(
            "SELECT id, category_id, name, slug, sku, product_type, short_description, description,
                    price, sale_price, stock, image, status, featured, track_inventory,
                    allow_backorder, is_preorder, low_stock_threshold, show_stock_count, weight_g,
                    brand, tags, meta_title, meta_description, meta_keywords, updated_at
             FROM products WHERE id = ? LIMIT 1"
        )
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if let Some(p) = p_row {
            product_data = serde_json::json!({
                "id": p.id,
                "category_id": p.category_id,
                "name": p.name,
                "slug": p.slug,
                "sku": p.sku.unwrap_or_default(),
                "product_type": p.product_type,
                "short_description": p.short_description.unwrap_or_default(),
                "description": p.description.unwrap_or_default(),
                "price": p.price.to_string(),
                "sale_price": p.sale_price.map(|s| s.to_string()).unwrap_or_default(),
                "stock": p.stock,
                "image": p.image,
                "status": p.status,
                "featured": p.featured,
                "track_inventory": p.track_inventory,
                "allow_backorder": p.allow_backorder,
                "is_preorder": p.is_preorder,
                "low_stock_threshold": p.low_stock_threshold,
                "show_stock_count": p.show_stock_count,
                "weight_g": p.weight_g.map(|w| w.to_string()).unwrap_or_default(),
                "brand": p.brand.unwrap_or_default(),
                "tags": p.tags.unwrap_or_default(),
                "meta_title": p.meta_title.unwrap_or_default(),
                "meta_description": p.meta_description.unwrap_or_default(),
                "meta_keywords": p.meta_keywords.unwrap_or_default(),
                "updated_at": p.updated_at.format("%Y-%m-%d %H:%M:%S").to_string(),
            });

            // Options and values
            let opt_rows = sqlx::query_as::<_, ProductOptionRow>(
                "SELECT id, name, display_type, position FROM product_options WHERE product_id = ? ORDER BY position ASC, id ASC"
            )
            .bind(id)
            .fetch_all(&state.db)
            .await
            .unwrap_or_default();

            for opt in opt_rows {
                let val_rows = sqlx::query_as::<_, ProductOptionValRow>(
                    "SELECT id, value, swatch_hex, position FROM product_option_values WHERE option_id = ? ORDER BY position ASC, id ASC"
                )
                .bind(opt.id)
                .fetch_all(&state.db)
                .await
                .unwrap_or_default();

                let vals: Vec<serde_json::Value> = val_rows
                    .into_iter()
                    .map(|v| {
                        serde_json::json!({
                            "id": v.id,
                            "value": v.value,
                            "swatch_hex": v.swatch_hex,
                            "position": v.position,
                        })
                    })
                    .collect();

                existing_options.push(serde_json::json!({
                    "id": opt.id,
                    "name": opt.name,
                    "display_type": opt.display_type,
                    "position": opt.position,
                    "values": vals,
                }));
            }

            // Variants with values and label
            let var_rows = sqlx::query_as::<_, ProductVariantRow>(
                "SELECT id, sku, barcode, price, sale_price, stock, weight_g, image, status, position
                 FROM product_variants WHERE product_id = ? ORDER BY position ASC, id ASC"
            )
            .bind(id)
            .fetch_all(&state.db)
            .await
            .unwrap_or_default();

            for v in var_rows {
                let link_rows = sqlx::query_as::<_, ProductVariantLinkRow>(
                    "SELECT po.name AS option_name, pov.value AS option_value
                     FROM product_variant_values pvv
                     JOIN product_options po ON po.id = pvv.option_id
                     JOIN product_option_values pov ON pov.id = pvv.option_value_id
                     WHERE pvv.variant_id = ?
                     ORDER BY po.position ASC, po.id ASC"
                )
                .bind(v.id)
                .fetch_all(&state.db)
                .await
                .unwrap_or_default();

                let combo: Vec<serde_json::Value> = link_rows
                    .iter()
                    .map(|lr| {
                        serde_json::json!({
                            "option_name": lr.option_name,
                            "value": lr.option_value,
                        })
                    })
                    .collect();

                let label = link_rows
                    .iter()
                    .map(|lr| format!("{}: {}", lr.option_name, lr.option_value))
                    .collect::<Vec<_>>()
                    .join(" / ");

                existing_variants.push(serde_json::json!({
                    "id": v.id,
                    "sku": v.sku.unwrap_or_default(),
                    "barcode": v.barcode.unwrap_or_default(),
                    "price": v.price.map(|p| p.to_string()).unwrap_or_default(),
                    "sale_price": v.sale_price.map(|s| s.to_string()).unwrap_or_default(),
                    "stock": v.stock,
                    "weight_g": v.weight_g.map(|w| w.to_string()).unwrap_or_default(),
                    "image": v.image.unwrap_or_default(),
                    "status": v.status,
                    "position": v.position,
                    "label": if label.is_empty() { "Variant".to_string() } else { label },
                    "combo": combo,
                }));
            }

            // Product Images Gallery
            let img_rows = sqlx::query_as::<_, ProductImageRow>(
                "SELECT id, filename, alt_text, position, is_primary
                 FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, position ASC, id ASC"
            )
            .bind(id)
            .fetch_all(&state.db)
            .await
            .unwrap_or_default();

            for img in img_rows {
                existing_images.push(serde_json::json!({
                    "id": img.id,
                    "filename": img.filename,
                    "alt_text": img.alt_text.unwrap_or_default(),
                    "position": img.position,
                    "is_primary": img.is_primary,
                }));
            }
        } else {
            SessionHelper::add_flash(&session, "warning", "Product not found.").await;
            return Redirect::to("/admin/products.php").into_response();
        }
    }

    let categories_rows: Vec<(u32, String)> =
        sqlx::query_as("SELECT id, name FROM categories ORDER BY name ASC")
            .fetch_all(&state.db)
            .await
            .unwrap_or_default();
    let categories: Vec<serde_json::Value> = categories_rows
        .into_iter()
        .map(|(cid, cname)| serde_json::json!({ "id": cid, "name": cname }))
        .collect();

    let mut ctx = build_admin_context(&state, &session, "product_form.php", page_title).await;
    ctx.insert("id", &id);
    ctx.insert("product", &product_data);
    ctx.insert("categories", &categories);
    ctx.insert("existingOptions", &existing_options);
    ctx.insert("existingVariants", &existing_variants);
    ctx.insert("existingImages", &existing_images);
    let empty_errors: Vec<String> = Vec::new();
    ctx.insert("errors", &empty_errors);

    match state.tera.render("admin/product_form.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => {
            let mut err_msg = format!("Template error: {}", e);
            let mut cur: &dyn std::error::Error = &e;
            while let Some(source) = cur.source() {
                err_msg.push_str(&format!(" -> {}", source));
                cur = source;
            }
            (StatusCode::INTERNAL_SERVER_ERROR, err_msg).into_response()
        }
    }
}

pub async fn admin_product_form_post_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<ProductFormQuery>,
    mut multipart: Multipart,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    let id = query.id.unwrap_or(0);

    let mut name = String::new();
    let mut slug = String::new();
    let mut sku = String::new();
    let mut product_type = "simple".to_string();
    let mut category_id: u32 = 0;
    let mut price = "0.00".to_string();
    let mut sale_price = String::new();
    let mut stock: i32 = 0;
    let mut short_description = String::new();
    let mut description = String::new();
    let mut status = "active".to_string();
    let mut featured: i8 = 0;
    let mut track_inventory: i8 = 1;
    let mut allow_backorder: i8 = 0;
    let mut is_preorder: i8 = 0;
    let mut low_stock_threshold: i32 = 5;
    let mut show_stock_count: i8 = 1;
    let mut weight_g: Option<i32> = None;
    let mut brand = String::new();
    let mut tags = String::new();
    let mut meta_title = String::new();
    let mut meta_desc = String::new();
    let mut meta_keywords = String::new();
    let mut options_json = "[]".to_string();
    let mut variants_json = "[]".to_string();
    let mut original_updated_at = String::new();
    let mut delete_gallery_ids: Vec<u32> = Vec::new();

    let mut primary_image_file: Option<(String, Vec<u8>)> = None;
    let mut gallery_files: Vec<(String, Vec<u8>)> = Vec::new();

    while let Ok(Some(field)) = multipart.next_field().await {
        let field_name = field.name().unwrap_or("").to_string();
        if field_name == "name" {
            name = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "slug" {
            slug = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "sku" {
            sku = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "product_type" {
            let pt = field.text().await.unwrap_or_default();
            if pt == "variable" {
                product_type = "variable".to_string();
            }
        } else if field_name == "category_id" {
            category_id = field.text().await.unwrap_or_default().parse().unwrap_or(0);
        } else if field_name == "price" {
            price = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "sale_price" {
            sale_price = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "stock" {
            stock = field.text().await.unwrap_or_default().parse().unwrap_or(0);
        } else if field_name == "short_description" {
            short_description = field.text().await.unwrap_or_default();
        } else if field_name == "description" {
            description = field.text().await.unwrap_or_default();
        } else if field_name == "status" {
            let st = field.text().await.unwrap_or_default();
            if st == "inactive" || st == "draft" {
                status = st;
            }
        } else if field_name == "featured" {
            featured = 1;
        } else if field_name == "track_inventory" {
            track_inventory = 1;
        } else if field_name == "allow_backorder" {
            allow_backorder = 1;
        } else if field_name == "is_preorder" {
            is_preorder = 1;
        } else if field_name == "low_stock_threshold" {
            low_stock_threshold = field.text().await.unwrap_or_default().parse().unwrap_or(5);
        } else if field_name == "show_stock_count" {
            show_stock_count = 1;
        } else if field_name == "weight_g" {
            if let Ok(w) = field.text().await.unwrap_or_default().trim().parse::<i32>() {
                weight_g = Some(w);
            }
        } else if field_name == "brand" {
            brand = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "tags" {
            tags = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "meta_title" {
            meta_title = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "meta_description" {
            meta_desc = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "meta_keywords" {
            meta_keywords = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "options_json" {
            options_json = field.text().await.unwrap_or_default();
        } else if field_name == "variants_json" {
            variants_json = field.text().await.unwrap_or_default();
        } else if field_name == "original_updated_at" {
            original_updated_at = field.text().await.unwrap_or_default().trim().to_string();
        } else if field_name == "delete_gallery_images[]" || field_name == "delete_gallery_images" {
            if let Ok(del_id) = field.text().await.unwrap_or_default().parse::<u32>() {
                delete_gallery_ids.push(del_id);
            }
        } else if field_name == "image" {
            let fname = field.file_name().unwrap_or("").to_string();
            if let Ok(bytes) = field.bytes().await {
                if !bytes.is_empty() && !fname.is_empty() {
                    primary_image_file = Some((fname, bytes.to_vec()));
                }
            }
        } else if field_name == "gallery_images[]" {
            let fname = field.file_name().unwrap_or("").to_string();
            if let Ok(bytes) = field.bytes().await {
                if !bytes.is_empty() && !fname.is_empty() {
                    gallery_files.push((fname, bytes.to_vec()));
                }
            }
        }
    }

    // Validation
    let mut errors = Vec::new();
    if name.is_empty() {
        errors.push("Product name is required.".to_string());
    }

    let parsed_price = price.parse::<f64>().unwrap_or(-1.0);
    if parsed_price < 0.0 {
        errors.push("Price must be a non-negative number.".to_string());
    }

    let parsed_sale = if !sale_price.is_empty() {
        sale_price.parse::<f64>().ok()
    } else {
        None
    };

    if let Some(sp) = parsed_sale {
        if sp < 0.0 || sp >= parsed_price {
            errors.push("Sale price must be less than the regular price.".to_string());
        }
    }

    // SKU check
    if !sku.is_empty() {
        let sku_exists: Option<(u64,)> = sqlx::query_as(
            "SELECT id FROM products WHERE sku = ? AND id <> ?
             UNION
             SELECT product_id FROM product_variants WHERE sku = ? AND product_id <> ?
             LIMIT 1",
        )
        .bind(&sku)
        .bind(id)
        .bind(&sku)
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if sku_exists.is_some() {
            errors.push("The product SKU is already in use by another product or variant.".to_string());
        }
    }

    // Concurrent edit detection
    if id > 0 && !original_updated_at.is_empty() {
        let cur_updated_at: Option<chrono::NaiveDateTime> =
            sqlx::query_scalar("SELECT updated_at FROM products WHERE id = ?")
                .bind(id)
                .fetch_optional(&state.db)
                .await
                .unwrap_or(None);

        if let Some(cur) = cur_updated_at {
            let cur_str = cur.format("%Y-%m-%d %H:%M:%S").to_string();
            if cur_str != original_updated_at {
                errors.push("This product was modified by another user while you were editing it. Please refresh and review before saving.".to_string());
            }
        }
    }

    if !errors.is_empty() {
        for err in errors {
            SessionHelper::add_flash(&session, "danger", &err).await;
        }
        let redir = if id > 0 {
            format!("/admin/product_form.php?id={}", id)
        } else {
            "/admin/product_form.php".to_string()
        };
        return Redirect::to(&redir).into_response();
    }

    // Generate unique slug
    let base_slug = if slug.is_empty() {
        slugify(&name)
    } else {
        slugify(&slug)
    };
    let final_slug = get_unique_slug(&state.db, &base_slug, "products", id as u64)
        .await
        .unwrap_or(base_slug);

    let uploads_dir = PathBuf::from(std::env::var("UPLOADS_DIR").unwrap_or_else(|_| "assets/uploads".to_string()));
    let storage = StorageManager::new(uploads_dir);

    // Upload primary image if provided
    let mut primary_image_key: Option<String> = None;
    let mut primary_file_id: Option<u64> = None;
    if let Some((fname, bytes)) = primary_image_file {
        if let Ok(info) = storage
            .store_file(
                &state.db,
                "product",
                &fname,
                &bytes,
                StoreOptions {
                    owner_type: Some("product".to_string()),
                    owner_id: if id > 0 { Some(id as u64) } else { None },
                    visibility: Some("public".to_string()),
                    is_test: false,
                },
            )
            .await
        {
            primary_image_key = Some(info.storage_key);
            primary_file_id = Some(info.id);
        }
    }

    let mut tx = match state.db.begin().await {
        Ok(t) => t,
        Err(e) => {
            SessionHelper::add_flash(&session, "danger", &format!("Database error: {}", e)).await;
            return Redirect::to("/admin/products.php").into_response();
        }
    };

    let target_product_id: u64;

    if id > 0 {
        let old_stock_val: i32 = sqlx::query_scalar("SELECT stock FROM products WHERE id = ?")
            .bind(id)
            .fetch_one(&mut *tx)
            .await
            .unwrap_or(0);

        if primary_image_key.is_some() {
            let _ = sqlx::query(
                "UPDATE products SET
                    category_id = ?, name = ?, slug = ?, product_type = ?, sku = ?,
                    short_description = ?, description = ?, price = ?, sale_price = ?,
                    image = ?, file_id = ?, status = ?, featured = ?, track_inventory = ?,
                    allow_backorder = ?, is_preorder = ?, low_stock_threshold = ?, show_stock_count = ?,
                    weight_g = ?, brand = ?, tags = ?, meta_title = ?, meta_description = ?,
                    meta_keywords = ?, updated_at = NOW()
                 WHERE id = ?"
            )
            .bind(if category_id > 0 { Some(category_id) } else { None })
            .bind(&name)
            .bind(&final_slug)
            .bind(&product_type)
            .bind(if sku.is_empty() { None } else { Some(&sku) })
            .bind(if short_description.is_empty() { None } else { Some(&short_description) })
            .bind(if description.is_empty() { None } else { Some(&description) })
            .bind(&price)
            .bind(if sale_price.is_empty() { None } else { Some(&sale_price) })
            .bind(&primary_image_key)
            .bind(primary_file_id)
            .bind(&status)
            .bind(featured)
            .bind(track_inventory)
            .bind(allow_backorder)
            .bind(is_preorder)
            .bind(low_stock_threshold)
            .bind(show_stock_count)
            .bind(weight_g)
            .bind(if brand.is_empty() { None } else { Some(&brand) })
            .bind(if tags.is_empty() { None } else { Some(&tags) })
            .bind(if meta_title.is_empty() { None } else { Some(&meta_title) })
            .bind(if meta_desc.is_empty() { None } else { Some(&meta_desc) })
            .bind(if meta_keywords.is_empty() { None } else { Some(&meta_keywords) })
            .bind(id)
            .execute(&mut *tx)
            .await;
        } else {
            let _ = sqlx::query(
                "UPDATE products SET
                    category_id = ?, name = ?, slug = ?, product_type = ?, sku = ?,
                    short_description = ?, description = ?, price = ?, sale_price = ?,
                    status = ?, featured = ?, track_inventory = ?, allow_backorder = ?,
                    is_preorder = ?, low_stock_threshold = ?, show_stock_count = ?, weight_g = ?, brand = ?,
                    tags = ?, meta_title = ?, meta_description = ?, meta_keywords = ?,
                    updated_at = NOW()
                 WHERE id = ?"
            )
            .bind(if category_id > 0 { Some(category_id) } else { None })
            .bind(&name)
            .bind(&final_slug)
            .bind(&product_type)
            .bind(if sku.is_empty() { None } else { Some(&sku) })
            .bind(if short_description.is_empty() { None } else { Some(&short_description) })
            .bind(if description.is_empty() { None } else { Some(&description) })
            .bind(&price)
            .bind(if sale_price.is_empty() { None } else { Some(&sale_price) })
            .bind(&status)
            .bind(featured)
            .bind(track_inventory)
            .bind(allow_backorder)
            .bind(is_preorder)
            .bind(low_stock_threshold)
            .bind(show_stock_count)
            .bind(weight_g)
            .bind(if brand.is_empty() { None } else { Some(&brand) })
            .bind(if tags.is_empty() { None } else { Some(&tags) })
            .bind(if meta_title.is_empty() { None } else { Some(&meta_title) })
            .bind(if meta_desc.is_empty() { None } else { Some(&meta_desc) })
            .bind(if meta_keywords.is_empty() { None } else { Some(&meta_keywords) })
            .bind(id)
            .execute(&mut *tx)
            .await;
        }

        target_product_id = id as u64;

        if product_type == "simple" {
            let delta = stock - old_stock_val;
            if delta != 0 {
                let _ = stock_adjust(
                    &state.db,
                    target_product_id,
                    None,
                    delta,
                    if old_stock_val == 0 { "restock" } else { "manual_adjust" },
                    None,
                    Some(admin.id),
                    Some("Product edit form stock update"),
                )
                .await;
            }
        }
    } else {
        let insert_res = sqlx::query(
            "INSERT INTO products
                (category_id, name, slug, product_type, sku, short_description, description,
                 price, sale_price, stock, image, file_id, status, featured, track_inventory,
                 allow_backorder, is_preorder, low_stock_threshold, show_stock_count, weight_g, brand,
                 tags, meta_title, meta_description, meta_keywords, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        )
        .bind(if category_id > 0 { Some(category_id) } else { None })
        .bind(&name)
        .bind(&final_slug)
        .bind(&product_type)
        .bind(if sku.is_empty() { None } else { Some(&sku) })
        .bind(if short_description.is_empty() { None } else { Some(&short_description) })
        .bind(if description.is_empty() { None } else { Some(&description) })
        .bind(&price)
        .bind(if sale_price.is_empty() { None } else { Some(&sale_price) })
        .bind(if product_type == "simple" { stock } else { 0 })
        .bind(&primary_image_key)
        .bind(primary_file_id)
        .bind(&status)
        .bind(featured)
        .bind(track_inventory)
        .bind(allow_backorder)
        .bind(is_preorder)
        .bind(low_stock_threshold)
        .bind(show_stock_count)
        .bind(weight_g)
        .bind(if brand.is_empty() { None } else { Some(&brand) })
        .bind(if tags.is_empty() { None } else { Some(&tags) })
        .bind(if meta_title.is_empty() { None } else { Some(&meta_title) })
        .bind(if meta_desc.is_empty() { None } else { Some(&meta_desc) })
        .bind(if meta_keywords.is_empty() { None } else { Some(&meta_keywords) })
        .execute(&mut *tx)
        .await;

        target_product_id = match insert_res {
            Ok(r) => r.last_insert_id(),
            Err(e) => {
                let _ = tx.rollback().await;
                SessionHelper::add_flash(&session, "danger", &format!("Insert failed: {}", e)).await;
                return Redirect::to("/admin/products.php").into_response();
            }
        };

        if product_type == "simple" && stock > 0 {
            let _ = stock_adjust(
                &state.db,
                target_product_id,
                None,
                stock,
                "restock",
                None,
                Some(admin.id),
                Some("Initial product stock creation"),
            )
            .await;
        }
    }

    let _ = tx.commit().await;

    // Gallery deletions
    for del_id in delete_gallery_ids {
        let row: Option<(u64,)> = sqlx::query_as(
            "SELECT file_id FROM product_images WHERE id = ? AND product_id = ?",
        )
        .bind(del_id)
        .bind(target_product_id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if let Some((fid,)) = row {
            let _ = storage.delete_file(&state.db, fid).await;
        }
        let _ = sqlx::query("DELETE FROM product_images WHERE id = ? AND product_id = ?")
            .bind(del_id)
            .bind(target_product_id)
            .execute(&state.db)
            .await;
    }

    // Gallery uploads
    for (fname, bytes) in gallery_files {
        if let Ok(info) = storage
            .store_file(
                &state.db,
                "product",
                &fname,
                &bytes,
                StoreOptions {
                    owner_type: Some("product".to_string()),
                    owner_id: Some(target_product_id),
                    visibility: Some("public".to_string()),
                    is_test: false,
                },
            )
            .await
        {
            let _ = sqlx::query(
                "INSERT INTO product_images (product_id, filename, file_id, alt_text, position, is_primary)
                 VALUES (?, ?, ?, ?, 0, 0)"
            )
            .bind(target_product_id)
            .bind(&info.storage_key)
            .bind(info.id)
            .bind(&name)
            .execute(&state.db)
            .await;
        }
    }

    // Variable product options & variants handling
    if product_type == "variable" {
        if let Ok(opts) = serde_json::from_str::<Vec<serde_json::Value>>(&options_json) {
            let mut val_map: HashMap<(String, String), (u32, u32)> = HashMap::new();

            for (opt_pos, opt) in opts.into_iter().enumerate() {
                let opt_name = opt["name"].as_str().unwrap_or("").trim().to_string();
                if opt_name.is_empty() {
                    continue;
                }
                let display_type = opt["display_type"].as_str().unwrap_or("button");

                let opt_id: u32 = if let Some(oid) = opt["id"].as_u64() {
                    let _ = sqlx::query(
                        "UPDATE product_options SET name = ?, display_type = ?, position = ? WHERE id = ?"
                    )
                    .bind(&opt_name)
                    .bind(display_type)
                    .bind((opt_pos + 1) as i32)
                    .bind(oid)
                    .execute(&state.db)
                    .await;
                    oid as u32
                } else {
                    let res = sqlx::query(
                        "INSERT INTO product_options (product_id, name, display_type, position) VALUES (?, ?, ?, ?)"
                    )
                    .bind(target_product_id)
                    .bind(&opt_name)
                    .bind(display_type)
                    .bind((opt_pos + 1) as i32)
                    .execute(&state.db)
                    .await;
                    res.map(|r| r.last_insert_id() as u32).unwrap_or(0)
                };

                if let Some(vals) = opt["values"].as_array() {
                    for (val_pos, v) in vals.iter().enumerate() {
                        let val_str = v["value"].as_str().unwrap_or("").trim().to_string();
                        if val_str.is_empty() {
                            continue;
                        }
                        let swatch = v["swatch_hex"].as_str();

                        let val_id: u32 = if let Some(vid) = v["id"].as_u64() {
                            let _ = sqlx::query(
                                "UPDATE product_option_values SET value = ?, swatch_hex = ?, position = ? WHERE id = ?"
                            )
                            .bind(&val_str)
                            .bind(swatch)
                            .bind((val_pos + 1) as i32)
                            .bind(vid)
                            .execute(&state.db)
                            .await;
                            vid as u32
                        } else {
                            let res = sqlx::query(
                                "INSERT INTO product_option_values (option_id, value, swatch_hex, position) VALUES (?, ?, ?, ?)"
                            )
                            .bind(opt_id)
                            .bind(&val_str)
                            .bind(swatch)
                            .bind((val_pos + 1) as i32)
                            .execute(&state.db)
                            .await;
                            res.map(|r| r.last_insert_id() as u32).unwrap_or(0)
                        };

                        val_map.insert((opt_name.to_lowercase(), val_str.to_lowercase()), (opt_id, val_id));
                    }
                }
            }

            // Process variants
            if let Ok(vars) = serde_json::from_str::<Vec<serde_json::Value>>(&variants_json) {
                let mut active_hashes = Vec::new();

                for (v_pos, v) in vars.into_iter().enumerate() {
                    let combo = v["combo"].as_array();
                    if combo.is_none() {
                        continue;
                    }

                    let mut val_ids: Vec<u32> = Vec::new();
                    let mut link_pairs = Vec::new();

                    for c in combo.unwrap() {
                        let oname = c["option_name"].as_str().unwrap_or("").trim().to_lowercase();
                        let oval = c["value"].as_str().unwrap_or("").trim().to_lowercase();
                        if let Some(&(oid, vid)) = val_map.get(&(oname, oval)) {
                            val_ids.push(vid);
                            link_pairs.push((oid, vid));
                        }
                    }

                    if val_ids.is_empty() {
                        continue;
                    }

                    val_ids.sort();
                    let hash_str = val_ids.iter().map(|id| id.to_string()).collect::<Vec<_>>().join("-");
                    let option_hash = format!("{:x}", md5::compute(hash_str.as_bytes()));
                    active_hashes.push(option_hash.clone());

                    let v_sku = v["sku"].as_str().filter(|s| !s.trim().is_empty());
                    let v_bc = v["barcode"].as_str().filter(|s| !s.trim().is_empty());
                    let v_price = v["price"].as_str().filter(|s| !s.trim().is_empty());
                    let v_sale = v["sale_price"].as_str().filter(|s| !s.trim().is_empty());
                    let v_weight = v["weight_g"].as_str().and_then(|w| w.parse::<i32>().ok());
                    let v_img = v["image"].as_str().filter(|s| !s.trim().is_empty());
                    let v_status = if v["status"].as_str() == Some("inactive") { "inactive" } else { "active" };
                    let target_stock = v["stock"].as_i64().unwrap_or(0) as i32;

                    let existing_var: Option<(u32, i32)> = sqlx::query_as(
                        "SELECT id, stock FROM product_variants WHERE product_id = ? AND option_hash = ? LIMIT 1"
                    )
                    .bind(target_product_id)
                    .bind(&option_hash)
                    .fetch_optional(&state.db)
                    .await
                    .unwrap_or(None);

                    let (var_id, cur_stock) = if let Some((eid, estock)) = existing_var {
                        let _ = sqlx::query(
                            "UPDATE product_variants SET
                                sku = ?, barcode = ?, price = ?, sale_price = ?, weight_g = ?,
                                image = ?, status = ?, position = ?
                             WHERE id = ?"
                        )
                        .bind(v_sku)
                        .bind(v_bc)
                        .bind(v_price)
                        .bind(v_sale)
                        .bind(v_weight)
                        .bind(v_img)
                        .bind(v_status)
                        .bind((v_pos + 1) as i32)
                        .bind(eid)
                        .execute(&state.db)
                        .await;
                        (eid as u64, estock)
                    } else {
                        let res = sqlx::query(
                            "INSERT INTO product_variants
                                (product_id, option_hash, sku, barcode, price, sale_price, stock, weight_g, image, status, position)
                             VALUES
                                (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)"
                        )
                        .bind(target_product_id)
                        .bind(&option_hash)
                        .bind(v_sku)
                        .bind(v_bc)
                        .bind(v_price)
                        .bind(v_sale)
                        .bind(v_weight)
                        .bind(v_img)
                        .bind(v_status)
                        .bind((v_pos + 1) as i32)
                        .execute(&state.db)
                        .await;

                        let new_vid = res.map(|r| r.last_insert_id()).unwrap_or(0);
                        for (oid, vid) in link_pairs {
                            let _ = sqlx::query(
                                "INSERT IGNORE INTO product_variant_values (variant_id, option_id, option_value_id) VALUES (?, ?, ?)"
                            )
                            .bind(new_vid)
                            .bind(oid)
                            .bind(vid)
                            .execute(&state.db)
                            .await;
                        }
                        (new_vid, 0)
                    };

                    let delta = target_stock - cur_stock;
                    if delta != 0 {
                        let _ = stock_adjust(
                            &state.db,
                            target_product_id,
                            Some(var_id),
                            delta,
                            "manual_adjust",
                            None,
                            Some(admin.id),
                            Some("Product form variant stock update"),
                        )
                        .await;
                    }
                }
            }
        }
    }

    log_activity(
        &state.db,
        Some(admin.id),
        if id > 0 { "product.update" } else { "product.create" },
        &format!("Saved product {}", name),
        None,
    )
    .await;

    SessionHelper::add_flash(
        &session,
        "success",
        if id > 0 { "Product updated." } else { "Product created." },
    )
    .await;

    Redirect::to("/admin/products.php").into_response()
}

// =================================================================
// 3. Categories List & Form
// =================================================================

#[derive(Debug, Deserialize)]
pub struct CategoryFormQuery {
    pub id: Option<u32>,
}

#[derive(Debug, Deserialize)]
pub struct CategoryPostForm {
    pub action: Option<String>,
    pub id: Option<u32>,
    pub name: Option<String>,
    pub slug: Option<String>,
    pub parent_id: Option<u32>,
    pub description: Option<String>,
    pub status: Option<String>,
}

pub async fn admin_categories_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<ProductsQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("categories.manage") && admin.role != "admin" && admin.role != "editor" {
        return (StatusCode::FORBIDDEN, Html("<h1>403 Forbidden</h1>")).into_response();
    }

    let page = query.page.unwrap_or(1).max(1);
    let per_page = query.per_page.unwrap_or(15).clamp(5, 100);

    let (rows, total) = get_categories_admin(
        &state.db,
        page,
        per_page,
        query.q.as_deref(),
        query.status.as_deref(),
    )
    .await
    .unwrap_or((Vec::new(), 0));

    let total_pages = (total as f64 / per_page as f64).ceil() as i64;

    let mut ctx = build_admin_context(&state, &session, "categories.php", "Categories").await;
    ctx.insert("rows", &rows);
    ctx.insert("categories", &rows);
    ctx.insert("total", &total);
    ctx.insert("current_page", &page);
    ctx.insert("total_pages", &total_pages.max(1));
    ctx.insert("search", query.q.as_deref().unwrap_or(""));
    ctx.insert("status", query.status.as_deref().unwrap_or(""));

    match state.tera.render("admin/categories.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => {
            let mut err_msg = format!("Template error: {}", e);
            let mut cur: &dyn std::error::Error = &e;
            while let Some(source) = cur.source() {
                err_msg.push_str(&format!(" -> {}", source));
                cur = source;
            }
            (StatusCode::INTERNAL_SERVER_ERROR, err_msg).into_response()
        }
    }
}

pub async fn admin_categories_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<CategoryPostForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("categories.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Permission denied.").await;
        return Redirect::to("/admin/categories.php").into_response();
    }

    if form.action.as_deref() == Some("delete") {
        let cid = form.id.unwrap_or(0);
        if let Ok(Some(name)) = delete_category(&state.db, cid).await {
            log_activity(
                &state.db,
                Some(admin.id),
                "category.delete",
                &format!("Deleted category {}", name),
                None,
            )
            .await;
            SessionHelper::add_flash(&session, "success", "Category deleted.").await;
        } else {
            SessionHelper::add_flash(&session, "warning", "Category not found.").await;
        }
    }

    Redirect::to("/admin/categories.php").into_response()
}

pub async fn admin_category_form_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<CategoryFormQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("categories.manage") && admin.role != "admin" && admin.role != "editor" {
        return (StatusCode::FORBIDDEN, Html("<h1>403 Forbidden</h1>")).into_response();
    }

    let id = query.id.unwrap_or(0);
    let page_title = if id > 0 { "Edit category" } else { "Add category" };

    let mut cat_data = serde_json::json!({
        "id": 0, "name": "", "slug": "", "description": "", "status": "active"
    });

    if id > 0 {
        let row: Option<(u32, String, String, Option<String>, String)> = sqlx::query_as(
            "SELECT id, name, slug, description, status FROM categories WHERE id = ? LIMIT 1",
        )
        .bind(id)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if let Some(c) = row {
            cat_data = serde_json::json!({
                "id": c.0, "name": c.1, "slug": c.2, "description": c.3.unwrap_or_default(), "status": c.4
            });
        } else {
            SessionHelper::add_flash(&session, "warning", "Category not found.").await;
            return Redirect::to("/admin/categories.php").into_response();
        }
    }

    let mut ctx = build_admin_context(&state, &session, "category_form.php", page_title).await;
    ctx.insert("id", &id);
    ctx.insert("cat", &cat_data);
    ctx.insert("category", &cat_data);
    let empty_errors: Vec<String> = Vec::new();
    ctx.insert("errors", &empty_errors);

    match state.tera.render("admin/category_form.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => {
            let mut err_msg = format!("Template error: {}", e);
            let mut cur: &dyn std::error::Error = &e;
            while let Some(source) = cur.source() {
                err_msg.push_str(&format!(" -> {}", source));
                cur = source;
            }
            (StatusCode::INTERNAL_SERVER_ERROR, err_msg).into_response()
        }
    }
}

pub async fn admin_category_form_post_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<CategoryFormQuery>,
    Form(form): Form<CategoryPostForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("categories.manage") && admin.role != "admin" && admin.role != "editor" {
        SessionHelper::add_flash(&session, "danger", "Permission denied.").await;
        return Redirect::to("/admin/categories.php").into_response();
    }

    let id = query.id.or(form.id);
    let name = form.name.as_deref().unwrap_or("").trim();
    let slug = form.slug.as_deref().unwrap_or("").trim();
    let desc = form.description.as_deref();
    let status = match form.status.as_deref() {
        Some("inactive") => "inactive",
        _ => "active",
    };

    if name.is_empty() {
        SessionHelper::add_flash(&session, "danger", "Name is required.").await;
        let redir = if let Some(cid) = id {
            format!("/admin/category_form.php?id={}", cid)
        } else {
            "/admin/category_form.php".to_string()
        };
        return Redirect::to(&redir).into_response();
    }

    match save_category(&state.db, id, name, slug, form.parent_id, desc, status).await {
        Ok(_) => {
            log_activity(
                &state.db,
                Some(admin.id),
                if id.is_some() { "category.update" } else { "category.create" },
                &format!("Saved category {}", name),
                None,
            )
            .await;
            SessionHelper::add_flash(
                &session,
                "success",
                if id.is_some() { "Category updated." } else { "Category created." },
            )
            .await;
            Redirect::to("/admin/categories.php").into_response()
        }
        Err(e) => {
            SessionHelper::add_flash(&session, "danger", &format!("Database error: {}", e)).await;
            Redirect::to("/admin/categories.php").into_response()
        }
    }
}

// =================================================================
// 4. Inventory & Stock Ledger
// =================================================================

#[derive(Debug, Deserialize)]
pub struct InventoryQuery {
    pub tab: Option<String>,
    pub filter: Option<String>,
    pub q: Option<String>,
    pub reason: Option<String>,
    pub page: Option<i64>,
    pub per_page: Option<i64>,
    pub export: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct AdjustStockForm {
    pub action: Option<String>,
    pub product_id: Option<u64>,
    pub variant_id: Option<u64>,
    pub delta: Option<i32>,
    pub reason: Option<String>,
    pub note: Option<String>,
}

pub async fn admin_inventory_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<InventoryQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("inventory.view")
        && !admin.has_permission("inventory.manage")
        && admin.role != "admin"
        && admin.role != "editor"
    {
        return (StatusCode::FORBIDDEN, Html("<h1>403 Forbidden</h1>")).into_response();
    }

    let tab = match query.tab.as_deref() {
        Some("ledger") => "ledger",
        Some("import") => "import",
        _ => "overview",
    };

    let page = query.page.unwrap_or(1).max(1);
    let per_page = query.per_page.unwrap_or(50).clamp(10, 100);

    // CSV Export for Ledger
    if tab == "ledger" && query.export.as_deref() == Some("csv") {
        let (rows, _) = get_inventory_movements(
            &state.db,
            1,
            5000,
            None,
            query.reason.as_deref(),
        )
        .await
        .unwrap_or((Vec::new(), 0));

        let mut csv_data = vec![0xEF, 0xBB, 0xBF]; // UTF-8 BOM
        {
            let mut writer = csv::Writer::from_writer(&mut csv_data);
            let _ = writer.write_record(&[
                "ID",
                "Date",
                "Product",
                "Variant",
                "Change Qty",
                "Stock After",
                "Reason",
                "Order Number",
                "User",
                "Note",
            ]);

            for r in rows {
                let _ = writer.write_record(&[
                    r.id.to_string(),
                    r.created_at.format("%Y-%m-%d %H:%M:%S").to_string(),
                    r.product_name,
                    r.variant_id.map(|v| v.to_string()).unwrap_or_default(),
                    r.change_qty.to_string(),
                    r.stock_after.map(|s| s.to_string()).unwrap_or_default(),
                    r.reason,
                    r.order_id.map(|o| o.to_string()).unwrap_or_default(),
                    r.user_name.unwrap_or_else(|| "System".to_string()),
                    r.note.unwrap_or_default(),
                ]);
            }
            let _ = writer.flush();
        }

        let mut headers = HeaderMap::new();
        headers.insert(header::CONTENT_TYPE, HeaderValue::from_static("text/csv; charset=utf-8"));
        let cd = format!("attachment; filename=\"inventory_ledger_{}.csv\"", Utc::now().format("%Y%m%d_%H%M%S"));
        if let Ok(v) = HeaderValue::from_str(&cd) {
            headers.insert(header::CONTENT_DISPOSITION, v);
        }
        return (headers, csv_data).into_response();
    }

    let mut ctx = build_admin_context(&state, &session, "inventory.php", "Inventory & Stock Ledger").await;
    ctx.insert("subTab", tab);
    ctx.insert("sub_tab", tab);

    if tab == "overview" {
        let (items, total_items) = get_inventory_overview(
            &state.db,
            page,
            per_page,
            query.q.as_deref(),
            query.filter.as_deref(),
        )
        .await
        .unwrap_or((Vec::new(), 0));

        let total_pages = (total_items as f64 / per_page as f64).ceil() as i64;
        ctx.insert("inventoryItems", &items);
        ctx.insert("items", &items);
        ctx.insert("totalItems", &total_items);
        ctx.insert("total_items", &total_items);
        ctx.insert("totalPages", &total_pages.max(1));
        ctx.insert("total_pages", &total_pages.max(1));
        ctx.insert("currentPage", &page);
        ctx.insert("current_page", &page);
        ctx.insert("search", query.q.as_deref().unwrap_or(""));
        ctx.insert("stockFilter", query.filter.as_deref().unwrap_or("all"));
        ctx.insert("stock_filter", query.filter.as_deref().unwrap_or("all"));
        let empty_ledger: Vec<serde_json::Value> = Vec::new();
        ctx.insert("ledger", &empty_ledger);
    } else if tab == "ledger" {
        let (rows, total_movements) = get_inventory_movements(
            &state.db,
            page,
            per_page,
            None,
            query.reason.as_deref(),
        )
        .await
        .unwrap_or((Vec::new(), 0));

        let total_pages = (total_movements as f64 / per_page as f64).ceil() as i64;
        ctx.insert("ledgerRows", &rows);
        ctx.insert("ledger", &rows);
        ctx.insert("rows", &rows);
        ctx.insert("totalMovements", &total_movements);
        ctx.insert("total_movements", &total_movements);
        ctx.insert("totalPages", &total_pages.max(1));
        ctx.insert("total_pages", &total_pages.max(1));
        ctx.insert("currentPage", &page);
        ctx.insert("current_page", &page);
        ctx.insert("reasonFilter", query.reason.as_deref().unwrap_or(""));
        ctx.insert("reason_filter", query.reason.as_deref().unwrap_or(""));
        let empty_items: Vec<serde_json::Value> = Vec::new();
        ctx.insert("items", &empty_items);
        ctx.insert("stock_filter", "all");
    }

    match state.tera.render("admin/inventory.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => {
            let mut err_msg = format!("Template error: {}", e);
            let mut cur: &dyn std::error::Error = &e;
            while let Some(source) = cur.source() {
                err_msg.push_str(&format!(" -> {}", source));
                cur = source;
            }
            (StatusCode::INTERNAL_SERVER_ERROR, err_msg).into_response()
        }
    }
}

pub async fn admin_inventory_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<AdjustStockForm>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("inventory.manage") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Permission denied.").await;
        return Redirect::to("/admin/inventory.php").into_response();
    }

    if form.action.as_deref() == Some("adjust_stock") {
        let pid = form.product_id.unwrap_or(0);
        let vid = form.variant_id;
        let delta = form.delta.unwrap_or(0);
        let reason = form.reason.as_deref().unwrap_or("manual_adjust");
        let note = form.note.as_deref();

        if delta == 0 {
            SessionHelper::add_flash(&session, "warning", "Adjustment quantity cannot be 0.").await;
        } else {
            match stock_adjust(
                &state.db,
                pid,
                vid,
                delta,
                reason,
                None,
                Some(admin.id),
                note,
            )
            .await
            {
                Ok(true) => {
                    log_activity(
                        &state.db,
                        Some(admin.id),
                        "inventory.adjust",
                        &format!("Adjusted stock for P:{} V:{:?} by {} ({})", pid, vid, delta, reason),
                        None,
                    )
                    .await;
                    SessionHelper::add_flash(
                        &session,
                        "success",
                        &format!("Stock successfully adjusted by {}.", delta),
                    )
                    .await;
                }
                Ok(false) => {
                    SessionHelper::add_flash(
                        &session,
                        "danger",
                        "Unable to adjust stock. (Cannot reduce stock below 0 if backorders are disabled).",
                    )
                    .await;
                }
                Err(e) => {
                    SessionHelper::add_flash(
                        &session,
                        "danger",
                        &format!("Database error during adjustment: {}", e),
                    )
                    .await;
                }
            }
        }
    }

    Redirect::to("/admin/inventory.php?tab=overview").into_response()
}
