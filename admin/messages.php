<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$pageTitle = 'Customer Messages';
$pdo = db();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['new', 'read', 'replied', 'spam']) ? $_POST['status'] : 'read';

        $pdo->prepare("UPDATE contact_messages SET status = ? WHERE id = ?")->execute([$status, $id]);
        flash('success', "Message #{$id} marked as {$status}.");
        admin_redirect('messages.php' . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
    }

    if ($action === 'delete') {
        require_role('admin');
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM contact_messages WHERE id = ?")->execute([$id]);
        log_activity('message.delete', "Deleted contact message #{$id}");
        flash('success', "Message #{$id} deleted.");
        admin_redirect('messages.php');
    }
}

// Filters
$statusFilter = $_GET['status'] ?? 'all';
$search = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($statusFilter !== 'all' && in_array($statusFilter, ['new', 'read', 'replied', 'spam'])) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ? OR subject LIKE ? OR message LIKE ? OR order_number LIKE ?)';
    for ($i = 0; $i < 6; $i++) $params[] = "%$search%";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Counts
$countNew     = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'")->fetchColumn();
$countRead    = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'read'")->fetchColumn();
$countReplied = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'replied'")->fetchColumn();
$countTotal   = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages")->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM contact_messages $whereSql ORDER BY id DESC LIMIT 100");
$stmt->execute($params);
$messages = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-1">Customer Inquiries & Messages</h1>
    <p class="text-muted small mb-0">Messages submitted via the storefront Contact Us form.</p>
  </div>
</div>

<!-- Filters -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div class="btn-group">
    <a href="<?= e(admin_url('messages.php')) ?>" class="btn btn-outline-secondary btn-sm <?= $statusFilter === 'all' ? 'active' : '' ?>">
      All (<?= $countTotal ?>)
    </a>
    <a href="<?= e(admin_url('messages.php?status=new')) ?>" class="btn btn-outline-secondary btn-sm <?= $statusFilter === 'new' ? 'active' : '' ?>">
      New (<?= $countNew ?>)
      <?php if ($countNew > 0): ?>
        <span class="badge bg-danger ms-1"><?= $countNew ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= e(admin_url('messages.php?status=read')) ?>" class="btn btn-outline-secondary btn-sm <?= $statusFilter === 'read' ? 'active' : '' ?>">
      Read (<?= $countRead ?>)
    </a>
    <a href="<?= e(admin_url('messages.php?status=replied')) ?>" class="btn btn-outline-secondary btn-sm <?= $statusFilter === 'replied' ? 'active' : '' ?>">
      Replied (<?= $countReplied ?>)
    </a>
  </div>

  <form method="get" action="" class="d-flex gap-2">
    <?php if ($statusFilter !== 'all'): ?>
      <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
    <?php endif; ?>
    <input type="text" name="q" class="form-control form-control-sm" placeholder="Search sender, email, message..." value="<?= e($search) ?>" style="width: 240px;">
    <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
  </form>
</div>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width: 60px;">#</th>
          <th style="width: 200px;">Sender</th>
          <th>Subject & Message</th>
          <th style="width: 120px;">Order No.</th>
          <th style="width: 100px;">Status</th>
          <th style="width: 130px;">Received</th>
          <th class="text-end" style="min-width: 140px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($messages)): ?>
          <tr>
            <td colspan="7" class="text-center py-5 text-muted">
              <i class="bi bi-inbox fs-2 d-block mb-2"></i>
              No messages found.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($messages as $m): ?>
            <tr class="<?= $m['status'] === 'new' ? 'table-warning bg-opacity-10 fw-medium' : '' ?>">
              <td class="text-muted small">#<?= (int)$m['id'] ?></td>
              <td>
                <div class="fw-semibold text-dark"><?= e($m['name']) ?></div>
                <div class="text-muted small"><a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a></div>
                <?php if (!empty($m['phone'])): ?>
                  <div class="text-muted small"><?= e($m['phone']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <div class="fw-bold small text-dark mb-1"><?= e($m['subject']) ?></div>
                <div class="text-secondary small text-truncate" style="max-width: 380px;">
                  <?= e(mb_substr($m['message'], 0, 140)) ?><?= mb_strlen($m['message']) > 140 ? '...' : '' ?>
                </div>
              </td>
              <td>
                <?php if (!empty($m['order_number'])): ?>
                  <span class="badge bg-light text-dark border"><?= e($m['order_number']) ?></span>
                <?php else: ?>
                  <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($m['status'] === 'new'): ?>
                  <span class="badge text-bg-danger">New</span>
                <?php elseif ($m['status'] === 'read'): ?>
                  <span class="badge text-bg-secondary">Read</span>
                <?php elseif ($m['status'] === 'replied'): ?>
                  <span class="badge text-bg-success">Replied</span>
                <?php else: ?>
                  <span class="badge text-bg-dark">Spam</span>
                <?php endif; ?>
              </td>
              <td class="text-muted small">
                <?= e(date('M d, Y h:i A', strtotime($m['created_at']))) ?>
              </td>
              <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewModal<?= (int)$m['id'] ?>" title="Read full message">
                  <i class="bi bi-eye"></i>
                </button>
                <a href="mailto:<?= e($m['email']) ?>?subject=<?= urlencode('Re: ' . $m['subject']) ?>" class="btn btn-sm btn-outline-success" title="Reply via email">
                  <i class="bi bi-reply"></i>
                </a>
                <?php if (has_role('admin')): ?>
                  <form method="post" action="<?= e(admin_url('messages.php')) ?>" class="d-inline" onsubmit="return confirm('Delete this message permanently?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete message">
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

<!-- Message View Modals -->
<?php foreach ($messages as $m): ?>
  <div class="modal fade" id="viewModal<?= (int)$m['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fs-6 fw-bold">Message #<?= (int)$m['id'] ?> &middot; <?= e($m['subject']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-4 p-3 bg-light rounded small">
            <div class="col-sm-6">
              <strong>From:</strong> <?= e($m['name']) ?> (<a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a>)
            </div>
            <div class="col-sm-6">
              <strong>Phone:</strong> <?= e($m['phone'] ?: 'None') ?>
            </div>
            <div class="col-sm-6">
              <strong>Order No:</strong> <?= e($m['order_number'] ?: 'N/A') ?>
            </div>
            <div class="col-sm-6">
              <strong>Received:</strong> <?= e(date('F d, Y h:i A', strtotime($m['created_at']))) ?> (IP: <?= e($m['ip']) ?>)
            </div>
          </div>

          <div class="p-3 border rounded bg-white" style="line-height: 1.8; white-space: pre-wrap; font-size: 0.95rem;"><?= e($m['message']) ?></div>
        </div>
        <div class="modal-footer d-flex justify-content-between">
          <form method="post" action="<?= e(admin_url('messages.php')) ?>" class="d-flex gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
              <option value="new" <?= $m['status'] === 'new' ? 'selected' : '' ?>>Status: New</option>
              <option value="read" <?= $m['status'] === 'read' ? 'selected' : '' ?>>Status: Read</option>
              <option value="replied" <?= $m['status'] === 'replied' ? 'selected' : '' ?>>Status: Replied</option>
              <option value="spam" <?= $m['status'] === 'spam' ? 'selected' : '' ?>>Status: Spam</option>
            </select>
          </form>
          <div>
            <a href="mailto:<?= e($m['email']) ?>?subject=<?= urlencode('Re: ' . $m['subject']) ?>" class="btn btn-sm btn-primary">
              <i class="bi bi-reply me-1"></i> Reply by Email
            </a>
            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
