<?php
/**
 * Top of every authenticated page.
 * Pages should set $pageTitle before including this file.
 */
require_once __DIR__ . '/auth.php';
require_login();

$pageTitle = $pageTitle ?? 'Dashboard';
$me        = current_user() ?: ['name' => 'Admin', 'email' => 'admin@example.com', 'avatar' => null];
$siteName  = setting('site_name', APP_NAME);
$themeCookie = $_COOKIE['theme'] ?? 'light';
if (!in_array($themeCookie, ['light', 'dark'], true)) {
    $themeCookie = 'light';
}
?>
<!doctype html>
<html lang="en" data-bs-theme="<?= e($themeCookie) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · <?= e($siteName) ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= filemtime(__DIR__ . '/../../assets/css/style.css') ?>">
</head>
<body>
<div class="app">

  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

  <main class="app-main">

    <!-- Top bar (Horizon UI Floating Glass Layout) -->
    <nav class="topbar">
      <div class="topbar-left d-flex align-items-center gap-3">
        <button class="topbar-toggle-btn" id="sidebarToggle" type="button" title="Toggle Sidebar" aria-label="Toggle Sidebar">
          <i class="bi bi-list fs-5"></i>
        </button>

        <div class="topbar-title-group">
          <div class="topbar-breadcrumb">
            <span class="text-muted">Pages</span>
            <span class="mx-1 text-muted">/</span>
            <span class="fw-semibold active-crumb"><?= e($pageTitle) ?></span>
          </div>
          <h1 class="topbar-title"><?= e($pageTitle) ?></h1>
        </div>
      </div>

      <div class="ms-auto topbar-pill-container">
        <?php
          // Real-time admin actionable notifications
          $notifPendingOrders = 0;
          $notifLowStock = 0;
          $notifFailedMail = 0;
          try {
              $notifPendingOrders = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
              $thresh = (int)setting('low_stock_threshold', '5');
              $notifLowStock = (int)db()->query("SELECT COUNT(*) FROM products WHERE status = 'active' AND track_stock = 1 AND stock <= {$thresh}")->fetchColumn();
              $notifFailedMail = (int)db()->query("SELECT COUNT(*) FROM email_queue WHERE status = 'failed'")->fetchColumn();
          } catch (Throwable $e) {}
          $rawNotifCount = $notifPendingOrders + $notifLowStock + $notifFailedMail;
        ?>

        <?php
          // Server Health Dot (Must show operational indicator)
          $serverHealthColor = 'secondary';
          $serverHealthTitle = 'System Health: Initializing (Collector starting up...)';
          try {
              $stmtH = db()->query("SELECT collected_at, cpu_percent, mem_used, mem_total, disk_used, disk_total FROM system_metrics ORDER BY id DESC LIMIT 1");
              $smRow = $stmtH ? $stmtH->fetch(PDO::FETCH_ASSOC) : false;
              if ($smRow && !empty($smRow['collected_at'])) {
                  $cpu = (float)($smRow['cpu_percent'] ?? 0);
                  $memTotal = (float)($smRow['mem_total'] ?? 0);
                  $memUsed = (float)($smRow['mem_used'] ?? 0);
                  $ram = ($memTotal > 0) ? ($memUsed / $memTotal) * 100 : 0;
                  $diskTotal = (float)($smRow['disk_total'] ?? 0);
                  $diskUsed = (float)($smRow['disk_used'] ?? 0);
                  $disk = ($diskTotal > 0) ? ($diskUsed / $diskTotal) * 100 : 0;

                  $lag = time() - strtotime($smRow['collected_at']);
                  if ($lag > 300) {
                      $serverHealthColor = 'warning';
                      $serverHealthTitle = "System Health: Stale Data (Last collected " . round($lag / 60) . "m ago)";
                  } elseif ($cpu >= 90 || $ram >= 90 || $disk >= 95) {
                      $serverHealthColor = 'danger';
                      $serverHealthTitle = "System Health: Warning (CPU " . round($cpu) . "%, RAM " . round($ram) . "%, Disk " . round($disk) . "%)";
                  } elseif ($cpu >= 75 || $ram >= 75 || $disk >= 85) {
                      $serverHealthColor = 'warning';
                      $serverHealthTitle = "System Health: Elevated Load (CPU " . round($cpu) . "%, RAM " . round($ram) . "%, Disk " . round($disk) . "%)";
                  } else {
                      $serverHealthColor = 'success';
                      $serverHealthTitle = "System Health: Good (CPU " . round($cpu) . "%, RAM " . round($ram) . "%, Disk " . round($disk) . "%)";
                  }
              }
          } catch (Throwable $e) {}
        ?>

        <!-- Storefront Shortcut Button -->
        <a href="<?= e(url('')) ?>" target="_blank" class="topbar-pill-btn" title="View Storefront">
          <i class="bi bi-shop"></i>
        </a>

        <!-- Health Badge -->
        <a href="<?= e(admin_url('monitoring.php')) ?>" class="topbar-pill-btn topbar-health-btn text-decoration-none" title="<?= e($serverHealthTitle) ?>">
          <span class="rounded-circle bg-<?= e($serverHealthColor) ?> shadow-sm" style="width: 8px; height: 8px; display: inline-block;"></span>
          <span class="small d-none d-lg-inline text-muted fw-semibold">Health</span>
        </a>

        <!-- Notifications Dropdown -->
        <div class="dropdown">
          <button class="topbar-pill-btn position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
            <i class="bi bi-bell"></i>
            <?php if ($rawNotifCount > 0): ?>
              <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem;">
                <?= $rawNotifCount > 99 ? '99+' : $rawNotifCount ?>
                <span class="visually-hidden">unread notifications</span>
              </span>
            <?php endif; ?>
          </button>
          <div class="dropdown-menu dropdown-menu-end shadow p-0 topbar-notif-dropdown">
            <div class="d-flex align-items-center justify-content-between p-3 border-bottom notif-header">
              <span class="fw-bold fs-6">Notifications</span>
              <?php if ($rawNotifCount > 0): ?>
                <span class="badge rounded-pill bg-primary"><?= $rawNotifCount ?> active</span>
              <?php endif; ?>
            </div>
            <div class="list-group list-group-flush" style="max-height: 320px; overflow-y: auto;">
              <?php if ($notifPendingOrders > 0): ?>
                <a href="<?= e(admin_url('orders.php?status=pending')) ?>" class="list-group-item list-group-item-action d-flex align-items-start gap-2 py-2">
                  <div class="text-warning mt-1"><i class="bi bi-clock-history fs-5"></i></div>
                  <div class="flex-grow-1">
                    <div class="fw-semibold small">Pending Orders</div>
                    <div class="text-muted small"><?= $notifPendingOrders ?> order<?= $notifPendingOrders > 1 ? 's' : '' ?> awaiting confirmation.</div>
                  </div>
                </a>
              <?php endif; ?>
              <?php if ($notifLowStock > 0): ?>
                <a href="<?= e(admin_url('inventory.php?filter=low')) ?>" class="list-group-item list-group-item-action d-flex align-items-start gap-2 py-2">
                  <div class="text-danger mt-1"><i class="bi bi-exclamation-triangle fs-5"></i></div>
                  <div class="flex-grow-1">
                    <div class="fw-semibold small">Low Stock Alert</div>
                    <div class="text-muted small"><?= $notifLowStock ?> product<?= $notifLowStock > 1 ? 's' : '' ?> running low on stock.</div>
                  </div>
                </a>
              <?php endif; ?>
              <?php if ($notifFailedMail > 0): ?>
                <a href="<?= e(admin_url('mail_queue.php?status=failed')) ?>" class="list-group-item list-group-item-action d-flex align-items-start gap-2 py-2">
                  <div class="text-danger mt-1"><i class="bi bi-envelope-exclamation fs-5"></i></div>
                  <div class="flex-grow-1">
                    <div class="fw-semibold small">Failed Emails</div>
                    <div class="text-muted small"><?= $notifFailedMail ?> queued email<?= $notifFailedMail > 1 ? 's' : '' ?> failed to send.</div>
                  </div>
                </a>
              <?php endif; ?>
              <?php if ($rawNotifCount === 0): ?>
                <div class="p-3 text-center text-muted small">
                  <i class="bi bi-check-circle-fill text-success d-block fs-3 mb-1"></i>
                  All caught up! No active alerts.
                </div>
              <?php endif; ?>
            </div>
            <div class="p-2 border-top text-center notif-footer">
              <a href="<?= e(admin_url('activity.php')) ?>" class="text-decoration-none small text-primary fw-medium">
                View Activity Log <i class="bi bi-arrow-right"></i>
              </a>
            </div>
          </div>
        </div>

        <!-- Theme Toggle -->
        <button class="topbar-pill-btn" id="themeToggle" type="button" title="Toggle theme">
          <i class="bi bi-moon-stars"></i>
        </button>

        <!-- User Profile Dropdown -->
        <div class="dropdown">
          <button class="topbar-avatar-btn dropdown-toggle d-flex align-items-center gap-2"
                  type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <img src="<?= e(avatar_url($me['avatar'] ?? null, $me['name'])) ?>"
                 class="rounded-circle topbar-avatar-img" width="32" height="32" alt="<?= e($me['name']) ?>">
            <span class="d-none d-sm-inline fw-semibold small text-body"><?= e($me['name']) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><h6 class="dropdown-header"><?= e($me['email']) ?></h6></li>
            <li><a class="dropdown-item" href="<?= e(admin_url('profile.php')) ?>">
              <i class="bi bi-person me-2"></i> Profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="<?= e(admin_url('logout.php')) ?>">
              <i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
          </ul>
        </div>
      </div>
    </nav>

    <div class="app-content">
      <?php foreach (get_flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
          <?= e($f['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endforeach; ?>
