<?php
/**
 * Shared Breadcrumb Partial.
 * Variables:
 *   $breadcrumbs (array of ['label' => string, 'url' => string|null])
 */
if (empty($breadcrumbs) || !is_array($breadcrumbs)) {
    return;
}
?>
<nav aria-label="breadcrumb" class="py-2 mb-3">
  <ol class="breadcrumb small mb-0">
    <li class="breadcrumb-item">
      <a href="<?= e(route('home')) ?>" class="text-muted text-decoration-none">
        <i class="bi bi-house-door me-1"></i>Home
      </a>
    </li>
    <?php
    $lastIndex = count($breadcrumbs) - 1;
    foreach ($breadcrumbs as $idx => $bc):
        $isLast = ($idx === $lastIndex);
    ?>
      <?php if ($isLast || empty($bc['url'])): ?>
        <li class="breadcrumb-item active text-dark fw-medium" aria-current="page">
          <?= e($bc['label']) ?>
        </li>
      <?php else: ?>
        <li class="breadcrumb-item">
          <a href="<?= e($bc['url']) ?>" class="text-muted text-decoration-none">
            <?= e($bc['label']) ?>
          </a>
        </li>
      <?php endif; ?>
    <?php endforeach; ?>
  </ol>
</nav>
