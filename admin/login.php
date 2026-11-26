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
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="login-page">

<!-- Top Bar Utility Controls -->
<div class="position-absolute top-0 start-0 p-3">
  <a href="<?= e(url()) ?>" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1 shadow-sm text-decoration-none" title="Return to storefront">
    <i class="bi bi-arrow-left"></i>
    <span class="d-none d-sm-inline">Storefront</span>
  </a>
</div>

<div class="position-absolute top-0 end-0 p-3 d-flex align-items-center gap-2">
  <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle d-inline-flex align-items-center justify-content-center shadow-sm"
          id="themeToggle" style="width: 34px; height: 34px;" title="Toggle Light / Dark Mode" aria-label="Toggle theme">
    <i class="bi <?= $themeCookie === 'dark' ? 'bi-sun' : 'bi-moon-stars' ?>" id="themeToggleIcon"></i>
  </button>
</div>

<div class="login-box">
  <!-- Brand / Header -->
  <div class="login-logo">
    <a href="<?= e(url()) ?>" class="text-decoration-none text-reset d-inline-flex flex-column align-items-center">
      <div class="login-brand-icon shadow-sm">
        <i class="bi bi-shield-lock-fill"></i>
      </div>
      <div class="fw-bold d-flex align-items-center gap-1">
        <span><?= e(APP_NAME) ?></span>
        <span class="badge bg-primary text-white font-monospace text-uppercase" style="font-size: 0.65rem; letter-spacing: 0.5px;">ADMIN</span>
      </div>
      <?php if (!empty($siteName) && $siteName !== APP_NAME): ?>
        <div class="small text-secondary text-truncate mt-1" style="max-width: 340px;" title="<?= e($siteName) ?>">
          <?= e($siteName) ?>
        </div>
      <?php endif; ?>
    </a>
  </div>

  <!-- Card -->
  <div class="card card-outline card-primary shadow">
    <div class="card-body login-card-body">
      <p class="login-box-msg">Sign in to start your administrator session</p>

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
          <label for="adminEmailInput" class="form-label">Email Address</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" name="email" id="adminEmailInput" class="form-control"
                   value="<?= old('email') ?>" required autofocus placeholder="name@example.com">
          </div>
        </div>

        <div class="mb-3">
          <label for="adminPasswordInput" class="form-label">Password</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input type="password" name="password" id="adminPasswordInput" class="form-control" required placeholder="Enter password">
            <button class="btn password-toggle-btn" type="button" id="togglePasswordBtn" aria-label="Toggle password visibility" title="Show/hide password">
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
          <button type="submit" class="btn btn-primary d-flex align-items-center justify-content-center shadow-sm">
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

  <div class="text-center text-muted small mt-3">
    &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.
  </div>
</div>

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
