<?php
/**
 * Mini-Cart AJAX API.
 * Provides unblocked cart actions (add, update, remove, get) for the slide-out mini-cart drawer.
 */
require_once __DIR__ . '/../includes/shop_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_POST['action'] ?? $_GET['action'] ?? 'get';
$response = ['ok' => true, 'action' => $action];

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $variantId = !empty($_POST['variant_id']) ? (int)$_POST['variant_id'] : null;
    $qty       = max(1, (int)($_POST['quantity'] ?? 1));

    if ($productId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid product.']);
        exit;
    }

    $product = product_get($productId);
    if (!$product || $product['status'] !== 'active') {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Product is no longer available.']);
        exit;
    }

    $variant = null;
    if ($variantId !== null) {
        $variant = variant_get($variantId);
        if (!$variant || $variant['status'] !== 'active') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Selected combination is not available.']);
            exit;
        }
    }

    $availQty = ($variant !== null)
        ? variant_available_qty($variant, $product)
        : product_available_qty($productId);

    if ($availQty <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Item is currently out of stock.']);
        exit;
    }

    $cartKey = cart_key($productId, $variantId);
    $currentInCart = (int)($_SESSION['cart'][$cartKey] ?? 0);
    $newTotal = $currentInCart + $qty;

    if ($newTotal > $availQty) {
        $qtyToAdd = max(0, $availQty - $currentInCart);
        if ($qtyToAdd <= 0) {
            http_response_code(400);
            echo json_encode([
                'ok'    => false,
                'error' => "You already have all available {$availQty} units in your cart.",
            ]);
            exit;
        }
        cart_add($productId, $qtyToAdd, $variantId);
        $response['warning'] = "Added {$qtyToAdd} units (maximum available).";
    } else {
        cart_add($productId, $qty, $variantId);
    }
} elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cartKey = trim((string)($_POST['cart_key'] ?? ''));
    $qty     = (int)($_POST['quantity'] ?? 1);

    if ($cartKey !== '') {
        cart_set_key($cartKey, $qty);
    }
} elseif ($action === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cartKey = trim((string)($_POST['cart_key'] ?? ''));
    if ($cartKey !== '') {
        cart_remove_key($cartKey);
    }
} elseif ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    cart_clear();
}

// For read-only cart inspection, release the session lock immediately
if ($action === 'get' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Hydrate fresh state
$cart = cart_load();

$response['cart_count'] = cart_count();
$response['subtotal']   = $cart['subtotal'];
$response['subtotal_fmt'] = price($cart['subtotal']);
$response['alerts']     = $cart['alerts'];

// Render mini-cart items HTML
ob_start();
?>
<?php if (empty($cart['items'])): ?>
  <div class="mini-cart-empty">
    <div class="empty-icon"><i class="bi bi-bag"></i></div>
    <div class="empty-title">Your cart is empty</div>
    <p class="empty-text">Discover our latest essentials and find something you love.</p>
    <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-outline-dark btn-sm mt-2">
      Continue Shopping
    </a>
  </div>
<?php else: ?>
  <div class="mini-cart-list">
    <?php foreach ($cart['items'] as $item): ?>
      <div class="mini-cart-item" data-cart-key="<?= e($item['cart_key']) ?>">
        <a href="<?= e(shop_url('product.php?slug=' . urlencode($item['product']['slug']))) ?>" class="item-img-link">
          <img src="<?= e(product_image_url($item['image'])) ?>" alt="<?= e($item['product_name']) ?>" class="item-thumb" loading="lazy">
        </a>
        <div class="item-details">
          <div class="item-header">
            <a href="<?= e(shop_url('product.php?slug=' . urlencode($item['product']['slug']))) ?>" class="item-name">
              <?= e($item['product_name']) ?>
            </a>
            <button type="button" class="item-remove-btn" data-cart-remove="<?= e($item['cart_key']) ?>" aria-label="Remove item">
              <i class="bi bi-x"></i>
            </button>
          </div>
          <?php if (!empty($item['variant_label'])): ?>
            <div class="item-variant"><?= e($item['variant_label']) ?></div>
          <?php endif; ?>
          <div class="item-footer">
            <div class="item-qty-stepper">
              <button type="button" class="qty-btn" data-qty-change="-1" data-cart-key="<?= e($item['cart_key']) ?>" aria-label="Decrease quantity">&minus;</button>
              <input type="number" class="qty-input" value="<?= (int)$item['qty'] ?>" min="1" max="<?= (int)$item['available_qty'] ?>" data-cart-key="<?= e($item['cart_key']) ?>" readonly>
              <button type="button" class="qty-btn" data-qty-change="1" data-cart-key="<?= e($item['cart_key']) ?>" aria-label="Increase quantity" <?= $item['qty'] >= $item['available_qty'] ? 'disabled' : '' ?>>&plus;</button>
            </div>
            <div class="item-price">
              <?= e(price($item['line_total'])) ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
$response['html'] = ob_get_clean();

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (function_exists('app_finish_response')) app_finish_response();
exit;
