<?php
/**
 * Shared Pagination Partial.
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
<nav aria-label="Page navigation" class="mt-4 pt-2">
  <ul class="pagination pagination-sm justify-content-center gap-1 mb-0">
    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
      <a class="page-link rounded-1" href="?<?= http_build_query(array_merge($q, ['page' => $currentPage - 1])) ?>" aria-label="Previous">
        <i class="bi bi-chevron-left"></i>
      </a>
    </li>

    <?php
    $startP = max(1, $currentPage - 2);
    $endP = min($totalP, $currentPage + 2);
    if ($startP > 1): ?>
      <li class="page-item"><a class="page-link rounded-1" href="?<?= http_build_query(array_merge($q, ['page' => 1])) ?>">1</a></li>
      <?php if ($startP > 2): ?><li class="page-item disabled"><span class="page-link rounded-1 border-0">&hellip;</span></li><?php endif; ?>
    <?php endif; ?>

    <?php for ($p = $startP; $p <= $endP; $p++): ?>
      <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
        <a class="page-link rounded-1" href="?<?= http_build_query(array_merge($q, ['page' => $p])) ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>

    <?php if ($endP < $totalP): ?>
      <?php if ($endP < $totalP - 1): ?><li class="page-item disabled"><span class="page-link rounded-1 border-0">&hellip;</span></li><?php endif; ?>
      <li class="page-item"><a class="page-link rounded-1" href="?<?= http_build_query(array_merge($q, ['page' => $totalP])) ?>"><?= $totalP ?></a></li>
    <?php endif; ?>

    <li class="page-item <?= $currentPage >= $totalP ? 'disabled' : '' ?>">
      <a class="page-link rounded-1" href="?<?= http_build_query(array_merge($q, ['page' => $currentPage + 1])) ?>" aria-label="Next">
        <i class="bi bi-chevron-right"></i>
      </a>
    </li>
  </ul>
</nav>
