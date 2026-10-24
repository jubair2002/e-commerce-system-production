<?php
/**
 * Customer Forgot Password Page (Prompt 12).
 * Anti-enumeration: uniform message & equalized timing whether email exists or not.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (customer_current()) {
    header('Location: ' . route('account'));
    exit;
}

$pageTitle = 'Forgot Password';
$error = null;
$successMessage = null;
$email = trim((string)($_GET['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $error = 'Invalid or expired session. Please try again.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $res = customer_forgot_password($email);

        if ($res['ok']) {
            $successMessage = $res['message'];
        } else {
            $error = $res['error'] ?? 'An error occurred. Please try again.';
        }
    }
}

include __DIR__ . '/../../includes/partials/header.php';
?>

<div class="auth-page-wrapper py-5 d-flex align-items-center justify-content-center" style="min-height: calc(100vh - 200px); background-color: var(--bg-soft);">
  <div class="container d-flex justify-content-center">
    <div class="auth-card card shadow-sm p-4 p-sm-5" style="max-width: 440px; width: 100%; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--line);">
      <div class="auth-header text-center mb-4">
        <h1 class="h3 fw-bold mb-2">Reset Password</h1>
        <p class="text-muted small">Enter your email address and we will send you a link to reset your password.</p>
      </div>

    <?php if ($successMessage): ?>
      <div class="alert alert-success text-center mb-4">
        <div class="fs-4 mb-2"><?= icon('mail', 'text-success') ?></div>
        <h6 class="fw-bold mb-1">Check Your Email</h6>
        <p class="small mb-0"><?= e($successMessage) ?></p>
      </div>
      <div class="d-grid gap-2">
        <a href="<?= e(route('login')) ?>" class="btn btn-editorial-primary">
          Back to Sign In
        </a>
      </div>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="alert alert-danger small py-2 mb-3" role="alert">
          <?= icon('alert-circle', 'me-1') ?> <?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e(route('forgot_password')) ?>" class="auth-form" novalidate id="forgotForm">
        <?= csrf_field() ?>

        <div class="form-group mb-3">
          <label for="forgotEmail" class="form-label small fw-semibold">Email Address</label>
          <input type="email"
                 name="email"
                 id="forgotEmail"
                 class="form-control"
                 value="<?= e($email) ?>"
                 autocomplete="email"
                 inputmode="email"
                 required
                 placeholder="you@example.com">
        </div>

        <div class="d-grid gap-2 mb-3">
          <button type="submit" class="btn btn-editorial-primary py-2 fw-semibold">
            Send Reset Link
          </button>
        </div>

        <div class="text-center pt-2">
          <span class="small text-muted">Remember your password?</span>
          <a href="<?= e(route('login')) ?>" class="small fw-semibold ms-1 text-decoration-none text-primary">
            Sign In
          </a>
        </div>
      </form>
    <?php endif; ?>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../includes/partials/footer.php';
