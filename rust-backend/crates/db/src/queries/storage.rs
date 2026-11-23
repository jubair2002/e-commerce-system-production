use std::path::{Path, PathBuf};
use std::time::SystemTime;

/// Candidate path resolution matching PHP uploads.php
pub fn resolve_media_file(requested: &str, uploads_dir: &Path) -> Option<PathBuf> {
    let clean = requested.trim_start_matches('/').replace(['\0', '\\'], "");
    if clean.contains("..") {
        return None;
    }

    let candidate_paths = vec![
        uploads_dir.join(&clean),
        PathBuf::from("assets/uploads").join(&clean),
        uploads_dir.join("products").join(&clean),
        PathBuf::from("assets/uploads/products").join(&clean),
        uploads_dir.join("avatars").join(&clean),
        PathBuf::from("assets/uploads/avatars").join(&clean),
        uploads_dir.join("banners").join(&clean),
        PathBuf::from("assets/uploads/banners").join(&clean),
    ];

    for path in candidate_paths {
        if path.is_file() {
            return Some(path);
        }
    }

    // Try fallback for responsive variants e.g. _thumb.webp, _card.webp
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

    None
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
