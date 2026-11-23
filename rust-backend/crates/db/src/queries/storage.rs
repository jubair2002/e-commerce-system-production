use chrono::Utc;
use rand::RngCore;
use sqlx::{MySql, Pool};
use std::fs;
use std::path::{Path, PathBuf};
use std::time::SystemTime;

#[derive(Debug, Clone)]
pub struct StoredFileInfo {
    pub id: u64,
    pub storage_key: String,
    pub provider: String,
    pub url: String,
    pub original_name: String,
    pub mime_type: String,
    pub size: u64,
    pub width: Option<u32>,
    pub height: Option<u32>,
    pub checksum: String,
    pub sync_status: String,
}

#[derive(Debug, Clone, sqlx::FromRow)]
pub struct DbFileRecord {
    pub id: u64,
    pub storage_key: String,
    pub provider: String,
    pub bucket: Option<String>,
    pub original_name: String,
    pub mime_type: String,
    pub size: i64,
    pub checksum_sha256: Option<String>,
    pub width: Option<i32>,
    pub height: Option<i32>,
    pub visibility: String,
    pub purpose: String,
    pub status: String,
    pub sync_status: String,
}

// Purpose limits in bytes
pub const MAX_SIZE_AVATAR: u64 = 2 * 1024 * 1024; // 2 MB
pub const MAX_SIZE_BRANDING: u64 = 2 * 1024 * 1024; // 2 MB
pub const MAX_SIZE_BANNER: u64 = 5 * 1024 * 1024; // 5 MB
pub const MAX_SIZE_PRODUCT: u64 = 10 * 1024 * 1024; // 10 MB
pub const MAX_SIZE_DOCUMENT: u64 = 10 * 1024 * 1024; // 10 MB
pub const MAX_SIZE_DEFAULT: u64 = 10 * 1024 * 1024; // 10 MB

pub fn get_max_size_for_purpose(purpose: &str) -> u64 {
    match purpose {
        "avatar" => MAX_SIZE_AVATAR,
        "branding" => MAX_SIZE_BRANDING,
        "banner" => MAX_SIZE_BANNER,
        "product" => MAX_SIZE_PRODUCT,
        "document" => MAX_SIZE_DOCUMENT,
        _ => MAX_SIZE_DEFAULT,
    }
}

pub fn get_allowed_mimes_for_purpose(purpose: &str) -> &'static [&'static str] {
    match purpose {
        "avatar" => &["image/jpeg", "image/png", "image/webp"],
        "branding" => &[
            "image/png",
            "image/jpeg",
            "image/webp",
            "image/x-icon",
            "image/vnd.microsoft.icon",
            "image/svg+xml",
        ],
        "banner" => &["image/jpeg", "image/png", "image/webp"],
        "product" => &["image/jpeg", "image/png", "image/webp", "image/gif"],
        "document" => &[
            "application/pdf",
            "application/json",
            "text/csv",
            "text/plain",
        ],
        _ => &["image/jpeg", "image/png", "image/webp"],
    }
}

pub fn mime_to_extension(mime: &str) -> &'static str {
    match mime {
        "image/jpeg" | "image/pjpeg" => "jpg",
        "image/png" | "image/x-png" => "png",
        "image/webp" => "webp",
        "image/gif" => "gif",
        "image/svg+xml" => "svg",
        "image/x-icon" | "image/vnd.microsoft.icon" => "ico",
        "application/pdf" => "pdf",
        "application/json" => "json",
        "text/csv" => "csv",
        "text/plain" => "txt",
        _ => "bin",
    }
}

/// Generate secure storage key: {purpose}/{YYYY}/{MM}/{random-32-hex}.{ext}
pub fn generate_storage_key(purpose: &str, ext: &str) -> String {
    let clean_purpose: String = purpose
        .to_lowercase()
        .chars()
        .filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-')
        .collect();
    let clean_purpose = if clean_purpose.is_empty() {
        "misc"
    } else {
        &clean_purpose
    };

    let now = Utc::now();
    let year = now.format("%Y").to_string();
    let month = now.format("%m").to_string();

    let mut rand_bytes = [0u8; 16];
    rand::thread_rng().fill_bytes(&mut rand_bytes);
    let random_hex = hex::encode(rand_bytes);

    let clean_ext = ext.trim_start_matches('.').to_lowercase();
    let clean_ext = if clean_ext.is_empty() { "bin" } else { &clean_ext };

    format!("{}/{}/{}/{}.{}", clean_purpose, year, month, random_hex, clean_ext)
}

/// Candidate path resolution matching PHP uploads.php
pub fn resolve_media_file(requested: &str, uploads_dir: &Path) -> Option<PathBuf> {
    let clean = requested.trim_start_matches('/').replace(['\0', '\\'], "");
    if clean.contains("..") {
        return None;
    }

    let mut candidate_paths = vec![
        uploads_dir.join(&clean),
        PathBuf::from("assets/uploads").join(&clean),
    ];

    // Avatar folder flexibility
    if let Some(bare) = clean.strip_prefix("avatars/") {
        candidate_paths.push(uploads_dir.join(bare));
        candidate_paths.push(PathBuf::from("assets/uploads").join(bare));
    } else {
        candidate_paths.push(uploads_dir.join("avatars").join(&clean));
        candidate_paths.push(PathBuf::from("assets/uploads/avatars").join(&clean));
    }

    // Product image flexibility
    if let Some(bare) = clean.strip_prefix("products/") {
        candidate_paths.push(uploads_dir.join(bare));
        candidate_paths.push(PathBuf::from("assets/uploads").join(bare));
    } else {
        candidate_paths.push(uploads_dir.join("products").join(&clean));
        candidate_paths.push(PathBuf::from("assets/uploads/products").join(&clean));
    }

    // Branding / banner / category / store flexibility
    for folder in &["banners", "branding", "categories", "store"] {
        let prefix = format!("{}/", folder);
        if let Some(bare) = clean.strip_prefix(&prefix) {
            candidate_paths.push(uploads_dir.join(bare));
            candidate_paths.push(PathBuf::from("assets/uploads").join(bare));
        } else {
            candidate_paths.push(uploads_dir.join(folder).join(&clean));
            candidate_paths.push(PathBuf::from("assets/uploads").join(folder).join(&clean));
        }
    }

    for path in &candidate_paths {
        if path.is_file() {
            return Some(path.clone());
        }
    }

    // Fallback for responsive variants e.g. _thumb.webp, _card.webp, _detail.webp
    let variant_suffixes = ["_thumb.webp", "_thumbnail.webp", "_card.webp", "_detail.webp"];
    for suffix in &variant_suffixes {
        if clean.ends_with(suffix) {
            let base_name = &clean[..clean.len() - suffix.len()];
            for ext in &[".webp", ".jpg", ".jpeg", ".png"] {
                let candidate = format!("{}{}", base_name, ext);
                let test_paths = vec![
                    uploads_dir.join(&candidate),
                    PathBuf::from("assets/uploads").join(&candidate),
                    uploads_dir.join("products").join(&candidate),
                    PathBuf::from("assets/uploads/products").join(&candidate),
                ];
                for tp in test_paths {
                    if tp.is_file() {
                        return Some(tp);
                    }
                }
            }
        }
    }

    // Prefix variant fallback e.g. thumb_..., card_...
    for prefix in &["thumb_", "thumbnail_", "card_", "detail_"] {
        if let Some(base_name) = clean.strip_prefix(prefix) {
            for ext in &[".webp", ".jpg", ".jpeg", ".png"] {
                let base_without_ext = if let Some(dot_idx) = base_name.rfind('.') {
                    &base_name[..dot_idx]
                } else {
                    base_name
                };
                let candidate = format!("{}{}", base_without_ext, ext);
                let test_paths = vec![
                    uploads_dir.join(&candidate),
                    PathBuf::from("assets/uploads").join(&candidate),
                    uploads_dir.join("products").join(&candidate),
                    PathBuf::from("assets/uploads/products").join(&candidate),
                ];
                for tp in test_paths {
                    if tp.is_file() {
                        return Some(tp);
                    }
                }
            }
        }
    }

    None
}

/// Fallback DB lookup in `files` table matching requested key or original name
pub async fn resolve_media_from_db(
    pool: &Pool<MySql>,
    requested: &str,
) -> Option<DbFileRecord> {
    let clean = requested.trim_start_matches('/').replace(['\0', '\\'], "");
    sqlx::query_as::<_, DbFileRecord>(
        "SELECT * FROM files WHERE (storage_key = ? OR original_name = ?) AND deleted_at IS NULL LIMIT 1"
    )
    .bind(&clean)
    .bind(&clean)
    .fetch_optional(pool)
    .await
    .ok()
    .flatten()
}

/// MIME type resolution
pub fn detect_mime_type(file_path: &Path, content: &[u8]) -> String {
    if let Some(kind) = infer::get(content) {
        return kind.mime_type().to_string();
    }

    let ext = file_path
        .extension()
        .and_then(|e| e.to_str())
        .unwrap_or("")
        .to_lowercase();

    match ext.as_str() {
        "jpg" | "jpeg" => "image/jpeg".to_string(),
        "png" => "image/png".to_string(),
        "gif" => "image/gif".to_string(),
        "webp" => "image/webp".to_string(),
        "svg" => "image/svg+xml".to_string(),
        "ico" => "image/x-icon".to_string(),
        "pdf" => "application/pdf".to_string(),
        "mp4" => "video/mp4".to_string(),
        "webm" => "video/webm".to_string(),
        _ => "application/octet-stream".to_string(),
    }
}

/// Compute ETag from file metadata
pub fn compute_etag(file_path: &Path, mtime: SystemTime, size: u64) -> String {
    let mtime_secs = mtime
        .duration_since(SystemTime::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0);
    let raw = format!("{}:{}:{}", file_path.to_string_lossy(), mtime_secs, size);
    let digest = md5::compute(raw.as_bytes());
    format!("\"{:x}\"", digest)
}

/// Fallback SVG placeholder matching uploads.php
pub fn placeholder_svg() -> &'static str {
    r##"<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200"><rect width="200" height="200" fill="#f1f5f9"/><path d="M70 85 L130 85 L125 140 L75 140 Z" fill="none" stroke="#cbd5e1" stroke-width="2.5" stroke-linejoin="round"/><path d="M85 85 C85 70 115 70 115 85" fill="none" stroke="#cbd5e1" stroke-width="2.5"/></svg>"##
}

/// Check SVG content for potential XSS payloads
pub fn validate_svg_content(content: &[u8]) -> Result<(), String> {
    let s = String::from_utf8_lossy(content).to_lowercase();
    if s.contains("<script")
        || s.contains("javascript:")
        || s.contains("onload=")
        || s.contains("onerror=")
        || s.contains("onclick=")
    {
        return Err("Security violation: SVG file contains potentially malicious active script content.".to_string());
    }
    Ok(())
}

/// Process image for avatar (400x400 center-crop WebP quality 85)
pub fn process_avatar_image(bytes: &[u8]) -> Result<(Vec<u8>, u32, u32), String> {
    let dyn_img = image::load_from_memory(bytes).map_err(|e| format!("Invalid image file: {}", e))?;
    let (w, h) = (dyn_img.width(), dyn_img.height());
    let min_dim = w.min(h);
    let x = (w - min_dim) / 2;
    let y = (h - min_dim) / 2;
    let cropped = dyn_img.crop_imm(x, y, min_dim, min_dim);
    let resized = cropped.resize_exact(400, 400, image::imageops::FilterType::Lanczos3);

    let encoder = webp::Encoder::from_image(&resized).map_err(|e| format!("WebP encode error: {}", e))?;
    let webp_memory = encoder.encode(85.0);
    Ok((webp_memory.to_vec(), 400, 400))
}

/// Process image for product (max width 1800 WebP quality 85, EXIF stripped)
pub fn process_product_image(bytes: &[u8]) -> Result<(Vec<u8>, u32, u32), String> {
    let dyn_img = image::load_from_memory(bytes).map_err(|e| format!("Invalid image file: {}", e))?;
    let (w, _h) = (dyn_img.width(), dyn_img.height());
    let resized = if w > 1800 {
        dyn_img.resize(1800, u32::MAX, image::imageops::FilterType::Lanczos3)
    } else {
        dyn_img
    };

    let (final_w, final_h) = (resized.width(), resized.height());
    let encoder = webp::Encoder::from_image(&resized).map_err(|e| format!("WebP encode error: {}", e))?;
    let webp_memory = encoder.encode(85.0);
    Ok((webp_memory.to_vec(), final_w, final_h))
}

/// Generate responsive variant (e.g. 300px thumb, 600px card, 1200px detail)
pub fn generate_image_variant(bytes: &[u8], target_width: u32, quality: f32) -> Result<(Vec<u8>, u32, u32), String> {
    let dyn_img = image::load_from_memory(bytes).map_err(|e| format!("Invalid image file: {}", e))?;
    let resized = if dyn_img.width() > target_width {
        dyn_img.resize(target_width, u32::MAX, image::imageops::FilterType::Lanczos3)
    } else {
        dyn_img
    };

    let (final_w, final_h) = (resized.width(), resized.height());
    let encoder = webp::Encoder::from_image(&resized).map_err(|e| format!("WebP encode error: {}", e))?;
    let webp_memory = encoder.encode(quality);
    Ok((webp_memory.to_vec(), final_w, final_h))
}

/// Storage options
#[derive(Debug, Clone, Default)]
pub struct StoreOptions {
    pub owner_type: Option<String>,
    pub owner_id: Option<u64>,
    pub visibility: Option<String>,
    pub is_test: bool,
}

/// Complete StorageManager storing files with validation, transformation, and DB persistence
#[allow(dead_code)]
pub struct StorageManager {
    uploads_dir: PathBuf,
    primary_driver: String,
    fallback_driver: String,
}

impl StorageManager {
    pub fn new(uploads_dir: PathBuf) -> Self {
        let primary = std::env::var("STORAGE_PRIMARY").unwrap_or_else(|_| "local".to_string());
        let fallback = std::env::var("STORAGE_FALLBACK").unwrap_or_else(|_| "none".to_string());
        Self {
            uploads_dir,
            primary_driver: primary,
            fallback_driver: fallback,
        }
    }

    pub fn generate_key(&self, purpose: &str, ext: &str) -> String {
        generate_storage_key(purpose, ext)
    }

    /// Store raw file bytes with validation and image transformations
    pub async fn store_file(
        &self,
        pool: &Pool<MySql>,
        purpose: &str,
        original_name: &str,
        content: &[u8],
        options: StoreOptions,
    ) -> Result<StoredFileInfo, String> {
        let file_size = content.len() as u64;

        // 1. Size check
        let max_size = get_max_size_for_purpose(purpose);
        if file_size > max_size {
            let mb = (max_size as f64) / (1024.0 * 1024.0);
            return Err(format!("File exceeds maximum allowed size of {:.1} MB.", mb));
        }

        // 2. MIME type detection
        let detected_mime = if let Some(kind) = infer::get(content) {
            kind.mime_type().to_string()
        } else {
            let ext = Path::new(original_name)
                .extension()
                .and_then(|e| e.to_str())
                .unwrap_or("")
                .to_lowercase();
            match ext.as_str() {
                "svg" => "image/svg+xml".to_string(),
                "ico" => "image/x-icon".to_string(),
                "json" => "application/json".to_string(),
                "txt" => "text/plain".to_string(),
                "csv" => "text/csv".to_string(),
                _ => "application/octet-stream".to_string(),
            }
        };

        let allowed_mimes = get_allowed_mimes_for_purpose(purpose);
        if !allowed_mimes.contains(&detected_mime.as_str()) {
            return Err(format!(
                "Invalid file format detected: {}. Format not allowed for {}.",
                detected_mime, purpose
            ));
        }

        // 3. SVG validation
        if detected_mime == "image/svg+xml" {
            validate_svg_content(content)?;
        }

        // 4. Image transformation
        let mut final_content = content.to_vec();
        let mut final_mime = detected_mime.clone();
        let mut img_width = None;
        let mut img_height = None;

        let is_image = detected_mime.starts_with("image/") && detected_mime != "image/svg+xml";
        if is_image {
            if purpose == "avatar" {
                let (proc_bytes, w, h) = process_avatar_image(content)?;
                final_content = proc_bytes;
                final_mime = "image/webp".to_string();
                img_width = Some(w);
                img_height = Some(h);
            } else if purpose == "product" {
                let (proc_bytes, w, h) = process_product_image(content)?;
                final_content = proc_bytes;
                final_mime = "image/webp".to_string();
                img_width = Some(w);
                img_height = Some(h);
            } else if let Ok(dyn_img) = image::load_from_memory(content) {
                img_width = Some(dyn_img.width());
                img_height = Some(dyn_img.height());
            }
        }

        // 5. Storage key & checksum
        let ext = mime_to_extension(&final_mime);
        let storage_key = generate_storage_key(purpose, ext);
        use sha2::Digest;
        let mut hasher = sha2::Sha256::new();
        hasher.update(&final_content);
        let checksum = hex::encode(hasher.finalize());

        let visibility = options.visibility.unwrap_or_else(|| "public".to_string());

        // 6. Write file to local disk (or fallback)
        let destination = self.uploads_dir.join(&storage_key);
        if let Some(parent) = destination.parent() {
            fs::create_dir_all(parent).map_err(|e| format!("Failed to create storage directory: {}", e))?;
        }
        fs::write(&destination, &final_content)
            .map_err(|e| format!("Failed to write file to storage: {}", e))?;

        let active_provider = "local".to_string();
        let sync_status = "synced".to_string();

        // 7. Insert into `files` table
        let query_res = sqlx::query(
            "INSERT INTO files (
                storage_key, provider, bucket, original_name, mime_type, size,
                checksum_sha256, width, height, visibility, purpose, owner_type, owner_id,
                status, sync_status, created_at, updated_at
             ) VALUES (
                ?, ?, NULL, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                'ready', ?, NOW(), NOW()
             )",
        )
        .bind(&storage_key)
        .bind(&active_provider)
        .bind(original_name)
        .bind(&final_mime)
        .bind(final_content.len() as i64)
        .bind(&checksum)
        .bind(img_width.map(|w| w as i32))
        .bind(img_height.map(|h| h as i32))
        .bind(&visibility)
        .bind(purpose)
        .bind(options.owner_type)
        .bind(options.owner_id)
        .bind(&sync_status)
        .execute(pool)
        .await
        .map_err(|e| format!("Database error inserting file record: {}", e))?;

        let file_id = query_res.last_insert_id();

        // 8. Generate responsive variants for products
        if purpose == "product" && is_image {
            let path_key = Path::new(&storage_key);
            let dir = path_key.parent().and_then(|p| p.to_str()).unwrap_or("");
            let stem = path_key.file_stem().and_then(|s| s.to_str()).unwrap_or("");

            for (variant_name, target_w) in [("thumb", 300), ("card", 600), ("detail", 1200)] {
                if let Ok((var_bytes, _, _)) = generate_image_variant(&final_content, target_w, 82.0) {
                    let var_key = if dir.is_empty() {
                        format!("{}_{}.webp", stem, variant_name)
                    } else {
                        format!("{}/{}_{}.webp", dir, stem, variant_name)
                    };
                    let var_dest = self.uploads_dir.join(&var_key);
                    if let Some(p) = var_dest.parent() {
                        let _ = fs::create_dir_all(p);
                    }
                    let _ = fs::write(&var_dest, &var_bytes);
                }
            }
        }

        let public_url = format!("/uploads/{}", storage_key);

        Ok(StoredFileInfo {
            id: file_id,
            storage_key,
            provider: active_provider,
            url: public_url,
            original_name: original_name.to_string(),
            mime_type: final_mime,
            size: final_content.len() as u64,
            width: img_width,
            height: img_height,
            checksum,
            sync_status,
        })
    }

    /// Delete file: soft delete in DB, remove from storage
    pub async fn delete_file(&self, pool: &Pool<MySql>, file_id: u64) -> Result<bool, String> {
        let record: Option<DbFileRecord> = sqlx::query_as(
            "SELECT * FROM files WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        )
        .bind(file_id)
        .fetch_optional(pool)
        .await
        .map_err(|e| e.to_string())?;

        let file = match record {
            Some(f) => f,
            None => return Ok(false),
        };

        sqlx::query("UPDATE files SET status = 'deleted', deleted_at = NOW() WHERE id = ?")
            .bind(file_id)
            .execute(pool)
            .await
            .map_err(|e| e.to_string())?;

        let disk_path = self.uploads_dir.join(&file.storage_key);
        if disk_path.is_file() {
            let _ = fs::remove_file(disk_path);
        }

        Ok(true)
    }
}
