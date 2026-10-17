<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$pageTitle = 'Newsletter Subscribers';
$pdo = db();

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_role('admin');
    $statusFilter = $_GET['status'] ?? 'all';
    $where = [];
    $params = [];
    if ($statusFilter !== 'all' && in_array($statusFilter, ['active', 'pending', 'unsubscribed'])) {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("SELECT id, email, status, source, ip, created_at, confirmed_at FROM newsletter_subscribers $whereSql ORDER BY id DESC");
    $stmt->execute($params);
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="newsletter_subscribers_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Email', 'Status', 'Source', 'IP Address', 'Subscribed At', 'Confirmed At']);
    foreach ($subs as $s) {
        fputcsv($output, $s);
    }
    fclose($output);
    exit;
}

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['active', 'pending', 'unsubscribed']) ? $_POST['status'] : 'active';
        $pdo->prepare("UPDATE newsletter_subscribers SET status = ? WHERE id = ?")->execute([$status, $id]);
        flash('success', "Subscriber #{$id} status set to {$status}.");
        admin_redirect('subscribers.php');
    }

    if ($action === 'delete') {
        require_role('admin');
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM newsletter_subscribers WHERE id = ?")->execute([$id]);
        log_activity('subscriber.delete', "Deleted subscriber #{$id}");
        flash('success', "Subscriber #{$id} deleted.");
        admin_redirect('subscribers.php');
    }
}

// Filters
$statusFilter = $_GET['status'] ?? 'all';
$search = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($statusFilter !== 'all' && in_array($statusFilter, ['active', 'pending', 'unsubscribed'])) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(email LIKE ? OR source LIKE ? OR ip LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Counts
$countActive   = (int)$pdo->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'active'")->fetchColumn();
$countPending  = (int)$pdo->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'pending'")->fetchColumn();
$countUnsub    = (int)$pdo->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'unsubscribed'")->fetchColumn();
$countTotal    = (int)$pdo->query("SELECT COUNT(*) FROM newsletter_subscribers")->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM newsletter_subscribers $whereSql ORDER BY id DESC LIMIT 100");
$stmt->execute($params);
$subscribers = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-1">Newsletter Subscribers</h1>
    <p class="text-muted small mb-0">Manage customer newsletter subscriptions and export CSV mailing lists.</p>
  </div>
  <?php if (has_role('admin')): ?>
    <a href="<?= e(admin_url('subscribers.php?export=csv' . ($statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : ''))) ?>" class="btn btn-outline-success d-inline-flex align-items-center gap-1">
      <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
    </a>
  <?php endif; ?>
</div>

<!-- Filters -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div class="btn-group">
    <a href="<?= e(admin_url('subscribers.php')) ?>" class="btn btn-outline-secondary btn-sm <?= $statusFilter === 'all' ? 'active' : '' ?>">
      All (<?= $countTotal ?>)
    </a>
    <a href="<?= e(admin_url('subscribers.php?status=active')) ?>" class="btn btn-outline-secondary btn-sm <?= $statusFilter === 'active' ? 'active' : '' ?>">
      Active (<?= $countActive ?>)
    </a>
    <a href="<?= e(admin_url('subscribers.php?status=pending')) ?>" class="btn btn-outline-secondary btn-sm <?= $statusFilter === 'pending' ? 'active' : '' ?>">
      Pending (<?= $countPending ?>)
    </a>
    <a href="<?= e(admin_url('subscribers.php?status=unsubscribed')) ?>" class="btn btn-outline-secondary btn-sm <?= $statusFilter === 'unsubscribed' ? 'active' : '' ?>">
      Unsubscribed (<?= $countUnsub ?>)
    </a>
  </div>

  <form method="get" action="" class="d-flex gap-2">
    <?php if ($statusFilter !== 'all'): ?>
      <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
    <?php endif; ?>
    <input type="text" name="q" class="form-control form-control-sm" placeholder="Search email..." value="<?= e($search) ?>" style="width: 240px;">
    <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
  </form>
</div>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width: 60px;">#</th>
          <th>Email Address</th>
          <th>Source</th>
          <th>Status</th>
          <th>Subscribed</th>
          <th>Confirmed</th>
          <th class="text-end" style="min-width: 140px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($subscribers)): ?>
          <tr>
            <td colspan="7" class="text-center py-5 text-muted">
              <i class="bi bi-envelope-check fs-2 d-block mb-2"></i>
              No subscribers found.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($subscribers as $s): ?>
            <tr>
              <td class="text-muted small">#<?= (int)$s['id'] ?></td>
              <td>
                <div class="fw-semibold text-dark"><?= e($s['email']) ?></div>
                <div class="text-muted" style="font-size: 11px;">IP: <?= e($s['ip']) ?></div>
              </td>
              <td>
                <span class="badge bg-light text-dark border"><?= e($s['source'] ?: 'web') ?></span>
              </td>
              <td>
                <?php if ($s['status'] === 'active'): ?>
                  <span class="badge text-bg-success">Active</span>
                <?php elseif ($s['status'] === 'pending'): ?>
                  <span class="badge text-bg-warning">Pending</span>
                <?php else: ?>
                  <span class="badge text-bg-secondary">Unsubscribed</span>
                <?php endif; ?>
              </td>
              <td class="text-muted small">
                <?= e(date('M d, Y', strtotime($s['created_at']))) ?>
              </td>
              <td class="text-muted small">
                <?= !empty($s['confirmed_at']) ? e(date('M d, Y', strtotime($s['confirmed_at']))) : '—' ?>
              </td>
              <td class="text-end">
                <form method="post" action="<?= e(admin_url('subscribers.php')) ?>" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <?php if ($s['status'] === 'active'): ?>
                    <input type="hidden" name="status" value="unsubscribed">
                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Unsubscribe this user">Unsubscribe</button>
                  <?php else: ?>
                    <input type="hidden" name="status" value="active">
                    <button type="submit" class="btn btn-sm btn-outline-success" title="Activate subscription">Activate</button>
                  <?php endif; ?>
                </form>

                <?php if (has_role('admin')): ?>
                  <form method="post" action="<?= e(admin_url('subscribers.php')) ?>" class="d-inline" onsubmit="return confirm('Delete subscriber permanently?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
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
