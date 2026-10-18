<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$id = (int)($_GET['id'] ?? 0);
$banner = null;

if ($id > 0) {
    $stmt = db()->prepare("SELECT * FROM storefront_banners WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $banner = $stmt->fetch();
    if (!$banner) {
        flash('warning', 'Banner not found.');
        admin_redirect('banners.php');
    }
}

$pageTitle = $banner ? 'Edit Banner: ' . $banner['title'] : 'Add New Banner';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $title      = trim((string)($_POST['title'] ?? ''));
    $subtitle   = trim((string)($_POST['subtitle'] ?? ''));
    $buttonText = trim((string)($_POST['button_text'] ?? ''));
    $buttonUrl  = trim((string)($_POST['button_url'] ?? ''));
    $placement  = in_array($_POST['placement'] ?? '', ['hero', 'promo_strip', 'category_row'], true) ? $_POST['placement'] : 'hero';
    $position   = (int)($_POST['position'] ?? 0);
    $startsAt   = !empty($_POST['starts_at']) ? $_POST['starts_at'] : null;
    $endsAt     = !empty($_POST['ends_at']) ? $_POST['ends_at'] : null;
    $status     = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

    if ($title === '') {
        $errors[] = 'Banner title is required.';
    }

    $imageName = $banner['image'] ?? null;

    // Handle image upload
    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $f = $_FILES['image'];
        if ($f['size'] > 4 * 1024 * 1024) {
            $errors[] = 'Banner image exceeds maximum size of 4 MB.';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $f['tmp_name']);
            finfo_close($finfo);

            $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
            if (!in_array($mime, $allowedMimes, true)) {
                $errors[] = 'Invalid image type (only PNG, JPG, WebP allowed).';
            } else {
                $ext = pathinfo($f['name'], PATHINFO_EXTENSION) ?: 'jpg';
                $newFilename = 'banner_' . bin2hex(random_bytes(8)) . '.' . strtolower($ext);
                $destDir = UPLOADS_PATH . '/banners';
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0755, true);
                }

                if (move_uploaded_file($f['tmp_name'], $destDir . '/' . $newFilename)) {
                    // Delete old image if present
                    if (!empty($imageName) && file_exists($destDir . '/' . $imageName)) {
                        @unlink($destDir . '/' . $imageName);
                    }
                    $imageName = $newFilename;
                } else {
                    $errors[] = 'Failed to upload banner image.';
                }
            }
        }
    }

    if (!$errors) {
        if ($banner) {
            db()->prepare(
                "UPDATE storefront_banners
                 SET title = :t, subtitle = :sub, button_text = :bt, button_url = :bu,
                     image = :img, placement = :pl, position = :pos, starts_at = :st, ends_at = :en, status = :stat
                 WHERE id = :id"
            )->execute([
                ':t'    => $title,
                ':sub'  => $subtitle ?: null,
                ':bt'   => $buttonText ?: null,
                ':bu'   => $buttonUrl ?: null,
                ':img'  => $imageName,
                ':pl'   => $placement,
                ':pos'  => $position,
                ':st'   => $startsAt,
                ':en'   => $endsAt,
                ':stat' => $status,
                ':id'   => $id,
            ]);
            log_activity('banner.update', "Updated banner #{$id}: {$title}");
            flash('success', 'Banner updated successfully.');
        } else {
            db()->prepare(
                "INSERT INTO storefront_banners (title, subtitle, button_text, button_url, image, placement, position, starts_at, ends_at, status)
                 VALUES (:t, :sub, :bt, :bu, :img, :pl, :pos, :st, :en, :stat)"
            )->execute([
                ':t'    => $title,
                ':sub'  => $subtitle ?: null,
                ':bt'   => $buttonText ?: null,
                ':bu'   => $buttonUrl ?: null,
                ':img'  => $imageName,
                ':pl'   => $placement,
                ':pos'  => $position,
                ':st'   => $startsAt,
                ':en'   => $endsAt,
                ':stat' => $status,
            ]);
            log_activity('banner.create', "Created banner: {$title}");
            flash('success', 'Banner created successfully.');
        }
        if (class_exists('FileCache')) {
            FileCache::deletePattern('banners:*');
            FileCache::deletePattern('home:*');
        }
        admin_redirect('banners.php');
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <a href="<?= e(admin_url('banners.php')) ?>" class="text-decoration-none small text-muted">
      <i class="bi bi-arrow-left"></i> Back to Banners
    </a>
    <h3 class="mt-1 mb-0"><?= $banner ? 'Edit Banner' : 'Create New Banner' ?></h3>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger alert-dismissible fade show mb-4">
    <ul class="mb-0 ps-3">
      <?php foreach ($errors as $e): ?>
        <li><?= e($e) ?></li>
      <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<div class="row g-4">
  <!-- Left: Banner Form -->
  <div class="col-lg-7">
    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>

          <div class="mb-3">
            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
            <input type="text" name="title" id="bannerTitleInput" class="form-control"
                   value="<?= e($_POST['title'] ?? ($banner['title'] ?? '')) ?>"
                   placeholder="e.g. Modern Essentials for Everyday Living" required
                   oninput="document.getElementById('prevTitle').textContent = this.value || 'Banner Title';">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Subtitle / Caption</label>
            <textarea name="subtitle" id="bannerSubInput" class="form-control" rows="2"
                      placeholder="e.g. Discover intentional design crafted for durability and comfort."
                      oninput="document.getElementById('prevSub').textContent = this.value || 'Banner Subtitle text goes here.';"><?= e($_POST['subtitle'] ?? ($banner['subtitle'] ?? '')) ?></textarea>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Button Text</label>
              <input type="text" name="button_text" id="bannerBtnTextInput" class="form-control"
                     value="<?= e($_POST['button_text'] ?? ($banner['button_text'] ?? '')) ?>"
                     placeholder="e.g. Explore Collection"
                     oninput="document.getElementById('prevBtn').textContent = this.value || 'Shop Now';">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Button Destination URL</label>
              <input type="text" name="button_url" class="form-control"
                     value="<?= e($_POST['button_url'] ?? ($banner['button_url'] ?? '')) ?>"
                     placeholder="e.g. /shop.php or /category.php?slug=tees">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Banner Background Image</label>
            <input type="file" name="image" class="form-control" accept="image/png,image/jpeg,image/webp">
            <div class="form-text">Recommended size: 1600 &times; 600 px (hero) or 1200 &times; 400 px (promo). Max 4MB.</div>
            <?php $bannerUrl = !empty($banner['image']) ? banner_image_url($banner['image']) : null; ?>
            <?php if ($bannerUrl): ?>
              <div class="mt-2 p-2 bg-light rounded border d-inline-block">
                <img src="<?= e($bannerUrl) ?>" alt="Current image" style="max-height: 80px;" class="rounded">
                <span class="small text-muted ms-2"><?= e($banner['image']) ?></span>
              </div>
            <?php endif; ?>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Placement</label>
              <?php $pl = $_POST['placement'] ?? ($banner['placement'] ?? 'hero'); ?>
              <select name="placement" class="form-select">
                <option value="hero" <?= $pl === 'hero' ? 'selected' : '' ?>>Hero Slider</option>
                <option value="promo_strip" <?= $pl === 'promo_strip' ? 'selected' : '' ?>>Promo Strip</option>
                <option value="category_row" <?= $pl === 'category_row' ? 'selected' : '' ?>>Category Showcase</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Sort Position</label>
              <input type="number" name="position" class="form-control"
                     value="<?= e($_POST['position'] ?? ($banner['position'] ?? 0)) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <?php $st = $_POST['status'] ?? ($banner['status'] ?? 'active'); ?>
              <select name="status" class="form-select">
                <option value="active" <?= $st === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $st === 'inactive' ? 'selected' : '' ?>>Inactive</option>
              </select>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Schedule Start Time (Optional)</label>
              <input type="datetime-local" name="starts_at" class="form-control"
                     value="<?= !empty($banner['starts_at']) ? date('Y-m-d\TH:i', strtotime($banner['starts_at'])) : '' ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Schedule End Time (Optional)</label>
              <input type="datetime-local" name="ends_at" class="form-control"
                     value="<?= !empty($banner['ends_at']) ? date('Y-m-d\TH:i', strtotime($banner['ends_at'])) : '' ?>">
            </div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary px-4">
              <i class="bi bi-save me-1"></i> Save Banner
            </button>
            <a href="<?= e(admin_url('banners.php')) ?>" class="btn btn-outline-secondary">
              Cancel
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Right: Live Preview Card -->
  <div class="col-lg-5">
    <div class="card shadow-sm border-0 sticky-top" style="top: 90px;">
      <div class="card-header bg-white py-3 border-bottom">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-eye text-primary me-2"></i> Live Storefront Preview</h6>
      </div>
      <div class="card-body p-4">
        <div class="p-4 rounded border bg-dark text-white text-center position-relative overflow-hidden"
             style="min-height: 240px; display: flex; flex-direction: column; justify-content: center; align-items: center; background: linear-gradient(135deg, #1f2937, #111827);">
          <h4 id="prevTitle" class="fw-bold mb-2">
            <?= e($banner['title'] ?? 'Banner Title') ?>
          </h4>
          <p id="prevSub" class="text-white-50 small mb-3" style="max-width: 320px;">
            <?= e($banner['subtitle'] ?? 'Banner Subtitle text goes here.') ?>
          </p>
          <button type="button" id="prevBtn" class="btn btn-primary btn-sm px-3">
            <?= e($banner['button_text'] ?? 'Explore Now') ?>
          </button>
        </div>
        <div class="text-muted small mt-2 text-center">
          Preview approximates hero banner styling on desktop.
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
