<?php
/**
 * 404 Not Found Page.
 * Matches the calm, editorial, minimal storefront design.
 */
require_once __DIR__ . '/../../includes/shop_bootstrap.php';

http_response_code(404);
$pageTitle = 'Page Not Found';

include __DIR__ . '/../../includes/partials/header.php';
?>

<section class="section py-5">
  <div class="container">
    <div class="empty-state-card py-5">
      <div class="empty-state-icon">
        <i class="bi bi-compass"></i>
      </div>
      <h1 class="h2 mb-3">Page Not Found</h1>
      <p class="text-muted mb-4">
        The page you are looking for may have been moved, renamed, or is temporarily unavailable.
      </p>
      <div class="d-flex justify-content-center gap-3">
        <a href="<?= e(route('home')) ?>" class="btn btn-editorial-primary">
          Back to Home
        </a>
        <a href="<?= e(route('shop')) ?>" class="btn btn-editorial-secondary">
          Explore Shop
        </a>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/../../includes/partials/footer.php'; ?>
