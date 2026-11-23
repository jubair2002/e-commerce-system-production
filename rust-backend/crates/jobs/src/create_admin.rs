use bcrypt::{hash, DEFAULT_COST};
use sqlx::{MySql, Pool};
use tracing::info;

pub async fn run_create_admin(
    pool: &Pool<MySql>,
    username: &str,
    email: &str,
    password: &str,
    role: &str,
    dry_run: bool,
) -> Result<u64, String> {
    if username.is_empty() || email.is_empty() || password.is_empty() {
        return Err("Username, email, and password must not be empty.".to_string());
    }

    if password.len() < 8 {
        return Err("Admin password must be at least 8 characters long.".to_string());
    }

    let password_hash = hash(password, DEFAULT_COST)
        .map_err(|e| format!("Password hashing failed: {}", e))?;

    if dry_run {
        info!(
            "  [DRY-RUN] Would create admin user '{}' <{}> with role '{}'",
            username, email, role
        );
        return Ok(0);
    }

    let permissions = if role == "super_admin" {
        r#"["*"]"#
    } else {
        r#"["catalog.*","orders.*","customers.*"]"#
    };

    let res = sqlx::query(
        "INSERT INTO users (username, email, password_hash, role, permissions, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role), permissions = VALUES(permissions), is_active = 1, updated_at = NOW()",
    )
    .bind(username)
    .bind(email)
    .bind(&password_hash)
    .bind(role)
    .bind(permissions)
    .execute(pool)
    .await
    .map_err(|e| format!("Database error creating admin: {}", e))?;

    info!(
        "Successfully created/updated admin user '{}' <{}> (role: {}).",
        username, email, role
    );

    Ok(res.last_insert_id())
}
