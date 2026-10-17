<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$pageTitle = 'Content Pages';
$pdo = db();

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        require_role('admin');
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT title FROM pages WHERE id = ?");
        $st->execute([$id]);
        $title = $st->fetchColumn();

        if ($title) {
            $pdo->prepare("DELETE FROM pages WHERE id = ?")->execute([$id]);
            log_activity('page.delete', "Deleted page '{$title}'");
            flash('success', "Page '{$title}' deleted successfully.");
        }
        admin_redirect('pages.php');
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT status FROM pages WHERE id = ?");
        $st->execute([$id]);
        $current = $st->fetchColumn();
        if ($current) {
            $next = ($current === 'published') ? 'draft' : 'published';
            $pdo->prepare("UPDATE pages SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$next, $id]);
            flash('success', "Page status updated to {$next}.");
        }
        admin_redirect('pages.php');
    }
}

// Fetch Pages
$pages = $pdo->query("SELECT * FROM pages ORDER BY position ASC, id ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-1">Content Pages (CMS)</h1>
    <p class="text-muted small mb-0">Manage storefront informational pages (About, Terms, Privacy, FAQ, etc.).</p>
  </div>
  <a href="<?= e(admin_url('page_form.php')) ?>" class="btn btn-primary d-inline-flex align-items-center gap-1">
    <i class="bi bi-plus-lg"></i> Add New Page
  </a>
</div>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width: 50px;">#</th>
          <th>Page Title</th>
          <th>Slug & URL</th>
          <th>Header / Footer</th>
          <th>Order</th>
          <th>Status</th>
          <th class="text-end" style="min-width: 150px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pages)): ?>
          <tr>
            <td colspan="7" class="text-center py-5 text-muted">
              <i class="bi bi-file-earmark-text fs-2 d-block mb-2"></i>
              No content pages created yet.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($pages as $p): ?>
            <tr>
              <td class="text-muted small"><?= (int)$p['id'] ?></td>
              <td>
                <div class="fw-semibold text-dark"><?= e($p['title']) ?></div>
                <div class="text-muted small">Updated <?= e(date('M d, Y', strtotime($p['updated_at'] ?? $p['created_at']))) ?></div>
              </td>
              <td>
                <span class="badge bg-light text-dark border">/page/<?= e($p['slug']) ?></span>
              </td>
              <td>
                <?php if (!empty($p['show_in_header'])): ?>
                  <span class="badge text-bg-info me-1">Header</span>
                <?php endif; ?>
                <?php if (!empty($p['show_in_footer'])): ?>
                  <span class="badge text-bg-secondary">Footer</span>
                <?php endif; ?>
                <?php if (empty($p['show_in_header']) && empty($p['show_in_footer'])): ?>
                  <span class="text-muted small">Hidden from nav</span>
                <?php endif; ?>
              </td>
              <td><?= (int)$p['position'] ?></td>
              <td>
                <form method="post" action="<?= e(admin_url('pages.php')) ?>" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_status">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button type="submit" class="btn btn-sm p-0 border-0" title="Click to toggle status">
                    <?php if ($p['status'] === 'published'): ?>
                      <span class="badge text-bg-success">Published</span>
                    <?php else: ?>
                      <span class="badge text-bg-warning">Draft</span>
                    <?php endif; ?>
                  </button>
                </form>
              </td>
              <td class="text-end">
                <a href="<?= e(route('page', ['slug' => $p['slug']])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="View live page">
                  <i class="bi bi-box-arrow-up-right"></i>
                </a>
                <a href="<?= e(admin_url('page_form.php?id=' . (int)$p['id'])) ?>" class="btn btn-sm btn-primary" title="Edit page">
                  <i class="bi bi-pencil"></i>
                </a>
                <?php if (has_role('admin')): ?>
                  <form method="post" action="<?= e(admin_url('pages.php')) ?>" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this page?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete page">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
