<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$isEdit = ($id > 0);
$pageTitle = $isEdit ? 'Edit Content Page' : 'New Content Page';

$page = [
    'title' => '',
    'slug' => '',
    'content' => '',
    'meta_title' => '',
    'meta_description' => '',
    'show_in_header' => 0,
    'show_in_footer' => 1,
    'position' => 0,
    'status' => 'published',
];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE id = ?");
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash('danger', 'Page not found.');
        admin_redirect('pages.php');
    }
    $page = $found;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $title = trim((string)($_POST['title'] ?? ''));
    $slug  = trim((string)($_POST['slug'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $metaTitle = trim((string)($_POST['meta_title'] ?? ''));
    $metaDesc  = trim((string)($_POST['meta_description'] ?? ''));
    $showInHeader = !empty($_POST['show_in_header']) ? 1 : 0;
    $showInFooter = !empty($_POST['show_in_footer']) ? 1 : 0;
    $position = (int)($_POST['position'] ?? 0);
    $status   = in_array($_POST['status'] ?? '', ['published', 'draft']) ? $_POST['status'] : 'draft';

    if ($title === '') {
        $errors[] = 'Title is required.';
    }

    if ($slug === '') {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
        $slug = trim($slug, '-');
    } else {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $slug));
        $slug = trim($slug, '-');
    }

    if ($slug === '') {
        $errors[] = 'A valid URL slug is required.';
    } else {
        // Unique slug check
        $stSlug = $pdo->prepare("SELECT id FROM pages WHERE slug = ? AND id != ? LIMIT 1");
        $stSlug->execute([$slug, $id]);
        if ($stSlug->fetchColumn()) {
            $errors[] = 'This URL slug is already in use by another page.';
        }
    }

    // HTML sanitization: strip dangerous tags and script execution
    $sanitizedContent = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $content);
    $sanitizedContent = preg_replace('#\son[a-z]+="[^"]*"#i', '', $sanitizedContent);
    $sanitizedContent = preg_replace('#\son[a-z]+=\'[^\']*\'#i', '', $sanitizedContent);
    $sanitizedContent = preg_replace('#href=["\']javascript:[^"\']*["\']#i', 'href="#"', $sanitizedContent);

    if (empty($errors)) {
        if ($isEdit) {
            $upd = $pdo->prepare("
                UPDATE pages SET 
                    title = ?, slug = ?, content = ?, meta_title = ?, meta_description = ?,
                    show_in_header = ?, show_in_footer = ?, position = ?, status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([
                $title, $slug, $sanitizedContent, $metaTitle, $metaDesc,
                $showInHeader, $showInFooter, $position, $status, $id
            ]);
            log_activity('page.edit', "Updated content page '{$title}'");
            flash('success', "Page '{$title}' updated successfully.");
        } else {
            $ins = $pdo->prepare("
                INSERT INTO pages 
                    (title, slug, content, meta_title, meta_description, show_in_header, show_in_footer, position, status, created_at, updated_at)
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $ins->execute([
                $title, $slug, $sanitizedContent, $metaTitle, $metaDesc,
                $showInHeader, $showInFooter, $position, $status
            ]);
            $newId = (int)$pdo->lastInsertId();
            log_activity('page.create', "Created content page '{$title}'");
            flash('success', "Page '{$title}' created successfully.");
        }
        admin_redirect('pages.php');
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-1"><?= e($pageTitle) ?></h1>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="<?= e(admin_url('pages.php')) ?>">Pages</a></li>
        <li class="breadcrumb-item active"><?= $isEdit ? 'Edit' : 'New' ?></li>
      </ol>
    </nav>
  </div>
  <?php if ($isEdit): ?>
    <a href="<?= e(route('page', ['slug' => $page['slug']])) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-box-arrow-up-right me-1"></i> Preview Live
    </a>
  <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0 ps-3">
      <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="">
  <?= csrf_field() ?>
  <div class="row g-4">
    <!-- Main Content Col -->
    <div class="col-lg-8">
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-4">
          <div class="mb-3">
            <label for="pageTitleInput" class="form-label fw-semibold">Page Title <span class="text-danger">*</span></label>
            <input type="text"
                   name="title"
                   id="pageTitleInput"
                   class="form-control form-control-lg"
                   placeholder="e.g. Terms of Service, About Us, Privacy Policy"
                   value="<?= e($page['title']) ?>"
                   required>
          </div>

          <div class="mb-3">
            <label for="pageSlugInput" class="form-label fw-semibold">URL Slug</label>
            <div class="input-group">
              <span class="input-group-text bg-light text-muted">/page/</span>
              <input type="text"
                     name="slug"
                     id="pageSlugInput"
                     class="form-control"
                     placeholder="about-us"
                     value="<?= e($page['slug']) ?>">
            </div>
            <div class="form-text">Leave blank to auto-generate from page title.</div>
          </div>

          <div class="mb-3">
            <label for="pageContentInput" class="form-label fw-semibold">Page Content (HTML supported)</label>
            <textarea name="content"
                      id="pageContentInput"
                      class="form-control font-monospace"
                      rows="14"
                      placeholder="Write your page content here... Supports standard HTML tags (<h2>, <p>, <ul>, <table>, etc.)"><?= e($page['content']) ?></textarea>
            <div class="form-text">Standard semantic HTML is rendered cleanly in the storefront editorial layout.</div>
          </div>
        </div>
      </div>

      <!-- SEO Settings -->
      <div class="card shadow-sm border-0">
        <div class="card-header bg-light py-3">
          <h5 class="card-title mb-0 fs-6 fw-bold">Search Engine Optimization (SEO)</h5>
        </div>
        <div class="card-body p-4">
          <div class="mb-3">
            <label for="metaTitle" class="form-label">Meta Title</label>
            <input type="text" name="meta_title" id="metaTitle" class="form-control" value="<?= e($page['meta_title'] ?? '') ?>" placeholder="Optional custom browser title">
          </div>
          <div class="mb-0">
            <label for="metaDesc" class="form-label">Meta Description</label>
            <textarea name="meta_description" id="metaDesc" class="form-control" rows="3" placeholder="Brief summary for search engine result snippets"><?= e($page['meta_description'] ?? '') ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- Sidebar Settings Col -->
    <div class="col-lg-4">
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-light py-3">
          <h5 class="card-title mb-0 fs-6 fw-bold">Publishing</h5>
        </div>
        <div class="card-body p-4">
          <div class="mb-3">
            <label for="statusSelect" class="form-label fw-semibold">Status</label>
            <select name="status" id="statusSelect" class="form-select">
              <option value="published" <?= $page['status'] === 'published' ? 'selected' : '' ?>>Published (Live)</option>
              <option value="draft" <?= $page['status'] === 'draft' ? 'selected' : '' ?>>Draft (Hidden)</option>
            </select>
          </div>

          <div class="mb-3">
            <label for="posInput" class="form-label fw-semibold">Menu Position / Order</label>
            <input type="number" name="position" id="posInput" class="form-control" value="<?= (int)($page['position'] ?? 0) ?>">
            <div class="form-text">Lower numbers appear first.</div>
          </div>

          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="show_in_header" id="showInHeader" value="1" <?= !empty($page['show_in_header']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="showInHeader">Show in Header Navigation</label>
          </div>

          <div class="form-check form-switch mb-4">
            <input class="form-check-input" type="checkbox" name="show_in_footer" id="showInFooter" value="1" <?= !empty($page['show_in_footer']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="showInFooter">Show in Footer Navigation</label>
          </div>

          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Save Changes' : 'Publish Page' ?>
            </button>
            <a href="<?= e(admin_url('pages.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
