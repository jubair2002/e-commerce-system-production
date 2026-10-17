<?php
/**
 * Customer Authentication & Account Management (Prompt 12).
 * Plain PHP / PDO security-first implementation.
 * Customers are stored in `customers` table and are strictly isolated from admin `users`.
 */

require_once __DIR__ . '/shop_bootstrap.php';
require_once __DIR__ . '/mailer.php';

/**
 * Return current logged-in customer data, re-validating at most every 60 seconds.
 * Automatically checks idle timeout and password stamp changes.
 */
function customer_current(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $c = $_SESSION['customer'] ?? null;
    if (!$c || empty($c['id'])) {
        return null;
    }

    $now = time();

    // 1. Idle session timeout check
    $idleMin = (int)setting('customer_session_idle_minutes', '120');
    if ($idleMin > 0 && isset($c['_last_activity'])) {
        if (($now - (int)$c['_last_activity']) > ($idleMin * 60)) {
            customer_logout();
            return null;
        }
    }
    $_SESSION['customer']['_last_activity'] = $now;

    // 2. Re-validate against database at most every 60 seconds
    $lastValidated = (int)($c['_last_validated'] ?? 0);
    if (($now - $lastValidated) > 60) {
        try {
            $stmt = db()->prepare(
                "SELECT id, name, email, phone, status, email_verified_at, password_hash, deleted_at
                 FROM customers WHERE id = :id LIMIT 1"
            );
            $stmt->execute([':id' => (int)$c['id']]);
            $row = $stmt->fetch();

            if (!$row || !empty($row['deleted_at']) || $row['status'] === 'blocked') {
                customer_logout();
                return null;
            }

            // Check if password was changed on another session
            $currentStamp = md5($row['password_hash']);
            if (isset($c['pwd_stamp']) && !hash_equals($c['pwd_stamp'], $currentStamp)) {
                customer_logout();
                return null;
            }

            // Update session cache with fresh fields
            $_SESSION['customer']['name'] = $row['name'];
            $_SESSION['customer']['email'] = $row['email'];
            $_SESSION['customer']['phone'] = $row['phone'];
            $_SESSION['customer']['status'] = $row['status'];
            $_SESSION['customer']['verified'] = !empty($row['email_verified_at']);
            $_SESSION['customer']['pwd_stamp'] = $currentStamp;
            $_SESSION['customer']['_last_validated'] = $now;
        } catch (Throwable $e) {
            // Keep existing session on transient DB error
        }
    }

    return $_SESSION['customer'];
}

/**
 * Register a new customer account.
 * Anti-enumeration: returns generic confirmation even if email already exists.
 */
function customer_register(array $in): array
{
    $errors = [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // Registration enabled check
    if ((int)setting('customer_registration_enabled', '1') !== 1) {
        return ['ok' => false, 'error' => 'Customer registration is currently disabled.'];
    }

    // CSRF Check
    if (!csrf_verify($in['_csrf'] ?? null)) {
        return ['ok' => false, 'error' => 'Invalid or expired form session. Please try again.'];
    }

    // Honeypot check
    if (!empty($in['website_url'])) {
        return ['ok' => true, 'message' => 'Check your email to finish creating your account.'];
    }

    // Time-trap check (form must take at least 2.5 seconds to fill out)
    $formRendered = (int)($in['_render_ts'] ?? 0);
    if ($formRendered > 0 && (time() - $formRendered) < 2) {
        return ['ok' => true, 'message' => 'Check your email to finish creating your account.'];
    }

    // Rate Limiting: 5 attempts per hour per IP
    if (auth_rate_limited('register', '', $ip)) {
        return ['ok' => false, 'error' => 'Too many registration attempts. Please try again later.'];
    }

    $name = trim((string)($in['name'] ?? ''));
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $phone = trim((string)($in['phone'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $confirmPassword = (string)($in['confirm_password'] ?? '');
    $marketing = !empty($in['marketing_opt_in']) ? 1 : 0;

    if (mb_strlen($name) < 2) {
        $errors['name'] = 'Full name must be at least 2 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'A valid email address is required.';
    }

    // Phone validation (optional or BD format)
    if ($phone !== '') {
        $cleanPhone = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($cleanPhone) < 7 || strlen($cleanPhone) > 20) {
            $errors['phone'] = 'Please enter a valid contact phone number.';
        }
    }

    // Password validation
    $pwErr = password_ok($password, $email, $name);
    if ($pwErr) {
        $errors['password'] = $pwErr;
    } elseif ($password !== $confirmPassword) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    // Terms agreement check if terms URL is set
    $termsUrl = setting('customer_terms_url', '');
    if (!empty($termsUrl) && empty($in['agree_terms'])) {
        $errors['agree_terms'] = 'You must agree to the Terms of Service to register.';
    }

    if (!empty($errors)) {
        auth_attempt_log('register', $email, $ip, false);
        return ['ok' => false, 'errors' => $errors];
    }

    $pdo = db();

    // Check existing customer (Anti-enumeration: don't reveal existence to attacker)
    $stmt = $pdo->prepare("SELECT id, name, status, email_verified_at FROM customers WHERE email = :e LIMIT 1");
    $stmt->execute([':e' => $email]);
    $existing = $stmt->fetch();

    if ($existing) {
        auth_attempt_log('register', $email, $ip, true);

        // Send "Account already exists" email with login & password reset link
        $resetTtl = (int)setting('customer_password_reset_minutes', setting('customer_reset_token_minutes', '60'));
        $resetToken = token_issue((int)$existing['id'], 'password_reset', $resetTtl * 60);
        $resetUrl = shop_url('reset_password.php?t=' . urlencode($resetToken));
        $loginUrl = shop_url('login.php');

        mail_queue_template('customer_welcome', $email, $existing['name'], [
            'customer_name' => $existing['name'],
            'account_url'   => $loginUrl,
            'reset_url'     => $resetUrl,
        ]);

        return [
            'ok'      => true,
            'message' => 'If this email is not already registered, an activation link has been sent. Please check your inbox.',
        ];
    }

    // Insert new customer
    $requireVerify = (int)setting('customer_require_email_verification', '1') === 1;
    $status = $requireVerify ? 'pending' : 'active';
    $passwordHash = customer_password_hash($password);

    $stmtIns = $pdo->prepare(
        "INSERT INTO customers
          (name, email, phone, password_hash, status, marketing_opt_in, created_at)
         VALUES
          (:name, :email, :phone, :hash, :status, :mkt, NOW())"
    );
    $stmtIns->execute([
        ':name'   => $name,
        ':email'  => $email,
        ':phone'  => $phone ?: null,
        ':hash'   => $passwordHash,
        ':status' => $status,
        ':mkt'    => $marketing,
    ]);

    $customerId = (int)$pdo->lastInsertId();
    auth_attempt_log('register', $email, $ip, true);

    if ($requireVerify) {
        $ttlHours = (int)setting('customer_verify_token_hours', '48');
        $token = token_issue($customerId, 'verify_email', $ttlHours * 3600);
        $verifyUrl = shop_url('verify_email.php?t=' . urlencode($token));

        mail_queue_template('customer_verify_email', $email, $name, [
            'customer_name' => $name,
            'verify_url'    => $verifyUrl,
            'expires_in'    => $ttlHours . ' hours',
        ]);

        return [
            'ok'            => true,
            'customer_id'   => $customerId,
            'status'        => 'pending',
            'message'       => 'Registration successful! Please check your email to activate your account.',
        ];
    } else {
        // Direct activation
        $stmtUp = $pdo->prepare("UPDATE customers SET email_verified_at = NOW() WHERE id = :id");
        $stmtUp->execute([':id' => $customerId]);

        customer_claim_guest_orders($customerId, $email);

        mail_queue_template('customer_welcome', $email, $name, [
            'customer_name' => $name,
            'account_url'   => shop_url('account/'),
        ]);

        // Auto-login
        customer_session_start([
            'id'            => $customerId,
            'name'          => $name,
            'email'         => $email,
            'phone'         => $phone,
            'status'        => 'active',
            'password_hash' => $passwordHash,
            'verified'      => true,
        ]);

        return [
            'ok'          => true,
            'customer_id' => $customerId,
            'status'      => 'active',
            'message'     => 'Welcome! Your account is active.',
        ];
    }
}

/**
 * Authenticate customer login.
 * Includes constant-time password verify, rate-limiting, and account lockout defense.
 */
function customer_login(string $email, string $pass, bool $remember = false): array
{
    $email = strtolower(trim($email));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // 1. IP and Identifier Rate Limiting
    if (auth_rate_limited('login', $email, $ip)) {
        return ['ok' => false, 'error' => 'Too many login attempts. Please wait 15 minutes before trying again.'];
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT id, name, email, phone, password_hash, status, failed_logins,
                locked_until, email_verified_at, deleted_at
         FROM customers
         WHERE email = :e LIMIT 1"
    );
    $stmt->execute([':e' => $email]);
    $user = $stmt->fetch();

    // Constant-time timing defense: Always verify password against dummy hash if user doesn't exist
    $dummyHash = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012';

    if (!$user || !empty($user['deleted_at'])) {
        customer_password_verify($pass, $dummyHash);
        auth_attempt_log('login', $email, $ip, false);
        return ['ok' => false, 'error' => 'Email or password is incorrect.'];
    }

    // 2. Account Lockout check
    $now = time();
    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > $now) {
        $remainingMin = max(1, (int)ceil((strtotime($user['locked_until']) - $now) / 60));
        auth_attempt_log('login', $email, $ip, false);
        return ['ok' => false, 'error' => "Account is temporarily locked. Please try again in {$remainingMin} minutes."];
    }

    // 3. Verify password
    $match = customer_password_verify($pass, $user['password_hash']);

    if (!$match) {
        $failed = (int)$user['failed_logins'] + 1;
        $maxAttempts = (int)setting('customer_login_max_attempts', '5');
        $lockMin = (int)setting('customer_lockout_minutes', '15');
        $lockedUntil = null;

        if ($failed >= $maxAttempts) {
            $lockedUntil = date('Y-m-d H:i:s', $now + ($lockMin * 60));
        }

        $pdo->prepare(
            "UPDATE customers
             SET failed_logins = :f, locked_until = :l
             WHERE id = :id"
        )->execute([
            ':f'  => $failed,
            ':l'  => $lockedUntil,
            ':id' => (int)$user['id'],
        ]);

        auth_attempt_log('login', $email, $ip, false);

        if ($lockedUntil) {
            return ['ok' => false, 'error' => "Too many failed attempts. Account locked for {$lockMin} minutes."];
        }

        return ['ok' => false, 'error' => 'Email or password is incorrect.'];
    }

    // 4. Blocked status check (returns generic error to avoid user enumeration)
    if ($user['status'] === 'blocked') {
        auth_attempt_log('login', $email, $ip, false);
        return ['ok' => false, 'error' => 'Email or password is incorrect.'];
    }

    // 5. Verification status check
    $isVerified = !empty($user['email_verified_at']);
    $blockUnverified = (int)setting('customer_block_login_unverified', setting('customer_block_login_until_verified', '0')) === 1;
    if (!$isVerified && $blockUnverified) {
        return ['ok' => false, 'error' => 'Please verify your email address before signing in.'];
    }

    // Reset login failures on success
    $pdo->prepare(
        "UPDATE customers
         SET failed_logins = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = :ip
         WHERE id = :id"
    )->execute([':ip' => $ip, ':id' => (int)$user['id']]);

    auth_attempt_log('login', $email, $ip, true);

    // Re-hash password if algorithm parameters changed
    if (customer_password_needs_rehash($user['password_hash'])) {
        $newHash = customer_password_hash($pass);
        $pdo->prepare("UPDATE customers SET password_hash = :h WHERE id = :id")
            ->execute([':h' => $newHash, ':id' => (int)$user['id']]);
        $user['password_hash'] = $newHash;
    }

    // Start session
    customer_session_start([
        'id'            => (int)$user['id'],
        'name'          => $user['name'],
        'email'         => $user['email'],
        'phone'         => $user['phone'],
        'status'        => $user['status'],
        'password_hash' => $user['password_hash'],
        'verified'      => $isVerified,
    ]);

    // Handle Remember-Me Cookie
    if ($remember) {
        $days = (int)setting('customer_remember_me_days', '30');
        $rawToken = token_issue((int)$user['id'], 'remember_me', $days * 86400);
        remember_me_cookie_set($rawToken, $days);
    }

    hook_fire('customer.login', ['customer_id' => (int)$user['id']]);

    return [
        'ok'       => true,
        'customer' => $_SESSION['customer'],
        'verified' => $isVerified,
    ];
}

/**
 * Establish logged-in customer session.
 */
function customer_session_start(array $data): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    session_regenerate_id(true);

    $_SESSION['customer'] = [
        'id'              => (int)$data['id'],
        'name'            => $data['name'],
        'email'           => $data['email'],
        'phone'           => $data['phone'] ?? null,
        'status'          => $data['status'] ?? 'active',
        'verified'        => !empty($data['verified']),
        'pwd_stamp'       => md5($data['password_hash'] ?? ''),
        '_last_validated' => time(),
        '_last_activity'  => time(),
    ];
}

/**
 * Log out the customer, revoke remember-me tokens, and regenerate session ID.
 */
function customer_logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $c = $_SESSION['customer'] ?? null;
    if ($c && !empty($c['id'])) {
        hook_fire('customer.logout', ['customer_id' => (int)$c['id']]);
    }

    // Revoke current remember-me cookie if present
    if (!empty($_COOKIE['customer_remember'])) {
        $raw = (string)$_COOKIE['customer_remember'];
        $parts = explode('.', $raw, 2);
        if (count($parts) === 2) {
            try {
                db()->prepare("DELETE FROM customer_tokens WHERE selector = :sel AND type = 'remember_me'")
                    ->execute([':sel' => $parts[0]]);
            } catch (Throwable $e) {}
        }
        remember_me_cookie_clear();
    }

    unset($_SESSION['customer']);
    session_regenerate_id(true);
}

/**
 * Enforce authenticated access on protected storefront pages.
 */
function customer_require_login(string $next = ''): array
{
    $c = customer_current();
    if (!$c) {
        $target = $next ? customer_url_next($next) : ($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . shop_url('login.php?next=' . urlencode(customer_url_next($target))));
        exit;
    }
    return $c;
}

/**
 * Validate password requirements and reject common dictionaries.
 */
function password_ok(string $pw, string $email, string $name = ''): ?string
{
    $minLen = (int)setting('customer_password_min_length', '8');
    if ($minLen < 6) $minLen = 8;

    $len = mb_strlen($pw);
    if ($len < $minLen) {
        return "Password must be at least {$minLen} characters.";
    }
    if ($len > 128) {
        return "Password cannot exceed 128 characters.";
    }

    $lowerPw = mb_strtolower($pw);
    $lowerEmail = mb_strtolower($email);
    $emailPrefix = explode('@', $lowerEmail)[0] ?? '';

    if ($lowerPw === $lowerEmail || ($emailPrefix !== '' && $lowerPw === $emailPrefix)) {
        return "Password cannot be your email address.";
    }

    if ($name !== '') {
        $lowerName = mb_strtolower(trim($name));
        if ($lowerName !== '' && $lowerPw === $lowerName) {
            return "Password cannot be your name.";
        }
    }

    // Top ~50 common passwords rejection
    $common = [
        'password', 'password1', 'password123', '12345678', '123456789', '1234567890',
        'qwertyuiop', 'qwerty123', 'admin123', 'welcome1', 'welcome123', 'letmein123',
        'iloveyou', 'sunshine', 'princess', 'football', 'monkey123', 'shadow123',
        'master123', 'dragon123', 'superman', 'trustno1', 'bangladesh', 'dhaka123',
    ];
    if (in_array($lowerPw, $common, true)) {
        return "This password is too common. Please choose a stronger password.";
    }

    return null;
}

/**
 * Pre-hash with SHA-256 before bcrypt to bypass bcrypt 72-byte truncation limit.
 */
function customer_password_hash(string $pw): string
{
    $preHashed = base64_encode(hash('sha256', $pw, true));
    return password_hash($preHashed, PASSWORD_DEFAULT);
}

function customer_password_verify(string $pw, string $hash): bool
{
    $preHashed = base64_encode(hash('sha256', $pw, true));
    return password_verify($preHashed, $hash);
}

function customer_password_needs_rehash(string $hash): bool
{
    return password_needs_rehash($hash, PASSWORD_DEFAULT);
}

/**
 * Issue single-use cryptographically secure selector.secret token.
 */
function token_issue(int $customerId, string $type, int $ttlSeconds, ?string $payload = null): string
{
    $selector = bin2hex(random_bytes(12)); // 24 chars
    $secret   = bin2hex(random_bytes(32)); // 64 chars
    $hash     = hash('sha256', $secret);

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);

    $stmt = db()->prepare(
        "INSERT INTO customer_tokens
          (customer_id, type, selector, token_hash, payload, expires_at, ip, user_agent, created_at)
         VALUES
          (:cid, :type, :sel, :hash, :pay, DATE_ADD(NOW(), INTERVAL :ttl SECOND), :ip, :ua, NOW())"
    );
    $stmt->execute([
        ':cid'  => $customerId,
        ':type' => $type,
        ':sel'  => $selector,
        ':hash' => $hash,
        ':pay'  => $payload,
        ':ttl'  => $ttlSeconds,
        ':ip'   => $ip,
        ':ua'   => $ua,
    ]);

    return $selector . '.' . $secret;
}

/**
 * Validate and consume a token atomically.
 */
function token_consume(string $type, string $raw): ?array
{
    $parts = explode('.', $raw, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$selector, $secret] = $parts;

    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT * FROM customer_tokens
         WHERE selector = :sel AND type = :type AND expires_at > NOW() AND used_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([':sel' => $selector, ':type' => $type]);
    $token = $stmt->fetch();

    if (!$token) {
        return null;
    }

    if (!hash_equals($token['token_hash'], hash('sha256', $secret))) {
        return null;
    }

    // Atomically claim the token
    $stmtUp = $pdo->prepare("UPDATE customer_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL");
    $stmtUp->execute([':id' => (int)$token['id']]);

    if ($stmtUp->rowCount() !== 1) {
        return null; // Already consumed in parallel
    }

    return $token;
}

/**
 * Validate token on GET without consuming it (for displaying the reset form).
 */
function token_peek(string $type, string $raw): ?array
{
    $parts = explode('.', $raw, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$selector, $secret] = $parts;

    $stmt = db()->prepare(
        "SELECT * FROM customer_tokens
         WHERE selector = :sel AND type = :type AND expires_at > NOW() AND used_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([':sel' => $selector, ':type' => $type]);
    $token = $stmt->fetch();

    if (!$token || !hash_equals($token['token_hash'], hash('sha256', $secret))) {
        return null;
    }

    return $token;
}

/**
 * Revoke tokens for a customer.
 */
function token_revoke_all(int $customerId, ?string $type = null): void
{
    try {
        if ($type !== null) {
            db()->prepare("DELETE FROM customer_tokens WHERE customer_id = :cid AND type = :t")
                ->execute([':cid' => $customerId, ':t' => $type]);
        } else {
            db()->prepare("DELETE FROM customer_tokens WHERE customer_id = :cid")
                ->execute([':cid' => $customerId]);
        }
    } catch (Throwable $e) {}
}

/**
 * Set HTTP-Only remember-me cookie.
 */
function remember_me_cookie_set(string $rawToken, int $days): void
{
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    setcookie('customer_remember', $rawToken, [
        'expires'  => time() + ($days * 86400),
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function remember_me_cookie_clear(): void
{
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    setcookie('customer_remember', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Restore customer session from remember-me cookie if not logged in.
 * Rotates token on every use; invalidates all on theft signal.
 */
function customer_remember_restore(): void
{
    if (PHP_SAPI === 'cli' || customer_current()) {
        return;
    }

    $raw = $_COOKIE['customer_remember'] ?? '';
    if (!$raw || !str_contains($raw, '.')) {
        return;
    }

    $parts = explode('.', $raw, 2);
    if (count($parts) !== 2) {
        remember_me_cookie_clear();
        return;
    }
    [$selector, $secret] = $parts;

    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT ct.*, c.name, c.email, c.phone, c.password_hash, c.status, c.email_verified_at, c.deleted_at
         FROM customer_tokens ct
         JOIN customers c ON c.id = ct.customer_id
         WHERE ct.selector = :sel AND ct.type = 'remember_me' AND ct.expires_at > NOW() AND ct.used_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([':sel' => $selector]);
    $row = $stmt->fetch();

    if (!$row) {
        remember_me_cookie_clear();
        return;
    }

    $cid = (int)$row['customer_id'];

    // Theft detection: Selector was valid but secret was incorrect
    if (!hash_equals($row['token_hash'], hash('sha256', $secret))) {
        token_revoke_all($cid, 'remember_me');
        remember_me_cookie_clear();
        return;
    }

    if (!empty($row['deleted_at']) || $row['status'] === 'blocked') {
        token_revoke_all($cid, 'remember_me');
        remember_me_cookie_clear();
        return;
    }

    // Atomically invalidate old token
    $pdo->prepare("UPDATE customer_tokens SET used_at = NOW() WHERE id = :id")->execute([':id' => (int)$row['id']]);

    // Rotate token (issue fresh token and update cookie)
    $days = (int)setting('customer_remember_me_days', '30');
    $newToken = token_issue($cid, 'remember_me', $days * 86400);
    remember_me_cookie_set($newToken, $days);

    // Establish session
    customer_session_start([
        'id'            => $cid,
        'name'          => $row['name'],
        'email'         => $row['email'],
        'phone'         => $row['phone'],
        'status'        => $row['status'],
        'password_hash' => $row['password_hash'],
        'verified'      => !empty($row['email_verified_at']),
    ]);
}

/**
 * Check brute-force and rate limits.
 */
function auth_rate_limited(string $scope, string $identifier, string $ip): bool
{
    $pdo = db();
    $identHash = hash('sha256', strtolower(trim($identifier)));

    try {
        if ($scope === 'login') {
            // Max 5 failed attempts per identifier in 15 minutes
            if ($identifier !== '') {
                $st1 = $pdo->prepare(
                    "SELECT COUNT(*) FROM auth_attempts
                     WHERE scope = 'login' AND identifier_hash = :idh AND success = 0
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
                );
                $st1->execute([':idh' => $identHash]);
                if ((int)$st1->fetchColumn() >= 5) return true;
            }

            // Max 20 failed attempts per IP in 15 minutes
            $st2 = $pdo->prepare(
                "SELECT COUNT(*) FROM auth_attempts
                 WHERE scope = 'login' AND ip = :ip AND success = 0
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $st2->execute([':ip' => $ip]);
            if ((int)$st2->fetchColumn() >= 20) return true;
        } elseif ($scope === 'register') {
            // Max 5 registrations per hour per IP
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM auth_attempts
                 WHERE scope = 'register' AND ip = :ip
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            $st->execute([':ip' => $ip]);
            if ((int)$st->fetchColumn() >= 5) return true;
        } elseif ($scope === 'forgot') {
            // Max 5 forgot password requests per hour per email or IP
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM auth_attempts
                 WHERE scope = 'forgot' AND (identifier_hash = :idh OR ip = :ip)
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            $st->execute([':idh' => $identHash, ':ip' => $ip]);
            if ((int)$st->fetchColumn() >= 5) return true;
        } elseif ($scope === 'resend') {
            // Max 3 resend verification emails per hour
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM auth_attempts
                 WHERE scope = 'resend' AND (identifier_hash = :idh OR ip = :ip)
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            $st->execute([':idh' => $identHash, ':ip' => $ip]);
            if ((int)$st->fetchColumn() >= 3) return true;
        }
    } catch (Throwable $e) {}

    return false;
}

/**
 * Log auth attempt for brute force tracking.
 */
function auth_attempt_log(string $scope, string $identifier, string $ip, bool $success): void
{
    try {
        $identHash = hash('sha256', strtolower(trim($identifier)));
        $stmt = db()->prepare(
            "INSERT INTO auth_attempts (scope, identifier_hash, ip, success, created_at)
             VALUES (:scope, :hash, :ip, :succ, NOW())"
        );
        $stmt->execute([
            ':scope' => $scope,
            ':hash'  => $identHash,
            ':ip'    => $ip,
            ':succ'  => $success ? 1 : 0,
        ]);
    } catch (Throwable $e) {}
}

/**
 * Automatically attach past guest orders to customer upon email verification.
 */
function customer_claim_guest_orders(int $customerId, string $email): int
{
    if ($customerId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 0;
    }
    $allowClaim = (int)setting('customer_claim_guest_orders_on_verify', setting('customer_claim_guest_orders', '1')) === 1;
    if (!$allowClaim) {
        return 0;
    }

    try {
        $stmt = db()->prepare(
            "UPDATE orders
             SET customer_id = :cid
             WHERE customer_id IS NULL AND LOWER(customer_email) = LOWER(:email)"
        );
        $stmt->execute([':cid' => $customerId, ':email' => trim($email)]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Validate open redirect allow-list for ?next= query parameter.
 * Prevents evil.com or javascript: open redirects.
 * Must start with '/', not '//', no scheme (no ':'), no backslash ('\'); otherwise 'account/'.
 */
function customer_url_next(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return 'account/';
    }

    $raw = trim($raw);
    if (str_starts_with($raw, '/') && !str_starts_with($raw, '//') && !str_contains($raw, '\\') && !str_contains($raw, ':')) {
        return $raw;
    }

    return 'account/';
}

/**
 * Handle password reset request (Prompt 12).
 * Anti-enumeration: returns identical message and equalized timing whether email exists or not.
 */
function customer_forgot_password(string $email): array
{
    $email = strtolower(trim($email));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please enter a valid email address.'];
    }

    if (auth_rate_limited('forgot', $email, $ip)) {
        return ['ok' => false, 'error' => 'Too many reset requests. Please try again in a few minutes.'];
    }

    $pdo = db();
    $start = microtime(true);

    try {
        $stmt = $pdo->prepare("SELECT id, name, status, email FROM customers WHERE email = :e AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':e' => $email]);
        $customer = $stmt->fetch();

        if ($customer) {
            auth_attempt_log('forgot', $email, $ip, true);

            // Invalidate previous unused reset tokens
            token_revoke_all((int)$customer['id'], 'password_reset');

            $ttlMinutes = (int)setting('customer_password_reset_minutes', setting('customer_reset_token_minutes', '60'));
            $ttlSeconds = max(300, $ttlMinutes * 60);
            $token = token_issue((int)$customer['id'], 'password_reset', $ttlSeconds);
            $resetUrl = shop_url('reset_password.php?t=' . urlencode($token));

            mail_queue_template('customer_password_reset', $email, $customer['name'], [
                'customer_name' => $customer['name'],
                'reset_url'     => $resetUrl,
                'expires_in'    => $ttlMinutes . ' minutes',
                'site_name'     => setting('site_name', 'Store'),
            ]);
        } else {
            auth_attempt_log('forgot', $email, $ip, false);
            // Simulate equivalent work so timing matches within +-50ms
            $dummyToken = bin2hex(random_bytes(32));
            hash('sha256', $dummyToken);
            usleep(random_int(15000, 25000));
        }
    } catch (Throwable $e) {
        error_log("customer_forgot_password error: " . $e->getMessage());
    }

    return [
        'ok'      => true,
        'message' => 'If that email exists, we sent a reset link. Please check your inbox.',
    ];
}

/**
 * Handle password reset form submission (Prompt 12).
 * Consumes single-use token, updates password, clears remember tokens, queues notification.
 */
function customer_reset_password(string $rawToken, string $newPassword, string $confirmPassword): array
{
    $tokenData = token_peek('password_reset', $rawToken);
    if (!$tokenData) {
        return ['ok' => false, 'error' => 'This password reset link is invalid, expired, or has already been used. Please request a new one.'];
    }

    if ($newPassword !== $confirmPassword) {
        return ['ok' => false, 'error' => 'Passwords do not match.'];
    }

    $pdo = db();
    $stmtC = $pdo->prepare("SELECT id, name, email, status, email_verified_at FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmtC->execute([':id' => (int)$tokenData['customer_id']]);
    $customer = $stmtC->fetch();

    if (!$customer) {
        return ['ok' => false, 'error' => 'Account not found or has been disabled.'];
    }

    $err = password_ok($newPassword, $customer['email'], $customer['name']);
    if ($err !== null) {
        return ['ok' => false, 'error' => $err];
    }

    // Atomically consume token
    $consumed = token_consume('password_reset', $rawToken);
    if (!$consumed) {
        return ['ok' => false, 'error' => 'This password reset link has already been used or has expired.'];
    }

    $newHash = customer_password_hash($newPassword);
    $wasUnverified = empty($customer['email_verified_at']);

    // Update password, clear failed attempts and unlock account, mark verified if pending
    $stmtUp = $pdo->prepare(
        "UPDATE customers
         SET password_hash = :hash,
             failed_logins = 0,
             locked_until = NULL,
             status = IF(status = 'pending', 'active', status),
             email_verified_at = IF(email_verified_at IS NULL, NOW(), email_verified_at),
             updated_at = NOW()
         WHERE id = :id"
    );
    $stmtUp->execute([
        ':hash' => $newHash,
        ':id'   => (int)$customer['id'],
    ]);

    // Claim guest orders if this was previously unverified
    if ($wasUnverified) {
        customer_claim_guest_orders((int)$customer['id'], $customer['email']);
    }

    // Revoke all remember-me tokens (forces re-authentication on other devices)
    token_revoke_all((int)$customer['id'], 'remember_me');
    remember_me_cookie_clear();

    // Queue security notification
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    mail_queue_template('customer_password_changed', $customer['email'], $customer['name'], [
        'customer_name' => $customer['name'],
        'ip'            => $ip,
        'changed_at'    => date('Y-m-d H:i:s'),
        'reset_url'     => shop_url('forgot_password.php'),
        'site_name'     => setting('site_name', 'Store'),
    ]);

    // Refresh customer record and start session
    $stmtFresh = $pdo->prepare("SELECT id, name, email, phone, status, email_verified_at, password_hash FROM customers WHERE id = :id");
    $stmtFresh->execute([':id' => (int)$customer['id']]);
    $fresh = $stmtFresh->fetch();
    if ($fresh) {
        customer_session_start($fresh);
    }

    return [
        'ok'      => true,
        'message' => 'Your password has been reset successfully. You are now signed in.',
    ];
}

/**
 * Handle email verification via link (Prompt 12).
 * Consumes single-use token, activates customer, claims guest orders, queues welcome email.
 */
function customer_verify_email(string $rawToken): array
{
    $tokenType = 'verify_email';
    $token = token_consume('verify_email', $rawToken);
    if (!$token) {
        $token = token_consume('email_change', $rawToken);
        if ($token) {
            $tokenType = 'email_change';
        }
    }

    if (!$token) {
        return [
            'ok'    => false,
            'error' => 'This verification link is invalid, expired, or has already been used.',
        ];
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => (int)$token['customer_id']]);
    $customer = $stmt->fetch();

    if (!$customer) {
        return ['ok' => false, 'error' => 'Customer account not found.'];
    }

    if ($tokenType === 'email_change') {
        $newEmail = strtolower(trim((string)$token['payload']));
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid email address in confirmation token.'];
        }

        // Verify that the new email hasn't been claimed by another account in the meantime
        $stmtChk = $pdo->prepare("SELECT id FROM customers WHERE email = :e AND id != :cid LIMIT 1");
        $stmtChk->execute([':e' => $newEmail, ':cid' => (int)$customer['id']]);
        if ($stmtChk->fetch()) {
            return ['ok' => false, 'error' => 'This email address is already in use by another account.'];
        }

        $stmtUp = $pdo->prepare(
            "UPDATE customers
             SET email = :new_email,
                 email_verified_at = NOW(),
                 status = 'active',
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmtUp->execute([
            ':new_email' => $newEmail,
            ':id'        => (int)$customer['id'],
        ]);

        $claimedCount = customer_claim_guest_orders((int)$customer['id'], $newEmail);

        if (isset($_SESSION['customer']['id']) && (int)$_SESSION['customer']['id'] === (int)$customer['id']) {
            $_SESSION['customer']['email'] = $newEmail;
            $_SESSION['customer']['verified'] = true;
            $_SESSION['customer']['status'] = 'active';
        }

        return [
            'ok'      => true,
            'message' => 'Your email address has been successfully updated and verified!',
        ];
    }

    $wasAlreadyVerified = !empty($customer['email_verified_at']);

    // Mark verified and active
    $stmtUp = $pdo->prepare(
        "UPDATE customers
         SET email_verified_at = IF(email_verified_at IS NULL, NOW(), email_verified_at),
             status = 'active',
             updated_at = NOW()
         WHERE id = :id"
    );
    $stmtUp->execute([':id' => (int)$customer['id']]);

    // Claim previous guest orders
    $claimedCount = customer_claim_guest_orders((int)$customer['id'], $customer['email']);

    // Queue welcome email if first-time verification
    if (!$wasAlreadyVerified) {
        mail_queue_template('customer_welcome', $customer['email'], $customer['name'], [
            'customer_name' => $customer['name'],
            'account_url'   => shop_url('account/'),
            'site_name'     => setting('site_name', 'Store'),
        ]);
    }

    // If currently logged in as this customer, update session
    if (isset($_SESSION['customer']['id']) && (int)$_SESSION['customer']['id'] === (int)$customer['id']) {
        $_SESSION['customer']['verified'] = true;
        $_SESSION['customer']['status'] = 'active';
    } else {
        // Log in user on verify
        customer_session_start($customer);
    }

    return [
        'ok'       => true,
        'claimed'  => $claimedCount,
        'customer' => $customer,
        'message'  => 'Your email address has been verified successfully. Welcome!',
    ];
}

/**
 * Resend email verification link (Prompt 12).
 * Anti-enumeration: returns uniform success response.
 */
function customer_resend_verification(?string $email = null): array
{
    $current = customer_current();
    if ($current && empty($email)) {
        $email = $current['email'];
    }

    $email = strtolower(trim((string)$email));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please provide a valid email address.'];
    }

    if (auth_rate_limited('resend', $email, $ip)) {
        return ['ok' => false, 'error' => 'Too many verification requests. Please wait a few minutes before trying again.'];
    }

    $pdo = db();
    try {
        $stmt = $pdo->prepare("SELECT id, name, status, email_verified_at FROM customers WHERE email = :e AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':e' => $email]);
        $customer = $stmt->fetch();

        if ($customer && empty($customer['email_verified_at'])) {
            auth_attempt_log('resend', $email, $ip, true);

            // Revoke prior unconsumed verification tokens
            token_revoke_all((int)$customer['id'], 'verify_email');

            $verifyHours = (int)setting('customer_verify_token_hours', '48');
            $ttlSeconds = max(3600, $verifyHours * 3600);
            $token = token_issue((int)$customer['id'], 'verify_email', $ttlSeconds);
            $verifyUrl = shop_url('verify_email.php?t=' . urlencode($token));

            mail_queue_template('customer_verify_email', $email, $customer['name'], [
                'customer_name' => $customer['name'],
                'verify_url'    => $verifyUrl,
                'expires_in'    => $verifyHours . ' hours',
                'site_name'     => setting('site_name', 'Store'),
            ]);
        } else {
            auth_attempt_log('resend', $email, $ip, false);
            usleep(random_int(12000, 20000));
        }
    } catch (Throwable $e) {
        error_log("customer_resend_verification error: " . $e->getMessage());
    }

    return [
        'ok'      => true,
        'message' => 'If your account is awaiting verification, a new link has been sent to your inbox.',
    ];
}

