<?php
/**
 * Customer Sign In Page (Prompt 12).
 * Constant-time authentication, rate-limiting, account lockout protection.
 */
require_once __DIR__ . '/includes/shop_bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');

$next = customer_url_next($_GET['next'] ?? $_POST['next'] ?? '');
$targetUrl = ($next === 'account/' || empty($next)) ? shop_url('account/') : $next;

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

include __DIR__ . '/includes/shop_header.php';
?>

<div class="auth-page-wrapper">
  <div class="auth-card">
    <div class="auth-header text-center">
      <h1 class="auth-title">Welcome Back</h1>
      <p class="auth-subtitle">Sign in to your account to view orders, saved items, and checkout faster.</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger small py-2 mb-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="login.php<?= ($next !== 'account/' && $next !== '') ? '?next=' . urlencode($next) : '' ?>" class="auth-form" novalidate id="loginForm">
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
          <a href="<?= e(shop_url('forgot_password.php')) ?>" class="small text-muted text-decoration-underline">
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
        <button type="submit" class="btn btn-editorial-primary btn-full py-2 fw-semibold" id="btnSubmitLogin">
          Sign In
        </button>
      </div>
    </form>

    <div class="auth-footer text-center mt-4 pt-3 border-top">
      <span class="small text-muted">Don't have an account?</span>
      <a href="<?= e(shop_url('register.php' . (($next !== 'account/' && $next !== '') ? '?next=' . urlencode($next) : ''))) ?>" class="small fw-semibold text-dark ms-1">
        Create Account &rarr;
      </a>
    </div>
  </div>
</div>

<script src="<?= e(url('assets/js/auth.js')) ?>?v=<?= filemtime(__DIR__ . '/assets/vendor/fonts/inter.css') ?>" defer></script>

<?php include __DIR__ . '/includes/shop_footer.php'; ?>
