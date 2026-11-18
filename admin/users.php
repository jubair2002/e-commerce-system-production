<?php
/**
 * Admin: Staff & Admin User Management Listing
 *
 * Displays all staff users, their assigned roles, permissions matrix summaries,
 * account status, quick toggles, and deletion controls.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_permission('users.view', 'users.manage');

$pageTitle = 'Admin Users';
$pdo = db();
$me = current_user();

// -------------------------------------------------------------------------
// Handle POST Actions (Toggle Status, Delete)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $targetId = (int)($_POST['id'] ?? 0);

    // Fetch target user
    $stmt = $pdo->prepare('SELECT id, name, email, role, status, avatar, avatar_file_id FROM users WHERE id = :id');
    $stmt->execute([':id' => $targetId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        flash('danger', 'User not found.');
        admin_redirect('users.php');
    }

    // Safeguard: Cannot alter own status/account from this action
    if ($targetId === (int)($me['id'] ?? 0)) {
        flash('danger', 'You cannot change your own account status or delete yourself.');
        admin_redirect('users.php');
    }

    if ($action === 'toggle_status') {
        require_permission('users.manage');

        $newStatus = ($targetUser['status'] === 'active') ? 'inactive' : 'active';

        // Safeguard: Cannot deactivate the last active super admin
        if ($targetUser['role'] === 'admin' && $newStatus === 'inactive') {
            $adminCount = (int)$pdo->query(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'"
            )->fetchColumn();

            if ($adminCount <= 1) {
                flash('danger', 'Cannot deactivate the last remaining active Super Administrator.');
                admin_redirect('users.php');
            }
        }

        $upd = $pdo->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id');
        $upd->execute([':status' => $newStatus, ':id' => $targetId]);

        log_activity('user.status', "Changed status of admin user #{$targetId} ({$targetUser['email']}) to {$newStatus}");
        flash('success', "Account for {$targetUser['name']} is now {$newStatus}.");
        admin_redirect('users.php');
    }

    if ($action === 'delete') {
        require_permission('users.delete', 'users.manage');

        // Safeguard: Cannot delete the last super admin
        if ($targetUser['role'] === 'admin') {
            $adminCount = (int)$pdo->query(
                "SELECT COUNT(*) FROM users WHERE role = 'admin'"
            )->fetchColumn();

            if ($adminCount <= 1) {
                flash('danger', 'Cannot delete the only remaining Super Administrator account.');
                admin_redirect('users.php');
            }
        }

        // Clean up avatar if stored
        if (!empty($targetUser['avatar_file_id']) && function_exists('storage')) {
            try {
                storage()->deleteFile((int)$targetUser['avatar_file_id']);
            } catch (Throwable $e) {}
        }
        if (!empty($targetUser['avatar'])) {
            $avatarFile = UPLOADS_PATH . '/avatars/' . $targetUser['avatar'];
            if (file_exists($avatarFile)) {
                @unlink($avatarFile);
            }
        }

        $del = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $del->execute([':id' => $targetId]);

        log_activity('user.delete', "Deleted admin user #{$targetId} ({$targetUser['name']} <{$targetUser['email']}>)");
        flash('success', "Admin user {$targetUser['name']} has been deleted.");
        admin_redirect('users.php');
    }
}

// -------------------------------------------------------------------------
// Filters & Data Retrieval
// -------------------------------------------------------------------------
$q      = trim((string)($_GET['q'] ?? ''));
$fRole   = trim((string)($_GET['role'] ?? ''));
$fStatus = trim((string)($_GET['status'] ?? ''));

$whereClauses = ['1=1'];
$params = [];

if ($q !== '') {
    $whereClauses[] = '(name LIKE :q OR email LIKE :q OR phone LIKE :q)';
    $params[':q'] = "%{$q}%";
}

if ($fRole !== '') {
    $whereClauses[] = 'role = :role';
    $params[':role'] = $fRole;
}

if ($fStatus !== '' && in_array($fStatus, ['active', 'inactive'], true)) {
    $whereClauses[] = 'status = :status';
    $params[':status'] = $fStatus;
}

$whereSql = implode(' AND ', $whereClauses);

// Fetch stats overview
$statsTotal   = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$statsAdmins  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$statsStaff   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn();
$statsActive  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();

// Fetch users
$query = "SELECT id, name, email, phone, role, permissions, status, avatar, created_at, updated_at
          FROM users
          WHERE {$whereSql}
          ORDER BY (role = 'admin') DESC, id ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rolesCatalog = admin_roles_catalog();
$permCatalog  = admin_permissions_catalog();

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
  <div>
    <h3 class="mb-1">Admin Users & Permissions</h3>
    <p class="text-muted small mb-0">
      Manage administrative staff, assign pre-built roles, or configure granular module permissions.
    </p>
  </div>
  <?php if (has_permission('users.manage')): ?>
    <div>
      <a href="<?= e(admin_url('user_form.php')) ?>" class="btn btn-primary d-inline-flex align-items-center gap-2">
        <i class="bi bi-person-plus-fill"></i>
        <span>Add Admin User</span>
      </a>
    </div>
  <?php endif; ?>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="stat-icon bg-primary-subtle text-primary">
        <i class="bi bi-people-fill"></i>
      </div>
      <div>
        <div class="stat-value"><?= number_format($statsTotal) ?></div>
        <div class="stat-label">Total Staff Accounts</div>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="stat-icon bg-indigo-subtle text-indigo" style="background: rgba(79, 70, 229, 0.1); color: #4f46e5;">
        <i class="bi bi-shield-lock-fill"></i>
      </div>
      <div>
        <div class="stat-value"><?= number_format($statsAdmins) ?></div>
        <div class="stat-label">Super Administrators</div>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="stat-icon bg-info-subtle text-info">
        <i class="bi bi-person-badge-fill"></i>
      </div>
      <div>
        <div class="stat-value"><?= number_format($statsStaff) ?></div>
        <div class="stat-label">Role-Restricted Staff</div>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="stat-icon bg-success-subtle text-success">
        <i class="bi bi-check-circle-fill"></i>
      </div>
      <div>
        <div class="stat-value"><?= number_format($statsActive) ?></div>
        <div class="stat-label">Active Accounts</div>
      </div>
    </div>
  </div>
</div>

<!-- Filters Bar -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body p-3">
    <form method="get" class="row g-2 align-items-center">
      <div class="col-md-5">
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-light border-end-0">
            <i class="bi bi-search text-muted"></i>
          </span>
          <input type="text"
                 name="q"
                 class="form-control form-control-sm border-start-0"
                 placeholder="Search by name, email, or phone..."
                 value="<?= e($q) ?>">
        </div>
      </div>

      <div class="col-sm-4 col-md-3">
        <select name="role" class="form-select form-select-sm">
          <option value="">All Roles</option>
          <?php foreach ($rolesCatalog as $rk => $rMeta): ?>
            <option value="<?= e($rk) ?>" <?= $fRole === $rk ? 'selected' : '' ?>>
              <?= e($rMeta['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-sm-4 col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>

      <div class="col-sm-4 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-primary w-100">
          <i class="bi bi-funnel me-1"></i> Filter
        </button>
        <?php if ($q !== '' || $fRole !== '' || $fStatus !== ''): ?>
          <a href="<?= e(admin_url('users.php')) ?>" class="btn btn-sm btn-light" title="Reset Filters">
            <i class="bi bi-x-lg"></i>
          </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Users Table Card -->
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="min-width: 220px;">User</th>
          <th style="min-width: 150px;">Role</th>
          <th style="min-width: 260px;">Permissions</th>
          <th>Status</th>
          <th>Created</th>
          <th class="text-end" style="min-width: 140px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr>
            <td colspan="6" class="text-center py-5 text-muted">
              <i class="bi bi-person-x fs-1 text-muted d-block mb-2"></i>
              No admin users match your search criteria.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
            <?php
              $roleKey = $u['role'] ?? 'custom';
              $roleMeta = $rolesCatalog[$roleKey] ?? [
                  'label' => ucfirst($roleKey),
                  'badge_class' => 'role-custom',
                  'description' => 'Custom role',
              ];
              $isSelf = ((int)$u['id'] === (int)($me['id'] ?? 0));
              $userPerms = user_permissions($u);
              $isSuperAdmin = ($u['role'] === 'admin' || in_array('*', $userPerms, true));
            ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img src="<?= e(avatar_url($u['avatar'] ?? null, $u['name'])) ?>"
                       class="rounded-circle border"
                       width="40" height="40"
                       style="object-fit: cover;"
                       alt="">
                  <div>
                    <div class="fw-bold d-flex align-items-center gap-1">
                      <span><?= e($u['name']) ?></span>
                      <?php if ($isSelf): ?>
                        <span class="badge bg-primary-subtle text-primary font-monospace" style="font-size: 0.68rem;">You</span>
                      <?php endif; ?>
                    </div>
                    <div class="small text-muted"><?= e($u['email']) ?></div>
                    <?php if (!empty($u['phone'])): ?>
                      <div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= e($u['phone']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>

              <td>
                <span class="badge <?= e($roleMeta['badge_class']) ?> text-uppercase px-2 py-1" style="font-size: 0.75rem;">
                  <?= e($roleMeta['label']) ?>
                </span>
              </td>

              <td>
                <?php if ($isSuperAdmin): ?>
                  <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                    <i class="bi bi-shield-shaded me-1"></i> Full Access (Wildcard *)
                  </span>
                <?php else: ?>
                  <?php
                    $permCount = count($userPerms);
                    // Determine which modules have permissions granted
                    $activeModules = [];
                    foreach ($permCatalog as $mKey => $mVal) {
                        foreach (array_keys($mVal['permissions']) as $pk) {
                            if (in_array($pk, $userPerms, true) || in_array($mKey . '.*', $userPerms, true)) {
                                $activeModules[$mKey] = $mVal['label'];
                                break;
                            }
                        }
                    }
                  ?>
                  <?php if ($permCount === 0): ?>
                    <span class="text-muted small fst-italic">No permissions granted</span>
                  <?php else: ?>
                    <div class="d-flex flex-wrap align-items-center gap-1">
                      <span class="badge bg-light text-dark border">
                        <i class="bi bi-key me-1 text-muted"></i><?= $permCount ?> <?= $permCount === 1 ? 'perm' : 'perms' ?>
                      </span>
                      <?php foreach ($activeModules as $mKey => $mLabel): ?>
                        <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.68rem;" title="<?= e($mLabel) ?>">
                          <?= e($mLabel) ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>

              <td>
                <?php if ($u['status'] === 'active'): ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle">
                    <i class="bi bi-check-circle me-1"></i>Active
                  </span>
                <?php else: ?>
                  <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                    <i class="bi bi-pause-circle me-1"></i>Inactive
                  </span>
                <?php endif; ?>
              </td>

              <td class="text-muted small">
                <?= date('M j, Y', strtotime($u['created_at'])) ?>
              </td>

              <td class="text-end">
                <div class="btn-group btn-group-sm">
                  <?php if (has_permission('users.manage')): ?>
                    <a href="<?= e(admin_url('user_form.php?id=' . (int)$u['id'])) ?>"
                       class="btn btn-outline-secondary"
                       title="Edit User & Permissions">
                      <i class="bi bi-pencil"></i>
                    </a>

                    <!-- Toggle Status Form -->
                    <?php if (!$isSelf): ?>
                      <form method="post" class="d-inline" onsubmit="return confirm('Toggle status for <?= e(addslashes($u['name'])) ?>?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button type="submit"
                                class="btn btn-outline-secondary"
                                title="<?= $u['status'] === 'active' ? 'Deactivate Account' : 'Activate Account' ?>">
                          <i class="bi <?= $u['status'] === 'active' ? 'bi-person-slash text-warning' : 'bi-person-check text-success' ?>"></i>
                        </button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if (has_permission('users.delete', 'users.manage')): ?>
                    <?php if (!$isSelf): ?>
                      <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete user <?= e(addslashes($u['name'])) ?>? This cannot be undone.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger" title="Delete User">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
