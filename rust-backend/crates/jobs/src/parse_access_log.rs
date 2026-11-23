use chrono::DateTime;
use sqlx::{MySql, Pool};
use std::collections::HashMap;
use std::fs::File;
use std::io::{Read, Seek, SeekFrom};
use std::path::{Path, PathBuf};
use tracing::info;

use db::SettingsRepository;

#[derive(Debug, Default, Clone)]
pub struct ParseAccessLogResult {
    pub log_file: Option<String>,
    pub buckets_updated: usize,
    pub elapsed_secs: f64,
}

#[derive(Default)]
struct MinuteAggregate {
    requests: i64,
    bytes_out: i64,
    s2xx: i64,
    s3xx: i64,
    s4xx: i64,
    s5xx: i64,
}

pub async fn run_parse_access_log(
    pool: &Pool<MySql>,
    custom_path: Option<&str>,
    dry_run: bool,
) -> Result<ParseAccessLogResult, String> {
    let start = std::time::Instant::now();
    let settings = SettingsRepository::load(pool)
        .await
        .map_err(|e| format!("Settings load error: {}", e))?;

    let apache_setting = settings.get_or("apache_access_log_path", "");
    let nginx_setting = settings.get_or("nginx_access_log_path", "");

    let candidate_paths = [
        custom_path.unwrap_or(""),
        &apache_setting,
        &nginx_setting,
        "/var/log/apache2/access.log",
        "/var/log/httpd/access_log",
        "/var/log/nginx/access.log",
        "/var/log/apache2/other_vhosts_access.log",
        "/var/log/apache2/ecommerce-access.log",
    ];

    let mut log_path: Option<PathBuf> = None;
    for p in candidate_paths {
        if !p.is_empty() && Path::new(p).exists() {
            log_path = Some(PathBuf::from(p));
            break;
        }
    }

    let path = match log_path {
        Some(p) => p,
        None => {
            info!("Notice: No readable access log found among standard paths. Skipping external access log parsing.");
            return Ok(ParseAccessLogResult {
                log_file: None,
                buckets_updated: 0,
                elapsed_secs: start.elapsed().as_secs_f64(),
            });
        }
    };

    let path_str = path.to_string_lossy().to_string();
    info!("Processing access log '{}' (dry_run: {})...", path_str, dry_run);

    let mut file = File::open(&path).map_err(|e| format!("Error opening access log '{}': {}", path_str, e))?;

    // Read last ~5000 lines efficiently from end of file
    let file_len = file.metadata().map(|m| m.len()).unwrap_or(0);
    let read_len = file_len.min(500 * 1024); // max 500 KB tail
    file.seek(SeekFrom::End(-(read_len as i64))).map_err(|e| e.to_string())?;

    let mut buf = Vec::with_capacity(read_len as usize);
    file.read_to_end(&mut buf).map_err(|e| e.to_string())?;
    let content = String::from_utf8_lossy(&buf);

    let mut aggregates: HashMap<String, MinuteAggregate> = HashMap::new();

    for line in content.lines().rev().take(5000) {
        let line = line.trim();
        if line.is_empty() {
            continue;
        }

        // Example line:
        // 127.0.0.1 - - [16/Oct/2026:22:15:30 +0600] "GET /path HTTP/1.1" 200 4520 "referer" "agent"
        if let Some(open_bracket) = line.find('[') {
            if let Some(close_bracket) = line[open_bracket..].find(']') {
                let time_str = &line[open_bracket + 1..open_bracket + close_bracket];
                if let Ok(dt) = DateTime::parse_from_str(time_str, "%d/%b/%Y:%H:%M:%S %z") {
                    let bucket = dt.format("%Y-%m-%d %H:%M:00").to_string();

                    // Parse status and bytes after the closing quote
                    let after_bracket = &line[open_bracket + close_bracket + 1..];
                    if let Some(last_quote) = after_bracket.rfind('"') {
                        let remainder = after_bracket[last_quote + 1..].trim();
                        let mut tokens = remainder.split_whitespace();
                        let status: i64 = tokens.next().and_then(|s| s.parse().ok()).unwrap_or(200);
                        let bytes: i64 = tokens.next().and_then(|b| b.parse().ok()).unwrap_or(0);

                        let entry = aggregates.entry(bucket).or_default();
                        entry.requests += 1;
                        entry.bytes_out += bytes;
                        if (200..300).contains(&status) {
                            entry.s2xx += 1;
                        } else if (300..400).contains(&status) {
                            entry.s3xx += 1;
                        } else if (400..500).contains(&status) {
                            entry.s4xx += 1;
                        } else if (500..600).contains(&status) {
                            entry.s5xx += 1;
                        }
                    }
                }
            }
        }
    }

    let buckets_count = aggregates.len();

    if !dry_run {
        for (bucket, stat) in &aggregates {
            let _ = sqlx::query(
                "INSERT INTO traffic_minute
                   (bucket, area, requests, bytes_out, bytes_in, total_ms, max_ms, s2xx, s3xx, s4xx, s5xx)
                 VALUES
                   (?, 'storefront', ?, ?, 0, 0, 0, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   requests  = GREATEST(requests, VALUES(requests)),
                   bytes_out = GREATEST(bytes_out, VALUES(bytes_out)),
                   s2xx      = GREATEST(s2xx, VALUES(s2xx)),
                   s3xx      = GREATEST(s3xx, VALUES(s3xx)),
                   s4xx      = GREATEST(s4xx, VALUES(s4xx)),
                   s5xx      = GREATEST(s5xx, VALUES(s5xx))",
            )
            .bind(bucket)
            .bind(stat.requests)
            .bind(stat.bytes_out)
            .bind(stat.s2xx)
            .bind(stat.s3xx)
            .bind(stat.s4xx)
            .bind(stat.s5xx)
            .execute(pool)
            .await;
        }
    }

    info!(
        "Processed access log '{}'. Updated {} minute buckets.",
        path_str, buckets_count
    );

    Ok(ParseAccessLogResult {
        log_file: Some(path_str),
        buckets_updated: buckets_count,
        elapsed_secs: start.elapsed().as_secs_f64(),
    })
}
