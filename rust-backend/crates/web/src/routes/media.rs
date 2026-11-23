use axum::{
    extract::{Request, State},
    http::StatusCode,
    response::{IntoResponse, Response},
};
use chrono::{DateTime, Utc};
use serde::Deserialize;
use std::fs;
use std::path::PathBuf;
use std::time::SystemTime;

use crate::state::AppState;
use db::queries::storage::{
    compute_etag, detect_mime_type, placeholder_svg, resolve_media_file, resolve_media_from_db,
};

#[derive(Debug, Deserialize)]
pub struct UploadsQuery {
    pub file: Option<String>,
}

pub async fn media_handler(
    State(state): State<AppState>,
    req: Request,
) -> Response {
    let uri_path = req.uri().path().to_string();
    let query_file = req.uri().query().and_then(|q| {
        serde_urlencoded::from_str::<UploadsQuery>(q).ok()?.file
    });

    let requested_path = if let Some(f) = query_file {
        f
    } else if let Some(stripped) = uri_path.strip_prefix("/uploads/") {
        stripped.to_string()
    } else if let Some(stripped) = uri_path.strip_prefix("/assets/uploads/") {
        stripped.to_string()
    } else {
        uri_path.trim_start_matches('/').to_string()
    };

    let clean = requested_path.trim_start_matches('/').replace(['\0', '\\'], "");

    // Path traversal check
    if clean.contains("..") || clean.is_empty() {
        return (StatusCode::BAD_REQUEST, "Invalid file request.").into_response();
    }

    // Disallow server-side code execution
    let lower_clean = clean.to_lowercase();
    if lower_clean.ends_with(".php")
        || lower_clean.ends_with(".phtml")
        || lower_clean.ends_with(".sh")
        || lower_clean.ends_with(".env")
        || lower_clean.ends_with(".htaccess")
    {
        return (StatusCode::FORBIDDEN, "Access denied.").into_response();
    }

    let uploads_dir = PathBuf::from(&state.config.uploads_dir);
    let mut resolved = resolve_media_file(&clean, &uploads_dir);

    // Fallback: check database `files` table
    if resolved.is_none() {
        if let Some(record) = resolve_media_from_db(&state.db, &clean).await {
            let candidate = uploads_dir.join(&record.storage_key);
            if candidate.is_file() {
                resolved = Some(candidate);
            }
        }
    }

    if let Some(path) = resolved {
        if let Ok(metadata) = fs::metadata(&path) {
            let mtime = metadata.modified().unwrap_or(SystemTime::UNIX_EPOCH);
            let size = metadata.len();
            let etag = compute_etag(&path, mtime, size);

            // 1. Check If-None-Match
            if let Some(if_none_match) = req.headers().get(axum::http::header::IF_NONE_MATCH) {
                if let Ok(val) = if_none_match.to_str() {
                    if val.trim() == etag.trim() {
                        return (StatusCode::NOT_MODIFIED, "").into_response();
                    }
                }
            }

            // 2. Check If-Modified-Since
            if let Some(if_modified_since) = req.headers().get(axum::http::header::IF_MODIFIED_SINCE) {
                if let Ok(ims_str) = if_modified_since.to_str() {
                    if let Ok(ims_dt) = DateTime::parse_from_rfc2822(ims_str) {
                        let mtime_dt: DateTime<Utc> = mtime.into();
                        if mtime_dt.timestamp() <= ims_dt.timestamp() {
                            return (StatusCode::NOT_MODIFIED, "").into_response();
                        }
                    }
                }
            }

            if let Ok(bytes) = fs::read(&path) {
                let mime = detect_mime_type(&path, &bytes);
                let last_modified = DateTime::<Utc>::from(mtime).to_rfc2822();

                return (
                    [
                        (axum::http::header::CONTENT_TYPE, mime),
                        (axum::http::header::ETAG, etag),
                        (axum::http::header::LAST_MODIFIED, last_modified),
                        (axum::http::header::CACHE_CONTROL, "public, max-age=86400".to_string()),
                    ],
                    bytes,
                )
                    .into_response();
            }
        }
    }

    // Ultimate fallback: placeholder SVG
    (
        [
            (axum::http::header::CONTENT_TYPE, "image/svg+xml".to_string()),
            (axum::http::header::CACHE_CONTROL, "public, max-age=3600".to_string()),
        ],
        placeholder_svg(),
    )
        .into_response()
}
