<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'editor');

$pageTitle = 'Products';

// -------------------------------------------------------------
// POST Handlers: Single Delete & Bulk Actions
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // Single product delete
    if ($action === 'delete') {
        require_role('admin');
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT name, image FROM products WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($p = $stmt->fetch()) {
            if ($p['image'] && file_exists(UPLOADS_PATH . '/products/' . $p['image'])) {
                @unlink(UPLOADS_PATH . '/products/' . $p['image']);
            }
            db()->prepare('DELETE FROM products WHERE id = :id')->execute([':id' => $id]);
            log_activity('product.delete', 'Deleted product ' . $p['name']);
            flash('success', 'Product deleted.');
        } else {
            flash('warning', 'Product not found.');
        }
        admin_redirect('products.php');
    }

    // Bulk actions
    if ($action === 'bulk') {
        $bulkOp = $_POST['bulk_op'] ?? '';
        $selectedIds = array_filter(array_map('intval', (array)($_POST['selected_ids'] ?? [])));

        if ($selectedIds) {
            $inClause = implode(',', $selectedIds);
            if ($bulkOp === 'activate') {
                db()->exec("UPDATE products SET status = 'active' WHERE id IN ($inClause)");
                log_activity('product.bulk_activate', 'Activated ' . count($selectedIds) . ' products');
                flash('success', count($selectedIds) . ' products activated.');
            } elseif ($bulkOp === 'deactivate') {
                db()->exec("UPDATE products SET status = 'inactive' WHERE id IN ($inClause)");
                log_activity('product.bulk_deactivate', 'Deactivated ' . count($selectedIds) . ' products');
                flash('success', count($selectedIds) . ' products deactivated.');
            } elseif ($bulkOp === 'delete') {
                require_role('admin');
                $stmtImgs = db()->query("SELECT image FROM products WHERE id IN ($inClause)");
                while ($img = $stmtImgs->fetchColumn()) {
                    if ($img && file_exists(UPLOADS_PATH . '/products/' . $img)) {
                        @unlink(UPLOADS_PATH . '/products/' . $img);
                    }
                }
                db()->exec("DELETE FROM products WHERE id IN ($inClause)");
                log_activity('product.bulk_delete', 'Deleted ' . count($selectedIds) . ' products');
                flash('success', count($selectedIds) . ' products deleted.');
            }
        }
        admin_redirect('products.php');
    }
}

// -------------------------------------------------------------
// Filters & Pagination
// -------------------------------------------------------------
$search      = trim((string)($_GET['q'] ?? ''));
$catId       = (int)($_GET['category'] ?? 0);
$status      = trim((string)($_GET['status'] ?? ''));
$typeFilter  = trim((string)($_GET['type'] ?? ''));
$stockFilter = trim((string)($_GET['stock_status'] ?? ''));
$featured    = trim((string)($_GET['featured'] ?? ''));
$perPage     = max(5, min(100, (int)($_GET['per_page'] ?? setting('items_per_page', '15'))));
$page        = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(p.name LIKE :q1 OR p.sku LIKE :q2)';
    $params[':q1'] = "%$search%";
    $params[':q2'] = "%$search%";
}
if ($catId > 0) {
    $where[] = 'p.category_id = :cid';
    $params[':cid'] = $catId;
}
if (in_array($status, ['active', 'inactive', 'draft'], true)) {
    $where[] = 'p.status = :st';
    $params[':st'] = $status;
}
if (in_array($typeFilter, ['simple', 'variable'], true)) {
    $where[] = 'p.product_type = :ptype';
    $params[':ptype'] = $typeFilter;
}
if ($featured === '1') {
    $where[] = 'p.featured = 1';
}

if ($stockFilter === 'out') {
    $where[] = 'COALESCE(vps.total_stock, p.stock) <= 0';
} elseif ($stockFilter === 'low') {
    $where[] = 'COALESCE(vps.total_stock, p.stock) > 0 AND COALESCE(vps.total_stock, p.stock) <= COALESCE(p.low_stock_threshold, 5)';
} elseif ($stockFilter === 'in') {
    $where[] = 'COALESCE(vps.total_stock, p.stock) > COALESCE(p.low_stock_threshold, 5)';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countJoins = str_contains($whereSql, 'vps.') ? ' LEFT JOIN v_product_stock vps ON vps.product_id = p.id' : '';
$countSql = "SELECT COUNT(p.id) FROM products p {$countJoins} {$whereSql}";
$stmt = db()->prepare($countSql);
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$selectSql = "
    SELECT p.id, p.name, p.slug, p.sku, p.price, p.sale_price, p.stock, p.image,
           p.status, p.featured, p.product_type, p.low_stock_threshold,
           c.name AS category_name,
           COALESCE(vps.total_stock, p.stock) AS calculated_stock,
           COALESCE(vps.variant_count, 1) AS variant_count
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN v_product_stock vps ON vps.product_id = p.id
    $whereSql
    ORDER BY p.created_at DESC
    LIMIT :lim OFFSET :off
";
$stmt = db()->prepare($selectSql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$categories = db()->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Filter Card -->
<div class="card mb-3 shadow-sm">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-3">
        <label class="form-label small text-muted">Search</label>
        <input type="text" name="q" class="form-control" value="<?= e($search) ?>" placeholder="Name or SKU">
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">Category</label>
        <select name="category" class="form-select">
          <option value="0">All</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $catId === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">Type</label>
        <select name="type" class="form-select">
          <option value="">All types</option>
          <option value="simple" <?= $typeFilter === 'simple' ? 'selected' : '' ?>>Simple</option>
          <option value="variable" <?= $typeFilter === 'variable' ? 'selected' : '' ?>>With options</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">Stock</label>
        <select name="stock_status" class="form-select">
          <option value="">All stock</option>
          <option value="in" <?= $stockFilter === 'in' ? 'selected' : '' ?>>In stock</option>
          <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low stock</option>
          <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of stock</option>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label small text-muted">Status</label>
        <select name="status" class="form-select">
          <option value="">All</option>
          <?php foreach (['active', 'inactive', 'draft'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button>
      </div>
    </form>
  </div>
</div>

<!-- Products List Card -->
<div class="card shadow-sm">
  <div class="card-body">
    <form method="post" id="bulk_action_form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk">

      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
          <h5 class="card-title mb-0"><?= number_format($total) ?> product<?= $total === 1 ? '' : 's' ?></h5>
          <!-- Bulk actions dropdown -->
          <div class="dropdown ms-2">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" id="bulk_dropdown_btn" disabled>
              Bulk actions
            </button>
            <ul class="dropdown-menu">
              <li><button type="submit" name="bulk_op" value="activate" class="dropdown-item"><i class="bi bi-check-circle text-success me-1"></i> Activate selected</button></li>
              <li><button type="submit" name="bulk_op" value="deactivate" class="dropdown-item"><i class="bi bi-pause-circle text-warning me-1"></i> Deactivate selected</button></li>
              <?php if (has_role('admin')): ?>
                <li><hr class="dropdown-divider"></li>
                <li><button type="submit" name="bulk_op" value="delete" class="dropdown-item text-danger" onclick="return confirm('Delete all selected products? This cannot be undone.');"><i class="bi bi-trash text-danger me-1"></i> Delete selected</button></li>
              <?php endif; ?>
            </ul>
          </div>

          <!-- Per-page selector -->
          <label class="d-flex align-items-center gap-1 ms-3 mb-0 small text-muted">
            <span>Per page:</span>
            <select class="form-select form-select-sm" style="width: auto; display: inline-block;" onchange="const url = new URL(window.location.href); url.searchParams.set('per_page', this.value); url.searchParams.set('page', 1); window.location.href = url.toString();">
              <option value="15" <?= $perPage === 15 ? 'selected' : '' ?>>15</option>
              <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
              <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
              <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
            </select>
          </label>
        </div>

        <a class="btn btn-primary" href="<?= e(admin_url('product_form.php')) ?>">
          <i class="bi bi-plus-lg"></i> Add product
        </a>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:30px;"><input type="checkbox" class="form-check-input" id="check_all_products"></th>
              <th style="width:55px;"></th>
              <th>Product</th>
              <th>Type</th>
              <th>SKU</th>
              <th>Category</th>
              <th>Price</th>
              <th>Stock</th>
              <th>Status</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $p): ?>
              <?php
                $calcStock = (int)$p['calculated_stock'];
                $lowThresh = (int)($p['low_stock_threshold'] ?? 5);
                $isVariable = ($p['product_type'] === 'variable');
                $varCount = (int)$p['variant_count'];
              ?>
              <tr id="product_row_<?= (int)$p['id'] ?>">
                <td>
                  <input type="checkbox" name="selected_ids[]" value="<?= (int)$p['id'] ?>" class="form-check-input product-check">
                </td>
                <td>
                  <img src="<?= e(product_image_url($p['image'], 'thumbnail', (int)$p['id'])) ?>"
                       class="rounded border" width="44" height="44" style="object-fit:cover" alt="" loading="lazy">
                </td>
                <td>
                  <div class="fw-semibold">
                    <a href="<?= e(admin_url('product_form.php?id=' . (int)$p['id'])) ?>" class="text-reset text-decoration-none">
                      <?= e($p['name']) ?>
                    </a>
                    <?php if ((int)$p['featured'] === 1): ?>
                      <i class="bi bi-star-fill text-warning ms-1" title="Featured"></i>
                    <?php endif; ?>
                  </div>
                  <small class="text-muted"><code><?= e($p['slug']) ?></code></small>
                </td>
                <td>
                  <?php if ($isVariable): ?>
                    <span class="badge bg-indigo-subtle text-indigo border border-indigo-subtle">Options</span>
                  <?php else: ?>
                    <span class="badge bg-light text-secondary border">Simple</span>
                  <?php endif; ?>
                </td>
                <td><code><?= e($p['sku'] ?: '—') ?></code></td>
                <td><?= e($p['category_name'] ?: '—') ?></td>
                <td>
                  <?php if ($p['sale_price'] !== null && (float)$p['sale_price'] > 0): ?>
                    <span class="fw-semibold text-success"><?= e(price((float)$p['sale_price'])) ?></span>
                    <small class="text-muted text-decoration-line-through d-block"><?= e(price((float)$p['price'])) ?></small>
                  <?php else: ?>
                    <span class="fw-semibold"><?= e(price((float)$p['price'])) ?></span>
                  <?php endif; ?>
                </td>
                <td id="stock_badge_col_<?= (int)$p['id'] ?>">
                  <?php if ($calcStock <= 0): ?>
                    <span class="badge text-bg-danger">Out of stock</span>
                  <?php elseif ($calcStock <= $lowThresh): ?>
                    <span class="badge text-bg-warning"><?= $calcStock ?> in stock</span>
                  <?php else: ?>
                    <span class="badge text-bg-success"><?= $calcStock ?></span>
                  <?php endif; ?>
                  <?php if ($isVariable): ?>
                    <small class="text-muted d-block"><?= $varCount ?> variant<?= $varCount === 1 ? '' : 's' ?></small>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge status-<?= e($p['status'] === 'active' ? 'active' : 'inactive') ?>">
                    <?= e(ucfirst($p['status'])) ?>
                  </span>
                </td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-outline-secondary quick-stock-btn me-1"
                          data-id="<?= (int)$p['id'] ?>" title="Quick stock adjustment">
                    <i class="bi bi-box-arrow-in-down"></i>
                  </button>
                  <a class="btn btn-sm btn-light me-1"
                     href="<?= e(admin_url('product_form.php?id=' . (int)$p['id'])) ?>" title="Edit">
                    <i class="bi bi-pencil"></i>
                  </a>
                  <?php if (has_role('admin')): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger delete-single-btn"
                            data-id="<?= (int)$p['id'] ?>" data-name="<?= e($p['name']) ?>" title="Delete">
                      <i class="bi bi-trash"></i>
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="10" class="text-center text-muted py-4">No products match your filters.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </form>

    <div class="mt-3">
      <?= render_admin_pagination($page, $pages, $_GET, 15) ?>
    </div>
  </div>
</div>

<!-- Single Delete Form (Hidden) -->
<form method="post" id="single_delete_form" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delete_target_id" value="">
</form>

<!-- Quick Stock Modal -->
<div class="modal fade" id="quickStockModal" tabindex="-1" aria-labelledby="quickStockModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow">
      <div class="modal-header">
        <h5 class="modal-title" id="quickStockModalLabel"><i class="bi bi-box-seam me-1"></i> Quick Stock Update</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="quickStockModalBody">
        <div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Check all products
  const checkAll = document.getElementById('check_all_products');
  const rowChecks = document.querySelectorAll('.product-check');
  const bulkBtn = document.getElementById('bulk_dropdown_btn');

  function updateBulkBtn() {
    const checkedCount = document.querySelectorAll('.product-check:checked').length;
    if (bulkBtn) {
      bulkBtn.disabled = (checkedCount === 0);
      bulkBtn.textContent = checkedCount > 0 ? `Bulk actions (${checkedCount})` : 'Bulk actions';
    }
  }

  if (checkAll) {
    checkAll.addEventListener('change', function () {
      rowChecks.forEach(cb => cb.checked = checkAll.checked);
      updateBulkBtn();
    });
  }

  rowChecks.forEach(cb => {
    cb.addEventListener('change', function () {
      updateBulkBtn();
    });
  });

  // Single Delete
  document.querySelectorAll('.delete-single-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const id = this.dataset.id;
      const name = this.dataset.name;
      if (confirm(`Delete product "${name}"? This cannot be undone.`)) {
        document.getElementById('delete_target_id').value = id;
        document.getElementById('single_delete_form').submit();
      }
    });
  });

  // Quick Stock Modal
  const quickStockModal = new bootstrap.Modal(document.getElementById('quickStockModal'));
  const modalBody = document.getElementById('quickStockModalBody');
  const csrfToken = "<?= e(csrf_token()) ?>";

  document.querySelectorAll('.quick-stock-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const pid = this.dataset.id;
      modalBody.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';
      quickStockModal.show();

      fetch(`ajax/stock_update.php?action=get_stock&product_id=${pid}`)
        .then(r => r.json())
        .then(res => {
          if (!res.ok) {
            modalBody.innerHTML = `<div class="alert alert-danger py-2">${res.error || 'Failed to load stock.'}</div>`;
            return;
          }

          let html = `<h6 class="fw-bold mb-1">${res.product_name}</h6>`;
          html += `<p class="text-muted small mb-3">Total current stock: <strong id="modal_total_stock">${res.total_stock}</strong></p>`;

          if (res.product_type === 'variable' && res.variants.length > 0) {
            html += `<div class="list-group">`;
            res.variants.forEach(v => {
              html += `
                <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                  <div>
                    <div class="fw-semibold small">${v.label}</div>
                    <small class="text-muted">${v.sku || 'No SKU'} &bull; Stock: <strong id="var_stock_${v.id}">${v.stock}</strong></small>
                  </div>
                  <div class="d-flex align-items-center gap-1">
                    <button class="btn btn-sm btn-outline-danger stock-delta-btn" data-pid="${res.product_id}" data-vid="${v.id}" data-delta="-1">-1</button>
                    <button class="btn btn-sm btn-outline-success stock-delta-btn" data-pid="${res.product_id}" data-vid="${v.id}" data-delta="1">+1</button>
                    <button class="btn btn-sm btn-outline-primary stock-delta-btn" data-pid="${res.product_id}" data-vid="${v.id}" data-delta="5">+5</button>
                  </div>
                </div>
              `;
            });
            html += `</div>`;
          } else {
            html += `
              <div class="d-flex justify-content-between align-items-center border p-3 rounded bg-light">
                <div>Current Stock: <strong class="fs-5" id="simple_modal_stock">${res.stock}</strong></div>
                <div class="d-flex align-items-center gap-1">
                  <button class="btn btn-outline-danger stock-delta-btn" data-pid="${res.product_id}" data-delta="-1">-1</button>
                  <button class="btn btn-outline-success stock-delta-btn" data-pid="${res.product_id}" data-delta="1">+1</button>
                  <button class="btn btn-outline-primary stock-delta-btn" data-pid="${res.product_id}" data-delta="5">+5</button>
                  <button class="btn btn-outline-primary stock-delta-btn" data-pid="${res.product_id}" data-delta="10">+10</button>
                </div>
              </div>
            `;
          }

          modalBody.innerHTML = html;

          // Attach listeners to delta buttons
          modalBody.querySelectorAll('.stock-delta-btn').forEach(dBtn => {
            dBtn.addEventListener('click', function () {
              const pid = this.dataset.pid;
              const vid = this.dataset.vid || '';
              const delta = parseInt(this.dataset.delta, 10);

              this.disabled = true;

              const fd = new FormData();
              fd.append('_csrf', csrfToken);
              fd.append('product_id', pid);
              if (vid) fd.append('variant_id', vid);
              fd.append('delta', delta);

              fetch('ajax/stock_update.php', {
                method: 'POST',
                body: fd
              })
                .then(r => r.json())
                .then(updateRes => {
                  dBtn.disabled = false;
                  if (updateRes.ok) {
                    if (vid) {
                      const vEl = document.getElementById(`var_stock_${vid}`);
                      if (vEl) vEl.textContent = updateRes.new_stock;
                    } else {
                      const sEl = document.getElementById('simple_modal_stock');
                      if (sEl) sEl.textContent = updateRes.new_stock;
                    }
                    const totalEl = document.getElementById('modal_total_stock');
                    if (totalEl) totalEl.textContent = updateRes.total_stock;

                    // Update row badge in main table
                    const badgeCol = document.getElementById(`stock_badge_col_${pid}`);
                    if (badgeCol) {
                      const count = updateRes.total_stock;
                      let badgeHtml = count <= 0
                        ? '<span class="badge text-bg-danger">Out of stock</span>'
                        : `<span class="badge text-bg-success">${count}</span>`;
                      if (vid) {
                        badgeHtml += `<small class="text-muted d-block">updated</small>`;
                      }
                      badgeCol.innerHTML = badgeHtml;
                    }
                  } else {
                    alert(updateRes.error || 'Failed to update stock.');
                  }
                })
                .catch(err => {
                  dBtn.disabled = false;
                  alert('Network error updating stock.');
                });
            });
          });
        })
        .catch(err => {
          modalBody.innerHTML = '<div class="alert alert-danger py-2">Network error loading stock.</div>';
        });
    });
  });

  // 30-Second Admin Stock Column Auto-Refresh via api/availability.php?ids=...
  let lastStockEtag = null;
  function refreshAdminStock() {
    if (document.hidden) return;
    const badgeCols = document.querySelectorAll('[id^="stock_badge_col_"]');
    if (!badgeCols.length) return;
    const ids = Array.from(badgeCols).map(el => el.id.replace('stock_badge_col_', '')).filter(Boolean);
    if (!ids.length) return;

    const headers = { 'Accept': 'application/json' };
    if (lastStockEtag) {
      headers['If-None-Match'] = lastStockEtag;
    }

    fetch(`../api/availability.php?ids=${ids.join(',')}`, { headers })
      .then(r => {
        if (r.status === 304) return null; // Not modified
        const etag = r.headers.get('ETag');
        if (etag) lastStockEtag = etag;
        return r.ok ? r.json() : null;
      })
      .then(data => {
        if (!data || !data.products) return;
        Object.entries(data.products).forEach(([pid, p]) => {
          const col = document.getElementById(`stock_badge_col_${pid}`);
          if (!col) return;
          const stock = parseInt(p.total_stock, 10);
          const thresh = p.threshold || 5;
          let badgeHtml = '';
          if (stock <= 0) {
            badgeHtml = '<span class="badge text-bg-danger">Out of stock</span>';
          } else if (stock <= thresh) {
            badgeHtml = `<span class="badge text-bg-warning">${stock} in stock</span>`;
          } else {
            badgeHtml = `<span class="badge text-bg-success">${stock}</span>`;
          }
          if (p.type === 'variable') {
            const vc = p.variant_count || 1;
            badgeHtml += `<small class="text-muted d-block">${vc} variant${vc === 1 ? '' : 's'}</small>`;
          }
          col.innerHTML = badgeHtml;
        });
      })
      .catch(() => {});
  }

  setInterval(refreshAdminStock, 30000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshAdminStock();
  });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
