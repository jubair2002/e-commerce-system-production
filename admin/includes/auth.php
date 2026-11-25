<?php
/**
 * Authentication helpers: login, logout, role checks.
 */
require_once __DIR__ . '/../../includes/traffic.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/crypto.php';
require_once __DIR__ . '/../../includes/hooks.php';
require_once __DIR__ . '/../../includes/catalog.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/analytics.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/customer_auth.php';

function attempt_login(string $email, string $password): bool
{
    $ip = get_client_ip();
    if (function_exists('auth_is_rate_limited') && auth_is_rate_limited('login', $email, $ip)) {
        return false;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        if (function_exists('auth_attempt_log')) {
            auth_attempt_log('login', $email, $ip, false);
        }
        return false;
    }
    if ($user['status'] !== 'active') {
        if (function_exists('auth_attempt_log')) {
            auth_attempt_log('login', $email, $ip, false);
        }
        return false;
    }

    if (function_exists('auth_attempt_log')) {
        auth_attempt_log('login', $email, $ip, true);
    }

    // Re-hash if PHP suggests a stronger algorithm.
    if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        db()->prepare('UPDATE users SET password = :p WHERE id = :id')
            ->execute([':p' => $newHash, ':id' => $user['id']]);
    }

    // Prevent session fixation.
    session_regenerate_id(true);

    $permissions = [];
    if (!empty($user['permissions'])) {
        $decoded = is_string($user['permissions']) ? json_decode($user['permissions'], true) : $user['permissions'];
        if (is_array($decoded)) {
            $permissions = $decoded;
        }
    }
    if ($user['role'] === 'admin' && !in_array('*', $permissions, true)) {
        $permissions[] = '*';
    }

    $_SESSION['user'] = [
        'id'          => (int)$user['id'],
        'name'        => $user['name'],
        'email'       => $user['email'],
        'role'        => $user['role'],
        'permissions' => $permissions,
        'avatar'      => $user['avatar'],
    ];

    log_activity('login', 'User logged in');
    return true;
}

function logout(): void
{
    log_activity('logout', 'User logged out');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}

function has_role(string ...$roles): bool
{
    $user = current_user();
    if (!$user) return false;
    $role = (string)($user['role'] ?? '');
    if ($role === 'admin') return true;
    if (in_array($role, $roles, true)) return true;
    if (in_array('editor', $roles, true) && $role === 'manager') return true;
    return false;
}

function require_login(): void
{
    if (PHP_SAPI === 'cli') return;
    if (!is_logged_in()) {
        flash('warning', 'Please log in to continue.');
        admin_redirect('login.php');
    }
}

function require_role(string ...$roles): void
{
    if (PHP_SAPI === 'cli') return;
    require_login();
    if (!has_role(...$roles)) {
        http_response_code(403);
        flash('danger', 'You do not have permission to access that page.');
        admin_redirect('dashboard.php');
    }
}

/** Refresh the cached user record from DB (after profile changes). */
function refresh_user_session(): void
{
    if (!is_logged_in()) return;
    $stmt = db()->prepare('SELECT id, name, email, role, permissions, avatar FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user']['id']]);
    if ($u = $stmt->fetch()) {
        $permissions = [];
        if (!empty($u['permissions'])) {
            $decoded = is_string($u['permissions']) ? json_decode($u['permissions'], true) : $u['permissions'];
            if (is_array($decoded)) {
                $permissions = $decoded;
            }
        }
        if ($u['role'] === 'admin' && !in_array('*', $permissions, true)) {
            $permissions[] = '*';
        }
        $_SESSION['user'] = [
            'id'          => (int)$u['id'],
            'name'        => $u['name'],
            'email'       => $u['email'],
            'role'        => $u['role'],
            'permissions' => $permissions,
            'avatar'      => $u['avatar'],
        ];
    }
}
