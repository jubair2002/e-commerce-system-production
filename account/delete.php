<?php
/**
 * Customer Account Deletion (Prompt 13).
 * Requires password and typed confirmation 'DELETE'.
 * Orders are unlinked and retained for accounting; customer record is anonymized.
 */
require_once __DIR__ . '/_layout.php';

$customer = customer_require_login();
$cid = (int)$customer['id'];

if ((int)setting('customer_allow_account_delete', '1') !== 1) {
    flash('info', 'Account deletion is currently disabled by store administration.');
    header('Location: ' . shop_url('account/security.php'));
    exit;
}

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
        $confirmWord = trim((string)($_POST['confirm_word'] ?? ''));

        if (!customer_password_verify($password, $freshCustomer['password_hash'])) {
            $error = 'Incorrect account password.';
        } elseif ($confirmWord !== 'DELETE') {
            $error = 'You must type DELETE (in capital letters) to confirm account closure.';
        } else {
            $oldEmail = $freshCustomer['email'];
            $oldName  = $freshCustomer['name'];
            $siteName = setting('site_name', 'Store');

            // Queue farewell / confirmation email to old address prior to data deletion
            try {
                mail_queue(
                    $oldEmail,
                    $oldName,
                    "Your {$siteName} account has been deleted",
                    "<p>Hi " . htmlspecialchars($oldName, ENT_QUOTES, 'UTF-8') . ",</p><p>As requested, your customer account with {$siteName} has been permanently deleted and your personal information has been anonymized.</p><p>If this was not done by you, please contact support immediately.</p>"
                );
            } catch (Throwable $e) {}

            // Delete avatar image file if exists
            if (!empty($freshCustomer['avatar'])) {
                $avatarFile = __DIR__ . '/../assets/uploads/avatars/' . $freshCustomer['avatar'];
                if (file_exists($avatarFile)) {
                    @unlink($avatarFile);
                }
            }

            // Atomic anonymization & cleanup
            $pdo->beginTransaction();
            try {
                // 1. Unlink orders (kept for financial & VAT accounting records)
                $pdo->prepare("UPDATE orders SET customer_id = NULL WHERE customer_id = :cid")
                    ->execute([':cid' => $cid]);

                // 2. Remove addresses, wishlist, and tokens
                $pdo->prepare("DELETE FROM customer_addresses WHERE customer_id = :cid")->execute([':cid' => $cid]);
                $pdo->prepare("DELETE FROM wishlists WHERE customer_id = :cid")->execute([':cid' => $cid]);
                $pdo->prepare("DELETE FROM customer_tokens WHERE customer_id = :cid")->execute([':cid' => $cid]);

                // 3. Anonymize customer row
                $anonEmail = 'deleted+' . $cid . '@invalid';
                $stmtAnon = $pdo->prepare(
                    "UPDATE customers
                     SET name = 'Deleted customer',
                         email = :anon_email,
                         phone = NULL,
                         avatar = NULL,
                         status = 'blocked',
                         deleted_at = NOW(),
                         updated_at = NOW()
                     WHERE id = :cid"
                );
                $stmtAnon->execute([':anon_email' => $anonEmail, ':cid' => $cid]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Failed to delete account. Please try again.';
            }

            if (!$error) {
                customer_logout();
                flash('info', 'Your account has been permanently deleted. We are sorry to see you go!');
                header('Location: ' . shop_url());
                exit;
            }
        }
    }
}

account_layout_start('security', 'Delete Account', $freshCustomer);
?>

<div class="account-card" style="max-width: 600px;">
  <div class="account-card-header border-danger">
    <h2 class="account-card-title text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i> Delete Customer Account</h2>
  </div>

  <div class="alert alert-danger bg-danger bg-opacity-10 border-danger small mb-4">
    <strong>Warning: This action is permanent and cannot be undone.</strong>
    <ul class="mb-0 mt-2 ps-3">
      <li>Your personal information, profile photo, and delivery addresses will be permanently erased.</li>
      <li>Saved wishlist items and preferences will be deleted.</li>
      <li>Your past order history will be unlinked and retained anonymously for accounting and tax records.</li>
    </ul>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger small py-2 mb-3">
      <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
    </div>
  <?php endif; ?>

  <form method="post" action="delete.php" class="auth-form" novalidate>
    <?= csrf_field() ?>

    <div class="form-group mb-3">
      <label for="delPassword" class="form-label small fw-semibold">Confirm Password <span class="text-danger">*</span></label>
      <div class="input-group">
        <input type="password"
               name="password"
               id="delPassword"
               class="form-control"
               required
               autocomplete="current-password"
               placeholder="Enter your password">
        <button type="button" class="btn btn-outline-secondary btn-pw-toggle" data-toggle-password="delPassword" aria-label="Toggle password visibility">
          <i class="bi bi-eye"></i>
        </button>
      </div>
    </div>

    <div class="form-group mb-4">
      <label for="confirmWord" class="form-label small fw-semibold">
        Type <span class="badge bg-danger">DELETE</span> to confirm <span class="text-danger">*</span>
      </label>
      <input type="text"
             name="confirm_word"
             id="confirmWord"
             class="form-control"
             required
             autocomplete="off"
             placeholder="Type DELETE in capital letters">
    </div>

    <div class="d-flex align-items-center gap-2">
      <button type="submit" class="btn btn-danger">
        <i class="bi bi-trash3 me-1"></i> Permanently Delete Account
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
