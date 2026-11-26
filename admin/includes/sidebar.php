
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
<aside class="app-sidebar sidebar-dark-primary shadow" id="sidebar" data-bs-theme="dark">
  <div class="sidebar-brand">
    <a href="<?= e(admin_url('dashboard.php')) ?>" class="brand-link text-decoration-none d-flex align-items-center">
      <div class="brand-image-box me-2 d-inline-flex align-items-center justify-content-center">
        <i class="bi bi-shield-check fs-5 text-primary"></i>
      </div>
      <span class="brand-text fw-semibold text-white text-truncate" style="max-width: 190px;" title="<?= e(setting('site_name', APP_NAME)) ?>"><?= e(setting('site_name', APP_NAME)) ?></span>
    </a>
  </div>

  <div class="sidebar-wrapper">
    <nav class="mt-2">
      <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu" data-accordion="false">
        <li class="nav-header">MAIN</li>

        <li class="nav-item">
          <a href="<?= e(admin_url('dashboard.php')) ?>"
             class="nav-link <?= active_if($current === 'dashboard.php') ?>">
            <i class="nav-icon bi bi-speedometer2"></i>
            <p>Dashboard</p>
          </a>
        </li>

        <li class="nav-item">
          <a href="<?= e(url('')) ?>" target="_blank" class="nav-link">
            <i class="nav-icon bi bi-shop"></i>
            <p>View store <i class="bi bi-box-arrow-up-right ms-auto small opacity-50"></i></p>
          </a>
        </li>

        <?php if ($canSeeEcommerce): ?>
          <li class="nav-header">E-COMMERCE</li>

          <?php if (has_permission('analytics.view') || has_role('admin', 'editor')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('analytics.php')) ?>"
                 class="nav-link <?= active_if($current === 'analytics.php' || $current === 'analytics_product.php') ?>">
                <i class="nav-icon bi bi-graph-up-arrow"></i>
                <p>Analytics</p>
              </a>
            </li>
          <?php endif; ?>

          <?php if (has_permission('orders.view', 'orders.manage') || has_role('admin', 'editor')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('orders.php')) ?>"
                 class="nav-link <?= active_if($current === 'orders.php' || $current === 'order_view.php') ?>">
                <i class="nav-icon bi bi-receipt"></i>
                <p>
                  Orders
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
                </p>
              </a>
            </li>
          <?php endif; ?>

          <?php if (has_permission('customers.view', 'customers.manage') || has_role('admin', 'editor')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('customers.php')) ?>"
                 class="nav-link <?= active_if($current === 'customers.php' || $current === 'customer_view.php') ?>">
                <i class="nav-icon bi bi-people"></i>
                <p>
                  Customers
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
                </p>
              </a>
            </li>
          <?php endif; ?>

          <?php if (has_permission('products.view', 'products.create', 'products.edit') || has_role('admin', 'editor')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('products.php')) ?>"
                 class="nav-link <?= active_if($current === 'products.php' || $current === 'product_form.php' || $current === 'product_import.php') ?>">
                <i class="nav-icon bi bi-box-seam"></i>
                <p>Products</p>
              </a>
            </li>
          <?php endif; ?>

          <?php if (has_permission('inventory.view', 'inventory.manage') || has_role('admin', 'editor')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('inventory.php')) ?>"
                 class="nav-link <?= active_if($current === 'inventory.php') ?>">
                <i class="nav-icon bi bi-boxes"></i>
                <p>Inventory</p>
              </a>
            </li>
          <?php endif; ?>

          <?php if (has_permission('categories.manage') || has_role('admin', 'editor')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('categories.php')) ?>"
                 class="nav-link <?= active_if($current === 'categories.php' || $current === 'category_form.php') ?>">
                <i class="nav-icon bi bi-tags"></i>
                <p>Categories</p>
              </a>
            </li>
          <?php endif; ?>

          <?php if (has_permission('banners.manage') || has_role('admin', 'editor')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('banners.php')) ?>"
                 class="nav-link <?= active_if($current === 'banners.php' || $current === 'banner_form.php') ?>">
                <i class="nav-icon bi bi-images"></i>
                <p>Banners</p>
              </a>
            </li>
          <?php endif; ?>

          <?php if (has_permission('reviews.view', 'reviews.manage') || has_role('admin', 'editor')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('reviews.php')) ?>"
                 class="nav-link <?= active_if($current === 'reviews.php') ?>">
                <i class="nav-icon bi bi-star"></i>
                <p>
                  Reviews
                  <?php
                    try {
                      $pendingRevCount = (int) db()->query("SELECT COUNT(*) FROM product_reviews WHERE status = 'pending'")->fetchColumn();
                    } catch (Throwable $e) { $pendingRevCount = 0; }
                  ?>
                  <?php if ($pendingRevCount > 0): ?>
                    <span class="badge text-bg-warning ms-auto"><?= $pendingRevCount ?></span>
                  <?php endif; ?>
                </p>
              </a>
            </li>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($canSeeContent): ?>
          <li class="nav-header">CONTENT & STOREFRONT</li>

          <?php if (has_permission('pages.manage') || has_role('admin', 'editor')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('pages.php')) ?>"
                 class="nav-link <?= active_if($current === 'pages.php' || $current === 'page_form.php') ?>">
                <i class="nav-icon bi bi-file-earmark-text"></i>
                <p>Pages (CMS)</p>
              </a>
            </li>
          <?php endif; ?>

          <?php if (has_permission('messages.manage') || has_role('admin', 'editor')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('messages.php')) ?>"
                 class="nav-link <?= active_if($current === 'messages.php') ?>">
                <i class="nav-icon bi bi-chat-left-text"></i>
                <p>
                  Messages
                  <?php
                    try {
                      $newMsgCount = (int) db()->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'")->fetchColumn();
                    } catch (Throwable $e) { $newMsgCount = 0; }
                  ?>
                  <?php if ($newMsgCount > 0): ?>
                    <span class="badge text-bg-danger ms-auto"><?= $newMsgCount ?></span>
                  <?php endif; ?>
                </p>
              </a>
            </li>
          <?php endif; ?>

          <?php if (has_permission('subscribers.manage') || has_role('admin', 'editor')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('subscribers.php')) ?>"
                 class="nav-link <?= active_if($current === 'subscribers.php') ?>">
                <i class="nav-icon bi bi-envelope-check"></i>
                <p>Subscribers</p>
              </a>
            </li>
          <?php endif; ?>
        <?php endif; ?>

        <?php if (has_permission('activity.view') || has_role('admin')): ?>
          <li class="nav-item">
            <a href="<?= e(admin_url('activity.php')) ?>"
               class="nav-link <?= active_if($current === 'activity.php') ?>">
              <i class="nav-icon bi bi-clock-history"></i>
              <p>Activity log</p>
            </a>
          </li>
        <?php endif; ?>

        <?php if ($canSeeConfig): ?>
          <li class="nav-header">CONFIGURATION</li>

          <?php if (has_permission('users.view', 'users.manage') || has_role('admin')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('users.php')) ?>"
                 class="nav-link <?= active_if($current === 'users.php' || $current === 'user_form.php') ?>">
                <i class="nav-icon bi bi-people-fill"></i>
                <p>Users & Permissions</p>
              </a>
            </li>
          <?php endif; ?>

          <?php if (has_permission('settings.general', 'settings.storage', 'coupons.manage') || has_role('admin')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('settings.php')) ?>"
                 class="nav-link <?= active_if($current === 'settings.php') ?>">
                <i class="nav-icon bi bi-gear"></i>
                <p>Settings</p>
              </a>
            </li>
          <?php endif; ?>

          <?php if (has_permission('mail.manage') || has_role('admin')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('mail_settings.php')) ?>"
                 class="nav-link <?= active_if($current === 'mail_settings.php' || $current === 'mail_templates.php' || $current === 'mail_queue.php') ?>">
                <i class="nav-icon bi bi-envelope"></i>
                <p>Mail System</p>
              </a>
            </li>
          <?php endif; ?>

          <?php if (has_permission('monitoring.view') || has_role('admin')): ?>
            <li class="nav-item">
              <a href="<?= e(admin_url('monitoring.php')) ?>"
                 class="nav-link <?= active_if($current === 'monitoring.php') ?>">
                <i class="nav-icon bi bi-cpu"></i>
                <p>Monitoring</p>
              </a>
            </li>
          <?php endif; ?>
        <?php endif; ?>
      </ul>
    </nav>
  </div>
</aside>
