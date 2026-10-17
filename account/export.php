<?php
/**
 * Customer Data Export (Prompt 13).
 * Requires password re-authentication and outputs JSON file download.
 */
require_once __DIR__ . '/_layout.php';

$customer = customer_require_login();
$cid = (int)$customer['id'];
$pdo = db();

$stmtC = $pdo->prepare("SELECT * FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1");
$stmtC->execute([':id' => $cid]);
$freshCustomer = $stmtC->fetch();
if (!$freshCustomer) {
    customer_logout();
    header('Location: ' . shop_url('login.php'));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        $error = 'Invalid or expired session. Please try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        if (!customer_password_verify($password, $freshCustomer['password_hash'])) {
            $error = 'Incorrect password. Data export requires password confirmation for security.';
        } else {
            // Gather customer data
            $profile = [
                'name'             => $freshCustomer['name'],
                'email'            => $freshCustomer['email'],
                'phone'            => $freshCustomer['phone'],
                'marketing_opt_in' => (int)$freshCustomer['marketing_opt_in'],
                'created_at'       => $freshCustomer['created_at'],
                'last_login_at'    => $freshCustomer['last_login_at'],
            ];

            // Addresses
            $stmtA = $pdo->prepare(
                "SELECT label, full_name, phone, address, city, area, zip, country, is_default, created_at
                 FROM customer_addresses
                 WHERE customer_id = :cid
                 ORDER BY id ASC"
            );
            $stmtA->execute([':cid' => $cid]);
            $addresses = $stmtA->fetchAll();

            // Orders Summary
            $stmtO = $pdo->prepare(
                "SELECT order_number, subtotal, shipping_fee, discount_total, cod_fee, total,
                        payment_method, status, created_at
                 FROM orders
                 WHERE customer_id = :cid
                 ORDER BY id DESC"
            );
            $stmtO->execute([':cid' => $cid]);
            $orders = $stmtO->fetchAll();

            // Wishlist
            $stmtW = $pdo->prepare(
                "SELECT p.name, p.slug, w.created_at
                 FROM wishlists w
                 JOIN products p ON p.id = w.product_id
                 WHERE w.customer_id = :cid
                 ORDER BY w.created_at DESC"
            );
            $stmtW->execute([':cid' => $cid]);
            $wishlist = $stmtW->fetchAll();

            $exportData = [
                'exported_at' => date('c'),
                'profile'     => $profile,
                'addresses'   => $addresses,
                'orders'      => $orders,
                'wishlist'    => $wishlist,
            ];

            $filename = 'customer-data-' . $cid . '-' . date('Ymd-His') . '.json';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');

            echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

account_layout_start('security', 'Download My Data', $freshCustomer);
?>

<div class="account-card" style="max-width: 600px;">
  <div class="account-card-header">
    <h2 class="account-card-title"><i class="bi bi-download me-2 text-primary"></i> Download Personal Data</h2>
  </div>

  <p class="text-muted small mb-4">
    You can download a complete archive of your account profile, delivery addresses, order history, and saved wishlist items in JSON format.
    For your privacy and security, please re-enter your account password to confirm.
  </p>

  <?php if ($error): ?>
    <div class="alert alert-danger small py-2 mb-3">
      <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
    </div>
  <?php endif; ?>

  <form method="post" action="export.php" class="auth-form" novalidate>
    <?= csrf_field() ?>

    <div class="form-group mb-3">
      <label for="exportPassword" class="form-label small fw-semibold">Account Password <span class="text-danger">*</span></label>
      <div class="input-group">
        <input type="password"
               name="password"
               id="exportPassword"
               class="form-control"
               required
               autocomplete="current-password"
               placeholder="Enter your password">
        <button type="button" class="btn btn-outline-secondary btn-pw-toggle" data-toggle-password="exportPassword" aria-label="Toggle password visibility">
          <i class="bi bi-eye"></i>
        </button>
      </div>
    </div>

    <div class="d-flex align-items-center gap-2">
      <button type="submit" class="btn btn-editorial-primary">
        <i class="bi bi-download me-1"></i> Confirm &amp; Download JSON
      </button>
      <a href="<?= e(shop_url('account/security.php')) ?>" class="btn btn-outline-secondary btn-sm">
        Cancel
      </a>
    </div>
  </form>
</div>

<script src="<?= e(shop_url('assets/js/auth.js')) ?>"></script>
<?php
account_layout_end();
