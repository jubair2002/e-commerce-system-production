<?php
/**
 * Customer Account Overview Dashboard (Prompt 13).
 */
require_once __DIR__ . '/_layout.php';

$customer = customer_require_login();
$cid = (int)$customer['id'];
$pdo = db();

// Fetch latest 3 orders
$latestOrders = [];
try {
    $stmtO = $pdo->prepare(
        "SELECT id, order_number, total, status, payment_method, created_at,
                (SELECT COUNT(*) FROM order_items WHERE order_id = orders.id) AS item_count
         FROM orders
         WHERE customer_id = :cid
         ORDER BY id DESC
         LIMIT 3"
    );
    $stmtO->execute([':cid' => $cid]);
    $latestOrders = $stmtO->fetchAll();
} catch (Throwable $e) {
    $latestOrders = [];
}

// Fetch default address
$defaultAddress = null;
try {
    $stmtA = $pdo->prepare(
        "SELECT * FROM customer_addresses
         WHERE customer_id = :cid
         ORDER BY is_default DESC, id DESC
         LIMIT 1"
    );
    $stmtA->execute([':cid' => $cid]);
    $defaultAddress = $stmtA->fetch();
} catch (Throwable $e) {
    $defaultAddress = null;
}

// Fetch wishlist count
$wishlistCount = 0;
try {
    $stmtW = $pdo->prepare("SELECT COUNT(*) FROM wishlists WHERE customer_id = :cid");
    $stmtW->execute([':cid' => $cid]);
    $wishlistCount = (int)$stmtW->fetchColumn();
} catch (Throwable $e) {
    $wishlistCount = 0;
}

$firstName = explode(' ', trim($customer['name']))[0] ?: 'there';

account_layout_start('overview', 'Overview', $customer);
?>

<!-- Welcome Banner -->
<div class="account-welcome-banner mb-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
      <span class="account-badge-pill mb-2 d-inline-block">Customer Dashboard</span>
      <h1 class="h3 fw-bold mb-1 text-dark">Welcome back, <?= e($firstName) ?></h1>
      <p class="text-muted small mb-0">From your portal you can track live orders, manage saved shipping addresses, and control security settings.</p>
    </div>
    <div>
      <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-editorial-primary btn-sm">
        <?= icon('bag', 'me-1') ?> Browse Shop
      </a>
    </div>
  </div>
</div>

<!-- Email Verification Alert Banner -->
<?php if (empty($customer['verified'])): ?>
  <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 mb-4 rounded-3 border" role="alert">
    <div class="d-flex align-items-center gap-3">
      <div class="text-warning flex-shrink-0"><?= icon('alert-circle') ?></div>
      <div>
        <strong class="d-block text-dark">Verify your email address</strong>
        <div class="small text-muted">We sent an activation link to <strong><?= e($customer['email']) ?></strong>. Verify your email to ensure uninterrupted account access.</div>
      </div>
    </div>
    <form method="post" action="<?= e(shop_url('resend_verification.php')) ?>" class="m-0">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-editorial-secondary btn-sm">
        <?= icon('mail', 'me-1') ?> Resend Link
      </button>
    </form>
  </div>
<?php endif; ?>

<!-- Stats Overview Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-md-4">
    <div class="account-stat-card">
      <div class="account-stat-icon neutral">
        <?= icon('box') ?>
      </div>
      <div>
        <div class="small text-muted fw-medium">Recent Orders</div>
        <div class="fs-5 fw-bold text-dark"><?= count($latestOrders) ?> <?= count($latestOrders) === 1 ? 'active' : 'orders' ?></div>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-md-4">
    <div class="account-stat-card">
      <div class="account-stat-icon rose">
        <?= icon('heart') ?>
      </div>
      <div>
        <div class="small text-muted fw-medium">Saved in Wishlist</div>
        <div class="fs-5 fw-bold text-dark"><?= $wishlistCount ?> <?= $wishlistCount === 1 ? 'item' : 'items' ?></div>
      </div>
    </div>
  </div>

  <div class="col-sm-12 col-md-4">
    <div class="account-stat-card">
      <div class="account-stat-icon emerald">
        <?= icon('check-circle') ?>
      </div>
      <div>
        <div class="small text-muted fw-medium">Account Status</div>
        <div class="fs-5 fw-bold text-success d-flex align-items-center gap-1">
          Active
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Recent Orders Section -->
<div class="account-card mb-4">
  <div class="account-card-header">
    <h2 class="account-card-title">
      <?= icon('box', 'text-primary') ?> Recent Orders
    </h2>
    <a href="<?= e(shop_url('account/orders.php')) ?>" class="small text-decoration-none fw-semibold">
      View All Orders &rarr;
    </a>
  </div>

  <?php if (empty($latestOrders)): ?>
    <div class="text-center py-5">
      <div class="empty-state-icon mb-3" style="width: 52px; height: 52px; margin: 0 auto; display: flex; align-items: center; justify-content: center; background: var(--surface-muted); border-radius: 50%; color: var(--ink-2);">
        <?= icon('box') ?>
      </div>
      <h3 class="h5 fw-bold mb-1">No Orders Yet</h3>
      <p class="text-muted small mb-3">You haven't placed any orders with this account yet.</p>
      <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-editorial-primary btn-sm">
        <?= icon('bag', 'me-1') ?> Discover Collection
      </a>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="small text-uppercase">Order</th>
            <th class="small text-uppercase">Date</th>
            <th class="small text-uppercase">Status</th>
            <th class="small text-uppercase">Total</th>
            <th class="small text-uppercase text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($latestOrders as $o): ?>
            <?php
            $statusBadgeClass = 'bg-secondary';
            if ($o['status'] === 'completed' || $o['status'] === 'delivered') $statusBadgeClass = 'bg-success';
            elseif ($o['status'] === 'processing' || $o['status'] === 'shipped') $statusBadgeClass = 'bg-info text-dark';
            elseif ($o['status'] === 'cancelled') $statusBadgeClass = 'bg-danger';
            elseif ($o['status'] === 'pending') $statusBadgeClass = 'bg-warning text-dark';
            ?>
            <tr>
              <td class="fw-bold">
                <a href="<?= e(shop_url('account/order.php?o=' . urlencode($o['order_number']))) ?>" class="text-decoration-none text-dark">
                  <?= e($o['order_number']) ?>
                </a>
                <div class="small text-muted"><?= (int)$o['item_count'] ?> <?= (int)$o['item_count'] === 1 ? 'item' : 'items' ?></div>
              </td>
              <td class="small text-muted"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
              <td>
                <span class="badge <?= $statusBadgeClass ?> text-uppercase" style="font-size: 0.72rem;">
                  <?= e($o['status']) ?>
                </span>
              </td>
              <td class="fw-semibold"><?= e(price($o['total'])) ?></td>
              <td class="text-end">
                <a href="<?= e(shop_url('account/order.php?o=' . urlencode($o['order_number']))) ?>" class="btn btn-editorial-secondary btn-sm py-1 px-2" style="font-size: 0.8rem;">
                  Details &rarr;
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Default Address & Quick Links Row -->
<div class="row g-4">
  <div class="col-md-6">
    <div class="account-card h-100">
      <div class="account-card-header">
        <h2 class="account-card-title">
          <?= icon('map-pin', 'text-primary') ?> Shipping Address
        </h2>
        <a href="<?= e(shop_url('account/addresses.php')) ?>" class="small text-decoration-none fw-semibold">
          Manage &rarr;
        </a>
      </div>
      <?php if ($defaultAddress): ?>
        <div>
          <div class="d-flex align-items-center gap-2 mb-2">
            <strong class="text-dark"><?= e($defaultAddress['full_name']) ?></strong>
            <span class="badge bg-light text-dark border small" style="font-size: 0.7rem;"><?= e($defaultAddress['label'] ?: 'Default') ?></span>
          </div>
          <div class="text-muted small mb-1"><?= icon('phone', 'me-1') ?> <?= e($defaultAddress['phone']) ?></div>
          <div class="text-muted small">
            <?= nl2br(e($defaultAddress['address'])) ?><br>
            <?= e($defaultAddress['city']) ?><?= !empty($defaultAddress['zip']) ? ' - ' . e($defaultAddress['zip']) : '' ?><br>
            <?= e($defaultAddress['country'] ?: 'Bangladesh') ?>
          </div>
        </div>
      <?php else: ?>
        <p class="text-muted small mb-3">No default address saved yet. Save an address for one-click checkout.</p>
        <a href="<?= e(shop_url('account/addresses.php')) ?>" class="btn btn-editorial-secondary btn-sm">
          <?= icon('map-pin', 'me-1') ?> Add Address
        </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-md-6">
    <div class="account-card h-100">
      <div class="account-card-header">
        <h2 class="account-card-title">
          <?= icon('shield', 'text-primary') ?> Security &amp; Access
        </h2>
        <a href="<?= e(shop_url('account/security.php')) ?>" class="small text-decoration-none fw-semibold">
          Settings &rarr;
        </a>
      </div>
      <p class="text-muted small mb-3">Keep your customer profile secure and manage authentication credentials.</p>
      <div class="d-flex flex-column gap-2">
        <a href="<?= e(shop_url('account/security.php#password')) ?>" class="account-quick-action">
          <span class="d-flex align-items-center gap-2">
            <?= icon('lock', 'text-muted') ?>
            <span>Update Password</span>
          </span>
          <span class="text-muted small">&rarr;</span>
        </a>
        <a href="<?= e(shop_url('account/security.php#email')) ?>" class="account-quick-action">
          <span class="d-flex align-items-center gap-2">
            <?= icon('mail', 'text-muted') ?>
            <span>Email Preferences</span>
          </span>
          <span class="text-muted small">&rarr;</span>
        </a>
      </div>
    </div>
  </div>
</div>

<?php
account_layout_end();
