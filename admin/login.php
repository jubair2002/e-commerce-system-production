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
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
<style>
  body {
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  }
  .auth-wrapper {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #0B1437 radial-gradient(circle at 80% 20%, rgba(67, 24, 255, 0.25) 0%, rgba(11, 20, 55, 0) 65%);
    padding: 1.5rem;
    position: relative;
  }
  .auth-card {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 440px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    box-shadow: 0px 25px 60px rgba(11, 20, 55, 0.45);
  }
  .auth-brand .brand-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 60px;
    height: 60px;
    border-radius: 18px;
    background: linear-gradient(135deg, #868CFF 0%, #4318FF 100%);
    color: #ffffff;
    font-size: 1.75rem;
    margin-bottom: 0.85rem;
    box-shadow: 0 10px 25px rgba(67, 24, 255, 0.35);
  }
  .btn-admin-submit {
    background: #4318FF;
    border: none;
    color: #fff;
    padding: 0.75rem 1.25rem;
    font-weight: 700;
    border-radius: 14px;
    box-shadow: 0 10px 24px rgba(67, 24, 255, 0.3);
    transition: all 0.2s ease;
  }
  .btn-admin-submit:hover {
    background: #3311DB;
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 14px 28px rgba(67, 24, 255, 0.4);
  }
</style>
</head>
<body>
<div class="auth-wrapper">
  <div class="card auth-card">
    <div class="card-body">

      <div class="auth-brand">
        <div class="brand-icon"><i class="bi bi-shield-lock-fill"></i></div>
        <h4 class="mb-1 fw-bold text-dark"><?= e($siteName) ?></h4>
        <p class="text-muted small mb-0">Admin Management Portal</p>
      </div>

      <?php foreach (get_flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?> py-2"><?= e($f['message']) ?></div>
      <?php endforeach; ?>

      <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger py-2"><?= e($err) ?></div>
      <?php endforeach; ?>

      <form method="post" autocomplete="off" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label fw-medium text-secondary small">Email address</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" name="email" class="form-control"
                   value="<?= old('email') ?>" required autofocus placeholder="admin@store.com">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-medium text-secondary small">Password</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input type="password" name="password" id="adminPasswordInput" class="form-control" required placeholder="••••••••">
            <button class="btn btn-outline-secondary" type="button" id="togglePasswordBtn" aria-label="Toggle password visibility" title="Toggle password visibility">
              <i class="bi bi-eye" id="togglePasswordIcon"></i>
            </button>
          </div>
        </div>
        <button class="btn btn-admin-submit w-100 mt-2">
          <i class="bi bi-box-arrow-in-right me-1"></i> Sign in
        </button>
      </form>

      <div class="text-center mt-3 pt-3 border-top">
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
