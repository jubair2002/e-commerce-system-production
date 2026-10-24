<?php
/**
 * Customer Reset Password Page (Prompt 12).
 * Verifies single-use selector.secret token without consuming on GET.
 * Consumes atomically on POST, invalidates all sessions/remember tokens.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

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
            header('Location: ' . route('login'));
            exit;
        } else {
            $error = $res['error'] ?? 'Failed to reset password. Please try again.';
            if (str_contains($error, 'invalid') || str_contains($error, 'expired') || str_contains($error, 'already been used')) {
                $tokenInvalid = true;
            }
        }
    }
}

include __DIR__ . '/../../includes/partials/header.php';
?>

<div class="auth-page-wrapper py-5 d-flex align-items-center justify-content-center" style="min-height: calc(100vh - 200px); background-color: var(--bg-soft);">
  <div class="container d-flex justify-content-center">
    <div class="auth-card card shadow-sm p-4 p-sm-5" style="max-width: 440px; width: 100%; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--line);">
      <?php if ($tokenInvalid): ?>
        <div class="text-center py-3">
          <div class="fs-1 text-warning mb-3">
            <i class="bi bi-shield-exclamation"></i>
          </div>
          <h1 class="h4 fw-bold mb-2">Invalid or Expired Link</h1>
          <p class="text-muted small mb-4">
            This password reset link is invalid, has expired, or has already been used.
            For your security, reset links are single-use and expire within 60 minutes.
          </p>
          <div class="d-grid gap-2">
            <a href="<?= e(route('forgot_password')) ?>" class="btn btn-primary">
              Request New Reset Link
            </a>
            <a href="<?= e(route('login')) ?>" class="btn btn-outline-secondary btn-sm">
              Back to Sign In
            </a>
          </div>
        </div>
      <?php else: ?>
        <div class="auth-header text-center mb-4">
          <h1 class="h3 fw-bold mb-2">Set New Password</h1>
          <p class="text-muted small">Create a strong, unique password for your account.</p>
        </div>

      <?php if ($error): ?>
        <div class="alert alert-danger small py-2 mb-3" role="alert">
          <?= icon('alert-circle', 'me-1') ?> <?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e(route('reset_password')) ?>" class="auth-form" novalidate id="resetPasswordForm">
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
              <?= icon('eye') ?>
            </button>
          </div>
          <!-- Live password strength meter -->
          <div class="password-meter-wrap mt-2">
            <div class="password-meter-bar" id="pwStrengthBar"></div>
          </div>
          <div class="form-text small text-muted">Use at least 8 characters with letters and numbers.</div>
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
              <?= icon('eye') ?>
            </button>
          </div>
        </div>

        <div class="d-grid gap-2 mb-3">
          <button type="submit" class="btn btn-editorial-primary py-2 fw-semibold" id="btnSubmitReset">
            Update Password &amp; Sign In
          </button>
        </div>

        <div class="text-center pt-2">
          <a href="<?= e(route('login')) ?>" class="btn btn-editorial-secondary btn-sm">
            <?= icon('arrow-left', 'me-1') ?> Return to Sign In
          </a>
        </div>
      </form>
    <?php endif; ?>
    </div>
  </div>
</div>

<script src="<?= e(url('assets/js/auth.js')) ?>"></script>

<?php
include __DIR__ . '/../../includes/partials/footer.php';
