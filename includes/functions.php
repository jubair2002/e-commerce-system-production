<?php
/**
 * Generic helper functions used throughout the panel.
 */
require_once __DIR__ . '/polyfills.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/FileCache.php';
require_once __DIR__ . '/images.php';

// ----------------------------------------------------------------
// Output / escaping
// ----------------------------------------------------------------
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

function admin_url(string $path = ''): string
{
    return ADMIN_URL . ltrim($path, '/');
}

/**
 * Route registry mapping logical names to physical file paths and clean URL patterns.
 */
function get_route_map(): array
{
    static $map = [
        'home'                => ['file' => '',                                   'pretty' => ''],
        'shop'                => ['file' => 'storefront/shop/index.php',          'pretty' => 'shop'],
        'category'            => ['file' => 'storefront/shop/category.php',       'pretty' => 'category/{slug}'],
        'product'             => ['file' => 'storefront/shop/product.php',        'pretty' => 'product/{slug}'],
        'cart'                => ['file' => 'storefront/cart/index.php',          'pretty' => 'cart'],
        'checkout'            => ['file' => 'storefront/cart/checkout.php',       'pretty' => 'checkout'],
        'order_confirmation'  => ['file' => 'storefront/cart/confirmation.php',   'pretty' => 'order/confirmation'],
        'track'               => ['file' => 'storefront/track/index.php',         'pretty' => 'track'],
        'login'               => ['file' => 'storefront/auth/login.php',          'pretty' => 'login'],
        'register'            => ['file' => 'storefront/auth/register.php',       'pretty' => 'register'],
        'logout'              => ['file' => 'storefront/auth/logout.php',         'pretty' => 'logout'],
        'forgot_password'     => ['file' => 'storefront/auth/forgot-password.php', 'pretty' => 'forgot-password'],
        'reset_password'      => ['file' => 'storefront/auth/reset-password.php',  'pretty' => 'reset-password'],
        'verify_email'        => ['file' => 'storefront/auth/verify-email.php',   'pretty' => 'verify-email'],
        'resend_verification' => ['file' => 'storefront/auth/resend-verification.php', 'pretty' => 'resend-verification'],
        'account'             => ['file' => 'account/index.php',                  'pretty' => 'account'],
        'account_orders'      => ['file' => 'account/orders.php',                 'pretty' => 'account/orders'],
        'account_order'       => ['file' => 'account/order.php',                  'pretty' => 'account/order'],
        'account_invoice'     => ['file' => 'account/invoice.php',                'pretty' => 'account/invoice'],
        'account_addresses'   => ['file' => 'account/addresses.php',              'pretty' => 'account/addresses'],
        'account_profile'     => ['file' => 'account/profile.php',                'pretty' => 'account/profile'],
        'account_security'    => ['file' => 'account/security.php',               'pretty' => 'account/security'],
        'account_wishlist'    => ['file' => 'account/wishlist.php',               'pretty' => 'account/wishlist'],
        'account_export'      => ['file' => 'account/export.php',                 'pretty' => 'account/export'],
        'account_delete'      => ['file' => 'account/delete.php',                 'pretty' => 'account/delete'],
        'page'                => ['file' => 'storefront/pages/view.php',          'pretty' => 'page/{slug}'],
        'contact'             => ['file' => 'storefront/pages/contact.php',       'pretty' => 'contact'],
        'newsletter'          => ['file' => 'storefront/pages/newsletter.php',    'pretty' => 'newsletter'],
        'sitemap'             => ['file' => 'storefront/seo/sitemap.php',         'pretty' => 'sitemap.xml'],
        'maintenance'         => ['file' => 'storefront/errors/maintenance.php',  'pretty' => 'maintenance'],
        '404'                 => ['file' => 'storefront/errors/404.php',          'pretty' => '404'],
        '500'                 => ['file' => 'storefront/errors/500.php',          'pretty' => '500'],
    ];
    return $map;
}

/**
 * Determine if pretty URLs are active.
 */
function pretty_urls_active(): bool
{
    static $active = null;
    if ($active !== null) {
        return $active;
    }
    $settingOn = function_exists('setting') ? ((int)setting('pretty_urls', '0') === 1) : false;
    $lockFileOn = file_exists(__DIR__ . '/../storage/pretty_urls.on');
    $active = ($settingOn || $lockFileOn);
    return $active;
}

/**
 * Central route generator.
 *
 * @param string $name Route name from get_route_map()
 * @param array $params Query / placeholder parameters
 * @return string Complete URL
 */
function route(string $name, array $params = []): string
{
    $map = get_route_map();
    if (!isset($map[$name])) {
        return url($name . (!empty($params) ? '?' . http_build_query($params) : ''));
    }

    $entry = $map[$name];
    $isPretty = pretty_urls_active();

    if ($isPretty && isset($entry['pretty'])) {
        $pattern = $entry['pretty'];
        foreach ($params as $k => $v) {
            $placeholder = '{' . $k . '}';
            if (str_contains($pattern, $placeholder)) {
                $pattern = str_replace($placeholder, rawurlencode((string)$v), $pattern);
                unset($params[$k]);
            }
        }
        $url = BASE_URL . $pattern;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $url;
    }

    $file = $entry['file'];
    if ($file === '') {
        return BASE_URL . (!empty($params) ? '?' . http_build_query($params) : '');
    }

    $url = BASE_URL . $file;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}

/**
 * Check if the current request matches a route name.
 */
function route_is(string $name): bool
{
    $map = get_route_map();
    if (!isset($map[$name])) {
        return false;
    }

    $entry = $map[$name];
    $targetFile = $entry['file'];

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';

    if ($targetFile === '') {
        return ($script === BASE_URL . 'index.php' || $script === '/index.php' || (basename($script) === 'index.php' && !str_contains($script, '/shop/') && !str_contains($script, '/cart/') && !str_contains($script, '/track/') && !str_contains($script, '/account/') && !str_contains($script, '/auth/') && !str_contains($script, '/pages/') && !str_contains($script, '/errors/')));
    }

    if (str_ends_with($script, '/' . $targetFile) || (basename($script) === basename($targetFile) && str_contains($script, dirname($targetFile)))) {
        return true;
    }

    if (!empty($entry['pretty'])) {
        $cleanPattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '[^/]+', $entry['pretty']);
        $expected = rtrim(BASE_URL, '/') . '/' . ltrim($cleanPattern, '/');
        if (preg_match('#^' . preg_quote($expected, '#') . '$#', $uriPath)) {
            return true;
        }
    }

    return false;
}

/**
 * Redirect to a path or route relative to project root.
 */
function redirect(string $path, int $code = 302): void
{
    $map = get_route_map();
    if (isset($map[$path])) {
        header('Location: ' . route($path), true, $code);
        exit;
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        header('Location: ' . $path, true, $code);
        exit;
    }
    header('Location: ' . url($path), true, $code);
    exit;
}

/** Redirect to a path relative to the admin panel (ADMIN_URL). */
function admin_redirect(string $path): void
{
    header('Location: ' . admin_url($path));
    exit;
}

// ----------------------------------------------------------------
// CSRF
// ----------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    return !empty($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && !csrf_verify($_POST['_csrf'] ?? null)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

// ----------------------------------------------------------------
// Flash messages
// ----------------------------------------------------------------

/**
 * Returns the client IP address, handling direct and proxy headers.
 */
function get_client_ip(): string
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return trim($_SERVER['HTTP_CLIENT_IP']);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($list[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return trim($_SERVER['HTTP_X_REAL_IP']);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

// ----------------------------------------------------------------
// Validation
// ----------------------------------------------------------------
function input(string $key, $default = ''): string
{
    return trim((string)($_POST[$key] ?? $_GET[$key] ?? $default));
}

function old(string $key, $default = ''): string
{
    return e($_SESSION['_old'][$key] ?? $default);
}

function set_old(array $data): void
{
    $_SESSION['_old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

// ----------------------------------------------------------------
// Settings (key/value table)
// ----------------------------------------------------------------
// Settings (key/value table cached at boot with 60 min TTL)
// ----------------------------------------------------------------
function &setting_cache(): array
{
    static $cache = null;
    if ($cache === null) {
        $cached = FileCache::get('settings:all');
        if (is_array($cached)) {
            $cache = $cached;
            return $cache;
        }

        $cache = [];
        try {
            $rows = db()->query('SELECT key_name, value FROM settings')->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['key_name']] = $r['value'];
            }
            FileCache::set('settings:all', $cache, 3600); // 60 min TTL
        } catch (Throwable $e) {
            // Settings table may not exist yet during install.
        }
    }
    return $cache;
}

function setting(string $key, ?string $default = null): ?string
{
    $cache = &setting_cache();
    return $cache[$key] ?? $default;
}

function update_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (key_name, value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    $stmt->execute([':k' => $key, ':v' => $value]);
    $cache = &setting_cache();
    $cache[$key] = $value;
    FileCache::delete('settings:all');
}

/**
 * Validates and saves whitelisted settings pairs according to rules.
 */
function settings_save(array $pairs, array $rules, string $tabName = 'settings'): array
{
    $errors = [];
    $changed = [];

    foreach ($rules as $key => $rule) {
        if (!array_key_exists($key, $pairs) && ($rule['type'] ?? '') !== 'bool') {
            continue;
        }

        $raw = $pairs[$key] ?? '';
        $type = $rule['type'] ?? 'text';
        $val = null;

        switch ($type) {
            case 'bool':
                $val = (!empty($raw) && $raw !== '0') ? '1' : '0';
                break;

            case 'int':
                $num = (int)$raw;
                if (isset($rule['min']) && $num < $rule['min']) {
                    $errors[] = "Value for '{$key}' cannot be less than {$rule['min']}.";
                    continue 2;
                }
                if (isset($rule['max']) && $num > $rule['max']) {
                    $errors[] = "Value for '{$key}' cannot be greater than {$rule['max']}.";
                    continue 2;
                }
                $val = (string)$num;
                break;

            case 'decimal':
                $dec = (float)$raw;
                if (isset($rule['min']) && $dec < $rule['min']) {
                    $errors[] = "Value for '{$key}' cannot be less than {$rule['min']}.";
                    continue 2;
                }
                if (isset($rule['max']) && $dec > $rule['max']) {
                    $errors[] = "Value for '{$key}' cannot be greater than {$rule['max']}.";
                    continue 2;
                }
                $val = number_format($dec, 2, '.', '');
                break;

            case 'enum':
                $allowed = $rule['choices'] ?? [];
                if (!in_array($raw, $allowed, true)) {
                    $errors[] = "Invalid option for '{$key}'.";
                    continue 2;
                }
                $val = (string)$raw;
                break;

            case 'hex':
                $rawHex = trim((string)$raw);
                if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $rawHex)) {
                    $errors[] = "Invalid hex color format for '{$key}'. Example: #111111.";
                    continue 2;
                }
                $val = $rawHex;
                break;

            case 'email':
                $rawEmail = trim((string)$raw);
                if ($rawEmail !== '' && !filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Invalid email format for '{$key}'.";
                    continue 2;
                }
                $val = $rawEmail;
                break;

            case 'url':
                $rawUrl = trim((string)$raw);
                if ($rawUrl !== '' && !filter_var($rawUrl, FILTER_VALIDATE_URL) && !str_starts_with($rawUrl, '/')) {
                    $errors[] = "Invalid URL format for '{$key}'.";
                    continue 2;
                }
                $val = $rawUrl;
                break;

            case 'text':
            default:
                $val = trim((string)$raw);
                break;
        }

        $current = setting($key, null);
        if ($current !== $val) {
            update_setting($key, $val);
            $changed[] = $key;
        }
    }

    if ($changed) {
        log_activity('settings.update', "tab: {$tabName}; changed keys: " . implode(',', $changed));
    }

    return ['ok' => empty($errors), 'errors' => $errors, 'changed' => $changed];
}

// ----------------------------------------------------------------
// Schema verification
// ----------------------------------------------------------------
/**
 * Safe schema check: checks information_schema once per request for
 * the core upgrade tables (product_variants and email_templates).
 */
function schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME IN ('product_variants', 'email_templates')"
        );
        $stmt->execute([':db' => DB_NAME]);
        $ready = ((int)$stmt->fetchColumn() === 2);
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

// Ensure timezone from settings is applied if loaded
$configuredTz = setting('timezone', 'Asia/Dhaka');
if (!empty($configuredTz) && date_default_timezone_get() !== $configuredTz) {
    @date_default_timezone_set($configuredTz);
}


// ----------------------------------------------------------------
// Activity log
// ----------------------------------------------------------------
function log_activity(string $action, string $description = ''): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_log (user_id, action, description, ip_address)
             VALUES (:uid, :action, :desc, :ip)'
        );
        $stmt->execute([
            ':uid'    => $_SESSION['user']['id'] ?? null,
            ':action' => $action,
            ':desc'   => $description,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Silent — logging must never break the request.
    }
}

// ----------------------------------------------------------------
// Misc UI helpers
// ----------------------------------------------------------------
function active_if(bool $condition, string $class = 'active'): string
{
    return $condition ? $class : '';
}

function format_date(?string $datetime, string $format = 'M j, Y H:i'): string
{
    if (empty($datetime)) return '—';
    $ts = strtotime($datetime);
    return $ts ? date($format, $ts) : e($datetime);
}

function avatar_url(?string $avatar, ?string $name = ''): string
{
    if (!empty($avatar)) {
        $clean = ltrim($avatar, '/');
        // 1. Direct file in UPLOADS_PATH
        if (file_exists(UPLOADS_PATH . '/' . $clean)) {
            return UPLOADS_URL . $clean;
        }
        // 2. In UPLOADS_PATH/avatars/
        if (file_exists(UPLOADS_PATH . '/avatars/' . $clean)) {
            return UPLOADS_URL . 'avatars/' . $clean;
        }
        // 3. If $avatar already has 'avatars/' prefix, check without it
        if (str_starts_with($clean, 'avatars/') && file_exists(UPLOADS_PATH . '/' . substr($clean, 8))) {
            return UPLOADS_URL . substr($clean, 8);
        }
        // 4. In-repo fallbacks
        if (file_exists(ROOT_PATH . '/assets/uploads/' . $clean)) {
            return BASE_URL . 'assets/uploads/' . $clean;
        }
        if (file_exists(ROOT_PATH . '/assets/uploads/avatars/' . $clean)) {
            return BASE_URL . 'assets/uploads/avatars/' . $clean;
        }
    }
    // Fallback: use ui-avatars.com (no key required).
    $initials = urlencode($name ? $name : 'User');
    return "https://ui-avatars.com/api/?name={$initials}&background=4f46e5&color=fff&size=128";
}

// ----------------------------------------------------------------
// E-commerce helpers
// ----------------------------------------------------------------

/** Convert a string to a URL-safe slug. */
function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT//IGNORE', $text) ?: $text;
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = strtolower(trim($text, '-'));
    $text = preg_replace('~-+~', '-', $text);
    return $text ?: 'n-a';
}

/** Make a slug unique within a table by appending -1, -2, ... if needed. */
function unique_slug(string $base, string $table, int $excludeId = 0): string
{
    $slug = $base;
    $i    = 1;
    $sql  = "SELECT id FROM `$table` WHERE slug = :s AND id <> :id LIMIT 1";
    while (true) {
        $stmt = db()->prepare($sql);
        $stmt->execute([':s' => $slug, ':id' => $excludeId]);
        if (!$stmt->fetch()) return $slug;
        $slug = $base . '-' . (++$i);
    }
}

/** Format a numeric price using the configured currency symbol. */
function price(?float $amount): string
{
    $symbol = setting('currency_symbol', '$');
    if ($amount === null) return '—';
    return $symbol . number_format((float)$amount, 2);
}

/** Alias for price() */
function format_price($amount): string
{
    return price($amount !== null ? (float)$amount : null);
}

/**
 * Translate an uploaded-file error code into a human-readable message.
 * See https://www.php.net/manual/en/features.file-upload.errors.php
 */
function upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_OK:         return '';
        case UPLOAD_ERR_INI_SIZE:   return 'File is larger than the server allows (upload_max_filesize).';
        case UPLOAD_ERR_FORM_SIZE:  return 'File is larger than the form allows.';
        case UPLOAD_ERR_PARTIAL:    return 'File was only partially uploaded — please retry.';
        case UPLOAD_ERR_NO_FILE:    return 'No file was selected.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Server is missing a temporary upload folder.';
        case UPLOAD_ERR_CANT_WRITE: return 'Server could not write the file to disk.';
        case UPLOAD_ERR_EXTENSION:  return 'A PHP extension blocked the upload.';
    }
    return 'Unknown upload error.';
}

/** Return URL to product image (or a placeholder), with support for pre-generated responsive WebP variants. */
function product_image_url(?string $image, string $size = 'full', ?int $productId = null): string
{
    if (!empty($image)) {
        $clean = ltrim($image, '/');

        // Check if pre-generated responsive variant exists when a specific size is requested
        if ($size !== 'full') {
            $baseName = pathinfo($clean, PATHINFO_FILENAME);
            // 1. Check /uploads/products/{productId}/{size}_{baseName}.webp
            if ($productId !== null && file_exists(UPLOADS_PATH . '/products/' . $productId . '/' . $size . '_' . $baseName . '.webp')) {
                return UPLOADS_URL . 'products/' . $productId . '/' . $size . '_' . $baseName . '.webp';
            }
            // 2. Check /uploads/products/{size}_{baseName}.webp
            if (file_exists(UPLOADS_PATH . '/products/' . $size . '_' . $baseName . '.webp')) {
                return UPLOADS_URL . 'products/' . $size . '_' . $baseName . '.webp';
            }
        }

        // 1. In UPLOADS_PATH/products/
        if (file_exists(UPLOADS_PATH . '/products/' . $clean)) {
            return UPLOADS_URL . 'products/' . $clean;
        }
        // 2. Direct in UPLOADS_PATH
        if (file_exists(UPLOADS_PATH . '/' . $clean)) {
            return UPLOADS_URL . $clean;
        }
        // 3. Fallback in ROOT_PATH/assets/uploads/products/
        if (file_exists(ROOT_PATH . '/assets/uploads/products/' . $clean)) {
            return BASE_URL . 'assets/uploads/products/' . $clean;
        }
        // 4. Fallback in ROOT_PATH/assets/uploads/
        if (file_exists(ROOT_PATH . '/assets/uploads/' . $clean)) {
            return BASE_URL . 'assets/uploads/' . $clean;
        }
    }
    // Clean, subtle editorial placeholder SVG
    return 'data:image/svg+xml;utf8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 250" width="200" height="250">' .
        '<rect width="200" height="250" fill="#f4f4f5"/>' .
        '<path d="M70 105 L130 105 L125 160 L75 160 Z" fill="none" stroke="#d4d4d8" stroke-width="2.5" stroke-linejoin="round"/>' .
        '<path d="M85 105 C85 90 115 90 115 105" fill="none" stroke="#d4d4d8" stroke-width="2.5"/>' .
        '</svg>'
    );
}

/** Return URL to store logo (or null if none exists). */
function store_logo_url(?string $logo = null): ?string
{
    $logo = $logo ?? (function_exists('setting') ? setting('store_logo') : null);
    if (empty($logo)) return null;
    $clean = ltrim($logo, '/');
    if (file_exists(UPLOADS_PATH . '/branding/' . $clean)) {
        return UPLOADS_URL . 'branding/' . $clean;
    }
    if (file_exists(UPLOADS_PATH . '/store/' . $clean)) {
        return UPLOADS_URL . 'store/' . $clean;
    }
    if (file_exists(UPLOADS_PATH . '/' . $clean)) {
        return UPLOADS_URL . $clean;
    }
    if (file_exists(ROOT_PATH . '/assets/uploads/branding/' . $clean)) {
        return BASE_URL . 'assets/uploads/branding/' . $clean;
    }
    if (file_exists(ROOT_PATH . '/assets/uploads/store/' . $clean)) {
        return BASE_URL . 'assets/uploads/store/' . $clean;
    }
    if (file_exists(ROOT_PATH . '/assets/uploads/' . $clean)) {
        return BASE_URL . 'assets/uploads/' . $clean;
    }
    return null;
}

/** Return URL to store favicon (or null if none exists). */
function store_favicon_url(?string $fav = null): ?string
{
    $fav = $fav ?? (function_exists('setting') ? setting('store_favicon') : null);
    if (empty($fav)) return null;
    $clean = ltrim($fav, '/');
    if (file_exists(UPLOADS_PATH . '/branding/' . $clean)) {
        return UPLOADS_URL . 'branding/' . $clean;
    }
    if (file_exists(UPLOADS_PATH . '/' . $clean)) {
        return UPLOADS_URL . $clean;
    }
    if (file_exists(ROOT_PATH . '/assets/uploads/branding/' . $clean)) {
        return BASE_URL . 'assets/uploads/branding/' . $clean;
    }
    if (file_exists(ROOT_PATH . '/assets/uploads/' . $clean)) {
        return BASE_URL . 'assets/uploads/' . $clean;
    }
    return null;
}

/** Return URL to banner image (or null if none exists). */
function banner_image_url(?string $image): ?string
{
    if (empty($image)) return null;
    $clean = ltrim($image, '/');
    if (file_exists(UPLOADS_PATH . '/banners/' . $clean)) {
        return UPLOADS_URL . 'banners/' . $clean;
    }
    if (file_exists(UPLOADS_PATH . '/' . $clean)) {
        return UPLOADS_URL . $clean;
    }
    if (file_exists(ROOT_PATH . '/assets/uploads/banners/' . $clean)) {
        return BASE_URL . 'assets/uploads/banners/' . $clean;
    }
    if (file_exists(ROOT_PATH . '/assets/uploads/' . $clean)) {
        return BASE_URL . 'assets/uploads/' . $clean;
    }
    return null;
}

/** Return URL to category image (or null if none exists). */
function category_image_url(?string $image): ?string
{
    if (empty($image)) return null;
    $clean = ltrim($image, '/');
    if (file_exists(UPLOADS_PATH . '/categories/' . $clean)) {
        return UPLOADS_URL . 'categories/' . $clean;
    }
    if (file_exists(UPLOADS_PATH . '/' . $clean)) {
        return UPLOADS_URL . $clean;
    }
    if (file_exists(ROOT_PATH . '/assets/uploads/categories/' . $clean)) {
        return BASE_URL . 'assets/uploads/categories/' . $clean;
    }
    if (file_exists(ROOT_PATH . '/assets/uploads/' . $clean)) {
        return BASE_URL . 'assets/uploads/' . $clean;
    }
    return null;
}

/**
 * Render an inline SVG icon referencing the central SVG sprite.
 */
function icon(string $name, string $class = '', string $title = ''): string
{
    $safeName = preg_replace('/[^a-z0-9_-]/i', '', $name);
    $cls = trim('i ' . $class);
    $spriteUrl = url('assets/img/icons.svg');
    
    $titleHtml = '';
    $aria = 'aria-hidden="true"';
    if ($title !== '') {
        $aria = 'role="img" aria-label="' . e($title) . '"';
        $titleHtml = '<title>' . e($title) . '</title>';
    }

    return '<svg class="' . e($cls) . '" ' . $aria . '>' . $titleHtml . '<use href="' . e($spriteUrl) . '#' . $safeName . '"></use></svg>';
}

/**
 * Calculate pagination window of pages to display (default up to 15 pages).
 * Returns array of elements: integers for page numbers, or string '...' for ellipsis.
 */
function pagination_window(int $currentPage, int $totalPages, int $maxOptions = 15): array
{
    if ($totalPages <= 1) {
        return [];
    }

    if ($totalPages <= $maxOptions) {
        return range(1, $totalPages);
    }

    $half = (int)floor($maxOptions / 2);
    $start = max(1, $currentPage - $half);
    $end = min($totalPages, $start + $maxOptions - 1);

    if ($end - $start + 1 < $maxOptions) {
        $start = max(1, $end - $maxOptions + 1);
    }

    $pages = [];
    if ($start > 1) {
        $pages[] = 1;
        if ($start > 2) {
            $pages[] = '...';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        if (!in_array($i, $pages, true)) {
            $pages[] = $i;
        }
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $pages[] = '...';
        }
        $pages[] = $totalPages;
    }

    return $pages;
}

/**
 * Render Bootstrap pagination HTML with 1-15 windowed page options.
 */
function render_admin_pagination(int $currentPage, int $totalPages, array $queryParams = [], int $maxOptions = 15, string $pageParam = 'page'): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $window = pagination_window($currentPage, $totalPages, $maxOptions);
    $html = '<nav aria-label="Table pagination"><ul class="pagination pagination-sm justify-content-center mb-0">';

    // First & Prev buttons
    if ($currentPage > 1) {
        $firstUrl = '?' . http_build_query(array_merge($queryParams, [$pageParam => 1]));
        $html .= '<li class="page-item"><a class="page-link" href="' . e($firstUrl) . '" title="First Page">&laquo;&laquo;</a></li>';
        $prevUrl = '?' . http_build_query(array_merge($queryParams, [$pageParam => $currentPage - 1]));
        $html .= '<li class="page-item"><a class="page-link" href="' . e($prevUrl) . '">&laquo; Prev</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">&laquo;&laquo;</span></li>';
        $html .= '<li class="page-item disabled"><span class="page-link">&laquo; Prev</span></li>';
    }

    foreach ($window as $item) {
        if ($item === '...') {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        } elseif ((int)$item === $currentPage) {
            $html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . (int)$item . '</span></li>';
        } else {
            $url = '?' . http_build_query(array_merge($queryParams, [$pageParam => (int)$item]));
            $html .= '<li class="page-item"><a class="page-link" href="' . e($url) . '">' . (int)$item . '</a></li>';
        }
    }

    // Next & Last buttons
    if ($currentPage < $totalPages) {
        $nextUrl = '?' . http_build_query(array_merge($queryParams, [$pageParam => $currentPage + 1]));
        $html .= '<li class="page-item"><a class="page-link" href="' . e($nextUrl) . '">Next &raquo;</a></li>';
        $lastUrl = '?' . http_build_query(array_merge($queryParams, [$pageParam => $totalPages]));
        $html .= '<li class="page-item"><a class="page-link" href="' . e($lastUrl) . '" title="Last Page">&raquo;&raquo;</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>';
        $html .= '<li class="page-item disabled"><span class="page-link">&raquo;&raquo;</span></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

/**
 * Render storefront editorial pagination HTML with 1-15 windowed page options.
 */
function render_editorial_pagination(int $currentPage, int $totalPages, array $queryParams = [], int $maxOptions = 15, string $pageParam = 'page'): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $window = pagination_window($currentPage, $totalPages, $maxOptions);
    $html = '<nav aria-label="Page navigation"><ul class="editorial-pagination">';

    // Previous Button
    if ($currentPage > 1) {
        $prevUrl = '?' . http_build_query(array_merge($queryParams, [$pageParam => $currentPage - 1]));
        $html .= '<li><a href="' . e($prevUrl) . '" aria-label="Previous page">' . (function_exists('icon') ? icon('chevron-left') : '&lsaquo;') . '</a></li>';
    }

    foreach ($window as $item) {
        if ($item === '...') {
            $html .= '<li class="pagination-ellipsis"><span class="border-0">&hellip;</span></li>';
        } elseif ((int)$item === $currentPage) {
            $html .= '<li class="active"><span aria-current="page">' . (int)$item . '</span></li>';
        } else {
            $url = '?' . http_build_query(array_merge($queryParams, [$pageParam => (int)$item]));
            $html .= '<li><a href="' . e($url) . '">' . (int)$item . '</a></li>';
        }
    }

    // Next Button
    if ($currentPage < $totalPages) {
        $nextUrl = '?' . http_build_query(array_merge($queryParams, [$pageParam => $currentPage + 1]));
        $html .= '<li><a href="' . e($nextUrl) . '" aria-label="Next page">' . (function_exists('icon') ? icon('chevron-right') : '&rsaquo;') . '</a></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

