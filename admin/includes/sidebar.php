
<?php
/**
 * Sidebar navigation. Highlights the current page using $_SERVER['PHP_SELF'].
 */
$current = basename($_SERVER['PHP_SELF'] ?? '');
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <a href="<?= e(admin_url('dashboard.php')) ?>" class="d-flex align-items-center gap-2 text-decoration-none">
      <span class="brand-icon"><i class="bi bi-shield-lock-fill"></i></span>
      <span class="brand-text"><?= e(setting('site_name', APP_NAME)) ?></span>
    </a>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>

    <a href="<?= e(admin_url('dashboard.php')) ?>"
       class="nav-link <?= active_if($current === 'dashboard.php') ?>">
      <i class="bi bi-speedometer2"></i><span>Dashboard</span>
    </a>

    <a href="<?= e(url('')) ?>" target="_blank" class="nav-link">
      <i class="bi bi-shop"></i><span>View store</span>
      <i class="bi bi-box-arrow-up-right ms-auto small text-muted"></i>
    </a>

    <?php if (has_role('admin', 'editor')): ?>
      <div class="nav-section">E-commerce</div>

      <a href="<?= e(admin_url('orders.php')) ?>"
         class="nav-link <?= active_if($current === 'orders.php' || $current === 'order_view.php') ?>">
        <i class="bi bi-receipt"></i><span>Orders</span>
        <?php
          try {
            $pendingCount = (int) db()->query(
              "SELECT COUNT(*) FROM orders WHERE status = 'pending'"
            )->fetchColumn();
          } catch (Throwable $e) { $pendingCount = 0; }
        ?>
        <?php if ($pendingCount > 0): ?>
          <span class="badge text-bg-warning ms-auto"><?= $pendingCount ?></span>
        <?php endif; ?>
      </a>

      <a href="<?= e(admin_url('customers.php')) ?>"
         class="nav-link <?= active_if($current === 'customers.php' || $current === 'customer_view.php') ?>">
        <i class="bi bi-people"></i><span>Customers</span>
        <?php
          try {
            $pendingCustCount = (int) db()->query(
              "SELECT COUNT(*) FROM customers WHERE status = 'pending' AND deleted_at IS NULL"
            )->fetchColumn();
          } catch (Throwable $e) { $pendingCustCount = 0; }
        ?>
        <?php if ($pendingCustCount > 0): ?>
          <span class="badge text-bg-warning ms-auto" title="<?= $pendingCustCount ?> pending verification"><?= $pendingCustCount ?></span>
        <?php endif; ?>
      </a>

      <a href="<?= e(admin_url('products.php')) ?>"
         class="nav-link <?= active_if($current === 'products.php' || $current === 'product_form.php') ?>">
        <i class="bi bi-box-seam"></i><span>Products</span>
      </a>

      <a href="<?= e(admin_url('inventory.php')) ?>"
         class="nav-link <?= active_if($current === 'inventory.php') ?>">
        <i class="bi bi-boxes"></i><span>Inventory</span>
      </a>

      <a href="<?= e(admin_url('categories.php')) ?>"
         class="nav-link <?= active_if($current === 'categories.php' || $current === 'category_form.php') ?>">
        <i class="bi bi-tags"></i><span>Categories</span>
      </a>

      <a href="<?= e(admin_url('banners.php')) ?>"
         class="nav-link <?= active_if($current === 'banners.php' || $current === 'banner_form.php') ?>">
        <i class="bi bi-images"></i><span>Banners</span>
      </a>

      <a href="<?= e(admin_url('reviews.php')) ?>"
         class="nav-link <?= active_if($current === 'reviews.php') ?>">
        <i class="bi bi-star"></i><span>Reviews</span>
        <?php
          try {
            $pendingRevCount = (int) db()->query("SELECT COUNT(*) FROM product_reviews WHERE status = 'pending'")->fetchColumn();
          } catch (Throwable $e) { $pendingRevCount = 0; }
        ?>
        <?php if ($pendingRevCount > 0): ?>
          <span class="badge text-bg-warning ms-auto"><?= $pendingRevCount ?></span>
        <?php endif; ?>
      </a>

      <div class="nav-section">Storefront & Content</div>

      <a href="<?= e(admin_url('pages.php')) ?>"
         class="nav-link <?= active_if($current === 'pages.php' || $current === 'page_form.php') ?>">
        <i class="bi bi-file-earmark-text"></i><span>Pages (CMS)</span>
      </a>

      <a href="<?= e(admin_url('messages.php')) ?>"
         class="nav-link <?= active_if($current === 'messages.php') ?>">
        <i class="bi bi-chat-left-text"></i><span>Messages</span>
        <?php
          try {
            $newMsgCount = (int) db()->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'")->fetchColumn();
          } catch (Throwable $e) { $newMsgCount = 0; }
        ?>
        <?php if ($newMsgCount > 0): ?>
          <span class="badge text-bg-danger ms-auto"><?= $newMsgCount ?></span>
        <?php endif; ?>
      </a>

      <a href="<?= e(admin_url('subscribers.php')) ?>"
         class="nav-link <?= active_if($current === 'subscribers.php') ?>">
        <i class="bi bi-envelope-check"></i><span>Subscribers</span>
      </a>

    <?php endif; ?>

    <a href="<?= e(admin_url('activity.php')) ?>"
       class="nav-link <?= active_if($current === 'activity.php') ?>">
      <i class="bi bi-clock-history"></i><span>Activity log</span>
    </a>

    <div class="nav-section">Configuration</div>

    <?php if (has_role('admin')): ?>
      <a href="<?= e(admin_url('settings.php')) ?>"
         class="nav-link <?= active_if($current === 'settings.php') ?>">
        <i class="bi bi-gear"></i><span>Settings</span>
      </a>

      <a href="<?= e(admin_url('mail_settings.php')) ?>"
         class="nav-link <?= active_if($current === 'mail_settings.php' || $current === 'mail_templates.php' || $current === 'mail_queue.php') ?>">
        <i class="bi bi-envelope"></i><span>Mail System</span>
      </a>

      <a href="<?= e(admin_url('monitoring.php')) ?>"
         class="nav-link <?= active_if($current === 'monitoring.php') ?>">
        <i class="bi bi-cpu"></i><span>Monitoring</span>
      </a>
    <?php endif; ?>

    <div class="nav-section">Account</div>

    <a href="<?= e(admin_url('profile.php')) ?>"
       class="nav-link <?= active_if($current === 'profile.php') ?>">
      <i class="bi bi-person-circle"></i><span>Profile</span>
    </a>

    <a href="<?= e(admin_url('logout.php')) ?>" class="nav-link text-danger">
      <i class="bi bi-box-arrow-right"></i><span>Logout</span>
    </a>
  </nav>
</aside>
