<?php
/**
 * Shared Breadcrumb Partial (Phase B).
 * Variables:
 *   $breadcrumbs (array of ['label' => string, 'url' => string|null])
 */
if (empty($breadcrumbs) || !is_array($breadcrumbs)) {
    return;
}
?>
<nav aria-label="breadcrumb" class="breadcrumb-nav">
  <ol class="breadcrumb-list">
    <li class="breadcrumb-item">
      <a href="<?= e(route('home')) ?>">Home</a>
    </li>
    <li class="breadcrumb-separator" aria-hidden="true">/</li>
    <?php
    $lastIndex = count($breadcrumbs) - 1;
    foreach ($breadcrumbs as $idx => $bc):
        $isLast = ($idx === $lastIndex);
    ?>
      <?php if ($isLast || empty($bc['url'])): ?>
        <li class="breadcrumb-item is-current" aria-current="page">
          <?= e($bc['label']) ?>
        </li>
      <?php else: ?>
        <li class="breadcrumb-item">
          <a href="<?= e($bc['url']) ?>">
            <?= e($bc['label']) ?>
          </a>
        </li>
        <li class="breadcrumb-separator" aria-hidden="true">/</li>
      <?php endif; ?>
    <?php endforeach; ?>
  </ol>
</nav>
