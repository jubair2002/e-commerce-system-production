<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$pageTitle = 'Product Reviews';
$pdo = db();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['approved', 'rejected', 'pending']) ? $_POST['status'] : 'pending';

        $st = $pdo->prepare("SELECT product_id FROM product_reviews WHERE id = ?");
        $st->execute([$id]);
        $productId = (int)$st->fetchColumn();

        if ($productId > 0) {
            $pdo->prepare("UPDATE product_reviews SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $id]);
            product_recalculate_rating($productId);
            log_activity('review.status', "Set review #{$id} status to {$status}");
            flash('success', "Review #{$id} status set to {$status}.");
        }
        admin_redirect('reviews.php' . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
    }

    if ($action === 'reply') {
        $id = (int)($_POST['id'] ?? 0);
        $reply = trim((string)($_POST['admin_reply'] ?? ''));

        $pdo->prepare("UPDATE product_reviews SET admin_reply = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$reply, $id]);
        log_activity('review.reply', "Replied to review #{$id}");
        flash('success', "Reply saved for review #{$id}.");
        admin_redirect('reviews.php');
    }

    if ($action === 'delete') {
        require_role('admin');
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT product_id FROM product_reviews WHERE id = ?");
        $st->execute([$id]);
        $productId = (int)$st->fetchColumn();

        if ($productId > 0) {
            $pdo->prepare("DELETE FROM product_reviews WHERE id = ?")->execute([$id]);
            product_recalculate_rating($productId);
            log_activity('review.delete', "Deleted review #{$id}");
            flash('success', "Review #{$id} deleted.");
        }
        admin_redirect('reviews.php');
    }

    if ($action === 'bulk') {
        $bulkOp = $_POST['bulk_op'] ?? '';
        $selectedIds = array_filter(array_map('intval', (array)($_POST['selected_ids'] ?? [])));

        if (!empty($selectedIds)) {
            $in = implode(',', $selectedIds);
            $productIds = $pdo->query("SELECT DISTINCT product_id FROM product_reviews WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);

            if ($bulkOp === 'approve') {
                $pdo->exec("UPDATE product_reviews SET status = 'approved', updated_at = NOW() WHERE id IN ($in)");
                flash('success', count($selectedIds) . " review(s) approved.");
            } elseif ($bulkOp === 'reject') {
                $pdo->exec("UPDATE product_reviews SET status = 'rejected', updated_at = NOW() WHERE id IN ($in)");
                flash('success', count($selectedIds) . " review(s) rejected.");
            } elseif ($bulkOp === 'delete' && has_role('admin')) {
                $pdo->exec("DELETE FROM product_reviews WHERE id IN ($in)");
                flash('success', count($selectedIds) . " review(s) deleted.");
            }

            foreach ($productIds as $pid) {
                product_recalculate_rating((int)$pid);
            }
        }
        admin_redirect('reviews.php');
    }
}

// Filters & Query
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery  = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($statusFilter !== 'all' && in_array($statusFilter, ['pending', 'approved', 'rejected'])) {
    $where[] = 'r.status = ?';
    $params[] = $statusFilter;
}

if ($searchQuery !== '') {
    $where[] = '(r.author_name LIKE ? OR r.title LIKE ? OR r.body LIKE ? OR p.name LIKE ?)';
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Counts
$countPending  = (int)$pdo->query("SELECT COUNT(*) FROM product_reviews WHERE status = 'pending'")->fetchColumn();
$countApproved = (int)$pdo->query("SELECT COUNT(*) FROM product_reviews WHERE status = 'approved'")->fetchColumn();
$countRejected = (int)$pdo->query("SELECT COUNT(*) FROM product_reviews WHERE status = 'rejected'")->fetchColumn();
$countTotal    = $countPending + $countApproved + $countRejected;

$sql = "
    SELECT r.*, p.name as product_name, p.slug as product_slug, p.image as product_image
    FROM product_reviews r
    JOIN products p ON p.id = r.product_id
    $whereSql
    ORDER BY r.id DESC
    LIMIT 100
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-1">Product Reviews</h1>
    <p class="text-muted small mb-0">Moderate customer reviews, publish approved ratings, and reply to buyers.</p>
  </div>
</div>

<!-- Status Filters -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div class="btn-group">
    <a href="<?= e(admin_url('reviews.php')) ?>" class="btn btn-outline-secondary btn-sm <?= $statusFilter === 'all' ? 'active' : '' ?>">
      All (<?= $countTotal ?>)
    </a>
    <a href="<?= e(admin_url('reviews.php?status=pending')) ?>" class="btn btn-outline-secondary btn-sm <?= $statusFilter === 'pending' ? 'active' : '' ?>">
      Pending (<?= $countPending ?>)
      <?php if ($countPending > 0): ?>
        <span class="badge bg-warning text-dark ms-1"><?= $countPending ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= e(admin_url('reviews.php?status=approved')) ?>" class="btn btn-outline-secondary btn-sm <?= $statusFilter === 'approved' ? 'active' : '' ?>">
      Approved (<?= $countApproved ?>)
    </a>
    <a href="<?= e(admin_url('reviews.php?status=rejected')) ?>" class="btn btn-outline-secondary btn-sm <?= $statusFilter === 'rejected' ? 'active' : '' ?>">
      Rejected (<?= $countRejected ?>)
    </a>
  </div>

  <form method="get" action="" class="d-flex gap-2">
    <?php if ($statusFilter !== 'all'): ?>
      <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
    <?php endif; ?>
    <input type="text" name="q" class="form-control form-control-sm" placeholder="Search author, product, content..." value="<?= e($searchQuery) ?>" style="width: 240px;">
    <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
  </form>
</div>

<!-- Bulk Actions Form -->
<form method="post" action="<?= e(admin_url('reviews.php')) ?>" id="bulkForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk">

  <div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-light d-flex align-items-center justify-content-between py-2">
      <div class="d-flex align-items-center gap-2">
        <select name="bulk_op" class="form-select form-select-sm" style="width: 140px;" required>
          <option value="">Bulk action</option>
          <option value="approve">Approve</option>
          <option value="reject">Reject</option>
          <?php if (has_role('admin')): ?>
            <option value="delete">Delete</option>
          <?php endif; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('Execute bulk action on selected reviews?');">Apply</button>
      </div>
      <div class="small text-muted">Showing <?= count($reviews) ?> reviews</div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width: 40px;">
              <input type="checkbox" class="form-check-input" id="selectAll">
            </th>
            <th style="width: 180px;">Product</th>
            <th style="width: 150px;">Author & Rating</th>
            <th>Review Content</th>
            <th style="width: 100px;">Status</th>
            <th style="width: 120px;">Date</th>
            <th class="text-end" style="min-width: 150px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($reviews)): ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">
                <i class="bi bi-star fs-2 d-block mb-2"></i>
                No reviews found matching your filter.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($reviews as $r): ?>
              <tr>
                <td>
                  <input type="checkbox" name="selected_ids[]" value="<?= (int)$r['id'] ?>" class="form-check-input row-select">
                </td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <?php if (!empty($r['product_image'])): ?>
                      <img src="<?= e(product_image_url($r['product_image'])) ?>" alt="" class="rounded" style="width: 40px; height: 40px; object-fit: cover;">
                    <?php endif; ?>
                    <div>
                      <a href="<?= e(route('product', ['slug' => $r['product_slug']])) ?>" target="_blank" class="fw-semibold text-dark text-decoration-none small d-block text-truncate" style="max-width: 140px;">
                        <?= e($r['product_name']) ?>
                      </a>
                      <span class="text-muted" style="font-size: 11px;">#<?= (int)$r['product_id'] ?></span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="fw-semibold text-dark small"><?= e($r['author_name']) ?></div>
                  <div class="text-warning small mb-1">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                      <i class="bi bi-star<?= $i <= (int)$r['rating'] ? '-fill' : '' ?>"></i>
                    <?php endfor; ?>
                  </div>
                  <?php if (!empty($r['order_id'])): ?>
                    <span class="badge text-bg-success" style="font-size: 10px;">Verified Buyer</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="fw-bold small mb-1"><?= e($r['title']) ?></div>
                  <div class="text-secondary small" style="line-height: 1.5;"><?= nl2br(e($r['body'])) ?></div>
                  <?php if (!empty($r['admin_reply'])): ?>
                    <div class="p-2 mt-2 bg-light rounded border-start border-3 border-primary small">
                      <strong class="text-primary d-block" style="font-size: 11px;">Store Response:</strong>
                      <?= nl2br(e($r['admin_reply'])) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['status'] === 'approved'): ?>
                    <span class="badge text-bg-success">Approved</span>
                  <?php elseif ($r['status'] === 'rejected'): ?>
                    <span class="badge text-bg-danger">Rejected</span>
                  <?php else: ?>
                    <span class="badge text-bg-warning">Pending</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted small">
                  <?= e(date('M d, Y', strtotime($r['created_at']))) ?>
                </td>
                <td class="text-end">
                  <!-- Status Actions -->
                  <div class="btn-group btn-group-sm">
                    <?php if ($r['status'] !== 'approved'): ?>
                      <button type="submit" form="formApprove<?= (int)$r['id'] ?>" class="btn btn-outline-success" title="Approve review">
                        <i class="bi bi-check-lg"></i>
                      </button>
                    <?php endif; ?>
                    <?php if ($r['status'] !== 'rejected'): ?>
                      <button type="submit" form="formReject<?= (int)$r['id'] ?>" class="btn btn-outline-warning" title="Reject review">
                        <i class="bi bi-x-lg"></i>
                      </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#replyModal<?= (int)$r['id'] ?>" title="Reply to review">
                      <i class="bi bi-reply"></i>
                    </button>
                    <?php if (has_role('admin')): ?>
                      <button type="submit" form="formDelete<?= (int)$r['id'] ?>" class="btn btn-outline-danger" title="Delete review" onclick="return confirm('Permanently delete this review?');">
                        <i class="bi bi-trash"></i>
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<!-- Standalone Action Forms -->
<?php foreach ($reviews as $r): ?>
  <form method="post" action="<?= e(admin_url('reviews.php')) ?>" id="formApprove<?= (int)$r['id'] ?>" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="set_status">
    <input type="hidden" name="status" value="approved">
    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
  </form>

  <form method="post" action="<?= e(admin_url('reviews.php')) ?>" id="formReject<?= (int)$r['id'] ?>" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="set_status">
    <input type="hidden" name="status" value="rejected">
    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
  </form>

  <?php if (has_role('admin')): ?>
    <form method="post" action="<?= e(admin_url('reviews.php')) ?>" id="formDelete<?= (int)$r['id'] ?>" class="d-none">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    </form>
  <?php endif; ?>

  <!-- Reply Modal -->
  <div class="modal fade" id="replyModal<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" action="<?= e(admin_url('reviews.php')) ?>" class="modal-content">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title fs-6 fw-bold">Official Store Reply to <?= e($r['author_name']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="p-3 bg-light rounded mb-3 small">
            <strong>"<?= e($r['title']) ?>"</strong>
            <p class="mb-0 text-muted"><?= nl2br(e($r['body'])) ?></p>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Public Store Response</label>
            <textarea name="admin_reply" class="form-control" rows="4" placeholder="Thank you for your feedback..."><?= e($r['admin_reply'] ?? '') ?></textarea>
            <div class="form-text">This reply will appear below the review on the storefront product page.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-primary">Save Response</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<script>
document.getElementById('selectAll')?.addEventListener('change', function() {
  document.querySelectorAll('.row-select').forEach(cb => cb.checked = this.checked);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
