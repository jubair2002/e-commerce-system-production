<?php
/**
 * Admin: Customer Management Listing & KPIs (Prompt 14).
 * Single optimized query with order aggregation, filters, pagination, bulk actions, and CSV export.
 */
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$pageTitle = 'Customers';
$pdo = db();

// Handle CSV Export (Admin only)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!has_role('admin')) {
        http_response_code(403);
        flash('danger', 'Access denied. Only administrators can export customer data.');
        admin_redirect('customers.php');
    }

    $exportMarketingOnly = !empty($_GET['marketing_only']);

    // Build query for export
    $where = ["c.deleted_at IS NULL"];
    $params = [];

    if ($exportMarketingOnly) {
        $where[] = "c.marketing_opt_in = 1";
    }

    $status = trim((string)($_GET['status'] ?? ''));
    if ($status !== '' && in_array($status, ['active', 'pending', 'blocked'], true)) {
        $where[] = "c.status = :status";
        $params[':status'] = $status;
    }

    $sql = "SELECT c.id, c.name, c.email, c.phone, c.status, c.email_verified_at,
                   c.marketing_opt_in, c.created_at,
                   COALESCE(o.order_count, 0) AS order_count,
                   COALESCE(o.lifetime_spend, 0.0) AS lifetime_spend
            FROM customers c
            LEFT JOIN (
                SELECT customer_id,
                       COUNT(*) AS order_count,
                       SUM(CASE WHEN status != 'cancelled' THEN total ELSE 0 END) AS lifetime_spend
                FROM orders
                WHERE customer_id IS NOT NULL
                GROUP BY customer_id
            ) o ON o.customer_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $filename = 'customers-export-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    // Output UTF-8 BOM for Microsoft Excel compatibility
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, ['ID', 'Name', 'Email', 'Phone', 'Status', 'Verified', 'Marketing Opt-In', 'Orders Count', 'Lifetime Spend (BDT)', 'Joined Date']);

    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['id'],
            $row['name'],
            $row['email'],
            $row['phone'] ?? '',
            ucfirst($row['status']),
            !empty($row['email_verified_at']) ? 'Yes' : 'No',
            (int)$row['marketing_opt_in'] === 1 ? 'Yes' : 'No',
            (int)$row['order_count'],
            number_format((float)$row['lifetime_spend'], 2, '.', ''),
            $row['created_at'],
        ]);
    }

    fclose($out);
    log_activity('customer.export_csv', "Exported customers CSV (marketing_only=" . ($exportMarketingOnly ? '1' : '0') . ")");
    exit;
}

// Handle POST Actions (Single Delete & Bulk Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? ($_POST['bulk_action'] ?? ''));

    // Role check for destructive / mutation actions
    if (!has_role('admin')) {
        flash('danger', 'Access denied. Only administrators can perform this action.');
        admin_redirect('customers.php');
    }

    // 1. Single Customer Deletion
    if ($action === 'delete_single') {
        $cid = (int)($_POST['id'] ?? 0);
        if ($cid <= 0) {
            flash('warning', 'Invalid customer ID.');
            admin_redirect('customers.php');
        }

        $stmtC = $pdo->prepare("SELECT id, name, email, avatar FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $stmtC->execute([':id' => $cid]);
        $targetCustomer = $stmtC->fetch();

        if (!$targetCustomer) {
            flash('warning', 'Customer not found or already deleted.');
            admin_redirect('customers.php');
        }

        $pdo->beginTransaction();
        try {
            // Remove avatar
            if (!empty($targetCustomer['avatar'])) {
                $oldPath = UPLOADS_PATH . '/avatars/' . $targetCustomer['avatar'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            // 1. Retain order history for bookkeeping, unlink customer_id
            $pdo->prepare("UPDATE orders SET customer_id = NULL WHERE customer_id = :cid")
                ->execute([':cid' => $cid]);

            // 2. Delete addresses, wishlists, and tokens
            $pdo->prepare("DELETE FROM customer_addresses WHERE customer_id = :cid")->execute([':cid' => $cid]);
            $pdo->prepare("DELETE FROM wishlists WHERE customer_id = :cid")->execute([':cid' => $cid]);
            $pdo->prepare("DELETE FROM customer_tokens WHERE customer_id = :cid")->execute([':cid' => $cid]);

            // 3. Anonymize customer row
            $anonEmail = 'deleted+' . $cid . '@invalid';
            $stmtAnon = $pdo->prepare(
                "UPDATE customers
                 SET name = 'Deleted customer',
                     email = :anon_email,
                     phone = NULL,
                     avatar = NULL,
                     admin_note = CONCAT(COALESCE(admin_note, ''), ' [Deleted by admin on ', NOW(), ']'),
                     status = 'blocked',
                     deleted_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :cid"
            );
            $stmtAnon->execute([
                ':anon_email' => $anonEmail,
                ':cid'        => $cid,
            ]);

            $pdo->commit();
            log_activity('customer.delete', "Admin deleted/anonymized customer #{$cid} (was {$targetCustomer['email']})");
            flash('success', "Customer #{$cid} ('{$targetCustomer['name']}') was permanently deleted and anonymized.");
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('danger', 'Error deleting customer: ' . $e->getMessage());
        }

        admin_redirect('customers.php');
    }

    // 2. Bulk Actions
    if (isset($_POST['bulk_action'])) {
        $selectedIds = array_filter(array_map('intval', (array)($_POST['selected_ids'] ?? [])));

        if (empty($selectedIds)) {
            flash('warning', 'Please select at least one customer.');
            admin_redirect('customers.php');
        }

        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));

        if ($action === 'delete') {
            $pdo->beginTransaction();
            try {
                // Delete avatars
                $stmtAvatars = $pdo->prepare("SELECT id, avatar FROM customers WHERE id IN ($inPlaceholders)");
                $stmtAvatars->execute($selectedIds);
                $avRows = $stmtAvatars->fetchAll();
                foreach ($avRows as $av) {
                    if (!empty($av['avatar'])) {
                        $p = UPLOADS_PATH . '/avatars/' . $av['avatar'];
                        if (file_exists($p)) @unlink($p);
                    }
                }

                // Unlink orders
                $pdo->prepare("UPDATE orders SET customer_id = NULL WHERE customer_id IN ($inPlaceholders)")
                    ->execute($selectedIds);

                // Delete addresses, wishlists, tokens
                $pdo->prepare("DELETE FROM customer_addresses WHERE customer_id IN ($inPlaceholders)")->execute($selectedIds);
                $pdo->prepare("DELETE FROM wishlists WHERE customer_id IN ($inPlaceholders)")->execute($selectedIds);
                $pdo->prepare("DELETE FROM customer_tokens WHERE customer_id IN ($inPlaceholders)")->execute($selectedIds);

                // Anonymize each customer row
                $stmtAnonBulk = $pdo->prepare(
                    "UPDATE customers
                     SET name = 'Deleted customer',
                         email = CONCAT('deleted+', id, '@invalid'),
                         phone = NULL,
                         avatar = NULL,
                         admin_note = CONCAT(COALESCE(admin_note, ''), ' [Bulk deleted by admin on ', NOW(), ']'),
                         status = 'blocked',
                         deleted_at = NOW(),
                         updated_at = NOW()
                     WHERE id = ?"
                );
                foreach ($selectedIds as $sid) {
                    $stmtAnonBulk->execute([$sid]);
                }

                $pdo->commit();
                log_activity('customer.bulk_delete', "Bulk deleted/anonymized customers: " . implode(',', $selectedIds));
                flash('success', count($selectedIds) . ' customer(s) have been permanently deleted and anonymized.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('danger', 'Error bulk deleting customers: ' . $e->getMessage());
            }
        } elseif ($action === 'block') {
            $stmt = $pdo->prepare("UPDATE customers SET status = 'blocked' WHERE id IN ($inPlaceholders)");
            $stmt->execute($selectedIds);

            // Delete all remember-me tokens for blocked customers
            $pdo->prepare("DELETE FROM customer_tokens WHERE customer_id IN ($inPlaceholders) AND type = 'remember_me'")
                ->execute($selectedIds);

            log_activity('customer.bulk_block', "Blocked customers: " . implode(',', $selectedIds));
            flash('success', count($selectedIds) . ' customer(s) have been blocked.');
        } elseif ($action === 'unblock') {
            $stmt = $pdo->prepare("UPDATE customers SET status = 'active' WHERE id IN ($inPlaceholders)");
            $stmt->execute($selectedIds);

            log_activity('customer.bulk_unblock', "Unblocked customers: " . implode(',', $selectedIds));
            flash('success', count($selectedIds) . ' customer(s) have been activated.');
        } elseif ($action === 'resend_verification') {
            require_once __DIR__ . '/../includes/customer_auth.php';
            $sentCount = 0;
            foreach ($selectedIds as $cid) {
                $stmtC = $pdo->prepare("SELECT id, name, email, email_verified_at FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1");
                $stmtC->execute([':id' => $cid]);
                $c = $stmtC->fetch();
                if ($c && empty($c['email_verified_at'])) {
                    customer_send_verification_email($c);
                    $sentCount++;
                }
            }
            log_activity('customer.bulk_resend_verification', "Resent verification to {$sentCount} customers");
            flash('info', "Queued verification emails to {$sentCount} unverified customer(s).");
        }

        admin_redirect('customers.php');
    }
}

// -------------------------------------------------------------
// KPI Calculations
// -------------------------------------------------------------
$kpis = [
    'total'         => 0,
    'new_month'     => 0,
    'prev_month'    => 0,
    'verified_pct'  => 0,
    'repeat_rate'   => 0,
    'avg_ltv'       => 0.0,
];

try {
    $kpis['total'] = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL")->fetchColumn();

    $kpis['new_month'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM customers
         WHERE deleted_at IS NULL AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')"
    )->fetchColumn();

    $kpis['prev_month'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM customers
         WHERE deleted_at IS NULL
           AND created_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m-01 00:00:00')
           AND created_at < DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')"
    )->fetchColumn();

    $verifiedCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL AND email_verified_at IS NOT NULL"
    )->fetchColumn();

    $kpis['verified_pct'] = $kpis['total'] > 0 ? round(($verifiedCount / $kpis['total']) * 100) : 0;

    // Repeat customer rate (customers with >= 2 non-cancelled orders / total customers with >= 1 order)
    $orderStats = $pdo->query(
        "SELECT COUNT(DISTINCT customer_id) AS buyers,
                SUM(CASE WHEN order_cnt >= 2 THEN 1 ELSE 0 END) AS repeat_buyers,
                SUM(lifetime_spend) AS total_spend
         FROM (
             SELECT customer_id,
                    COUNT(*) AS order_cnt,
                    SUM(CASE WHEN status != 'cancelled' THEN total ELSE 0 END) AS lifetime_spend
             FROM orders
             WHERE customer_id IS NOT NULL
             GROUP BY customer_id
         ) t"
    )->fetch();

    $buyers = (int)($orderStats['buyers'] ?? 0);
    $repeatBuyers = (int)($orderStats['repeat_buyers'] ?? 0);
    $totalSpend = (float)($orderStats['total_spend'] ?? 0);

    $kpis['repeat_rate'] = $buyers > 0 ? round(($repeatBuyers / $buyers) * 100, 1) : 0;
    $kpis['avg_ltv'] = $kpis['total'] > 0 ? ($totalSpend / $kpis['total']) : 0.0;
} catch (Throwable $e) {}

// Calculate month-over-month delta for new customers
$deltaMonth = $kpis['new_month'] - $kpis['prev_month'];

// -------------------------------------------------------------
// Filters & Search
// -------------------------------------------------------------
$statusFilter   = trim((string)($_GET['status'] ?? ''));
$verifiedFilter = trim((string)($_GET['verified'] ?? ''));
$ordersFilter   = trim((string)($_GET['orders'] ?? ''));
$joinedFrom     = trim((string)($_GET['joined_from'] ?? ''));
$joinedTo       = trim((string)($_GET['joined_to'] ?? ''));
$searchQuery    = trim((string)($_GET['q'] ?? ''));
$sort           = trim((string)($_GET['sort'] ?? 'joined_desc'));

$where = ["c.deleted_at IS NULL"];
$params = [];

if ($statusFilter !== '' && in_array($statusFilter, ['active', 'pending', 'blocked'], true)) {
    $where[] = "c.status = :status";
    $params[':status'] = $statusFilter;
}

if ($verifiedFilter === '1') {
    $where[] = "c.email_verified_at IS NOT NULL";
} elseif ($verifiedFilter === '0') {
    $where[] = "c.email_verified_at IS NULL";
}

if ($ordersFilter === 'has_orders') {
    $where[] = "COALESCE(o.order_count, 0) > 0";
} elseif ($ordersFilter === 'no_orders') {
    $where[] = "COALESCE(o.order_count, 0) = 0";
}

if ($joinedFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $joinedFrom)) {
    $where[] = "c.created_at >= :j_from";
    $params[':j_from'] = $joinedFrom . ' 00:00:00';
}

if ($joinedTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $joinedTo)) {
    $where[] = "c.created_at <= :j_to";
    $params[':j_to'] = $joinedTo . ' 23:59:59';
}

if ($searchQuery !== '') {
    $where[] = "(c.name LIKE :q OR c.email LIKE :q OR c.phone LIKE :q)";
    $params[':q'] = '%' . $searchQuery . '%';
}

// Sorting logic
$sortClauses = [
    'joined_desc' => 'c.created_at DESC',
    'joined_asc'  => 'c.created_at ASC',
    'spend_desc'  => 'lifetime_spend DESC, c.id DESC',
    'spend_asc'   => 'lifetime_spend ASC, c.id DESC',
    'orders_desc' => 'order_count DESC, c.id DESC',
    'orders_asc'  => 'order_count ASC, c.id DESC',
    'name_asc'    => 'c.name ASC',
];
$orderBy = $sortClauses[$sort] ?? 'c.created_at DESC';

// Count total rows for pagination
$countSql = "SELECT COUNT(*)
             FROM customers c
             LEFT JOIN (
                 SELECT customer_id, COUNT(*) AS order_count
                 FROM orders
                 WHERE customer_id IS NOT NULL
                 GROUP BY customer_id
             ) o ON o.customer_id = c.id
             WHERE " . implode(' AND ', $where);

$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();

// Pagination 25 items per page
$perPage = 25;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;

// Fetch page items with single optimized query
$itemsSql = "SELECT c.id, c.name, c.email, c.phone, c.status, c.email_verified_at, c.avatar,
                    c.marketing_opt_in, c.last_login_at, c.created_at,
                    COALESCE(o.order_count, 0) AS order_count,
                    COALESCE(o.lifetime_spend, 0.0) AS lifetime_spend,
                    o.last_order_at
             FROM customers c
             LEFT JOIN (
                 SELECT customer_id,
                        COUNT(*) AS order_count,
                        SUM(CASE WHEN status != 'cancelled' THEN total ELSE 0 END) AS lifetime_spend,
                        MAX(created_at) AS last_order_at
                 FROM orders
                 WHERE customer_id IS NOT NULL
                 GROUP BY customer_id
             ) o ON o.customer_id = c.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY {$orderBy}
             LIMIT {$perPage} OFFSET {$offset}";

$stmtItems = $pdo->prepare($itemsSql);
$stmtItems->execute($params);
$customers = $stmtItems->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h3 class="mb-1">Customer Management</h3>
    <p class="text-muted small mb-0">Browse registered customers, track lifetime spend, and manage account statuses.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if (has_role('admin')): ?>
      <div class="dropdown">
        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-download me-1"></i> Export CSV
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
          <li>
            <a class="dropdown-item small" href="<?= e(admin_url('customers.php?' . http_build_query(array_merge($_GET, ['export' => 'csv'])))) ?>">
              <i class="bi bi-file-earmark-spreadsheet me-2 text-primary"></i> All Matching Customers
            </a>
          </li>
          <li>
            <a class="dropdown-item small" href="<?= e(admin_url('customers.php?' . http_build_query(array_merge($_GET, ['export' => 'csv', 'marketing_only' => '1'])))) ?>">
              <i class="bi bi-megaphone me-2 text-success"></i> Newsletter Subscribers Only
            </a>
          </li>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- KPI Summary Tiles -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-2">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body p-3">
        <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Total Customers</div>
        <div class="fs-4 fw-bold mt-1 text-dark"><?= number_format($kpis['total']) ?></div>
        <div class="small text-muted mt-1" style="font-size: 0.75rem;">Lifetime registered</div>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-xl-3">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body p-3">
        <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">New This Month</div>
        <div class="fs-4 fw-bold mt-1 text-dark d-flex align-items-center gap-2">
          <span><?= number_format($kpis['new_month']) ?></span>
          <?php if ($deltaMonth > 0): ?>
            <span class="badge bg-success-subtle text-success small" style="font-size: 0.72rem;">
              <i class="bi bi-arrow-up-right"></i> +<?= $deltaMonth ?> vs last mo.
            </span>
          <?php elseif ($deltaMonth < 0): ?>
            <span class="badge bg-danger-subtle text-danger small" style="font-size: 0.72rem;">
              <i class="bi bi-arrow-down-right"></i> <?= $deltaMonth ?> vs last mo.
            </span>
          <?php else: ?>
            <span class="badge bg-secondary-subtle text-muted small" style="font-size: 0.72rem;">Same as last mo.</span>
          <?php endif; ?>
        </div>
        <div class="small text-muted mt-1" style="font-size: 0.75rem;">Since 1st of this month</div>
      </div>
    </div>
  </div>

  <div class="col-sm-4 col-xl-2">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body p-3">
        <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Verified Rate</div>
        <div class="fs-4 fw-bold mt-1 text-primary"><?= $kpis['verified_pct'] ?>%</div>
        <div class="small text-muted mt-1" style="font-size: 0.75rem;">Email confirmed</div>
      </div>
    </div>
  </div>

  <div class="col-sm-4 col-xl-2">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body p-3">
        <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Repeat Rate</div>
        <div class="fs-4 fw-bold mt-1 text-success"><?= $kpis['repeat_rate'] ?>%</div>
        <div class="small text-muted mt-1" style="font-size: 0.75rem;">≥ 2 purchases</div>
      </div>
    </div>
  </div>

  <div class="col-sm-4 col-xl-3">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body p-3">
        <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Avg. Customer LTV</div>
        <div class="fs-4 fw-bold mt-1 text-dark"><?= e(price($kpis['avg_ltv'])) ?></div>
        <div class="small text-muted mt-1" style="font-size: 0.75rem;">Revenue per account</div>
      </div>
    </div>
  </div>
</div>

<!-- Filters & Search Toolbar -->
<div class="card shadow-sm border-0 mb-4">
  <div class="card-body p-3">
    <form method="get" action="<?= e(admin_url('customers.php')) ?>" class="row g-2 align-items-center">
      <!-- Search -->
      <div class="col-md-3">
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Search name, email, phone..." value="<?= e($searchQuery) ?>">
        </div>
      </div>

      <!-- Status Filter -->
      <div class="col-6 col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending (Unverified)</option>
          <option value="blocked" <?= $statusFilter === 'blocked' ? 'selected' : '' ?>>Blocked</option>
        </select>
      </div>

      <!-- Verified Filter -->
      <div class="col-6 col-md-2">
        <select name="verified" class="form-select form-select-sm">
          <option value="">All Verification</option>
          <option value="1" <?= $verifiedFilter === '1' ? 'selected' : '' ?>>Verified Only</option>
          <option value="0" <?= $verifiedFilter === '0' ? 'selected' : '' ?>>Unverified Only</option>
        </select>
      </div>

      <!-- Orders Filter -->
      <div class="col-6 col-md-2">
        <select name="orders" class="form-select form-select-sm">
          <option value="">Order History</option>
          <option value="has_orders" <?= $ordersFilter === 'has_orders' ? 'selected' : '' ?>>Has Placed Orders</option>
          <option value="no_orders" <?= $ordersFilter === 'no_orders' ? 'selected' : '' ?>>Never Ordered</option>
        </select>
      </div>

      <!-- Sort By -->
      <div class="col-6 col-md-2">
        <select name="sort" class="form-select form-select-sm">
          <option value="joined_desc" <?= $sort === 'joined_desc' ? 'selected' : '' ?>>Joined (Newest)</option>
          <option value="joined_asc" <?= $sort === 'joined_asc' ? 'selected' : '' ?>>Joined (Oldest)</option>
          <option value="spend_desc" <?= $sort === 'spend_desc' ? 'selected' : '' ?>>Highest Spend</option>
          <option value="spend_asc" <?= $sort === 'spend_asc' ? 'selected' : '' ?>>Lowest Spend</option>
          <option value="orders_desc" <?= $sort === 'orders_desc' ? 'selected' : '' ?>>Most Orders</option>
          <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
        </select>
      </div>

      <!-- Submit & Reset -->
      <div class="col-md-1 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-grow-1" title="Apply filters">
          <i class="bi bi-funnel"></i>
        </button>
        <?php if ($searchQuery || $statusFilter || $verifiedFilter !== '' || $ordersFilter || $sort !== 'joined_desc'): ?>
          <a href="<?= e(admin_url('customers.php')) ?>" class="btn btn-outline-secondary btn-sm" title="Clear filters">
            <i class="bi bi-x-lg"></i>
          </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Bulk Actions & Table Form -->
<form method="post" action="<?= e(admin_url('customers.php')) ?>" id="bulkForm">
  <?= csrf_field() ?>

  <div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="d-flex align-items-center gap-2">
        <span class="fw-semibold text-dark">
          <?= number_format($totalRows) ?> Customer<?= $totalRows === 1 ? '' : 's' ?> Found
        </span>
      </div>

      <?php if (has_role('admin')): ?>
        <div class="d-flex align-items-center gap-2" id="bulkActionsBar" style="display: none !important;">
          <span class="small text-muted"><span id="selectedCount">0</span> selected:</span>
          <button type="submit" name="bulk_action" value="block" class="btn btn-outline-danger btn-sm py-1" onclick="return confirm('Are you sure you want to block the selected customers? Their active sessions will terminate.');">
            <i class="bi bi-slash-circle me-1"></i> Block
          </button>
          <button type="submit" name="bulk_action" value="unblock" class="btn btn-outline-success btn-sm py-1" onclick="return confirm('Activate selected customers?');">
            <i class="bi bi-check-circle me-1"></i> Activate
          </button>
          <button type="submit" name="bulk_action" value="resend_verification" class="btn btn-outline-secondary btn-sm py-1" onclick="return confirm('Resend activation email to selected unverified customers?');">
            <i class="bi bi-send me-1"></i> Resend Verification
          </button>
          <button type="submit" name="bulk_action" value="delete" class="btn btn-danger btn-sm py-1" onclick="return confirm('Are you sure you want to permanently delete and anonymize the selected customers? Their orders will be unlinked and personal data deleted.');">
            <i class="bi bi-trash me-1"></i> Delete Selected
          </button>
        </div>
      <?php endif; ?>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <?php if (has_role('admin')): ?>
              <th style="width: 40px;" class="text-center">
                <input class="form-check-input" type="checkbox" id="selectAllCheckbox" title="Select all on this page">
              </th>
            <?php endif; ?>
            <th>Customer</th>
            <th>Contact</th>
            <th>Status</th>
            <th class="text-center">Orders</th>
            <th class="text-end">Lifetime Spend</th>
            <th>Last Activity</th>
            <th>Joined</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($customers)): ?>
            <tr>
              <td colspan="<?= has_role('admin') ? 9 : 8 ?>" class="text-center py-5 text-muted">
                <div class="fs-1 mb-2"><i class="bi bi-people"></i></div>
                No customers found matching the selected filters.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($customers as $c): ?>
              <?php
              $initials = strtoupper(mb_substr(trim($c['name']), 0, 1));
              $avatarUrl = null;
              if (!empty($c['avatar'])) {
                  $resolvedUrl = avatar_url($c['avatar'], $c['name']);
                  if (!str_contains($resolvedUrl, 'ui-avatars.com')) {
                      $avatarUrl = $resolvedUrl;
                  }
              }

              $statusBadge = 'bg-secondary';
              if ($c['status'] === 'active') $statusBadge = 'bg-success';
              elseif ($c['status'] === 'pending') $statusBadge = 'bg-warning text-dark';
              elseif ($c['status'] === 'blocked') $statusBadge = 'bg-danger';
              ?>
              <tr>
                <?php if (has_role('admin')): ?>
                  <td class="text-center">
                    <input class="form-check-input row-checkbox" type="checkbox" name="selected_ids[]" value="<?= (int)$c['id'] ?>">
                  </td>
                <?php endif; ?>

                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center fw-bold overflow-hidden flex-shrink-0" style="width: 38px; height: 38px; font-size: 14px;">
                      <?php if ($avatarUrl): ?>
                        <img src="<?= e($avatarUrl) ?>" alt="<?= e($c['name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                      <?php else: ?>
                        <span><?= e($initials) ?></span>
                      <?php endif; ?>
                    </div>
                    <div>
                      <a href="<?= e(admin_url('customer_view.php?id=' . (int)$c['id'])) ?>" class="fw-semibold text-dark text-decoration-none">
                        <?= e($c['name']) ?>
                      </a>
                      <div class="small text-muted d-flex align-items-center gap-1">
                        <span>#<?= (int)$c['id'] ?></span>
                        <?php if (!empty($c['marketing_opt_in'])): ?>
                          <span class="badge bg-light text-muted border py-0 px-1" title="Subscribed to marketing" style="font-size: 0.68rem;">
                            <i class="bi bi-envelope-check"></i> Subscribed
                          </span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </td>

                <td>
                  <div class="small">
                    <div class="d-flex align-items-center gap-1">
                      <a href="mailto:<?= e($c['email']) ?>" class="text-decoration-none text-dark text-truncate" style="max-width: 180px;">
                        <?= e($c['email']) ?>
                      </a>
                      <?php if (!empty($c['email_verified_at'])): ?>
                        <i class="bi bi-patch-check-fill text-primary" title="Email Verified on <?= e(date('M j, Y', strtotime($c['email_verified_at']))) ?>"></i>
                      <?php else: ?>
                        <i class="bi bi-exclamation-circle text-warning" title="Email Unverified"></i>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($c['phone'])): ?>
                      <div class="text-muted mt-1"><i class="bi bi-telephone text-secondary me-1"></i><?= e($c['phone']) ?></div>
                    <?php endif; ?>
                  </div>
                </td>

                <td>
                  <span class="badge <?= $statusBadge ?> text-capitalize" style="font-size: 0.72rem;">
                    <?= e($c['status']) ?>
                  </span>
                </td>

                <td class="text-center fw-semibold">
                  <?= (int)$c['order_count'] ?>
                </td>

                <td class="text-end fw-bold text-dark">
                  <?= e(price($c['lifetime_spend'])) ?>
                </td>

                <td>
                  <div class="small text-muted">
                    <?php if (!empty($c['last_login_at'])): ?>
                      <div><i class="bi bi-box-arrow-in-right me-1"></i> Login: <?= date('M j, Y', strtotime($c['last_login_at'])) ?></div>
                    <?php else: ?>
                      <div><i class="bi bi-dash"></i> Never logged in</div>
                    <?php endif; ?>
                    <?php if (!empty($c['last_order_at'])): ?>
                      <div><i class="bi bi-bag-check me-1"></i> Order: <?= date('M j, Y', strtotime($c['last_order_at'])) ?></div>
                    <?php endif; ?>
                  </div>
                </td>

                <td>
                  <span class="small text-muted"><?= date('M j, Y', strtotime($c['created_at'])) ?></span>
                </td>

                <td class="text-end text-nowrap">
                  <div class="d-inline-flex align-items-center gap-1">
                    <a href="<?= e(admin_url('customer_view.php?id=' . (int)$c['id'])) ?>" class="btn btn-outline-primary btn-sm py-1 px-2" style="font-size: 0.8rem;">
                      <i class="bi bi-eye me-1"></i> View
                    </a>
                    <?php if (has_role('admin')): ?>
                      <button type="button" class="btn btn-outline-danger btn-sm py-1 px-2 btn-delete-customer"
                              data-id="<?= (int)$c['id'] ?>"
                              data-name="<?= e($c['name']) ?>"
                              data-email="<?= e($c['email']) ?>"
                              style="font-size: 0.8rem;"
                              title="Delete Customer">
                        <i class="bi bi-trash me-1"></i> Delete
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

    <!-- Pagination Footer -->
    <?php if ($totalPages > 1): ?>
      <div class="card-footer bg-white py-3 border-top d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="small text-muted">
          Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRows) ?> of <?= number_format($totalRows) ?> customers
        </div>
        <?= render_admin_pagination($page, $totalPages, $_GET, 15) ?>
      </div>
    <?php endif; ?>
  </div>
</form>

<?php if (has_role('admin')): ?>
  <!-- Single Customer Delete Confirmation Modal -->
  <div class="modal fade" id="deleteCustomerSingleModal" tabindex="-1" aria-labelledby="deleteSingleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow">
        <form method="post" action="<?= e(admin_url('customers.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_single">
          <input type="hidden" name="id" id="deleteSingleCustomerId" value="">

          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title fs-6" id="deleteSingleModalLabel">
              <i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Customer Deletion
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body p-4">
            <p class="mb-2">Are you sure you want to permanently delete and anonymize customer <strong id="deleteSingleCustomerName"></strong>?</p>
            <div class="alert alert-warning small mb-3">
              <ul class="mb-0 ps-3">
                <li>Order history will be retained unlinked for accounting/tax records.</li>
                <li>Saved addresses, wishlist items, and login sessions will be permanently erased.</li>
                <li>Customer email will be anonymized to prevent further sign in.</li>
              </ul>
            </div>
            <p class="small text-muted mb-0">This action cannot be undone.</p>
          </div>

          <div class="modal-footer bg-light">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger btn-sm">
              <i class="bi bi-trash me-1"></i> Delete Customer
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const selectAll = document.getElementById('selectAllCheckbox');
  const checkboxes = document.querySelectorAll('.row-checkbox');
  const bulkBar = document.getElementById('bulkActionsBar');
  const countSpan = document.getElementById('selectedCount');

  function updateBulkBar() {
    const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
    if (countSpan) countSpan.textContent = checkedCount;
    if (bulkBar) {
      if (checkedCount > 0) {
        bulkBar.style.removeProperty('display');
      } else {
        bulkBar.style.setProperty('display', 'none', 'important');
      }
    }
  }

  if (selectAll) {
    selectAll.addEventListener('change', function() {
      checkboxes.forEach(cb => cb.checked = selectAll.checked);
      updateBulkBar();
    });
  }

  checkboxes.forEach(cb => {
    cb.addEventListener('change', function() {
      if (!this.checked && selectAll) selectAll.checked = false;
      updateBulkBar();
    });
  });

  // Single customer delete handler
  const deleteModalEl = document.getElementById('deleteCustomerSingleModal');
  const deleteModal = (deleteModalEl && typeof bootstrap !== 'undefined') ? new bootstrap.Modal(deleteModalEl) : null;
  const deleteIdInput = document.getElementById('deleteSingleCustomerId');
  const deleteNameSpan = document.getElementById('deleteSingleCustomerName');

  document.querySelectorAll('.btn-delete-customer').forEach(btn => {
    btn.addEventListener('click', function() {
      const id = this.getAttribute('data-id');
      const name = this.getAttribute('data-name');
      if (deleteIdInput) deleteIdInput.value = id;
      if (deleteNameSpan) deleteNameSpan.textContent = name ? `${name} (#${id})` : `#${id}`;
      if (deleteModal) {
        deleteModal.show();
      } else {
        if (confirm(`Are you sure you want to delete customer ${name} (#${id})? This will anonymize their account and unlink orders.`)) {
          const form = document.createElement('form');
          form.method = 'POST';
          form.action = 'customers.php';
          form.innerHTML = `
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_single">
            <input type="hidden" name="id" value="${id}">
          `;
          document.body.appendChild(form);
          form.submit();
        }
      }
    });
  });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
