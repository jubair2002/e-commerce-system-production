<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');

$pageTitle = 'Settings & CMS';

$activeTab = trim((string)($_GET['tab'] ?? 'general'));
$allowedTabs = ['general', 'storefront', 'inventory', 'checkout', 'shipping', 'coupons', 'seo', 'customers'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'general';
}

if (!function_exists('settings_save')) {
    /**
     * Robust settings_save helper:
     * Validates by type: bool, int, decimal, enum, hex, email, url, text.
     * Mass-assignment protection: whitelists keys defined in $rules.
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
}

// -----------------------------------------------------------------
// Handle POST Actions
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // Tab 1: General Settings
    if ($action === 'save_general') {
        $rules = [
            'site_name'       => ['type' => 'text'],
            'store_tagline'   => ['type' => 'text'],
            'site_email'      => ['type' => 'email'],
            'store_phone'     => ['type' => 'text'],
            'store_address'   => ['type' => 'text'],
            'timezone'        => ['type' => 'text'],
            'currency_code'   => ['type' => 'text'],
            'currency_symbol' => ['type' => 'text'],
            'items_per_page'  => ['type' => 'int', 'min' => 5, 'max' => 100],
        ];

        $res = settings_save($_POST, $rules, 'general');

        // Handle Branding Uploads (Logo & Favicon)
        $brandingDir = UPLOADS_PATH . '/branding';
        if (!is_dir($brandingDir)) {
            @mkdir($brandingDir, 0755, true);
        }

        $allowedMimes = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/webp'];

        foreach (['store_logo', 'store_favicon'] as $fileKey) {
            if (!empty($_FILES[$fileKey]['tmp_name']) && is_uploaded_file($_FILES[$fileKey]['tmp_name'])) {
                $f = $_FILES[$fileKey];
                if ($f['size'] > 2 * 1024 * 1024) {
                    $res['errors'][] = ucfirst(str_replace('_', ' ', $fileKey)) . " exceeds maximum size of 2 MB.";
                    continue;
                }

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $f['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mime, $allowedMimes, true)) {
                    $res['errors'][] = "Invalid file type for " . str_replace('_', ' ', $fileKey) . " ({$mime}).";
                    continue;
                }

                $ext = pathinfo($f['name'], PATHINFO_EXTENSION) ?: 'png';
                $newFilename = $fileKey . '_' . bin2hex(random_bytes(6)) . '.' . strtolower($ext);

                if (move_uploaded_file($f['tmp_name'], $brandingDir . '/' . $newFilename)) {
                    // Delete old file if present
                    $oldFile = setting($fileKey);
                    if (!empty($oldFile) && file_exists($brandingDir . '/' . $oldFile)) {
                        @unlink($brandingDir . '/' . $oldFile);
                    }
                    update_setting($fileKey, $newFilename);
                    log_activity('settings.update', "Uploaded new {$fileKey}: {$newFilename}");
                }
            }
        }

        if ($res['errors']) {
            foreach ($res['errors'] as $e) flash('danger', $e);
        } else {
            flash('success', 'General settings saved.');
        }
        admin_redirect('settings.php?tab=general');
    }

    // Direct Maintenance Mode Quick Toggle
    if ($action === 'toggle_maintenance') {
        $newVal = (!empty($_POST['maintenance_mode']) && $_POST['maintenance_mode'] !== '0') ? '1' : '0';
        update_setting('maintenance_mode', $newVal);
        log_activity('settings.maintenance', "Maintenance mode set to {$newVal}");
        flash('success', $newVal === '1' ? 'Maintenance mode enabled. Storefront is now under scheduled maintenance.' : 'Maintenance mode disabled. Storefront is now live.');
        $redirectTo = $_POST['redirect_to'] ?? admin_url('settings.php?tab=storefront');
        header("Location: " . $redirectTo);
        exit;
    }

    // Tab 2: Storefront CMS Settings
    // Clear Image Cache Action
    if ($action === 'clear_image_cache') {
        require_once __DIR__ . '/../includes/images.php';
        $purgedCount = image_pipeline_clear_cache();
        log_activity('image_cache.clear', "Purged {$purgedCount} cached image variants");
        flash('success', "Image cache purged ({$purgedCount} files removed).");
        admin_redirect('settings.php?tab=storefront');
    }

    if ($action === 'save_storefront') {
        $rules = [
            'home_show_hero'           => ['type' => 'bool'],
            'home_show_categories'     => ['type' => 'bool'],
            'home_show_featured'       => ['type' => 'bool'],
            'home_show_new_arrivals'   => ['type' => 'bool'],
            'home_show_best_sellers'   => ['type' => 'bool'],
            'home_show_reviews'        => ['type' => 'bool'],
            'home_show_brand_story'    => ['type' => 'bool'],
            'home_show_newsletter'     => ['type' => 'bool'],
            'home_featured_count'      => ['type' => 'int', 'min' => 1, 'max' => 40],
            'shop_products_per_page'   => ['type' => 'int', 'min' => 4, 'max' => 60],
            'shop_default_sort'        => ['type' => 'enum', 'choices' => ['newest', 'price_asc', 'price_desc', 'name_asc', 'popular']],
            'shop_show_filters'        => ['type' => 'bool'],
            'shop_show_sale_badge'     => ['type' => 'bool'],
            'shop_show_low_stock_badge' => ['type' => 'bool'],
            'announcement_bar_enabled' => ['type' => 'bool'],
            'announcement_bar_text'    => ['type' => 'text'],
            'footer_text'              => ['type' => 'text'],
            'social_facebook'          => ['type' => 'url'],
            'social_instagram'         => ['type' => 'url'],
            'social_whatsapp'          => ['type' => 'text'],
            'theme_accent_color'       => ['type' => 'hex'],
            'maintenance_mode'         => ['type' => 'bool'],
            'pretty_urls'              => ['type' => 'bool'],
            'dark_mode_enabled'        => ['type' => 'bool'],
            'mobile_bottom_nav'        => ['type' => 'bool'],
            'sticky_add_to_cart'       => ['type' => 'bool'],
            'image_pipeline_enabled'   => ['type' => 'bool'],
            'brand_story_title'        => ['type' => 'text'],
            'brand_story_text'         => ['type' => 'text'],
            'trust_item_1_title'       => ['type' => 'text'],
            'trust_item_1_desc'        => ['type' => 'text'],
            'trust_item_2_title'       => ['type' => 'text'],
            'trust_item_2_desc'        => ['type' => 'text'],
            'trust_item_3_title'       => ['type' => 'text'],
            'trust_item_3_desc'        => ['type' => 'text'],
            'trust_item_4_title'       => ['type' => 'text'],
            'trust_item_4_desc'        => ['type' => 'text'],
            'reviews_enabled'          => ['type' => 'bool'],
            'reviews_require_login'    => ['type' => 'bool'],
            'reviews_auto_approve'     => ['type' => 'bool'],
            'newsletter_enabled'       => ['type' => 'bool'],
            'newsletter_double_optin'  => ['type' => 'bool'],
            'size_guide_page_slug'     => ['type' => 'text'],
        ];

        $res = settings_save($_POST, $rules, 'storefront');
        if (isset($_POST['theme_accent_color']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $_POST['theme_accent_color'])) {
            update_setting('theme_accent', $_POST['theme_accent_color']);
        }

        // Pretty URLs lockfile toggle
        $lockFile = __DIR__ . '/../storage/pretty_urls.on';
        if (!empty($_POST['pretty_urls'])) {
            @file_put_contents($lockFile, date('c'));
        } else {
            if (file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }

        if ($res['errors']) {
            foreach ($res['errors'] as $e) flash('danger', $e);
        } else {
            flash('success', 'Storefront settings saved.');
        }
        admin_redirect('settings.php?tab=storefront');
    }

    // Tab 3: Inventory & Stock Display Settings
    if ($action === 'save_inventory') {
        $rules = [
            'stock_display_mode'     => ['type' => 'enum', 'choices' => ['always', 'count_when_low', 'in_out_only', 'hidden']],
            'low_stock_threshold'    => ['type' => 'int', 'min' => 1, 'max' => 100],
            'out_of_stock_behavior'  => ['type' => 'enum', 'choices' => ['show_disabled', 'hide']],
            'realtime_poll_seconds'  => ['type' => 'int', 'min' => 5, 'max' => 60],
            'realtime_mode'          => ['type' => 'enum', 'choices' => ['polling', 'sse']],
        ];

        $res = settings_save($_POST, $rules, 'inventory');
        if ($res['errors']) {
            foreach ($res['errors'] as $e) flash('danger', $e);
        } else {
            flash('success', 'Inventory display settings saved.');
        }
        admin_redirect('settings.php?tab=inventory');
    }

    // Tab 4: Checkout & Payments Settings
    if ($action === 'save_checkout') {
        $rules = [
            'guest_checkout'             => ['type' => 'bool'],
            'require_phone'              => ['type' => 'bool'],
            'require_email'              => ['type' => 'bool'],
            'cod_enabled'                => ['type' => 'bool'],
            'cod_fee'                    => ['type' => 'decimal', 'min' => 0],
            'cod_min_order'              => ['type' => 'decimal', 'min' => 0],
            'cod_max_order'              => ['type' => 'decimal', 'min' => 0],
            'bank_transfer_enabled'      => ['type' => 'bool'],
            'bank_transfer_instructions' => ['type' => 'text'],
            'order_number_prefix'        => ['type' => 'text'],
            'antispam_orders_limit'      => ['type' => 'int', 'min' => 1, 'max' => 50],
        ];

        $res = settings_save($_POST, $rules, 'checkout');
        if ($res['errors']) {
            foreach ($res['errors'] as $e) flash('danger', $e);
        } else {
            flash('success', 'Checkout & payment settings saved.');
        }
        admin_redirect('settings.php?tab=checkout');
    }

    // Tab 5: Shipping Method CRUD
    if ($action === 'add_shipping') {
        $name = trim((string)($_POST['name'] ?? ''));
        $zone = trim((string)($_POST['zone'] ?? 'Everywhere'));
        $cost = max(0, (float)($_POST['cost'] ?? 0));
        $freeOver = !empty($_POST['free_over']) ? max(0, (float)$_POST['free_over']) : null;
        $minDays = max(1, (int)($_POST['min_days'] ?? 1));
        $maxDays = max(1, (int)($_POST['max_days'] ?? 3));
        $codAllowed = !empty($_POST['cod_allowed']) ? 1 : 0;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

        if ($name === '') {
            flash('danger', 'Shipping method name is required.');
        } else {
            db()->prepare(
                "INSERT INTO shipping_methods (name, zone, cost, free_over, min_days, max_days, cod_allowed, status)
                 VALUES (:n, :z, :c, :fo, :min, :max, :cod, :st)"
            )->execute([
                ':n'   => $name,
                ':z'   => $zone,
                ':c'   => $cost,
                ':fo'  => $freeOver,
                ':min' => $minDays,
                ':max' => $maxDays,
                ':cod' => $codAllowed,
                ':st'  => $status,
            ]);
            log_activity('shipping.add', "Created shipping method: {$name}");
            flash('success', "Shipping method \"{$name}\" created.");
        }
        admin_redirect('settings.php?tab=shipping');
    }

    if ($action === 'update_shipping') {
        $id = (int)($_POST['shipping_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $zone = trim((string)($_POST['zone'] ?? ''));
        $cost = max(0, (float)($_POST['cost'] ?? 0));
        $freeOver = (!empty($_POST['free_over']) && (float)$_POST['free_over'] > 0) ? (float)$_POST['free_over'] : null;
        $minDays = max(1, (int)($_POST['min_days'] ?? 1));
        $maxDays = max(1, (int)($_POST['max_days'] ?? 3));
        $codAllowed = !empty($_POST['cod_allowed']) ? 1 : 0;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

        if ($id > 0 && $name !== '') {
            db()->prepare(
                "UPDATE shipping_methods
                 SET name = :n, zone = :z, cost = :c, free_over = :fo,
                     min_days = :min, max_days = :max, cod_allowed = :cod, status = :st
                 WHERE id = :id"
            )->execute([
                ':n'   => $name,
                ':z'   => $zone,
                ':c'   => $cost,
                ':fo'  => $freeOver,
                ':min' => $minDays,
                ':max' => $maxDays,
                ':cod' => $codAllowed,
                ':st'  => $status,
                ':id'  => $id,
            ]);
            flash('success', "Updated shipping method \"{$name}\".");
        }
        admin_redirect('settings.php?tab=shipping');
    }

    if ($action === 'delete_shipping') {
        $id = (int)($_POST['shipping_id'] ?? 0);
        if ($id > 0) {
            db()->prepare("DELETE FROM shipping_methods WHERE id = :id")->execute([':id' => $id]);
            log_activity('shipping.delete', "Deleted shipping method #{$id}");
            flash('success', 'Shipping method removed.');
        }
        admin_redirect('settings.php?tab=shipping');
    }

    // Tab 6: Coupons CRUD
    if ($action === 'add_coupon') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $type = in_array($_POST['type'] ?? '', ['percent', 'fixed'], true) ? $_POST['type'] : 'percent';
        $val = max(0.01, (float)($_POST['value'] ?? 0));
        $minSub = max(0, (float)($_POST['min_subtotal'] ?? 0));
        $maxDisc = (!empty($_POST['max_discount']) && (float)$_POST['max_discount'] > 0) ? (float)$_POST['max_discount'] : null;
        $limit = (!empty($_POST['usage_limit']) && (int)$_POST['usage_limit'] > 0) ? (int)$_POST['usage_limit'] : null;
        $startsAt = !empty($_POST['starts_at']) ? $_POST['starts_at'] : null;
        $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

        if ($code === '') {
            flash('danger', 'Coupon code is required.');
        } else {
            try {
                db()->prepare(
                    "INSERT INTO coupons (code, type, value, min_subtotal, max_discount, usage_limit, starts_at, expires_at, status)
                     VALUES (:c, :t, :v, :min, :max, :lim, :st, :ex, :stat)"
                )->execute([
                    ':c'    => $code,
                    ':t'    => $type,
                    ':v'    => $val,
                    ':min'  => $minSub,
                    ':max'  => $maxDisc,
                    ':lim'  => $limit,
                    ':st'   => $startsAt,
                    ':ex'   => $expiresAt,
                    ':stat' => $status,
                ]);
                log_activity('coupon.add', "Created coupon: {$code}");
                flash('success', "Coupon \"{$code}\" created.");
            } catch (Throwable $e) {
                flash('danger', 'Error adding coupon (code may already exist).');
            }
        }
        admin_redirect('settings.php?tab=coupons');
    }

    if ($action === 'delete_coupon') {
        $id = (int)($_POST['coupon_id'] ?? 0);
        if ($id > 0) {
            db()->prepare("DELETE FROM coupons WHERE id = :id")->execute([':id' => $id]);
            log_activity('coupon.delete', "Deleted coupon #{$id}");
            flash('success', 'Coupon deleted.');
        }
        admin_redirect('settings.php?tab=coupons');
    }

    // Tab 7: SEO Settings
    if ($action === 'save_seo') {
        $rules = [
            'seo_default_title'       => ['type' => 'text'],
            'seo_default_description' => ['type' => 'text'],
            'robots_allow'            => ['type' => 'bool'],
            'monitor_external_url'    => ['type' => 'text'],
            'monitor_external_embed'  => ['type' => 'bool'],
        ];

        $res = settings_save($_POST, $rules, 'seo');
        if ($res['errors']) {
            foreach ($res['errors'] as $e) flash('danger', $e);
        } else {
            flash('success', 'SEO & System settings saved.');
        }
        admin_redirect('settings.php?tab=seo');
    }

    // Tab 8: Customers Settings (Prompt 14)
    if ($action === 'save_customers') {
        $rules = [
            'customer_registration_enabled'         => ['type' => 'bool'],
            'customer_require_email_verification'    => ['type' => 'bool'],
            'customer_block_login_unverified'       => ['type' => 'bool'],
            'customer_password_min_length'          => ['type' => 'int', 'min' => 6, 'max' => 64],
            'customer_login_max_attempts'           => ['type' => 'int', 'min' => 3, 'max' => 20],
            'customer_lockout_minutes'              => ['type' => 'int', 'min' => 1, 'max' => 1440],
            'customer_remember_me_days'             => ['type' => 'int', 'min' => 1, 'max' => 365],
            'customer_password_reset_minutes'       => ['type' => 'int', 'min' => 5, 'max' => 1440],
            'customer_verify_token_hours'           => ['type' => 'int', 'min' => 1, 'max' => 168],
            'customer_session_idle_minutes'         => ['type' => 'int', 'min' => 0, 'max' => 10080],
            'customer_claim_guest_orders_on_verify' => ['type' => 'bool'],
            'customer_allow_account_delete'         => ['type' => 'bool'],
            'customer_wishlist_enabled'             => ['type' => 'bool'],
            'customer_marketing_checkbox_default'   => ['type' => 'bool'],
            'customer_terms_url'                    => ['type' => 'url'],
            'customer_can_cancel'                   => ['type' => 'bool'],
            'customer_unverified_ttl_days'          => ['type' => 'int', 'min' => 0, 'max' => 365],
        ];

        $res = settings_save($_POST, $rules, 'customers');
        if ($res['errors']) {
            foreach ($res['errors'] as $e) flash('danger', $e);
        } else {
            flash('success', 'Customer account settings saved.');
        }
        admin_redirect('settings.php?tab=customers');
    }

    // Reset Tab to Defaults
    if ($action === 'reset_tab') {
        $tabToReset = trim((string)($_POST['tab_name'] ?? ''));
        $defaults = [
            'general' => [
                'site_name' => 'Store',
                'store_tagline' => 'Quality products, delivered to your door',
                'timezone' => 'Asia/Dhaka',
                'currency_code' => 'BDT',
                'currency_symbol' => '৳',
                'items_per_page' => '10',
            ],
            'storefront' => [
                'home_show_hero' => '1',
                'home_show_categories' => '1',
                'home_show_featured' => '1',
                'home_show_new_arrivals' => '1',
                'home_show_best_sellers' => '1',
                'home_featured_count' => '8',
                'shop_products_per_page' => '12',
                'shop_default_sort' => 'newest',
                'shop_show_filters' => '1',
                'shop_show_sale_badge' => '1',
                'shop_show_low_stock_badge' => '1',
                'announcement_bar_enabled' => '0',
                'announcement_bar_text' => '',
                'theme_accent_color' => '#111111',
                'theme_accent' => '#111111',
                'maintenance_mode' => '0',
            ],
            'inventory' => [
                'stock_display_mode' => 'count_when_low',
                'low_stock_threshold' => '5',
                'out_of_stock_behavior' => 'show_disabled',
                'realtime_poll_seconds' => '10',
                'realtime_mode' => 'polling',
            ],
            'checkout' => [
                'guest_checkout' => '1',
                'require_phone' => '1',
                'require_email' => '0',
                'cod_enabled' => '1',
                'cod_fee' => '0.00',
                'cod_min_order' => '0',
                'cod_max_order' => '0',
                'bank_transfer_enabled' => '0',
                'order_number_prefix' => 'ORD',
                'antispam_orders_limit' => '5',
            ],
            'seo' => [
                'seo_default_title' => '',
                'seo_default_description' => '',
                'robots_allow' => '1',
            ],
            'customers' => [
                'customer_registration_enabled' => '1',
                'customer_require_email_verification' => '1',
                'customer_block_login_unverified' => '0',
                'customer_password_min_length' => '8',
                'customer_login_max_attempts' => '5',
                'customer_lockout_minutes' => '15',
                'customer_remember_me_days' => '30',
                'customer_password_reset_minutes' => '60',
                'customer_verify_token_hours' => '24',
                'customer_session_idle_minutes' => '120',
                'customer_claim_guest_orders_on_verify' => '1',
                'customer_allow_account_delete' => '1',
                'customer_wishlist_enabled' => '1',
                'customer_marketing_checkbox_default' => '1',
                'customer_terms_url' => '',
                'customer_can_cancel' => '1',
                'customer_unverified_ttl_days' => '14',
            ],
        ];

        if (isset($defaults[$tabToReset])) {
            foreach ($defaults[$tabToReset] as $dk => $dv) {
                update_setting($dk, $dv);
            }
            log_activity('settings.reset', "Reset tab '{$tabToReset}' to defaults");
            flash('info', "Reset {$tabToReset} tab settings to defaults.");
        }
        admin_redirect('settings.php?tab=' . $tabToReset);
    }
}

// Fetch Shipping Methods
$shippingMethods = [];
try {
    $shippingMethods = db()->query("SELECT * FROM shipping_methods ORDER BY position ASC, id ASC")->fetchAll();
} catch (Throwable $e) {}

// Fetch Coupons
$couponsList = [];
try {
    $couponsList = db()->query("SELECT * FROM coupons ORDER BY id DESC")->fetchAll();
} catch (Throwable $e) {}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="mb-1">Store Settings & CMS</h3>
    <p class="text-muted small mb-0">Configure your storefront, branding, inventory display, checkout rules, shipping, and SEO.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= e(admin_url('mail_settings.php')) ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-envelope me-1"></i> Mail Config
    </a>
    <a href="<?= e(admin_url('banners.php')) ?>" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-images me-1"></i> Storefront Banners
    </a>
  </div>
</div>

<!-- Nav Tabs -->
<ul class="nav nav-tabs mb-4" id="settingsTab" role="tablist">
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'general' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url('settings.php?tab=general')) ?>">
      <i class="bi bi-sliders me-1"></i> General
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'storefront' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url('settings.php?tab=storefront')) ?>">
      <i class="bi bi-shop me-1"></i> Storefront
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'inventory' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url('settings.php?tab=inventory')) ?>">
      <i class="bi bi-boxes me-1"></i> Inventory & Stock
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'checkout' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url('settings.php?tab=checkout')) ?>">
      <i class="bi bi-credit-card me-1"></i> Checkout & COD
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'shipping' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url('settings.php?tab=shipping')) ?>">
      <i class="bi bi-truck me-1"></i> Shipping Methods (<?= count($shippingMethods) ?>)
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'coupons' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url('settings.php?tab=coupons')) ?>">
      <i class="bi bi-ticket-perforated me-1"></i> Coupons (<?= count($couponsList) ?>)
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'seo' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url('settings.php?tab=seo')) ?>">
      <i class="bi bi-search me-1"></i> SEO & Sitemap
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'customers' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url('settings.php?tab=customers')) ?>">
      <i class="bi bi-people me-1"></i> Customers
    </a>
  </li>
</ul>

<div class="tab-content">
  <!-- TAB 1: GENERAL -->
  <?php if ($activeTab === 'general'): ?>
    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <h5 class="fw-bold mb-0">General Store Configuration</h5>
          <div class="d-flex align-items-center gap-2">
            <button type="submit" form="generalForm" class="btn btn-primary btn-sm px-3">
              <i class="bi bi-save me-1"></i> Save Settings
            </button>
            <form method="post" onsubmit="return confirm('Reset general settings to defaults?');" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_tab">
              <input type="hidden" name="tab_name" value="general">
              <button class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
            </form>
          </div>
        </div>

        <form method="post" enctype="multipart/form-data" id="generalForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_general">

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Store / Site Name</label>
              <input type="text" name="site_name" class="form-control" value="<?= e(setting('site_name', APP_NAME)) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Store Tagline</label>
              <input type="text" name="store_tagline" class="form-control" value="<?= e(setting('store_tagline', 'Quality products, delivered to your door')) ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Contact Email</label>
              <input type="email" name="site_email" class="form-control" value="<?= e(setting('site_email', '')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Support Phone</label>
              <input type="text" name="store_phone" class="form-control" value="<?= e(setting('store_phone', '')) ?>" placeholder="+880 1700 000000">
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold">Physical Address</label>
              <textarea name="store_address" class="form-control" rows="2"><?= e(setting('store_address', '')) ?></textarea>
            </div>

            <!-- Branding Uploads -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Store Logo</label>
              <input type="file" name="store_logo" class="form-control" accept="image/png,image/jpeg,image/svg+xml,image/webp">
              <div class="form-text">Max 2MB (PNG, JPG, SVG, WebP).</div>
              <?php $logo = setting('store_logo'); ?>
              <?php if ($logo && (file_exists(UPLOADS_PATH . '/branding/' . $logo) || file_exists(UPLOADS_PATH . '/store/' . $logo))): ?>
                <div class="mt-2 p-2 bg-light rounded border d-inline-block">
                  <img src="<?= e(file_exists(UPLOADS_PATH . '/branding/' . $logo) ? UPLOADS_URL . 'branding/' . $logo : UPLOADS_URL . 'store/' . $logo) ?>" alt="Logo" style="max-height: 40px;">
                  <span class="text-muted small ms-2"><?= e($logo) ?></span>
                </div>
              <?php endif; ?>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Favicon</label>
              <input type="file" name="store_favicon" class="form-control" accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/svg+xml">
              <div class="form-text">Max 1MB (ICO, PNG, SVG).</div>
              <?php $fav = setting('store_favicon'); ?>
              <?php if ($fav && file_exists(UPLOADS_PATH . '/branding/' . $fav)): ?>
                <div class="mt-2 p-2 bg-light rounded border d-inline-block">
                  <img src="<?= e(UPLOADS_URL . 'branding/' . $fav) ?>" alt="Favicon" style="max-height: 24px;">
                  <span class="text-muted small ms-2"><?= e($fav) ?></span>
                </div>
              <?php endif; ?>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Timezone</label>
              <select name="timezone" class="form-select">
                <?php $currentTz = setting('timezone', 'Asia/Dhaka'); ?>
                <?php foreach (['Asia/Dhaka', 'UTC', 'Asia/Kolkata', 'America/New_York', 'Europe/London'] as $tz): ?>
                  <option value="<?= $tz ?>" <?= $currentTz === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Currency Code</label>
              <input type="text" name="currency_code" class="form-control" value="<?= e(setting('currency_code', 'BDT')) ?>" maxlength="5">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Currency Symbol</label>
              <input type="text" name="currency_symbol" class="form-control" value="<?= e(setting('currency_symbol', '৳')) ?>" maxlength="5">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Items per page (Admin lists)</label>
              <input type="number" name="items_per_page" class="form-control" min="5" max="100" value="<?= e(setting('items_per_page', '10')) ?>">
            </div>
          </div>

          <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-save me-1"></i> Save General Settings
          </button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <!-- TAB 2: STOREFRONT CMS -->
  <?php if ($activeTab === 'storefront'): ?>
    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <h5 class="fw-bold mb-0">Storefront Design & CMS Options</h5>
          <div class="d-flex align-items-center gap-2">
            <button type="submit" form="storefrontForm" class="btn btn-primary btn-sm px-3">
              <i class="bi bi-save me-1"></i> Save Settings
            </button>
            <form method="post" onsubmit="return confirm('Reset storefront settings to defaults?');" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_tab">
              <input type="hidden" name="tab_name" value="storefront">
              <button class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
            </form>
          </div>
        </div>

        <form method="post" id="storefrontForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_storefront">

          <h6 class="fw-bold border-bottom pb-2 mb-3">Homepage Sections</h6>
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="home_show_hero" value="1" <?= setting('home_show_hero', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Hero / Main Banner</label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="home_show_categories" value="1" <?= setting('home_show_categories', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Featured Categories</label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="home_show_featured" value="1" <?= setting('home_show_featured', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Featured Products Grid</label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="home_show_new_arrivals" value="1" <?= setting('home_show_new_arrivals', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">New Arrivals Section</label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="home_show_best_sellers" value="1" <?= setting('home_show_best_sellers', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Best Sellers Section</label>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Featured Products Count</label>
              <input type="number" name="home_featured_count" class="form-control form-control-sm" min="1" max="40" value="<?= e(setting('home_featured_count', '8')) ?>">
            </div>
          </div>

          <h6 class="fw-bold border-bottom pb-2 mb-3">Shop Catalog & Badges</h6>
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Products per Page</label>
              <input type="number" name="shop_products_per_page" class="form-control" min="4" max="60" value="<?= e(setting('shop_products_per_page', '12')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Default Catalog Sort</label>
              <?php $defSort = setting('shop_default_sort', 'newest'); ?>
              <select name="shop_default_sort" class="form-select">
                <option value="newest" <?= $defSort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                <option value="popular" <?= $defSort === 'popular' ? 'selected' : '' ?>>Most Popular / Sold</option>
                <option value="price_asc" <?= $defSort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
                <option value="price_desc" <?= $defSort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                <option value="name_asc" <?= $defSort === 'name_asc' ? 'selected' : '' ?>>Name: A to Z</option>
              </select>
            </div>
            <div class="col-md-4 d-flex flex-column justify-content-center">
              <div class="form-check form-switch mb-1">
                <input class="form-check-input" type="checkbox" name="shop_show_filters" value="1" <?= setting('shop_show_filters', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label small fw-medium">Show Filters Sidebar</label>
              </div>
              <div class="form-check form-switch mb-1">
                <input class="form-check-input" type="checkbox" name="shop_show_sale_badge" value="1" <?= setting('shop_show_sale_badge', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label small fw-medium">Show Sale Badges</label>
              </div>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="shop_show_low_stock_badge" value="1" <?= setting('shop_show_low_stock_badge', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label small fw-medium">Show Low Stock Badges</label>
              </div>
            </div>
          </div>

          <h6 class="fw-bold border-bottom pb-2 mb-3">Theme & Announcement</h6>
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Theme Accent Color</label>
              <?php $accent = setting('theme_accent_color', setting('theme_accent', '#111111')); ?>
              <div class="input-group">
                <input type="color" class="form-control form-control-color" id="accentColorPicker" value="<?= e($accent) ?>" oninput="document.getElementById('accentText').value = this.value; document.getElementById('swatchPreview').style.backgroundColor = this.value;">
                <input type="text" name="theme_accent_color" id="accentText" class="form-control" value="<?= e($accent) ?>" maxlength="7" oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value)) { document.getElementById('accentColorPicker').value = this.value; document.getElementById('swatchPreview').style.backgroundColor = this.value; }">
              </div>
              <div class="mt-2 d-flex align-items-center gap-2">
                <span class="small text-muted">Live Preview:</span>
                <span id="swatchPreview" class="d-inline-block rounded border" style="width: 28px; height: 28px; background-color: <?= e($accent) ?>;"></span>
                <button type="button" class="btn btn-link btn-sm p-0" onclick="document.getElementById('accentColorPicker').value='#111111'; document.getElementById('accentText').value='#111111'; document.getElementById('swatchPreview').style.backgroundColor='#111111';">Reset Black</button>
                <button type="button" class="btn btn-link btn-sm p-0" onclick="document.getElementById('accentColorPicker').value='#2563eb'; document.getElementById('accentText').value='#2563eb'; document.getElementById('swatchPreview').style.backgroundColor='#2563eb';">Blue</button>
                <button type="button" class="btn btn-link btn-sm p-0" onclick="document.getElementById('accentColorPicker').value='#16a34a'; document.getElementById('accentText').value='#16a34a'; document.getElementById('swatchPreview').style.backgroundColor='#16a34a';">Green</button>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Announcement Bar</label>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="announcement_bar_enabled" value="1" <?= setting('announcement_bar_enabled', '0') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Enable Top Announcement Bar</label>
              </div>
              <input type="text" name="announcement_bar_text" class="form-control" value="<?= e(setting('announcement_bar_text', '')) ?>" placeholder="e.g. Complimentary nationwide delivery on orders over ৳3,000">
            </div>

            <div class="col-md-12">
              <label class="form-label fw-semibold">Footer Text / Copyright</label>
              <input type="text" name="footer_text" class="form-control" value="<?= e(setting('footer_text', '')) ?>" placeholder="e.g. Crafted with passion. All rights reserved.">
            </div>

            <div class="col-md-4">
              <label class="form-label small fw-semibold">Facebook URL</label>
              <input type="url" name="social_facebook" class="form-control form-control-sm" value="<?= e(setting('social_facebook', '')) ?>" placeholder="https://facebook.com/yourpage">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Instagram URL</label>
              <input type="url" name="social_instagram" class="form-control form-control-sm" value="<?= e(setting('social_instagram', '')) ?>" placeholder="https://instagram.com/yourpage">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">WhatsApp Number</label>
              <input type="text" name="social_whatsapp" class="form-control form-control-sm" value="<?= e(setting('social_whatsapp', '')) ?>" placeholder="+8801700000000">
            </div>
          </div>

          <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4">Storefront Maintenance Window</h6>
          <div class="row g-3 mb-4">
            <div class="col-12">
              <div class="p-3 bg-warning bg-opacity-10 border border-warning rounded">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                  <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="maintenance_mode" value="1" id="maintModeSwitch" <?= setting('maintenance_mode', '0') == '1' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold text-dark" for="maintModeSwitch">
                      Enable Maintenance Mode <?= setting('maintenance_mode', '0') == '1' ? '<span class="badge bg-danger ms-2">Active (Store Closed)</span>' : '<span class="badge bg-success ms-2">Live (Store Open)</span>' ?>
                    </label>
                  </div>
                  <a href="<?= e(shop_url('maintenance.php')) ?>" target="_blank" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-eye me-1"></i> Preview Maintenance Window
                  </a>
                </div>
                <div class="small text-muted mt-2">
                  When enabled, storefront visitors see the scheduled maintenance screen. Logged-in admin and editor users can bypass and view the store normally.
                </div>
              </div>
            </div>
          </div>

          <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4">Architecture, UI & Image Pipeline</h6>
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="pretty_urls" value="1" <?= setting('pretty_urls', '0') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Clean / Pretty URLs (/shop, /product/slug)</label>
              </div>
              <div class="small text-muted mb-3">Uses mod_rewrite. Automatically creates/removes storage/pretty_urls.on lockfile.</div>

              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="dark_mode_enabled" value="1" <?= setting('dark_mode_enabled', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Allow Customer Dark Theme Toggle</label>
              </div>

              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="mobile_bottom_nav" value="1" <?= setting('mobile_bottom_nav', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Mobile Bottom Navigation Bar</label>
              </div>

              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="sticky_add_to_cart" value="1" <?= setting('sticky_add_to_cart', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Sticky Mobile Add-to-Cart Bar</label>
              </div>
            </div>

            <div class="col-md-6">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="image_pipeline_enabled" value="1" <?= setting('image_pipeline_enabled', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Responsive WebP Image Pipeline</label>
              </div>
              <div class="small text-muted mb-3">Generates optimized WebP variants and responsive srcset tags lazily.</div>

              <div class="p-3 bg-light rounded border">
                <div class="fw-semibold small mb-1">Image Pipeline Cache</div>
                <div class="text-muted small mb-2">Cached resized variants stored in assets/uploads/cache/.</div>
                <button type="submit" form="clearCacheForm" class="btn btn-outline-secondary btn-sm">
                  <i class="bi bi-trash me-1"></i> Clear Image Cache
                </button>
              </div>
            </div>
          </div>

          <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4">Homepage Trust Bar & Brand Story</h6>
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Trust Item 1 (COD)</label>
              <input type="text" name="trust_item_1_title" class="form-control form-control-sm mb-1" value="<?= e(setting('trust_item_1_title', 'Nationwide COD')) ?>" placeholder="Title">
              <input type="text" name="trust_item_1_desc" class="form-control form-control-sm" value="<?= e(setting('trust_item_1_desc', 'Cash on delivery across all 64 districts')) ?>" placeholder="Subtitle">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Trust Item 2 (Fast Delivery)</label>
              <input type="text" name="trust_item_2_title" class="form-control form-control-sm mb-1" value="<?= e(setting('trust_item_2_title', 'Fast Dispatch')) ?>" placeholder="Title">
              <input type="text" name="trust_item_2_desc" class="form-control form-control-sm" value="<?= e(setting('trust_item_2_desc', '24h in Dhaka, 48-72h outside')) ?>" placeholder="Subtitle">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Trust Item 3 (Authentic)</label>
              <input type="text" name="trust_item_3_title" class="form-control form-control-sm mb-1" value="<?= e(setting('trust_item_3_title', '100% Authentic')) ?>" placeholder="Title">
              <input type="text" name="trust_item_3_desc" class="form-control form-control-sm" value="<?= e(setting('trust_item_3_desc', 'Guaranteed genuine products direct from makers')) ?>" placeholder="Subtitle">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Trust Item 4 (Support)</label>
              <input type="text" name="trust_item_4_title" class="form-control form-control-sm mb-1" value="<?= e(setting('trust_item_4_title', 'Dedicated Support')) ?>" placeholder="Title">
              <input type="text" name="trust_item_4_desc" class="form-control form-control-sm" value="<?= e(setting('trust_item_4_desc', 'Friendly phone & WhatsApp assistance')) ?>" placeholder="Subtitle">
            </div>

            <div class="col-12 mt-3">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="home_show_brand_story" value="1" <?= setting('home_show_brand_story', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Show Brand Story Section on Homepage</label>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Brand Story Headline</label>
              <input type="text" name="brand_story_title" class="form-control form-control-sm" value="<?= e(setting('brand_story_title', 'Crafted with Intention')) ?>">
            </div>
            <div class="col-md-8">
              <label class="form-label small fw-semibold">Brand Story Paragraph</label>
              <textarea name="brand_story_text" class="form-control form-control-sm" rows="2"><?= e(setting('brand_story_text', 'We believe everyday objects should be both functional and beautiful. Every piece in our collection is curated with care and designed to last.')) ?></textarea>
            </div>
          </div>

          <h6 class="fw-bold border-bottom pb-2 mb-3 mt-4">Reviews, Newsletter & Size Guide</h6>
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="reviews_enabled" value="1" <?= setting('reviews_enabled', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Enable Customer Reviews</label>
              </div>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="reviews_require_login" value="1" <?= setting('reviews_require_login', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label small">Require Account Login to Review</label>
              </div>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="reviews_auto_approve" value="1" <?= setting('reviews_auto_approve', '0') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label small">Auto-Approve Reviews (No Moderation)</label>
              </div>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="home_show_reviews" value="1" <?= setting('home_show_reviews', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label small">Show Customer Reviews on Homepage</label>
              </div>
            </div>

            <div class="col-md-4">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="newsletter_enabled" value="1" <?= setting('newsletter_enabled', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Enable Newsletter Subscription</label>
              </div>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="newsletter_double_optin" value="1" <?= setting('newsletter_double_optin', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label small">Double Opt-in Confirmation Email</label>
              </div>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="home_show_newsletter" value="1" <?= setting('home_show_newsletter', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label small">Show Newsletter on Homepage</label>
              </div>
            </div>

            <div class="col-md-4">
              <label class="form-label small fw-semibold">Size Guide Page Slug</label>
              <input type="text" name="size_guide_page_slug" class="form-control form-control-sm" value="<?= e(setting('size_guide_page_slug', 'size-guide')) ?>" placeholder="size-guide">
              <div class="form-text">Slug of the CMS Page to load in the Product Detail modal.</div>
            </div>
          </div>

          <div class="d-flex align-items-center gap-3">
            <button type="submit" class="btn btn-primary px-4">
              <i class="bi bi-save me-1"></i> Save Storefront Settings
            </button>
          </div>
        </form>

        <form method="post" id="clearCacheForm" class="d-none">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clear_image_cache">
        </form>
      </div>
    </div>
  <?php endif; ?>

  <!-- TAB 3: INVENTORY & STOCK -->
  <?php if ($activeTab === 'inventory'): ?>
    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <h5 class="fw-bold mb-0">Inventory & Realtime Stock Display</h5>
          <div class="d-flex align-items-center gap-2">
            <button type="submit" form="inventoryForm" class="btn btn-primary btn-sm px-3">
              <i class="bi bi-save me-1"></i> Save Settings
            </button>
            <form method="post" onsubmit="return confirm('Reset inventory settings to defaults?');" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_tab">
              <input type="hidden" name="tab_name" value="inventory">
              <button class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
            </form>
          </div>
        </div>

        <form method="post" id="inventoryForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_inventory">

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Stock Display Mode</label>
              <?php $sMode = setting('stock_display_mode', 'count_when_low'); ?>
              <select name="stock_display_mode" class="form-select">
                <option value="always" <?= $sMode === 'always' ? 'selected' : '' ?>>Always show exact stock count (e.g. "12 in stock")</option>
                <option value="count_when_low" <?= $sMode === 'count_when_low' ? 'selected' : '' ?>>Show exact count ONLY when stock is low (e.g. "Only 3 left")</option>
                <option value="in_out_only" <?= $sMode === 'in_out_only' ? 'selected' : '' ?>>In stock / Out of stock only (no numbers displayed)</option>
                <option value="hidden" <?= $sMode === 'hidden' ? 'selected' : '' ?>>Hidden (Never show stock counts; API does not leak numbers)</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Global Low-Stock Threshold</label>
              <input type="number" name="low_stock_threshold" class="form-control" min="1" max="100" value="<?= e(setting('low_stock_threshold', '5')) ?>">
              <div class="form-text">Default threshold when product-level threshold is not customized.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Out of Stock Behavior</label>
              <?php $oos = setting('out_of_stock_behavior', 'show_disabled'); ?>
              <select name="out_of_stock_behavior" class="form-select">
                <option value="show_disabled" <?= $oos === 'show_disabled' ? 'selected' : '' ?>>Show product with "Out of stock" badge (Add to Cart disabled)</option>
                <option value="hide" <?= $oos === 'hide' ? 'selected' : '' ?>>Hide out-of-stock products completely from storefront catalog</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Realtime Stock Polling Interval (Seconds)</label>
              <input type="number" name="realtime_poll_seconds" class="form-control" min="5" max="60" value="<?= e(setting('realtime_poll_seconds', '10')) ?>">
              <div class="form-text">Rate at which client browsers poll for stock changes (5–60 seconds).</div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Realtime Mode</label>
              <?php $rtMode = setting('realtime_mode', 'polling'); ?>
              <select name="realtime_mode" class="form-select">
                <option value="polling" <?= $rtMode === 'polling' ? 'selected' : '' ?>>Standard Polling (ETag 304 conditional HTTP)</option>
                <option value="sse" <?= $rtMode === 'sse' ? 'selected' : '' ?>>Server-Sent Events (SSE stream fallback)</option>
              </select>
            </div>
          </div>

          <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-save me-1"></i> Save Inventory Settings
          </button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <!-- TAB 4: CHECKOUT & COD -->
  <?php if ($activeTab === 'checkout'): ?>
    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <h5 class="fw-bold mb-0">Checkout & Payment Gateways</h5>
          <div class="d-flex align-items-center gap-2">
            <button type="submit" form="checkoutForm" class="btn btn-primary btn-sm px-3">
              <i class="bi bi-save me-1"></i> Save Settings
            </button>
            <form method="post" onsubmit="return confirm('Reset checkout settings to defaults?');" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_tab">
              <input type="hidden" name="tab_name" value="checkout">
              <button class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
            </form>
          </div>
        </div>

        <form method="post" id="checkoutForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_checkout">

          <h6 class="fw-bold border-bottom pb-2 mb-3">Customer & Order Fields</h6>
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="guest_checkout" value="1" <?= setting('guest_checkout', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Allow Guest Checkout</label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="require_phone" value="1" <?= setting('require_phone', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Require Valid Phone (BD format)</label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="require_email" value="1" <?= setting('require_email', '0') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Require Email at Checkout</label>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Order Number Prefix</label>
              <input type="text" name="order_number_prefix" class="form-control" value="<?= e(setting('order_number_prefix', 'ORD')) ?>" maxlength="10">
              <div class="form-text">e.g. ORD -> ORD-202610-0001</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Anti-Spam Order Limit per IP (30 min)</label>
              <input type="number" name="antispam_orders_limit" class="form-control" min="1" max="50" value="<?= e(setting('antispam_orders_limit', '5')) ?>">
            </div>
          </div>

          <h6 class="fw-bold border-bottom pb-2 mb-3">Cash on Delivery (COD) Rules</h6>
          <div class="row g-3 mb-4">
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="cod_enabled" value="1" <?= setting('cod_enabled', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold">Enable Cash on Delivery (COD)</label>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">COD Handling Fee</label>
              <input type="number" step="0.01" min="0" name="cod_fee" class="form-control" value="<?= e(setting('cod_fee', '0.00')) ?>">
              <div class="form-text">Extra fee added to order total if paid via COD.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Minimum Order for COD</label>
              <input type="number" step="0.01" min="0" name="cod_min_order" class="form-control" value="<?= e(setting('cod_min_order', '0')) ?>">
              <div class="form-text">0 = no minimum required.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Maximum Order for COD</label>
              <input type="number" step="0.01" min="0" name="cod_max_order" class="form-control" value="<?= e(setting('cod_max_order', '0')) ?>">
              <div class="form-text">0 = no maximum limit.</div>
            </div>
          </div>

          <h6 class="fw-bold border-bottom pb-2 mb-3">Direct Bank Transfer</h6>
          <div class="row g-3 mb-4">
            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="bank_transfer_enabled" value="1" <?= setting('bank_transfer_enabled', '0') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold">Enable Bank Transfer</label>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Bank Instructions Text</label>
              <textarea name="bank_transfer_instructions" class="form-control" rows="3" placeholder="Bank name, account number, branch, routing code..."><?= e(setting('bank_transfer_instructions', '')) ?></textarea>
            </div>
          </div>

          <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-save me-1"></i> Save Checkout Settings
          </button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <!-- TAB 5: SHIPPING METHODS CRUD -->
  <?php if ($activeTab === 'shipping'): ?>
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0">Active Shipping Delivery Zones & Methods</h5>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#addShippingForm">
          <i class="bi bi-plus-circle me-1"></i> Add Shipping Method
        </button>
      </div>
      <div class="card-body p-0">
        <!-- Add Shipping Method Collapse -->
        <div class="collapse p-4 border-bottom bg-light" id="addShippingForm">
          <h6 class="fw-bold mb-3">Add New Shipping Method</h6>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_shipping">
            <div class="row g-3">
              <div class="col-md-3">
                <label class="form-label small fw-semibold">Method Name</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Express Delivery" required>
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-semibold">Zone / Region</label>
                <input type="text" name="zone" class="form-control" placeholder="Inside Dhaka / Nationwide" required>
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-semibold">Cost (৳)</label>
                <input type="number" step="0.01" min="0" name="cost" class="form-control" value="60.00" required>
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-semibold">Free Over (৳)</label>
                <input type="number" step="0.01" min="0" name="free_over" class="form-control" placeholder="e.g. 3000">
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-semibold">Est. Days (Min-Max)</label>
                <div class="input-group">
                  <input type="number" name="min_days" class="form-control" value="1" min="1" max="30">
                  <span class="input-group-text">-</span>
                  <input type="number" name="max_days" class="form-control" value="3" min="1" max="30">
                </div>
              </div>
              <div class="col-md-2">
                <div class="form-check mt-4">
                  <input class="form-check-input" type="checkbox" name="cod_allowed" value="1" checked id="addCodAllowed">
                  <label class="form-check-label small fw-semibold" for="addCodAllowed">COD Allowed</label>
                </div>
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-semibold">Status</label>
                <select name="status" class="form-select">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
              <div class="col-12 mt-3">
                <button type="submit" class="btn btn-success"><i class="bi bi-check2"></i> Create Method</button>
              </div>
            </div>
          </form>
        </div>

        <!-- Shipping Methods List -->
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Method Name</th>
                <th>Zone</th>
                <th>Standard Cost</th>
                <th>Free Over</th>
                <th>ETA</th>
                <th>COD Allowed</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$shippingMethods): ?>
                <tr>
                  <td colspan="8" class="text-center py-4 text-muted">No shipping methods configured yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($shippingMethods as $sm): ?>
                  <tr>
                    <form method="post">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update_shipping">
                      <input type="hidden" name="shipping_id" value="<?= (int)$sm['id'] ?>">

                      <td>
                        <input type="text" name="name" class="form-control form-control-sm" value="<?= e($sm['name']) ?>" required>
                      </td>
                      <td>
                        <input type="text" name="zone" class="form-control form-control-sm" value="<?= e($sm['zone']) ?>">
                      </td>
                      <td style="width: 110px;">
                        <input type="number" step="0.01" min="0" name="cost" class="form-control form-control-sm" value="<?= e($sm['cost']) ?>" required>
                      </td>
                      <td style="width: 110px;">
                        <input type="number" step="0.01" min="0" name="free_over" class="form-control form-control-sm" value="<?= e($sm['free_over'] ?? '') ?>" placeholder="None">
                      </td>
                      <td style="width: 120px;">
                        <div class="input-group input-group-sm">
                          <input type="number" name="min_days" class="form-control" value="<?= e($sm['min_days']) ?>">
                          <span class="input-group-text">-</span>
                          <input type="number" name="max_days" class="form-control" value="<?= e($sm['max_days']) ?>">
                        </div>
                      </td>
                      <td class="text-center">
                        <input type="checkbox" name="cod_allowed" value="1" <?= !empty($sm['cod_allowed']) ? 'checked' : '' ?> class="form-check-input">
                      </td>
                      <td>
                        <select name="status" class="form-select form-select-sm">
                          <option value="active" <?= $sm['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                          <option value="inactive" <?= $sm['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                      </td>
                      <td class="text-end text-nowrap">
                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Save changes">
                          <i class="bi bi-check-lg"></i>
                        </button>
                    </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this shipping method?');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="delete_shipping">
                          <input type="hidden" name="shipping_id" value="<?= (int)$sm['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                            <i class="bi bi-trash"></i>
                          </button>
                        </form>
                      </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- TAB 6: COUPONS CRUD -->
  <?php if ($activeTab === 'coupons'): ?>
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0">Discount Coupons Management</h5>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#addCouponCollapse">
          <i class="bi bi-plus-circle me-1"></i> Create Coupon
        </button>
      </div>
      <div class="card-body p-0">
        <!-- Add Coupon Form Collapse -->
        <div class="collapse p-4 border-bottom bg-light" id="addCouponCollapse">
          <h6 class="fw-bold mb-3">Add New Coupon</h6>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_coupon">
            <div class="row g-3">
              <div class="col-md-3">
                <label class="form-label small fw-semibold">Coupon Code</label>
                <div class="input-group">
                  <input type="text" name="code" id="couponCodeInput" class="form-control" placeholder="e.g. SALE20" required style="text-transform: uppercase;">
                  <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('couponCodeInput').value = 'OFF' + Math.floor(1000 + Math.random() * 9000);" title="Generate random code">
                    <i class="bi bi-magic"></i>
                  </button>
                </div>
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-semibold">Type</label>
                <select name="type" class="form-select">
                  <option value="percent">Percent (%)</option>
                  <option value="fixed">Fixed Amount (৳)</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-semibold">Value</label>
                <input type="number" step="0.01" min="0.01" name="value" class="form-control" value="10.00" required>
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-semibold">Min Subtotal (৳)</label>
                <input type="number" step="0.01" min="0" name="min_subtotal" class="form-control" value="0.00">
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-semibold">Max Discount (৳) <span class="text-muted">(for %)</span></label>
                <input type="number" step="0.01" min="0" name="max_discount" class="form-control" placeholder="Optional">
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-semibold">Usage Limit</label>
                <input type="number" min="1" name="usage_limit" class="form-control" placeholder="Unlimited">
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-semibold">Expires At</label>
                <input type="datetime-local" name="expires_at" class="form-control">
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-semibold">Status</label>
                <select name="status" class="form-select">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
              <div class="col-12 mt-3">
                <button type="submit" class="btn btn-success"><i class="bi bi-check2"></i> Save Coupon</button>
              </div>
            </div>
          </form>
        </div>

        <!-- Coupons Table -->
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Code</th>
                <th>Discount</th>
                <th>Min Subtotal</th>
                <th>Usage Count</th>
                <th>Expires</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$couponsList): ?>
                <tr>
                  <td colspan="7" class="text-center py-4 text-muted">No coupons created yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($couponsList as $cp): ?>
                  <tr>
                    <td>
                      <span class="badge bg-light text-dark border fs-6"><code><?= e($cp['code']) ?></code></span>
                    </td>
                    <td class="fw-semibold">
                      <?= $cp['type'] === 'percent' ? (float)$cp['value'] . '%' : format_price($cp['value']) ?>
                      <?php if (!empty($cp['max_discount'])): ?>
                        <div class="small text-muted">Max: <?= format_price($cp['max_discount']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= (float)$cp['min_subtotal'] > 0 ? format_price($cp['min_subtotal']) : 'None' ?></td>
                    <td>
                      <?= (int)$cp['used_count'] ?> / <?= $cp['usage_limit'] ? (int)$cp['usage_limit'] : '&infin;' ?>
                    </td>
                    <td class="small text-muted">
                      <?= !empty($cp['expires_at']) ? date('M j, Y', strtotime($cp['expires_at'])) : 'Never' ?>
                    </td>
                    <td>
                      <span class="badge <?= $cp['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?> text-uppercase">
                        <?= e($cp['status']) ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <form method="post" class="d-inline" onsubmit="return confirm('Delete this coupon?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_coupon">
                        <input type="hidden" name="coupon_id" value="<?= (int)$cp['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Coupon">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- TAB 7: SEO -->
  <?php if ($activeTab === 'seo'): ?>
    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <h5 class="fw-bold mb-0">Search Engine Optimization & Sitemaps</h5>
          <div class="d-flex align-items-center gap-2">
            <button type="submit" form="seoForm" class="btn btn-primary btn-sm px-3">
              <i class="bi bi-save me-1"></i> Save Settings
            </button>
            <form method="post" onsubmit="return confirm('Reset SEO settings to defaults?');" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_tab">
              <input type="hidden" name="tab_name" value="seo">
              <button class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
            </form>
          </div>
        </div>

        <form method="post" id="seoForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_seo">

          <div class="row g-3 mb-4">
            <div class="col-12">
              <label class="form-label fw-semibold">Default Meta Title</label>
              <input type="text" name="seo_default_title" class="form-control" value="<?= e(setting('seo_default_title', '')) ?>" placeholder="e.g. Premium E-Commerce Store | Best Online Deals">
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold">Default Meta Description</label>
              <textarea name="seo_default_description" class="form-control" rows="3" placeholder="Compelling 150-160 character description of your store for Google search snippets..."><?= e(setting('seo_default_description', '')) ?></textarea>
            </div>

            <div class="col-12">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="robots_allow" value="1" <?= setting('robots_allow', '1') == '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium">Allow Search Engine Crawlers (robots.txt index)</label>
              </div>
            </div>
          </div>

          <div class="p-3 bg-light rounded border mb-4">
            <h6 class="fw-bold mb-2"><i class="bi bi-speedometer text-primary me-2"></i> External Monitoring Integration (Netdata / Grafana)</h6>
            <p class="small text-muted mb-2">
              Optionally link an external APM dashboard (like Netdata, Grafana, or Glances) inside the <a href="<?= e(admin_url('monitoring.php')) ?>">Server Monitoring</a> dashboard.
            </p>
            <div class="row g-2">
              <div class="col-md-8">
                <label class="form-label small fw-semibold">Dashboard URL</label>
                <input type="url" name="monitor_external_url" class="form-control form-control-sm" value="<?= e(setting('monitor_external_url', '')) ?>" placeholder="https://netdata.yourdomain.com">
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <div class="form-check form-switch pb-1">
                  <input class="form-check-input" type="checkbox" name="monitor_external_embed" value="1" <?= setting('monitor_external_embed', '0') == '1' ? 'checked' : '' ?>>
                  <label class="form-check-label small fw-medium">Embed as iframe (HTTPS required)</label>
                </div>
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-save me-1"></i> Save Settings
          </button>
        </form>
      </div>
    </div>
  <!-- TAB 8: CUSTOMERS (Prompt 14) -->
  <?php elseif ($activeTab === 'customers'): ?>
    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <div>
            <h5 class="fw-bold mb-1">Customer Accounts & Security Policy</h5>
            <p class="text-muted small mb-0">Control registration, email verification, session timeouts, lockout thresholds, and data privacy policies.</p>
          </div>
          <form method="post" class="d-inline" onsubmit="return confirm('Reset all Customer settings to system defaults?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_tab">
            <input type="hidden" name="tab_name" value="customers">
            <button type="submit" class="btn btn-outline-danger btn-sm">
              <i class="bi bi-arrow-counterclockwise me-1"></i> Reset to Defaults
            </button>
          </form>
        </div>

        <form method="post" id="customersForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_customers">

          <!-- Registration & Verification -->
          <div class="card mb-4 border">
            <div class="card-header bg-light py-2 fw-semibold">
              <i class="bi bi-person-plus text-primary me-2"></i> Registration & Email Verification
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="customer_registration_enabled" value="1" <?= setting('customer_registration_enabled', '1') == '1' ? 'checked' : '' ?> id="regEnabledToggle">
                    <label class="form-check-label fw-semibold" for="regEnabledToggle">Allow Public Registration</label>
                  </div>
                  <div class="form-text small">When disabled, new customers cannot register via the storefront.</div>
                </div>

                <div class="col-md-6">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="customer_require_email_verification" value="1" <?= setting('customer_require_email_verification', '1') == '1' ? 'checked' : '' ?> id="reqVerifToggle">
                    <label class="form-check-label fw-semibold" for="reqVerifToggle">Require Email Verification</label>
                  </div>
                  <div class="form-text small">Sends an activation link upon customer registration.</div>
                </div>

                <div class="col-md-6">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="customer_block_login_unverified" value="1" <?= setting('customer_block_login_unverified', '0') == '1' ? 'checked' : '' ?> id="blockUnverifiedToggle">
                    <label class="form-check-label fw-semibold" for="blockUnverifiedToggle">Block Login Until Verified</label>
                  </div>
                  <div class="form-text small">If enabled, customers cannot log in until their email has been verified.</div>
                </div>

                <div class="col-md-6">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="customer_claim_guest_orders_on_verify" value="1" <?= setting('customer_claim_guest_orders_on_verify', '1') == '1' ? 'checked' : '' ?> id="claimOrdersToggle">
                    <label class="form-check-label fw-semibold" for="claimOrdersToggle">Auto-Claim Guest Orders</label>
                  </div>
                  <div class="form-text small">Automatically links past guest orders matching the verified email.</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Password Policy & Brute-Force Protection -->
          <div class="card mb-4 border">
            <div class="card-header bg-light py-2 fw-semibold">
              <i class="bi bi-shield-lock text-primary me-2"></i> Passwords & Brute-Force Lockout
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label small fw-semibold">Minimum Password Length</label>
                  <input type="number" name="customer_password_min_length" class="form-control" value="<?= (int)setting('customer_password_min_length', '8') ?>" min="6" max="64" required>
                  <div class="form-text small">Recommended: 8 characters or more.</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label small fw-semibold">Max Login Attempts</label>
                  <input type="number" name="customer_login_max_attempts" class="form-control" value="<?= (int)setting('customer_login_max_attempts', '5') ?>" min="3" max="20" required>
                  <div class="form-text small">Before account is temporarily locked.</div>
                </div>

                <div class="col-md-4">
                  <label class="form-label small fw-semibold">Lockout Duration (Minutes)</label>
                  <input type="number" name="customer_lockout_minutes" class="form-control" value="<?= (int)setting('customer_lockout_minutes', '15') ?>" min="1" max="1440" required>
                  <div class="form-text small">Cooldown after exceeding maximum attempts.</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Tokens, Sessions & Timeouts -->
          <div class="card mb-4 border">
            <div class="card-header bg-light py-2 fw-semibold">
              <i class="bi bi-clock-history text-primary me-2"></i> Tokens & Session Lifetimes
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-3">
                  <label class="form-label small fw-semibold">Password Reset TTL (Minutes)</label>
                  <input type="number" name="customer_password_reset_minutes" class="form-control" value="<?= (int)setting('customer_password_reset_minutes', '60') ?>" min="5" max="1440" required>
                  <div class="form-text small">Expiration for password reset tokens.</div>
                </div>

                <div class="col-md-3">
                  <label class="form-label small fw-semibold">Verify Email TTL (Hours)</label>
                  <input type="number" name="customer_verify_token_hours" class="form-control" value="<?= (int)setting('customer_verify_token_hours', '24') ?>" min="1" max="168" required>
                  <div class="form-text small">Activation link lifetime.</div>
                </div>

                <div class="col-md-3">
                  <label class="form-label small fw-semibold">Remember-Me Lifetime (Days)</label>
                  <input type="number" name="customer_remember_me_days" class="form-control" value="<?= (int)setting('customer_remember_me_days', '30') ?>" min="1" max="365" required>
                  <div class="form-text small">Persistent login token validity.</div>
                </div>

                <div class="col-md-3">
                  <label class="form-label small fw-semibold">Idle Timeout (Minutes)</label>
                  <input type="number" name="customer_session_idle_minutes" class="form-control" value="<?= (int)setting('customer_session_idle_minutes', '120') ?>" min="0" max="10080" required>
                  <div class="form-text small">Auto-logout after inactivity (0 = disabled).</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Features, Privacy & Compliance -->
          <div class="card mb-4 border">
            <div class="card-header bg-light py-2 fw-semibold">
              <i class="bi bi-toggles text-primary me-2"></i> Features, Wishlist & Privacy
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="customer_wishlist_enabled" value="1" <?= setting('customer_wishlist_enabled', '1') == '1' ? 'checked' : '' ?> id="wishlistEnabledToggle">
                    <label class="form-check-label fw-semibold" for="wishlistEnabledToggle">Enable Customer Wishlist</label>
                  </div>
                  <div class="form-text small">Allows customers to save favorite items.</div>
                </div>

                <div class="col-md-6">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="customer_allow_account_delete" value="1" <?= setting('customer_allow_account_delete', '1') == '1' ? 'checked' : '' ?> id="allowDeleteToggle">
                    <label class="form-check-label fw-semibold" for="allowDeleteToggle">Allow Self-Service Account Deletion</label>
                  </div>
                  <div class="form-text small">Permits customers to anonymize and delete their accounts.</div>
                </div>

                <div class="col-md-6">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="customer_can_cancel" value="1" <?= setting('customer_can_cancel', '1') == '1' ? 'checked' : '' ?> id="canCancelToggle">
                    <label class="form-check-label fw-semibold" for="canCancelToggle">Allow Customer Order Cancellation</label>
                  </div>
                  <div class="form-text small">Customers can cancel their orders while status is "pending".</div>
                </div>

                <div class="col-md-6">
                  <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="customer_marketing_checkbox_default" value="1" <?= setting('customer_marketing_checkbox_default', '1') == '1' ? 'checked' : '' ?> id="mktOptinToggle">
                    <label class="form-check-label fw-semibold" for="mktOptinToggle">Pre-Check Marketing Opt-In</label>
                  </div>
                  <div class="form-text small">Default state of newsletter checkbox on registration/checkout.</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Terms & Conditions URL</label>
                  <input type="text" name="customer_terms_url" class="form-control" value="<?= e(setting('customer_terms_url', '')) ?>" placeholder="/terms.php or https://example.com/terms">
                  <div class="form-text small">Optional link displayed on registration page.</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Purge Unverified Accounts After (Days)</label>
                  <input type="number" name="customer_unverified_ttl_days" class="form-control" value="<?= (int)setting('customer_unverified_ttl_days', '14') ?>" min="0" max="365" required>
                  <div class="form-text small">Maintenance cron cleans up abandoned pending registrations (0 = keep forever).</div>
                </div>
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-save me-1"></i> Save Customer Settings
          </button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
