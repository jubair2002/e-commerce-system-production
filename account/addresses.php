<?php
/**
 * Customer Address Book Management (Prompt 13).
 * Full CRUD, max 10 addresses, transactional single default enforcement, IDOR checks.
 */
require_once __DIR__ . '/_layout.php';

$customer = customer_require_login();
$cid = (int)$customer['id'];
$pdo = db();

$errors = [];
$editAddress = null;

// Handle GET for editing an address
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmtE = $pdo->prepare("SELECT * FROM customer_addresses WHERE id = :id AND customer_id = :cid LIMIT 1");
    $stmtE->execute([':id' => $editId, ':cid' => $cid]);
    $editAddress = $stmtE->fetch();
    if (!$editAddress) {
        flash('warning', 'Address not found or access denied.');
        header('Location: ' . shop_url('account/addresses.php'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        flash('danger', 'Invalid or expired session. Please try again.');
        header('Location: ' . shop_url('account/addresses.php'));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Delete Address
    if ($action === 'delete') {
        $delId = (int)($_POST['address_id'] ?? 0);
        $stmtChk = $pdo->prepare("SELECT is_default FROM customer_addresses WHERE id = :id AND customer_id = :cid LIMIT 1");
        $stmtChk->execute([':id' => $delId, ':cid' => $cid]);
        $row = $stmtChk->fetch();

        if ($row) {
            $wasDefault = (int)$row['is_default'] === 1;
            $pdo->prepare("DELETE FROM customer_addresses WHERE id = :id AND customer_id = :cid")
                ->execute([':id' => $delId, ':cid' => $cid]);

            // If deleted address was default, promote the newest remaining address
            if ($wasDefault) {
                $pdo->prepare(
                    "UPDATE customer_addresses
                     SET is_default = 1
                     WHERE customer_id = :cid
                     ORDER BY id DESC
                     LIMIT 1"
                )->execute([':cid' => $cid]);
            }
            flash('success', 'Address deleted successfully.');
        } else {
            flash('danger', 'Unable to delete address. Record not found.');
        }
        header('Location: ' . shop_url('account/addresses.php'));
        exit;
    }

    // 2. Set Default Address
    elseif ($action === 'set_default') {
        $defId = (int)($_POST['address_id'] ?? 0);
        $stmtChk = $pdo->prepare("SELECT id FROM customer_addresses WHERE id = :id AND customer_id = :cid LIMIT 1");
        $stmtChk->execute([':id' => $defId, ':cid' => $cid]);
        if ($stmtChk->fetch()) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE customer_addresses SET is_default = 0 WHERE customer_id = :cid")
                    ->execute([':cid' => $cid]);
                $pdo->prepare("UPDATE customer_addresses SET is_default = 1 WHERE id = :id AND customer_id = :cid")
                    ->execute([':id' => $defId, ':cid' => $cid]);
                $pdo->commit();
                flash('success', 'Default shipping address updated.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('danger', 'Failed to update default address.');
            }
        }
        header('Location: ' . shop_url('account/addresses.php'));
        exit;
    }

    // 3. Save Address (Add or Edit)
    elseif ($action === 'save') {
        $saveId      = (int)($_POST['address_id'] ?? 0);
        $label       = trim((string)($_POST['label'] ?? 'Home'));
        $fullName    = trim((string)($_POST['full_name'] ?? ''));
        $phone       = trim((string)($_POST['phone'] ?? ''));
        $address     = trim((string)($_POST['address'] ?? ''));
        $city        = trim((string)($_POST['city'] ?? ''));
        $area        = trim((string)($_POST['area'] ?? ''));
        $zip         = trim((string)($_POST['zip'] ?? ''));
        $country     = trim((string)($_POST['country'] ?? 'Bangladesh')) ?: 'Bangladesh';
        $makeDefault = !empty($_POST['is_default']);

        if (mb_strlen($fullName) < 2) {
            $errors['full_name'] = 'Full name is required.';
        }
        if (empty($phone)) {
            $errors['phone'] = 'Phone number is required for delivery.';
        }
        if (mb_strlen($address) < 5) {
            $errors['address'] = 'Please enter a complete street address.';
        }
        if (empty($city)) {
            $errors['city'] = 'City or District is required.';
        }

        // Check max 10 addresses limit on add
        if ($saveId <= 0) {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM customer_addresses WHERE customer_id = :cid");
            $countStmt->execute([':cid' => $cid]);
            $currentCount = (int)$countStmt->fetchColumn();
            if ($currentCount >= 10) {
                $errors['general'] = 'You can save a maximum of 10 delivery addresses. Please edit or delete an existing one.';
            }
            if ($currentCount === 0) {
                $makeDefault = true; // First address is always default
            }
        }

        if (empty($errors)) {
            $pdo->beginTransaction();
            try {
                if ($makeDefault) {
                    $pdo->prepare("UPDATE customer_addresses SET is_default = 0 WHERE customer_id = :cid")
                        ->execute([':cid' => $cid]);
                }

                if ($saveId > 0) {
                    // Update existing with strict ownership check
                    $stmtUp = $pdo->prepare(
                        "UPDATE customer_addresses
                         SET label = :label, full_name = :name, phone = :phone, address = :addr,
                             city = :city, area = :area, zip = :zip, country = :country,
                             is_default = IF(:is_def = 1, 1, is_default), updated_at = NOW()
                         WHERE id = :id AND customer_id = :cid"
                    );
                    $stmtUp->execute([
                        ':label'   => $label,
                        ':name'    => $fullName,
                        ':phone'   => $phone,
                        ':addr'    => $address,
                        ':city'    => $city,
                        ':area'    => $area ?: null,
                        ':zip'     => $zip ?: null,
                        ':country' => $country,
                        ':is_def'  => $makeDefault ? 1 : 0,
                        ':id'      => $saveId,
                        ':cid'     => $cid,
                    ]);
                    flash('success', 'Address updated successfully.');
                } else {
                    // Insert new address
                    $stmtIns = $pdo->prepare(
                        "INSERT INTO customer_addresses
                          (customer_id, label, full_name, phone, address, city, area, zip, country, is_default, created_at)
                         VALUES
                          (:cid, :label, :name, :phone, :addr, :city, :area, :zip, :country, :is_def, NOW())"
                    );
                    $stmtIns->execute([
                        ':cid'     => $cid,
                        ':label'   => $label,
                        ':name'    => $fullName,
                        ':phone'   => $phone,
                        ':addr'    => $address,
                        ':city'    => $city,
                        ':area'    => $area ?: null,
                        ':zip'     => $zip ?: null,
                        ':country' => $country,
                        ':is_def'  => $makeDefault ? 1 : 0,
                    ]);
                    flash('success', 'New delivery address added successfully.');
                }
                $pdo->commit();
                header('Location: ' . shop_url('account/addresses.php'));
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors['general'] = 'Database error saving address. Please try again.';
            }
        }
    }
}

// Fetch all addresses for this customer
$addresses = [];
try {
    $stmtA = $pdo->prepare(
        "SELECT * FROM customer_addresses
         WHERE customer_id = :cid
         ORDER BY is_default DESC, id DESC"
    );
    $stmtA->execute([':cid' => $cid]);
    $addresses = $stmtA->fetchAll();
} catch (Throwable $e) {
    $addresses = [];
}

account_layout_start('addresses', 'Delivery Addresses', $customer);
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">Delivery Addresses</h1>
    <p class="text-muted small mb-0">Manage your shipping destinations for faster checkout (up to 10 saved addresses).</p>
  </div>
  <?php if (count($addresses) < 10 && !$editAddress): ?>
    <button type="button" class="btn btn-editorial-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#addressFormCollapse" aria-expanded="<?= !empty($errors) ? 'true' : 'false' ?>">
      + Add New Address
    </button>
  <?php endif; ?>
</div>

<!-- Add / Edit Address Form Collapse -->
<div class="collapse <?= ($editAddress || !empty($errors)) ? 'show' : '' ?> mb-4" id="addressFormCollapse">
  <div class="account-card border-primary">
    <div class="account-card-header">
      <h2 class="account-card-title"><?= $editAddress ? 'Edit Delivery Address' : 'Add New Delivery Address' ?></h2>
      <?php if ($editAddress): ?>
        <a href="<?= e(shop_url('account/addresses.php')) ?>" class="btn btn-editorial-secondary btn-sm">Cancel Edit</a>
      <?php else: ?>
        <button type="button" class="btn-close" data-bs-toggle="collapse" data-bs-target="#addressFormCollapse" aria-label="Close form"></button>
      <?php endif; ?>
    </div>

    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert-danger small py-2 mb-3">
        <?= icon('alert-circle', 'me-1') ?> <?= e($errors['general']) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="addresses.php" class="auth-form" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="address_id" value="<?= $editAddress ? (int)$editAddress['id'] : 0 ?>">

      <div class="row g-3">
        <div class="col-md-4">
          <label for="addrLabel" class="form-label small fw-semibold">Address Label</label>
          <select name="label" id="addrLabel" class="form-select form-select-sm">
            <?php
            $currentLabel = $editAddress['label'] ?? ($_POST['label'] ?? 'Home');
            foreach (['Home', 'Office', 'Other'] as $lbl):
            ?>
              <option value="<?= $lbl ?>" <?= $currentLabel === $lbl ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label for="addrName" class="form-label small fw-semibold">Recipient Full Name <span class="text-danger">*</span></label>
          <input type="text"
                 name="full_name"
                 id="addrName"
                 class="form-control form-control-sm <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>"
                 value="<?= e($editAddress['full_name'] ?? ($_POST['full_name'] ?? $customer['name'])) ?>"
                 required>
          <?php if (isset($errors['full_name'])): ?>
            <div class="invalid-feedback"><?= e($errors['full_name']) ?></div>
          <?php endif; ?>
        </div>

        <div class="col-md-4">
          <label for="addrPhone" class="form-label small fw-semibold">Contact Phone <span class="text-danger">*</span></label>
          <input type="tel"
                 name="phone"
                 id="addrPhone"
                 class="form-control form-control-sm <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                 value="<?= e($editAddress['phone'] ?? ($_POST['phone'] ?? $customer['phone'])) ?>"
                 placeholder="01712345678"
                 required>
          <?php if (isset($errors['phone'])): ?>
            <div class="invalid-feedback"><?= e($errors['phone']) ?></div>
          <?php endif; ?>
        </div>

        <div class="col-12">
          <label for="addrStreet" class="form-label small fw-semibold">Street Address / House / Flat <span class="text-danger">*</span></label>
          <textarea name="address"
                    id="addrStreet"
                    class="form-control form-control-sm <?= isset($errors['address']) ? 'is-invalid' : '' ?>"
                    rows="2"
                    placeholder="House #, Road #, Sector / Area"
                    required><?= e($editAddress['address'] ?? ($_POST['address'] ?? '')) ?></textarea>
          <?php if (isset($errors['address'])): ?>
            <div class="invalid-feedback"><?= e($errors['address']) ?></div>
          <?php endif; ?>
        </div>

        <div class="col-md-4">
          <label for="addrCity" class="form-label small fw-semibold">City / District <span class="text-danger">*</span></label>
          <input type="text"
                 name="city"
                 id="addrCity"
                 class="form-control form-control-sm <?= isset($errors['city']) ? 'is-invalid' : '' ?>"
                 value="<?= e($editAddress['city'] ?? ($_POST['city'] ?? 'Dhaka')) ?>"
                 placeholder="e.g. Dhaka"
                 required>
          <?php if (isset($errors['city'])): ?>
            <div class="invalid-feedback"><?= e($errors['city']) ?></div>
          <?php endif; ?>
        </div>

        <div class="col-md-4">
          <label for="addrArea" class="form-label small fw-semibold">Thana / Area</label>
          <input type="text"
                 name="area"
                 id="addrArea"
                 class="form-control form-control-sm"
                 value="<?= e($editAddress['area'] ?? ($_POST['area'] ?? '')) ?>"
                 placeholder="e.g. Dhanmondi">
        </div>

        <div class="col-md-4">
          <label for="addrZip" class="form-label small fw-semibold">Postal Code</label>
          <input type="text"
                 name="zip"
                 id="addrZip"
                 class="form-control form-control-sm"
                 value="<?= e($editAddress['zip'] ?? ($_POST['zip'] ?? '')) ?>"
                 placeholder="e.g. 1205">
        </div>

        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input"
                   type="checkbox"
                   name="is_default"
                   value="1"
                   id="isDefaultCheck"
                   <?= (!empty($editAddress['is_default']) || empty($addresses)) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="isDefaultCheck">
              Set as my default shipping address
            </label>
          </div>
        </div>

        <div class="col-12 pt-2">
          <button type="submit" class="btn btn-editorial-primary btn-sm">
            <?= $editAddress ? 'Update Address' : 'Save Address' ?>
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Saved Addresses List -->
<?php if (empty($addresses)): ?>
  <div class="account-card text-center py-5">
    <div class="empty-state-icon mb-3" style="width: 52px; height: 52px; margin: 0 auto; display: flex; align-items: center; justify-content: center; background: var(--surface-muted); border-radius: 50%; color: var(--ink-2);">
      <?= icon('map-pin') ?>
    </div>
    <h3 class="h5 fw-bold mb-1">No Delivery Addresses Saved</h3>
    <p class="text-muted small mb-3">Add your home or office address to save time when checking out.</p>
  </div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($addresses as $addr): ?>
      <div class="col-md-6">
        <div class="account-card h-100 position-relative <?= (int)$addr['is_default'] === 1 ? 'border-primary' : '' ?>">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-light text-dark border fw-semibold"><?= e($addr['label'] ?: 'Address') ?></span>
              <?php if ((int)$addr['is_default'] === 1): ?>
                <span class="badge bg-primary text-white"><?= icon('check') ?> Default</span>
              <?php endif; ?>
            </div>
            <div class="dropdown">
              <button class="btn btn-sm btn-light border-0 py-0 px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                &vellip;
              </button>
              <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size: 0.85rem;">
                <li>
                  <a class="dropdown-item" href="<?= e(shop_url('account/addresses.php?edit=' . (int)$addr['id'])) ?>">
                    <?= icon('edit', 'me-2 text-secondary') ?> Edit
                  </a>
                </li>
                <?php if ((int)$addr['is_default'] !== 1): ?>
                  <li>
                    <form method="post" action="addresses.php" class="m-0">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="set_default">
                      <input type="hidden" name="address_id" value="<?= (int)$addr['id'] ?>">
                      <button type="submit" class="dropdown-item">
                        <?= icon('check-circle', 'me-2 text-secondary') ?> Set as Default
                      </button>
                    </form>
                  </li>
                <?php endif; ?>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                  <form method="post" action="addresses.php" class="m-0" onsubmit="return confirm('Delete this address?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="address_id" value="<?= (int)$addr['id'] ?>">
                    <button type="submit" class="dropdown-item text-danger">
                      <?= icon('trash', 'me-2 text-danger') ?> Delete
                    </button>
                  </form>
                </li>
              </ul>
            </div>
          </div>

          <div class="fw-bold fs-6 mb-1 text-dark"><?= e($addr['full_name']) ?></div>
          <div class="text-muted small mb-2"><?= icon('phone', 'me-1') ?> <?= e($addr['phone']) ?></div>
          <div class="text-muted small">
            <?= nl2br(e($addr['address'])) ?><br>
            <?= e($addr['city']) ?><?= !empty($addr['area']) ? ', ' . e($addr['area']) : '' ?><?= !empty($addr['zip']) ? ' - ' . e($addr['zip']) : '' ?><br>
            <?= e($addr['country'] ?: 'Bangladesh') ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
account_layout_end();
