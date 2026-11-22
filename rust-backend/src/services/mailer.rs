use lettre::{
    message::header::ContentType,
    transport::smtp::authentication::Credentials,
    AsyncSmtpTransport, AsyncTransport, Message, Tokio1Executor,
};
use sqlx::{MySql, Pool, Row};
use std::collections::HashMap;

use crate::crypto::app_decrypt;
use crate::settings::SettingsRepository;

/// Queue an email into email_queue
pub async fn queue_email(
    pool: &Pool<MySql>,
    template_slug: Option<&str>,
    to_email: &str,
    to_name: Option<&str>,
    subject: &str,
    body_html: &str,
    body_text: Option<&str>,
    related_type: Option<&str>,
    related_id: Option<u64>,
) -> Result<u64, sqlx::Error> {
    let res = sqlx::query(
        "INSERT INTO email_queue
          (template_slug, to_email, to_name, subject, body_html, body_text,
           related_type, related_id, status, attempts, scheduled_at, created_at)
         VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW(), NOW())",
    )
    .bind(template_slug)
    .bind(to_email)
    .bind(to_name)
    .bind(subject)
    .bind(body_html)
    .bind(body_text)
    .bind(related_type)
    .bind(related_id)
    .execute(pool)
    .await?;

    Ok(res.last_insert_id())
}

/// Queue email from an email_template with placeholder replacements
pub async fn queue_template_email(
    pool: &Pool<MySql>,
    slug: &str,
    to_email: &str,
    to_name: Option<&str>,
    vars: &HashMap<&str, &str>,
    related_type: Option<&str>,
    related_id: Option<u64>,
) -> Result<Option<u64>, sqlx::Error> {
    let row = sqlx::query(
        "SELECT subject, body_html, body_text, is_active FROM email_templates WHERE slug = ? LIMIT 1",
    )
    .bind(slug)
    .fetch_optional(pool)
    .await?;

    let r = match row {
        Some(r) => r,
        None => return Ok(None),
    };

    let is_active: i8 = r.try_get("is_active").unwrap_or(1);
    if is_active == 0 {
        return Ok(None);
    }

    let mut subject: String = r.try_get("subject").unwrap_or_default();
    let mut body_html: String = r.try_get("body_html").unwrap_or_default();
    let body_text: Option<String> = r.try_get("body_text").ok();

    for (k, v) in vars {
        let needle = format!("{{{}}}", k);
        subject = subject.replace(&needle, v);
        body_html = body_html.replace(&needle, v);
    }

    let id = queue_email(
        pool,
        Some(slug),
        to_email,
        to_name,
        &subject,
        &body_html,
        body_text.as_deref(),
        related_type,
        related_id,
    )
    .await?;

    Ok(Some(id))
}

/// Send a queued email using Lettre AsyncSmtpTransport
pub async fn send_queued_email(
    pool: &Pool<MySql>,
    settings: &SettingsRepository,
    queue_id: u64,
) -> Result<(), String> {
    let row = sqlx::query("SELECT * FROM email_queue WHERE id = ? LIMIT 1")
        .bind(queue_id)
        .fetch_optional(pool)
        .await
        .map_err(|e| e.to_string())?
        .ok_or_else(|| "Queue entry not found".to_string())?;

    let to_email: String = row.try_get("to_email").unwrap_or_default();
    let to_name: Option<String> = row.try_get("to_name").ok();
    let subject: String = row.try_get("subject").unwrap_or_default();
    let body_html: String = row.try_get("body_html").unwrap_or_default();

    let host = settings.get_or("mail_host", "localhost");
    let port = settings.get_or("mail_port", "587").parse::<u16>().unwrap_or(587);
    let user = settings.get_or("mail_username", "");
    let enc_pass = settings.get_or("mail_password", "");
    let from_email = settings.get_or("mail_from_address", "noreply@jinishpotro.com");
    let from_name = settings.get_or("mail_from_name", "Jinish Potro");

    let app_key = settings.get_or("app_key", "");
    let pass = app_decrypt(&enc_pass, &app_key).unwrap_or(enc_pass);

    let to_header = match to_name {
        Some(name) => format!("{} <{}>", name, to_email),
        None => to_email.clone(),
    };
    let from_header = format!("{} <{}>", from_name, from_email);

    let email = Message::builder()
        .from(from_header.parse().map_err(|e| format!("{:?}", e))?)
        .to(to_header.parse().map_err(|e| format!("{:?}", e))?)
        .subject(subject)
        .header(ContentType::TEXT_HTML)
        .body(body_html)
        .map_err(|e| format!("{:?}", e))?;

    let mailer = if !user.is_empty() {
        let creds = Credentials::new(user, pass);
        AsyncSmtpTransport::<Tokio1Executor>::relay(&host)
            .map_err(|e| format!("{:?}", e))?
            .port(port)
            .credentials(creds)
            .build()
    } else {
        AsyncSmtpTransport::<Tokio1Executor>::relay(&host)
            .map_err(|e| format!("{:?}", e))?
            .port(port)
            .build()
    };

    match mailer.send(email).await {
        Ok(_) => {
            let _ = sqlx::query("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?")
                .bind(queue_id)
                .execute(pool)
                .await;
            Ok(())
        }
        Err(e) => {
            let err_msg = format!("{:?}", e);
            let _ = sqlx::query(
                "UPDATE email_queue SET status = 'failed', error_message = ?, attempts = attempts + 1 WHERE id = ?",
            )
            .bind(&err_msg)
            .bind(queue_id)
            .execute(pool)
            .await;
            Err(err_msg)
        }
    }
}

/// Mailer service struct
#[derive(Clone)]
pub struct Mailer {
    pub pool: Pool<MySql>,
    pub smtp_host: String,
    pub smtp_port: u16,
    pub smtp_user: String,
    pub smtp_pass: String,
    pub smtp_from: String,
}

impl Mailer {
    pub fn new(
        smtp_host: String,
        smtp_port: u16,
        smtp_user: String,
        smtp_pass: String,
        smtp_from: String,
        pool: Pool<MySql>,
    ) -> Self {
        Self {
            pool,
            smtp_host,
            smtp_port,
            smtp_user,
            smtp_pass,
            smtp_from,
        }
    }

    pub async fn queue_email(
        &self,
        to_email: &str,
        to_name: Option<&str>,
        subject: &str,
        body_html: &str,
        _is_html: bool,
    ) -> Result<u64, sqlx::Error> {
        queue_email(
            &self.pool,
            None,
            to_email,
            to_name,
            subject,
            body_html,
            None,
            None,
            None,
        )
        .await
    }
}
