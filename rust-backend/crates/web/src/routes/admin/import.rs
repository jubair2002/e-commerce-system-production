use axum::{
    extract::{Multipart, Query, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{Html, IntoResponse, Redirect, Response},
};
use common::formatters::slugify;
use db::queries::activity::log_activity;
use db::queries::admin::get_unique_slug;
use db::queries::inventory::stock_adjust;
use rust_decimal::Decimal;
use serde::Deserialize;
use std::collections::HashMap;
use tower_sessions::Session;

use crate::state::{build_admin_context, AppState, SessionHelper};

pub fn normalize_csv_header(header: &str) -> String {
    let mut h = header.trim().to_lowercase();
    if h.starts_with('\u{feff}') {
        h = h.trim_start_matches('\u{feff}').to_string();
    }
    let stripped: String = h.chars().filter(|c| *c != ' ' && *c != '-' && *c != '_').collect();

    match stripped.as_str() {
        "name" | "productname" | "title" | "itemname" | "producttitle" | "item" => "name".to_string(),
        "sku" | "productsku" | "itemsku" | "itemcode" | "code" | "barcode" => "sku".to_string(),
        "producttype" | "type" | "itemtype" | "kind" | "productkind" | "productswithoption" | "productwithoptions" | "productswithoptions" | "productwithoption" => "product_type".to_string(),
        "category" | "categories" | "categoryname" | "cat" | "cats" | "collection" | "collections" | "department" | "departments" | "productcategory" | "productcategories" | "taxonomies" => "category".to_string(),
        "price" | "regularprice" | "retailprice" | "cost" | "mrp" | "standardprice" => "price".to_string(),
        "saleprice" | "discountprice" | "discountedprice" | "specialprice" | "offerprice" => "sale_price".to_string(),
        "stock" | "quantity" | "qty" | "inventory" | "stockcount" | "amount" => "stock".to_string(),
        "shortdescription" | "summary" | "brief" | "tagline" | "shortdesc" => "short_description".to_string(),
        "description" | "details" | "body" | "fulldescription" | "content" | "desc" => "description".to_string(),
        "brand" | "manufacturer" | "vendor" | "maker" => "brand".to_string(),
        "tags" | "labels" => "tags".to_string(),
        "weight" | "weightg" | "weightinms" | "weightgrams" => "weight_g".to_string(),
        "image" | "imageurl" | "img" | "photo" | "picture" | "thumbnail" => "image".to_string(),
        "status" | "visibility" | "state" => "status".to_string(),
        "featured" | "isfeatured" | "highlight" | "promoted" => "featured".to_string(),
        "lowstockthreshold" | "lowstock" | "alertstock" => "low_stock".to_string(),
        "metatitle" | "seotitle" | "pagetitle" | "headertitle" | "seometatitle" => "meta_title".to_string(),
        "metadescription" | "seodescription" | "metadesc" | "seodesc" | "meta_desc" | "seometadescription" => "meta_description".to_string(),
        "metakeywords" | "seokeywords" | "keywords" | "metatags" | "seometakeywords" => "meta_keywords".to_string(),
        "parent" | "parentsku" | "parentname" | "parentid" => "parent".to_string(),
        "color" | "colour" | "colors" | "colours" => "color".to_string(),
        "size" | "sizes" => "size".to_string(),
        "model" | "models" => "model".to_string(),
        "storage" | "capacity" => "storage".to_string(),
        _ => stripped,
    }
}

pub fn detect_csv_delimiter(content: &str) -> u8 {
    let first_line = content.lines().next().unwrap_or("");
    let comma_count = first_line.matches(',').count();
    let semi_count = first_line.matches(';').count();
    let tab_count = first_line.matches('\t').count();

    if semi_count > comma_count && semi_count > tab_count {
        b';'
    } else if tab_count > comma_count && tab_count > semi_count {
        b'\t'
    } else {
        b','
    }
}

#[derive(Debug, Deserialize)]
pub struct ImportQuery {
    pub action: Option<String>,
}

pub async fn admin_product_import_get_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<ImportQuery>,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("products.create") && !admin.has_permission("products.edit") && admin.role != "admin" {
        return (StatusCode::FORBIDDEN, Html("<h1>403 Forbidden</h1>")).into_response();
    }

    // Action 1: Sample CSV Template Download
    if query.action.as_deref() == Some("sample_csv") {
        let mut csv_data = vec![0xEF, 0xBB, 0xBF]; // UTF-8 BOM
        {
            let mut writer = csv::Writer::from_writer(&mut csv_data);

            let _ = writer.write_record(&[
                "name", "sku", "product_type", "category", "price", "sale_price", "stock",
                "color", "size", "model", "short_description", "description", "brand", "tags",
                "meta_title", "meta_description", "meta_keywords", "status", "featured"
            ]);

            let _ = writer.write_record(&[
                "Semi-Transparent Jelly Magnetic Phone Case",
                "JP-GADT-CASE-001",
                "Products with option",
                "Gadgets",
                "650.00",
                "600.00",
                "60",
                "Black, Navy Blue, Midnight Green",
                "",
                "iPhone 13, iPhone 14, iPhone 15 Pro",
                "Semi-transparent jelly magnetic phone case with liquid silicone finish.",
                "High quality shockproof magnetic liquid silicone phone case compatible with MagSafe chargers and accessories.",
                "Jinishpotro",
                "case, iphone, magsafe, accessories",
                "Magnetic Liquid Silicone Case Bangladesh | Jinishpotro",
                "Buy authentic magnetic jelly silicone iPhone case in BD. Cash on delivery available nationwide.",
                "iphone case, magsafe case, liquid silicone, jinishpotro",
                "active",
                "1"
            ]);

            let _ = writer.write_record(&[
                "Minimalist Slim Leather Wallet",
                "WLLT-LTH-03",
                "simple",
                "Accessories",
                "850.00",
                "750.00",
                "25",
                "Brown",
                "",
                "",
                "Slim bi-fold genuine top-grain leather wallet.",
                "Ultra-slim profile holding up to 8 cards and full-length cash slot. Built with RFID-blocking security lining.",
                "Jinishpotro",
                "wallet, leather, accessories",
                "Slim Genuine Leather Wallet BD | Jinishpotro",
                "Minimalist RFID-blocking top-grain leather wallet at best price in Bangladesh.",
                "leather wallet, bifold wallet, rfid wallet, jinishpotro",
                "active",
                "0"
            ]);

            let _ = writer.flush();
        }

        let mut headers = HeaderMap::new();
        headers.insert(header::CONTENT_TYPE, HeaderValue::from_static("text/csv; charset=utf-8"));
        headers.insert(
            header::CONTENT_DISPOSITION,
            HeaderValue::from_static("attachment; filename=\"products_import_sample_template.csv\""),
        );
        return (headers, csv_data).into_response();
    }

    let ctx = build_admin_context(&state, &session, "product_import.php", "Import Products via CSV").await;
    match state.tera.render("admin/product_import.html", &ctx) {
        Ok(html) => Html(html).into_response(),
        Err(e) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            format!("Template error: {}", e),
        )
            .into_response(),
    }
}

pub async fn admin_product_import_post_handler(
    State(state): State<AppState>,
    session: Session,
    mut multipart: Multipart,
) -> Response {
    let admin = match SessionHelper::get_user(&session).await {
        Some(a) => a,
        None => return Redirect::to("/admin/login.php").into_response(),
    };

    if !admin.has_permission("products.create") && !admin.has_permission("products.edit") && admin.role != "admin" {
        SessionHelper::add_flash(&session, "danger", "Permission denied.").await;
        return Redirect::to("/admin/products.php").into_response();
    }

    let mut csv_bytes: Option<Vec<u8>> = None;
    let mut update_existing = false;

    while let Ok(Some(field)) = multipart.next_field().await {
        let name = field.name().unwrap_or("").to_string();
        if name == "csv_file" {
            if let Ok(bytes) = field.bytes().await {
                if !bytes.is_empty() {
                    csv_bytes = Some(bytes.to_vec());
                }
            }
        } else if name == "update_existing" {
            update_existing = true;
        }
    }

    let raw_content = match csv_bytes {
        Some(b) => String::from_utf8_lossy(&b).to_string(),
        None => {
            SessionHelper::add_flash(&session, "danger", "Please select a valid CSV file to upload.").await;
            return Redirect::to("/admin/product_import.php").into_response();
        }
    };

    let delim = detect_csv_delimiter(&raw_content);
    let mut rdr = csv::ReaderBuilder::new()
        .delimiter(delim)
        .flexible(true)
        .from_reader(raw_content.as_bytes());

    let raw_headers = match rdr.headers() {
        Ok(h) => h.clone(),
        Err(e) => {
            SessionHelper::add_flash(&session, "danger", &format!("Malformed CSV header: {}", e)).await;
            return Redirect::to("/admin/product_import.php").into_response();
        }
    };

    let mut header_map: HashMap<String, usize> = HashMap::new();
    for (idx, h) in raw_headers.iter().enumerate() {
        header_map.insert(normalize_csv_header(h), idx);
    }

    if !header_map.contains_key("name") || !header_map.contains_key("price") {
        SessionHelper::add_flash(
            &session,
            "danger",
            "CSV is missing required columns. It must include 'name' and 'price'.",
        )
        .await;
        return Redirect::to("/admin/product_import.php").into_response();
    }

    let mut imported_count = 0;
    let mut updated_count = 0;
    let mut skipped_count = 0;
    let mut errors = Vec::new();

    for (row_idx, record) in rdr.records().enumerate() {
        let rec = match record {
            Ok(r) => r,
            Err(e) => {
                errors.push(format!("Row {}: Parse error: {}", row_idx + 2, e));
                skipped_count += 1;
                continue;
            }
        };

        let get_col = |name: &str| -> String {
            header_map.get(name).and_then(|&idx| rec.get(idx)).unwrap_or("").trim().to_string()
        };

        let name = get_col("name");
        if name.is_empty() {
            skipped_count += 1;
            continue;
        }

        let sku = get_col("sku");
        let price_str = get_col("price");
        let sale_price_str = get_col("sale_price");
        let stock_str = get_col("stock");
        let cat_name = get_col("category");
        let short_desc = get_col("short_description");
        let desc = get_col("description");
        let brand = get_col("brand");
        let tags = get_col("tags");
        let status = match get_col("status").to_lowercase().as_str() {
            "inactive" => "inactive",
            "draft" => "draft",
            _ => "active",
        };
        let featured = if get_col("featured") == "1" { 1 } else { 0 };

        let price_dec = price_str.parse::<Decimal>().unwrap_or(Decimal::ZERO);
        let sale_price_dec = if !sale_price_str.is_empty() {
            sale_price_str.parse::<Decimal>().ok()
        } else {
            None
        };
        let stock_qty = stock_str.parse::<i32>().unwrap_or(0);

        // Resolve or create category
        let category_id: u32 = if !cat_name.is_empty() {
            let cat_row: Option<(u32,)> = sqlx::query_as(
                "SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1"
            )
            .bind(&cat_name)
            .fetch_optional(&state.db)
            .await
            .unwrap_or(None);

            if let Some((cid,)) = cat_row {
                cid
            } else {
                let cat_slug = get_unique_slug(&state.db, &slugify(&cat_name), "categories", 0)
                    .await
                    .unwrap_or_else(|_| slugify(&cat_name));
                let ins_cat = sqlx::query(
                    "INSERT INTO categories (name, slug, status, created_at, updated_at) VALUES (?, ?, 'active', NOW(), NOW())"
                )
                .bind(&cat_name)
                .bind(&cat_slug)
                .execute(&state.db)
                .await;
                ins_cat.map(|r| r.last_insert_id() as u32).unwrap_or(0)
            }
        } else {
            0
        };

        // Check if SKU exists
        let existing_prod: Option<(u64, String, i32)> = if !sku.is_empty() {
            sqlx::query_as(
                "SELECT id, name, stock FROM products WHERE sku = ? LIMIT 1"
            )
            .bind(&sku)
            .fetch_optional(&state.db)
            .await
            .unwrap_or(None)
        } else {
            None
        };

        if let Some((existing_id, _pname, old_stock)) = existing_prod {
            if update_existing {
                let _ = sqlx::query(
                    "UPDATE products SET
                        category_id = ?, name = ?, price = ?, sale_price = ?, brand = ?,
                        status = ?, updated_at = NOW()
                     WHERE id = ?"
                )
                .bind(if category_id > 0 { Some(category_id) } else { None })
                .bind(&name)
                .bind(price_dec)
                .bind(sale_price_dec)
                .bind(if brand.is_empty() { None } else { Some(&brand) })
                .bind(status)
                .bind(existing_id)
                .execute(&state.db)
                .await;

                let delta = stock_qty - old_stock;
                if delta != 0 {
                    let _ = stock_adjust(
                        &state.db,
                        existing_id,
                        None,
                        delta,
                        "restock",
                        None,
                        Some(admin.id),
                        Some("CSV import update"),
                    )
                    .await;
                }
                updated_count += 1;
            } else {
                skipped_count += 1;
            }
            continue;
        }

        // Insert new product
        let base_slug = slugify(&name);
        let final_slug = get_unique_slug(&state.db, &base_slug, "products", 0)
            .await
            .unwrap_or(base_slug);

        let ins_res = sqlx::query(
            "INSERT INTO products
                (category_id, name, slug, product_type, sku, short_description, description,
                 price, sale_price, stock, status, featured, brand, tags, track_inventory,
                 created_at, updated_at)
             VALUES
                (?, ?, ?, 'simple', ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 1, NOW(), NOW())"
        )
        .bind(if category_id > 0 { Some(category_id) } else { None })
        .bind(&name)
        .bind(&final_slug)
        .bind(if sku.is_empty() { None } else { Some(&sku) })
        .bind(if short_desc.is_empty() { None } else { Some(&short_desc) })
        .bind(if desc.is_empty() { None } else { Some(&desc) })
        .bind(price_dec)
        .bind(sale_price_dec)
        .bind(status)
        .bind(featured)
        .bind(if brand.is_empty() { None } else { Some(&brand) })
        .bind(if tags.is_empty() { None } else { Some(&tags) })
        .execute(&state.db)
        .await;

        match ins_res {
            Ok(r) => {
                let pid = r.last_insert_id();
                if stock_qty > 0 {
                    let _ = stock_adjust(
                        &state.db,
                        pid,
                        None,
                        stock_qty,
                        "restock",
                        None,
                        Some(admin.id),
                        Some("CSV product import"),
                    )
                    .await;
                }
                imported_count += 1;
            }
            Err(e) => {
                errors.push(format!("Row {}: Insert failed: {}", row_idx + 2, e));
                skipped_count += 1;
            }
        }
    }

    log_activity(
        &state.db,
        Some(admin.id),
        "product.import",
        &format!(
            "Imported {} products, updated {}, skipped {} via CSV",
            imported_count, updated_count, skipped_count
        ),
        None,
    )
    .await;

    SessionHelper::add_flash(
        &session,
        "success",
        &format!(
            "Import complete! Created {} new product(s), updated {}, skipped {}.",
            imported_count, updated_count, skipped_count
        ),
    )
    .await;

    Redirect::to("/admin/products.php").into_response()
}
