<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    admin_redirect('dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $email    = trim($_POST['email']    ?? '');
    $password = (string)($_POST['password'] ?? '');

    $ip = get_client_ip();
    if (function_exists('auth_is_rate_limited') && auth_is_rate_limited('login', $email, $ip)) {
        $errors[] = 'Too many failed login attempts. Please wait 15 minutes before trying again.';
    } elseif (!$email || !$password) {
        $errors[] = 'Email and password are required.';
    } elseif (!attempt_login($email, $password)) {
        $errors[] = 'Invalid credentials, or your account is disabled.';
    } else {
        flash('success', 'Welcome back, ' . current_user()['name'] . '!');
        admin_redirect('dashboard.php');
    }
    set_old(['email' => $email]);
}

$siteName = setting('site_name', APP_NAME);
$themeCookie = $_GET['theme'] ?? $_COOKIE['theme'] ?? 'light';
if (!in_array($themeCookie, ['light', 'dark'], true)) {
    $themeCookie = 'light';
}
$cssVersion = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
?>
<!doctype html>
<html lang="en" data-bs-theme="<?= e($themeCookie) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login · <?= e($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= e($cssVersion) ?>">
<style>
  html, body {
    height: 100%;
    margin: 0;
    padding: 0;
    overflow-x: hidden;
  }
  body.login-page {
    background-color: var(--bs-tertiary-bg, #f4f6f9);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  [data-bs-theme="dark"] body.login-page {
    background-color: #0b1120 !important;
    background-image: radial-gradient(at 50% 0%, rgba(30, 41, 59, 0.7) 0%, rgba(11, 17, 32, 1) 75%) !important;
  }
  .login-box {
    width: 100% !important;
    max-width: 420px !important;
    margin-left: auto !important;
    margin-right: auto !important;
  }
  .login-card {
    border-radius: 0.5rem !important;
    border: 1px solid var(--bs-border-color) !important;
    border-top: 3px solid #0d6efd !important;
    background-color: var(--bs-card-bg) !important;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04) !important;
  }
  [data-bs-theme="dark"] .login-card {
    background-color: #1e293b !important;
    border-color: #334155 !important;
    border-top-color: #3b82f6 !important;
    box-shadow: 0 15px 30px -5px rgba(0, 0, 0, 0.5) !important;
  }
  .login-brand-icon {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    box-shadow: 0 4px 10px rgba(13, 110, 253, 0.25);
  }
  [data-bs-theme="dark"] .login-brand-icon {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
  }
  .login-box .input-group-text {
    border-color: var(--bs-border-color);
  }
  .login-box .form-control {
    border-color: var(--bs-border-color);
  }
  .login-box .password-toggle-btn {
    border-color: var(--bs-border-color);
    background-color: var(--bs-tertiary-bg);
    color: var(--bs-secondary-color);
  }
  .login-box .password-toggle-btn:hover,
  .login-box .password-toggle-btn:focus {
    background-color: var(--bs-secondary-bg);
    color: var(--bs-body-color);
    border-color: var(--bs-border-color);
    box-shadow: none;
  }
</style>
</head>
<body class="login-page">

<!-- Top Navigation Header (Normal Flow, No Absolute Overlap) -->
<header class="w-100 px-3 py-2 px-sm-4 py-sm-3 d-flex justify-content-between align-items-center">
  <a href="<?= e(url()) ?>" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1 shadow-sm text-decoration-none">
    <i class="bi bi-arrow-left"></i>
    <span>Storefront</span>
  </a>

  <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm"
          id="themeToggle" style="width: 36px; height: 36px;" title="Toggle Light / Dark Mode" aria-label="Toggle theme">
    <i class="bi <?= $themeCookie === 'dark' ? 'bi-sun' : 'bi-moon-stars' ?>" id="themeToggleIcon"></i>
  </button>
</header>

<!-- Main Centered Content -->
<main class="flex-grow-1 d-flex align-items-center justify-content-center px-3 py-3">
  <div class="login-box">
    <!-- Brand / Header -->
    <div class="login-logo text-center mb-3">
      <a href="<?= e(url()) ?>" class="text-decoration-none text-reset d-inline-flex flex-column align-items-center w-100">
        <div class="login-brand-icon mb-2 shadow-sm">
          <i class="bi bi-shield-lock-fill"></i>
        </div>
        <div class="fw-bold fs-4 d-flex align-items-center justify-content-center gap-2">
          <span><?= e(APP_NAME) ?></span>
          <span class="badge bg-primary text-white font-monospace text-uppercase" style="font-size: 0.65rem; letter-spacing: 0.5px;">ADMIN</span>
        </div>
        <?php if (!empty($siteName) && $siteName !== APP_NAME): ?>
          <div class="small text-secondary text-truncate mt-1 px-2" style="max-width: 100%; width: 340px;" title="<?= e($siteName) ?>">
            <?= e($siteName) ?>
          </div>
        <?php endif; ?>
      </a>
    </div>

    <!-- Login Card -->
    <div class="card login-card shadow-sm">
      <div class="card-body p-4">
        <p class="text-center text-secondary mb-3 small">Sign in to start your administrator session</p>

        <?php foreach (get_flashes() as $f): ?>
          <div class="alert alert-<?= e($f['type']) ?> py-2 px-3 small d-flex align-items-center mb-3">
            <i class="bi bi-info-circle me-2 flex-shrink-0"></i>
            <div><?= e($f['message']) ?></div>
          </div>
        <?php endforeach; ?>

        <?php foreach ($errors as $err): ?>
          <div class="alert alert-danger py-2 px-3 small d-flex align-items-center mb-3">
            <i class="bi bi-exclamation-triangle-fill me-2 flex-shrink-0"></i>
            <div><?= e($err) ?></div>
          </div>
        <?php endforeach; ?>

        <form method="post" autocomplete="off" novalidate>
          <?= csrf_field() ?>

          <div class="mb-3">
            <label for="adminEmailInput" class="form-label small fw-semibold text-secondary mb-1">Email Address</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-envelope"></i></span>
              <input type="email" name="email" id="adminEmailInput" class="form-control"
                     value="<?= old('email') ?>" required autofocus placeholder="name@example.com">
            </div>
          </div>

          <div class="mb-3">
            <label for="adminPasswordInput" class="form-label small fw-semibold text-secondary mb-1">Password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-key"></i></span>
              <input type="password" name="password" id="adminPasswordInput" class="form-control" required placeholder="Enter password">
              <button class="btn btn-outline-secondary input-group-text password-toggle-btn"
                      type="button" id="togglePasswordBtn" aria-label="Toggle password visibility" title="Show/hide password">
                <i class="bi bi-eye" id="togglePasswordIcon"></i>
              </button>
            </div>
          </div>

          <div class="d-flex align-items-center justify-content-between mb-4">
            <div class="form-check mb-0">
              <input class="form-check-input" type="checkbox" id="rememberMe">
              <label class="form-check-label small text-secondary user-select-none" for="rememberMe">Remember Me</label>
            </div>
            <span class="badge bg-secondary-subtle text-secondary small border">Secure Area</span>
          </div>

          <div class="d-grid mb-3">
            <button type="submit" class="btn btn-primary d-flex align-items-center justify-content-center py-2 fw-semibold shadow-sm">
              <i class="bi bi-box-arrow-in-right me-2"></i>
              <span>Sign In</span>
            </button>
          </div>
        </form>

        <div class="text-center pt-3 border-top">
          <a href="<?= e(url()) ?>" class="text-secondary small text-decoration-none d-inline-flex align-items-center">
            <i class="bi bi-arrow-left me-1"></i> Return to Storefront
          </a>
        </div>

      </div>
    </div>
  </div>
</main>

<!-- Bottom Footer -->
<footer class="w-100 py-3 text-center text-muted small">
  &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.
</footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Password Visibility Toggle
  const toggleBtn = document.getElementById('togglePasswordBtn');
  const pwdInput = document.getElementById('adminPasswordInput');
  const toggleIcon = document.getElementById('togglePasswordIcon');
  if (toggleBtn && pwdInput && toggleIcon) {
    toggleBtn.addEventListener('click', function(e) {
      e.preventDefault();
      if (pwdInput.type === 'password') {
        pwdInput.type = 'text';
        toggleIcon.classList.remove('bi-eye');
        toggleIcon.classList.add('bi-eye-slash');
      } else {
        pwdInput.type = 'password';
        toggleIcon.classList.remove('bi-eye-slash');
        toggleIcon.classList.add('bi-eye');
      }
    });
  }

  // Theme Switcher (Sync with script.js and cookie)
  const themeBtn = document.getElementById('themeToggle');
  const themeIcon = document.getElementById('themeToggleIcon');
  const root = document.documentElement;

  if (themeBtn && themeIcon) {
    themeBtn.addEventListener('click', function(e) {
      e.preventDefault();
      const current = root.getAttribute('data-bs-theme') || 'light';
      const next = current === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-bs-theme', next);
      localStorage.setItem('theme', next);
      document.cookie = "theme=" + next + "; path=/; max-age=31536000";
      if (next === 'dark') {
        themeIcon.classList.remove('bi-moon-stars');
        themeIcon.classList.add('bi-sun');
      } else {
        themeIcon.classList.remove('bi-sun');
        themeIcon.classList.add('bi-moon-stars');
      }
    });
  }
});
</script>
<?php clear_old(); ?>
</body>
</html>
