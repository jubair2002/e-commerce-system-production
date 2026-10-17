<?php
/**
 * Customer Account Security Management (Prompt 13).
 * Change password, request email change, review signed-in devices, and privacy options.
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

$pwErrors = [];
$emailErrors = [];
$deviceMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        flash('danger', 'Invalid or expired session. Please try again.');
        header('Location: ' . shop_url('account/security.php'));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Change Password Action
    if ($action === 'change_password') {
        $currentPass = (string)($_POST['current_password'] ?? '');
        $newPass     = (string)($_POST['new_password'] ?? '');
        $confirmPass = (string)($_POST['new_password_confirm'] ?? '');

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (auth_rate_limited('login', $freshCustomer['email'], $ip)) {
            $pwErrors['general'] = 'Too many attempts. Please wait a few minutes before trying again.';
        } elseif (!customer_password_verify($currentPass, $freshCustomer['password_hash'])) {
            $pwErrors['current_password'] = 'The current password you entered is incorrect.';
        } elseif ($newPass !== $confirmPass) {
            $pwErrors['new_password_confirm'] = 'New passwords do not match.';
        } else {
            $err = password_ok($newPass, $freshCustomer['email'], $freshCustomer['name']);
            if ($err !== null) {
                $pwErrors['new_password'] = $err;
            } else {
                $newHash = customer_password_hash($newPass);
                $stmtUp = $pdo->prepare("UPDATE customers SET password_hash = :h, updated_at = NOW() WHERE id = :id");
                $stmtUp->execute([':h' => $newHash, ':id' => $cid]);

                // Invalidate all other remember-me tokens
                token_revoke_all($cid, 'remember_me');

                // Update current session's pwd_stamp so this session remains logged in
                $newStamp = md5($newHash);
                $_SESSION['customer']['pwd_stamp'] = $newStamp;

                // Queue password changed security notification
                mail_queue_template('customer_password_changed', $freshCustomer['email'], $freshCustomer['name'], [
                    'customer_name' => $freshCustomer['name'],
                    'ip'            => $ip,
                    'changed_at'    => date('Y-m-d H:i:s'),
                    'reset_url'     => shop_url('forgot_password.php'),
                    'site_name'     => setting('site_name', 'Store'),
                ]);

                flash('success', 'Your password has been changed successfully. Other devices have been signed out.');
                header('Location: ' . shop_url('account/security.php'));
                exit;
            }
        }
    }

    // 2. Change Email Action
    elseif ($action === 'change_email') {
        $currentPass = (string)($_POST['email_change_password'] ?? '');
        $newEmail    = strtolower(trim((string)($_POST['new_email'] ?? '')));

        if (!customer_password_verify($currentPass, $freshCustomer['password_hash'])) {
            $emailErrors['email_change_password'] = 'Password confirmation is required to change your email.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $emailErrors['new_email'] = 'Please enter a valid email address.';
        } elseif ($newEmail === strtolower($freshCustomer['email'])) {
            $emailErrors['new_email'] = 'The new email must be different from your current email.';
        } else {
            $stmtEx = $pdo->prepare("SELECT id FROM customers WHERE email = :e AND id != :cid LIMIT 1");
            $stmtEx->execute([':e' => $newEmail, ':cid' => $cid]);
            if ($stmtEx->fetch()) {
                $emailErrors['new_email'] = 'This email address is already associated with another account.';
            } else {
                // Issue single-use email_change token (TTL 1 hour)
                token_revoke_all($cid, 'email_change');
                $token = token_issue($cid, 'email_change', 3600, $newEmail);
                $verifyUrl = shop_url('verify_email.php?t=' . urlencode($token));

                // Send confirmation to NEW address
                mail_queue_template('customer_email_change', $newEmail, $freshCustomer['name'], [
                    'customer_name' => $freshCustomer['name'],
                    'verify_url'    => $verifyUrl,
                    'site_name'     => setting('site_name', 'Store'),
                ]);

                flash('info', 'A confirmation link has been sent to ' . htmlspecialchars($newEmail, ENT_QUOTES, 'UTF-8') . '. Please check your inbox and click the link to confirm this change.');
                header('Location: ' . shop_url('account/security.php'));
                exit;
            }
        }
    }

    // 3. Revoke Single Device Action
    elseif ($action === 'revoke_device') {
        $tokenId = (int)($_POST['token_id'] ?? 0);
        if ($tokenId > 0) {
            $pdo->prepare("DELETE FROM customer_tokens WHERE id = :tid AND customer_id = :cid AND type = 'remember_me'")
                ->execute([':tid' => $tokenId, ':cid' => $cid]);
            flash('success', 'Device signed out successfully.');
            header('Location: ' . shop_url('account/security.php#devices'));
            exit;
        }
    }

    // 4. Revoke All Devices Action
    elseif ($action === 'revoke_all_devices') {
        token_revoke_all($cid, 'remember_me');
        remember_me_cookie_clear();
        flash('success', 'All other signed-in devices have been logged out.');
        header('Location: ' . shop_url('account/security.php#devices'));
        exit;
    }
}

// Fetch active remember-me devices
$devices = [];
try {
    $stmtDev = $pdo->prepare(
        "SELECT id, ip, user_agent, created_at, expires_at
         FROM customer_tokens
         WHERE customer_id = :cid AND type = 'remember_me' AND expires_at > NOW() AND used_at IS NULL
         ORDER BY id DESC"
    );
    $stmtDev->execute([':cid' => $cid]);
    $devices = $stmtDev->fetchAll();
} catch (Throwable $e) {
    $devices = [];
}

$allowDelete = (int)setting('customer_allow_account_delete', '1') === 1;

account_layout_start('security', 'Account Security', $freshCustomer);
?>

<!-- 1. Change Password Section -->
<div class="account-card" id="password">
  <div class="account-card-header">
    <h2 class="account-card-title"><i class="bi bi-key me-2 text-primary"></i> Change Password</h2>
  </div>

  <?php if (!empty($pwErrors['general'])): ?>
    <div class="alert alert-danger small py-2 mb-3">
      <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($pwErrors['general']) ?>
    </div>
  <?php endif; ?>

  <form method="post" action="security.php" class="auth-form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">

    <div class="form-group mb-3">
      <label for="currentPassword" class="form-label small fw-semibold">Current Password <span class="text-danger">*</span></label>
      <div class="input-group">
        <input type="password"
               name="current_password"
               id="currentPassword"
               class="form-control <?= isset($pwErrors['current_password']) ? 'is-invalid' : '' ?>"
               required
               autocomplete="current-password">
        <button type="button" class="btn btn-outline-secondary btn-pw-toggle" data-toggle-password="currentPassword" aria-label="Toggle password visibility">
          <i class="bi bi-eye"></i>
        </button>
        <?php if (isset($pwErrors['current_password'])): ?>
          <div class="invalid-feedback"><?= e($pwErrors['current_password']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label for="resetPassword" class="form-label small fw-semibold">New Password <span class="text-danger">*</span></label>
        <div class="input-group">
          <input type="password"
                 name="new_password"
                 id="resetPassword"
                 class="form-control <?= isset($pwErrors['new_password']) ? 'is-invalid' : '' ?>"
                 required
                 autocomplete="new-password">
          <button type="button" class="btn btn-outline-secondary btn-pw-toggle" data-toggle-password="resetPassword" aria-label="Toggle password visibility">
            <i class="bi bi-eye"></i>
          </button>
          <?php if (isset($pwErrors['new_password'])): ?>
            <div class="invalid-feedback"><?= e($pwErrors['new_password']) ?></div>
          <?php endif; ?>
        </div>
        <!-- Live password strength meter -->
        <div class="password-meter-wrapper mt-2">
          <div class="progress" style="height: 4px;">
            <div class="progress-bar password-meter-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
          </div>
          <div class="d-flex justify-content-between align-items-center mt-1">
            <span class="password-meter-text small text-muted" style="font-size: 0.75rem;">Password strength</span>
            <span class="password-rules small text-muted" style="font-size: 0.75rem;">Min. 8 chars</span>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <label for="newPasswordConfirm" class="form-label small fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
        <div class="input-group">
          <input type="password"
                 name="new_password_confirm"
                 id="newPasswordConfirm"
                 class="form-control <?= isset($pwErrors['new_password_confirm']) ? 'is-invalid' : '' ?>"
                 required
                 autocomplete="new-password">
          <button type="button" class="btn btn-outline-secondary btn-pw-toggle" data-toggle-password="newPasswordConfirm" aria-label="Toggle password visibility">
            <i class="bi bi-eye"></i>
          </button>
          <?php if (isset($pwErrors['new_password_confirm'])): ?>
            <div class="invalid-feedback"><?= e($pwErrors['new_password_confirm']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div>
      <button type="submit" class="btn btn-editorial-primary btn-sm">
        Update Password
      </button>
    </div>
  </form>
</div>

<!-- 2. Change Email Section -->
<div class="account-card" id="email">
  <div class="account-card-header">
    <h2 class="account-card-title"><i class="bi bi-envelope me-2 text-primary"></i> Change Email Address</h2>
  </div>

  <p class="text-muted small mb-3">
    Your current primary email is <strong><?= e($freshCustomer['email']) ?></strong>.
    When changing your email, a confirmation link will be sent to the new address and will only become active once confirmed.
  </p>

  <form method="post" action="security.php" class="auth-form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_email">

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label for="newEmailInput" class="form-label small fw-semibold">New Email Address <span class="text-danger">*</span></label>
        <input type="email"
               name="new_email"
               id="newEmailInput"
               class="form-control <?= isset($emailErrors['new_email']) ? 'is-invalid' : '' ?>"
               required
               autocomplete="email"
               placeholder="new-address@example.com">
        <?php if (isset($emailErrors['new_email'])): ?>
          <div class="invalid-feedback"><?= e($emailErrors['new_email']) ?></div>
        <?php endif; ?>
      </div>

      <div class="col-md-6">
        <label for="emailPassInput" class="form-label small fw-semibold">Confirm Password <span class="text-danger">*</span></label>
        <div class="input-group">
          <input type="password"
                 name="email_change_password"
                 id="emailPassInput"
                 class="form-control <?= isset($emailErrors['email_change_password']) ? 'is-invalid' : '' ?>"
                 required
                 autocomplete="current-password"
                 placeholder="Your current password">
          <button type="button" class="btn btn-outline-secondary btn-pw-toggle" data-toggle-password="emailPassInput" aria-label="Toggle password visibility">
            <i class="bi bi-eye"></i>
          </button>
          <?php if (isset($emailErrors['email_change_password'])): ?>
            <div class="invalid-feedback"><?= e($emailErrors['email_change_password']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div>
      <button type="submit" class="btn btn-outline-dark btn-sm">
        Send Email Change Confirmation
      </button>
    </div>
  </form>
</div>

<!-- 3. Signed-in Devices Section -->
<div class="account-card" id="devices">
  <div class="account-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h2 class="account-card-title"><i class="bi bi-laptop me-2 text-primary"></i> Signed-in Devices</h2>
    <?php if (!empty($devices)): ?>
      <form method="post" action="security.php" class="m-0" onsubmit="return confirm('Sign out all other devices?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="revoke_all_devices">
        <button type="submit" class="btn btn-outline-danger btn-sm">
          <i class="bi bi-box-arrow-right me-1"></i> Sign Out Everywhere
        </button>
      </form>
    <?php endif; ?>
  </div>

  <p class="text-muted small mb-3">These are browser sessions that used "Remember Me" to stay signed in to your account.</p>

  <?php if (empty($devices)): ?>
    <div class="p-3 bg-light rounded text-center small text-muted">
      No other persistent browser sessions active.
    </div>
  <?php else: ?>
    <div class="list-group list-group-flush border rounded">
      <?php foreach ($devices as $dev): ?>
        <?php
        $ua = $dev['user_agent'] ?: 'Web Browser';
        $browserSnippet = 'Unknown Device';
        if (str_contains($ua, 'Chrome')) $browserSnippet = 'Google Chrome';
        elseif (str_contains($ua, 'Safari')) $browserSnippet = 'Apple Safari';
        elseif (str_contains($ua, 'Firefox')) $browserSnippet = 'Mozilla Firefox';
        elseif (str_contains($ua, 'Edge')) $browserSnippet = 'Microsoft Edge';
        ?>
        <div class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
          <div class="d-flex align-items-center gap-3">
            <div class="fs-3 text-secondary"><i class="bi bi-display"></i></div>
            <div>
              <div class="fw-semibold small"><?= e($browserSnippet) ?></div>
              <div class="text-muted small" style="font-size: 0.78rem;">
                IP: <?= e($dev['ip']) ?> &bull; Active since: <?= date('M j, Y', strtotime($dev['created_at'])) ?>
              </div>
            </div>
          </div>
          <form method="post" action="security.php" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="revoke_device">
            <input type="hidden" name="token_id" value="<?= (int)$dev['id'] ?>">
            <button type="submit" class="btn btn-light btn-sm border text-danger">
              Revoke Access
            </button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- 4. Privacy & Data Rights Section -->
<div class="account-card">
  <div class="account-card-header">
    <h2 class="account-card-title"><i class="bi bi-shield me-2 text-primary"></i> Privacy &amp; Data Rights</h2>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="p-3 border rounded h-100 d-flex flex-column justify-content-between">
        <div>
          <h6 class="fw-bold mb-1"><i class="bi bi-download me-1 text-primary"></i> Download My Data</h6>
          <p class="text-muted small mb-3">Export all your personal data, past order histories, saved addresses, and wishlist items in standard JSON format.</p>
        </div>
        <div>
          <a href="<?= e(shop_url('account/export.php')) ?>" class="btn btn-outline-dark btn-sm">
            Export Data (JSON) &rarr;
          </a>
        </div>
      </div>
    </div>

    <?php if ($allowDelete): ?>
      <div class="col-md-6">
        <div class="p-3 border border-danger border-opacity-25 rounded bg-danger bg-opacity-10 h-100 d-flex flex-column justify-content-between">
          <div>
            <h6 class="fw-bold text-danger mb-1"><i class="bi bi-trash3 me-1"></i> Delete Account</h6>
            <p class="text-muted small mb-3">Permanently close and anonymize your account and personal details. Past completed orders are retained anonymously for accounting records.</p>
          </div>
          <div>
            <a href="<?= e(shop_url('account/delete.php')) ?>" class="btn btn-danger btn-sm">
              Delete Account &rarr;
            </a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="<?= e(shop_url('assets/js/auth.js')) ?>"></script>
<?php
account_layout_end();
