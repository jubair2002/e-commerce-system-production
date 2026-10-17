<?php
/**
 * Shared Customer Account Layout (Prompt 13).
 * Desktop sidebar navigation & mobile horizontal scroll tabs.
 */
require_once __DIR__ . '/../includes/shop_bootstrap.php';

function account_layout_start(string $activeTab, string $title, ?array $customer = null): void
{
    global $pageTitle;
    $pageTitle = $title . ' · My Account';

    if (!$customer) {
        $customer = customer_require_login();
    }

    $name = trim($customer['name'] ?? 'Customer');
    $parts = explode(' ', $name);
    $firstName = $parts[0] ?: 'Account';
    $email = $customer['email'] ?? '';
    $initials = strtoupper(mb_substr($name, 0, 1));

    $avatarUrl = null;
    if (!empty($customer['avatar']) && file_exists(__DIR__ . '/../assets/uploads/avatars/' . $customer['avatar'])) {
        $avatarUrl = shop_url('assets/uploads/avatars/' . $customer['avatar']);
    }

    include __DIR__ . '/../includes/partials/header.php';
    ?>
    <div class="account-wrapper">
      <div class="container">

        <!-- Mobile Navigation Tabs -->
        <div class="account-nav-mobile" role="navigation" aria-label="Account Navigation">
          <a href="<?= e(shop_url('account/')) ?>" class="account-nav-pill <?= $activeTab === 'overview' ? 'active' : '' ?>">
            <i class="bi bi-grid"></i> Overview
          </a>
          <a href="<?= e(shop_url('account/orders.php')) ?>" class="account-nav-pill <?= $activeTab === 'orders' ? 'active' : '' ?>">
            <i class="bi bi-box-seam"></i> Orders
          </a>
          <a href="<?= e(shop_url('account/wishlist.php')) ?>" class="account-nav-pill <?= $activeTab === 'wishlist' ? 'active' : '' ?>">
            <i class="bi bi-heart"></i> Wishlist
          </a>
          <a href="<?= e(shop_url('account/addresses.php')) ?>" class="account-nav-pill <?= $activeTab === 'addresses' ? 'active' : '' ?>">
            <i class="bi bi-geo-alt"></i> Addresses
          </a>
          <a href="<?= e(shop_url('account/profile.php')) ?>" class="account-nav-pill <?= $activeTab === 'profile' ? 'active' : '' ?>">
            <i class="bi bi-person"></i> Profile
          </a>
          <a href="<?= e(shop_url('account/security.php')) ?>" class="account-nav-pill <?= $activeTab === 'security' ? 'active' : '' ?>">
            <i class="bi bi-shield-lock"></i> Security
          </a>
        </div>

        <div class="row g-4">
          <!-- Desktop Sidebar -->
          <div class="col-lg-3 d-none d-lg-block">
            <div class="account-sidebar-card">
              <div class="account-user-badge">
                <div class="avatar-circle">
                  <?php if ($avatarUrl): ?>
                    <img src="<?= e($avatarUrl) ?>" alt="<?= e($name) ?>">
                  <?php else: ?>
                    <span><?= e($initials) ?></span>
                  <?php endif; ?>
                </div>
                <div class="overflow-hidden">
                  <div class="fw-bold text-truncate text-dark" title="<?= e($name) ?>"><?= e($name) ?></div>
                  <div class="small text-muted text-truncate" title="<?= e($email) ?>" style="font-size: 0.8rem;"><?= e($email) ?></div>
                </div>
              </div>

              <ul class="account-nav">
                <li>
                  <a href="<?= e(shop_url('account/')) ?>" class="account-nav-link <?= $activeTab === 'overview' ? 'active' : '' ?>">
                    <i class="bi bi-grid text-secondary"></i> Overview
                  </a>
                </li>
                <li>
                  <a href="<?= e(shop_url('account/orders.php')) ?>" class="account-nav-link <?= $activeTab === 'orders' ? 'active' : '' ?>">
                    <i class="bi bi-box-seam text-secondary"></i> Orders
                  </a>
                </li>
                <li>
                  <a href="<?= e(shop_url('account/wishlist.php')) ?>" class="account-nav-link <?= $activeTab === 'wishlist' ? 'active' : '' ?>">
                    <i class="bi bi-heart text-secondary"></i> Wishlist
                  </a>
                </li>
                <li>
                  <a href="<?= e(shop_url('account/addresses.php')) ?>" class="account-nav-link <?= $activeTab === 'addresses' ? 'active' : '' ?>">
                    <i class="bi bi-geo-alt text-secondary"></i> Addresses
                  </a>
                </li>
                <li>
                  <a href="<?= e(shop_url('account/profile.php')) ?>" class="account-nav-link <?= $activeTab === 'profile' ? 'active' : '' ?>">
                    <i class="bi bi-person text-secondary"></i> Profile
                  </a>
                </li>
                <li>
                  <a href="<?= e(shop_url('account/security.php')) ?>" class="account-nav-link <?= $activeTab === 'security' ? 'active' : '' ?>">
                    <i class="bi bi-shield-lock text-secondary"></i> Security
                  </a>
                </li>
                <li class="pt-2 mt-2 border-top">
                  <form method="post" action="<?= e(shop_url('logout.php')) ?>" class="m-0 p-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="account-nav-link text-danger border-0 bg-transparent w-100 text-start">
                      <i class="bi bi-box-arrow-right text-danger"></i> Sign Out
                    </button>
                  </form>
                </li>
              </ul>
            </div>
          </div>

          <!-- Main Content Area -->
          <div class="col-lg-9">
    <?php
}

function account_layout_end(): void
{
    ?>
          </div>
        </div>
      </div>
    </div>
    <?php
    include __DIR__ . '/../includes/partials/footer.php';
}
