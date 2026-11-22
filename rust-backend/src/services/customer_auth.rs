use chrono::Utc;
use rand::RngCore;
use sha2::{Digest, Sha256};
use sqlx::{MySql, Pool};

use crate::crypto::{customer_password_hash, customer_password_verify};
use crate::models::customer::{Customer, CustomerSession, CustomerToken};

/// Issue a split token `selector.secret`
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
    let parts: Vec<&str> = raw.splitn(2, '.').collect();
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

/// Consume token atomically
pub async fn token_consume(
    pool: &Pool<MySql>,
    token_type: &str,
    raw: &str,
) -> Result<Option<CustomerToken>, sqlx::Error> {
    let parts: Vec<&str> = raw.splitn(2, '.').collect();
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

/// Authenticate customer with lockout checking and failed login tracking
pub async fn customer_authenticate(
    pool: &Pool<MySql>,
    email_or_phone: &str,
    password: &str,
    ip: Option<&str>,
    user_agent: Option<&str>,
) -> Result<Customer, String> {
    let identifier = email_or_phone.trim();
    if identifier.is_empty() || password.is_empty() {
        return Err("Please enter both email/phone and password.".to_string());
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
            // Anti-enumeration dummy hash check
            let dummy_hash = "$2y$12$e8w.p4Yh8Wp3aYfGZ5e3QeJ99yYV53QZgE5y1W4lQ64hN.w8y17Q.";
            customer_password_verify(password, dummy_hash);
            return Err("Invalid credentials. Please check and try again.".to_string());
        }
    };

    if cust.status == "blocked" {
        return Err("Your account has been suspended. Please contact support.".to_string());
    }

    // Check lock out
    if let Some(lock_until) = cust.locked_until {
        let now = Utc::now().naive_utc();
        if lock_until > now {
            let diff_mins = (lock_until - now).num_minutes().max(1);
            return Err(format!(
                "Account is temporarily locked due to multiple failed login attempts. Please try again in {} minute(s).",
                diff_mins
            ));
        }
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

        let _ = sqlx::query(
            "INSERT INTO auth_attempts (email, ip_address, user_agent, attempt_type, success, created_at)
             VALUES (?, ?, ?, 'login', 0, NOW())",
        )
        .bind(&cust.email)
        .bind(ip.unwrap_or("127.0.0.1"))
        .bind(user_agent)
        .execute(pool)
        .await;

        return Err("Invalid credentials. Please check and try again.".to_string());
    }

    // Successful login: reset failed logins, update last_login
    let _ = sqlx::query(
        "UPDATE customers
         SET failed_logins = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ?
         WHERE id = ?",
    )
    .bind(ip.unwrap_or("127.0.0.1"))
    .bind(cust.id)
    .execute(pool)
    .await;

    let _ = sqlx::query(
        "INSERT INTO auth_attempts (email, ip_address, user_agent, attempt_type, success, created_at)
         VALUES (?, ?, ?, 'login', 1, NOW())",
    )
    .bind(&cust.email)
    .bind(ip.unwrap_or("127.0.0.1"))
    .bind(user_agent)
    .execute(pool)
    .await;

    Ok(cust)
}

/// Register a new customer
pub async fn customer_register(
    pool: &Pool<MySql>,
    name: &str,
    email: &str,
    phone: Option<&str>,
    password: &str,
    marketing_opt_in: bool,
) -> Result<Customer, String> {
    let clean_name = name.trim();
    let clean_email = email.trim().to_lowercase();
    let clean_phone = phone.map(|p| p.trim().to_string()).filter(|p| !p.is_empty());

    if clean_name.is_empty() || clean_email.is_empty() || password.is_empty() {
        return Err("Please complete all required fields.".to_string());
    }

    if password.len() < 8 {
        return Err("Password must be at least 8 characters long.".to_string());
    }

    // Check duplicate email
    let exists: Option<u64> = sqlx::query_scalar("SELECT id FROM customers WHERE email = ? LIMIT 1")
        .bind(&clean_email)
        .fetch_optional(pool)
        .await
        .map_err(|e| format!("Database error: {}", e))?;

    if exists.is_some() {
        return Err("An account with this email address already exists.".to_string());
    }

    let password_hash = customer_password_hash(password)
        .map_err(|e| format!("Password hashing error: {}", e))?;

    let res = sqlx::query(
        "INSERT INTO customers
          (name, email, phone, password_hash, status, marketing_opt_in, created_at, updated_at)
         VALUES
          (?, ?, ?, ?, 'active', ?, NOW(), NOW())",
    )
    .bind(clean_name)
    .bind(&clean_email)
    .bind(&clean_phone)
    .bind(&password_hash)
    .bind(if marketing_opt_in { 1i8 } else { 0i8 })
    .execute(pool)
    .await
    .map_err(|e| format!("Registration failed: {}", e))?;

    let new_id = res.last_insert_id();

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
    let mut hasher = md5::Md5::new();
    use md5::Digest;
    hasher.update(customer.password_hash.as_bytes());
    let pwd_stamp = hex::encode(hasher.finalize());
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
