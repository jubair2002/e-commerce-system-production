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

    if (!$email || !$password) {
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
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
<style>
  .auth-wrapper {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #0a0f1d url('<?= e(url('assets/images/admin-login-bg.jpg')) ?>') no-repeat center center / cover fixed;
    padding: 1.5rem;
    position: relative;
  }
  .auth-wrapper::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at center, rgba(10, 15, 29, 0.35) 0%, rgba(10, 15, 29, 0.78) 100%);
    pointer-events: none;
  }
  .auth-card {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 420px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 1.25rem;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.5);
  }
  .auth-brand .brand-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 58px;
    height: 58px;
    border-radius: 16px;
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #38bdf8;
    font-size: 1.7rem;
    margin-bottom: 0.75rem;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.25);
  }
  .btn-admin-submit {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    border: none;
    color: #fff;
    padding: 0.65rem 1rem;
    font-weight: 600;
    transition: all 0.2s ease;
  }
  .btn-admin-submit:hover {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.25);
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
