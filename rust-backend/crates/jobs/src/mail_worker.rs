use chrono::Utc;
use lettre::{
    message::header::ContentType,
    transport::smtp::authentication::Credentials,
    AsyncSmtpTransport, AsyncTransport, Message, Tokio1Executor,
};
use sqlx::{MySql, Pool, Row};
use std::collections::HashMap;
use tracing::{info, warn};

use common::crypto::app_decrypt;
use db::SettingsRepository;

#[derive(Debug, Default, Clone)]
pub struct MailWorkerResult {
    pub claimed: usize,
    pub sent: usize,
    pub failed: usize,
    pub elapsed_secs: f64,
}

pub struct MailConfig {
    pub enabled: bool,
    pub host: String,
    pub port: u16,
    pub username: String,
    pub password: String,
    pub encryption: String,
    pub from_email: String,
    pub from_name: String,
    pub reply_to: String,
    pub max_attempts: i32,
    pub timeout_secs: u64,
}

pub async fn load_mail_config(settings: &SettingsRepository) -> MailConfig {
    let enc_pass = {
        let p = settings.get_or("smtp_pass", "");
        if !p.is_empty() { p } else { settings.get_or("mail_password_enc", &settings.get_or("mail_password", "")) }
    };
    let app_key = settings.get_or("app_key", "");
    let password = if !enc_pass.is_empty() {
        app_decrypt(&enc_pass, &app_key).unwrap_or(enc_pass)
    } else {
        String::new()
    };

    let host = {
        let h = settings.get_or("smtp_host", "");
        if !h.is_empty() { h } else { settings.get_or("mail_host", "localhost") }
    };
    let port = {
        let p = settings.get_or("smtp_port", "");
        if !p.is_empty() { p.parse::<u16>().unwrap_or(587) } else { settings.get_or("mail_port", "587").parse::<u16>().unwrap_or(587) }
    };
    let username = {
        let u = settings.get_or("smtp_user", "");
        if !u.is_empty() { u } else { settings.get_or("mail_username", "") }
    };
    let encryption = {
        let e = settings.get_or("smtp_encryption", "");
        if !e.is_empty() { e } else { settings.get_or("mail_encryption", "tls") }
    };
    let from_email = {
        let f = settings.get_or("mail_from_address", "");
        if !f.is_empty() { f } else { settings.get_or("mail_from_email", &settings.get_or("site_email", "noreply@example.com")) }
    };
    let from_name = {
        let n = settings.get_or("mail_from_name", "");
        if !n.is_empty() { n } else { settings.get_or("site_name", "Jinish Potro") }
    };

    MailConfig {
        enabled: settings.get_or("mail_enabled", "0") == "1",
        host,
        port,
        username,
        password,
        encryption,
        from_email,
        from_name,
        reply_to: settings.get_or("mail_reply_to", ""),
        max_attempts: settings.get_or("mail_max_attempts", "3").parse::<i32>().unwrap_or(3).max(1),
        timeout_secs: settings.get_or("mail_timeout", "8").parse::<u64>().unwrap_or(8).clamp(2, 30),
    }
}

pub fn render_email_template(
    subject_tmpl: &str,
    body_tmpl: &str,
    vars: &HashMap<String, String>,
    site_name: &str,
    store_logo: Option<&str>,
    footer_text: Option<&str>,
) -> (String, String) {
    let mut subject = subject_tmpl.to_string();
    let mut body = body_tmpl.to_string();

    for (k, v) in vars {
        let needle = format!("{{{{{}}}}}", k);
        // HTML escape all placeholders except pre-rendered HTML blocks like items_table
        let val_escaped = if k == "items_table" {
            v.clone()
        } else {
            html_escape(v)
        };
        subject = subject.replace(&needle, v);
        body = body.replace(&needle, &val_escaped);
    }

    let wrapped_html = wrap_email_layout(&body, &subject, site_name, store_logo, footer_text);
    (subject, wrapped_html)
}

fn html_escape(s: &str) -> String {
    s.replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&#039;")
}

fn wrap_email_layout(
    inner_html: &str,
    title: &str,
    site_name: &str,
    store_logo: Option<&str>,
    footer_text: Option<&str>,
) -> String {
    let logo_html = if let Some(logo) = store_logo {
        if !logo.is_empty() {
            format!(
                "<img src=\"{}\" alt=\"{}\" style=\"max-height: 48px; border:0;\">",
                logo, html_escape(site_name)
            )
        } else {
            format!("<h2>{}</h2>", html_escape(site_name))
        }
    } else {
        format!("<h2>{}</h2>", html_escape(site_name))
    };

    let footer = footer_text.unwrap_or("");

    format!(
        r#"<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#18181b;">
  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f4f5; padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px; background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.06); border:1px solid #e4e4e7;">
          <tr>
            <td style="padding:28px 32px; border-bottom:1px solid #f4f4f5; text-align:center;">
              {}
            </td>
          </tr>
          <tr>
            <td style="padding:32px; font-size:15px; line-height:1.6; color:#27272a;">
              {}
            </td>
          </tr>
          <tr>
            <td style="padding:24px 32px; background-color:#fafafa; border-top:1px solid #f4f4f5; font-size:12px; color:#71717a; text-align:center;">
              <div>&copy; {}. All rights reserved.</div>
              <div style="margin-top:6px;">{}</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>"#,
        html_escape(title),
        logo_html,
        inner_html,
        html_escape(site_name),
        html_escape(footer)
    )
}

/// Run mail queue processor
pub async fn run_mail_worker(
    pool: &Pool<MySql>,
    dry_run: bool,
    batch_limit: usize,
) -> Result<MailWorkerResult, String> {
    let start = std::time::Instant::now();
    let limit = batch_limit.clamp(1, 500);

    let settings = SettingsRepository::load(pool)
        .await
        .map_err(|e| format!("Settings load error: {}", e))?;

    let cfg = load_mail_config(&settings).await;

    if !cfg.enabled && (cfg.host.is_empty() || cfg.username.is_empty()) {
        info!("Mail sending is disabled (mail_enabled=0). Exiting.");
        return Ok(MailWorkerResult::default());
    }

    // Try claiming batch with FOR UPDATE SKIP LOCKED
    let mut claimed_ids: Vec<u64> = Vec::new();

    let tx_res = pool.begin().await;
    if let Ok(mut tx) = tx_res {
        let select_res = sqlx::query_scalar::<_, u64>(
            "SELECT id FROM email_queue
             WHERE status = 'pending' AND scheduled_at <= NOW()
             ORDER BY id ASC
             LIMIT ?
             FOR UPDATE SKIP LOCKED",
        )
        .bind(limit as i64)
        .fetch_all(&mut *tx)
        .await;

        match select_res {
            Ok(ids) if !ids.is_empty() => {
                if !dry_run {
                    for id in &ids {
                        let _ = sqlx::query("UPDATE email_queue SET status = 'sending' WHERE id = ?")
                            .bind(id)
                            .execute(&mut *tx)
                            .await;
                    }
                }
                let _ = tx.commit().await;
                claimed_ids = ids;
            }
            Ok(_) => {
                let _ = tx.rollback().await;
            }
            Err(_) => {
                // Fallback for older MySQL without SKIP LOCKED
                let _ = tx.rollback().await;
                let candidate_ids: Vec<u64> = sqlx::query_scalar(
                    "SELECT id FROM email_queue
                     WHERE status = 'pending' AND scheduled_at <= NOW()
                     ORDER BY id ASC
                     LIMIT ?",
                )
                .bind(limit as i64)
                .fetch_all(pool)
                .await
                .unwrap_or_default();

                for cid in candidate_ids {
                    if dry_run {
                        claimed_ids.push(cid);
                    } else {
                        let res = sqlx::query(
                            "UPDATE email_queue SET status = 'sending' WHERE id = ? AND status = 'pending'",
                        )
                        .bind(cid)
                        .execute(pool)
                        .await;

                        if let Ok(r) = res {
                            if r.rows_affected() > 0 {
                                claimed_ids.push(cid);
                            }
                        }
                    }
                }
            }
        }
    }

    if claimed_ids.is_empty() {
        return Ok(MailWorkerResult {
            claimed: 0,
            sent: 0,
            failed: 0,
            elapsed_secs: start.elapsed().as_secs_f64(),
        });
    }

    info!(
        "Claimed {} email(s) for delivery... (dry_run: {})",
        claimed_ids.len(),
        dry_run
    );

    let mut sent = 0;
    let mut failed = 0;

    for id in &claimed_ids {
        let row = sqlx::query(
            "SELECT id, to_email, to_name, subject, body_html, body_text, attempts, template_slug FROM email_queue WHERE id = ?",
        )
        .bind(id)
        .fetch_optional(pool)
        .await;

        let r = match row {
            Ok(Some(r)) => r,
            _ => continue,
        };

        let to_email: String = r.try_get("to_email").unwrap_or_default();
        let to_name: Option<String> = r.try_get("to_name").ok();
        let subject: String = r.try_get("subject").unwrap_or_default();
        let body_html: String = r.try_get("body_html").unwrap_or_default();
        let attempts: u8 = r.try_get("attempts").unwrap_or(0);
        let new_attempts = (attempts as i32) + 1;

        if dry_run {
            info!("  [DRY-RUN] Would dispatch email #{} to <{}>: {}", id, to_email, subject);
            sent += 1;
            continue;
        }

        // Send via SMTP using lettre
        let send_res = send_email_via_smtp(&cfg, &to_email, to_name.as_deref(), &subject, &body_html).await;

        match send_res {
            Ok(_) => {
                let _ = sqlx::query(
                    "UPDATE email_queue
                     SET status = 'sent', sent_at = NOW(), attempts = ?, last_error = NULL
                     WHERE id = ?",
                )
                .bind(new_attempts)
                .bind(id)
                .execute(pool)
                .await;
                sent += 1;
            }
            Err(err) => {
                failed += 1;
                // Mask password in sanitized error message
                let clean_err = if !cfg.password.is_empty() {
                    err.replace(&cfg.password, "••••••••")
                } else {
                    err
                };
                let clean_err_truncated = if clean_err.len() > 490 {
                    clean_err[..490].to_string()
                } else {
                    clean_err
                };

                warn!("Failed sending email #{}: {}", id, clean_err_truncated);

                let backoffs = [1, 5, 30];
                let idx = ((new_attempts - 1).max(0) as usize).min(backoffs.len() - 1);
                let backoff_min = backoffs[idx];

                if new_attempts >= cfg.max_attempts {
                    let _ = sqlx::query(
                        "UPDATE email_queue
                         SET status = 'failed', attempts = ?, last_error = ?
                         WHERE id = ?",
                    )
                    .bind(new_attempts)
                    .bind(&clean_err_truncated)
                    .bind(id)
                    .execute(pool)
                    .await;
                } else {
                    let _ = sqlx::query(
                        "UPDATE email_queue
                         SET status = 'pending', attempts = ?, last_error = ?, scheduled_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                         WHERE id = ?",
                    )
                    .bind(new_attempts)
                    .bind(&clean_err_truncated)
                    .bind(backoff_min)
                    .bind(id)
                    .execute(pool)
                    .await;
                }
            }
        }
    }

    if !dry_run {
        let now_ts = Utc::now().timestamp().to_string();
        let _ = sqlx::query(
            "INSERT INTO settings (`key`, `value`) VALUES ('mail_last_run', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        )
        .bind(&now_ts)
        .execute(pool)
        .await;
    }

    Ok(MailWorkerResult {
        claimed: claimed_ids.len(),
        sent,
        failed,
        elapsed_secs: start.elapsed().as_secs_f64(),
    })
}

async fn send_email_via_smtp(
    cfg: &MailConfig,
    to_email: &str,
    to_name: Option<&str>,
    subject: &str,
    body_html: &str,
) -> Result<(), String> {
    if !to_email.contains('@') {
        return Err(format!("Invalid recipient email address: {}", to_email));
    }

    let to_header = match to_name {
        Some(name) if !name.is_empty() => format!("{} <{}>", name, to_email),
        _ => to_email.to_string(),
    };
    let from_header = format!("{} <{}>", cfg.from_name, cfg.from_email);

    let email = Message::builder()
        .from(from_header.parse().map_err(|e| format!("From header error: {:?}", e))?)
        .to(to_header.parse().map_err(|e| format!("To header error: {:?}", e))?)
        .subject(subject)
        .header(ContentType::TEXT_HTML)
        .body(body_html.to_string())
        .map_err(|e| format!("Message build error: {:?}", e))?;

    let mailer = if !cfg.username.is_empty() {
        let creds = Credentials::new(cfg.username.clone(), cfg.password.clone());
        AsyncSmtpTransport::<Tokio1Executor>::relay(&cfg.host)
            .map_err(|e| format!("Transport relay error: {:?}", e))?
            .port(cfg.port)
            .credentials(creds)
            .timeout(Some(std::time::Duration::from_secs(cfg.timeout_secs)))
            .build()
    } else {
        AsyncSmtpTransport::<Tokio1Executor>::relay(&cfg.host)
            .map_err(|e| format!("Transport relay error: {:?}", e))?
            .port(cfg.port)
            .timeout(Some(std::time::Duration::from_secs(cfg.timeout_secs)))
            .build()
    };

    mailer.send(email).await.map_err(|e| format!("{:?}", e))?;
    Ok(())
}
