use axum::{
    extract::{Path, Query, State},
    response::{Html, IntoResponse, Redirect, Response},
    Form,
};
use chrono::Utc;
use rust_decimal::Decimal;
use serde::Deserialize;
use tower_sessions::Session;

use crate::state::{build_base_context, AppState, SessionHelper};
use common::crypto::generate_csrf_token;
use common::money::format_money;
use db::queries::cart::{
    calculate_totals, cart_add, cart_clear, cart_load, cart_remove_key, cart_set_key,
    validate_coupon, HydratedCart,
};
use db::queries::catalog::{
    get_active_banners, get_best_sellers, get_featured_products, get_new_arrivals,
    get_pdp_by_slug, get_product_reviews, get_related_products, get_shop_products,
    resolve_image_url, ShopFilter,
};
use db::queries::checkout::{get_shipping_fee, place_order, CheckoutFormData};
use domain::models::content::Page;
use domain::models::customer::CustomerAddress;
use domain::models::order::{Order, OrderItem, OrderStatusHistory, ShippingMethod};
use domain::models::product::Category;

#[derive(Debug, Deserialize)]
pub struct ShopQuery {
    pub category: Option<String>,
    pub category_id: Option<u64>,
    pub q: Option<String>,
    pub search: Option<String>,
    pub sort: Option<String>,
    pub min_price: Option<Decimal>,
    pub max_price: Option<Decimal>,
    pub page: Option<i64>,
    pub slug: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct SlugQuery {
    pub slug: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct CartForm {
    pub _csrf: Option<String>,
    pub action: Option<String>,
    pub product_id: Option<u64>,
    pub variant_id: Option<u64>,
    pub quantity: Option<i32>,
    pub qty: Option<i32>,
    pub cart_key: Option<String>,
    pub coupon_code: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct CheckoutForm {
    pub _csrf: Option<String>,
    pub checkout_token: Option<String>,
    pub form_time: Option<i64>,
    pub honeypot: Option<String>,

    pub full_name: Option<String>,
    pub email: Option<String>,
    pub phone: Option<String>,
    pub address: Option<String>,
    pub city: Option<String>,
    pub area: Option<String>,
    pub zip: Option<String>,
    pub notes: Option<String>,

    pub shipping_method_id: Option<u64>,
    pub payment_method: Option<String>,
    pub create_account: Option<String>,
    pub account_password: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct TrackForm {
    pub _csrf: Option<String>,
    pub order_number: Option<String>,
    pub email_or_phone: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct ContactForm {
    pub _csrf: Option<String>,
    pub name: Option<String>,
    pub email: Option<String>,
    pub phone: Option<String>,
    pub subject: Option<String>,
    pub message: Option<String>,
    pub website_hp: Option<String>,
    pub form_time: Option<i64>,
}

// -------------------------------------------------------------
// GET /
// -------------------------------------------------------------
pub async fn home_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    let mut ctx = build_base_context(&state, &session, "home").await;
    ctx.insert("header_class", "header-v1");

    let currency_sym = state.settings.get_or("currency_symbol", "৳");

    let show_hero = state.settings.get_or("home_show_hero", "1") == "1";
    ctx.insert("show_hero", &show_hero);

    let show_categories = state.settings.get_or("home_show_categories", "1") == "1";
    ctx.insert("show_categories", &show_categories);

    let show_new_arrivals = state.settings.get_or("home_show_new_arrivals", "1") == "1";
    ctx.insert("show_new_arrivals", &show_new_arrivals);

    let show_best_sellers = state.settings.get_or("home_show_best_sellers", "1") == "1";
    ctx.insert("show_best_sellers", &show_best_sellers);

    let show_featured = state.settings.get_or("home_show_featured", "1") == "1";
    ctx.insert("show_featured", &show_featured);

    let show_reviews = state.settings.get_or("home_show_reviews", "1") == "1";
    ctx.insert("show_reviews", &show_reviews);

    let hero_banners = if show_hero {
        get_active_banners(&state.db, "hero").await.unwrap_or_default()
    } else {
        Vec::new()
    };
    ctx.insert("hero_banners", &hero_banners);

    let category_banners = if show_categories {
        get_active_banners(&state.db, "category_row").await.unwrap_or_default()
    } else {
        Vec::new()
    };
    ctx.insert("category_banners", &category_banners);

    let new_arrivals = if show_new_arrivals {
        get_new_arrivals(&state.db, 8, &currency_sym).await.unwrap_or_default()
    } else {
        Vec::new()
    };
    ctx.insert("new_arrivals", &new_arrivals);

    let best_sellers = if show_best_sellers {
        get_best_sellers(&state.db, 8, &currency_sym).await.unwrap_or_default()
    } else {
        Vec::new()
    };
    ctx.insert("best_sellers", &best_sellers);

    let featured = if show_featured {
        get_featured_products(&state.db, 8, &currency_sym).await.unwrap_or_default()
    } else {
        Vec::new()
    };
    ctx.insert("featured_products", &featured);

    let reviews = if show_reviews {
        get_product_reviews(&state.db, 0).await.unwrap_or_default()
    } else {
        Vec::new()
    };
    ctx.insert("reviews", &reviews);

    state
        .tera
        .render("home.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error in home: {:#?}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// GET /shop, GET /shop.php
// -------------------------------------------------------------
pub async fn shop_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<ShopQuery>,
) -> Result<Html<String>, Response> {
    let mut ctx = build_base_context(&state, &session, "shop").await;

    let search = query.search.clone().or(query.q.clone());
    let mut cat_id = query.category_id;
    let mut selected_category_slug = query.category.clone().or(query.slug.clone());
    let currency_sym = state.settings.get_or("currency_symbol", "৳");

    if cat_id.is_none() {
        if let Some(ref slug) = selected_category_slug {
            let found_id: Option<u64> = sqlx::query_scalar(
                "SELECT id FROM categories WHERE slug = ? AND status = 'active' LIMIT 1",
            )
            .bind(slug)
            .fetch_optional(&state.db)
            .await
            .ok()
            .flatten();
            cat_id = found_id;
        }
    } else if selected_category_slug.is_none() {
        if let Some(cid) = cat_id {
            let found_slug: Option<String> = sqlx::query_scalar(
                "SELECT slug FROM categories WHERE id = ? AND status = 'active' LIMIT 1",
            )
            .bind(cid)
            .fetch_optional(&state.db)
            .await
            .ok()
            .flatten();
            selected_category_slug = found_slug;
        }
    }

    let page = query.page.unwrap_or(1).max(1);
    let per_page = 12;

    let filter = ShopFilter {
        category_id: cat_id,
        category_slug: None,
        search: search.clone(),
        sort: query.sort.clone(),
        min_price: query.min_price,
        max_price: query.max_price,
        tag: None,
        page,
        per_page,
    };

    let (products, total) = get_shop_products(&state.db, filter, &currency_sym)
        .await
        .unwrap_or((Vec::new(), 0));

    let total_pages = if total == 0 {
        1
    } else {
        ((total as f64) / (per_page as f64)).ceil() as i64
    };

    let page_numbers: Vec<i64> = (1..=total_pages).collect();

    ctx.insert("products", &products);
    ctx.insert("total_products", &total);
    ctx.insert("current_page", &page);
    ctx.insert("total_pages", &total_pages);
    ctx.insert("page_numbers", &page_numbers);
    ctx.insert("sort", query.sort.as_deref().unwrap_or("newest"));
    ctx.insert("current_sort", query.sort.as_deref().unwrap_or("newest"));
    ctx.insert("search_query", &search.unwrap_or_default());
    ctx.insert("selected_category_id", &cat_id);
    ctx.insert("selected_category_slug", &selected_category_slug);
    ctx.insert("min_price", &query.min_price.map(|p| p.to_string()).unwrap_or_default());
    ctx.insert("max_price", &query.max_price.map(|p| p.to_string()).unwrap_or_default());
    ctx.insert("filter_query_no_sort", &"");
    ctx.insert("query_string", &"");

    state
        .tera
        .render("shop/index.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error in shop: {:#?}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// GET /category/:slug, GET /category.php
// -------------------------------------------------------------
pub async fn category_slug_handler(
    State(state): State<AppState>,
    session: Session,
    Path(slug): Path<String>,
    Query(query): Query<ShopQuery>,
) -> Result<Html<String>, Response> {
    render_category(&state, &session, &slug, query).await
}

pub async fn category_php_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<ShopQuery>,
) -> Result<Html<String>, Response> {
    if state.config.pretty_urls {
        if let Some(ref slug) = query.slug {
            return Err(axum::response::Redirect::permanent(&format!("/category/{}", slug)).into_response());
        }
    }
    let slug = query.slug.clone().unwrap_or_default();
    render_category(&state, &session, &slug, query).await
}

async fn render_category(
    state: &AppState,
    session: &Session,
    slug: &str,
    query: ShopQuery,
) -> Result<Html<String>, Response> {
    let mut ctx = build_base_context(state, session, "shop").await;
    let currency_sym = state.settings.get_or("currency_symbol", "৳");

    let category: Option<Category> = sqlx::query_as(
        "SELECT * FROM categories WHERE slug = ? AND status = 'active' LIMIT 1",
    )
    .bind(slug)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let cat = match category {
        Some(c) => c,
        None => return Err(Redirect::to("/404").into_response()),
    };

    ctx.insert("active_category", &cat);
    ctx.insert("selected_category_id", &cat.id);

    let page = query.page.unwrap_or(1).max(1);
    let per_page = 12;

    let search = query.search.or(query.q);
    let filter = ShopFilter {
        category_id: Some(cat.id),
        category_slug: None,
        search: search.clone(),
        sort: query.sort.clone(),
        min_price: query.min_price,
        max_price: query.max_price,
        tag: None,
        page,
        per_page,
    };

    let (products, total) = get_shop_products(&state.db, filter, &currency_sym)
        .await
        .unwrap_or((Vec::new(), 0));

    let total_pages = if total == 0 {
        1
    } else {
        ((total as f64) / (per_page as f64)).ceil() as i64
    };

    let page_numbers: Vec<i64> = (1..=total_pages).collect();

    ctx.insert("products", &products);
    ctx.insert("total_products", &total);
    ctx.insert("current_page", &page);
    ctx.insert("total_pages", &total_pages);
    ctx.insert("page_numbers", &page_numbers);
    ctx.insert("sort", query.sort.as_deref().unwrap_or("newest"));
    ctx.insert("current_sort", query.sort.as_deref().unwrap_or("newest"));
    ctx.insert("search_query", &search.unwrap_or_default());
    ctx.insert("selected_category_id", &cat.id);
    ctx.insert("selected_category_slug", &cat.slug);
    ctx.insert("min_price", &query.min_price.map(|p| p.to_string()).unwrap_or_default());
    ctx.insert("max_price", &query.max_price.map(|p| p.to_string()).unwrap_or_default());
    ctx.insert("filter_query_no_sort", &"");
    ctx.insert("query_string", &"");

    state
        .tera
        .render("shop/index.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// GET /product/:slug, GET /product.php
// -------------------------------------------------------------
pub async fn product_slug_handler(
    State(state): State<AppState>,
    session: Session,
    Path(slug): Path<String>,
) -> Result<Html<String>, Response> {
    render_product(&state, &session, &slug).await
}

pub async fn product_php_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<SlugQuery>,
) -> Result<Html<String>, Response> {
    if state.config.pretty_urls {
        if let Some(ref slug) = query.slug {
            return Err(axum::response::Redirect::permanent(&format!("/product/{}", slug)).into_response());
        }
    }
    let slug = query.slug.unwrap_or_default();
    render_product(&state, &session, &slug).await
}

async fn render_product(
    state: &AppState,
    session: &Session,
    slug: &str,
) -> Result<Html<String>, Response> {
    let mut ctx = build_base_context(state, session, "product").await;
    let currency_sym = state.settings.get_or("currency_symbol", "৳");

    let pdp = match get_pdp_by_slug(&state.db, slug).await {
        Ok(Some(pd)) => pd,
        Ok(None) => return Err(Redirect::to("/404").into_response()),
        Err(e) => {
            tracing::error!("get_pdp_by_slug error: {:?}", e);
            return Err(Redirect::to("/404").into_response());
        }
    };

    let category_id = pdp.product.category_id;
    let product_id = pdp.product.id;

    let main_img_url = resolve_image_url(pdp.product.image.as_deref());
    let mut gallery: Vec<serde_json::Value> = Vec::new();
    gallery.push(serde_json::json!({
        "url": main_img_url,
        "thumb_url": main_img_url,
    }));
    for img in &pdp.images {
        let u = resolve_image_url(Some(&img.filename));
        gallery.push(serde_json::json!({
            "url": u,
            "thumb_url": u,
        }));
    }

    let is_on_sale = match pdp.product.sale_price {
        Some(sp) => sp > Decimal::ZERO && sp < pdp.product.price,
        None => false,
    };
    let discount_percent = if is_on_sale {
        let sp = pdp.product.sale_price.unwrap_or(pdp.product.price);
        let diff = pdp.product.price - sp;
        ((diff / pdp.product.price) * Decimal::from(100))
            .to_string()
            .parse::<f64>()
            .ok()
            .map(|f| f.round() as u32)
            .unwrap_or(0)
    } else {
        0
    };

    let in_stock = pdp.product.stock > 0 || pdp.product.allow_backorder == 1 || pdp.product.track_inventory == 0;
    let current_stock = pdp.product.stock;

    let display_price = if is_on_sale {
        pdp.product.sale_price.unwrap_or(pdp.product.price)
    } else {
        pdp.product.price
    };

    let page_description = pdp
        .product
        .short_description
        .clone()
        .or(pdp.product.description.clone())
        .unwrap_or_else(|| pdp.product.name.clone());

    let avg_rating: f64 = pdp
        .rating_avg
        .to_string()
        .parse()
        .unwrap_or(5.0);

    let (cat_name, cat_slug) = match pdp.category.as_ref() {
        Some(c) => (Some(c.name.clone()), Some(c.slug.clone())),
        None => (None, None),
    };

    let mut prod_val = serde_json::to_value(&pdp.product).unwrap_or_default();
    if let Some(obj) = prod_val.as_object_mut() {
        obj.insert("category_name".to_string(), serde_json::to_value(&cat_name).unwrap());
        obj.insert("category_slug".to_string(), serde_json::to_value(&cat_slug).unwrap());
    }

    ctx.insert("product", &prod_val);
    ctx.insert("images", &pdp.images);
    ctx.insert("gallery", &gallery);
    ctx.insert("main_img_url", &main_img_url);
    ctx.insert("variants", &pdp.variants);
    ctx.insert("options", &pdp.options);
    ctx.insert("category", &pdp.category);
    ctx.insert("is_variable", &pdp.is_variable);
    ctx.insert("rating_avg", &avg_rating);
    ctx.insert("avg_rating", &avg_rating);
    ctx.insert("rating_count", &pdp.rating_count);
    ctx.insert("total_reviews", &pdp.rating_count);
    ctx.insert("on_sale", &is_on_sale);
    ctx.insert("discount_percent", &discount_percent);
    ctx.insert("in_stock", &in_stock);
    ctx.insert("current_stock", &current_stock);
    ctx.insert("display_price", &display_price);
    ctx.insert("page_description", &page_description);

    let related = get_related_products(&state.db, category_id, product_id, 4, &currency_sym)
        .await
        .unwrap_or_default();
    ctx.insert("related", &related);
    ctx.insert("related_products", &related);

    let reviews = get_product_reviews(&state.db, product_id)
        .await
        .unwrap_or_default();
    ctx.insert("reviews", &reviews);

    state
        .tera
        .render("shop/product.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error in product: {:#?}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// GET /cart, GET /cart.php
// -------------------------------------------------------------
pub async fn cart_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    let mut ctx = build_base_context(&state, &session, "cart").await;

    let raw_cart = SessionHelper::get_cart(&session).await;
    let currency_sym = state.settings.get_or("currency_symbol", "৳");
    let loaded_cart = cart_load(&state.db, &raw_cart, &currency_sym)
        .await
        .unwrap_or(HydratedCart {
            items: Vec::new(),
            subtotal: Decimal::ZERO,
            formatted_subtotal: format_money(Some(Decimal::ZERO), &currency_sym),
            total_count: 0,
            alerts: Vec::new(),
        });

    let applied_coupon: Option<String> = session
        .get(SessionHelper::KEY_APPLIED_COUPON)
        .await
        .ok()
        .flatten();

    let coupon_discount = if let Some(ref code) = applied_coupon {
        match validate_coupon(&state.db, code, loaded_cart.subtotal, &currency_sym).await {
            Ok(c) => {
                ctx.insert("coupon", &c.coupon);
                c.discount
            }
            Err(_) => {
                let _ = session.remove::<String>(SessionHelper::KEY_APPLIED_COUPON).await;
                Decimal::ZERO
            }
        }
    } else {
        Decimal::ZERO
    };

    let shipping_fee = get_shipping_fee(&state.settings, loaded_cart.subtotal);
    let grand_total = (loaded_cart.subtotal - coupon_discount + shipping_fee).max(Decimal::ZERO);

    let free_shipping_threshold: Decimal = state
        .settings
        .get_or("free_shipping_threshold", "0")
        .parse()
        .unwrap_or(Decimal::ZERO);

    let free_shipping_qualifies = free_shipping_threshold > Decimal::ZERO && loaded_cart.subtotal >= free_shipping_threshold;
    let free_shipping_diff = if free_shipping_threshold > loaded_cart.subtotal {
        free_shipping_threshold - loaded_cart.subtotal
    } else {
        Decimal::ZERO
    };
    let free_shipping_pct = if free_shipping_threshold > Decimal::ZERO {
        let pct = (loaded_cart.subtotal / free_shipping_threshold * Decimal::from(100)).min(Decimal::from(100));
        pct.to_string().parse::<i32>().unwrap_or(100)
    } else {
        0
    };

    let threshold_f64: f64 = free_shipping_threshold.to_string().parse().unwrap_or(0.0);
    ctx.insert("free_shipping_threshold", &threshold_f64);
    ctx.insert("free_shipping_qualifies", &free_shipping_qualifies);
    ctx.insert("free_shipping_diff", &free_shipping_diff);
    ctx.insert("free_shipping_pct", &free_shipping_pct);

    ctx.insert("cart", &loaded_cart);
    ctx.insert("shipping_fee", &format_money(Some(shipping_fee), &currency_sym));
    ctx.insert("discount_amount", &format_money(Some(coupon_discount), &currency_sym));
    ctx.insert("grand_total", &format_money(Some(grand_total), &currency_sym));

    state
        .tera
        .render("cart/index.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error in cart: {:#?}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// POST /cart
// -------------------------------------------------------------
pub async fn cart_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<CartForm>,
) -> Response {
    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/cart").into_response();
    }

    let action = form.action.as_deref().unwrap_or("add");
    let mut raw_cart = SessionHelper::get_cart(&session).await;

    match action {
        "add" => {
            if let Some(pid) = form.product_id {
                let qty = form.qty.or(form.quantity).unwrap_or(1).max(1);
                cart_add(&mut raw_cart, pid, form.variant_id, qty);
                SessionHelper::set_cart(&session, &raw_cart).await;
                SessionHelper::add_flash(&session, "success", "Item added to cart.").await;
            }
        }
        "update" => {
            if let Some(ref key) = form.cart_key {
                let qty = form.qty.or(form.quantity).unwrap_or(1);
                cart_set_key(&mut raw_cart, key, qty);
                SessionHelper::set_cart(&session, &raw_cart).await;
                SessionHelper::add_flash(&session, "success", "Cart updated.").await;
            }
        }
        "remove" => {
            if let Some(ref key) = form.cart_key {
                cart_remove_key(&mut raw_cart, key);
                SessionHelper::set_cart(&session, &raw_cart).await;
                SessionHelper::add_flash(&session, "info", "Item removed from cart.").await;
            }
        }
        "clear" => {
            cart_clear(&mut raw_cart);
            SessionHelper::set_cart(&session, &raw_cart).await;
            SessionHelper::add_flash(&session, "info", "Cart cleared.").await;
        }
        "apply_coupon" => {
            if let Some(ref code) = form.coupon_code {
                let currency_sym = state.settings.get_or("currency_symbol", "৳");
                let loaded = cart_load(&state.db, &raw_cart, &currency_sym)
                    .await
                    .unwrap_or(HydratedCart {
                        items: Vec::new(),
                        subtotal: Decimal::ZERO,
                        formatted_subtotal: format_money(Some(Decimal::ZERO), &currency_sym),
                        total_count: 0,
                        alerts: Vec::new(),
                    });
                match validate_coupon(&state.db, code, loaded.subtotal, &currency_sym).await {
                    Ok(cres) => {
                        if cres.valid {
                            if let Some(ref cp) = cres.coupon {
                                let _ = session
                                    .insert(SessionHelper::KEY_APPLIED_COUPON, &cp.code)
                                    .await;
                                SessionHelper::add_flash(
                                    &session,
                                    "success",
                                    &format!("Coupon \"{}\" applied successfully!", cp.code),
                                )
                                .await;
                            }
                        } else {
                            let err_msg = cres.error.unwrap_or_else(|| "Invalid coupon code.".to_string());
                            SessionHelper::add_flash(&session, "danger", &err_msg).await;
                        }
                    }
                    Err(e) => {
                        SessionHelper::add_flash(&session, "danger", &e.to_string()).await;
                    }
                }
            }
        }
        "remove_coupon" => {
            let _ = session.remove::<String>(SessionHelper::KEY_APPLIED_COUPON).await;
            SessionHelper::add_flash(&session, "info", "Coupon removed.").await;
        }
        _ => {}
    }

    Redirect::to("/cart").into_response()
}

// -------------------------------------------------------------
// GET /checkout, GET /checkout.php
// -------------------------------------------------------------
pub async fn checkout_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    let raw_cart = SessionHelper::get_cart(&session).await;
    if raw_cart.is_empty() {
        SessionHelper::add_flash(&session, "warning", "Your cart is empty. Please add items before checking out.").await;
        return Err(Redirect::to("/cart").into_response());
    }

    let customer = SessionHelper::get_customer(&session).await;
    let allow_guest = state.settings.get_or("allow_guest_checkout", "1") == "1";
    if !allow_guest && customer.is_none() {
        SessionHelper::add_flash(&session, "info", "Please sign in or create an account to proceed to checkout.").await;
        return Err(Redirect::to("/login?next=/checkout").into_response());
    }

    let mut ctx = build_base_context(&state, &session, "checkout").await;

    let checkout_token = generate_csrf_token();
    let now_ts = Utc::now().timestamp();
    let _ = session.insert(SessionHelper::KEY_CHECKOUT_TOKEN, &checkout_token).await;
    let _ = session.insert(SessionHelper::KEY_CHECKOUT_TIME, now_ts).await;

    ctx.insert("checkout_token", &checkout_token);
    ctx.insert("form_time", &now_ts);

    let currency_sym = state.settings.get_or("currency_symbol", "৳");
    let loaded_cart = cart_load(&state.db, &raw_cart, &currency_sym)
        .await
        .unwrap_or(HydratedCart {
            items: Vec::new(),
            subtotal: Decimal::ZERO,
            formatted_subtotal: format_money(Some(Decimal::ZERO), &currency_sym),
            total_count: 0,
            alerts: Vec::new(),
        });

    let applied_coupon: Option<String> = session
        .get(SessionHelper::KEY_APPLIED_COUPON)
        .await
        .ok()
        .flatten();

    let coupon_discount = if let Some(ref code) = applied_coupon {
        match validate_coupon(&state.db, code, loaded_cart.subtotal, &currency_sym).await {
            Ok(c) => {
                ctx.insert("coupon", &c.coupon);
                c.discount
            }
            Err(_) => Decimal::ZERO,
        }
    } else {
        Decimal::ZERO
    };

    let shipping_fee = get_shipping_fee(&state.settings, loaded_cart.subtotal);
    let cod_fee = Decimal::ZERO;
    let grand_total = (loaded_cart.subtotal - coupon_discount + shipping_fee + cod_fee).max(Decimal::ZERO);

    let shipping_methods_raw: Vec<ShippingMethod> = sqlx::query_as(
        "SELECT * FROM shipping_methods WHERE status = 'active' ORDER BY position ASC, id ASC",
    )
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let shipping_methods: Vec<serde_json::Value> = shipping_methods_raw
        .into_iter()
        .map(|sm| {
            let is_free = sm.free_over.map(|fo| loaded_cart.subtotal >= fo).unwrap_or(false);
            serde_json::json!({
                "id": sm.id,
                "name": sm.name,
                "zone": sm.zone,
                "cost": sm.cost,
                "min_days": sm.min_days,
                "max_days": sm.max_days,
                "cod_allowed": sm.cod_allowed,
                "is_free": is_free,
            })
        })
        .collect();

    let selected_shipping_id = shipping_methods
        .first()
        .and_then(|s| s.get("id").and_then(|v| v.as_u64()))
        .unwrap_or(1);

    let require_phone = state.settings.get_or("require_phone", "1") == "1";
    let require_email = state.settings.get_or("require_email", "0") == "1";
    let cod_enabled = state.settings.get_or("cod_enabled", "1") == "1";
    let bank_enabled = state.settings.get_or("bank_transfer_enabled", "0") == "1";
    let bank_instructions = state.settings.get_or("bank_transfer_instructions", "");

    ctx.insert("shipping_methods", &shipping_methods);
    ctx.insert("selected_shipping_id", &selected_shipping_id);
    ctx.insert("require_phone", &require_phone);
    ctx.insert("require_email", &require_email);
    ctx.insert("cod_enabled", &cod_enabled);
    ctx.insert("bank_enabled", &bank_enabled);
    ctx.insert("bank_instructions", &bank_instructions);
    ctx.insert("errors", &Vec::<String>::new());
    ctx.insert("old", &serde_json::json!({}));
    ctx.insert("now_ts", &now_ts);

    ctx.insert("cart", &loaded_cart);
    ctx.insert("subtotal", &format_money(Some(loaded_cart.subtotal), &currency_sym));
    ctx.insert("shipping_fee", &format_money(Some(shipping_fee), &currency_sym));
    ctx.insert("discount_amount", &format_money(Some(coupon_discount), &currency_sym));
    ctx.insert("grand_total", &format_money(Some(grand_total), &currency_sym));

    if let Some(ref c) = customer {
        let addresses: Vec<CustomerAddress> = sqlx::query_as(
            "SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id DESC",
        )
        .bind(c.id)
        .fetch_all(&state.db)
        .await
        .unwrap_or_default();
        ctx.insert("saved_addresses", &addresses);
    }

    state
        .tera
        .render("cart/checkout.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// POST /checkout
// -------------------------------------------------------------
pub async fn checkout_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<CheckoutForm>,
) -> Response {
    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/checkout").into_response();
    }

    let rendered_at: i64 = session
        .get(SessionHelper::KEY_CHECKOUT_TIME)
        .await
        .ok()
        .flatten()
        .unwrap_or(0);
    let now_ts = Utc::now().timestamp();
    if rendered_at > 0 && (now_ts - rendered_at) < 3 {
        SessionHelper::add_flash(&session, "danger", "Form submitted too quickly. Please try again.").await;
        return Redirect::to("/checkout").into_response();
    }

    if let Some(ref hp) = form.honeypot {
        if !hp.trim().is_empty() {
            SessionHelper::add_flash(&session, "danger", "Spam check failed.").await;
            return Redirect::to("/checkout").into_response();
        }
    }

    let expected_token: Option<String> = session
        .get(SessionHelper::KEY_CHECKOUT_TOKEN)
        .await
        .ok()
        .flatten();
    if form.checkout_token.is_none() || form.checkout_token != expected_token {
        SessionHelper::add_flash(&session, "danger", "Order was already submitted or token expired.").await;
        return Redirect::to("/checkout").into_response();
    }

    let customer = SessionHelper::get_customer(&session).await;
    let customer_id = customer.as_ref().map(|c| c.id);

    let full_name = form.full_name.as_deref().unwrap_or("").trim();
    let email = form.email.as_deref().unwrap_or("").trim();
    let phone = form.phone.as_deref().unwrap_or("").trim();
    let address = form.address.as_deref().unwrap_or("").trim();
    let city = form.city.as_deref().unwrap_or("").trim();

    if full_name.is_empty() || email.is_empty() || phone.is_empty() || address.is_empty() || city.is_empty() {
        SessionHelper::add_flash(&session, "danger", "Please fill in all required delivery fields.").await;
        return Redirect::to("/checkout").into_response();
    }

    let raw_cart = SessionHelper::get_cart(&session).await;
    let currency_sym = state.settings.get_or("currency_symbol", "৳");
    let loaded_cart = match cart_load(&state.db, &raw_cart, &currency_sym).await {
        Ok(c) => c,
        Err(e) => {
            SessionHelper::add_flash(&session, "danger", &format!("Cart error: {}", e)).await;
            return Redirect::to("/checkout").into_response();
        }
    };

    if loaded_cart.items.is_empty() {
        SessionHelper::add_flash(&session, "warning", "Your cart is empty.").await;
        return Redirect::to("/cart").into_response();
    }

    let applied_coupon: Option<String> = session
        .get(SessionHelper::KEY_APPLIED_COUPON)
        .await
        .ok()
        .flatten();
    let coupon_code = applied_coupon.as_deref();
    let payment_method = form.payment_method.as_deref().unwrap_or("cod");

    let totals = match calculate_totals(
        &state.db,
        &loaded_cart,
        form.shipping_method_id,
        coupon_code,
        payment_method,
        &state.settings,
    )
    .await
    {
        Ok(t) => t,
        Err(e) => {
            SessionHelper::add_flash(&session, "danger", &format!("Totals error: {}", e)).await;
            return Redirect::to("/checkout").into_response();
        }
    };

    let checkout_form_data = CheckoutFormData {
        customer_name: full_name.to_string(),
        customer_email: email.to_string(),
        customer_phone: Some(phone.to_string()),
        shipping_address: address.to_string(),
        shipping_city: Some(city.to_string()),
        shipping_zip: form.zip.clone(),
        shipping_country: Some("Bangladesh".to_string()),
        shipping_method_id: form.shipping_method_id,
        payment_method: payment_method.to_string(),
        coupon_code: coupon_code.map(|s| s.to_string()),
        notes: form.notes.clone(),
    };

    let prefix = state.settings.get_or("order_prefix", "ORD");

    match place_order(
        &state.db,
        checkout_form_data,
        &loaded_cart,
        &totals,
        customer_id,
        &prefix,
        Some("127.0.0.1"),
    )
    .await
    {
        Ok(order) => {
            let _ = session.remove::<String>(SessionHelper::KEY_CHECKOUT_TOKEN).await;
            SessionHelper::clear_cart(&session).await;
            let _ = session.insert("last_placed_order", &order.order_number).await;

            let site_name = state.settings.get_or("site_name", "Jinish Potro");
            let _ = state.mailer.queue_email(
                &order.customer_email,
                Some(&order.customer_name),
                &format!("Order Confirmation #{} - {}", order.order_number, site_name),
                &format!(
                    "<p>Thank you for your order, {}! Your order number is <strong>#{}</strong>.</p>",
                    order.customer_name, order.order_number
                ),
                false,
            ).await;

            let token = common::crypto::generate_hmac_sha256(&order.order_number, &state.config.app_key);
            Redirect::to(&format!("/order/confirmation?o={}&t={}", order.order_number, token)).into_response()
        }
        Err(e) => {
            SessionHelper::add_flash(&session, "danger", &format!("Order could not be placed: {}", e)).await;
            Redirect::to("/checkout").into_response()
        }
    }
}

// -------------------------------------------------------------
// GET /order/confirmation, GET /order_confirmation.php
// -------------------------------------------------------------
#[derive(Debug, Deserialize)]
pub struct ConfirmationQuery {
    pub o: Option<String>,
    pub t: Option<String>,
    pub number: Option<String>,
    pub order: Option<String>,
}

pub async fn order_confirmation_handler(
    State(state): State<AppState>,
    session: Session,
    Query(query): Query<ConfirmationQuery>,
) -> Result<Html<String>, Response> {
    let order_num = query.o.or(query.number).or(query.order).unwrap_or_default();
    let token = query.t.unwrap_or_default();

    let mut is_authorized = false;
    if !order_num.is_empty() {
        let expected_token = common::crypto::generate_hmac_sha256(&order_num, &state.config.app_key);
        if !token.is_empty() && common::crypto::verify_csrf_token(&token, &expected_token) {
            is_authorized = true;
        } else if let Ok(Some(last_ord)) = session.get::<String>("last_placed_order").await {
            if last_ord == order_num {
                is_authorized = true;
            }
        }
    }

    if !is_authorized && !order_num.is_empty() {
        if let Some(cust) = SessionHelper::get_customer(&session).await {
            let owner_match: Option<u64> = sqlx::query_scalar("SELECT id FROM orders WHERE order_number = ? AND customer_id = ? LIMIT 1")
                .bind(&order_num)
                .bind(cust.id)
                .fetch_optional(&state.db)
                .await
                .unwrap_or(None);
            if owner_match.is_some() {
                is_authorized = true;
            }
        }
    }

    if !is_authorized {
        return Err(Redirect::to("/").into_response());
    }

    let mut ctx = build_base_context(&state, &session, "order_confirmation").await;

    let order: Option<Order> = sqlx::query_as(
        "SELECT * FROM orders WHERE order_number = ? LIMIT 1",
    )
    .bind(&order_num)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let ord = match order {
        Some(o) => o,
        None => return Err(Redirect::to("/").into_response()),
    };

    let items: Vec<OrderItem> = sqlx::query_as(
        "SELECT * FROM order_items WHERE order_id = ?",
    )
    .bind(ord.id)
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    ctx.insert("order", &ord);
    ctx.insert("items", &items);

    state
        .tera
        .render("cart/confirmation.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// GET /track, POST /track, GET /track.php
// -------------------------------------------------------------
pub async fn track_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    let ctx = build_base_context(&state, &session, "track").await;
    state
        .tera
        .render("track/index.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

pub async fn track_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<TrackForm>,
) -> Result<Html<String>, Response> {
    let mut ctx = build_base_context(&state, &session, "track").await;

    let num = form.order_number.as_deref().unwrap_or("").trim();
    let iden = form.email_or_phone.as_deref().unwrap_or("").trim();

    if !num.is_empty() && !iden.is_empty() {
        let order: Option<Order> = sqlx::query_as(
            "SELECT * FROM orders WHERE order_number = ? AND (customer_email = ? OR customer_phone = ?) LIMIT 1",
        )
        .bind(num)
        .bind(iden)
        .bind(iden)
        .fetch_optional(&state.db)
        .await
        .unwrap_or(None);

        if let Some(ord) = order {
            let history: Vec<OrderStatusHistory> = sqlx::query_as(
                "SELECT * FROM order_status_history WHERE order_id = ? ORDER BY id ASC",
            )
            .bind(ord.id)
            .fetch_all(&state.db)
            .await
            .unwrap_or_default();

            let items: Vec<OrderItem> = sqlx::query_as(
                "SELECT * FROM order_items WHERE order_id = ?",
            )
            .bind(ord.id)
            .fetch_all(&state.db)
            .await
            .unwrap_or_default();

            ctx.insert("order", &ord);
            ctx.insert("history", &history);
            ctx.insert("items", &items);
        } else {
            ctx.insert("error", "No matching order found for this order number and email/phone.");
        }
    }

    state
        .tera
        .render("track/index.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/500").into_response()
        })
}

// -------------------------------------------------------------
// GET /page/:slug
// -------------------------------------------------------------
pub async fn page_handler(
    State(state): State<AppState>,
    session: Session,
    Path(slug): Path<String>,
) -> Result<Html<String>, Response> {
    let mut ctx = build_base_context(&state, &session, "page").await;

    let page: Option<Page> = sqlx::query_as(
        "SELECT * FROM pages WHERE slug = ? AND status = 'published' LIMIT 1",
    )
    .bind(&slug)
    .fetch_optional(&state.db)
    .await
    .unwrap_or(None);

    let pg = match page {
        Some(p) => p,
        None => return Err(Redirect::to("/404").into_response()),
    };

    ctx.insert("page", &pg);

    state
        .tera
        .render("pages/view.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error: {}", e);
            Redirect::to("/404").into_response()
        })
}

// -------------------------------------------------------------
// GET /contact, POST /contact
// -------------------------------------------------------------
pub async fn contact_get_handler(
    State(state): State<AppState>,
    session: Session,
) -> Result<Html<String>, Response> {
    let mut ctx = build_base_context(&state, &session, "contact").await;
    ctx.insert("errors", &Vec::<String>::new());
    ctx.insert("old", &serde_json::json!({}));
    state
        .tera
        .render("pages/contact.html", &ctx)
        .map(Html)
        .map_err(|e| {
            tracing::error!("Template render error in contact: {:#?}", e);
            Redirect::to("/500").into_response()
        })
}

pub async fn contact_post_handler(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<ContactForm>,
) -> Response {
    if !SessionHelper::verify_csrf(&session, form._csrf.as_deref()).await {
        SessionHelper::add_flash(&session, "danger", "Invalid session token. Please try again.").await;
        return Redirect::to("/contact").into_response();
    }

    if let Some(ref hp) = form.website_hp {
        if !hp.trim().is_empty() {
            SessionHelper::add_flash(&session, "danger", "Spam detection triggered.").await;
            return Redirect::to("/contact").into_response();
        }
    }

    let name = form.name.as_deref().unwrap_or("").trim();
    let email = form.email.as_deref().unwrap_or("").trim();
    let message = form.message.as_deref().unwrap_or("").trim();

    if name.is_empty() || email.is_empty() || message.is_empty() {
        SessionHelper::add_flash(&session, "danger", "Please fill in all required fields.").await;
        return Redirect::to("/contact").into_response();
    }

    let admin_email = state.settings.get_or("store_email", "admin@jinishpotro.com");
    let _ = state.mailer.queue_email(
        &admin_email,
        Some("Store Admin"),
        &format!("Contact Form Message from {}", name),
        &format!("<p>From: {} ({})</p><p>{}</p>", name, email, message),
        false,
    ).await;

    SessionHelper::add_flash(&session, "success", "Thank you! Your message has been sent successfully.").await;
    Redirect::to("/contact").into_response()
}

// -------------------------------------------------------------
// GET /sitemap.xml, GET /sitemap.php
// -------------------------------------------------------------
pub async fn sitemap_handler(
    State(state): State<AppState>,
) -> Response {
    let base_url = state.config.app_url.trim_end_matches('/');

    let products: Vec<(String, Option<chrono::NaiveDateTime>)> = sqlx::query_as(
        "SELECT slug, updated_at FROM products WHERE status = 'active' ORDER BY id DESC",
    )
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let categories: Vec<(String, Option<chrono::NaiveDateTime>)> = sqlx::query_as(
        "SELECT slug, updated_at FROM categories WHERE status = 'active' ORDER BY id DESC",
    )
    .fetch_all(&state.db)
    .await
    .unwrap_or_default();

    let mut xml = String::from("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n");

    xml.push_str(&format!(
        "  <url><loc>{}/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>\n",
        base_url
    ));
    xml.push_str(&format!(
        "  <url><loc>{}/shop</loc><changefreq>daily</changefreq><priority>0.9</priority></url>\n",
        base_url
    ));

    for (slug, _) in categories {
        xml.push_str(&format!(
            "  <url><loc>{}/category/{}</loc><changefreq>weekly</changefreq><priority>0.8</priority></url>\n",
            base_url, slug
        ));
    }

    for (slug, _) in products {
        xml.push_str(&format!(
            "  <url><loc>{}/product/{}</loc><changefreq>weekly</changefreq><priority>0.7</priority></url>\n",
            base_url, slug
        ));
    }

    xml.push_str("</urlset>");

    ([(axum::http::header::CONTENT_TYPE, "application/xml; charset=utf-8")], xml).into_response()
}

pub async fn robots_txt_handler(State(state): State<AppState>) -> Response {
    let base_url = state.config.app_url.trim_end_matches('/');
    let robots = format!(
        "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/\nDisallow: /scratch/\nSitemap: {}/sitemap.xml\n",
        base_url
    );
    ([(axum::http::header::CONTENT_TYPE, "text/plain; charset=utf-8")], robots).into_response()
}
