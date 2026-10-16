<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');

$pageTitle = 'Mail Queue & Logs';

// Handle POST Actions: Retry, Delete, Retry All, Purge
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'retry') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare(
                "UPDATE email_queue
                 SET status = 'pending', scheduled_at = NOW(), last_error = NULL
                 WHERE id = :id"
            )->execute([':id' => $id]);
            log_activity('mail.retry', "Manually rescheduled queue item #{$id}");
            flash('success', "Message #{$id} reset to pending.");
        }
        admin_redirect('mail_queue.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare("DELETE FROM email_queue WHERE id = :id")->execute([':id' => $id]);
            log_activity('mail.delete', "Deleted queue item #{$id}");
            flash('success', "Queue item #{$id} deleted.");
        }
        admin_redirect('mail_queue.php');
    }

    if ($action === 'retry_all_failed') {
        $stmt = db()->prepare(
            "UPDATE email_queue
             SET status = 'pending', scheduled_at = NOW(), attempts = 0, last_error = NULL
             WHERE status = 'failed'"
        );
        $stmt->execute();
        $cnt = $stmt->rowCount();
        log_activity('mail.retry_all', "Reset {$cnt} failed emails to pending");
        flash('success', "Reset {$cnt} failed message(s) to pending for re-delivery.");
        admin_redirect('mail_queue.php');
    }

    if ($action === 'purge_sent') {
        $stmt = db()->prepare("DELETE FROM email_queue WHERE status = 'sent'");
        $stmt->execute();
        $cnt = $stmt->rowCount();
        log_activity('mail.purge', "Purged {$cnt} sent emails from queue");
        flash('info', "Purged {$cnt} sent email logs.");
        admin_redirect('mail_queue.php');
    }
}

// Filters & Query
$statusFilter = trim((string)($_GET['status'] ?? ''));
$search       = trim((string)($_GET['q'] ?? ''));
$allowedStatuses = ['pending', 'sending', 'sent', 'failed'];

$where = [];
$params = [];

if (in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = 'status = :st';
    $params[':st'] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(to_email LIKE :q1 OR subject LIKE :q2 OR to_name LIKE :q3)';
    $params[':q1'] = "%$search%";
    $params[':q2'] = "%$search%";
    $params[':q3'] = "%$search%";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare("SELECT COUNT(*) FROM email_queue $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$stmt = db()->prepare(
    "SELECT * FROM email_queue
     $whereSql
     ORDER BY id DESC
     LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$queueRows = $stmt->fetchAll();

$statusBadge = [
    'pending' => 'bg-warning text-dark',
    'sending' => 'bg-info text-white',
    'sent'    => 'bg-success text-white',
    'failed'  => 'bg-danger text-white',
];

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="mb-1">Mail Queue & Audit Logs</h3>
    <p class="text-muted small mb-0">Monitor background transactional email delivery, inspect failures, and retry stalled messages.</p>
  </div>
  <div class="d-flex gap-2">
    <form method="post" class="d-inline" onsubmit="return confirm('Reschedule all failed emails for re-sending?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="retry_all_failed">
      <button type="submit" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-arrow-repeat me-1"></i> Retry All Failed
      </button>
    </form>
    <form method="post" class="d-inline" onsubmit="return confirm('Purge all sent email records from the database?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="purge_sent">
      <button type="submit" class="btn btn-outline-danger btn-sm">
        <i class="bi bi-trash me-1"></i> Purge Sent
      </button>
    </form>
  </div>
</div>

<!-- Filters Toolbar -->
<div class="card shadow-sm border-0 mb-4">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-center">
      <div class="col-md-5">
        <input type="text" name="q" class="form-control" placeholder="Search recipient, name or subject..." value="<?= e($search) ?>">
      </div>
      <div class="col-md-3">
        <select name="status" class="form-select" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
          <option value="sending" <?= $statusFilter === 'sending' ? 'selected' : '' ?>>Sending</option>
          <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent</option>
          <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Search</button>
      </div>
      <?php if ($search || $statusFilter): ?>
        <div class="col-md-2">
          <a href="<?= e(admin_url('mail_queue.php')) ?>" class="btn btn-outline-secondary w-100">Clear</a>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- Queue Table -->
<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>ID</th>
          <th>Recipient</th>
          <th>Subject</th>
          <th>Template</th>
          <th class="text-center">Attempts</th>
          <th>Status</th>
          <th>Date</th>
          <th class="text-end" style="width: 130px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$queueRows): ?>
          <tr>
            <td colspan="8" class="text-center py-5 text-muted">No mail queue records found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($queueRows as $row): ?>
            <tr>
              <td><code>#<?= (int)$row['id'] ?></code></td>
              <td>
                <div class="fw-semibold"><?= e($row['to_email']) ?></div>
                <?php if (!empty($row['to_name'])): ?>
                  <div class="small text-muted"><?= e($row['to_name']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <div class="fw-medium text-truncate" style="max-width: 260px;">
                  <?= e($row['subject']) ?>
                </div>
                <?php if (!empty($row['last_error'])): ?>
                  <div class="small text-danger text-truncate" style="max-width: 260px;" title="<?= e($row['last_error']) ?>">
                    <i class="bi bi-exclamation-triangle"></i> <?= e($row['last_error']) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge bg-light text-dark border">
                  <?= e($row['template_slug'] ?: 'direct') ?>
                </span>
              </td>
              <td class="text-center"><?= (int)$row['attempts'] ?></td>
              <td>
                <span class="badge <?= e($statusBadge[$row['status']] ?? 'bg-secondary') ?> text-uppercase">
                  <?= e($row['status']) ?>
                </span>
              </td>
              <td class="small text-muted">
                <?= date('M j, g:i a', strtotime($row['created_at'])) ?>
              </td>
              <td class="text-end">
                <div class="btn-group btn-group-sm">
                  <button type="button" class="btn btn-outline-secondary"
                          data-bs-toggle="modal"
                          data-bs-target="#viewBodyModal"
                          data-subject="<?= e($row['subject']) ?>"
                          data-body="<?= e($row['body_html']) ?>"
                          title="View Email Body">
                    <i class="bi bi-eye"></i>
                  </button>

                  <?php if ($row['status'] === 'failed' || $row['status'] === 'pending'): ?>
                    <form method="post" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="retry">
                      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                      <button type="submit" class="btn btn-outline-primary" title="Reschedule / Retry">
                        <i class="bi bi-arrow-repeat"></i>
                      </button>
                    </form>
                  <?php endif; ?>

                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this queue record?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
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

<?php if ($pages > 1): ?>
  <nav class="mt-4">
    <ul class="pagination justify-content-center">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <li class="page-item <?= $page === $i ? 'active' : '' ?>">
          <a class="page-link" href="<?= e(admin_url('mail_queue.php?' . http_build_query(array_merge($_GET, ['page' => $i])))) ?>">
            <?= $i ?>
          </a>
        </li>
      <?php endfor; ?>
    </ul>
  </nav>
<?php endif; ?>

<!-- View Rendered Body Modal -->
<div class="modal fade" id="viewBodyModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewModalSubject">Rendered Email</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="viewModalIframe" style="width: 100%; height: 500px; border: none; background: #fff;"></iframe>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const modal = document.getElementById('viewBodyModal');
  if (modal) {
    modal.addEventListener('show.bs.modal', function(event) {
      const btn = event.relatedTarget;
      document.getElementById('viewModalSubject').textContent = btn.getAttribute('data-subject');
      const doc = document.getElementById('viewModalIframe').contentWindow.document;
      doc.open();
      doc.write(btn.getAttribute('data-body'));
      doc.close();
    });
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
