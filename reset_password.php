<?php
/**
 * Customer Reset Password Page (Prompt 12).
 * Verifies single-use selector.secret token without consuming on GET.
 * Consumes atomically on POST, invalidates all sessions/remember tokens.
 */
require_once __DIR__ . '/includes/shop_bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: no-referrer');

$rawToken = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$pageTitle = 'Set New Password';
$error = null;

// Validate token format & existence on GET/POST without consuming yet
$tokenData = !empty($rawToken) ? token_peek('password_reset', $rawToken) : null;
$tokenInvalid = empty($tokenData);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$tokenInvalid) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $error = 'Invalid or expired form session. Please try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['password_confirm'] ?? '');

        $res = customer_reset_password($rawToken, $password, $confirmPassword);

        if ($res['ok']) {
            flash('success', $res['message']);
            header('Location: ' . shop_url('login.php'));
            exit;
        } else {
            $error = $res['error'] ?? 'Failed to reset password. Please try again.';
            // If token became invalid during consume (e.g. reused concurrently)
            if (str_contains($error, 'invalid') || str_contains($error, 'expired') || str_contains($error, 'already been used')) {
                $tokenInvalid = true;
            }
        }
    }
}

include __DIR__ . '/includes/shop_header.php';
?>

<div class="auth-page-wrapper">
  <div class="auth-card">
    <?php if ($tokenInvalid): ?>
      <div class="text-center py-3">
        <div class="fs-1 text-warning mb-3">
          <i class="bi bi-shield-exclamation"></i>
        </div>
        <h1 class="auth-title mb-2">Invalid or Expired Link</h1>
        <p class="text-muted small mb-4">
          This password reset link is invalid, has expired, or has already been used.
          For your security, reset links are single-use and expire within 60 minutes.
        </p>
        <div class="d-grid gap-2">
          <a href="<?= e(shop_url('forgot_password.php')) ?>" class="btn btn-editorial-primary">
            Request New Reset Link &rarr;
          </a>
          <a href="<?= e(shop_url('login.php')) ?>" class="btn btn-outline-secondary btn-sm">
            Back to Sign In
          </a>
        </div>
      </div>
    <?php else: ?>
      <div class="auth-header text-center">
        <h1 class="auth-title">Set New Password</h1>
        <p class="auth-subtitle">Create a strong, unique password for your account.</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger small py-2 mb-3" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="reset_password.php" class="auth-form" novalidate id="resetPasswordForm">
        <?= csrf_field() ?>
        <input type="hidden" name="t" value="<?= e($rawToken) ?>">

        <!-- New Password -->
        <div class="form-group mb-3">
          <label for="resetPassword" class="form-label small fw-semibold">New Password</label>
          <div class="input-group">
            <input type="password"
                   name="password"
                   id="resetPassword"
                   class="form-control"
                   autocomplete="new-password"
                   required
                   placeholder="At least 8 characters">
            <button type="button" class="btn btn-outline-secondary btn-pw-toggle" data-toggle-password="resetPassword" aria-label="Toggle password visibility">
              <i class="bi bi-eye"></i>
            </button>
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

        <!-- Confirm Password -->
        <div class="form-group mb-4">
          <label for="resetPasswordConfirm" class="form-label small fw-semibold">Confirm New Password</label>
          <div class="input-group">
            <input type="password"
                   name="password_confirm"
                   id="resetPasswordConfirm"
                   class="form-control"
                   autocomplete="new-password"
                   required
                   placeholder="Repeat new password">
            <button type="button" class="btn btn-outline-secondary btn-pw-toggle" data-toggle-password="resetPasswordConfirm" aria-label="Toggle password visibility">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        <div class="d-grid gap-2 mb-3">
          <button type="submit" class="btn btn-editorial-primary" id="btnSubmitReset">
            Update Password &amp; Sign In
          </button>
        </div>

        <div class="text-center pt-2">
          <a href="<?= e(shop_url('login.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Return to Sign In
          </a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<script src="<?= e(shop_url('assets/js/auth.js')) ?>"></script>
<?php
include __DIR__ . '/includes/shop_footer.php';
