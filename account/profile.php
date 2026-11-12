<?php
/**
 * Customer Profile Management (Prompt 13).
 * Edit profile, phone, marketing preferences, and secure avatar upload re-encoded with GD.
 */
require_once __DIR__ . '/_layout.php';

$customer = customer_require_login();
$cid = (int)$customer['id'];
$pdo = db();

// Fetch fresh record from DB
$stmtC = $pdo->prepare("SELECT * FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1");
$stmtC->execute([':id' => $cid]);
$freshCustomer = $stmtC->fetch();
if (!$freshCustomer) {
    customer_logout();
    header('Location: ' . shop_url('login.php'));
    exit;
}

$errors = [];
$name = $freshCustomer['name'];
$phone = $freshCustomer['phone'] ?? '';
$marketing = (int)$freshCustomer['marketing_opt_in'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $errors['general'] = 'Invalid or expired session. Please try again.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $marketing = !empty($_POST['marketing_opt_in']) ? 1 : 0;
        $removeAvatar = !empty($_POST['remove_avatar']);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = 'Name must be between 2 and 100 characters.';
        }

        if ($phone !== '') {
            $cleanedPhone = preg_replace('/[\s\-\(\)]/', '', $phone);
            if (!preg_match('/^(?:\+?88)?01[3-9]\d{8}$/', $cleanedPhone)) {
                $errors['phone'] = 'Please enter a valid Bangladeshi mobile number (e.g. 01712345678).';
            } else {
                $phone = $cleanedPhone;
            }
        }

        $newAvatarFilename = null;

        $newAvatarFilename = null;
        $newAvatarFileId   = null;

        // Avatar Upload Handling via StorageManager
        if (empty($errors) && !empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $stored = storage()->storeUploadedFile('avatar', $_FILES['avatar'], [
                    'owner_type' => 'customer',
                    'owner_id'   => $cid,
                    'visibility' => 'public',
                ]);
                $newAvatarFilename = $stored['storage_key'];
                $newAvatarFileId   = $stored['id'];

                // Clean up old avatar if exists
                if (!empty($freshCustomer['avatar_file_id'])) {
                    storage()->deleteFile((int)$freshCustomer['avatar_file_id']);
                }
                if (!empty($freshCustomer['avatar']) && file_exists(UPLOADS_PATH . '/avatars/' . $freshCustomer['avatar'])) {
                    @unlink(UPLOADS_PATH . '/avatars/' . $freshCustomer['avatar']);
                }
            } catch (Throwable $e) {
                $errors['avatar'] = $e->getMessage();
            }
        }

        // Handle Avatar Removal
        if ($removeAvatar && empty($newAvatarFilename)) {
            if (!empty($freshCustomer['avatar_file_id'])) {
                storage()->deleteFile((int)$freshCustomer['avatar_file_id']);
            }
            if (!empty($freshCustomer['avatar']) && file_exists(UPLOADS_PATH . '/avatars/' . $freshCustomer['avatar'])) {
                @unlink(UPLOADS_PATH . '/avatars/' . $freshCustomer['avatar']);
            }
            $newAvatarFilename = '';
            $newAvatarFileId   = null;
        }

        if (empty($errors)) {
            try {
                if ($newAvatarFilename !== null) {
                    $stmtUp = $pdo->prepare(
                        "UPDATE customers
                         SET name = :name, phone = :phone, marketing_opt_in = :mkt, avatar = :av, avatar_file_id = :avid, updated_at = NOW()
                         WHERE id = :id"
                    );
                    $stmtUp->execute([
                        ':name'  => $name,
                        ':phone' => $phone ?: null,
                        ':mkt'   => $marketing,
                        ':av'    => $newAvatarFilename ?: null,
                        ':avid'  => $newAvatarFileId,
                        ':id'    => $cid,
                    ]);
                    $_SESSION['customer']['avatar'] = $newAvatarFilename ?: null;
                } else {
                    $stmtUp = $pdo->prepare(
                        "UPDATE customers
                         SET name = :name, phone = :phone, marketing_opt_in = :mkt, updated_at = NOW()
                         WHERE id = :id"
                    );
                    $stmtUp->execute([
                        ':name'  => $name,
                        ':phone' => $phone ?: null,
                        ':mkt'   => $marketing,
                        ':id'    => $cid,
                    ]);
                }

                // Update session
                $_SESSION['customer']['name'] = $name;
                $_SESSION['customer']['phone'] = $phone ?: null;

                flash('success', 'Your profile has been updated successfully.');
                header('Location: ' . shop_url('account/profile.php'));
                exit;
            } catch (Throwable $e) {
                $errors['general'] = 'Failed to update profile. Please try again.';
            }
        }
    }
}

$avatarUrl = null;
if (!empty($freshCustomer['avatar'])) {
    $res = avatar_url($freshCustomer['avatar'], $freshCustomer['name']);
    if (!str_contains($res, 'ui-avatars.com')) {
        $avatarUrl = $res;
    }
}
$initials = strtoupper(mb_substr($freshCustomer['name'], 0, 1));

account_layout_start('profile', 'Edit Profile', $freshCustomer);
?>

<div class="account-card">
  <div class="account-card-header">
    <h2 class="account-card-title">Profile Information</h2>
  </div>

  <?php if (!empty($errors['general'])): ?>
    <div class="alert alert-danger small py-2 mb-3">
      <?= icon('alert-circle', 'i-sm me-1') ?> <?= e($errors['general']) ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" action="profile.php" class="auth-form" novalidate>
    <?= csrf_field() ?>

    <!-- Avatar Upload & Preview -->
    <div class="d-flex align-items-center gap-4 mb-4 pb-3 border-bottom">
      <div class="avatar-circle" style="width: 72px; height: 72px; font-size: 26px;">
        <?php if ($avatarUrl): ?>
          <img src="<?= e($avatarUrl) ?>" alt="<?= e($freshCustomer['name']) ?>">
        <?php else: ?>
          <span><?= e($initials) ?></span>
        <?php endif; ?>
      </div>
      <div>
        <label for="avatarInput" class="form-label small fw-semibold mb-1">Profile Photo</label>
        <input type="file" name="avatar" id="avatarInput" class="form-control form-control-sm <?= isset($errors['avatar']) ? 'is-invalid' : '' ?>" accept="image/jpeg,image/png,image/webp">
        <div class="form-text" style="font-size: 0.75rem;">JPG, PNG, or WEBP &bull; Max 2 MB &bull; Square cropped automatically</div>
        <?php if (isset($errors['avatar'])): ?>
          <div class="invalid-feedback"><?= e($errors['avatar']) ?></div>
        <?php endif; ?>

        <?php if ($avatarUrl): ?>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="remove_avatar" value="1" id="removeAvatarCheck">
            <label class="form-check-label small text-danger" for="removeAvatarCheck">
              Remove custom photo and use initials
            </label>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Name & Phone -->
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label for="profName" class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
        <input type="text"
               name="name"
               id="profName"
               class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
               value="<?= e($name) ?>"
               required
               autocomplete="name">
        <?php if (isset($errors['name'])): ?>
          <div class="invalid-feedback"><?= e($errors['name']) ?></div>
        <?php endif; ?>
      </div>

      <div class="col-md-6">
        <label for="profPhone" class="form-label small fw-semibold">Mobile Phone (BD)</label>
        <input type="tel"
               name="phone"
               id="profPhone"
               class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
               value="<?= e($phone) ?>"
               placeholder="01712345678"
               autocomplete="tel">
        <?php if (isset($errors['phone'])): ?>
          <div class="invalid-feedback"><?= e($errors['phone']) ?></div>
        <?php endif; ?>
        <div class="form-text" style="font-size: 0.75rem;">Used for parcel delivery notifications.</div>
      </div>
    </div>

    <!-- Email Address (Read-only with link to Security) -->
    <div class="form-group mb-3">
      <label class="form-label small fw-semibold">Email Address</label>
      <div class="input-group">
        <input type="email" class="form-control bg-light" value="<?= e($freshCustomer['email']) ?>" readonly>
        <a href="<?= e(shop_url('account/security.php#email')) ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center">
          Change Email
        </a>
      </div>
      <div class="form-text" style="font-size: 0.75rem;">
        Email changes require password verification in the Security tab.
      </div>
    </div>

    <!-- Marketing Preferences -->
    <div class="form-check mb-4 pt-2">
      <input class="form-check-input" type="checkbox" name="marketing_opt_in" value="1" id="mktOptIn" <?= $marketing ? 'checked' : '' ?>>
      <label class="form-check-label small" for="mktOptIn">
        Receive exclusive offers, new product announcements, and seasonal discount codes via email.
      </label>
    </div>

    <!-- Metadata Section -->
    <div class="p-3 bg-light rounded border mb-4 text-muted small">
      <div class="row g-2">
        <div class="col-sm-6 d-flex align-items-center gap-1">
          <?= icon('box', 'i-sm text-muted') ?> Member since: <strong><?= date('F j, Y', strtotime($freshCustomer['created_at'])) ?></strong>
        </div>
        <div class="col-sm-6 d-flex align-items-center gap-1">
          <?= icon('rotate-ccw', 'i-sm text-muted') ?> Last sign in: <strong><?= !empty($freshCustomer['last_login_at']) ? date('M j, Y, g:i a', strtotime($freshCustomer['last_login_at'])) : 'Recent session' ?></strong>
        </div>
      </div>
    </div>

    <div>
      <button type="submit" class="btn btn-editorial-primary">
        Save Profile Changes
      </button>
    </div>
  </form>
</div>

<?php
account_layout_end();
