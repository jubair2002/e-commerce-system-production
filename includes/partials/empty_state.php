<?php
/**
 * Shared Empty State Partial.
 * Variables:
 *   $emptyIcon (string - SVG icon symbol name, e.g. 'bag', 'heart', 'search')
 *   $emptyTitle (string)
 *   $emptyMessage (string)
 *   $emptyActionUrl (string|null)
 *   $emptyActionLabel (string|null)
 */
$iconName = $emptyIcon ?? 'bag';
if (str_starts_with($iconName, 'bi-')) {
    $iconName = str_replace('bi-', '', $iconName);
}
if ($iconName === 'inbox' || $iconName === 'cart-x' || $iconName === 'bag-x') $iconName = 'bag';
if ($iconName === 'search-x') $iconName = 'search';

$title = $emptyTitle ?? 'No items found';
$message = $emptyMessage ?? 'There are currently no items to display.';
$actionUrl = $emptyActionUrl ?? null;
$actionLabel = $emptyActionLabel ?? null;
?>
<div class="empty-state text-center py-5 px-3 my-4">
  <div class="text-muted mb-3" style="font-size: 40px; opacity: 0.6;">
    <?= icon($iconName) ?>
  </div>
  <h3 class="mb-2"><?= e($title) ?></h3>
  <p class="text-muted small mb-4 mx-auto" style="max-width: 440px;"><?= e($message) ?></p>
  <?php if (!empty($actionUrl) && !empty($actionLabel)): ?>
    <a href="<?= e($actionUrl) ?>" class="btn btn-primary btn-sm px-4">
      <?= e($actionLabel) ?>
    </a>
  <?php endif; ?>
</div>
