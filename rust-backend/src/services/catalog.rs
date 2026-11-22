use rust_decimal::Decimal;
use sqlx::{MySql, Pool, Row};

use crate::models::content::StorefrontBanner;
use crate::models::product::{
    Category, CategoryWithCount, Product, ProductCardItem, ProductColorSwatch, ProductImage,
    ProductOption, ProductOptionValue, ProductVariant,
};
use crate::money::{format_money, round_money};

#[derive(Clone, Debug, serde::Serialize, serde::Deserialize)]
pub struct ProductReviewItem {
    pub id: u64,
    pub product_id: u64,
    pub author_name: String,
    pub rating: i32,
    pub title: Option<String>,
    pub body: String,
    pub product_name: String,
    pub product_slug: String,
    pub product_image: Option<String>,
    pub product_image_url: String,
}

#[derive(Clone, Debug, serde::Serialize, serde::Deserialize)]
pub struct ProductOptionWithValues {
    pub id: u64,
    pub name: String,
    pub display_type: String,
    pub position: i32,
    pub values: Vec<ProductOptionValue>,
}

#[derive(Clone, Debug, serde::Serialize, serde::Deserialize)]
pub struct ProductVariantDetail {
    pub id: u64,
    pub product_id: u64,
    pub option_hash: Option<String>,
    pub sku: Option<String>,
    pub barcode: Option<String>,
    pub price: Option<Decimal>,
    pub sale_price: Option<Decimal>,
    pub stock: i32,
    pub image: Option<String>,
    pub label: String,
    pub option_value_ids: Vec<u64>,
}

/// Active categories with active product counts matching PHP query
pub async fn get_active_categories(pool: &Pool<MySql>) -> Result<Vec<CategoryWithCount>, sqlx::Error> {
    let rows = sqlx::query(
        "SELECT c.id, c.name, c.slug, c.description, NULL AS image,
                (SELECT COUNT(*) FROM products p 
                 WHERE (p.category_id = c.id 
                    OR ((p.category_id IS NULL OR p.category_id = 0) 
                        AND (LOWER(p.tags) LIKE CONCAT('%', LOWER(c.slug), '%') OR LOWER(p.tags) LIKE CONCAT('%', LOWER(c.name), '%')))) 
                   AND p.status = 'active') AS product_count
         FROM categories c
         WHERE c.status = 'active'
         ORDER BY product_count DESC, c.name ASC",
    )
    .fetch_all(pool)
    .await?;

    let mut list = Vec::with_capacity(rows.len());
    for r in rows {
        list.push(CategoryWithCount {
            id: r.try_get("id")?,
            name: r.try_get("name")?,
            slug: r.try_get("slug")?,
            description: r.try_get("description")?,
            image: r.try_get("image")?,
            product_count: r.try_get("product_count").unwrap_or(0),
        });
    }
    Ok(list)
}

/// Fetch category by slug
pub async fn get_category_by_slug(
    pool: &Pool<MySql>,
    slug: &str,
) -> Result<Option<Category>, sqlx::Error> {
    sqlx::query_as("SELECT * FROM categories WHERE slug = ? AND status = 'active' LIMIT 1")
        .bind(slug)
        .fetch_optional(pool)
        .await
}

/// Fetch active banners for a specific placement
pub async fn get_banners_by_placement(
    pool: &Pool<MySql>,
    placement: &str,
) -> Result<Vec<StorefrontBanner>, sqlx::Error> {
    sqlx::query_as(
        "SELECT * FROM storefront_banners
         WHERE placement = ? AND status = 'active'
           AND (starts_at IS NULL OR starts_at <= NOW())
           AND (ends_at IS NULL OR ends_at >= NOW())
         ORDER BY position ASC, id ASC",
    )
    .bind(placement)
    .fetch_all(pool)
    .await
}

/// Fetch new arrival products
pub async fn get_new_arrivals(
    pool: &Pool<MySql>,
    limit: i64,
    currency_symbol: &str,
) -> Result<Vec<ProductCardItem>, sqlx::Error> {
    let rows: Vec<Product> = sqlx::query_as(
        "SELECT * FROM products
         WHERE status = 'active'
         ORDER BY created_at DESC, id DESC
         LIMIT ?",
    )
    .bind(limit)
    .fetch_all(pool)
    .await?;

    attach_card_data(pool, rows, currency_symbol).await
}

/// Fetch best seller products
pub async fn get_best_sellers(
    pool: &Pool<MySql>,
    limit: i64,
    currency_symbol: &str,
) -> Result<Vec<ProductCardItem>, sqlx::Error> {
    let rows: Vec<Product> = sqlx::query_as(
        "SELECT p.*
         FROM products p
         JOIN product_sales_summary pss ON pss.product_id = p.id
         WHERE p.status = 'active'
         GROUP BY p.id
         ORDER BY SUM(pss.units) DESC, p.created_at DESC
         LIMIT ?",
    )
    .bind(limit)
    .fetch_all(pool)
    .await?;

    attach_card_data(pool, rows, currency_symbol).await
}

/// Fetch featured products
pub async fn get_featured_products(
    pool: &Pool<MySql>,
    limit: i64,
    currency_symbol: &str,
) -> Result<Vec<ProductCardItem>, sqlx::Error> {
    let rows: Vec<Product> = sqlx::query_as(
        "SELECT * FROM products
         WHERE status = 'active' AND featured = 1
         ORDER BY created_at DESC
         LIMIT ?",
    )
    .bind(limit)
    .fetch_all(pool)
    .await?;

    attach_card_data(pool, rows, currency_symbol).await
}

/// Fetch customer reviews for homepage
pub async fn get_approved_reviews(
    pool: &Pool<MySql>,
    limit: i64,
) -> Result<Vec<ProductReviewItem>, sqlx::Error> {
    let rows = sqlx::query(
        "SELECT r.id, r.product_id, r.author_name, r.rating, r.title, r.body,
                p.name AS product_name, p.slug AS product_slug, p.image AS product_image
         FROM product_reviews r
         JOIN products p ON p.id = r.product_id
         WHERE r.status = 'approved' AND r.rating >= 4
         ORDER BY r.id DESC
         LIMIT ?",
    )
    .bind(limit)
    .fetch_all(pool)
    .await?;

    let mut list = Vec::with_capacity(rows.len());
    for r in rows {
        let p_img: Option<String> = r.try_get("product_image")?;
        let img_url = resolve_image_url(p_img.as_deref());
        list.push(ProductReviewItem {
            id: r.try_get("id")?,
            product_id: r.try_get("product_id")?,
            author_name: r.try_get("author_name")?,
            rating: r.try_get("rating")?,
            title: r.try_get("title")?,
            body: r.try_get("body")?,
            product_name: r.try_get("product_name")?,
            product_slug: r.try_get("product_slug")?,
            product_image: p_img,
            product_image_url: img_url,
        });
    }
    Ok(list)
}

/// Filter query parameters for shop catalog
#[derive(Clone, Debug, Default)]
pub struct ShopFilter {
    pub category_id: Option<u64>,
    pub category_slug: Option<String>,
    pub search: Option<String>,
    pub sort: Option<String>,
    pub min_price: Option<Decimal>,
    pub max_price: Option<Decimal>,
    pub tag: Option<String>,
    pub page: i64,
    pub per_page: i64,
}

/// Fetch shop products with pagination and total count
pub async fn get_shop_products(
    pool: &Pool<MySql>,
    filter: ShopFilter,
    currency_symbol: &str,
) -> Result<(Vec<ProductCardItem>, i64), sqlx::Error> {
    let mut where_clauses = vec!["p.status = 'active'".to_string()];
    let mut count_clauses = vec!["p.status = 'active'".to_string()];

    if let Some(cat_id) = filter.category_id {
        where_clauses.push(format!("p.category_id = {}", cat_id));
        count_clauses.push(format!("p.category_id = {}", cat_id));
    } else if let Some(ref cat_slug) = filter.category_slug {
        let cat: Option<Category> = sqlx::query_as("SELECT * FROM categories WHERE slug = ? LIMIT 1")
            .bind(cat_slug)
            .fetch_optional(pool)
            .await?;
        if let Some(c) = cat {
            where_clauses.push(format!("p.category_id = {}", c.id));
            count_clauses.push(format!("p.category_id = {}", c.id));
        }
    }

    if let Some(ref q) = filter.search {
        let clean = q.trim();
        if !clean.is_empty() {
            let escaped = clean.replace('\'', "''");
            let search_clause = format!(
                "(p.name LIKE '%{}%' OR p.description LIKE '%{}%' OR p.sku LIKE '%{}%' OR p.tags LIKE '%{}%')",
                escaped, escaped, escaped, escaped
            );
            where_clauses.push(search_clause.clone());
            count_clauses.push(search_clause);
        }
    }

    if let Some(min_p) = filter.min_price {
        where_clauses.push(format!("COALESCE(p.sale_price, p.price) >= {}", min_p));
        count_clauses.push(format!("COALESCE(p.sale_price, p.price) >= {}", min_p));
    }
    if let Some(max_p) = filter.max_price {
        where_clauses.push(format!("COALESCE(p.sale_price, p.price) <= {}", max_p));
        count_clauses.push(format!("COALESCE(p.sale_price, p.price) <= {}", max_p));
    }

    let order_by = match filter.sort.as_deref() {
        Some("price_asc") => "COALESCE(p.sale_price, p.price) ASC",
        Some("price_desc") => "COALESCE(p.sale_price, p.price) DESC",
        Some("rating") => "p.rating_avg DESC, p.rating_count DESC",
        Some("popularity") | Some("best_selling") => "p.sold_count DESC",
        _ => "p.created_at DESC, p.id DESC",
    };

    let count_sql = format!(
        "SELECT COUNT(*) FROM products p WHERE {}",
        count_clauses.join(" AND ")
    );
    let total: i64 = sqlx::query_scalar(&count_sql).fetch_one(pool).await?;

    let per_page = if filter.per_page <= 0 { 16 } else { filter.per_page };
    let page = if filter.page <= 0 { 1 } else { filter.page };
    let offset = (page - 1) * per_page;

    let select_sql = format!(
        "SELECT p.* FROM products p WHERE {} ORDER BY {} LIMIT {} OFFSET {}",
        where_clauses.join(" AND "),
        order_by,
        per_page,
        offset
    );

    let products: Vec<Product> = sqlx::query_as(&select_sql).fetch_all(pool).await?;
    let cards = attach_card_data(pool, products, currency_symbol).await?;

    Ok((cards, total))
}

/// Fetch single product by slug
pub async fn get_product_by_slug(
    pool: &Pool<MySql>,
    slug: &str,
) -> Result<Option<Product>, sqlx::Error> {
    sqlx::query_as("SELECT * FROM products WHERE slug = ? AND status = 'active' LIMIT 1")
        .bind(slug)
        .fetch_optional(pool)
        .await
}

/// Fetch product gallery images
pub async fn get_product_gallery(
    pool: &Pool<MySql>,
    product_id: u64,
) -> Result<Vec<ProductImage>, sqlx::Error> {
    sqlx::query_as(
        "SELECT * FROM product_images
         WHERE product_id = ?
         ORDER BY is_primary DESC, position ASC, id ASC",
    )
    .bind(product_id)
    .fetch_all(pool)
    .await
}

/// Fetch product options and values
pub async fn get_product_options_and_values(
    pool: &Pool<MySql>,
    product_id: u64,
) -> Result<Vec<ProductOptionWithValues>, sqlx::Error> {
    let options: Vec<ProductOption> = sqlx::query_as(
        "SELECT * FROM product_options
         WHERE product_id = ?
         ORDER BY position ASC, id ASC",
    )
    .bind(product_id)
    .fetch_all(pool)
    .await?;

    if options.is_empty() {
        return Ok(Vec::new());
    }

    let mut result = Vec::with_capacity(options.len());
    for opt in options {
        let values: Vec<ProductOptionValue> = sqlx::query_as(
            "SELECT * FROM product_option_values
             WHERE option_id = ?
             ORDER BY position ASC, id ASC",
        )
        .bind(opt.id)
        .fetch_all(pool)
        .await?;

        result.push(ProductOptionWithValues {
            id: opt.id,
            name: opt.name,
            display_type: opt.display_type,
            position: opt.position,
            values,
        });
    }

    Ok(result)
}

/// Fetch product variants with details and option values mapping
pub async fn get_product_variants(
    pool: &Pool<MySql>,
    product_id: u64,
) -> Result<Vec<ProductVariantDetail>, sqlx::Error> {
    let variants: Vec<ProductVariant> = sqlx::query_as(
        "SELECT * FROM product_variants
         WHERE product_id = ? AND status = 'active'
         ORDER BY position ASC, id ASC",
    )
    .bind(product_id)
    .fetch_all(pool)
    .await?;

    if variants.is_empty() {
        return Ok(Vec::new());
    }

    let mut details = Vec::with_capacity(variants.len());
    for v in variants {
        let rows = sqlx::query(
            "SELECT pvv.option_id, pvv.option_value_id,
                    po.name AS option_name, pov.value AS option_value
             FROM product_variant_values pvv
             JOIN product_options po ON po.id = pvv.option_id
             JOIN product_option_values pov ON pov.id = pvv.option_value_id
             WHERE pvv.variant_id = ?
             ORDER BY po.position ASC, po.id ASC",
        )
        .bind(v.id)
        .fetch_all(pool)
        .await?;

        let mut labels = Vec::new();
        let mut opt_val_ids = Vec::new();
        for r in rows {
            let opt_name: String = r.try_get("option_name")?;
            let opt_val: String = r.try_get("option_value")?;
            let val_id: u64 = r.try_get("option_value_id")?;
            labels.push(format!("{}: {}", opt_name, opt_val));
            opt_val_ids.push(val_id);
        }

        let label = if !labels.is_empty() {
            labels.join(" / ")
        } else {
            v.sku.clone().unwrap_or_else(|| "Default".to_string())
        };

        details.push(ProductVariantDetail {
            id: v.id,
            product_id: v.product_id,
            option_hash: v.option_hash,
            sku: v.sku,
            barcode: v.barcode,
            price: v.price,
            sale_price: v.sale_price,
            stock: v.stock,
            image: v.image,
            label,
            option_value_ids: opt_val_ids,
        });
    }

    Ok(details)
}

/// Helper to attach card data (category, swatches, images, prices) to products
pub async fn attach_card_data(
    pool: &Pool<MySql>,
    products: Vec<Product>,
    currency_symbol: &str,
) -> Result<Vec<ProductCardItem>, sqlx::Error> {
    if products.is_empty() {
        return Ok(Vec::new());
    }

    let product_ids: Vec<u64> = products.iter().map(|p| p.id).collect();

    // Batch query swatches
    let in_placeholders = product_ids.iter().map(|_| "?").collect::<Vec<_>>().join(",");
    let swatch_query = format!(
        "SELECT po.product_id, pov.value, pov.swatch_hex
         FROM product_option_values pov
         JOIN product_options po ON po.id = pov.option_id
         WHERE po.product_id IN ({})
           AND (po.display_type IN ('color', 'swatch') OR LOWER(po.name) IN ('color', 'colour'))
         ORDER BY po.product_id, pov.position, pov.id",
        in_placeholders
    );

    let mut q = sqlx::query(&swatch_query);
    for id in &product_ids {
        q = q.bind(id);
    }
    let swatch_rows = q.fetch_all(pool).await.unwrap_or_default();

    let mut swatch_map: std::collections::HashMap<u64, Vec<ProductColorSwatch>> =
        std::collections::HashMap::new();
    for r in swatch_rows {
        let pid: u64 = r.try_get("product_id").unwrap_or(0);
        let val: String = r.try_get("value").unwrap_or_default();
        let hex: Option<String> = r.try_get("swatch_hex").ok();
        swatch_map.entry(pid).or_default().push(ProductColorSwatch {
            name: val,
            color_code: hex,
        });
    }

    // Batch query category names
    let cat_ids: Vec<u64> = products.iter().filter_map(|p| p.category_id).collect();
    let mut cat_map: std::collections::HashMap<u64, (String, String)> =
        std::collections::HashMap::new();
    if !cat_ids.is_empty() {
        let cat_in = cat_ids.iter().map(|_| "?").collect::<Vec<_>>().join(",");
        let cat_query = format!("SELECT id, name, slug FROM categories WHERE id IN ({})", cat_in);
        let mut cq = sqlx::query(&cat_query);
        for cid in &cat_ids {
            cq = cq.bind(cid);
        }
        if let Ok(crows) = cq.fetch_all(pool).await {
            for cr in crows {
                let cid: u64 = cr.try_get("id").unwrap_or(0);
                let cname: String = cr.try_get("name").unwrap_or_default();
                let cslug: String = cr.try_get("slug").unwrap_or_default();
                cat_map.insert(cid, (cname, cslug));
            }
        }
    }

    let mut cards = Vec::with_capacity(products.len());
    for p in products {
        let (eff_price, is_sale, discount_pct) = match p.sale_price {
            Some(sp) if sp > Decimal::ZERO && sp < p.price => {
                let diff = p.price - sp;
                let pct = ((diff / p.price) * Decimal::from(100))
                    .to_string()
                    .parse::<f64>()
                    .ok()
                    .map(|f| f.round() as u32);
                (sp, true, pct)
            }
            _ => (p.price, false, None),
        };

        let formatted_price = format_money(Some(eff_price), currency_symbol);
        let formatted_sale_price = if is_sale {
            Some(format_money(p.sale_price, currency_symbol))
        } else {
            None
        };
        let formatted_orig_price = if is_sale {
            Some(format_money(Some(p.price), currency_symbol))
        } else {
            None
        };

        let img_url = resolve_image_url(p.image.as_deref());
        let cat_info = p.category_id.and_then(|cid| cat_map.get(&cid).cloned());
        let (category_name, category_slug) = match cat_info {
            Some((name, slug)) => (Some(name), Some(slug)),
            None => (None, None),
        };

        let colors = swatch_map.remove(&p.id).unwrap_or_default();
        let in_stock = p.stock > 0 || p.allow_backorder == 1 || p.track_inventory == 0;

        cards.push(ProductCardItem {
            id: p.id,
            category_id: p.category_id,
            name: p.name,
            slug: p.slug,
            sku: p.sku,
            price: round_money(p.price),
            sale_price: p.sale_price.map(round_money),
            effective_price: round_money(eff_price),
            is_on_sale: is_sale,
            discount_percent: discount_pct,
            stock: p.stock,
            in_stock,
            allow_backorder: p.allow_backorder == 1,
            image: p.image,
            image_url: img_url,
            category_name,
            category_slug,
            rating_avg: p.rating_avg.unwrap_or(Decimal::ZERO),
            rating_count: p.rating_count,
            is_new: false, // can check created_at < 14 days
            product_type: p.product_type,
            colors,
            formatted_price,
            formatted_sale_price,
            formatted_original_price: formatted_orig_price,
        });
    }

    Ok(cards)
}

/// Image resolver matching PHP product_image_url()
pub fn resolve_image_url(img: Option<&str>) -> String {
    match img {
        Some(s) if s.starts_with("http://") || s.starts_with("https://") => s.to_string(),
        Some(s) if s.starts_with('/') => s.to_string(),
        Some(s) if !s.trim().is_empty() => {
            let clean = s.trim_start_matches('/');
            if clean.starts_with("uploads/") || clean.starts_with("assets/uploads/") {
                format!("/{}", clean)
            } else {
                format!("/uploads/{}", clean)
            }
        }
        _ => "/assets/coza/images/product-placeholder.svg".to_string(),
    }
}

pub async fn get_active_banners(
    pool: &Pool<MySql>,
    placement: &str,
) -> Result<Vec<StorefrontBanner>, sqlx::Error> {
    get_banners_by_placement(pool, placement).await
}

#[derive(Clone, Debug, serde::Serialize, serde::Deserialize)]
pub struct ProductDetailPage {
    pub product: Product,
    pub images: Vec<ProductImage>,
    pub variants: Vec<ProductVariantDetail>,
    pub options: Vec<ProductOptionWithValues>,
    pub category: Option<Category>,
    pub is_variable: bool,
    pub rating_avg: Decimal,
    pub rating_count: u32,
}

pub async fn get_pdp_by_slug(
    pool: &Pool<MySql>,
    slug: &str,
) -> Result<Option<ProductDetailPage>, sqlx::Error> {
    let product = match get_product_by_slug(pool, slug).await? {
        Some(p) => p,
        None => return Ok(None),
    };

    let pid = product.id;
    let images = get_product_gallery(pool, pid).await.unwrap_or_default();
    let variants = get_product_variants(pool, pid).await.unwrap_or_default();
    let options = get_product_options_and_values(pool, pid).await.unwrap_or_default();
    let category = match product.category_id {
        Some(cid) => sqlx::query_as("SELECT * FROM categories WHERE id = ? LIMIT 1")
            .bind(cid)
            .fetch_optional(pool)
            .await
            .unwrap_or(None),
        None => None,
    };

    let is_variable = product.product_type == "variable";
    let rating_avg = product.rating_avg.unwrap_or(Decimal::ZERO);
    let rating_count = product.rating_count;

    Ok(Some(ProductDetailPage {
        product,
        images,
        variants,
        options,
        category,
        is_variable,
        rating_avg,
        rating_count,
    }))
}

pub async fn get_related_products(
    pool: &Pool<MySql>,
    category_id: Option<u64>,
    product_id: u64,
    limit: i64,
    currency_symbol: &str,
) -> Result<Vec<ProductCardItem>, sqlx::Error> {
    let rows: Vec<Product> = if let Some(cid) = category_id {
        sqlx::query_as(
            "SELECT * FROM products WHERE category_id = ? AND id != ? AND status = 'active' ORDER BY sold_count DESC, id DESC LIMIT ?",
        )
        .bind(cid)
        .bind(product_id)
        .bind(limit)
        .fetch_all(pool)
        .await?
    } else {
        sqlx::query_as(
            "SELECT * FROM products WHERE id != ? AND status = 'active' ORDER BY sold_count DESC, id DESC LIMIT ?",
        )
        .bind(product_id)
        .bind(limit)
        .fetch_all(pool)
        .await?
    };

    attach_card_data(pool, rows, currency_symbol).await
}

#[derive(Clone, Debug, serde::Serialize, serde::Deserialize, sqlx::FromRow)]
pub struct ProductReviewDisplay {
    pub id: u64,
    pub product_id: u64,
    pub author_name: String,
    pub rating: i32,
    pub title: Option<String>,
    pub body: String,
    pub created_at: Option<chrono::NaiveDateTime>,
}

pub async fn get_product_reviews(
    pool: &Pool<MySql>,
    product_id: u64,
) -> Result<Vec<ProductReviewDisplay>, sqlx::Error> {
    sqlx::query_as(
        "SELECT id, product_id, author_name, rating, title, body, created_at FROM product_reviews WHERE product_id = ? AND status = 'approved' ORDER BY id DESC",
    )
    .bind(product_id)
    .fetch_all(pool)
    .await
}

