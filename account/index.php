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
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">Hello, <?= e($firstName) ?> 👋</h1>
    <p class="text-muted small mb-0">From your account dashboard you can view recent orders, manage addresses, and update your profile.</p>
  </div>
  <div>
    <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-editorial-primary btn-sm">
      <i class="bi bi-bag me-1"></i> Browse Shop
    </a>
  </div>
</div>

<!-- Email Verification Alert Banner -->
<?php if (empty($customer['verified'])): ?>
  <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2 p-3 mb-4 shadow-sm" role="alert">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill fs-5 text-warning flex-shrink-0"></i>
      <div>
        <strong>Verify your email address</strong>
        <div class="small text-muted">We sent an activation link to <strong><?= e($customer['email']) ?></strong>. Verify your email to ensure uninterrupted account access.</div>
      </div>
    </div>
    <form method="post" action="<?= e(shop_url('resend_verification.php')) ?>" class="m-0">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-outline-dark btn-sm">
        <i class="bi bi-send me-1"></i> Resend Email
      </button>
    </form>
  </div>
<?php endif; ?>

<!-- Stats Overview Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-md-4">
    <div class="account-card p-3 h-100 d-flex align-items-center gap-3">
      <div class="rounded-circle bg-light d-flex align-items-center justify-content-center text-primary fs-4" style="width: 48px; height: 48px;">
        <i class="bi bi-box-seam"></i>
      </div>
      <div>
        <div class="small text-muted">Recent Orders</div>
        <div class="fs-5 fw-bold"><?= count($latestOrders) ?> active / past</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-4">
    <div class="account-card p-3 h-100 d-flex align-items-center gap-3">
      <div class="rounded-circle bg-light d-flex align-items-center justify-content-center text-danger fs-4" style="width: 48px; height: 48px;">
        <i class="bi bi-heart"></i>
      </div>
      <div>
        <div class="small text-muted">Saved in Wishlist</div>
        <div class="fs-5 fw-bold"><?= $wishlistCount ?> <?= $wishlistCount === 1 ? 'item' : 'items' ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-12 col-md-4">
    <div class="account-card p-3 h-100 d-flex align-items-center gap-3">
      <div class="rounded-circle bg-light d-flex align-items-center justify-content-center text-success fs-4" style="width: 48px; height: 48px;">
        <i class="bi bi-shield-check"></i>
      </div>
      <div>
        <div class="small text-muted">Account Status</div>
        <div class="fs-5 fw-bold text-capitalize text-success">Active</div>
      </div>
    </div>
  </div>
</div>

<!-- Recent Orders Section -->
<div class="account-card">
  <div class="account-card-header">
    <h2 class="account-card-title">Recent Orders</h2>
    <a href="<?= e(shop_url('account/orders.php')) ?>" class="small text-decoration-none fw-semibold">
      View All Orders &rarr;
    </a>
  </div>

  <?php if (empty($latestOrders)): ?>
    <div class="text-center py-4">
      <div class="fs-1 text-muted mb-2"><i class="bi bi-bag-x"></i></div>
      <p class="text-muted small mb-3">You haven't placed any orders with this account yet.</p>
      <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-outline-dark btn-sm">Start Shopping</a>
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
                <a href="<?= e(shop_url('account/order.php?o=' . urlencode($o['order_number']))) ?>" class="btn btn-outline-secondary btn-sm py-1 px-2" style="font-size: 0.8rem;">
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
        <h2 class="account-card-title">Default Shipping Address</h2>
        <a href="<?= e(shop_url('account/addresses.php')) ?>" class="small text-decoration-none fw-semibold">
          Manage &rarr;
        </a>
      </div>
      <?php if ($defaultAddress): ?>
        <div>
          <div class="d-flex align-items-center gap-2 mb-2">
            <strong><?= e($defaultAddress['full_name']) ?></strong>
            <span class="badge bg-light text-dark border small" style="font-size: 0.7rem;"><?= e($defaultAddress['label'] ?: 'Default') ?></span>
          </div>
          <div class="text-muted small mb-1"><i class="bi bi-telephone me-1"></i> <?= e($defaultAddress['phone']) ?></div>
          <div class="text-muted small">
            <?= nl2br(e($defaultAddress['address'])) ?><br>
            <?= e($defaultAddress['city']) ?><?= !empty($defaultAddress['zip']) ? ' - ' . e($defaultAddress['zip']) : '' ?><br>
            <?= e($defaultAddress['country'] ?: 'Bangladesh') ?>
          </div>
        </div>
      <?php else: ?>
        <p class="text-muted small mb-3">No default address saved yet. Save an address for one-click checkout.</p>
        <a href="<?= e(shop_url('account/addresses.php')) ?>" class="btn btn-outline-dark btn-sm">Add Address</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-md-6">
    <div class="account-card h-100">
      <div class="account-card-header">
        <h2 class="account-card-title">Account Security</h2>
        <a href="<?= e(shop_url('account/security.php')) ?>" class="small text-decoration-none fw-semibold">
          Settings &rarr;
        </a>
      </div>
      <p class="text-muted small mb-3">Keep your account secure by updating your password and reviewing active sessions.</p>
      <div class="d-flex flex-column gap-2">
        <a href="<?= e(shop_url('account/security.php#password')) ?>" class="btn btn-light btn-sm text-start border">
          <i class="bi bi-key me-2 text-secondary"></i> Change Password
        </a>
        <a href="<?= e(shop_url('account/security.php#email')) ?>" class="btn btn-light btn-sm text-start border">
          <i class="bi bi-envelope me-2 text-secondary"></i> Change Email Address
        </a>
      </div>
    </div>
  </div>
</div>

<?php
account_layout_end();
