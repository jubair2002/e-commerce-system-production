use axum::{
    extract::{Request, State},
    response::{IntoResponse, Response},
};
use serde::Deserialize;
use std::fs;
use std::path::PathBuf;

use crate::state::AppState;
use db::queries::storage::{detect_mime_type, placeholder_svg, resolve_media_file};

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

    let uploads_dir = PathBuf::from(&state.config.uploads_dir);
    let resolved = resolve_media_file(&requested_path, &uploads_dir);

    if let Some(path) = resolved {
        if let Ok(bytes) = fs::read(&path) {
            let mime = detect_mime_type(&path, &bytes);
            let etag = format!("\"{:x}-{:x}\"", bytes.len(), bytes.iter().fold(0u32, |acc, &b| acc.wrapping_add(b as u32)));

            // Check If-None-Match
            if let Some(if_none_match) = req.headers().get(axum::http::header::IF_NONE_MATCH) {
                if let Ok(val) = if_none_match.to_str() {
                    if val == etag {
                        return (axum::http::StatusCode::NOT_MODIFIED, "").into_response();
                    }
                }
            }

            return (
                [
                    (axum::http::header::CONTENT_TYPE, mime),
                    (axum::http::header::ETAG, etag),
                    (axum::http::header::CACHE_CONTROL, "public, max-age=86400".to_string()),
                ],
                bytes,
            )
                .into_response();
        }
    }

    // Fallback: placeholder SVG
    (
        [
            (axum::http::header::CONTENT_TYPE, "image/svg+xml".to_string()),
            (axum::http::header::CACHE_CONTROL, "public, max-age=3600".to_string()),
        ],
        placeholder_svg(),
    )
        .into_response()
}
