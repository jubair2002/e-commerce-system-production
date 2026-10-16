<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$pageTitle = 'Inventory & Stock Ledger';

$subTab = trim((string)($_GET['tab'] ?? 'overview'));
if (!in_array($subTab, ['overview', 'ledger', 'import'], true)) {
    $subTab = 'overview';
}

// -----------------------------------------------------------------
// POST Action 1: Inline Stock Adjustment via stock_adjust()
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust_stock') {
    require_csrf();
    $productId = (int)($_POST['product_id'] ?? 0);
    $variantId = !empty($_POST['variant_id']) ? (int)$_POST['variant_id'] : null;
    $delta     = (int)($_POST['delta'] ?? 0);
    $reason    = trim((string)($_POST['reason'] ?? 'manual_adjust'));
    $note      = trim((string)($_POST['note'] ?? ''));

    if ($delta === 0) {
        flash('warning', 'Adjustment quantity cannot be 0.');
    } else {
        $adjusted = stock_adjust(
            $productId,
            $variantId,
            $delta,
            $reason,
            null,
            $note ?: 'Manual adjustment from admin inventory'
        );

        if ($adjusted) {
            log_activity('inventory.adjust', "Adjusted stock for P:{$productId} V:{$variantId} by {$delta} ({$reason})");
            flash('success', "Stock successfully adjusted by {$delta}.");
        } else {
            flash('danger', 'Unable to adjust stock. (Cannot reduce stock below 0 if backorders are disabled).');
        }
    }
    admin_redirect('inventory.php?tab=overview');
}

// -----------------------------------------------------------------
// POST Action 2: CSV Stock Import (Dry-run & Commit)
// -----------------------------------------------------------------
$dryRunRows = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview_csv') {
    require_csrf();
    if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if ($handle) {
            $header = fgetcsv($handle);
            // Normalize headers
            $skuIdx = false;
            $stockIdx = false;
            foreach ($header as $idx => $col) {
                $c = strtolower(trim($col));
                if ($c === 'sku') $skuIdx = $idx;
                if ($c === 'stock' || $c === 'qty' || $c === 'quantity') $stockIdx = $idx;
            }

            if ($skuIdx === false || $stockIdx === false) {
                flash('danger', 'Invalid CSV format. File must contain "sku" and "stock" columns in the header.');
            } else {
                $rowsToPreview = [];
                while (($data = fgetcsv($handle)) !== false) {
                    if (empty($data[$skuIdx])) continue;
                    $sku = trim($data[$skuIdx]);
                    $targetStock = (int)($data[$stockIdx] ?? 0);

                    // Look up variant or product by SKU
                    $found = null;
                    $currentStock = 0;
                    $itemType = 'product';
                    $pid = null;
                    $vid = null;

                    // Check variants first
                    $vStmt = db()->prepare("SELECT v.*, p.name as product_name FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.sku = :s LIMIT 1");
                    $vStmt->execute([':s' => $sku]);
                    $varRow = $vStmt->fetch();

                    if ($varRow) {
                        $found = $varRow['product_name'] . ' (' . ($varRow['label'] ?? 'Variant') . ')';
                        $currentStock = (int)$varRow['stock'];
                        $itemType = 'variant';
                        $pid = (int)$varRow['product_id'];
                        $vid = (int)$varRow['id'];
                    } else {
                        // Check products
                        $pStmt = db()->prepare("SELECT * FROM products WHERE sku = :s LIMIT 1");
                        $pStmt->execute([':s' => $sku]);
                        $prodRow = $pStmt->fetch();
                        if ($prodRow) {
                            $found = $prodRow['name'];
                            $currentStock = (int)$prodRow['stock'];
                            $pid = (int)$prodRow['id'];
                        }
                    }

                    $delta = $found ? ($targetStock - $currentStock) : 0;

                    $rowsToPreview[] = [
                        'sku'           => $sku,
                        'name'          => $found ?: 'NOT FOUND',
                        'product_id'    => $pid,
                        'variant_id'    => $vid,
                        'current_stock' => $currentStock,
                        'target_stock'  => $targetStock,
                        'delta'         => $delta,
                        'status'        => $found ? 'Ready' : 'Invalid SKU',
                    ];
                }
                fclose($handle);
                $_SESSION['stock_import_preview'] = $rowsToPreview;
                flash('info', 'CSV parsed. Review the dry-run preview below before confirming changes.');
                admin_redirect('inventory.php?tab=import&preview=1');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commit_import') {
    require_csrf();
    $previewData = $_SESSION['stock_import_preview'] ?? [];
    if (!$previewData) {
        flash('warning', 'No preview data found to import.');
        admin_redirect('inventory.php?tab=import');
    }

    $applied = 0;
    foreach ($previewData as $item) {
        if ($item['status'] !== 'Ready' || $item['delta'] === 0) continue;
        $ok = stock_adjust(
            (int)$item['product_id'],
            $item['variant_id'] ? (int)$item['variant_id'] : null,
            (int)$item['delta'],
            'restock',
            null,
            'Imported from CSV'
        );
        if ($ok) $applied++;
    }

    unset($_SESSION['stock_import_preview']);
    log_activity('inventory.import', "Imported stock for {$applied} items via CSV");
    flash('success', "Stock updated for {$applied} items successfully.");
    admin_redirect('inventory.php?tab=overview');
}

// -----------------------------------------------------------------
// Query for Overview Tab
// -----------------------------------------------------------------
$stockFilter = trim((string)($_GET['filter'] ?? 'all'));
$search      = trim((string)($_GET['q'] ?? ''));

// Fetch inventory rows (combining simple products and variable product variants)
$inventoryItems = [];
try {
    // 1. Simple products
    $simpleSql = "SELECT p.id as product_id, NULL as variant_id, p.name as product_name,
                         '' as variant_label, p.sku, p.stock, p.low_stock_threshold, p.status,
                         'simple' as item_type
                  FROM products p
                  WHERE p.product_type = 'simple'";
    if ($search !== '') {
        $simpleSql .= " AND (p.name LIKE " . db()->quote("%$search%") . " OR p.sku LIKE " . db()->quote("%$search%") . ")";
    }
    $simpleRows = db()->query($simpleSql)->fetchAll();

    // 2. Variable product variants
    $variantSql = "SELECT v.product_id, v.id as variant_id, p.name as product_name,
                          v.label as variant_label, COALESCE(v.sku, p.sku) as sku, v.stock,
                          p.low_stock_threshold, v.status, 'variant' as item_type
                   FROM product_variants v
                   JOIN products p ON p.id = v.product_id";
    if ($search !== '') {
        $variantSql .= " WHERE (p.name LIKE " . db()->quote("%$search%") . " OR v.sku LIKE " . db()->quote("%$search%") . " OR v.label LIKE " . db()->quote("%$search%") . ")";
    }
    $variantRows = db()->query($variantSql)->fetchAll();

    $merged = array_merge($simpleRows, $variantRows);

    foreach ($merged as $row) {
        $st = (int)$row['stock'];
        $thresh = (int)($row['low_stock_threshold'] ?: 5);

        if ($st <= 0) {
            $condition = 'out';
        } elseif ($st <= $thresh) {
            $condition = 'low';
        } else {
            $condition = 'in';
        }

        $row['condition'] = $condition;

        if ($stockFilter === 'low' && $condition !== 'low') continue;
        if ($stockFilter === 'out' && $condition !== 'out') continue;

        $inventoryItems[] = $row;
    }
} catch (Throwable $e) {
    $inventoryItems = [];
}

// -----------------------------------------------------------------
// Query for Ledger Tab (inventory_movements)
// -----------------------------------------------------------------
$reasonFilter = trim((string)($_GET['reason'] ?? ''));

$ledgerWhere = [];
$ledgerParams = [];
if ($reasonFilter !== '') {
    $ledgerWhere[] = 'm.reason = :r';
    $ledgerParams[':r'] = $reasonFilter;
}
$ledgerWhereSql = $ledgerWhere ? ('WHERE ' . implode(' AND ', $ledgerWhere)) : '';

// CSV Export for Ledger
if ($subTab === 'ledger' && isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = db()->prepare(
        "SELECT m.*, p.name as product_name, v.label as variant_label, o.order_number, u.name as user_name
         FROM inventory_movements m
         LEFT JOIN products p ON p.id = m.product_id
         LEFT JOIN product_variants v ON v.id = m.variant_id
         LEFT JOIN orders o ON o.id = m.order_id
         LEFT JOIN users u ON u.id = m.user_id
         $ledgerWhereSql
         ORDER BY m.id DESC"
    );
    $stmt->execute($ledgerParams);
    $ledgerExport = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_ledger_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Date', 'Product', 'Variant', 'Change Qty', 'Stock After', 'Reason', 'Order Number', 'User', 'Note']);
    foreach ($ledgerExport as $lr) {
        fputcsv($out, [
            $lr['id'],
            $lr['created_at'],
            $lr['product_name'] ?? ('Product #' . $lr['product_id']),
            $lr['variant_label'] ?? '',
            $lr['change_qty'],
            $lr['stock_after'],
            $lr['reason'],
            $lr['order_number'] ?? '',
            $lr['user_name'] ?? 'System',
            $lr['note'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$ledgerRows = [];
if ($subTab === 'ledger') {
    try {
        $stmt = db()->prepare(
            "SELECT m.*, p.name as product_name, v.label as variant_label, o.order_number, u.name as user_name
             FROM inventory_movements m
             LEFT JOIN products p ON p.id = m.product_id
             LEFT JOIN product_variants v ON v.id = m.variant_id
             LEFT JOIN orders o ON o.id = m.order_id
             LEFT JOIN users u ON u.id = m.user_id
             $ledgerWhereSql
             ORDER BY m.id DESC
             LIMIT 100"
        );
        $stmt->execute($ledgerParams);
        $ledgerRows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $ledgerRows = [];
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="mb-1">Inventory Management</h3>
    <p class="text-muted small mb-0">Live stock overview, atomic adjustment controls, and full inventory movement audit trail.</p>
  </div>
</div>

<!-- Sub Navigation Tabs -->
<ul class="nav nav-pills mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $subTab === 'overview' ? 'active' : '' ?>" href="<?= e(admin_url('inventory.php?tab=overview')) ?>">
      <i class="bi bi-grid-3x3 me-1"></i> Stock Overview (<?= count($inventoryItems) ?>)
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $subTab === 'ledger' ? 'active' : '' ?>" href="<?= e(admin_url('inventory.php?tab=ledger')) ?>">
      <i class="bi bi-journal-text me-1"></i> Movements Ledger
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $subTab === 'import' ? 'active' : '' ?>" href="<?= e(admin_url('inventory.php?tab=import')) ?>">
      <i class="bi bi-file-earmark-arrow-up me-1"></i> Import Stock CSV
    </a>
  </li>
</ul>

<!-- ================================================================= -->
<!-- TAB 1: STOCK OVERVIEW                                             -->
<!-- ================================================================= -->
<?php if ($subTab === 'overview'): ?>
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-center">
        <input type="hidden" name="tab" value="overview">
        <div class="col-md-5">
          <input type="text" name="q" class="form-control" placeholder="Search by name, SKU or variant label..." value="<?= e($search) ?>">
        </div>
        <div class="col-md-3">
          <select name="filter" class="form-select" onchange="this.form.submit()">
            <option value="all" <?= $stockFilter === 'all' ? 'selected' : '' ?>>All Stock Statuses</option>
            <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low Stock Only (&le; Threshold)</option>
            <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of Stock Only (&le; 0)</option>
          </select>
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Search</button>
        </div>
        <?php if ($search || $stockFilter !== 'all'): ?>
          <div class="col-md-2">
            <a href="<?= e(admin_url('inventory.php?tab=overview')) ?>" class="btn btn-outline-secondary w-100">Clear</a>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <div class="card shadow-sm border-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Product & Variant</th>
            <th>Type</th>
            <th>SKU</th>
            <th class="text-center">Current Stock</th>
            <th class="text-center">Threshold</th>
            <th>Condition</th>
            <th class="text-end" style="width: 140px;">Adjust Stock</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$inventoryItems): ?>
            <tr>
              <td colspan="7" class="text-center py-4 text-muted">No inventory items matching filter.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($inventoryItems as $item): ?>
              <tr>
                <td>
                  <div class="fw-bold"><?= e($item['product_name']) ?></div>
                  <?php if (!empty($item['variant_label'])): ?>
                    <span class="badge bg-light text-dark border"><?= e($item['variant_label']) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge bg-light text-dark border text-uppercase" style="font-size: 11px;">
                    <?= e($item['item_type']) ?>
                  </span>
                </td>
                <td><code><?= e($item['sku'] ?: '-') ?></code></td>
                <td class="text-center fw-bold fs-6">
                  <?= (int)$item['stock'] ?>
                </td>
                <td class="text-center text-muted">
                  <?= (int)$item['low_stock_threshold'] ?>
                </td>
                <td>
                  <?php if ($item['condition'] === 'out'): ?>
                    <span class="badge bg-danger">Out of Stock</span>
                  <?php elseif ($item['condition'] === 'low'): ?>
                    <span class="badge bg-warning text-dark">Low Stock</span>
                  <?php else: ?>
                    <span class="badge bg-success">In Stock</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-outline-primary"
                          data-bs-toggle="modal"
                          data-bs-target="#adjustModal"
                          data-pid="<?= (int)$item['product_id'] ?>"
                          data-vid="<?= $item['variant_id'] ? (int)$item['variant_id'] : '' ?>"
                          data-name="<?= e($item['product_name'] . ($item['variant_label'] ? ' - ' . $item['variant_label'] : '')) ?>"
                          data-cur="<?= (int)$item['stock'] ?>">
                    <i class="bi bi-pencil-square me-1"></i> Adjust
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Adjustment Modal -->
  <div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="adjust_stock">
          <input type="hidden" name="product_id" id="modalPid">
          <input type="hidden" name="variant_id" id="modalVid">

          <div class="modal-header">
            <h5 class="modal-title">Adjust Stock Level</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p id="modalItemName" class="fw-bold mb-2"></p>
            <div class="mb-3">
              <label class="form-label small text-muted">Current Stock: <strong id="modalCurrentStock">0</strong></label>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Quantity Adjustment (+ or -)</label>
              <input type="number" name="delta" class="form-control" placeholder="e.g. +10 or -3" required>
              <div class="form-text">Positive number to add stock, negative to deduct stock.</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Reason</label>
              <select name="reason" class="form-select">
                <option value="restock">Restock / New Shipment Received</option>
                <option value="manual_adjust">Manual Stock Adjustment</option>
                <option value="correction">Inventory Audit Count Correction</option>
                <option value="return">Customer Return</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Audit Note</label>
              <input type="text" name="note" class="form-control" placeholder="Optional reference note">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Apply Adjustment</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('adjustModal');
    if (modal) {
      modal.addEventListener('show.bs.modal', function(event) {
        const btn = event.relatedTarget;
        document.getElementById('modalPid').value = btn.getAttribute('data-pid');
        document.getElementById('modalVid').value = btn.getAttribute('data-vid') || '';
        document.getElementById('modalItemName').textContent = btn.getAttribute('data-name');
        document.getElementById('modalCurrentStock').textContent = btn.getAttribute('data-cur');
      });
    }
  });
  </script>
<?php endif; ?>

<!-- ================================================================= -->
<!-- TAB 2: MOVEMENTS LEDGER                                           -->
<!-- ================================================================= -->
<?php if ($subTab === 'ledger'): ?>
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <form method="get" class="d-flex gap-2">
          <input type="hidden" name="tab" value="ledger">
          <select name="reason" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All Movement Reasons</option>
            <option value="order" <?= $reasonFilter === 'order' ? 'selected' : '' ?>>Customer Orders</option>
            <option value="cancel_restock" <?= $reasonFilter === 'cancel_restock' ? 'selected' : '' ?>>Order Cancel Restock</option>
            <option value="restock" <?= $reasonFilter === 'restock' ? 'selected' : '' ?>>Shipment Restock</option>
            <option value="manual_adjust" <?= $reasonFilter === 'manual_adjust' ? 'selected' : '' ?>>Manual Adjustments</option>
            <option value="correction" <?= $reasonFilter === 'correction' ? 'selected' : '' ?>>Audit Corrections</option>
          </select>
        </form>
        <a href="<?= e(admin_url('inventory.php?tab=ledger&export=csv' . ($reasonFilter ? '&reason=' . urlencode($reasonFilter) : ''))) ?>"
           class="btn btn-outline-success btn-sm">
          <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Ledger CSV
        </a>
      </div>
    </div>
  </div>

  <div class="card shadow-sm border-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Date & Time</th>
            <th>Item</th>
            <th class="text-center">Change</th>
            <th class="text-center">Stock After</th>
            <th>Reason</th>
            <th>Order Ref</th>
            <th>Logged By</th>
            <th>Note</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$ledgerRows): ?>
            <tr>
              <td colspan="8" class="text-center py-4 text-muted">No inventory movements recorded yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($ledgerRows as $lr): ?>
              <tr>
                <td class="small text-muted">
                  <?= date('M j, Y - g:i a', strtotime($lr['created_at'])) ?>
                </td>
                <td>
                  <div class="fw-semibold"><?= e($lr['product_name'] ?? ('Product #' . $lr['product_id'])) ?></div>
                  <?php if (!empty($lr['variant_label'])): ?>
                    <span class="badge bg-light text-dark border"><?= e($lr['variant_label']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <?php if ((int)$lr['change_qty'] > 0): ?>
                    <span class="badge bg-success">+<?= (int)$lr['change_qty'] ?></span>
                  <?php else: ?>
                    <span class="badge bg-danger"><?= (int)$lr['change_qty'] ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-center fw-bold">
                  <?= e($lr['stock_after'] ?? '-') ?>
                </td>
                <td>
                  <span class="badge bg-light text-dark border text-uppercase" style="font-size: 11px;">
                    <?= e(str_replace('_', ' ', $lr['reason'])) ?>
                  </span>
                </td>
                <td>
                  <?php if (!empty($lr['order_number'])): ?>
                    <a href="<?= e(admin_url('order_view.php?id=' . $lr['order_id'])) ?>" class="small fw-bold">
                      <?= e($lr['order_number']) ?>
                    </a>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted">
                  <?= e($lr['user_name'] ?? 'System') ?>
                </td>
                <td class="small text-muted">
                  <?= e($lr['note'] ?? '') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- ================================================================= -->
<!-- TAB 3: IMPORT STOCK CSV                                           -->
<!-- ================================================================= -->
<?php if ($subTab === 'import'): ?>
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
      <h5 class="fw-bold mb-3">Bulk Stock CSV Import</h5>
      <p class="text-muted small">
        Upload a CSV file containing <code>sku</code> and <code>stock</code> columns to update inventory quantities across simple and variable products in one go.
      </p>

      <form method="post" enctype="multipart/form-data" class="row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="preview_csv">
        <div class="col-md-6">
          <input type="file" name="csv_file" class="form-control" accept=".csv" required>
        </div>
        <div class="col-md-3">
          <button type="submit" class="btn btn-primary w-100"><i class="bi bi-eye me-1"></i> Preview Dry-Run</button>
        </div>
      </form>
    </div>
  </div>

  <?php if (!empty($_SESSION['stock_import_preview'])): ?>
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">Dry-Run Preview (<?= count($_SESSION['stock_import_preview']) ?> rows)</h6>
        <form method="post" onsubmit="return confirm('Commit these stock adjustments to the database?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="commit_import">
          <button type="submit" class="btn btn-success btn-sm">
            <i class="bi bi-check2-circle me-1"></i> Confirm & Commit Stock Updates
          </button>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>SKU</th>
              <th>Matched Item</th>
              <th class="text-center">Current Stock</th>
              <th class="text-center">Target Stock</th>
              <th class="text-center">Delta</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($_SESSION['stock_import_preview'] as $row): ?>
              <tr>
                <td><code><?= e($row['sku']) ?></code></td>
                <td><?= e($row['name']) ?></td>
                <td class="text-center"><?= (int)$row['current_stock'] ?></td>
                <td class="text-center fw-bold"><?= (int)$row['target_stock'] ?></td>
                <td class="text-center">
                  <?php if ($row['delta'] > 0): ?>
                    <span class="badge bg-success">+<?= (int)$row['delta'] ?></span>
                  <?php elseif ($row['delta'] < 0): ?>
                    <span class="badge bg-danger"><?= (int)$row['delta'] ?></span>
                  <?php else: ?>
                    <span class="text-muted">No change</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($row['status'] === 'Ready'): ?>
                    <span class="badge bg-success">Ready</span>
                  <?php else: ?>
                    <span class="badge bg-danger"><?= e($row['status']) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
