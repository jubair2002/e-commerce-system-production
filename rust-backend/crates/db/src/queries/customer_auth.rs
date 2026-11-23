use chrono::Utc;
use rand::RngCore;
use sha2::{Digest, Sha256};
use sqlx::{MySql, Pool};

use common::crypto::{customer_password_hash, customer_password_verify, dummy_customer_password_verify};
use domain::models::customer::{Customer, CustomerSession, CustomerToken};

/// Common password blacklist matching PHP includes/customer_auth.php:483
pub const COMMON_PASSWORDS: &[&str] = &[
    "password", "password1", "password123", "12345678", "123456789", "1234567890",
    "qwertyuiop", "qwerty123", "admin123", "welcome1", "welcome123", "letmein123",
    "iloveyou", "sunshine", "princess", "football", "monkey123", "shadow123",
    "master123", "dragon123", "superman", "trustno1", "bangladesh", "dhaka123",
];

/// Password policy validation matching PHP password_ok()
pub fn password_ok(pw: &str, email: &str, name: &str, min_len: usize) -> Result<(), String> {
    let effective_min = if min_len < 6 { 8 } else { min_len };
    if pw.len() < effective_min {
        return Err(format!("Password must be at least {} characters.", effective_min));
    }
    if pw.len() > 128 {
        return Err("Password cannot exceed 128 characters.".to_string());
    }

    let lower_pw = pw.to_lowercase();
    let lower_email = email.to_lowercase();
    let email_prefix = lower_email.split('@').next().unwrap_or("");

    if lower_pw == lower_email || (!email_prefix.is_empty() && lower_pw == email_prefix) {
        return Err("Password cannot be your email address.".to_string());
    }

    if !name.trim().is_empty() {
        let lower_name = name.trim().to_lowercase();
        if lower_pw == lower_name {
            return Err("Password cannot be your name.".to_string());
        }
    }

    if COMMON_PASSWORDS.contains(&lower_pw.as_str()) {
        return Err("This password is too common. Please choose a stronger password.".to_string());
    }

    Ok(())
}

/// Log authentication attempt into auth_attempts table
pub async fn auth_attempt_log(
    pool: &Pool<MySql>,
    scope: &str,
    identifier: &str,
    ip: &str,
    success: bool,
) {
    let mut hasher = Sha256::new();
    hasher.update(identifier.trim().to_lowercase().as_bytes());
    let ident_hash = hex::encode(hasher.finalize());

    let _ = sqlx::query(
        "INSERT INTO auth_attempts (scope, identifier_hash, ip, success, created_at)
         VALUES (?, ?, ?, ?, NOW())",
    )
    .bind(scope)
    .bind(&ident_hash)
    .bind(ip)
    .bind(if success { 1i8 } else { 0i8 })
    .execute(pool)
    .await;
}

/// Check rate limiting in auth_attempts table matching PHP auth_rate_limited()
pub async fn auth_rate_limited(
    pool: &Pool<MySql>,
    scope: &str,
    identifier: &str,
    ip: &str,
) -> bool {
    let mut hasher = Sha256::new();
    hasher.update(identifier.trim().to_lowercase().as_bytes());
    let ident_hash = hex::encode(hasher.finalize());

    match scope {
        "login" => {
            // 5 failed attempts per identifier in 15 minutes
            if !identifier.is_empty() {
                let count: Option<i64> = sqlx::query_scalar(
                    "SELECT COUNT(*) FROM auth_attempts
                     WHERE scope = 'login' AND identifier_hash = ? AND success = 0
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
                )
                .bind(&ident_hash)
                .fetch_optional(pool)
                .await
                .ok()
                .flatten();

                if count.unwrap_or(0) >= 5 {
                    return true;
                }
            }

            // 20 failed attempts per IP in 15 minutes
            let count_ip: Option<i64> = sqlx::query_scalar(
                "SELECT COUNT(*) FROM auth_attempts
                 WHERE scope = 'login' AND ip = ? AND success = 0
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
            )
            .bind(ip)
            .fetch_optional(pool)
            .await
            .ok()
            .flatten();

            count_ip.unwrap_or(0) >= 20
        }
        "register" => {
            // Max 5 registrations per hour per IP
            let count: Option<i64> = sqlx::query_scalar(
                "SELECT COUNT(*) FROM auth_attempts
                 WHERE scope = 'register' AND ip = ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            )
            .bind(ip)
            .fetch_optional(pool)
            .await
            .ok()
            .flatten();

            count.unwrap_or(0) >= 5
        }
        "forgot" => {
            // Max 5 forgot password requests per hour per email or IP
            let count: Option<i64> = sqlx::query_scalar(
                "SELECT COUNT(*) FROM auth_attempts
                 WHERE scope = 'forgot' AND (identifier_hash = ? OR ip = ?)
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            )
            .bind(&ident_hash)
            .bind(ip)
            .fetch_optional(pool)
            .await
            .ok()
            .flatten();

            count.unwrap_or(0) >= 5
        }
        "resend" => {
            // Max 3 resend verification emails per hour
            let count: Option<i64> = sqlx::query_scalar(
                "SELECT COUNT(*) FROM auth_attempts
                 WHERE scope = 'resend' AND (identifier_hash = ? OR ip = ?)
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            )
            .bind(&ident_hash)
            .bind(ip)
            .fetch_optional(pool)
            .await
            .ok()
            .flatten();

            count.unwrap_or(0) >= 3
        }
        _ => false,
    }
}

/// Auto-claim guest orders placed with this email address
pub async fn customer_claim_guest_orders(
    pool: &Pool<MySql>,
    customer_id: u64,
    email: &str,
) -> u64 {
    let clean = email.trim().to_lowercase();
    let res = sqlx::query(
        "UPDATE orders SET customer_id = ?
         WHERE customer_id IS NULL AND LOWER(customer_email) = ?",
    )
    .bind(customer_id)
    .bind(&clean)
    .execute(pool)
    .await;

    res.map(|r| r.rows_affected()).unwrap_or(0)
}

/// Issue a split token `selector.secret` (or selector:validator)
pub async fn token_issue(
    pool: &Pool<MySql>,
    customer_id: u64,
    token_type: &str,
    ttl_seconds: i64,
    payload: Option<&str>,
    ip: Option<&str>,
    user_agent: Option<&str>,
) -> Result<String, sqlx::Error> {
    let mut sel_bytes = [0u8; 12];
    let mut sec_bytes = [0u8; 32];
    rand::thread_rng().fill_bytes(&mut sel_bytes);
    rand::thread_rng().fill_bytes(&mut sec_bytes);

    let selector = hex::encode(sel_bytes);
    let secret = hex::encode(sec_bytes);

    let mut hasher = Sha256::new();
    hasher.update(secret.as_bytes());
    let token_hash = hex::encode(hasher.finalize());

    let safe_ua = user_agent.map(|u| {
        if u.len() > 200 {
            u[..200].to_string()
        } else {
            u.to_string()
        }
    });

    sqlx::query(
        "INSERT INTO customer_tokens
          (customer_id, type, selector, token_hash, payload, expires_at, ip, user_agent, created_at)
         VALUES
          (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?, NOW())",
    )
    .bind(customer_id)
    .bind(token_type)
    .bind(&selector)
    .bind(&token_hash)
    .bind(payload)
    .bind(ttl_seconds)
    .bind(ip.unwrap_or("127.0.0.1"))
    .bind(safe_ua)
    .execute(pool)
    .await?;

    Ok(format!("{}.{}", selector, secret))
}

/// Peek token without consuming it
pub async fn token_peek(
    pool: &Pool<MySql>,
    token_type: &str,
    raw: &str,
) -> Result<Option<CustomerToken>, sqlx::Error> {
    let sep = if raw.contains('.') { '.' } else { ':' };
    let parts: Vec<&str> = raw.splitn(2, sep).collect();
    if parts.len() != 2 {
        return Ok(None);
    }
    let selector = parts[0];
    let secret = parts[1];

    let mut hasher = Sha256::new();
    hasher.update(secret.as_bytes());
    let computed_hash = hex::encode(hasher.finalize());

    let token: Option<CustomerToken> = sqlx::query_as(
        "SELECT * FROM customer_tokens
         WHERE selector = ? AND type = ? AND expires_at > NOW() AND used_at IS NULL
         LIMIT 1",
    )
    .bind(selector)
    .bind(token_type)
    .fetch_optional(pool)
    .await?;

    if let Some(t) = token {
        if t.token_hash == computed_hash {
            return Ok(Some(t));
        }
    }

    Ok(None)
}

/// Consume token atomically (marks used_at = NOW())
pub async fn token_consume(
    pool: &Pool<MySql>,
    token_type: &str,
    raw: &str,
) -> Result<Option<CustomerToken>, sqlx::Error> {
    let sep = if raw.contains('.') { '.' } else { ':' };
    let parts: Vec<&str> = raw.splitn(2, sep).collect();
    if parts.len() != 2 {
        return Ok(None);
    }
    let selector = parts[0];
    let secret = parts[1];

    let mut hasher = Sha256::new();
    hasher.update(secret.as_bytes());
    let computed_hash = hex::encode(hasher.finalize());

    let token: Option<CustomerToken> = sqlx::query_as(
        "SELECT * FROM customer_tokens
         WHERE selector = ? AND type = ? AND expires_at > NOW() AND used_at IS NULL
         LIMIT 1",
    )
    .bind(selector)
    .bind(token_type)
    .fetch_optional(pool)
    .await?;

    let t = match token {
        Some(t) if t.token_hash == computed_hash => t,
        _ => return Ok(None),
    };

    let rows_affected = sqlx::query(
        "UPDATE customer_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL",
    )
    .bind(t.id)
    .execute(pool)
    .await?
    .rows_affected();

    if rows_affected == 1 {
        Ok(Some(t))
    } else {
        Ok(None)
    }
}

/// Revoke all tokens for a customer
pub async fn token_revoke_all(
    pool: &Pool<MySql>,
    customer_id: u64,
    token_type: Option<&str>,
) -> Result<(), sqlx::Error> {
    if let Some(t) = token_type {
        sqlx::query("DELETE FROM customer_tokens WHERE customer_id = ? AND type = ?")
            .bind(customer_id)
            .bind(t)
            .execute(pool)
            .await?;
    } else {
        sqlx::query("DELETE FROM customer_tokens WHERE customer_id = ?")
            .bind(customer_id)
            .execute(pool)
            .await?;
    }
    Ok(())
}

/// Authenticate customer with lockout checking, rate limits, and timing defense
pub async fn customer_authenticate(
    pool: &Pool<MySql>,
    email_or_phone: &str,
    password: &str,
    ip: Option<&str>,
) -> Result<Customer, String> {
    let identifier = email_or_phone.trim();
    let client_ip = ip.unwrap_or("127.0.0.1");

    if identifier.is_empty() || password.is_empty() {
        return Err("Email or password is incorrect.".to_string());
    }

    // Rate limiting check
    if auth_rate_limited(pool, "login", identifier, client_ip).await {
        return Err("Too many login attempts. Please wait 15 minutes before trying again.".to_string());
    }

    let customer: Option<Customer> = sqlx::query_as(
        "SELECT * FROM customers
         WHERE (email = ? OR phone = ?) AND deleted_at IS NULL
         LIMIT 1",
    )
    .bind(identifier)
    .bind(identifier)
    .fetch_optional(pool)
    .await
    .map_err(|e| format!("Database error: {}", e))?;

    let cust = match customer {
        Some(c) => c,
        None => {
            // Constant-time timing attack defense on unknown email
            dummy_customer_password_verify(password);
            auth_attempt_log(pool, "login", identifier, client_ip, false).await;
            return Err("Email or password is incorrect.".to_string());
        }
    };

    // Account lockout check
    if let Some(lock_until) = cust.locked_until {
        let now = Utc::now().naive_utc();
        if lock_until > now {
            let diff_mins = (lock_until - now).num_minutes().max(1);
            auth_attempt_log(pool, "login", identifier, client_ip, false).await;
            return Err(format!(
                "Account is temporarily locked. Please try again in {} minutes.",
                diff_mins
            ));
        }
    }

    // Blocked check
    if cust.status == "blocked" {
        auth_attempt_log(pool, "login", identifier, client_ip, false).await;
        return Err("Email or password is incorrect.".to_string());
    }

    let is_valid = customer_password_verify(password, &cust.password_hash);
    if !is_valid {
        let new_failed = cust.failed_logins + 1;
        let lock_time = if new_failed >= 5 {
            "DATE_ADD(NOW(), INTERVAL 15 MINUTE)"
        } else {
            "NULL"
        };

        let update_sql = format!(
            "UPDATE customers SET failed_logins = ?, locked_until = {} WHERE id = ?",
            lock_time
        );

        let _ = sqlx::query(&update_sql)
            .bind(new_failed)
            .bind(cust.id)
            .execute(pool)
            .await;

        auth_attempt_log(pool, "login", identifier, client_ip, false).await;

        if new_failed >= 5 {
            return Err("Too many failed attempts. Account locked for 15 minutes.".to_string());
        }

        return Err("Email or password is incorrect.".to_string());
    }

    // Successful login: reset failed logins, update last_login
    let _ = sqlx::query(
        "UPDATE customers
         SET failed_logins = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ?
         WHERE id = ?",
    )
    .bind(client_ip)
    .bind(cust.id)
    .execute(pool)
    .await;

    auth_attempt_log(pool, "login", identifier, client_ip, true).await;

    Ok(cust)
}

/// Restore customer session from remember-me cookie (selector.secret) with rotation
pub async fn customer_remember_restore(
    pool: &Pool<MySql>,
    raw_cookie: &str,
    ip: Option<&str>,
) -> Result<Option<(Customer, String)>, sqlx::Error> {
    let raw = raw_cookie.trim();
    if raw.is_empty() {
        return Ok(None);
    }

    let sep = if raw.contains('.') { '.' } else { ':' };
    let parts: Vec<&str> = raw.splitn(2, sep).collect();
    if parts.len() != 2 {
        return Ok(None);
    }
    let selector = parts[0];
    let secret = parts[1];

    let mut hasher = Sha256::new();
    hasher.update(secret.as_bytes());
    let computed_hash = hex::encode(hasher.finalize());

    let token_row: Option<CustomerToken> = sqlx::query_as(
        "SELECT * FROM customer_tokens
         WHERE selector = ? AND type = 'remember_me' AND expires_at > NOW() AND used_at IS NULL
         LIMIT 1",
    )
    .bind(selector)
    .fetch_optional(pool)
    .await?;

    let token = match token_row {
        Some(t) => t,
        None => return Ok(None),
    };

    // Theft detection: selector was valid but secret was incorrect
    if token.token_hash != computed_hash {
        let _ = token_revoke_all(pool, token.customer_id, Some("remember_me")).await;
        return Ok(None);
    }

    let customer: Option<Customer> = sqlx::query_as(
        "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1",
    )
    .bind(token.customer_id)
    .fetch_optional(pool)
    .await?;

    let cust = match customer {
        Some(c) if c.status != "blocked" => c,
        _ => {
            let _ = token_revoke_all(pool, token.customer_id, Some("remember_me")).await;
            return Ok(None);
        }
    };

    // Atomically invalidate old token
    let _ = sqlx::query("UPDATE customer_tokens SET used_at = NOW() WHERE id = ?")
        .bind(token.id)
        .execute(pool)
        .await;

    // Rotate token (issue fresh token for 30 days)
    let new_token = token_issue(
        pool,
        cust.id,
        "remember_me",
        30 * 86400,
        None,
        ip,
        None,
    )
    .await?;

    Ok(Some((cust, new_token)))
}

/// Register a new customer
#[allow(clippy::too_many_arguments)]
pub async fn customer_register(
    pool: &Pool<MySql>,
    name: &str,
    email: &str,
    phone: Option<&str>,
    password: &str,
    marketing_opt_in: bool,
    require_verification: bool,
    ip: &str,
) -> Result<Customer, String> {
    let clean_name = name.trim();
    let clean_email = email.trim().to_lowercase();
    let clean_phone = phone.map(|p| p.trim().to_string()).filter(|p| !p.is_empty());

    if clean_name.is_empty() || clean_email.is_empty() || password.is_empty() {
        return Err("Please complete all required fields.".to_string());
    }

    // Rate limiting check: 5 attempts per hour per IP
    if auth_rate_limited(pool, "register", &clean_email, ip).await {
        return Err("Too many registration attempts. Please try again later.".to_string());
    }

    // Validate password rules
    password_ok(password, &clean_email, clean_name, 8)?;

    // Check duplicate email
    let exists: Option<u64> = sqlx::query_scalar("SELECT id FROM customers WHERE email = ? LIMIT 1")
        .bind(&clean_email)
        .fetch_optional(pool)
        .await
        .map_err(|e| format!("Database error: {}", e))?;

    if exists.is_some() {
        auth_attempt_log(pool, "register", &clean_email, ip, true).await;
        return Err("An account with this email address already exists.".to_string());
    }

    let password_hash = customer_password_hash(password)
        .map_err(|e| format!("Password hashing error: {}", e))?;

    let initial_status = if require_verification { "pending" } else { "active" };

    let res = sqlx::query(
        "INSERT INTO customers
          (name, email, phone, password_hash, status, marketing_opt_in, created_at, updated_at)
         VALUES
          (?, ?, ?, ?, ?, ?, NOW(), NOW())",
    )
    .bind(clean_name)
    .bind(&clean_email)
    .bind(&clean_phone)
    .bind(&password_hash)
    .bind(initial_status)
    .bind(if marketing_opt_in { 1i8 } else { 0i8 })
    .execute(pool)
    .await
    .map_err(|e| format!("Registration failed: {}", e))?;

    let new_id = res.last_insert_id();

    // Auto-claim guest orders
    customer_claim_guest_orders(pool, new_id, &clean_email).await;

    auth_attempt_log(pool, "register", &clean_email, ip, true).await;

    let created: Customer = sqlx::query_as("SELECT * FROM customers WHERE id = ? LIMIT 1")
        .bind(new_id)
        .fetch_one(pool)
        .await
        .map_err(|e| format!("Customer lookup failed: {}", e))?;

    Ok(created)
}

/// Create a CustomerSession from Customer model
pub fn build_customer_session(customer: &Customer) -> CustomerSession {
    let now = Utc::now().timestamp();
    let digest = md5::compute(customer.password_hash.as_bytes());
    let pwd_stamp = format!("{:x}", digest);
    CustomerSession {
        id: customer.id,
        name: customer.name.clone(),
        email: customer.email.clone(),
        phone: customer.phone.clone(),
        status: customer.status.clone(),
        verified: customer.email_verified_at.is_some(),
        pwd_stamp,
        last_activity: now,
        last_validated: now,
    }
}
