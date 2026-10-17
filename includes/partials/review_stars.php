<?php
/**
 * Shared Star Rating Display Partial.
 * Variables:
 *   $rating (float 0.0 - 5.0)
 *   $reviewCount (int|null, optional total count)
 *   $showScore (bool, optional display numeric value)
 */
$score = (float)($rating ?? 0.0);
$count = isset($reviewCount) ? (int)$reviewCount : null;
$showNum = !empty($showScore);
?>
<div class="review-stars d-inline-flex align-items-center gap-1 text-warning" title="<?= number_format($score, 1) ?> out of 5 stars">
  <?php for ($i = 1; $i <= 5; $i++): ?>
    <?php if ($score >= $i): ?>
      <i class="bi bi-star-fill" style="font-size: 0.85em;"></i>
    <?php elseif ($score >= $i - 0.5): ?>
      <i class="bi bi-star-half" style="font-size: 0.85em;"></i>
    <?php else: ?>
      <i class="bi bi-star text-muted opacity-50" style="font-size: 0.85em;"></i>
    <?php endif; ?>
  <?php endfor; ?>
  <?php if ($showNum): ?>
    <span class="ms-1 fw-semibold text-dark small"><?= number_format($score, 1) ?></span>
  <?php endif; ?>
  <?php if ($count !== null): ?>
    <span class="text-muted small ms-1">(<?= number_format($count) ?>)</span>
  <?php endif; ?>
</div>
