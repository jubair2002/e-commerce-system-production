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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login · <?= e($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body class="login-page bg-body-secondary">
<div class="login-box">
  <div class="login-logo mb-3">
    <a href="<?= e(url()) ?>" class="text-decoration-none text-body">
      <b><?= e($siteName) ?></b> ADMIN
    </a>
  </div>
  <!-- /.login-logo -->
  <div class="card card-outline card-primary shadow-sm">
    <div class="card-body login-card-body p-4">
      <p class="login-box-msg text-secondary">Sign in to start your session</p>

      <?php foreach (get_flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?> py-2 small"><?= e($f['message']) ?></div>
      <?php endforeach; ?>

      <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger py-2 small"><?= e($err) ?></div>
      <?php endforeach; ?>

      <form method="post" autocomplete="off" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <div class="input-group">
            <input type="email" name="email" class="form-control"
                   value="<?= old('email') ?>" required autofocus placeholder="Email">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
          </div>
        </div>
        <div class="mb-3">
          <div class="input-group">
            <input type="password" name="password" id="adminPasswordInput" class="form-control" required placeholder="Password">
            <button class="btn btn-outline-secondary" type="button" id="togglePasswordBtn" aria-label="Toggle password visibility" title="Toggle password visibility">
              <i class="bi bi-eye" id="togglePasswordIcon"></i>
            </button>
          </div>
        </div>
        <div class="row align-items-center mb-3">
          <div class="col-8">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="rememberMe">
              <label class="form-check-label small" for="rememberMe">Remember Me</label>
            </div>
          </div>
          <div class="col-4">
            <button type="submit" class="btn btn-primary w-100">
              Sign In
            </button>
          </div>
        </div>
      </form>

      <div class="text-center pt-3 border-top">
        <a href="<?= e(url()) ?>" class="text-muted small text-decoration-none">
          <i class="bi bi-arrow-left me-1"></i> Return to Storefront
        </a>
      </div>

    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
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
});
</script>
<?php clear_old(); ?>
</body>
</html>
