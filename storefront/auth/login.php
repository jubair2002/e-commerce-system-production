<?php
/**
 * Customer Sign In Page (Prompt 12).
 * Constant-time authentication, rate-limiting, account lockout protection.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');

$next = customer_url_next($_GET['next'] ?? $_POST['next'] ?? '');
$targetUrl = ($next === 'account/' || empty($next)) ? route('account') : $next;

if (customer_current()) {
    header('Location: ' . $targetUrl);
    exit;
}

$pageTitle = 'Sign In';
$error = null;
$unverifiedWarning = false;

$email = trim((string)($_GET['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $error = 'Invalid or expired session. Please try again.';
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $remember = !empty($_POST['remember_me']);

        $res = customer_login($email, $password, $remember);

        if ($res['ok']) {
            $addWishlistPid = (int)($_POST['add_wishlist'] ?? ($_GET['add_wishlist'] ?? 0));
            if ($addWishlistPid > 0 && !empty($res['customer']['id'])) {
                try {
                    db()->prepare("INSERT IGNORE INTO wishlists (customer_id, product_id, created_at) VALUES (:cid, :pid, NOW())")
                        ->execute([':cid' => (int)$res['customer']['id'], ':pid' => $addWishlistPid]);
                    flash('success', 'Item has been added to your wishlist!');
                } catch (Throwable $e) {}
            }

            if (empty($res['verified'])) {
                flash('warning', 'Please check your email to verify your account for full access.');
            } else {
                flash('success', 'Welcome back, ' . htmlspecialchars($res['customer']['name'], ENT_QUOTES, 'UTF-8') . '!');
            }
            header('Location: ' . $targetUrl);
            exit;
        } else {
            $error = $res['error'] ?? 'Email or password is incorrect.';
        }
    }
}

$guestCheckout = (int)setting('guest_checkout', '1') === 1;

include __DIR__ . '/../../includes/partials/header.php';
?>

<div class="auth-split-wrapper">
  <!-- Brand Side Panel (Desktop only) -->
  <div class="auth-brand-panel">
    <div>
      <div class="d-inline-flex align-items-center gap-2 mb-4">
        <h2 class="auth-brand-title fw-bold text-dark mb-0"><?= e(setting('site_name', APP_NAME)) ?></h2>
      </div>
      <p class="auth-brand-subtitle text-muted mb-5">
        Experience a faster, more personal way to shop. Manage your orders, save multiple delivery addresses, and track real-time delivery status.
      </p>

      <div class="d-flex flex-column gap-4">
        <div class="d-flex align-items-start gap-3">
          <div class="trust-icon bg-white shadow-sm text-primary">
            <svg class="icon"><use href="<?= e(url('assets/img/icons.svg#truck')) ?>"></use></svg>
          </div>
          <div>
            <div class="fw-semibold text-dark">Real-Time Tracking</div>
            <div class="small text-muted">Follow your parcels step-by-step from dispatch to your door.</div>
          </div>
        </div>

        <div class="d-flex align-items-start gap-3">
          <div class="trust-icon bg-white shadow-sm text-primary">
            <svg class="icon"><use href="<?= e(url('assets/img/icons.svg#shield')) ?>"></use></svg>
          </div>
          <div>
            <div class="fw-semibold text-dark">100% Cash on Delivery</div>
            <div class="small text-muted">Pay comfortably with cash only when your order is in your hands.</div>
          </div>
        </div>

        <div class="d-flex align-items-start gap-3">
          <div class="trust-icon bg-white shadow-sm text-primary">
            <svg class="icon"><use href="<?= e(url('assets/img/icons.svg#heart')) ?>"></use></svg>
          </div>
          <div>
            <div class="fw-semibold text-dark">Wishlist &amp; Save for Later</div>
            <div class="small text-muted">Keep your favourite products close and receive stock restock alerts.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="small text-muted pt-4 border-top">
      &copy; <?= date('Y') ?> <?= e(setting('site_name', APP_NAME)) ?>. All rights reserved.
    </div>
  </div>

  <!-- Form Panel -->
  <div class="auth-form-panel">
    <div class="auth-card">
      <div class="auth-header text-center mb-4">
        <h1 class="h3 fw-bold text-dark mb-2">Welcome Back</h1>
        <p class="text-muted small">Sign in to your account to continue.</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger small py-2 mb-3" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e(route('login')) ?><?= ($next !== 'account/' && $next !== '') ? '?next=' . urlencode($next) : '' ?>" class="auth-form" novalidate id="loginForm">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <input type="hidden" name="add_wishlist" value="<?= (int)($_GET['add_wishlist'] ?? ($_POST['add_wishlist'] ?? 0)) ?>">

        <!-- Email Address -->
        <div class="form-group mb-3">
          <label for="loginEmail" class="form-label small fw-semibold">Email Address</label>
          <input type="email"
                 name="email"
                 id="loginEmail"
                 class="form-control"
                 value="<?= e($email) ?>"
                 autocomplete="email"
                 inputmode="email"
                 required
                 placeholder="you@example.com">
        </div>

        <!-- Password -->
        <div class="form-group mb-3">
          <div class="d-flex align-items-center justify-content-between">
            <label for="loginPassword" class="form-label small fw-semibold mb-1">Password</label>
            <a href="<?= e(route('forgot_password')) ?>" class="small text-muted text-decoration-underline">
              Forgot password?
            </a>
          </div>
          <div class="input-group">
            <input type="password"
                   name="password"
                   id="loginPassword"
                   class="form-control"
                   autocomplete="current-password"
                   required
                   placeholder="Enter your password">
            <button type="button" class="btn btn-outline-secondary btn-pw-toggle" data-toggle-password="loginPassword" aria-label="Toggle password visibility">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        <!-- Remember Me -->
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="remember_me" value="1" id="rememberMe" checked>
          <label class="form-check-label small text-muted" for="rememberMe">
            Keep me signed in for 30 days
          </label>
        </div>

        <div class="d-grid mt-4">
          <button type="submit" class="btn btn-primary py-2 fw-semibold" id="btnSubmitLogin">
            Sign In
          </button>
        </div>

        <?php if ($guestCheckout && str_contains($next, 'checkout')): ?>
          <div class="text-center mt-3">
            <a href="<?= e(route('checkout')) ?>" class="small text-muted text-decoration-underline">
              Continue as Guest &rarr;
            </a>
          </div>
        <?php endif; ?>
      </form>

      <div class="auth-footer text-center mt-4 pt-3 border-top">
        <span class="small text-muted">Don't have an account?</span>
        <a href="<?= e(route('register', ($next !== 'account/' && $next !== '') ? ['next' => $next] : [])) ?>" class="small fw-semibold text-primary ms-1">
          Create Account &rarr;
        </a>
      </div>
    </div>
  </div>
</div>

<script src="<?= e(url('assets/js/auth.js')) ?>"></script>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
