<?php
/**
 * Storefront Maintenance Screen.
 * Displayed when maintenance mode is active or when directly previewed.
 */
require_once __DIR__ . '/includes/shop_bootstrap.php';

$siteName = setting('site_name', APP_NAME);
$siteAbout = setting('site_about', 'Quality essentials delivered to your door.');
$themeAccent = setting('theme_accent_color', setting('theme_accent', '#111111'));
$storeFavicon = setting('store_favicon');
$isMaintActive = (int)setting('maintenance_mode', '0') === 1;

if ($isMaintActive) {
    http_response_code(503);
    header('Retry-After: 3600');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Under Scheduled Maintenance · <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
  <?php if (!empty($storeFavicon)): ?>
    <link rel="icon" href="<?= e(UPLOADS_URL . 'branding/' . $storeFavicon) ?>">
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root {
      --ink: #111111;
      --muted: #666666;
      --line: #e8e8e6;
      --bg: #ffffff;
      --bg-soft: #f7f7f5;
      --accent: <?= e($themeAccent) ?>;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--bg-soft);
      color: var(--ink);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 24px;
      text-align: center;
    }
    .maint-card {
      background: var(--bg);
      max-width: 540px;
      width: 100%;
      padding: 48px 40px;
      border: 1px solid var(--line);
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.04);
      animation: fadeIn 0.4s ease-out;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .maint-icon {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: #fffbeb;
      border: 1px solid #fef3c7;
      color: #d97706;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      margin-bottom: 24px;
    }
    .maint-badge {
      display: inline-block;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      padding: 4px 12px;
      border-radius: 99px;
      background: var(--bg-soft);
      color: var(--muted);
      border: 1px solid var(--line);
      margin-bottom: 18px;
      font-weight: 600;
    }
    h1 {
      font-size: 26px;
      font-weight: 700;
      letter-spacing: -0.02em;
      margin-bottom: 12px;
      color: var(--ink);
    }
    p {
      color: var(--muted);
      line-height: 1.6;
      font-size: 15px;
      margin-bottom: 24px;
    }
    .maint-details {
      background: var(--bg-soft);
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 16px;
      margin-bottom: 24px;
      font-size: 13px;
      color: var(--muted);
      text-align: left;
    }
    .maint-details div {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 6px;
    }
    .maint-details div:last-child {
      margin-bottom: 0;
    }
    .maint-note {
      font-size: 13px;
      color: var(--muted);
      border-top: 1px solid var(--line);
      padding-top: 20px;
      text-align: center;
    }
  </style>
</head>
<body>
  <div class="maint-card">
    <div class="maint-icon">
      <i class="bi bi-tools"></i>
    </div>
    <div>
      <span class="maint-badge">Under Scheduled Maintenance</span>
    </div>
    <h1>We'll be right back</h1>
    <p>Our store is currently undergoing brief scheduled improvements and maintenance to serve you better. We expect to be back online shortly.</p>

    <div class="maint-details">
      <div><i class="bi bi-shield-check text-success"></i> Customer data and pending orders are safe.</div>
      <div><i class="bi bi-clock-history text-primary"></i> Estimated downtime: Less than 15 minutes.</div>
      <div><i class="bi bi-envelope text-secondary"></i> Urgent inquiries: <?= e(setting('site_email', 'support@example.com')) ?></div>
    </div>

    <div class="maint-note">
      <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>. All rights reserved.</span>
    </div>
  </div>
</body>
</html>
