<?php
/**
 * Admin: Customer View & Management (Prompt 14).
 * Profile header, order history, addresses, wishlist, activity audit, notes,
 * and secure administrative actions (block, unlock, reset password, verify, claim orders, delete).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/customer_auth.php';
require_role('admin', 'editor');

$pdo = db();
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));

if ($id <= 0) {
    flash('danger', 'Customer ID is required.');
    admin_redirect('customers.php');
}

// Fetch customer details
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1");
$stmt->execute([':id' => $id]);
$customer = $stmt->fetch();

if (!$customer) {
    flash('danger', 'Customer not found or has been permanently removed.');
    admin_redirect('customers.php');
}

$emailHash = hash('sha256', strtolower(trim($customer['email'])));

// Count unlinked guest orders with matching email
$unlinkedCount = 0;
try {
    $stmtUnlinked = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer_id IS NULL AND LOWER(TRIM(customer_email)) = :email");
    $stmtUnlinked->execute([':email' => strtolower(trim($customer['email']))]);
    $unlinkedCount = (int)$stmtUnlinked->fetchColumn();
} catch (Throwable $e) {}

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');

    // 1. Save Admin Note (Available to admin & editor)
    if ($action === 'save_note') {
        $note = trim((string)($_POST['admin_note'] ?? ''));
        if (mb_strlen($note) > 500) {
            flash('danger', 'Admin note cannot exceed 500 characters.');
        } else {
            $pdo->prepare("UPDATE customers SET admin_note = :note, updated_at = NOW() WHERE id = :id")
                ->execute([':note' => $note ?: null, ':id' => $id]);
            log_activity('customer.update_note', "Updated note for customer #{$id}");
            flash('success', 'Customer note updated.');
        }
        admin_redirect("customer_view.php?id={$id}&tab=notes");
    }

    // All following actions require 'admin' role
    if (!has_role('admin')) {
        http_response_code(403);
        flash('danger', 'Permission denied. Only administrators can perform this action.');
        admin_redirect("customer_view.php?id={$id}");
    }

    // 2. Block / Unblock Customer
    if ($action === 'toggle_block') {
        $newStatus = ($customer['status'] === 'blocked') ? 'active' : 'blocked';
        $pdo->prepare("UPDATE customers SET status = :st, updated_at = NOW() WHERE id = :id")
            ->execute([':st' => $newStatus, ':id' => $id]);

        if ($newStatus === 'blocked') {
            // Immediately revoke remember-me tokens
            $pdo->prepare("DELETE FROM customer_tokens WHERE customer_id = :id AND type = 'remember_me'")
                ->execute([':id' => $id]);
            log_activity('customer.block', "Blocked customer #{$id} ({$customer['email']})");
            flash('warning', "Customer #{$id} has been blocked and active sessions will end within 60 seconds.");
        } else {
            log_activity('customer.unblock', "Unblocked customer #{$id} ({$customer['email']})");
            flash('success', "Customer #{$id} has been activated.");
        }
        admin_redirect("customer_view.php?id={$id}");
    }

    // 3. Unlock Account
    if ($action === 'unlock') {
        $pdo->prepare("UPDATE customers SET failed_logins = 0, locked_until = NULL, updated_at = NOW() WHERE id = :id")
            ->execute([':id' => $id]);
        log_activity('customer.unlock', "Unlocked customer #{$id} ({$customer['email']})");
        flash('success', 'Account unlocked and failed login counter reset.');
        admin_redirect("customer_view.php?id={$id}");
    }

    // 4. Send Password Reset Link
    if ($action === 'send_password_reset') {
        $ok = customer_send_password_reset_email($customer);
        if ($ok) {
            log_activity('customer.send_password_reset', "Admin triggered password reset for customer #{$id}");
            flash('info', "Password reset link queued and sent to {$customer['email']}.");
        } else {
            flash('danger', 'Failed to generate password reset token.');
        }
        admin_redirect("customer_view.php?id={$id}");
    }

    // 5. Resend Email Verification
    if ($action === 'resend_verification') {
        $ok = customer_send_verification_email($customer);
        if ($ok) {
            log_activity('customer.resend_verification', "Admin resent verification to customer #{$id}");
            flash('info', "Verification email queued and sent to {$customer['email']}.");
        } else {
            flash('danger', 'Failed to generate verification token.');
        }
        admin_redirect("customer_view.php?id={$id}");
    }

    // 6. Mark Email as Verified
    if ($action === 'mark_verified') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE customers SET email_verified_at = NOW(), status = IF(status = 'pending', 'active', status), updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $id]);

            // Claim unlinked guest orders
            $claimed = 0;
            if ((int)setting('customer_claim_guest_orders_on_verify', '1') === 1) {
                $stClaim = $pdo->prepare(
                    "UPDATE orders
                     SET customer_id = :cid
                     WHERE customer_id IS NULL AND LOWER(TRIM(customer_email)) = :email"
                );
                $stClaim->execute([
                    ':cid'   => $id,
                    ':email' => strtolower(trim($customer['email'])),
                ]);
                $claimed = $stClaim->rowCount();
            }

            $pdo->commit();
            log_activity('customer.mark_verified', "Admin marked email verified for customer #{$id} (claimed {$claimed} orders)");
            flash('success', "Email marked as verified." . ($claimed > 0 ? " Claimed {$claimed} past guest order(s)." : ''));
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('danger', 'Error updating verification status.');
        }
        admin_redirect("customer_view.php?id={$id}");
    }

    // 7. Link Guest Orders
    if ($action === 'link_guest_orders') {
        try {
            $stmtLink = $pdo->prepare(
                "UPDATE orders
                 SET customer_id = :cid
                 WHERE customer_id IS NULL AND LOWER(TRIM(customer_email)) = :email"
            );
            $stmtLink->execute([
                ':cid'   => $id,
                ':email' => strtolower(trim($customer['email'])),
            ]);
            $count = $stmtLink->rowCount();
            log_activity('customer.link_orders', "Linked {$count} guest orders to customer #{$id}");
            flash('success', "Successfully linked {$count} past guest order(s) to this customer.");
        } catch (Throwable $e) {
            flash('danger', 'Failed to link guest orders.');
        }
        admin_redirect("customer_view.php?id={$id}&tab=orders");
    }

    // 8. Delete / Anonymize Customer Account (Admin Only)
    if ($action === 'delete_customer') {
        $confirmText = trim((string)($_POST['confirm_delete'] ?? ''));
        if ($confirmText !== 'DELETE') {
            flash('danger', "Please type the word 'DELETE' exactly to confirm deletion.");
            admin_redirect("customer_view.php?id={$id}");
        }

        $pdo->beginTransaction();
        try {
            // Remove avatar file if exists
            if (!empty($customer['avatar'])) {
                $oldPath = UPLOADS_PATH . '/avatars/' . $customer['avatar'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            // 1. Retain order history for bookkeeping, unlink customer_id
            $pdo->prepare("UPDATE orders SET customer_id = NULL WHERE customer_id = :cid")
                ->execute([':cid' => $id]);

            // 2. Delete addresses, wishlists, and tokens
            $pdo->prepare("DELETE FROM customer_addresses WHERE customer_id = :cid")->execute([':cid' => $id]);
            $pdo->prepare("DELETE FROM wishlists WHERE customer_id = :cid")->execute([':cid' => $id]);
            $pdo->prepare("DELETE FROM customer_tokens WHERE customer_id = :cid")->execute([':cid' => $id]);

            // 3. Anonymize customer row
            $anonEmail = 'deleted+' . $id . '@invalid';
            $stmtAnon = $pdo->prepare(
                "UPDATE customers
                 SET name = 'Deleted customer',
                     email = :anon_email,
                     phone = NULL,
                     avatar = NULL,
                     admin_note = CONCAT(COALESCE(admin_note, ''), ' [Anonymized by admin on ', NOW(), ']'),
                     status = 'blocked',
                     deleted_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :cid"
            );
            $stmtAnon->execute([
                ':anon_email' => $anonEmail,
                ':cid'        => $id,
            ]);

            $pdo->commit();
            log_activity('customer.delete', "Admin deleted/anonymized customer #{$id} (was {$customer['email']})");
            flash('success', "Customer #{$id} has been anonymized and permanently disabled.");
            admin_redirect('customers.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('danger', 'Error deleting customer: ' . $e->getMessage());
            admin_redirect("customer_view.php?id={$id}");
        }
    }
}

// -------------------------------------------------------------
// Load Tab Data
// -------------------------------------------------------------
$activeTab = trim((string)($_GET['tab'] ?? 'orders'));
$allowedTabs = ['orders', 'addresses', 'wishlist', 'activity', 'notes'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'orders';
}

// 1. Orders
$orders = [];
$totalSpend = 0.0;
try {
    $stmtO = $pdo->prepare(
        "SELECT id, order_number, total, status, payment_method, payment_status, created_at,
                (SELECT COUNT(*) FROM order_items WHERE order_id = orders.id) AS item_count
         FROM orders
         WHERE customer_id = :cid
         ORDER BY id DESC"
    );
    $stmtO->execute([':cid' => $id]);
    $orders = $stmtO->fetchAll();
    foreach ($orders as $o) {
        if ($o['status'] !== 'cancelled') {
            $totalSpend += (float)$o['total'];
        }
    }
} catch (Throwable $e) {}

// 2. Addresses
$addresses = [];
try {
    $stmtA = $pdo->prepare("SELECT * FROM customer_addresses WHERE customer_id = :cid ORDER BY is_default DESC, id DESC");
    $stmtA->execute([':cid' => $id]);
    $addresses = $stmtA->fetchAll();
} catch (Throwable $e) {}

// 3. Wishlist
$wishlistItems = [];
try {
    $stmtW = $pdo->prepare(
        "SELECT w.id AS wishlist_id, w.created_at AS added_at,
                p.id AS product_id, p.name, p.slug, p.price, p.sale_price, p.stock, p.status, p.image
         FROM wishlists w
         JOIN products p ON p.id = w.product_id
         WHERE w.customer_id = :cid
         ORDER BY w.id DESC"
    );
    $stmtW->execute([':cid' => $id]);
    $wishlistItems = $stmtW->fetchAll();
} catch (Throwable $e) {}

// 4. Activity Logs & Auth Attempts
$activityLogs = [];
$authAttempts = [];
try {
    // Activity log events mentioning this customer
    $stmtAct = $pdo->prepare(
        "SELECT a.*, u.name AS admin_name
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE (a.action LIKE 'customer.%' AND (a.description LIKE :cid_match OR a.description LIKE :email_match))
         ORDER BY a.created_at DESC
         LIMIT 50"
    );
    $stmtAct->execute([
        ':cid_match'   => "%#{$id}%",
        ':email_match' => "%{$customer['email']}%",
    ]);
    $activityLogs = $stmtAct->fetchAll();

    // Authentication attempts for this email in last 30 days
    $stmtAuth = $pdo->prepare(
        "SELECT * FROM auth_attempts
         WHERE identifier_hash = :hash
           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         ORDER BY created_at DESC
         LIMIT 50"
    );
    $stmtAuth->execute([':hash' => $emailHash]);
    $authAttempts = $stmtAuth->fetchAll();
} catch (Throwable $e) {}

$initials = strtoupper(mb_substr(trim($customer['name']), 0, 1));
$avatarUrl = null;
if (!empty($customer['avatar'])) {
    $resolvedUrl = avatar_url($customer['avatar'], $customer['name']);
    if (!str_contains($resolvedUrl, 'ui-avatars.com')) {
        $avatarUrl = $resolvedUrl;
    }
}

$pageTitle = 'Customer: ' . $customer['name'];
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div class="d-flex flex-wrap align-items-center gap-3">
    <a href="<?= e(admin_url('customers.php')) ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i> Back to Customers
    </a>
    <h3 class="mb-0 d-flex align-items-center gap-2">
      <span><?= e($customer['name']) ?></span>
      <span class="badge bg-light text-muted border fs-6">ID #<?= (int)$customer['id'] ?></span>
      <?php if ($customer['status'] === 'active'): ?>
        <span class="badge bg-success fs-6">Active</span>
      <?php elseif ($customer['status'] === 'pending'): ?>
        <span class="badge bg-warning text-dark fs-6">Pending Verification</span>
      <?php else: ?>
        <span class="badge bg-danger fs-6">Blocked</span>
      <?php endif; ?>
    </h3>
  </div>

  <!-- Top Action Buttons (Admin Only) -->
  <div class="d-flex flex-wrap gap-2">
    <?php if (has_role('admin')): ?>
      <!-- Block / Unblock Toggle -->
      <form method="post" class="m-0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle_block">
        <?php if ($customer['status'] === 'blocked'): ?>
          <button type="submit" class="btn btn-outline-success btn-sm" onclick="return confirm('Activate this customer?');">
            <i class="bi bi-check-circle me-1"></i> Unblock Customer
          </button>
        <?php else: ?>
          <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Block this customer? Their active sessions will terminate within 60 seconds.');">
            <i class="bi bi-slash-circle me-1"></i> Block Customer
          </button>
        <?php endif; ?>
      </form>

      <!-- Password Reset -->
      <form method="post" class="m-0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_password_reset">
        <button type="submit" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Send password reset email to <?= e($customer['email']) ?>?');">
          <i class="bi bi-key me-1"></i> Send Password Reset
        </button>
      </form>

      <?php if ($unlinkedCount > 0): ?>
        <form method="post" class="m-0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="link_guest_orders">
          <button type="submit" class="btn btn-outline-primary btn-sm" title="Link <?= $unlinkedCount ?> unassigned guest order(s) placed with this email">
            <i class="bi bi-link-45deg me-1"></i> Link Guest Orders (<?= $unlinkedCount ?>)
          </button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Customer Overview Header Card -->
<div class="card shadow-sm border-0 mb-4">
  <div class="card-body p-4">
    <div class="row g-4 align-items-center">
      <div class="col-auto">
        <div class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center fw-bold overflow-hidden" style="width: 72px; height: 72px; font-size: 26px;">
          <?php if ($avatarUrl): ?>
            <img src="<?= e($avatarUrl) ?>" alt="<?= e($customer['name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
          <?php else: ?>
            <span><?= e($initials) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="col">
        <div class="row g-3">
          <div class="col-sm-6 col-md-3">
            <div class="text-muted small fw-semibold">Email Address</div>
            <div class="fw-semibold text-dark text-truncate d-flex align-items-center gap-1">
              <span><?= e($customer['email']) ?></span>
              <?php if (!empty($customer['email_verified_at'])): ?>
                <i class="bi bi-patch-check-fill text-primary" title="Verified on <?= e($customer['email_verified_at']) ?>"></i>
              <?php else: ?>
                <span class="badge bg-warning text-dark py-0 px-1" style="font-size: 0.68rem;">Unverified</span>
              <?php endif; ?>
            </div>
            <?php if (empty($customer['email_verified_at']) && has_role('admin')): ?>
              <div class="mt-1 d-flex gap-2">
                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="resend_verification">
                  <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none small">Resend link</button>
                </form>
                <span class="text-muted">·</span>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mark_verified">
                  <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none small text-success" onclick="return confirm('Manually mark email as verified?');">Mark verified</button>
                </form>
              </div>
            <?php endif; ?>
          </div>

          <div class="col-sm-6 col-md-3">
            <div class="text-muted small fw-semibold">Phone Number</div>
            <div class="fw-semibold text-dark">
              <?php if (!empty($customer['phone'])): ?>
                <a href="tel:<?= e($customer['phone']) ?>" class="text-dark text-decoration-none">
                  <i class="bi bi-telephone text-secondary me-1"></i><?= e($customer['phone']) ?>
                </a>
                <a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', $customer['phone'])) ?>" target="_blank" class="ms-1 text-success" title="Message on WhatsApp">
                  <i class="bi bi-whatsapp"></i>
                </a>
              <?php else: ?>
                <span class="text-muted">Not provided</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="col-sm-6 col-md-3">
            <div class="text-muted small fw-semibold">Member Since</div>
            <div class="fw-semibold text-dark">
              <?= date('M j, Y g:i A', strtotime($customer['created_at'])) ?>
            </div>
          </div>

          <div class="col-sm-6 col-md-3">
            <div class="text-muted small fw-semibold">Marketing Subscription</div>
            <div class="fw-semibold">
              <?php if (!empty($customer['marketing_opt_in'])): ?>
                <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i> Subscribed</span>
              <?php else: ?>
                <span class="text-muted"><i class="bi bi-dash-circle me-1"></i> Unsubscribed</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Security & Login Status Row -->
    <hr class="my-3">
    <div class="row g-3 small">
      <div class="col-sm-6 col-md-3">
        <span class="text-muted">Last Login:</span>
        <strong class="text-dark">
          <?= !empty($customer['last_login_at']) ? date('M j, Y g:i A', strtotime($customer['last_login_at'])) : 'Never' ?>
        </strong>
      </div>
      <div class="col-sm-6 col-md-3">
        <span class="text-muted">Last Known IP:</span>
        <strong class="text-dark"><?= e($customer['last_login_ip'] ?? 'N/A') ?></strong>
      </div>
      <div class="col-sm-6 col-md-3">
        <span class="text-muted">Failed Logins:</span>
        <strong class="<?= $customer['failed_logins'] > 0 ? 'text-danger' : 'text-dark' ?>">
          <?= (int)$customer['failed_logins'] ?> attempt(s)
        </strong>
      </div>
      <div class="col-sm-6 col-md-3 d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted">Account Lock:</span>
          <?php if (!empty($customer['locked_until']) && strtotime($customer['locked_until']) > time()): ?>
            <span class="badge bg-danger">Locked until <?= date('g:i A', strtotime($customer['locked_until'])) ?></span>
          <?php else: ?>
            <span class="text-success">Unlocked</span>
          <?php endif; ?>
        </div>
        <?php if (!empty($customer['locked_until']) && strtotime($customer['locked_until']) > time() && has_role('admin')): ?>
          <form method="post" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unlock">
            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size: 0.75rem;">Unlock Now</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-tabs mb-4" role="tablist">
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'orders' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url("customer_view.php?id={$id}&tab=orders")) ?>">
      <i class="bi bi-receipt me-1"></i> Orders (<?= count($orders) ?>)
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'addresses' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url("customer_view.php?id={$id}&tab=addresses")) ?>">
      <i class="bi bi-geo-alt me-1"></i> Addresses (<?= count($addresses) ?>)
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'wishlist' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url("customer_view.php?id={$id}&tab=wishlist")) ?>">
      <i class="bi bi-heart me-1"></i> Wishlist (<?= count($wishlistItems) ?>)
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'activity' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url("customer_view.php?id={$id}&tab=activity")) ?>">
      <i class="bi bi-clock-history me-1"></i> Activity & Security
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'notes' ? 'active fw-bold' : '' ?>" href="<?= e(admin_url("customer_view.php?id={$id}&tab=notes")) ?>">
      <i class="bi bi-journal-text me-1"></i> Admin Notes
    </a>
  </li>
</ul>

<!-- Tab 1: Orders -->
<?php if ($activeTab === 'orders'): ?>
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
      <h6 class="mb-0 fw-semibold">Order History (<?= count($orders) ?> orders placed)</h6>
      <div class="fw-bold text-dark">
        Total Lifetime Spend: <span class="text-primary"><?= e(price($totalSpend)) ?></span>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Order #</th>
            <th>Date</th>
            <th>Items</th>
            <th>Payment</th>
            <th>Status</th>
            <th class="text-end">Total</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">
                <i class="bi bi-bag-x fs-1 d-block mb-2"></i>
                No orders linked to this customer yet.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($orders as $o): ?>
              <?php
              $badge = 'bg-secondary';
              if ($o['status'] === 'completed' || $o['status'] === 'delivered') $badge = 'bg-success';
              elseif ($o['status'] === 'processing' || $o['status'] === 'shipped') $badge = 'bg-info text-dark';
              elseif ($o['status'] === 'cancelled') $badge = 'bg-danger';
              elseif ($o['status'] === 'pending') $badge = 'bg-warning text-dark';
              ?>
              <tr>
                <td class="fw-semibold">
                  <a href="<?= e(admin_url('order_view.php?id=' . (int)$o['id'])) ?>" class="text-decoration-none">
                    <?= e($o['order_number']) ?>
                  </a>
                </td>
                <td class="small text-muted"><?= date('M j, Y g:i A', strtotime($o['created_at'])) ?></td>
                <td><?= (int)$o['item_count'] ?> item<?= (int)$o['item_count'] === 1 ? '' : 's' ?></td>
                <td>
                  <span class="badge bg-light text-dark border text-uppercase" style="font-size: 0.7rem;">
                    <?= e($o['payment_method']) ?>
                  </span>
                </td>
                <td>
                  <span class="badge <?= $badge ?> text-uppercase" style="font-size: 0.72rem;">
                    <?= e($o['status']) ?>
                  </span>
                </td>
                <td class="text-end fw-bold"><?= e(price($o['total'])) ?></td>
                <td class="text-end">
                  <a href="<?= e(admin_url('order_view.php?id=' . (int)$o['id'])) ?>" class="btn btn-outline-secondary btn-sm py-1 px-2" style="font-size: 0.8rem;">
                    <i class="bi bi-eye me-1"></i> View Order
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<!-- Tab 2: Addresses -->
<?php elseif ($activeTab === 'addresses'): ?>
  <div class="row g-3">
    <?php if (empty($addresses)): ?>
      <div class="col-12">
        <div class="card shadow-sm border-0 p-5 text-center text-muted">
          <i class="bi bi-geo-alt fs-1 d-block mb-2"></i>
          This customer has not saved any delivery addresses yet.
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($addresses as $addr): ?>
        <div class="col-md-6 col-lg-4">
          <div class="card shadow-sm border-0 h-100 <?= $addr['is_default'] ? 'border-primary border-2' : '' ?>">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
              <span class="badge bg-secondary"><?= e($addr['label']) ?></span>
              <?php if ($addr['is_default']): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle">Default Address</span>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <div class="fw-bold text-dark fs-6"><?= e($addr['full_name']) ?></div>
              <div class="small text-muted mb-2"><i class="bi bi-telephone me-1"></i><?= e($addr['phone']) ?></div>
              <div class="small text-dark mb-2">
                <?= nl2br(e($addr['address'])) ?><br>
                <?= e($addr['city']) ?><?= !empty($addr['area']) ? ', ' . e($addr['area']) : '' ?><?= !empty($addr['zip']) ? ' - ' . e($addr['zip']) : '' ?><br>
                <?= e($addr['country']) ?>
              </div>
              <div class="text-muted" style="font-size: 0.75rem;">
                Saved on <?= date('M j, Y', strtotime($addr['created_at'])) ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<!-- Tab 3: Wishlist -->
<?php elseif ($activeTab === 'wishlist'): ?>
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-bottom">
      <h6 class="mb-0 fw-semibold">Saved Wishlist Items (<?= count($wishlistItems) ?>)</h6>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width: 60px;">Image</th>
            <th>Product Name</th>
            <th>Current Price</th>
            <th>Stock Status</th>
            <th>Added On</th>
            <th class="text-end">Store Link</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($wishlistItems)): ?>
            <tr>
              <td colspan="6" class="text-center py-5 text-muted">
                <i class="bi bi-heart fs-1 d-block mb-2 text-muted"></i>
                No items saved to wishlist.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($wishlistItems as $w): ?>
              <tr>
                <td>
                  <?php if (!empty($w['image'])): ?>
                    <img src="<?= e(product_image_url($w['image'])) ?>" alt="" class="rounded" style="width: 44px; height: 44px; object-fit: cover;">
                  <?php else: ?>
                    <div class="rounded bg-light d-flex align-items-center justify-content-center text-muted" style="width: 44px; height: 44px;">
                      <i class="bi bi-image"></i>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="fw-semibold">
                  <?= e($w['name']) ?>
                </td>
                <td>
                  <?php if ($w['sale_price'] !== null && (float)$w['sale_price'] < (float)$w['price']): ?>
                    <span class="text-danger fw-bold"><?= e(price($w['sale_price'])) ?></span>
                    <span class="text-muted text-decoration-line-through small"><?= e(price($w['price'])) ?></span>
                  <?php else: ?>
                    <span class="fw-bold"><?= e(price($w['price'])) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($w['status'] !== 'active'): ?>
                    <span class="badge bg-secondary">Inactive</span>
                  <?php elseif ((int)$w['stock'] <= 0): ?>
                    <span class="badge bg-danger">Out of stock</span>
                  <?php else: ?>
                    <span class="badge bg-success"><?= (int)$w['stock'] ?> in stock</span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted"><?= date('M j, Y', strtotime($w['added_at'])) ?></td>
                <td class="text-end">
                  <a href="<?= e(shop_url('product.php?slug=' . urlencode($w['slug']))) ?>" target="_blank" class="btn btn-outline-secondary btn-sm py-1 px-2" style="font-size: 0.8rem;">
                    View on Store <i class="bi bi-box-arrow-up-right ms-1"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<!-- Tab 4: Activity & Security -->
<?php elseif ($activeTab === 'activity'): ?>
  <div class="row g-4">
    <!-- Activity Log Events -->
    <div class="col-lg-7">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-header bg-white py-3 border-bottom">
          <h6 class="mb-0 fw-semibold"><i class="bi bi-journal-check text-primary me-2"></i> Administrative Activity Log</h6>
        </div>
        <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
          <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light sticky-top">
              <tr>
                <th>Date & Time</th>
                <th>Action</th>
                <th>Actor</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($activityLogs)): ?>
                <tr>
                  <td colspan="4" class="text-center py-4 text-muted">No audit activity logged for this customer.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($activityLogs as $act): ?>
                  <tr>
                    <td class="text-muted text-nowrap"><?= date('M j, Y g:i A', strtotime($act['created_at'])) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= e($act['action']) ?></span></td>
                    <td><?= e($act['admin_name'] ?: 'System') ?></td>
                    <td class="text-truncate" style="max-width: 200px;" title="<?= e($act['description']) ?>">
                      <?= e($act['description']) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Auth Attempts Last 30 Days -->
    <div class="col-lg-5">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-header bg-white py-3 border-bottom">
          <h6 class="mb-0 fw-semibold"><i class="bi bi-shield-lock text-primary me-2"></i> Authentication Attempts (30 Days)</h6>
        </div>
        <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
          <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light sticky-top">
              <tr>
                <th>Date</th>
                <th>Scope</th>
                <th>IP</th>
                <th>Result</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($authAttempts)): ?>
                <tr>
                  <td colspan="4" class="text-center py-4 text-muted">No auth attempts recorded in the last 30 days.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($authAttempts as $att): ?>
                  <tr>
                    <td class="text-muted text-nowrap"><?= date('M j, g:i A', strtotime($att['created_at'])) ?></td>
                    <td><span class="badge bg-light text-secondary border text-uppercase" style="font-size: 0.68rem;"><?= e($att['scope']) ?></span></td>
                    <td><code><?= e($att['ip']) ?></code></td>
                    <td>
                      <?php if (!empty($att['success'])): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Success</span>
                      <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Failed</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

<!-- Tab 5: Admin Notes -->
<?php elseif ($activeTab === 'notes'): ?>
  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 border-bottom">
          <h6 class="mb-0 fw-semibold"><i class="bi bi-pencil-square text-primary me-2"></i> Internal Staff Notes</h6>
        </div>
        <div class="card-body">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_note">
            <div class="mb-3">
              <label for="adminNoteText" class="form-label small fw-semibold">Note Content</label>
              <textarea name="admin_note" id="adminNoteText" class="form-control" rows="5" maxlength="500" placeholder="e.g. VIP customer, prefers morning delivery, verified phone by call..."><?= e($customer['admin_note'] ?? '') ?></textarea>
              <div class="form-text">Visible only to administrative staff. Max 500 characters.</div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
              <i class="bi bi-save me-1"></i> Save Note
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Danger Zone (Admin Only) -->
    <?php if (has_role('admin')): ?>
      <div class="col-lg-5">
        <div class="card shadow-sm border-danger border-opacity-25 h-100">
          <div class="card-header bg-danger bg-opacity-10 py-3 border-bottom border-danger border-opacity-25">
            <h6 class="mb-0 fw-semibold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i> Danger Zone</h6>
          </div>
          <div class="card-body">
            <p class="small text-muted">
              Permanently delete and anonymize this customer account. All orders will be retained unlinked for accounting, while personal data, addresses, and wishlist items are erased.
            </p>
            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteCustomerModal">
              <i class="bi bi-trash me-1"></i> Delete & Anonymize Customer
            </button>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- Delete Customer Confirmation Modal -->
<?php if (has_role('admin')): ?>
  <div class="modal fade" id="deleteCustomerModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_customer">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title" id="deleteModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Account Anonymization</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>You are about to permanently anonymize and delete the customer record for <strong><?= e($customer['name']) ?></strong> (<?= e($customer['email']) ?>).</p>
            <ul class="small text-muted">
              <li>Order history is preserved for financial records, unlinked from customer ID.</li>
              <li>Addresses, wishlist items, and remember-me tokens will be deleted.</li>
              <li>Customer email will be masked to <code>deleted+<?= (int)$customer['id'] ?>@invalid</code>.</li>
            </ul>
            <div class="mb-3">
              <label for="confirmDeleteInput" class="form-label small fw-bold text-danger">Type "DELETE" below to confirm:</label>
              <input type="text" name="confirm_delete" id="confirmDeleteInput" class="form-control" autocomplete="off" required placeholder="DELETE">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger btn-sm">Permanently Anonymize</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
