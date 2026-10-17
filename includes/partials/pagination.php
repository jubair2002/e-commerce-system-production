<?php
/**
 * Shared Pagination Partial (Phase B).
 * Variables:
 *   $page (int, current page)
 *   $totalPages (int)
 *   $queryParams (array, existing $_GET params to preserve)
 */
$currentPage = (int)($page ?? 1);
$totalP = (int)($totalPages ?? 1);
if ($totalP <= 1) {
    return;
}
$q = $queryParams ?? $_GET;
?>
<nav aria-label="Page navigation" class="pagination-wrap">
  <a class="pagination-item <?= $currentPage <= 1 ? 'is-disabled' : '' ?>"
     href="?<?= http_build_query(array_merge($q, ['page' => $currentPage - 1])) ?>"
     aria-label="Previous page">
    <?= icon('chevron-left') ?>
  </a>

  <?php
  $startP = max(1, $currentPage - 2);
  $endP = min($totalP, $currentPage + 2);
  if ($startP > 1): ?>
    <a class="pagination-item" href="?<?= http_build_query(array_merge($q, ['page' => 1])) ?>">1</a>
    <?php if ($startP > 2): ?><span class="pagination-item is-disabled border-0">&hellip;</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($p = $startP; $p <= $endP; $p++): ?>
    <a class="pagination-item <?= $p === $currentPage ? 'is-active' : '' ?>"
       href="?<?= http_build_query(array_merge($q, ['page' => $p])) ?>">
      <?= $p ?>
    </a>
  <?php endfor; ?>

  <?php if ($endP < $totalP): ?>
    <?php if ($endP < $totalP - 1): ?><span class="pagination-item is-disabled border-0">&hellip;</span><?php endif; ?>
    <a class="pagination-item" href="?<?= http_build_query(array_merge($q, ['page' => $totalP])) ?>"><?= $totalP ?></a>
  <?php endif; ?>

  <a class="pagination-item <?= $currentPage >= $totalP ? 'is-disabled' : '' ?>"
     href="?<?= http_build_query(array_merge($q, ['page' => $currentPage + 1])) ?>"
     aria-label="Next page">
    <?= icon('chevron-right') ?>
  </a>
</nav>
