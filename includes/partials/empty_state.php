<?php
/**
 * Shared Empty State Partial.
 * Variables:
 *   $emptyIcon (string)
 *   $emptyTitle (string)
 *   $emptyMessage (string)
 *   $emptyActionUrl (string|null)
 *   $emptyActionLabel (string|null)
 */
$icon = $emptyIcon ?? 'bi-inbox';
$title = $emptyTitle ?? 'No items found';
$message = $emptyMessage ?? 'There are currently no items to display.';
$actionUrl = $emptyActionUrl ?? null;
$actionLabel = $emptyActionLabel ?? null;
?>
<div class="empty-state text-center py-5 px-3 my-3">
  <div class="empty-state-icon fs-1 text-muted mb-3 opacity-75">
    <i class="bi <?= e($icon) ?>"></i>
  </div>
  <h3 class="h5 fw-semibold text-dark mb-2"><?= e($title) ?></h3>
  <p class="text-muted small mb-4 mx-auto" style="max-width: 440px;"><?= e($message) ?></p>
  <?php if (!empty($actionUrl) && !empty($actionLabel)): ?>
    <a href="<?= e($actionUrl) ?>" class="btn btn-editorial-primary btn-sm px-4">
      <?= e($actionLabel) ?>
    </a>
  <?php endif; ?>
</div>
