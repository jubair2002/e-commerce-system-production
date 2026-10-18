<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$pageTitle = 'Storefront Banners';

// Handle banner deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);

    $stmt = db()->prepare("SELECT * FROM storefront_banners WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $banner = $stmt->fetch();

    if ($banner) {
        if (!empty($banner['image'])) {
            $imgPath = UPLOADS_PATH . '/banners/' . $banner['image'];
            if (file_exists($imgPath)) {
                @unlink($imgPath);
            }
        }
        db()->prepare("DELETE FROM storefront_banners WHERE id = :id")->execute([':id' => $id]);
        if (class_exists('FileCache')) {
            FileCache::deletePattern('banners:*');
            FileCache::deletePattern('home:*');
        }
        log_activity('banner.delete', "Deleted banner #{$id}: {$banner['title']}");
        flash('success', "Banner \"{$banner['title']}\" deleted.");
    }
    admin_redirect('banners.php');
}

// Fetch banners
$banners = [];
try {
    $banners = db()->query(
        "SELECT * FROM storefront_banners ORDER BY placement ASC, position ASC, id DESC"
    )->fetchAll();
} catch (Throwable $e) {
    $banners = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="mb-1">Storefront Banners</h3>
    <p class="text-muted small mb-0">Manage promotional banners for hero slider, promo strips, and category showcases.</p>
  </div>
  <a href="<?= e(admin_url('banner_form.php')) ?>" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i> Add New Banner
  </a>
</div>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width: 120px;">Image</th>
          <th>Title & Subtitle</th>
          <th>Placement</th>
          <th style="width: 90px;">Position</th>
          <th>Schedule</th>
          <th>Status</th>
          <th class="text-end" style="width: 130px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$banners): ?>
          <tr>
            <td colspan="7" class="text-center py-5 text-muted">
              <i class="bi bi-images fs-1 text-muted d-block mb-2"></i>
              No storefront banners created yet. Click "Add New Banner" to create one.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($banners as $b): ?>
            <tr>
              <td>
                <?php $bannerUrl = !empty($b['image']) ? banner_image_url($b['image']) : null; ?>
                <?php if ($bannerUrl): ?>
                  <img src="<?= e($bannerUrl) ?>" alt="<?= e($b['title']) ?>"
                       class="rounded border" style="width: 100px; height: 50px; object-fit: cover;">
                <?php else: ?>
                  <div class="bg-light rounded border text-muted d-flex align-items-center justify-content-center" style="width: 100px; height: 50px; font-size: 11px;">
                    No Image
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <div class="fw-bold"><?= e($b['title']) ?></div>
                <?php if (!empty($b['subtitle'])): ?>
                  <div class="small text-muted"><?= e($b['subtitle']) ?></div>
                <?php endif; ?>
                <?php if (!empty($b['button_text'])): ?>
                  <div class="small text-primary mt-1">
                    <i class="bi bi-link-45deg"></i> <?= e($b['button_text']) ?> &rarr; <code><?= e($b['button_url'] ?: '#') ?></code>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge bg-light text-dark border text-uppercase">
                  <?= e(str_replace('_', ' ', $b['placement'])) ?>
                </span>
              </td>
              <td><?= (int)$b['position'] ?></td>
              <td class="small text-muted">
                <?php if (!empty($b['starts_at']) || !empty($b['ends_at'])): ?>
                  <?= !empty($b['starts_at']) ? date('M j', strtotime($b['starts_at'])) : 'Immediate' ?>
                  &ndash;
                  <?= !empty($b['ends_at']) ? date('M j, Y', strtotime($b['ends_at'])) : 'Ongoing' ?>
                <?php else: ?>
                  Always Active
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $b['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?> text-uppercase">
                  <?= e($b['status']) ?>
                </span>
              </td>
              <td class="text-end">
                <div class="btn-group btn-group-sm">
                  <a href="<?= e(admin_url('banner_form.php?id=' . $b['id'])) ?>" class="btn btn-outline-secondary" title="Edit">
                    <i class="bi bi-pencil"></i>
                  </a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this banner?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger" title="Delete">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
