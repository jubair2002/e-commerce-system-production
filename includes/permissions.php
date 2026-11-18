<?php
/**
 * Role-Based Access Control (RBAC) & Granular Permission System
 *
 * Defines permission catalog, role presets, and permission verification helpers.
 */

declare(strict_types=1);

/**
 * Return all system permissions categorized by module.
 *
 * @return array<string, array{label: string, icon: string, permissions: array<string, array{label: string, description: string}>}>
 */
function admin_permissions_catalog(): array
{
    return [
        'orders' => [
            'label' => 'Orders & Fulfillment',
            'icon'  => 'bi-receipt',
            'permissions' => [
                'orders.view'   => [
                    'label'       => 'View Orders',
                    'description' => 'Browse order lists, search, filter, and inspect detailed order items and customer info.',
                ],
                'orders.manage' => [
                    'label'       => 'Manage Orders',
                    'description' => 'Update order fulfillment status, edit shipping tracking numbers, and add notes.',
                ],
                'orders.delete' => [
                    'label'       => 'Delete Orders',
                    'description' => 'Permanently delete orders and order history records.',
                ],
                'orders.print'  => [
                    'label'       => 'Print Invoices',
                    'description' => 'Generate and print customer invoices, packing slips, and delivery receipts.',
                ],
            ],
        ],
        'catalog' => [
            'label' => 'Products & Catalog',
            'icon'  => 'bi-box-seam',
            'permissions' => [
                'products.view'   => [
                    'label'       => 'View Products',
                    'description' => 'Browse product list, view SKUs, pricing, stock levels, and variants.',
                ],
                'products.create' => [
                    'label'       => 'Create Products',
                    'description' => 'Add new simple and configurable/variable products.',
                ],
                'products.edit'   => [
                    'label'       => 'Edit Products',
                    'description' => 'Edit product details, pricing, variants, gallery images, attributes, and SEO.',
                ],
                'products.delete' => [
                    'label'       => 'Delete Products',
                    'description' => 'Delete products and bulk-remove catalog items.',
                ],
                'categories.manage' => [
                    'label'       => 'Manage Categories',
                    'description' => 'Create, edit, organize category trees, and delete categories.',
                ],
            ],
        ],
        'inventory' => [
            'label' => 'Inventory & Warehouse',
            'icon'  => 'bi-boxes',
            'permissions' => [
                'inventory.view'   => [
                    'label'       => 'View Inventory',
                    'description' => 'Inspect stock availability, low-stock thresholds, and stock movement history.',
                ],
                'inventory.manage' => [
                    'label'       => 'Manage Inventory',
                    'description' => 'Perform quick stock adjustments and update warehouse quantities.',
                ],
            ],
        ],
        'customers' => [
            'label' => 'Customers & Reviews',
            'icon'  => 'bi-people',
            'permissions' => [
                'customers.view'   => [
                    'label'       => 'View Customers',
                    'description' => 'Browse customer directory, inspect profiles, addresses, and order history.',
                ],
                'customers.manage' => [
                    'label'       => 'Manage Customers',
                    'description' => 'Edit customer details, reset passwords, unlock accounts, and verify emails.',
                ],
                'customers.delete' => [
                    'label'       => 'Delete / Ban Customers',
                    'description' => 'Permanently delete customer accounts or soft-delete/ban users.',
                ],
                'reviews.view'     => [
                    'label'       => 'View Reviews',
                    'description' => 'Read customer product ratings and reviews.',
                ],
                'reviews.manage'   => [
                    'label'       => 'Moderate Reviews',
                    'description' => 'Approve, reject, feature, or delete submitted product reviews.',
                ],
            ],
        ],
        'marketing' => [
            'label' => 'Marketing, CMS & Communications',
            'icon'  => 'bi-megaphone',
            'permissions' => [
                'analytics.view'     => [
                    'label'       => 'View Analytics',
                    'description' => 'Access revenue charts, visitor analytics, sales statistics, and top product metrics.',
                ],
                'analytics.export'   => [
                    'label'       => 'Export Reports',
                    'description' => 'Export revenue and order data to CSV / Excel.',
                ],
                'banners.manage'     => [
                    'label'       => 'Manage Banners',
                    'description' => 'Create, edit, schedule, and delete homepage and storefront promo banners.',
                ],
                'pages.manage'       => [
                    'label'       => 'Manage Pages (CMS)',
                    'description' => 'Create, edit, and publish static CMS store pages (About Us, Terms, FAQ).',
                ],
                'messages.manage'    => [
                    'label'       => 'Contact Messages',
                    'description' => 'View customer contact submissions, mark resolved, and reply.',
                ],
                'subscribers.manage' => [
                    'label'       => 'Newsletter Subscribers',
                    'description' => 'View and export newsletter subscriber lists.',
                ],
                'coupons.manage'     => [
                    'label'       => 'Coupons & Discounts',
                    'description' => 'Create and configure promo discount codes, expiration dates, and minimum spend.',
                ],
            ],
        ],
        'settings' => [
            'label' => 'Settings & Infrastructure',
            'icon'  => 'bi-gear',
            'permissions' => [
                'settings.general' => [
                    'label'       => 'Store Settings',
                    'description' => 'Configure store details, currency, taxes, and storefront appearance.',
                ],
                'settings.storage' => [
                    'label'       => 'Storage / AWS S3',
                    'description' => 'Configure cloud S3 storage, credentials, and CloudFront CDN.',
                ],
                'mail.manage'      => [
                    'label'       => 'Mail System',
                    'description' => 'Manage SMTP mail settings, email templates, and failed queue items.',
                ],
                'monitoring.view'  => [
                    'label'       => 'System Monitoring',
                    'description' => 'Inspect server CPU, memory, disk usage, background jobs, and system health.',
                ],
                'activity.view'    => [
                    'label'       => 'Activity Log',
                    'description' => 'Review the administrative audit log of all staff and system events.',
                ],
            ],
        ],
        'users' => [
            'label' => 'Users & Permissions',
            'icon'  => 'bi-shield-lock',
            'permissions' => [
                'users.view'   => [
                    'label'       => 'View Users & Permissions',
                    'description' => 'View user accounts and their assigned role and permissions.',
                ],
                'users.manage' => [
                    'label'       => 'Manage Users & Permissions',
                    'description' => 'Create new user accounts, edit credentials, roles, and configure permission sets.',
                ],
                'users.delete' => [
                    'label'       => 'Delete Users',
                    'description' => 'Delete user accounts.',
                ],
            ],
        ],
    ];
}

/**
 * Return a flat array of all valid permission keys.
 *
 * @return string[]
 */
function all_permission_keys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }

    $keys = [];
    foreach (admin_permissions_catalog() as $module) {
        foreach (array_keys($module['permissions']) as $perm) {
            $keys[] = (string)$perm;
        }
    }
    return $keys;
}

/**
 * Return predefined role configurations with their default permission sets.
 *
 * @return array<string, array{label: string, badge_class: string, description: string, permissions: string[]}>
 */
function admin_roles_catalog(): array
{
    return [
        'admin' => [
            'label'       => 'Super Administrator',
            'badge_class' => 'role-admin',
            'description' => 'Unrestricted access to all features, settings, user management, and server tools.',
            'permissions' => ['*'],
        ],
        'manager' => [
            'label'       => 'Store Manager',
            'badge_class' => 'role-manager',
            'description' => 'Full access to sales, catalog, customers, inventory, analytics, and marketing.',
            'permissions' => [
                'orders.view', 'orders.manage', 'orders.print',
                'products.view', 'products.create', 'products.edit', 'products.delete', 'categories.manage',
                'inventory.view', 'inventory.manage',
                'customers.view', 'customers.manage', 'reviews.view', 'reviews.manage',
                'analytics.view', 'analytics.export', 'banners.manage', 'pages.manage',
                'messages.manage', 'subscribers.manage', 'coupons.manage',
                'activity.view',
            ],
        ],
        'editor' => [
            'label'       => 'Catalog & Content Editor',
            'badge_class' => 'role-editor',
            'description' => 'Manage products, categories, reviews, banners, and static store pages.',
            'permissions' => [
                'products.view', 'products.create', 'products.edit', 'categories.manage',
                'inventory.view',
                'reviews.view', 'reviews.manage',
                'banners.manage', 'pages.manage',
            ],
        ],
        'support' => [
            'label'       => 'Customer Support',
            'badge_class' => 'role-support',
            'description' => 'Handle order fulfillment, inspect customer accounts, view reviews, and reply to messages.',
            'permissions' => [
                'orders.view', 'orders.manage', 'orders.print',
                'customers.view', 'customers.manage',
                'reviews.view',
                'messages.manage',
                'products.view',
            ],
        ],
        'analyst' => [
            'label'       => 'Reports & Analyst',
            'badge_class' => 'role-analyst',
            'description' => 'Read-only access to analytics, orders, customers, and inventory data.',
            'permissions' => [
                'analytics.view', 'analytics.export',
                'orders.view',
                'customers.view',
                'inventory.view',
                'products.view',
                'activity.view',
            ],
        ],
        'custom' => [
            'label'       => 'Custom Role',
            'badge_class' => 'role-custom',
            'description' => 'Custom granular permissions explicitly configured by an administrator.',
            'permissions' => [],
        ],
    ];
}

/**
 * Extract normalized permission array for a user record.
 *
 * @param array|null $user
 * @return string[]
 */
function user_permissions(?array $user): array
{
    if (!$user) {
        return [];
    }

    // Role 'admin' always gets wildcard
    if (($user['role'] ?? '') === 'admin') {
        return ['*'];
    }

    $raw = $user['permissions'] ?? [];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
}

/**
 * Check whether a user has any of the requested permissions.
 *
 * @param array|null $user
 * @param string ...$permissions
 * @return bool
 */
function user_has_permission(?array $user, string ...$permissions): bool
{
    if (!$user) {
        return false;
    }

    $role = (string)($user['role'] ?? '');
    if ($role === 'admin') {
        return true;
    }

    $userPerms = user_permissions($user);
    if (in_array('*', $userPerms, true)) {
        return true;
    }

    if (empty($permissions)) {
        return true;
    }

    foreach ($permissions as $perm) {
        if ($perm === '*' || in_array($perm, $userPerms, true)) {
            return true;
        }

        // Support module wildcard e.g. "orders.*"
        $parts = explode('.', $perm, 2);
        if (count($parts) === 2 && in_array($parts[0] . '.*', $userPerms, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if the currently logged-in admin user has any of the specified permissions.
 *
 * @param string ...$permissions
 * @return bool
 */
function has_permission(string ...$permissions): bool
{
    $user = current_user();
    return user_has_permission($user, ...$permissions);
}

/**
 * Enforce that the currently logged-in user possesses one of the required permissions.
 *
 * @param string ...$permissions
 * @return void
 */
function require_permission(string ...$permissions): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    require_login();

    if (!has_permission(...$permissions)) {
        http_response_code(403);
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'    => false,
                'error' => 'You do not have permission to perform this action.',
            ]);
            exit;
        }

        flash('danger', 'You do not have permission to access that section.');
        admin_redirect('dashboard.php');
    }
}
