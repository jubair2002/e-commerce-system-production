<?php
/**
 * Customer Forgot Password Page (Prompt 12).
 * Anti-enumeration: uniform message & equalized timing whether email exists or not.
 */
require_once __DIR__ . '/includes/shop_bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (customer_current()) {
    header('Location: ' . shop_url('account/'));
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

include __DIR__ . '/includes/shop_header.php';
?>

<div class="auth-page-wrapper">
  <div class="auth-card">
    <div class="auth-header text-center">
      <h1 class="auth-title">Reset Password</h1>
      <p class="auth-subtitle">Enter your email address and we will send you a link to reset your password.</p>
    </div>

    <?php if ($successMessage): ?>
      <div class="alert alert-success text-center mb-4">
        <div class="fs-4 mb-2"><i class="bi bi-envelope-check"></i></div>
        <h6 class="fw-bold mb-1">Check Your Email</h6>
        <p class="small mb-0"><?= e($successMessage) ?></p>
      </div>
      <div class="d-grid gap-2">
        <a href="<?= e(shop_url('login.php')) ?>" class="btn btn-editorial-primary">
          Back to Sign In &rarr;
        </a>
      </div>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="alert alert-danger small py-2 mb-3" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="forgot_password.php" class="auth-form" novalidate id="forgotForm">
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
          <button type="submit" class="btn btn-editorial-primary">
            Send Reset Link
          </button>
        </div>

        <div class="text-center pt-2">
          <span class="small text-muted">Remember your password?</span>
          <a href="<?= e(shop_url('login.php')) ?>" class="small fw-semibold ms-1 text-decoration-none text-dark">
            Sign In &rarr;
          </a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php
include __DIR__ . '/includes/shop_footer.php';
