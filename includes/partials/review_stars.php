<?php
/**
 * Shared Star Rating Display Partial (Phase B).
 * Variables:
 *   $rating (float 0.0 - 5.0)
 *   $reviewCount (int|null, optional total count)
 *   $showScore (bool, optional display numeric value)
 */
$score = (float)($rating ?? 0.0);
$count = isset($reviewCount) ? (int)$reviewCount : null;
$showNum = !empty($showScore);
?>
<div class="rating-summary" title="<?= number_format($score, 1) ?> out of 5 stars">
  <div class="rating-stars" aria-hidden="true">
    <?php for ($i = 1; $i <= 5; $i++): ?>
      <?php if ($score >= $i): ?>
        <?= icon('star') ?>
      <?php else: ?>
        <?= icon('star-empty') ?>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php if ($showNum): ?>
    <span class="rating-score"><?= number_format($score, 1) ?></span>
  <?php endif; ?>
  <?php if ($count !== null): ?>
    <span class="text-muted">(<?= number_format($count) ?>)</span>
  <?php endif; ?>
</div>
