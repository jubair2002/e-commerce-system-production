<?php
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

$pageTitle = 'Checkout';

// Load cart and auto-revalidate against live database
$cart = cart_load();
if (!empty($cart['alerts'])) {
    foreach ($cart['alerts'] as $msg) {
        flash('warning', $msg);
    }
}

if (empty($cart['items'])) {
    flash('warning', 'Your cart is empty. Please add items to your cart before proceeding to checkout.');
    redirect(route('cart'));
}

// Prompt 12: Customer Account & Guest Checkout Enforce
$guestCheckout = (int)setting('guest_checkout', '1') === 1;
$currentCustomer = function_exists('customer_current') ? customer_current() : null;
if (!$guestCheckout && !$currentCustomer) {
    flash('info', 'Please sign in or create an account to proceed to checkout.');
    header('Location: ' . route('login', ['next' => route('checkout')]));
    exit;
}

// Fetch active shipping methods
$shippingMethods = [];
try {
    $shippingMethods = db()->query(
        "SELECT * FROM shipping_methods WHERE status = 'active' ORDER BY position ASC, id ASC"
    )->fetchAll();
} catch (Throwable $e) {
    $shippingMethods = [];
}

// Settings
$requirePhone = (int)setting('require_phone', '1') === 1;
$requireEmail = (int)setting('require_email', '0') === 1;
$bdDistricts = file_exists(__DIR__ . '/../../includes/data/bd_districts.php')
    ? require __DIR__ . '/../../includes/data/bd_districts.php'
    : [];
$codEnabled = (int)setting('cod_enabled', '1') === 1;
$codFee = (float)setting('cod_fee', '0.00');
$codMin = (float)setting('cod_min_order', '0');
$codMax = (float)setting('cod_max_order', '0');
$bankEnabled = (int)setting('bank_transfer_enabled', '0') === 1;
$bankInstructions = setting('bank_transfer_instructions', '');
$antispamLimit = (int)setting('antispam_orders_limit', '5');

// Selected shipping method & applied coupon
$selectedShippingId = isset($_POST['shipping_method_id'])
    ? (int)$_POST['shipping_method_id']
    : (isset($_SESSION['shipping_method_id']) ? (int)$_SESSION['shipping_method_id'] : ($shippingMethods[0]['id'] ?? 0));

$appliedCouponCode = trim((string)($_POST['coupon_code'] ?? ($_SESSION['applied_coupon'] ?? '')));
$paymentMethod = trim((string)($_POST['payment_method'] ?? 'cod'));

$totals = cart_totals($cart, $selectedShippingId, $appliedCouponCode, $paymentMethod);

// Ensure a checkout token is created for double-submit protection
if (empty($_SESSION['checkout_token'])) {
    $_SESSION['checkout_token'] = bin2hex(random_bytes(16));
}
$checkoutToken = $_SESSION['checkout_token'];

// Render timestamp for time-trap
if (empty($_SESSION['checkout_rendered_at'])) {
    $_SESSION['checkout_rendered_at'] = time();
}

// Track checkout.started event once per checkout session
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($cart) && empty($_SESSION['checkout_started_tracked'])) {
    $_SESSION['checkout_started_tracked'] = true;
    hook_fire('checkout.started', ['total' => $totals['total'] ?? 0]);
}

$errors = [];
$old = [
    'customer_name'    => '',
    'customer_phone'   => '',
    'customer_email'   => '',
    'shipping_address' => '',
    'shipping_city'    => '',
    'shipping_zip'     => '',
    'payment_method'   => $paymentMethod,
    'shipping_method_id' => $selectedShippingId,
    'notes'            => '',
];

$savedAddresses = [];
if ($currentCustomer) {
    try {
        $stA = db()->prepare("SELECT * FROM customer_addresses WHERE customer_id = :cid ORDER BY is_default DESC, id DESC");
        $stA->execute([':cid' => (int)$currentCustomer['id']]);
        $savedAddresses = $stA->fetchAll();
    } catch (Throwable $e) {}
}

if ($currentCustomer && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $old['customer_name']  = $currentCustomer['name'] ?? '';
    $old['customer_email'] = $currentCustomer['email'] ?? '';
    $old['customer_phone'] = $currentCustomer['phone'] ?? '';

    if (!empty($savedAddresses)) {
        $cAddr = $savedAddresses[0];
        $old['shipping_address'] = $cAddr['address'] ?? '';
        $old['shipping_city']    = $cAddr['city'] ?? '';
        $old['shipping_zip']     = $cAddr['zip'] ?? '';
        if (!empty($cAddr['phone']) && empty($old['customer_phone'])) {
            $old['customer_phone'] = $cAddr['phone'];
        }
        if (!empty($cAddr['full_name']) && empty($old['customer_name'])) {
            $old['customer_name'] = $cAddr['full_name'];
        }
    } else {
        try {
            $stL = db()->prepare("SELECT shipping_address, shipping_city, shipping_zip, customer_phone FROM orders WHERE customer_id = :cid ORDER BY id DESC LIMIT 1");
            $stL->execute([':cid' => (int)$currentCustomer['id']]);
            $lOrder = $stL->fetch();
            if ($lOrder) {
                $old['shipping_address'] = $lOrder['shipping_address'] ?? '';
                $old['shipping_city']    = $lOrder['shipping_city'] ?? '';
                $old['shipping_zip']     = $lOrder['shipping_zip'] ?? '';
                if (!empty($lOrder['customer_phone']) && empty($old['customer_phone'])) {
                    $old['customer_phone'] = $lOrder['customer_phone'];
                }
            }
        } catch (Throwable $e) {}
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    foreach (array_keys($old) as $k) {
        if (isset($_POST[$k])) {
            $old[$k] = trim((string)$_POST[$k]);
        }
    }

    // 1. Anti-spam: Honeypot check
    if (!empty($_POST['website_url'])) {
        $errors[] = 'Spam submission detected.';
    }

    // 2. Anti-spam: Time-trap check (< 3 seconds)
    $renderedAt = (int)($_POST['checkout_rendered_at'] ?? 0);
    if ($renderedAt <= 0 || (time() - $renderedAt) < 3) {
        $errors[] = 'Submission was too fast. Please take a moment to review your details.';
    }

    // 3. Double-submit protection
    $submittedToken = (string)($_POST['checkout_token'] ?? '');
    if (!hash_equals($_SESSION['checkout_token'] ?? '', $submittedToken)) {
        $errors[] = 'This order has already been submitted. Please check your order confirmation.';
    }

    // 4. Anti-spam: Per-IP rate limiting (5 orders in 30 mins)
    $clientIp = get_client_ip();
    try {
        $ipStmt = db()->prepare(
            "SELECT COUNT(*) FROM orders
             WHERE ip_address = :ip AND created_at >= NOW() - INTERVAL 30 MINUTE"
        );
        $ipStmt->execute([':ip' => $clientIp]);
        $recentOrders = (int)$ipStmt->fetchColumn();
        if ($recentOrders >= $antispamLimit) {
            $errors[] = 'Order limit reached for your connection. Please wait before placing another order or contact support.';
        }
    } catch (Throwable $e) {
        // Continue if IP check query fails
    }

    // 5. Customer field validations
    if ($old['customer_name'] === '') {
        $errors[] = 'Full name is required.';
    }

    if ($requirePhone) {
        if ($old['customer_phone'] === '') {
            $errors[] = 'Phone number is required.';
        } elseif (!preg_match('/^(\+?88)?01[3-9]\d{8}$/', $old['customer_phone'])) {
            $errors[] = 'Please enter a valid 11-digit Bangladesh phone number (e.g. 01712345678).';
        }
    }

    if ($requireEmail && $old['customer_email'] === '') {
        $errors[] = 'Email address is required.';
    } elseif ($old['customer_email'] !== '' && !filter_var($old['customer_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($old['shipping_address'] === '') {
        $errors[] = 'Delivery address is required.';
    }
    if ($old['shipping_city'] === '') {
        $errors[] = 'City / District is required.';
    }

    // 6. Validate Shipping Method
    $chosenMethod = null;
    foreach ($shippingMethods as $sm) {
        if ((int)$sm['id'] === (int)$old['shipping_method_id']) {
            $chosenMethod = $sm;
            break;
        }
    }
    if (!$chosenMethod) {
        $errors[] = 'Please select a valid delivery method.';
    }

    // 7. Validate Payment Method
    $allowedPayments = [];
    if ($codEnabled) $allowedPayments[] = 'cod';
    if ($bankEnabled) $allowedPayments[] = 'bank_transfer';

    if (!in_array($old['payment_method'], $allowedPayments, true)) {
        $errors[] = 'Please choose a valid and enabled payment method.';
    }

    if ($old['payment_method'] === 'cod') {
        if (!$codEnabled) {
            $errors[] = 'Cash on Delivery is currently disabled.';
        }
        if ($chosenMethod && empty($chosenMethod['cod_allowed'])) {
            $errors[] = 'The selected shipping method does not support Cash on Delivery.';
        }
        if ($codMin > 0 && $totals['subtotal'] < $codMin) {
            $errors[] = 'Minimum order amount for Cash on Delivery is ' . format_price($codMin) . '.';
        }
        if ($codMax > 0 && $totals['subtotal'] > $codMax) {
            $errors[] = 'Maximum order amount for Cash on Delivery is ' . format_price($codMax) . '.';
        }
    }

    // 8. Execute Order Transaction if no errors
    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Re-load fresh cart inside transaction to avoid race conditions
            $freshCart = cart_load();
            if (empty($freshCart['items'])) {
                throw new Exception('Your cart is empty.');
            }

            // Recompute totals strictly from DB
            $recomputedTotals = cart_totals(
                $freshCart,
                $chosenMethod ? (int)$chosenMethod['id'] : null,
                $appliedCouponCode,
                $old['payment_method']
            );

            // Sort lines by variant_id / product_id ascending to prevent deadlocks
            $orderLines = $freshCart['items'];
            usort($orderLines, function ($a, $b) {
                $aKey = ($a['variant_id'] ?? 0) ? (int)$a['variant_id'] : ((int)$a['product_id'] + 1000000);
                $bKey = ($b['variant_id'] ?? 0) ? (int)$b['variant_id'] : ((int)$b['product_id'] + 1000000);
                return $aKey <=> $bKey;
            });

            // Generate unique order number with retry on duplicate key
            $orderNumber = null;
            $orderId = null;
            $prefixSetting = setting('order_number_prefix', 'ORD') ?: 'ORD';
            $monthPrefix = $prefixSetting . '-' . date('Ym') . '-';

            // Find current highest sequence with row lock or generate
            $customerId = $currentCustomer ? (int)$currentCustomer['id'] : null;

            for ($retry = 0; $retry < 5; $retry++) {
                $randSuffix = str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
                $candidateNumber = $monthPrefix . $randSuffix;

                $stmtInsertOrder = $pdo->prepare(
                    "INSERT INTO orders
                      (order_number, customer_id, customer_name, customer_email, customer_phone,
                       shipping_address, shipping_city, shipping_zip, shipping_country,
                       subtotal, shipping_fee, discount_total, cod_fee, total,
                       payment_method, payment_status, shipping_method_id, shipping_method_name,
                       coupon_code, ip_address, notes, status, stock_restored)
                     VALUES
                      (:order_number, :customer_id, :name, :email, :phone,
                       :address, :city, :zip, 'Bangladesh',
                       :subtotal, :shipping_fee, :discount_total, :cod_fee, :total,
                       :payment_method, 'unpaid', :shipping_method_id, :shipping_method_name,
                       :coupon_code, :ip, :notes, 'pending', 0)"
                );

                try {
                    $stmtInsertOrder->execute([
                        ':order_number'         => $candidateNumber,
                        ':customer_id'          => $customerId,
                        ':name'                 => $old['customer_name'],
                        ':email'                => $old['customer_email'],
                        ':phone'                => $old['customer_phone'],
                        ':address'              => $old['shipping_address'],
                        ':city'                 => $old['shipping_city'],
                        ':zip'                  => $old['shipping_zip'],
                        ':subtotal'             => $recomputedTotals['subtotal'],
                        ':shipping_fee'         => $recomputedTotals['shipping_fee'],
                        ':discount_total'       => $recomputedTotals['discount'],
                        ':cod_fee'              => $recomputedTotals['cod_fee'],
                        ':total'                => $recomputedTotals['total'],
                        ':payment_method'       => $old['payment_method'],
                        ':shipping_method_id'   => $chosenMethod['id'] ?? null,
                        ':shipping_method_name' => $chosenMethod['name'] ?? null,
                        ':coupon_code'          => $recomputedTotals['coupon']['code'] ?? null,
                        ':ip'                   => $clientIp,
                        ':notes'                => $old['notes'],
                    ]);
                    $orderId = (int)$pdo->lastInsertId();
                    $orderNumber = $candidateNumber;
                    break;
                } catch (PDOException $pe) {
                    if ($pe->getCode() === '23000' && str_contains($pe->getMessage(), 'Duplicate')) {
                        continue;
                    }
                    throw $pe;
                }
            }

            if (!$orderId || !$orderNumber) {
                throw new Exception('Unable to generate unique order number. Please try again.');
            }

            // Save address to customer address book if logged in and requested
            if ($customerId && !empty($_POST['save_address']) && (empty($_POST['saved_address_id']) || $_POST['saved_address_id'] === 'new')) {
                try {
                    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM customer_addresses WHERE customer_id = :cid");
                    $cntStmt->execute([':cid' => $customerId]);
                    $addrCount = (int)$cntStmt->fetchColumn();
                    if ($addrCount < 10) {
                        $isDef = ($addrCount === 0) ? 1 : 0;
                        $stSave = $pdo->prepare(
                            "INSERT INTO customer_addresses
                              (customer_id, label, full_name, phone, address, city, zip, is_default, created_at)
                             VALUES
                              (:cid, 'Home', :rname, :phone, :addr, :city, :zip, :def, NOW())"
                        );
                        $stSave->execute([
                            ':cid'   => $customerId,
                            ':rname' => $old['customer_name'],
                            ':phone' => $old['customer_phone'],
                            ':addr'  => $old['shipping_address'],
                            ':city'  => $old['shipping_city'],
                            ':zip'   => $old['shipping_zip'] ?: null,
                            ':def'   => $isDef,
                        ]);
                    }
                } catch (Throwable $e) {}
            }

            // Insert line items & atomically adjust stock
            $stmtItem = $pdo->prepare(
                "INSERT INTO order_items
                  (order_id, product_id, product_name, product_sku,
                   variant_id, variant_sku, variant_label,
                   unit_price, quantity, line_total)
                 VALUES
                  (:oid, :pid, :pname, :psku,
                   :vid, :vsku, :vlabel,
                   :price, :qty, :total)"
            );

            foreach ($orderLines as $line) {
                $pid = (int)$line['product_id'];
                $vid = $line['variant_id'] ? (int)$line['variant_id'] : null;
                $qty = (int)$line['qty'];

                $stmtItem->execute([
                    ':oid'    => $orderId,
                    ':pid'    => $pid,
                    ':pname'  => $line['product_name'],
                    ':psku'   => $line['product']['sku'] ?? null,
                    ':vid'    => $vid,
                    ':vsku'   => $line['sku'] ?? null,
                    ':vlabel' => $line['variant_label'] ?: null,
                    ':price'  => $line['unit_price'],
                    ':qty'    => $qty,
                    ':total'  => $line['line_total'],
                ]);

                // Atomic stock decrement
                                $adjusted = stock_adjust($pid, $vid, -$qty, 'order', $orderId, "Order {$orderNumber} placed");
                if (!$adjusted) {
                    throw new Exception("Unable to reserve stock for \"{$line['product_name']}\". It may have just sold out.");
                }

                // Increment sold_count on products
                $pdo->prepare(
                    "UPDATE products SET sold_count = sold_count + :q WHERE id = :pid"
                )->execute([':q' => $qty, ':pid' => $pid]);
            }

            // Insert order status history
            $stmtHist = $pdo->prepare(
                "INSERT INTO order_status_history (order_id, status, note)
                 VALUES (:oid, 'pending', :note)"
            );
            $stmtHist->execute([
                ':oid'  => $orderId,
                ':note' => 'Order placed by customer via ' . ($old['payment_method'] === 'cod' ? 'Cash on Delivery' : 'Bank Transfer'),
            ]);

            // Increment coupon usage
            if (!empty($recomputedTotals['coupon']['id'])) {
                $pdo->prepare(
                    "UPDATE coupons SET used_count = used_count + 1 WHERE id = :cid"
                )->execute([':cid' => $recomputedTotals['coupon']['id']]);
            }

            // Commit transaction
            $pdo->commit();

            // Clear cart & session coupon
            cart_clear();
            unset($_SESSION['applied_coupon']);
            unset($_SESSION['checkout_token']);
            unset($_SESSION['checkout_started_tracked']);
            $_SESSION['last_placed_order'] = $orderNumber;

            // Trigger order.placed hook
            hook_fire('order.placed', [
                'order_id'     => $orderId,
                'order_number' => $orderNumber,
                'total'        => $recomputedTotals['total'],
                'customer_name' => $old['customer_name'],
                'customer_phone' => $old['customer_phone'],
                'customer_email' => $old['customer_email'],
            ]);

            // Signed HMAC token for confirmation page
            $appKey = defined('APP_KEY') ? APP_KEY : 'secret-key-fallback';
            $signedToken = hash_hmac('sha256', $orderNumber, $appKey);

            redirect(route('order_confirmation', ['o' => $orderNumber, 't' => $signedToken]));

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex->getMessage();
        }
    }
}

// Reset rendered_at for next attempt
$_SESSION['checkout_rendered_at'] = time();

include __DIR__ . '/../../includes/partials/header.php';
?>

<section class="section-py pt-4">
  <div class="container">

    <!-- 3-Step Checkout Visual Progress Indicator -->
    <div class="checkout-progress">
      <a href="<?= e(route('cart')) ?>" class="progress-step text-muted text-decoration-none">
        <span class="step-number">1</span>
        <span>Cart</span>
      </a>
      <div class="progress-line"></div>
      <div class="progress-step is-active">
        <span class="step-number">2</span>
        <span>Details</span>
      </div>
      <div class="progress-line"></div>
      <div class="progress-step">
        <span class="step-number">3</span>
        <span>Confirmation</span>
      </div>
    </div>

    <div class="section-header mb-4 text-center">
      <h1 class="display-serif fs-2xl mb-1">Complete Your Order</h1>
      <p class="text-muted small">Fill in your delivery details below. Fast shipping with Cash on Delivery nationwide.</p>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger alert-dismissible fade show mb-4">
        <strong><?= icon('alert-circle', 'me-1') ?> Please check the following:</strong>
        <ul class="mb-0 mt-2 ps-3">
          <?php foreach ($errors as $err): ?>
            <li><?= e($err) ?></li>
          <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <form method="post" id="checkoutForm" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="checkout_token" value="<?= e($checkoutToken) ?>">
      <input type="hidden" name="checkout_rendered_at" value="<?= time() ?>">

      <!-- Anti-spam Honeypot -->
      <div style="display:none !important;" aria-hidden="true">
        <label for="website_url">Website</label>
        <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off">
      </div>

      <div class="row g-4">
        <!-- Left: Customer and Delivery Details -->
        <div class="col-lg-7">

          <div class="card shadow-sm border-0 mb-4">
            <div class="card-header py-3 border-bottom d-flex justify-content-between align-items-center">
              <h5 class="mb-0 fw-semibold"><?= icon('user', 'text-primary me-2') ?> Contact Information</h5>
              <?php if ($currentCustomer): ?>
                <span class="badge bg-light text-dark border">
                  <?= icon('check-circle', 'text-success me-1') ?> <?= e($currentCustomer['name']) ?>
                </span>
              <?php else: ?>
                <a href="<?= e(route('login', ['next' => route('checkout')])) ?>" class="small text-decoration-underline text-muted">
                  Already have an account? Sign in
                </a>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                  <input type="text" name="customer_name" class="form-control"
                         value="<?= e($old['customer_name']) ?>" placeholder="e.g. Tanvir Ahmed" required>
                </div>

                <div class="col-md-6">
                  <label class="form-label fw-medium">
                    Phone Number <?= $requirePhone ? '<span class="text-danger">*</span>' : '' ?>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text"><?= icon('phone') ?></span>
                    <input type="tel" name="customer_phone" class="form-control"
                           value="<?= e($old['customer_phone']) ?>" placeholder="01712345678" <?= $requirePhone ? 'required' : '' ?>>
                  </div>
                  <div class="form-text">Used for delivery confirmation and tracking.</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label fw-medium">
                    Email Address <?= $requireEmail ? '<span class="text-danger">*</span>' : '<span class="text-muted">(Optional)</span>' ?>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text"><?= icon('mail') ?></span>
                    <input type="email" name="customer_email" class="form-control"
                           value="<?= e($old['customer_email']) ?>" placeholder="tanvir@example.com" <?= $requireEmail ? 'required' : '' ?>>
                  </div>
                  <div class="form-text">Invoice copy will be sent here.</div>
                </div>
              </div>
            </div>
          </div>

          <div class="card shadow-sm border-0 mb-4">
            <div class="card-header py-3 border-bottom">
              <h5 class="mb-0 fw-semibold"><?= icon('map-pin', 'text-primary me-2') ?> Delivery Address</h5>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <?php if (!empty($savedAddresses)): ?>
                  <div class="col-12 mb-2">
                    <label class="form-label fw-medium d-flex justify-content-between align-items-center">
                      <span><?= icon('map-pin', 'me-1 text-primary') ?> Saved Delivery Addresses</span>
                      <a href="<?= e(route('account_addresses')) ?>" target="_blank" class="small text-decoration-none">Manage addresses &rarr;</a>
                    </label>
                    <div class="d-flex flex-column gap-2" id="savedAddressSelector">
                      <?php foreach ($savedAddresses as $idx => $sa): 
                        $isSelected = ($idx === 0);
                      ?>
                        <label class="border rounded-3 p-2 px-3 d-flex align-items-start gap-2 bg-light cursor-pointer saved-addr-item <?= $isSelected ? 'border-primary bg-primary-subtle' : '' ?>" style="cursor:pointer;">
                          <input type="radio" name="saved_address_id" value="<?= (int)$sa['id'] ?>" class="form-check-input mt-1" <?= $isSelected ? 'checked' : '' ?>
                            data-name="<?= e($sa['full_name']) ?>"
                            data-phone="<?= e($sa['phone']) ?>"
                            data-addr="<?= e($sa['address']) ?>"
                            data-city="<?= e($sa['city']) ?>"
                            data-zip="<?= e($sa['zip'] ?? '') ?>"
                          >
                          <div class="small flex-grow-1">
                            <div class="d-flex align-items-center gap-1 mb-1">
                              <span class="badge bg-secondary"><?= e($sa['label']) ?></span>
                              <?php if (!empty($sa['is_default'])): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Default</span>
                              <?php endif; ?>
                              <strong class="ms-1"><?= e($sa['full_name']) ?></strong>
                              <span class="text-muted ms-1">(<?= e($sa['phone']) ?>)</span>
                            </div>
                            <div class="text-secondary"><?= e($sa['address']) ?>, <?= e($sa['city']) ?><?= !empty($sa['zip']) ? ' - ' . e($sa['zip']) : '' ?></div>
                          </div>
                        </label>
                      <?php endforeach; ?>
                      <label class="border rounded-3 p-2 px-3 d-flex align-items-center gap-2 bg-light cursor-pointer saved-addr-item" style="cursor:pointer;">
                        <input type="radio" name="saved_address_id" value="new" class="form-check-input">
                        <span class="small fw-semibold text-primary"><?= icon('map-pin', 'me-1') ?> Add new delivery address</span>
                      </label>
                    </div>
                  </div>
                <?php endif; ?>

                <div class="col-12" id="addressInputWrap">
                  <label class="form-label fw-medium">Street Address / House / Flat <span class="text-danger">*</span></label>
                  <textarea name="shipping_address" id="shippingAddressInput" class="form-control" rows="2"
                            placeholder="House #, Road #, Area / Thana" required><?= e($old['shipping_address']) ?></textarea>
                </div>

                <div class="col-md-6">
                  <label class="form-label fw-medium" for="shippingCityInput">City / District <span class="text-danger">*</span></label>
                  <select name="shipping_city" id="shippingCityInput" class="form-select" required>
                    <option value="">-- Select District (64 Districts) --</option>
                    <?php foreach ($bdDistricts as $dist): ?>
                      <option value="<?= e($dist['name']) ?>" data-zone="<?= e($dist['zone']) ?>" <?= ($old['shipping_city'] === $dist['name'] || (empty($old['shipping_city']) && $dist['name'] === 'Dhaka')) ? 'selected' : '' ?>>
                        <?= e($dist['name']) ?> (<?= $dist['zone'] === 'inside_dhaka' ? 'Inside Dhaka' : 'Outside Dhaka' ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="mt-2 d-flex flex-wrap gap-1">
                    <button type="button" class="btn btn-outline-secondary btn-sm area-chip py-0 px-2" data-city="Dhaka" data-method-keyword="Inside Dhaka" style="font-size: 0.78rem;">
                      <?= icon('map-pin', 'me-1') ?> Inside Dhaka
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm area-chip py-0 px-2" data-city="Chattogram" data-method-keyword="Outside Dhaka" style="font-size: 0.78rem;">
                      <?= icon('truck', 'me-1') ?> Outside Dhaka
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm area-chip py-0 px-2" data-city="Gazipur" data-method-keyword="Custom Area" style="font-size: 0.78rem;">
                      <?= icon('map-pin', 'me-1') ?> Gazipur / Suburbs
                    </button>
                  </div>
                </div>

                <div class="col-md-6">
                  <label class="form-label fw-medium">Area / Postal Code</label>
                  <input type="text" name="shipping_zip" id="shippingZipInput" class="form-control"
                         value="<?= e($old['shipping_zip']) ?>" placeholder="e.g. 1205">
                </div>

                <div class="col-12">
                  <label class="form-label fw-medium">Order Notes <span class="text-muted">(Optional)</span></label>
                  <input type="text" name="notes" class="form-control"
                         value="<?= e($old['notes']) ?>" placeholder="Special instructions for delivery (e.g. call before arriving)">
                </div>

                <?php if ($currentCustomer): ?>
                  <div class="col-12 mt-2" id="saveAddressCheckWrap" style="<?= !empty($savedAddresses) ? 'display:none;' : '' ?>">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="save_address" value="1" id="saveAddressCheck" <?= empty($savedAddresses) ? 'checked' : '' ?>>
                      <label class="form-check-label small" for="saveAddressCheck">
                        Save this address to my account for future orders
                      </label>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Shipping Method Selection -->
          <div class="card shadow-sm border-0 mb-4">
            <div class="card-header py-3 border-bottom d-flex justify-content-between align-items-center">
              <h5 class="mb-0 fw-semibold"><?= icon('truck', 'text-primary me-2') ?> Delivery Area &amp; Shipping Rate</h5>
              <span class="badge text-bg-light border text-muted">Select your delivery zone</span>
            </div>
            <div class="card-body p-0">
              <div class="list-group list-group-flush" id="shippingMethodsList">
                <?php foreach ($shippingMethods as $idx => $sm): ?>
                  <?php
                    $isFree = ($sm['free_over'] !== null && (float)$sm['free_over'] > 0 && $totals['discounted_subtotal'] >= (float)$sm['free_over']);
                    $cost = $isFree ? 0.0 : (float)$sm['cost'];
                    $isChecked = ((int)$sm['id'] === (int)$selectedShippingId) || ($idx === 0 && !$selectedShippingId);
                  ?>
                  <label class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 shipping-method-card <?= $isChecked ? 'border-primary bg-light' : '' ?>" style="cursor: pointer;">
                    <div class="d-flex align-items-center">
                      <input class="form-check-input me-3 mt-0 shipping-radio" type="radio" name="shipping_method_id"
                             value="<?= (int)$sm['id'] ?>"
                             data-cost="<?= $cost ?>"
                             data-is-free="<?= $isFree ? '1' : '0' ?>"
                             data-name="<?= e($sm['name']) ?>"
                             data-zone="<?= e($sm['zone']) ?>"
                             data-cod-allowed="<?= (int)$sm['cod_allowed'] ?>"
                             <?= $isChecked ? 'checked' : '' ?>>
                      <div>
                        <div class="fw-semibold shipping-name"><?= e($sm['name']) ?></div>
                        <div class="small text-muted">
                          <?= e($sm['zone']) ?> · Estimated <?= e($sm['min_days'] ?: 1) ?>-<?= e($sm['max_days'] ?: 3) ?> business days
                        </div>
                      </div>
                    </div>
                    <div class="text-end">
                      <span class="fw-bold fs-6 <?= $isFree ? 'text-success' : 'text-ink' ?>">
                        <?= $isFree ? 'FREE' : price($cost) ?>
                      </span>
                    </div>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Payment Method Selection -->
          <div class="card shadow-sm border-0 mb-4">
            <div class="card-header py-3 border-bottom">
              <h5 class="mb-0 fw-semibold"><?= icon('lock', 'text-primary me-2') ?> Payment Method</h5>
            </div>
            <div class="card-body">
              <?php if ($codEnabled): ?>
                <div class="form-check p-3 border rounded mb-3 bg-light" id="codOptionWrapper">
                  <input class="form-check-input payment-radio" type="radio" name="payment_method" id="pay_cod"
                         value="cod" <?= $old['payment_method'] === 'cod' ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold d-block" for="pay_cod" style="cursor: pointer;">
                    <?= icon('check', 'text-success me-1') ?> Cash on Delivery (COD)
                    <?php if ($codFee > 0): ?>
                      <span class="badge bg-secondary ms-2" id="codBadge">+<?= e(price($codFee)) ?> handling fee</span>
                    <?php endif; ?>
                  </label>
                  <div class="text-muted small mt-1">
                    Pay with cash when your shipment arrives at your doorstep.
                  </div>
                </div>
              <?php endif; ?>

              <?php if ($bankEnabled): ?>
                <div class="form-check p-3 border rounded mb-3">
                  <input class="form-check-input payment-radio" type="radio" name="payment_method" id="pay_bank"
                         value="bank_transfer" <?= $old['payment_method'] === 'bank_transfer' ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold d-block" for="pay_bank">
                    <?= icon('shield', 'text-primary me-1') ?> Direct Bank Transfer
                  </label>
                  <div class="text-muted small mt-1">
                    <?= nl2br(e($bankInstructions ?: 'Bank account details will be shown upon placing the order.')) ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>

        </div>

        <!-- Right: Order Summary Sidebar -->
        <div class="col-lg-5">
          <div class="card shadow-sm border-0 sticky-top" style="top: 90px;">
            <div class="card-header py-3 border-bottom">
              <h5 class="mb-0 fw-semibold">Order Summary (<?= cart_count() ?>)</h5>
            </div>
            <div class="card-body p-0">
              <div class="p-3 border-bottom" style="max-height: 280px; overflow-y: auto;">
                <?php foreach ($cart['items'] as $item): ?>
                  <div class="d-flex align-items-center mb-3">
                    <img src="<?= e(product_image_url($item['image'])) ?>"
                         class="rounded border me-3 flex-shrink-0" width="54" height="54"
                         style="object-fit:cover" alt="">
                    <div class="flex-grow-1 min-w-0">
                      <div class="fw-semibold text-truncate small"><?= e($item['product_name']) ?></div>
                      <?php if (!empty($item['variant_label'])): ?>
                        <div class="text-muted" style="font-size: 11px;"><?= e($item['variant_label']) ?></div>
                      <?php endif; ?>
                      <div class="text-muted small"><?= (int)$item['qty'] ?> &times; <?= e(price($item['unit_price'])) ?></div>
                    </div>
                    <div class="text-end fw-semibold small ps-2">
                      <?= e(price($item['line_total'])) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="p-3">
                <div class="d-flex justify-content-between mb-2">
                  <span class="text-muted">Subtotal</span>
                  <strong><?= e(price($totals['subtotal'])) ?></strong>
                </div>

                <?php if ($totals['discount'] > 0): ?>
                  <div class="d-flex justify-content-between mb-2 text-success">
                    <span>Coupon Discount (<code><?= e($totals['coupon']['code']) ?></code>)</span>
                    <strong>-<?= e(price($totals['discount'])) ?></strong>
                  </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between mb-2">
                  <span class="text-muted">Shipping</span>
                  <strong id="summaryShipping">
                    <?= $totals['shipping_fee'] > 0 ? e(price($totals['shipping_fee'])) : '<span class="text-success">Free</span>' ?>
                  </strong>
                </div>

                <div id="summaryCodRow" class="d-flex justify-content-between mb-2 <?= $totals['cod_fee'] > 0 ? '' : 'd-none' ?>">
                  <span class="text-muted">Cash on Delivery Fee</span>
                  <strong id="summaryCodFee"><?= e(price($totals['cod_fee'])) ?></strong>
                </div>

                <hr>

                <div class="d-flex justify-content-between align-items-center mb-4">
                  <span class="fs-5 fw-bold">Total to Pay</span>
                  <span id="summaryTotal" class="fs-3 fw-bold text-primary"><?= e(price($totals['total'])) ?></span>
                </div>

                <button type="submit" id="btnSubmitOrder" class="btn btn-primary btn-lg w-100 py-3 fw-semibold shadow-sm">
                  <?= icon('bag', 'me-2') ?> Place Order Now
                </button>

                <div class="text-center mt-3 text-muted small">
                  <?= icon('shield', 'text-success me-1') ?> 100% Secure Checkout · No account needed
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </form>

  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const subtotal = <?= (float)$totals['subtotal'] ?>;
    const discount = <?= (float)$totals['discount'] ?>;
    const codFeeRate = <?= (float)$codFee ?>;
    const currencySymbol = <?= json_encode(setting('currency_symbol', '৳')) ?>;

    const shippingRadios = document.querySelectorAll('.shipping-radio');
    const paymentRadios = document.querySelectorAll('.payment-radio');
    const cityInput = document.getElementById('shippingCityInput');
    const areaChips = document.querySelectorAll('.area-chip');
    const summaryShipping = document.getElementById('summaryShipping');
    const summaryCodRow = document.getElementById('summaryCodRow');
    const summaryCodFee = document.getElementById('summaryCodFee');
    const summaryTotal = document.getElementById('summaryTotal');
    const codOptionWrapper = document.getElementById('codOptionWrapper');
    const payCodInput = document.getElementById('pay_cod');
    const payBankInput = document.getElementById('pay_bank');

    function formatMoney(amount) {
        return currencySymbol + parseFloat(amount).toFixed(2);
    }

    function recalculate() {
        let selectedShipping = document.querySelector('.shipping-radio:checked');
        if (!selectedShipping && shippingRadios.length > 0) {
            shippingRadios[0].checked = true;
            selectedShipping = shippingRadios[0];
        }

        const isFree = selectedShipping ? selectedShipping.getAttribute('data-is-free') === '1' : false;
        const shippingCost = selectedShipping ? parseFloat(selectedShipping.getAttribute('data-cost') || 0) : 0;
        const codAllowed = selectedShipping ? selectedShipping.getAttribute('data-cod-allowed') === '1' : true;

        // Highlight selected shipping card
        document.querySelectorAll('.shipping-method-card').forEach(card => {
            const rad = card.querySelector('.shipping-radio');
            if (rad && rad.checked) {
                card.classList.add('border-primary', 'bg-light');
            } else {
                card.classList.remove('border-primary', 'bg-light');
            }
        });

        // COD availability check for this method
        if (codOptionWrapper) {
            if (!codAllowed) {
                codOptionWrapper.classList.add('opacity-50');
                if (payCodInput && payCodInput.checked && payBankInput) {
                    payBankInput.checked = true;
                }
                if (payCodInput) payCodInput.disabled = true;
            } else {
                codOptionWrapper.classList.remove('opacity-50');
                if (payCodInput) payCodInput.disabled = false;
            }
        }

        const selectedPayment = document.querySelector('.payment-radio:checked');
        const isCod = selectedPayment && selectedPayment.value === 'cod';
        const activeCodFee = isCod ? codFeeRate : 0;

        // Update shipping summary display
        if (summaryShipping) {
            summaryShipping.innerHTML = isFree ? '<span class="text-success">Free</span>' : (shippingCost > 0 ? formatMoney(shippingCost) : '<span class="text-success">Free</span>');
        }

        // Update COD fee row
        if (summaryCodRow && summaryCodFee) {
            if (activeCodFee > 0) {
                summaryCodFee.textContent = formatMoney(activeCodFee);
                summaryCodRow.classList.remove('d-none');
            } else {
                summaryCodRow.classList.add('d-none');
            }
        }

        // Total
        const total = Math.max(0, subtotal - discount + (isFree ? 0 : shippingCost) + activeCodFee);
        if (summaryTotal) {
            summaryTotal.textContent = formatMoney(total);
        }
    }

    shippingRadios.forEach(r => r.addEventListener('change', recalculate));
    paymentRadios.forEach(p => p.addEventListener('change', recalculate));

    // Handle city dropdown changes
    if (cityInput) {
        cityInput.addEventListener('change', function() {
            const selectedOpt = cityInput.options ? cityInput.options[cityInput.selectedIndex] : null;
            const zone = selectedOpt ? selectedOpt.getAttribute('data-zone') : '';
            const val = (cityInput.value || '').toLowerCase();
            for (const radio of shippingRadios) {
                const name = (radio.getAttribute('data-name') || '').toLowerCase();
                const rZone = (radio.getAttribute('data-zone') || '').toLowerCase();
                if (zone === 'inside_dhaka' || val === 'dhaka') {
                    if (name.includes('inside dhaka') || rZone.includes('inside dhaka') || rZone === 'dhaka') {
                        radio.checked = true;
                        break;
                    }
                } else if (zone === 'outside_dhaka' || (val !== '' && !val.includes('dhaka'))) {
                    if (name.includes('outside dhaka') || rZone.includes('outside dhaka') || rZone === 'outside') {
                        radio.checked = true;
                        break;
                    }
                }
            }
            recalculate();
        });
    }

    // Double submit prevention
    const checkoutForm = document.getElementById('checkoutForm');
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', function() {
            const btn = document.getElementById('btnSubmitOrder');
            if (btn && !btn.disabled) {
                setTimeout(() => {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Placing Order...';
                }, 10);
            }
        });
    }

    // Area quick-select chips
    areaChips.forEach(chip => {
        chip.addEventListener('click', function(e) {
            e.preventDefault();
            const city = this.getAttribute('data-city') || '';
            const keyword = (this.getAttribute('data-method-keyword') || '').toLowerCase();
            if (cityInput) {
                cityInput.value = city;
                cityInput.dispatchEvent(new Event('change'));
            }

            // Match and select shipping radio
            for (const radio of shippingRadios) {
                const name = (radio.getAttribute('data-name') || '').toLowerCase();
                const zone = (radio.getAttribute('data-zone') || '').toLowerCase();
                if (name.includes(keyword) || zone.includes(keyword)) {
                    radio.checked = true;
                    break;
                }
            }
            recalculate();
        });
    });

    // Saved address selection handling
    const savedAddressRadios = document.querySelectorAll('input[name="saved_address_id"]');
    if (savedAddressRadios.length) {
        savedAddressRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.saved-addr-item').forEach(el => {
                    el.classList.remove('border-primary', 'bg-primary-subtle');
                });
                const parentLabel = this.closest('.saved-addr-item');
                if (parentLabel) {
                    parentLabel.classList.add('border-primary', 'bg-primary-subtle');
                }

                const addrInput = document.getElementById('shippingAddressInput');
                const zipInput = document.getElementById('shippingZipInput');
                const phoneInput = document.querySelector('input[name="customer_phone"]');
                const nameInput = document.querySelector('input[name="customer_name"]');
                const saveWrap = document.getElementById('saveAddressCheckWrap');
                const saveCheck = document.getElementById('saveAddressCheck');

                if (this.value === 'new') {
                    if (addrInput) { addrInput.value = ''; addrInput.focus(); }
                    if (cityInput) cityInput.value = '';
                    if (zipInput) zipInput.value = '';
                    if (saveCheck) saveCheck.checked = true;
                    if (saveWrap) saveWrap.style.display = '';
                } else {
                    if (addrInput && this.dataset.addr) addrInput.value = this.dataset.addr;
                    if (cityInput && this.dataset.city) {
                        cityInput.value = this.dataset.city;
                        // Auto-match city to shipping rate
                        const lowerCity = this.dataset.city.toLowerCase();
                        for (const sRadio of shippingRadios) {
                            const name = (sRadio.getAttribute('data-name') || '').toLowerCase();
                            const zone = (sRadio.getAttribute('data-zone') || '').toLowerCase();
                            if (lowerCity.includes('dhaka') && (name.includes('inside dhaka') || zone.includes('inside dhaka'))) {
                                sRadio.checked = true;
                                break;
                            } else if (!lowerCity.includes('dhaka') && (name.includes('outside dhaka') || zone.includes('outside dhaka'))) {
                                sRadio.checked = true;
                                break;
                            }
                        }
                    }
                    if (zipInput) zipInput.value = this.dataset.zip || '';
                    if (phoneInput && this.dataset.phone && !phoneInput.value) phoneInput.value = this.dataset.phone;
                    if (nameInput && this.dataset.name && !nameInput.value) nameInput.value = this.dataset.name;
                    if (saveCheck) saveCheck.checked = false;
                    if (saveWrap) saveWrap.style.display = 'none';
                    recalculate();
                }
            });
        });
    }

    recalculate();
});
</script>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
