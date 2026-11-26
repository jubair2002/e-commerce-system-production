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
$themeCookie = $_GET['theme'] ?? $_COOKIE['theme'] ?? 'dark';
if (!in_array($themeCookie, ['light', 'dark'], true)) {
    $themeCookie = 'dark';
}
$bgUrl = url('assets/images/login-bg.jpg');
?>
<!doctype html>
<html lang="en" data-bs-theme="<?= e($themeCookie) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Portal · <?= e($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&family=Source+Sans+3:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<style>
  :root {
    --now-primary-grad: linear-gradient(135deg, #f96332 0%, #ff3b30 100%);
    --now-primary-hover: linear-gradient(135deg, #fa7346 0%, #ff4d43 100%);
    --now-glass-bg: rgba(255, 255, 255, 0.14);
    --now-glass-border: rgba(255, 255, 255, 0.28);
    --now-glass-focus: rgba(255, 255, 255, 0.22);
  }

  html, body {
    height: 100%;
    margin: 0;
    padding: 0;
    overflow-x: hidden;
    font-family: 'Montserrat', 'Source Sans 3', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  }

  /* Full Screen Atmospheric Background */
  body.now-login-body {
    min-height: 100vh;
    background-color: #17121e;
    background-image:
      linear-gradient(180deg, rgba(22, 16, 26, 0.52) 0%, rgba(18, 14, 22, 0.65) 50%, rgba(10, 8, 14, 0.88) 100%),
      url('<?= e($bgUrl) ?>');
    background-size: cover;
    background-position: center center;
    background-repeat: no-repeat;
    background-attachment: fixed;
    color: #ffffff;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
  }

  [data-bs-theme="light"] body.now-login-body {
    background-image:
      linear-gradient(180deg, rgba(25, 20, 30, 0.38) 0%, rgba(20, 16, 26, 0.52) 50%, rgba(12, 10, 16, 0.78) 100%),
      url('<?= e($bgUrl) ?>');
  }

  /* Subtle vignette layer */
  body.now-login-body::before {
    content: '';
    position: fixed;
    inset: 0;
    background: radial-gradient(circle at center, transparent 30%, rgba(0, 0, 0, 0.45) 100%);
    pointer-events: none;
    z-index: 1;
  }

  /* Top and Bottom Elements sit above overlay */
  .now-layer {
    position: relative;
    z-index: 2;
  }

  /* Top Navigation Bar */
  .now-header {
    padding: 24px 36px;
  }
  @media (max-width: 576px) {
    .now-header {
      padding: 14px 16px;
      gap: 8px;
    }
    .now-brand-title {
      font-size: 0.8rem;
      letter-spacing: 1px;
    }
    .now-nav-link {
      padding: 5px 10px;
      font-size: 0.72rem;
    }
    .now-theme-btn {
      width: 32px;
      height: 32px;
    }
  }

  .now-brand-title {
    font-size: 0.95rem;
    font-weight: 700;
    letter-spacing: 1.5px;
    color: #ffffff;
    text-transform: uppercase;
  }

  .now-nav-link {
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    text-decoration: none;
    transition: all 0.2s ease;
    padding: 6px 14px;
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
  }
  .now-nav-link:hover {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.18);
    border-color: rgba(255, 255, 255, 0.35);
    transform: translateY(-1px);
  }

  .now-theme-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    backdrop-filter: blur(8px);
  }
  .now-theme-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
    transform: translateY(-1px);
  }

  /* Center Floating Card / Form Container */
  .now-login-container {
    width: 100%;
    max-width: 380px;
    margin: 0 auto;
    padding: 20px 16px;
  }

  /* Floating Emblem with Pulsing Signal Waves (Now UI style) */
  .now-emblem-wrap {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 32px;
  }

  .now-signal-icon {
    position: relative;
    width: 72px;
    height: 72px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  /* Animated glowing signal arcs */
  .now-signal-arc {
    position: absolute;
    border: 2px solid rgba(255, 255, 255, 0.6);
    border-bottom-color: transparent;
    border-left-color: transparent;
    border-right-color: transparent;
    border-radius: 50%;
    top: 0;
  }
  .now-signal-arc.arc-1 {
    width: 52px;
    height: 52px;
    top: 4px;
    border-width: 2.5px;
    opacity: 0.85;
  }
  .now-signal-arc.arc-2 {
    width: 70px;
    height: 70px;
    top: -6px;
    border-width: 2px;
    opacity: 0.55;
  }

  .now-emblem-badge {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.35);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.65rem;
    font-weight: 800;
    letter-spacing: -0.5px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25), inset 0 1px 1px rgba(255, 255, 255, 0.4);
    position: relative;
    z-index: 2;
  }

  .now-emblem-title {
    font-size: 0.82rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    letter-spacing: 1.5px;
    text-transform: uppercase;
    margin-top: 14px;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
    max-width: 320px;
    text-align: center;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* Frosted Glassmorphism Pill Inputs */
  .now-pill-group {
    background: var(--now-glass-bg);
    border: 1px solid var(--now-glass-border);
    border-radius: 30px;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    padding: 3px 6px;
    transition: all 0.25s ease;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12), inset 0 1px 1px rgba(255, 255, 255, 0.2);
  }
  .now-pill-group:focus-within {
    background: var(--now-glass-focus);
    border-color: rgba(255, 255, 255, 0.65);
    box-shadow: 0 0 20px rgba(255, 255, 255, 0.25), inset 0 1px 1px rgba(255, 255, 255, 0.3);
    transform: translateY(-1px);
  }

  .now-pill-icon {
    background: transparent !important;
    border: none !important;
    color: rgba(255, 255, 255, 0.85) !important;
    padding-left: 14px;
    padding-right: 6px;
    font-size: 1rem;
  }

  .now-pill-input {
    background: transparent !important;
    border: none !important;
    color: #ffffff !important;
    font-size: 0.92rem;
    font-weight: 400;
    padding: 11px 10px;
    box-shadow: none !important;
  }
  .now-pill-input::placeholder {
    color: rgba(255, 255, 255, 0.6);
    font-weight: 300;
    letter-spacing: 0.3px;
  }
  .now-pill-input:focus {
    color: #ffffff !important;
  }

  /* Password Toggle Button inside pill */
  .now-pwd-toggle {
    background: transparent !important;
    border: none !important;
    color: rgba(255, 255, 255, 0.7) !important;
    padding-right: 14px;
    padding-left: 6px;
    box-shadow: none !important;
    transition: color 0.2s ease;
  }
  .now-pwd-toggle:hover,
  .now-pwd-toggle:focus {
    color: #ffffff !important;
  }

  /* Vibrant Coral/Orange Rounded Submit Button */
  .now-btn-submit {
    border-radius: 30px;
    height: 50px;
    background: var(--now-primary-grad);
    border: none;
    color: #ffffff;
    font-weight: 700;
    font-size: 0.95rem;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    box-shadow: 0 6px 24px rgba(249, 99, 50, 0.45);
    transition: all 0.25s cubic-bezier(0.25, 0.8, 0.25, 1);
  }
  .now-btn-submit:hover {
    background: var(--now-primary-hover);
    color: #ffffff;
    box-shadow: 0 8px 30px rgba(249, 99, 50, 0.6);
    transform: translateY(-2px);
  }
  .now-btn-submit:active {
    transform: translateY(0);
    box-shadow: 0 3px 14px rgba(249, 99, 50, 0.4);
  }

  /* Frosted Glass Checkbox */
  .now-checkbox {
    background-color: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.35);
    border-radius: 4px;
  }
  .now-checkbox:checked {
    background-color: #f96332;
    border-color: #f96332;
  }
  .now-checkbox:focus {
    box-shadow: 0 0 0 0.2rem rgba(249, 99, 50, 0.3);
    border-color: #f96332;
  }

  /* Soft Alerts */
  .now-alert {
    border-radius: 20px;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    font-size: 0.84rem;
    font-weight: 500;
    color: #ffffff;
    padding: 10px 16px;
    margin-bottom: 16px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
  }
  .now-alert-danger {
    background: rgba(220, 53, 69, 0.35);
    border: 1px solid rgba(220, 53, 69, 0.6);
  }
  .now-alert-success {
    background: rgba(25, 135, 84, 0.35);
    border: 1px solid rgba(25, 135, 84, 0.6);
  }

  /* Clean Footer */
  .now-footer {
    padding: 24px 36px;
    font-size: 0.78rem;
    letter-spacing: 0.8px;
    color: rgba(255, 255, 255, 0.65);
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  @media (max-width: 576px) {
    .now-footer {
      flex-direction: column;
      gap: 8px;
      padding: 16px 20px;
      text-align: center;
    }
  }
</style>
</head>
<body class="now-login-body">

<!-- Top Navigation Header (Only 1 Single Clean Storefront Link) -->
<header class="now-header now-layer d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div class="d-flex align-items-center gap-2">
    <i class="bi bi-grid-fill text-white-50" style="font-size: 1.1rem;"></i>
    <span class="now-brand-title"><?= e(APP_NAME) ?></span>
    <span class="badge bg-white text-dark rounded-pill px-2 py-1 small fw-bold" style="font-size: 0.62rem; letter-spacing: 0.5px;">ADMIN</span>
  </div>

  <div class="d-flex align-items-center gap-2 gap-sm-3 ms-auto">
    <a href="<?= e(url()) ?>" class="now-nav-link d-inline-flex align-items-center gap-1" title="Return to storefront">
      <i class="bi bi-shop"></i>
      <span>Storefront</span>
    </a>

    <button type="button" class="now-theme-btn" id="themeToggle" title="Toggle Light / Dark Mode" aria-label="Toggle theme">
      <i class="bi <?= $themeCookie === 'dark' ? 'bi-sun-fill' : 'bi-moon-stars-fill' ?>" id="themeToggleIcon"></i>
    </button>
  </div>
</header>

<!-- Main Form Centerpiece -->
<main class="now-layer flex-grow-1 d-flex align-items-center justify-content-center">
  <div class="now-login-container">

    <!-- Glowing Brand Emblem with Signal Rings (Now UI style) -->
    <div class="now-emblem-wrap">
      <div class="now-signal-icon">
        <span class="now-signal-arc arc-2"></span>
        <span class="now-signal-arc arc-1"></span>
        <div class="now-emblem-badge shadow-lg">
          <i class="bi bi-shield-lock-fill"></i>
        </div>
      </div>
      <div class="now-emblem-title">
        <?= e($siteName) ?>
      </div>
    </div>

    <!-- Feedback Alerts -->
    <?php foreach (get_flashes() as $f): ?>
      <div class="now-alert now-alert-success d-flex align-items-center">
        <i class="bi bi-check-circle-fill me-2 flex-shrink-0"></i>
        <div><?= e($f['message']) ?></div>
      </div>
    <?php endforeach; ?>

    <?php foreach ($errors as $err): ?>
      <div class="now-alert now-alert-danger d-flex align-items-center">
        <i class="bi bi-exclamation-triangle-fill me-2 flex-shrink-0"></i>
        <div><?= e($err) ?></div>
      </div>
    <?php endforeach; ?>

    <!-- Login Form -->
    <form method="post" autocomplete="off" novalidate>
      <?= csrf_field() ?>

      <!-- Email Pill Input -->
      <div class="mb-3">
        <div class="input-group now-pill-group">
          <span class="input-group-text now-pill-icon">
            <i class="bi bi-person"></i>
          </span>
          <input type="email" name="email" id="adminEmailInput" class="form-control now-pill-input"
                 value="<?= old('email') ?>" required autofocus placeholder="Email Address...">
        </div>
      </div>

      <!-- Password Pill Input -->
      <div class="mb-4">
        <div class="input-group now-pill-group">
          <span class="input-group-text now-pill-icon">
            <i class="bi bi-key"></i>
          </span>
          <input type="password" name="password" id="adminPasswordInput" class="form-control now-pill-input"
                 required placeholder="Password...">
          <button class="btn now-pwd-toggle" type="button" id="togglePasswordBtn"
                  aria-label="Toggle password visibility" title="Show/hide password">
            <i class="bi bi-eye" id="togglePasswordIcon"></i>
          </button>
        </div>
      </div>

      <!-- Submit Pill Button -->
      <div class="d-grid mb-3">
        <button type="submit" class="btn now-btn-submit d-flex align-items-center justify-content-center gap-2">
          <span>Sign In</span>
          <i class="bi bi-arrow-right-short fs-4"></i>
        </button>
      </div>

      <!-- Options Row (Clean, No Duplicate Links) -->
      <div class="d-flex align-items-center justify-content-between px-2 text-uppercase" style="font-size: 11px; letter-spacing: 0.8px;">
        <div class="form-check mb-0">
          <input class="form-check-input now-checkbox" type="checkbox" id="rememberMe">
          <label class="form-check-label text-white-50 user-select-none fw-semibold" for="rememberMe" style="cursor: pointer;">
            Remember Me
          </label>
        </div>
        <span class="text-white-50 fw-semibold d-inline-flex align-items-center gap-1" title="Protected administrator session">
          <i class="bi bi-shield-check text-white"></i>
          <span>Secure Portal</span>
        </span>
      </div>

    </form>

  </div>
</main>

<!-- Bottom Footer -->
<footer class="now-footer now-layer">
  <div>
    &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.
  </div>
  <div>
    <span>Authorized Personnel Only</span>
  </div>
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

  // Theme Toggle (Light / Dark mode persistence)
  const themeBtn = document.getElementById('themeToggle');
  const themeIcon = document.getElementById('themeToggleIcon');
  if (themeBtn && themeIcon) {
    themeBtn.addEventListener('click', function() {
      const htmlEl = document.documentElement;
      const current = htmlEl.getAttribute('data-bs-theme') || 'dark';
      const target = current === 'dark' ? 'light' : 'dark';
      htmlEl.setAttribute('data-bs-theme', target);

      if (target === 'dark') {
        themeIcon.className = 'bi bi-sun-fill';
      } else {
        themeIcon.className = 'bi bi-moon-stars-fill';
      }
      document.cookie = 'theme=' + target + '; path=/; max-age=31536000; SameSite=Lax';
    });
  }
});
</script>

</body>
</html>
