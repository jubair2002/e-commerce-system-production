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

<!-- Google Font: Source Sans 3 (AdminLTE standard) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">

<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= filemtime(__DIR__ . '/../../assets/css/style.css') ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<div class="app-wrapper app">

  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

  <main class="app-main">

    <!-- AdminLTE v4 Top Navbar -->
    <nav class="app-header navbar navbar-expand bg-body border-bottom sticky-top">
      <div class="container-fluid">
        <!-- Start navbar links -->
        <ul class="navbar-nav align-items-center">
          <li class="nav-item">
            <a class="nav-link px-2" id="sidebarToggle" href="#" role="button" title="Toggle Sidebar">
              <i class="bi bi-list fs-5"></i>
            </a>
          </li>
          <li class="nav-item d-none d-md-block">
            <a href="<?= e(admin_url('dashboard.php')) ?>" class="nav-link px-3 <?= $pageTitle === 'Dashboard' ? 'active fw-semibold' : '' ?>">Home</a>
          </li>
          <li class="nav-item d-none d-md-block">
            <a href="<?= e(url('')) ?>" target="_blank" class="nav-link px-2 text-body-secondary">
              <i class="bi bi-box-arrow-up-right me-1 small"></i>View Store
            </a>
          </li>
        </ul>

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

          // Server Health Dot
          $serverHealthColor = 'secondary';
          $serverHealthTitle = 'System Health: Initializing...';
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
                      $serverHealthTitle = "System Health: Stale Data (" . round($lag / 60) . "m ago)";
                  } elseif ($cpu >= 90 || $ram >= 90 || $disk >= 95) {
                      $serverHealthColor = 'danger';
                      $serverHealthTitle = "System Health: High Load (CPU " . round($cpu) . "%, RAM " . round($ram) . "%, Disk " . round($disk) . "%)";
                  } elseif ($cpu >= 75 || $ram >= 75 || $disk >= 85) {
                      $serverHealthColor = 'warning';
                      $serverHealthTitle = "System Health: Elevated Load (CPU " . round($cpu) . "%, RAM " . round($ram) . "%)";
                  } else {
                      $serverHealthColor = 'success';
                      $serverHealthTitle = "System Health: Good (CPU " . round($cpu) . "%, RAM " . round($ram) . "%)";
                  }
              }
          } catch (Throwable $e) {}
        ?>

        <!-- End navbar links -->
        <ul class="navbar-nav ms-auto align-items-center gap-2">
          <!-- Server Health -->
          <li class="nav-item d-none d-sm-block">
            <a href="<?= e(admin_url('monitoring.php')) ?>" class="nav-link px-2 py-1" title="<?= e($serverHealthTitle) ?>">
              <span class="badge bg-<?= e($serverHealthColor) ?>-subtle text-<?= e($serverHealthColor) ?> border border-<?= e($serverHealthColor) ?>-subtle rounded-pill d-inline-flex align-items-center gap-1 px-2 py-1">
                <span class="status-dot bg-<?= e($serverHealthColor) ?>"></span>
                <span class="small fw-semibold">Health</span>
              </span>
            </a>
          </li>

          <!-- Notifications Dropdown -->
          <li class="nav-item dropdown">
            <a class="nav-link px-2 position-relative" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false" title="Notifications">
              <i class="bi bi-bell fs-5"></i>
              <?php if ($rawNotifCount > 0): ?>
                <span class="navbar-badge badge rounded-pill bg-danger">
                  <?= $rawNotifCount > 99 ? '99+' : $rawNotifCount ?>
                </span>
              <?php endif; ?>
            </a>
            <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end shadow-sm p-0">
              <div class="dropdown-header fw-bold border-bottom py-2 px-3 d-flex justify-content-between align-items-center">
                <span><?= $rawNotifCount ?> Notification<?= $rawNotifCount !== 1 ? 's' : '' ?></span>
                <?php if ($rawNotifCount > 0): ?>
                  <span class="badge bg-primary rounded-pill"><?= $rawNotifCount ?> active</span>
                <?php endif; ?>
              </div>
              <div class="list-group list-group-flush" style="max-height: 280px; overflow-y: auto;">
                <?php if ($notifPendingOrders > 0): ?>
                  <a href="<?= e(admin_url('orders.php?status=pending')) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2">
                    <i class="bi bi-clock-history text-warning fs-5"></i>
                    <div class="flex-grow-1 small">
                      <div class="fw-semibold">Pending Orders</div>
                      <div class="text-muted"><?= $notifPendingOrders ?> order<?= $notifPendingOrders > 1 ? 's' : '' ?> awaiting confirmation</div>
                    </div>
                  </a>
                <?php endif; ?>
                <?php if ($notifLowStock > 0): ?>
                  <a href="<?= e(admin_url('inventory.php?filter=low')) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2">
                    <i class="bi bi-exclamation-triangle text-danger fs-5"></i>
                    <div class="flex-grow-1 small">
                      <div class="fw-semibold">Low Stock Alert</div>
                      <div class="text-muted"><?= $notifLowStock ?> product<?= $notifLowStock > 1 ? 's' : '' ?> low on stock</div>
                    </div>
                  </a>
                <?php endif; ?>
                <?php if ($notifFailedMail > 0): ?>
                  <a href="<?= e(admin_url('mail_queue.php?status=failed')) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2">
                    <i class="bi bi-envelope-exclamation text-danger fs-5"></i>
                    <div class="flex-grow-1 small">
                      <div class="fw-semibold">Failed Emails</div>
                      <div class="text-muted"><?= $notifFailedMail ?> queued email<?= $notifFailedMail > 1 ? 's' : '' ?> failed</div>
                    </div>
                  </a>
                <?php endif; ?>
                <?php if ($rawNotifCount === 0): ?>
                  <div class="p-3 text-center text-muted small">
                    <i class="bi bi-check-circle-fill text-success d-block fs-4 mb-1"></i>
                    All caught up! No active alerts.
                  </div>
                <?php endif; ?>
              </div>
              <div class="border-top text-center py-2">
                <a href="<?= e(admin_url('activity.php')) ?>" class="dropdown-item dropdown-footer small text-primary fw-semibold">
                  View All Activity <i class="bi bi-arrow-right"></i>
                </a>
              </div>
            </div>
          </li>

          <!-- Theme Toggle -->
          <li class="nav-item">
            <a class="nav-link px-2" id="themeToggle" href="#" role="button" title="Toggle Light/Dark Theme">
              <i class="bi bi-moon-stars fs-5"></i>
            </a>
          </li>

          <!-- User Menu Dropdown -->
          <li class="nav-item dropdown user-menu">
            <a href="#" class="nav-link dropdown-toggle d-flex align-items-center gap-2 px-2 py-1" data-bs-toggle="dropdown" aria-expanded="false">
              <img src="<?= e(avatar_url($me['avatar'] ?? null, $me['name'])) ?>"
                   class="user-image rounded-circle border" width="28" height="28" alt="<?= e($me['name']) ?>">
              <span class="d-none d-md-inline fw-semibold small text-body"><?= e($me['name']) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
              <li class="user-header bg-primary text-white text-center p-3">
                <img src="<?= e(avatar_url($me['avatar'] ?? null, $me['name'])) ?>"
                     class="rounded-circle border border-2 border-white mb-2 shadow-sm" width="56" height="56" alt="<?= e($me['name']) ?>">
                <p class="mb-0 fw-semibold fs-6"><?= e($me['name']) ?></p>
                <small class="text-white-50"><?= e($me['email']) ?></small>
              </li>
              <li class="user-footer p-2 d-flex justify-content-between align-items-center bg-body-tertiary">
                <a href="<?= e(admin_url('profile.php')) ?>" class="btn btn-default btn-sm btn-outline-secondary">
                  <i class="bi bi-person me-1"></i>Profile
                </a>
                <a href="<?= e(admin_url('logout.php')) ?>" class="btn btn-default btn-sm btn-outline-danger">
                  <i class="bi bi-box-arrow-right me-1"></i>Sign out
                </a>
              </li>
            </ul>
          </li>
        </ul>
      </div>
    </nav>

    <!-- AdminLTE v4 Content Header (Page title + Breadcrumb) -->
    <div class="app-content-header py-3 border-bottom bg-body">
      <div class="container-fluid">
        <div class="row align-items-center">
          <div class="col-sm-6">
            <h3 class="mb-0 fs-4 fw-semibold text-body"><?= e($pageTitle) ?></h3>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end mb-0 small">
              <li class="breadcrumb-item"><a href="<?= e(admin_url('dashboard.php')) ?>" class="text-decoration-none">Home</a></li>
              <li class="breadcrumb-item active" aria-current="page"><?= e($pageTitle) ?></li>
            </ol>
          </div>
        </div>
      </div>
    </div>

    <!-- AdminLTE v4 Content Area -->
    <div class="app-content py-3">
      <div class="container-fluid">
        <?php foreach (get_flashes() as $f): ?>
          <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
            <?= e($f['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endforeach; ?>
