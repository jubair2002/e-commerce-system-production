<?php
/**
 * Automated Test Suite for RBAC & Admin User Permission Sets
 */

declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/auth.php';

$passCount = 0;
$failCount = 0;

function it(string $desc, callable $fn): void {
    global $passCount, $failCount;
    try {
        $fn();
        echo "  [PASS] {$desc}\n";
        $passCount++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$desc}: {$e->getMessage()}\n";
        $failCount++;
    }
}

echo "==========================================================\n";
echo "Starting RBAC & Admin Permissions Test Suite\n";
echo "==========================================================\n\n";

echo "--- 1. Catalog & Preset Validation ---\n";

it('Permission catalog contains expected modules and keys', function() {
    $catalog = admin_permissions_catalog();
    $expectedModules = ['orders', 'catalog', 'inventory', 'customers', 'marketing', 'settings', 'users'];
    foreach ($expectedModules as $m) {
        if (!isset($catalog[$m])) {
            throw new RuntimeException("Missing module '{$m}' in permissions catalog");
        }
    }
});

it('all_permission_keys() returns unique, valid string keys', function() {
    $keys = all_permission_keys();
    if (count($keys) < 25) {
        throw new RuntimeException("Expected at least 25 keys, found " . count($keys));
    }
    if (count($keys) !== count(array_unique($keys))) {
        throw new RuntimeException("Duplicate permission keys found in catalog");
    }
});

it('admin_roles_catalog() includes standard presets mapped to valid keys', function() {
    $allKeys = all_permission_keys();
    $roles = admin_roles_catalog();
    $expectedRoles = ['admin', 'manager', 'editor', 'support', 'analyst', 'custom'];
    foreach ($expectedRoles as $r) {
        if (!isset($roles[$r])) {
            throw new RuntimeException("Missing role '{$r}' in roles catalog");
        }
        if ($r !== 'admin' && $r !== 'custom') {
            foreach ($roles[$r]['permissions'] as $p) {
                if (!in_array($p, $allKeys, true)) {
                    throw new RuntimeException("Invalid permission '{$p}' configured in role '{$r}'");
                }
            }
        }
    }
});

echo "\n--- 2. Permission Evaluation Logic ---\n";

it('Super Admin (role=admin) automatically bypasses any permission check', function() {
    $user = ['id' => 99, 'name' => 'Admin', 'role' => 'admin', 'permissions' => []];
    if (!user_has_permission($user, 'orders.view', 'products.delete', 'any.random.key')) {
        throw new RuntimeException("Admin did not pass permission check");
    }
});

it('Wildcard (*) permission string grants access to all permissions', function() {
    $user = ['id' => 99, 'name' => 'Staff', 'role' => 'custom', 'permissions' => ['*']];
    if (!user_has_permission($user, 'settings.storage', 'monitoring.view')) {
        throw new RuntimeException("Wildcard permission did not grant access");
    }
});

it('Module wildcard (orders.*) grants access to all orders actions only', function() {
    $user = ['id' => 99, 'name' => 'Shipper', 'role' => 'custom', 'permissions' => ['orders.*']];
    if (!user_has_permission($user, 'orders.view')) throw new RuntimeException("Failed orders.view");
    if (!user_has_permission($user, 'orders.manage')) throw new RuntimeException("Failed orders.manage");
    if (!user_has_permission($user, 'orders.delete')) throw new RuntimeException("Failed orders.delete");
    if (user_has_permission($user, 'products.create')) throw new RuntimeException("Module wildcard leaked to products.create");
});

it('Specific granular permissions correctly allow and deny access', function() {
    $user = ['id' => 99, 'name' => 'Support', 'role' => 'support', 'permissions' => ['orders.view', 'messages.manage']];
    if (!user_has_permission($user, 'orders.view')) throw new RuntimeException("Failed orders.view");
    if (!user_has_permission($user, 'messages.manage')) throw new RuntimeException("Failed messages.manage");
    if (user_has_permission($user, 'orders.delete')) throw new RuntimeException("Should not have orders.delete");
    if (user_has_permission($user, 'settings.general')) throw new RuntimeException("Should not have settings.general");
});

it('has_role recognizes manager as satisfying editor requirements', function() {
    // Simulate user session
    $_SESSION['user'] = ['id' => 99, 'name' => 'Manager', 'role' => 'manager', 'permissions' => []];
    if (!has_role('editor')) {
        throw new RuntimeException("Manager role should satisfy editor role check");
    }
    if (!has_role('manager')) {
        throw new RuntimeException("Manager role should satisfy manager check");
    }
});

echo "\n--- 3. Database & Session Integration ---\n";

it('Inserts and fetches admin user with JSON permissions properly', function() {
    $pdo = db();
    $testEmail = 'rbac_test_' . uniqid() . '@example.com';
    $perms = ['orders.view', 'products.view', 'analytics.view'];

    $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role, permissions, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute(['Test RBAC', $testEmail, password_hash('pass123', PASSWORD_BCRYPT), 'analyst', json_encode($perms), 'active']);
    $userId = (int)$pdo->lastInsertId();

    $stmtGet = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmtGet->execute([$userId]);
    $row = $stmtGet->fetch(PDO::FETCH_ASSOC);

    if (!$row) throw new RuntimeException("Failed to fetch inserted user");
    $decoded = user_permissions($row);
    if ($decoded !== $perms) {
        throw new RuntimeException("Decoded permissions did not match. Got: " . json_encode($decoded));
    }

    // Cleanup
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
});

echo "\n==========================================================\n";
echo "Test Suite Results: {$passCount} Passed, {$failCount} Failed\n";
echo "==========================================================\n";

if ($failCount > 0) {
    exit(1);
}
