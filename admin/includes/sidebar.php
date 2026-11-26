
<?php
/**
 * Sidebar navigation. Highlights the current page using $_SERVER['PHP_SELF'].
 * Checks granular permissions for each module.
 */
$current = basename($_SERVER['PHP_SELF'] ?? '');

$canSeeEcommerce = has_permission(
    'analytics.view', 'orders.view', 'orders.manage',
    'customers.view', 'customers.manage',
    'products.view', 'products.create', 'products.edit',
    'inventory.view', 'inventory.manage',
    'categories.manage', 'banners.manage',
    'reviews.view', 'reviews.manage'
) || has_role('admin', 'editor');

$canSeeContent = has_permission(
    'pages.manage', 'messages.manage', 'subscribers.manage'
) || has_role('admin', 'editor');

$canSeeConfig = has_permission(
    'users.view', 'users.manage', 'settings.general', 'settings.storage',
    'mail.manage', 'monitoring.view'
) || has_role('admin');
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <a href="<?= e(admin_url('dashboard.php')) ?>" class="d-flex align-items-center gap-3 text-decoration-none">
      <div class="brand-icon">
        <i class="bi bi-shield-check"></i>
      </div>
      <div class="brand-meta">
        <div class="brand-text"><?= e(setting('site_name', APP_NAME)) ?></div>
        <div class="brand-subtext">ADMIN DASHBOARD</div>
      </div>
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

    <?php if ($canSeeEcommerce): ?>
      <div class="nav-section">E-commerce</div>

      <?php if (has_permission('analytics.view') || has_role('admin', 'editor')): ?>
        <a href="<?= e(admin_url('analytics.php')) ?>"
           class="nav-link <?= active_if($current === 'analytics.php' || $current === 'analytics_product.php') ?>">
          <i class="bi bi-graph-up-arrow"></i><span>Analytics</span>
        </a>
      <?php endif; ?>

      <?php if (has_permission('orders.view', 'orders.manage') || has_role('admin', 'editor')): ?>
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
      <?php endif; ?>

      <?php if (has_permission('customers.view', 'customers.manage') || has_role('admin', 'editor')): ?>
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
      <?php endif; ?>

      <?php if (has_permission('products.view', 'products.create', 'products.edit') || has_role('admin', 'editor')): ?>
        <a href="<?= e(admin_url('products.php')) ?>"
           class="nav-link <?= active_if($current === 'products.php' || $current === 'product_form.php' || $current === 'product_import.php') ?>">
          <i class="bi bi-box-seam"></i><span>Products</span>
        </a>
      <?php endif; ?>

      <?php if (has_permission('inventory.view', 'inventory.manage') || has_role('admin', 'editor')): ?>
        <a href="<?= e(admin_url('inventory.php')) ?>"
           class="nav-link <?= active_if($current === 'inventory.php') ?>">
          <i class="bi bi-boxes"></i><span>Inventory</span>
        </a>
      <?php endif; ?>

      <?php if (has_permission('categories.manage') || has_role('admin', 'editor')): ?>
        <a href="<?= e(admin_url('categories.php')) ?>"
           class="nav-link <?= active_if($current === 'categories.php' || $current === 'category_form.php') ?>">
          <i class="bi bi-tags"></i><span>Categories</span>
        </a>
      <?php endif; ?>

      <?php if (has_permission('banners.manage') || has_role('admin', 'editor')): ?>
        <a href="<?= e(admin_url('banners.php')) ?>"
           class="nav-link <?= active_if($current === 'banners.php' || $current === 'banner_form.php') ?>">
          <i class="bi bi-images"></i><span>Banners</span>
        </a>
      <?php endif; ?>

      <?php if (has_permission('reviews.view', 'reviews.manage') || has_role('admin', 'editor')): ?>
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
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($canSeeContent): ?>
      <div class="nav-section">Storefront & Content</div>

      <?php if (has_permission('pages.manage') || has_role('admin', 'editor')): ?>
        <a href="<?= e(admin_url('pages.php')) ?>"
           class="nav-link <?= active_if($current === 'pages.php' || $current === 'page_form.php') ?>">
          <i class="bi bi-file-earmark-text"></i><span>Pages (CMS)</span>
        </a>
      <?php endif; ?>

      <?php if (has_permission('messages.manage') || has_role('admin', 'editor')): ?>
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
      <?php endif; ?>

      <?php if (has_permission('subscribers.manage') || has_role('admin', 'editor')): ?>
        <a href="<?= e(admin_url('subscribers.php')) ?>"
           class="nav-link <?= active_if($current === 'subscribers.php') ?>">
          <i class="bi bi-envelope-check"></i><span>Subscribers</span>
        </a>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (has_permission('activity.view') || has_role('admin')): ?>
      <a href="<?= e(admin_url('activity.php')) ?>"
         class="nav-link <?= active_if($current === 'activity.php') ?>">
        <i class="bi bi-clock-history"></i><span>Activity log</span>
      </a>
    <?php endif; ?>

    <?php if ($canSeeConfig): ?>
      <div class="nav-section">Configuration</div>

      <?php if (has_permission('users.view', 'users.manage') || has_role('admin')): ?>
        <a href="<?= e(admin_url('users.php')) ?>"
           class="nav-link <?= active_if($current === 'users.php' || $current === 'user_form.php') ?>">
          <i class="bi bi-people-fill"></i><span>Users & Permissions</span>
        </a>
      <?php endif; ?>

      <?php if (has_permission('settings.general', 'settings.storage', 'coupons.manage') || has_role('admin')): ?>
        <a href="<?= e(admin_url('settings.php')) ?>"
           class="nav-link <?= active_if($current === 'settings.php') ?>">
          <i class="bi bi-gear"></i><span>Settings</span>
        </a>
      <?php endif; ?>

      <?php if (has_permission('mail.manage') || has_role('admin')): ?>
        <a href="<?= e(admin_url('mail_settings.php')) ?>"
           class="nav-link <?= active_if($current === 'mail_settings.php' || $current === 'mail_templates.php' || $current === 'mail_queue.php') ?>">
          <i class="bi bi-envelope"></i><span>Mail System</span>
        </a>
      <?php endif; ?>

      <?php if (has_permission('monitoring.view') || has_role('admin')): ?>
        <a href="<?= e(admin_url('monitoring.php')) ?>"
           class="nav-link <?= active_if($current === 'monitoring.php') ?>">
          <i class="bi bi-cpu"></i><span>Monitoring</span>
        </a>
      <?php endif; ?>
    <?php endif; ?>
  </nav>
</aside>
