<?php
/**
 * Customer Wishlist Page (Prompt 13).
 * Batched data retrieval, live availability badges, move to cart, and item removal.
 */
require_once __DIR__ . '/_layout.php';

$customer = customer_require_login();
$cid = (int)$customer['id'];
$pdo = db();

$wishlistEnabled = (int)setting('customer_wishlist_enabled', '1') === 1;

// Handle POST actions: Remove from wishlist & Move to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $wishlistEnabled) {
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        flash('danger', 'Invalid or expired session. Please try again.');
        header('Location: ' . shop_url('account/wishlist.php'));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $productId = (int)($_POST['product_id'] ?? 0);

    // 1. Remove from wishlist
    if ($action === 'remove' && $productId > 0) {
        $pdo->prepare("DELETE FROM wishlists WHERE customer_id = :cid AND product_id = :pid")
            ->execute([':cid' => $cid, ':pid' => $productId]);
        flash('info', 'Item removed from your wishlist.');
        header('Location: ' . shop_url('account/wishlist.php'));
        exit;
    }

    // 2. Move to cart
    elseif ($action === 'move_to_cart' && $productId > 0) {
        $product = product_get($productId);
        if (!$product || $product['status'] !== 'published' || !empty($product['deleted_at'])) {
            flash('warning', 'This product is no longer available.');
        } elseif (($product['product_type'] ?? '') === 'variable') {
            // For variable products, direct customer to product page to choose options
            header('Location: ' . shop_url('product.php?slug=' . urlencode($product['slug'])));
            exit;
        } else {
            // Simple product: check stock availability
            $inStock = true;
            if (!empty($product['manage_stock']) && (int)$product['stock_quantity'] <= 0) {
                $inStock = false;
            }

            if (!$inStock) {
                flash('warning', 'Sorry, "' . htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') . '" is currently out of stock.');
            } else {
                cart_add($productId, 1);
                // Remove from wishlist once added to cart
                $pdo->prepare("DELETE FROM wishlists WHERE customer_id = :cid AND product_id = :pid")
                    ->execute([':cid' => $cid, ':pid' => $productId]);
                flash('success', '"' . htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') . '" was moved to your cart.');
            }
        }
        header('Location: ' . shop_url('account/wishlist.php'));
        exit;
    }
}

// Fetch Wishlist Products
$products = [];
if ($wishlistEnabled) {
    try {
        $stmtW = $pdo->prepare(
            "SELECT p.*, w.created_at AS added_to_wishlist_at
             FROM wishlists w
             JOIN products p ON p.id = w.product_id
             WHERE w.customer_id = :cid AND p.deleted_at IS NULL
             ORDER BY w.created_at DESC"
        );
        $stmtW->execute([':cid' => $cid]);
        $products = $stmtW->fetchAll();

        // Batch pre-fetch all pricing, images, variants, and stock in 1 efficient query
        if (!empty($products) && function_exists('products_attach_card_data')) {
            $products = products_attach_card_data($products);
        }
    } catch (Throwable $e) {
        $products = [];
    }
}

account_layout_start('wishlist', 'Saved Wishlist', $customer);
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">My Wishlist</h1>
    <p class="text-muted small mb-0">Items you saved for later. Keep track of stock and add directly to your cart.</p>
  </div>
  <?php if (!empty($products)): ?>
    <span class="badge bg-light text-dark border px-3 py-2 fw-semibold">
      <?= count($products) ?> <?= count($products) === 1 ? 'item saved' : 'items saved' ?>
    </span>
  <?php endif; ?>
</div>

<?php if (!$wishlistEnabled): ?>
  <div class="alert alert-warning text-center py-4">
    <?= icon('info', 'i-sm me-1') ?> The customer wishlist feature is currently disabled by store management.
  </div>
<?php elseif (empty($products)): ?>
  <div class="account-card text-center py-5">
    <div class="mb-3 d-flex justify-content-center"><?= icon('heart', 'i-xl text-rose', 'Wishlist') ?></div>
    <h3 class="h5 fw-bold mb-1">Your Wishlist is Empty</h3>
    <p class="text-muted small mb-3">Explore our curated collections and tap the heart icon to save items you love.</p>
    <a href="<?= e(shop_url('shop.php')) ?>" class="btn btn-editorial-primary btn-sm">
      <?= icon('bag', 'i-sm me-1') ?> Explore Products
    </a>
  </div>
<?php else: ?>
  <div class="row g-4">
    <?php foreach ($products as $p): ?>
      <?php
      $isVariable = (($p['product_type'] ?? '') === 'variable');
      $cardData = $p['card_data'] ?? [];
      $imageUrl = $cardData['image_url'] ?? (!empty($p['featured_image']) ? product_image_url($p['featured_image']) : (!empty($p['image']) ? product_image_url($p['image']) : ''));
      $priceHtml = $cardData['price_html'] ?? price($p['price']);

      // Live availability status badge
      $stockBadge = '';
      if ($stock <= 0 && (!empty($p['manage_stock']) || $isVariable)) {
          $stockBadge = '<span class="badge bg-secondary">Sold Out</span>';
      } elseif ($stock > 0 && $stock <= 5 && !empty($p['manage_stock'])) {
          $stockBadge = '<span class="badge bg-warning text-dark">Only ' . $stock . ' left</span>';
      } else {
          $stockBadge = '<span class="badge bg-success bg-opacity-75">In Stock</span>';
      }
      ?>
      <div class="col-sm-6 col-md-4 col-xl-3">
        <div class="card h-100 border shadow-sm product-card">
          <!-- Thumbnail & Badges -->
          <div class="position-relative overflow-hidden bg-light" style="height: 220px;">
            <a href="<?= e(shop_url('product.php?slug=' . urlencode($p['slug']))) ?>" class="d-block w-100 h-100">
              <?php if (!empty($imageUrl)): ?>
                <img src="<?= e($imageUrl) ?>" alt="<?= e($p['name']) ?>" class="w-100 h-100 object-fit-cover">
              <?php else: ?>
                <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted">
                  <?= icon('box', 'i-xl text-muted') ?>
                </div>
              <?php endif; ?>
            </a>
            <!-- Stock Badge overlay -->
            <div class="position-absolute top-0 start-0 m-2">
              <?= $stockBadge ?>
            </div>
            <!-- Remove heart button -->
            <form method="post" action="wishlist.php" class="position-absolute top-0 end-0 m-2 m-0 p-0">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="btn btn-sm btn-light rounded-circle shadow-sm p-1 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;" title="Remove from wishlist" aria-label="Remove item">
                <?= icon('trash', 'i-sm text-danger') ?>
              </button>
            </form>
          </div>

          <!-- Body -->
          <div class="card-body p-3 d-flex flex-column justify-content-between">
            <div>
              <h6 class="card-title mb-1 text-truncate" title="<?= e($p['name']) ?>">
                <a href="<?= e(shop_url('product.php?slug=' . urlencode($p['slug']))) ?>" class="text-ink text-decoration-none fw-semibold">
                  <?= e($p['name']) ?>
                </a>
              </h6>
              <div class="fw-bold fs-6 text-primary mb-3">
                <?= $priceHtml ?>
              </div>
            </div>

            <!-- Action: Move to Cart or Choose Options -->
            <div>
              <?php if ($isVariable): ?>
                <a href="<?= e(shop_url('product.php?slug=' . urlencode($p['slug']))) ?>" class="btn btn-outline-dark btn-sm w-100">
                  <?= icon('filter', 'i-sm me-1') ?> Choose Options
                </a>
              <?php elseif ($stock <= 0 && !empty($p['manage_stock'])): ?>
                <button type="button" class="btn btn-secondary btn-sm w-100" disabled>
                  Sold Out
                </button>
              <?php else: ?>
                <form method="post" action="wishlist.php" class="m-0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="move_to_cart">
                  <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                  <button type="submit" class="btn btn-editorial-primary btn-sm w-100">
                    <?= icon('bag', 'i-sm me-1') ?> Move to Cart
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
account_layout_end();
