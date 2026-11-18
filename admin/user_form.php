<?php
/**
 * Admin: Create / Edit Administrative Staff User & Permissions
 *
 * Provides full management of staff users, roles (Super Admin, Store Manager,
 * Content Editor, Support, Analyst, Custom), and module-by-module granular permission sets.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_permission('users.manage');

$pdo = db();
$me = current_user();

$id = (int)($_GET['id'] ?? 0);
$isEdit = ($id > 0);
$pageTitle = $isEdit ? 'Edit Admin User' : 'New Admin User';

$rolesCatalog = admin_roles_catalog();
$permCatalog  = admin_permissions_catalog();
$allPermKeys  = all_permission_keys();

// Default values for new user
$user = [
    'id'             => 0,
    'name'           => '',
    'email'          => '',
    'phone'          => '',
    'bio'            => '',
    'role'           => 'editor',
    'permissions'    => [],
    'status'         => 'active',
    'avatar'         => null,
    'avatar_file_id' => null,
];

// If editing, load existing user
if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$found) {
        flash('danger', 'Admin user not found.');
        admin_redirect('users.php');
    }

    $user = $found;
    $user['permissions'] = user_permissions($user);
}

$isSelf = ($isEdit && (int)$user['id'] === (int)($me['id'] ?? 0));
$errors = [];

// -------------------------------------------------------------------------
// Handle POST Form Submission
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $name     = trim((string)($_POST['name'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $phone    = trim((string)($_POST['phone'] ?? ''));
    $bio      = trim((string)($_POST['bio'] ?? ''));
    $role     = trim((string)($_POST['role'] ?? 'custom'));
    $status   = (($_POST['status'] ?? '') === 'inactive') ? 'inactive' : 'active';
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    $submittedPerms = $_POST['permissions'] ?? [];
    if (!is_array($submittedPerms)) {
        $submittedPerms = [];
    }

    // Role validation
    if (!array_key_exists($role, $rolesCatalog)) {
        $role = 'custom';
    }

    // Validation
    if ($name === '') {
        $errors[] = 'Full name is required.';
    } elseif (mb_strlen($name) > 100) {
        $errors[] = 'Full name cannot exceed 100 characters.';
    }

    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    } else {
        // Unique email check
        $stmtChk = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id != :id LIMIT 1');
        $stmtChk->execute([':e' => $email, ':id' => $id]);
        if ($stmtChk->fetch()) {
            $errors[] = 'That email address is already registered to another user.';
        }
    }

    // Password validation
    if (!$isEdit) {
        if ($password === '') {
            $errors[] = 'Password is required for new accounts.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Password confirmation does not match.';
        }
    } else {
        if ($password !== '') {
            if (strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters long.';
            } elseif ($password !== $confirm) {
                $errors[] = 'Password confirmation does not match.';
            }
        }
    }

    // Safeguards for self-editing
    if ($isSelf) {
        if ($status === 'inactive') {
            $errors[] = 'You cannot deactivate your own account.';
            $status = 'active';
        }
    }

    // Safeguards for last remaining active Super Admin
    if ($isEdit && $user['role'] === 'admin' && ($role !== 'admin' || $status === 'inactive')) {
        $activeAdminCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'"
        )->fetchColumn();

        if ($activeAdminCount <= 1) {
            $errors[] = 'Cannot remove Super Admin role or deactivate the only remaining active Super Administrator.';
        }
    }

    // Process permissions
    $finalPerms = [];
    if ($role === 'admin') {
        $finalPerms = ['*'];
    } else {
        // Only allow valid permission keys from catalog
        foreach ($submittedPerms as $p) {
            if (is_string($p) && in_array($p, $allPermKeys, true)) {
                $finalPerms[] = $p;
            }
        }
        $finalPerms = array_values(array_unique($finalPerms));
    }
    $permissionsJson = json_encode($finalPerms);

    // Avatar upload handling
    $avatarFile   = $user['avatar'];
    $avatarFileId = $user['avatar_file_id'];
    $uploadErr    = $_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE;
    $hasUpload    = !empty($_FILES['avatar']['name']) && $uploadErr !== UPLOAD_ERR_NO_FILE;

    if ($hasUpload && empty($errors)) {
        try {
            if (function_exists('storage')) {
                $stored = storage()->storeUploadedFile('avatar', $_FILES['avatar'], [
                    'owner_type' => 'user',
                    'owner_id'   => $id,
                    'visibility' => 'public',
                ]);
                $newAvatarFile   = $stored['storage_key'];
                $newAvatarFileId = $stored['id'];
            } else {
                $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    throw new RuntimeException('Only JPG, PNG, WEBP, and GIF images are allowed.');
                }
                $newName = 'avatar_' . uniqid() . '.' . $ext;
                $destDir = UPLOADS_PATH . '/avatars';
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0775, true);
                }
                if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $destDir . '/' . $newName)) {
                    throw new RuntimeException('Failed to save uploaded avatar file.');
                }
                $newAvatarFile = $newName;
                $newAvatarFileId = null;
            }

            // Cleanup old avatar
            if ($isEdit) {
                if (!empty($user['avatar_file_id']) && function_exists('storage')) {
                    try { storage()->deleteFile((int)$user['avatar_file_id']); } catch (Throwable $e) {}
                }
                if (!empty($user['avatar'])) {
                    $avatarsDir = UPLOADS_PATH . '/avatars';
                    if (file_exists($avatarsDir . '/' . $user['avatar'])) @unlink($avatarsDir . '/' . $user['avatar']);
                    if (file_exists(UPLOADS_PATH . '/' . $user['avatar'])) @unlink(UPLOADS_PATH . '/' . $user['avatar']);
                }
            }

            $avatarFile   = $newAvatarFile;
            $avatarFileId = $newAvatarFileId;
        } catch (Throwable $e) {
            $errors[] = 'Avatar upload failed: ' . $e->getMessage();
        }
    }

    // Persist to database if no errors
    if (empty($errors)) {
        if ($isEdit) {
            $sql = 'UPDATE users SET
                        name = :name,
                        email = :email,
                        phone = :phone,
                        bio = :bio,
                        role = :role,
                        permissions = :permissions,
                        status = :status,
                        avatar = :avatar,
                        avatar_file_id = :avatar_file_id,
                        updated_at = NOW()';

            $params = [
                ':name'           => $name,
                ':email'          => $email,
                ':phone'          => $phone !== '' ? $phone : null,
                ':bio'            => $bio !== '' ? $bio : null,
                ':role'           => $role,
                ':permissions'    => $permissionsJson,
                ':status'         => $status,
                ':avatar'         => $avatarFile,
                ':avatar_file_id' => $avatarFileId,
                ':id'             => $id,
            ];

            if ($password !== '') {
                $sql .= ', password = :password';
                $params[':password'] = password_hash($password, PASSWORD_BCRYPT);
            }

            $sql .= ' WHERE id = :id';
            $stmtUpd = $pdo->prepare($sql);
            $stmtUpd->execute($params);

            if ($isSelf) {
                refresh_user_session();
            }

            log_activity('user.update', "Updated admin user #{$id} ({$email}) with role '{$role}'");
            flash('success', "Admin user '{$name}' has been updated successfully.");
            admin_redirect('users.php');
        } else {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            $ins = $pdo->prepare('
                INSERT INTO users
                    (name, email, password, phone, bio, role, permissions, status, avatar, avatar_file_id, created_at, updated_at)
                VALUES
                    (:name, :email, :password, :phone, :bio, :role, :permissions, :status, :avatar, :avatar_file_id, NOW(), NOW())
            ');

            $ins->execute([
                ':name'           => $name,
                ':email'          => $email,
                ':password'       => $hashedPassword,
                ':phone'          => $phone !== '' ? $phone : null,
                ':bio'            => $bio !== '' ? $bio : null,
                ':role'           => $role,
                ':permissions'    => $permissionsJson,
                ':status'         => $status,
                ':avatar'         => $avatarFile,
                ':avatar_file_id' => $avatarFileId,
            ]);

            $newId = (int)$pdo->lastInsertId();
            log_activity('user.create', "Created new admin user #{$newId} ({$email}) with role '{$role}'");
            flash('success', "Admin user '{$name}' created successfully.");
            admin_redirect('users.php');
        }
    }

    // Keep submitted values in case of validation errors
    $user['name']        = $name;
    $user['email']       = $email;
    $user['phone']       = $phone;
    $user['bio']         = $bio;
    $user['role']        = $role;
    $user['status']      = $status;
    $user['permissions'] = $finalPerms;
}

// Prepare presets for JavaScript
$jsPresets = [];
foreach ($rolesCatalog as $rKey => $rData) {
    $jsPresets[$rKey] = $rData['permissions'];
}

include __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
  <div class="d-flex align-items-center gap-2 mb-2">
    <a href="<?= e(admin_url('users.php')) ?>" class="text-decoration-none text-muted small">
      <i class="bi bi-arrow-left me-1"></i> Back to Admin Users
    </a>
  </div>
  <h3 class="mb-1"><?= $isEdit ? 'Edit Admin User: ' . e($user['name']) : 'Create New Admin User' ?></h3>
  <p class="text-muted small mb-0">
    Configure user identity, assigned role preset, and fine-grained module permissions.
  </p>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong><i class="bi bi-exclamation-triangle-fill me-1"></i> Please correct the following errors:</strong>
    <ul class="mb-0 mt-2">
      <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
      <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="adminUserForm">
  <?= csrf_field() ?>

  <div class="row g-4">
    <!-- Main Column: Account Details & Permissions Matrix -->
    <div class="col-lg-8">

      <!-- Section 1: Basic Information -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 border-bottom">
          <h5 class="card-title mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-person-lines-fill text-primary"></i>
            <span>Account Details</span>
          </h5>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label for="name" class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
              <input type="text"
                     name="name"
                     id="name"
                     class="form-control"
                     required
                     maxlength="100"
                     placeholder="e.g. Jane Doe"
                     value="<?= e($user['name']) ?>">
            </div>

            <div class="col-md-6">
              <label for="email" class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
              <input type="email"
                     name="email"
                     id="email"
                     class="form-control"
                     required
                     maxlength="150"
                     placeholder="e.g. staff@example.com"
                     value="<?= e($user['email']) ?>">
            </div>

            <div class="col-md-6">
              <label for="phone" class="form-label fw-medium">Phone Number</label>
              <input type="text"
                     name="phone"
                     id="phone"
                     class="form-control"
                     maxlength="30"
                     placeholder="e.g. +880 1700 000000"
                     value="<?= e($user['phone'] ?? '') ?>">
            </div>

            <div class="col-md-6">
              <label for="status" class="form-label fw-medium">Account Status</label>
              <select name="status" id="status" class="form-select" <?= $isSelf ? 'disabled' : '' ?>>
                <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active (Can log in)</option>
                <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive (Access suspended)</option>
              </select>
              <?php if ($isSelf): ?>
                <input type="hidden" name="status" value="active">
                <div class="form-text text-muted">You cannot deactivate your own logged-in account.</div>
              <?php endif; ?>
            </div>

            <div class="col-12">
              <label for="bio" class="form-label fw-medium">Bio / Internal Staff Notes</label>
              <textarea name="bio"
                        id="bio"
                        rows="2"
                        class="form-control"
                        placeholder="e.g. Warehouse shift lead, customer satisfaction specialist..."><?= e($user['bio'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Section 2: Security & Credentials -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 border-bottom">
          <h5 class="card-title mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-key-fill text-primary"></i>
            <span>Security & Password</span>
          </h5>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label for="password" class="form-label fw-medium">
                <?= $isEdit ? 'New Password' : 'Password <span class="text-danger">*</span>' ?>
              </label>
              <input type="password"
                     name="password"
                     id="password"
                     class="form-control"
                     <?= $isEdit ? '' : 'required' ?>
                     minlength="6"
                     placeholder="<?= $isEdit ? 'Leave blank to keep unchanged' : 'Minimum 6 characters' ?>"
                     autocomplete="new-password">
              <?php if ($isEdit): ?>
                <div class="form-text text-muted">Leave empty if you don't want to change the password.</div>
              <?php endif; ?>
            </div>

            <div class="col-md-6">
              <label for="confirm_password" class="form-label fw-medium">
                <?= $isEdit ? 'Confirm New Password' : 'Confirm Password <span class="text-danger">*</span>' ?>
              </label>
              <input type="password"
                     name="confirm_password"
                     id="confirm_password"
                     class="form-control"
                     <?= $isEdit ? '' : 'required' ?>
                     minlength="6"
                     placeholder="Re-enter password"
                     autocomplete="new-password">
            </div>
          </div>
        </div>
      </div>

      <!-- Section 3: Role & Granular Permission Matrix -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
          <div>
            <h5 class="card-title mb-0 d-flex align-items-center gap-2">
              <i class="bi bi-shield-check text-primary"></i>
              <span>Role & Permissions Matrix</span>
            </h5>
            <small class="text-muted">Choose an administrative role preset or tailor custom individual permissions.</small>
          </div>
        </div>

        <div class="card-body">
          <!-- Role Selector -->
          <div class="mb-4">
            <label for="roleSelector" class="form-label fw-semibold">Administrative Role Preset</label>
            <select name="role" id="roleSelector" class="form-select form-select-lg">
              <?php foreach ($rolesCatalog as $rKey => $rMeta): ?>
                <option value="<?= e($rKey) ?>"
                        data-desc="<?= e($rMeta['description']) ?>"
                        <?= $user['role'] === $rKey ? 'selected' : '' ?>>
                  <?= e($rMeta['label']) ?> (<?= ucfirst($rKey) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <div id="roleDescription" class="form-text text-primary mt-2 fw-medium">
              <?= e($rolesCatalog[$user['role']]['description'] ?? '') ?>
            </div>
          </div>

          <!-- Quick Presets Toolbar -->
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-3 bg-light rounded-3 mb-4 border">
            <div class="small fw-semibold text-muted text-uppercase" style="letter-spacing: 0.5px;">
              <i class="bi bi-sliders me-1"></i> Quick Presets:
            </div>
            <div class="btn-group btn-group-sm flex-wrap" role="group">
              <button type="button" class="btn btn-outline-primary btn-preset" data-preset="admin">
                <i class="bi bi-shield-shaded me-1"></i> Super Admin
              </button>
              <button type="button" class="btn btn-outline-primary btn-preset" data-preset="manager">
                <i class="bi bi-briefcase me-1"></i> Store Manager
              </button>
              <button type="button" class="btn btn-outline-primary btn-preset" data-preset="editor">
                <i class="bi bi-pen me-1"></i> Content Editor
              </button>
              <button type="button" class="btn btn-outline-primary btn-preset" data-preset="support">
                <i class="bi bi-headset me-1"></i> Support
              </button>
              <button type="button" class="btn btn-outline-primary btn-preset" data-preset="analyst">
                <i class="bi bi-graph-up me-1"></i> Analyst
              </button>
              <button type="button" class="btn btn-outline-secondary btn-preset-clear">
                <i class="bi bi-x-circle me-1"></i> Clear All
              </button>
            </div>
          </div>

          <!-- Super Admin Notice Banner -->
          <div id="adminNoticeBanner" class="alert alert-primary d-flex align-items-center gap-3 mb-4 <?= $user['role'] === 'admin' ? '' : 'd-none' ?>">
            <i class="bi bi-info-circle-fill fs-3 flex-shrink-0"></i>
            <div>
              <strong>Super Administrator (Wildcard *) Access Active</strong>
              <div class="small mt-1">
                Super Admins automatically inherit unrestricted access to all operations, controllers, and administrative sections. All module permissions below are automatically granted.
              </div>
            </div>
          </div>

          <!-- Module Permissions Cards -->
          <div id="permissionsAccordion" class="d-flex flex-column gap-3">
            <?php
              $currentPerms = $user['permissions'];
              $isWildcardAdmin = ($user['role'] === 'admin' || in_array('*', $currentPerms, true));
            ?>

            <?php foreach ($permCatalog as $moduleKey => $module): ?>
              <?php
                $modulePermKeys = array_keys($module['permissions']);
                $totalModulePerms = count($modulePermKeys);
                $checkedCount = 0;
                foreach ($modulePermKeys as $pk) {
                    if ($isWildcardAdmin || in_array($pk, $currentPerms, true) || in_array($moduleKey . '.*', $currentPerms, true)) {
                        $checkedCount++;
                    }
                }
                $allChecked = ($checkedCount === $totalModulePerms);
              ?>
              <div class="permission-module-card border rounded-3 bg-white shadow-sm overflow-hidden" data-module="<?= e($moduleKey) ?>">
                <!-- Module Header -->
                <div class="permission-module-header d-flex align-items-center justify-content-between p-3 bg-light border-bottom">
                  <div class="d-flex align-items-center gap-2">
                    <i class="bi <?= e($module['icon']) ?> fs-5 text-primary"></i>
                    <span class="fw-bold"><?= e($module['label']) ?></span>
                    <span class="badge bg-secondary-subtle text-secondary module-count-badge" style="font-size: 0.72rem;">
                      <span class="checked-count"><?= $checkedCount ?></span>/<?= $totalModulePerms ?>
                    </span>
                  </div>
                  <div>
                    <div class="form-check form-check-inline m-0">
                      <input class="form-check-input module-select-all"
                             type="checkbox"
                             id="select_all_<?= e($moduleKey) ?>"
                             data-target-module="<?= e($moduleKey) ?>"
                             <?= $allChecked ? 'checked' : '' ?>
                             <?= $user['role'] === 'admin' ? 'disabled' : '' ?>>
                      <label class="form-check-label small fw-medium" for="select_all_<?= e($moduleKey) ?>">
                        Select All
                      </label>
                    </div>
                  </div>
                </div>

                <!-- Module Permission Items -->
                <div class="card-body p-3">
                  <div class="row g-2">
                    <?php foreach ($module['permissions'] as $permKey => $permMeta): ?>
                      <?php
                        $isChecked = $isWildcardAdmin || in_array($permKey, $currentPerms, true) || in_array($moduleKey . '.*', $currentPerms, true);
                      ?>
                      <div class="col-md-6">
                        <div class="permission-item h-100 border rounded p-2">
                          <div class="form-check m-0">
                            <input class="form-check-input perm-checkbox"
                                   type="checkbox"
                                   name="permissions[]"
                                   value="<?= e($permKey) ?>"
                                   id="perm_<?= e(str_replace('.', '_', $permKey)) ?>"
                                   data-module="<?= e($moduleKey) ?>"
                                   <?= $isChecked ? 'checked' : '' ?>
                                   <?= $user['role'] === 'admin' ? 'disabled' : '' ?>>
                            <label class="form-check-label w-100" for="perm_<?= e(str_replace('.', '_', $permKey)) ?>" style="cursor: pointer;">
                              <div class="fw-semibold small text-dark"><?= e($permMeta['label']) ?></div>
                              <div class="text-muted" style="font-size: 0.75rem; line-height: 1.25;">
                                <?= e($permMeta['description']) ?>
                              </div>
                            </label>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

        </div>
      </div>

      <!-- Action Buttons -->
      <div class="d-flex align-items-center gap-2 mb-5">
        <button type="submit" class="btn btn-primary px-4 py-2">
          <i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Update Admin User' : 'Create Admin User' ?>
        </button>
        <a href="<?= e(admin_url('users.php')) ?>" class="btn btn-outline-secondary px-4 py-2">
          Cancel
        </a>
      </div>

    </div>

    <!-- Right Sidebar Column: Avatar & Role Summary -->
    <div class="col-lg-4">

      <!-- Avatar Upload Card -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 border-bottom">
          <h5 class="card-title mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-image text-primary"></i>
            <span>Profile Photo</span>
          </h5>
        </div>
        <div class="card-body text-center">
          <div class="mb-3">
            <img src="<?= e(avatar_url($user['avatar'] ?? null, $user['name'] ?: 'Admin User')) ?>"
                 id="avatarPreview"
                 class="rounded-circle border shadow-sm"
                 width="110" height="110"
                 style="object-fit: cover;"
                 alt="Avatar Preview">
          </div>
          <div class="mb-2">
            <input type="file"
                   name="avatar"
                   id="avatarInput"
                   class="form-control form-control-sm"
                   accept="image/png, image/jpeg, image/webp, image/gif">
          </div>
          <div class="form-text text-muted small">
            Recommended: Square JPG, PNG or WEBP (max 2MB).
          </div>
        </div>
      </div>

      <!-- Role Reference Card -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 border-bottom">
          <h5 class="card-title mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-info-circle text-primary"></i>
            <span>Role Presets Guide</span>
          </h5>
        </div>
        <div class="card-body p-0">
          <div class="list-group list-group-flush small">
            <?php foreach ($rolesCatalog as $rk => $rMeta): ?>
              <div class="list-group-item p-3">
                <div class="d-flex align-items-center justify-content-between mb-1">
                  <span class="badge <?= e($rMeta['badge_class']) ?> text-uppercase px-2">
                    <?= e($rMeta['label']) ?>
                  </span>
                  <span class="text-muted font-monospace" style="font-size: 0.7rem;">
                    <?= $rk === 'admin' ? 'Wildcard (*)' : count($rMeta['permissions']) . ' perms' ?>
                  </span>
                </div>
                <div class="text-muted" style="font-size: 0.78rem;">
                  <?= e($rMeta['description']) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <?php if ($isEdit): ?>
        <!-- Account Metadata -->
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header bg-white py-3 border-bottom">
            <h5 class="card-title mb-0 d-flex align-items-center gap-2">
              <i class="bi bi-clock-history text-primary"></i>
              <span>System Metadata</span>
            </h5>
          </div>
          <div class="card-body small">
            <div class="d-flex justify-content-between py-1 border-bottom">
              <span class="text-muted">Account ID:</span>
              <span class="fw-semibold font-monospace">#<?= (int)$user['id'] ?></span>
            </div>
            <div class="d-flex justify-content-between py-1 border-bottom">
              <span class="text-muted">Created:</span>
              <span><?= date('M j, Y H:i', strtotime($user['created_at'])) ?></span>
            </div>
            <div class="d-flex justify-content-between py-1">
              <span class="text-muted">Last Updated:</span>
              <span><?= date('M j, Y H:i', strtotime($user['updated_at'])) ?></span>
            </div>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </div>
</form>

<script>
(function() {
  const ROLE_PRESETS = <?= json_encode($jsPresets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const roleSelect = document.getElementById('roleSelector');
  const roleDesc = document.getElementById('roleDescription');
  const adminBanner = document.getElementById('adminNoticeBanner');
  const checkboxes = Array.from(document.querySelectorAll('.perm-checkbox'));
  const moduleSelectAlls = Array.from(document.querySelectorAll('.module-select-all'));
  const avatarInput = document.getElementById('avatarInput');
  const avatarPreview = document.getElementById('avatarPreview');

  // Preview uploaded avatar
  if (avatarInput && avatarPreview) {
    avatarInput.addEventListener('change', function() {
      const file = this.files && this.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          avatarPreview.src = e.target.result;
        };
        reader.readAsDataURL(file);
      }
    });
  }

  // Update counter badges for each module
  function updateModuleCounters() {
    document.querySelectorAll('.permission-module-card').forEach(card => {
      const mod = card.getAttribute('data-module');
      const boxList = card.querySelectorAll('.perm-checkbox');
      const checked = card.querySelectorAll('.perm-checkbox:checked').length;
      const countSpan = card.querySelector('.checked-count');
      if (countSpan) countSpan.textContent = checked;

      const selectAll = card.querySelector('.module-select-all');
      if (selectAll) {
        selectAll.checked = (checked === boxList.length && boxList.length > 0);
      }
    });
  }

  // Apply a specific list of permissions to checkboxes
  function applyPermissionList(permList) {
    const isWildcard = permList.includes('*');

    checkboxes.forEach(cb => {
      if (isWildcard) {
        cb.checked = true;
      } else {
        cb.checked = permList.includes(cb.value);
      }
    });

    updateModuleCounters();
  }

  // Handle Role selection changes
  function onRoleChange() {
    const selectedRole = roleSelect.value;
    const selectedOption = roleSelect.options[roleSelect.selectedIndex];
    if (selectedOption && roleDesc) {
      roleDesc.textContent = selectedOption.getAttribute('data-desc') || '';
    }

    if (selectedRole === 'admin') {
      adminBanner.classList.remove('d-none');
      checkboxes.forEach(cb => {
        cb.checked = true;
        cb.disabled = true;
      });
      moduleSelectAlls.forEach(cb => {
        cb.checked = true;
        cb.disabled = true;
      });
    } else {
      adminBanner.classList.add('d-none');
      checkboxes.forEach(cb => cb.disabled = false);
      moduleSelectAlls.forEach(cb => cb.disabled = false);

      if (ROLE_PRESETS[selectedRole] && selectedRole !== 'custom') {
        applyPermissionList(ROLE_PRESETS[selectedRole]);
      }
    }

    updateModuleCounters();
  }

  if (roleSelect) {
    roleSelect.addEventListener('change', onRoleChange);
  }

  // Preset buttons click handler
  document.querySelectorAll('.btn-preset').forEach(btn => {
    btn.addEventListener('click', function() {
      const targetRole = this.getAttribute('data-preset');
      if (roleSelect) {
        roleSelect.value = targetRole;
        onRoleChange();
      }
    });
  });

  // Clear all button
  const clearBtn = document.querySelector('.btn-preset-clear');
  if (clearBtn) {
    clearBtn.addEventListener('click', function() {
      if (roleSelect) {
        roleSelect.value = 'custom';
        onRoleChange();
      }
      checkboxes.forEach(cb => {
        cb.checked = false;
        cb.disabled = false;
      });
      moduleSelectAlls.forEach(cb => {
        cb.checked = false;
        cb.disabled = false;
      });
      updateModuleCounters();
    });
  }

  // Module "Select All" toggle
  moduleSelectAlls.forEach(selectAll => {
    selectAll.addEventListener('change', function() {
      const targetMod = this.getAttribute('data-target-module');
      const modCheckboxes = document.querySelectorAll('.perm-checkbox[data-module="' + targetMod + '"]');
      modCheckboxes.forEach(cb => {
        if (!cb.disabled) {
          cb.checked = selectAll.checked;
        }
      });
      updateModuleCounters();
      if (roleSelect && roleSelect.value !== 'custom' && roleSelect.value !== 'admin') {
        roleSelect.value = 'custom';
        if (roleDesc) roleDesc.textContent = 'Custom granular permissions explicitly configured by an administrator.';
      }
    });
  });

  // Individual checkbox change
  checkboxes.forEach(cb => {
    cb.addEventListener('change', function() {
      updateModuleCounters();
      if (roleSelect && roleSelect.value !== 'custom' && roleSelect.value !== 'admin') {
        roleSelect.value = 'custom';
        if (roleDesc) roleDesc.textContent = 'Custom granular permissions explicitly configured by an administrator.';
      }
    });
  });

  // Ensure disabled state doesn't prevent form submission: re-enable inputs right before submit
  const form = document.getElementById('adminUserForm');
  if (form) {
    form.addEventListener('submit', function() {
      checkboxes.forEach(cb => {
        cb.disabled = false;
      });
    });
  }

  // Initial counter calculation on page load
  updateModuleCounters();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
