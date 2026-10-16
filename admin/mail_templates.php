<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');

$pageTitle = 'Email Templates';

$defaultTemplates = [
    'order_placed_customer' => [
        'name'      => 'Order confirmation',
        'audience'  => 'customer',
        'subject'   => 'Your order {{order_number}} is confirmed',
        'body_html' => '<h2>Thanks for your order, {{customer_name}}!</h2><p>We received order <strong>{{order_number}}</strong> and will contact you before delivery.</p>{{items_table}}<p>Subtotal: {{subtotal}}<br>Shipping: {{shipping_fee}}<br><strong>Total (Cash on Delivery): {{total}}</strong></p><p>Deliver to:<br>{{shipping_address}}</p><p>Track your order: <a href="{{track_url}}">{{track_url}}</a></p>',
        'variables' => 'order_number, customer_name, items_table, subtotal, shipping_fee, discount, total, payment_method, shipping_address, track_url, site_name',
    ],
    'order_placed_admin' => [
        'name'      => 'New order (admin alert)',
        'audience'  => 'admin',
        'subject'   => 'New order {{order_number}} - {{total}}',
        'body_html' => '<p>New order <strong>{{order_number}}</strong> from {{customer_name}} ({{customer_phone}}).</p>{{items_table}}<p>Total: {{total}} - {{payment_method}}</p><p><a href="{{admin_order_url}}">Open in admin</a></p>',
        'variables' => 'order_number, customer_name, customer_phone, customer_email, items_table, total, payment_method, admin_order_url',
    ],
    'order_processing' => [
        'name'      => 'Order processing',
        'audience'  => 'customer',
        'subject'   => 'We are preparing order {{order_number}}',
        'body_html' => '<p>Hi {{customer_name}}, your order <strong>{{order_number}}</strong> is now being prepared.</p><p><a href="{{track_url}}">Track Order</a></p>',
        'variables' => 'order_number, customer_name, track_url',
    ],
    'order_shipped' => [
        'name'      => 'Order shipped',
        'audience'  => 'customer',
        'subject'   => 'Order {{order_number}} is on the way',
        'body_html' => '<p>Hi {{customer_name}}, your order <strong>{{order_number}}</strong> has shipped via {{courier}}.</p><p>Tracking: <code>{{tracking_number}}</code></p><p>Please keep {{total}} ready for Cash on Delivery.</p><p><a href="{{track_url}}">Track Delivery</a></p>',
        'variables' => 'order_number, customer_name, courier, tracking_number, total, track_url',
    ],
    'order_completed' => [
        'name'      => 'Order delivered',
        'audience'  => 'customer',
        'subject'   => 'Order {{order_number}} delivered',
        'body_html' => '<p>Hi {{customer_name}}, your order <strong>{{order_number}}</strong> was delivered. Thank you for shopping with {{site_name}}!</p>',
        'variables' => 'order_number, customer_name, site_name',
    ],
    'order_cancelled' => [
        'name'      => 'Order cancelled',
        'audience'  => 'customer',
        'subject'   => 'Order {{order_number}} cancelled',
        'body_html' => '<p>Hi {{customer_name}}, your order <strong>{{order_number}}</strong> has been cancelled. Contact us if this is unexpected.</p>',
        'variables' => 'order_number, customer_name, site_name',
    ],
    'low_stock_admin' => [
        'name'      => 'Low stock alert',
        'audience'  => 'admin',
        'subject'   => 'Low stock: {{product_name}} {{variant_label}}',
        'body_html' => '<p><strong>{{product_name}}</strong> {{variant_label}} has only <strong>{{stock}}</strong> left (threshold {{threshold}}).</p><p><a href="{{admin_product_url}}">Restock now</a></p>',
        'variables' => 'product_name, variant_label, stock, threshold, admin_product_url',
    ],
    'test_email' => [
        'name'      => 'Test email',
        'audience'  => 'admin',
        'subject'   => 'Test email from {{site_name}}',
        'body_html' => '<p>If you can read this, your mail settings work.</p><p>Sent at {{sent_at}}.</p>',
        'variables' => 'site_name, sent_at',
    ],
];

$activeSlug = trim((string)($_GET['slug'] ?? 'order_placed_customer'));

// Handle Preview Iframe Render
if (isset($_GET['preview_slug'])) {
    $pSlug = trim((string)$_GET['preview_slug']);
    $stmt = db()->prepare("SELECT * FROM email_templates WHERE slug = :s LIMIT 1");
    $stmt->execute([':s' => $pSlug]);
    $tmpl = $stmt->fetch();

    if (!$tmpl) {
        echo "Template not found.";
        exit;
    }

    $sampleTable = '<table style="width:100%; border-collapse:collapse; margin:16px 0;">'
                 . '<tr style="border-bottom:1px solid #e4e4e7;"><td style="padding:8px 0;"><strong>Classic Cotton Tee (Black / M)</strong></td><td align="center">2</td><td align="right">৳1,600.00</td></tr>'
                 . '</table>';

    $sampleVars = [
        'order_number'      => 'ORD-202610-0042',
        'customer_name'     => 'Tanvir Ahmed',
        'customer_phone'    => '01712345678',
        'customer_email'    => 'tanvir@example.com',
        'shipping_address'  => 'House 14, Road 7, Dhanmondi, Dhaka',
        'subtotal'          => '৳1,600.00',
        'shipping_fee'      => '৳60.00',
        'discount'          => '৳0.00',
        'total'             => '৳1,660.00',
        'payment_method'    => 'CASH ON DELIVERY',
        'courier'           => 'Steadfast Courier',
        'tracking_number'   => 'SF-88991244',
        'track_url'         => rtrim(BASE_URL, '/') . '/track.php',
        'admin_order_url'   => rtrim(BASE_URL, '/') . '/admin/order_view.php?id=42',
        'admin_product_url' => rtrim(BASE_URL, '/') . '/admin/products.php',
        'product_name'      => 'Classic Cotton Tee',
        'variant_label'     => '(Size: M / Color: Black)',
        'stock'             => '2',
        'threshold'         => '5',
        'site_name'         => setting('site_name', APP_NAME),
        'sent_at'           => date('Y-m-d H:i:s'),
        'items_table'       => $sampleTable,
    ];

    $renderedHtml = $tmpl['body_html'];
    foreach ($sampleVars as $k => $v) {
        $renderedHtml = str_replace('{{' . $k . '}}', $v, $renderedHtml);
    }

    echo mail_wrap_html($renderedHtml, $tmpl['subject']);
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_template') {
        $slug     = trim((string)($_POST['slug'] ?? ''));
        $subject  = trim((string)($_POST['subject'] ?? ''));
        $bodyHtml = (string)($_POST['body_html'] ?? '');
        $enabled  = !empty($_POST['enabled']) ? 1 : 0;

        // Security: strip harmful scripts or on* handlers
        $bodyHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $bodyHtml);
        $bodyHtml = preg_replace('/\son[a-zA-Z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $bodyHtml);

        if ($slug !== '' && $subject !== '') {
            db()->prepare(
                "UPDATE email_templates
                 SET subject = :sub, body_html = :b, enabled = :en
                 WHERE slug = :s"
            )->execute([
                ':sub' => $subject,
                ':b'   => $bodyHtml,
                ':en'  => $enabled,
                ':s'   => $slug,
            ]);
            log_activity('mail.template_update', "Updated email template: {$slug}");
            flash('success', "Template saved successfully.");
        }
        admin_redirect('mail_templates.php?slug=' . urlencode($slug));
    }

    if ($action === 'reset_default') {
        $slug = trim((string)($_POST['slug'] ?? ''));
        if (isset($defaultTemplates[$slug])) {
            $def = $defaultTemplates[$slug];
            db()->prepare(
                "UPDATE email_templates
                 SET subject = :sub, body_html = :b, enabled = 1
                 WHERE slug = :s"
            )->execute([
                ':sub' => $def['subject'],
                ':b'   => $def['body_html'],
                ':s'   => $slug,
            ]);
            log_activity('mail.template_reset', "Reset email template {$slug} to defaults");
            flash('info', "Template reset to default values.");
        }
        admin_redirect('mail_templates.php?slug=' . urlencode($slug));
    }

    if ($action === 'send_sample') {
        $slug = trim((string)($_POST['slug'] ?? ''));
        $adminEmail = $_SESSION['user']['email'] ?? setting('site_email');
        if ($adminEmail && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $sampleTable = '<table style="width:100%; border-collapse:collapse; margin:16px 0;">'
                         . '<tr style="border-bottom:1px solid #e4e4e7;"><td style="padding:8px 0;"><strong>Sample Product</strong></td><td align="center">1</td><td align="right">৳850.00</td></tr>'
                         . '</table>';

            $sampleVars = [
                'order_number'      => 'ORD-SAMPLE-99',
                'customer_name'     => 'Sample Customer',
                'customer_phone'    => '01700000000',
                'customer_email'    => $adminEmail,
                'shipping_address'  => 'Dhaka, Bangladesh',
                'subtotal'          => '৳850.00',
                'shipping_fee'      => '৳60.00',
                'discount'          => '৳0.00',
                'total'             => '৳910.00',
                'payment_method'    => 'COD',
                'courier'           => 'Sample Delivery',
                'tracking_number'   => 'TRK-12345',
                'track_url'         => rtrim(BASE_URL, '/') . '/track.php',
                'admin_order_url'   => rtrim(BASE_URL, '/') . '/admin/orders.php',
                'admin_product_url' => rtrim(BASE_URL, '/') . '/admin/products.php',
                'product_name'      => 'Sample Product',
                'variant_label'     => '(Size: M)',
                'stock'             => '3',
                'threshold'         => '5',
                'site_name'         => setting('site_name', APP_NAME),
                'sent_at'           => date('Y-m-d H:i:s'),
                'items_table'       => $sampleTable,
            ];

            mail_queue_template($slug, $adminEmail, 'Admin Sample', $sampleVars, [
                'related_type' => 'test_sample',
            ]);
            flash('success', "Queued sample email to {$adminEmail}.");
        }
        admin_redirect('mail_templates.php?slug=' . urlencode($slug));
    }
}

// Fetch all templates
$templates = [];
try {
    $templates = db()->query("SELECT * FROM email_templates ORDER BY id ASC")->fetchAll();
} catch (Throwable $e) {}

$selectedTemplate = null;
foreach ($templates as $t) {
    if ($t['slug'] === $activeSlug) {
        $selectedTemplate = $t;
        break;
    }
}
if (!$selectedTemplate && $templates) {
    $selectedTemplate = $templates[0];
    $activeSlug = $selectedTemplate['slug'];
}

$variablesList = [];
if (!empty($selectedTemplate['variables'])) {
    $variablesList = array_filter(array_map('trim', explode(',', $selectedTemplate['variables'])));
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="mb-1">Email Templates</h3>
    <p class="text-muted small mb-0">Customize automated notifications sent to customers and store staff.</p>
  </div>
  <a href="<?= e(admin_url('mail_settings.php')) ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-gear me-1"></i> Mail Connection Settings
  </a>
</div>

<div class="row g-4">
  <!-- Left: Templates Navigation List -->
  <div class="col-lg-3">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white py-3 border-bottom">
        <h6 class="mb-0 fw-semibold">Available Templates (<?= count($templates) ?>)</h6>
      </div>
      <div class="list-group list-group-flush">
        <?php foreach ($templates as $tmpl): ?>
          <a href="<?= e(admin_url('mail_templates.php?slug=' . urlencode($tmpl['slug']))) ?>"
             class="list-group-item list-group-item-action py-3 <?= $tmpl['slug'] === $activeSlug ? 'active' : '' ?>">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="fw-semibold small"><?= e($tmpl['name']) ?></span>
              <span class="badge <?= $tmpl['audience'] === 'admin' ? 'bg-secondary' : 'bg-primary' ?> text-uppercase" style="font-size: 9px;">
                <?= e($tmpl['audience']) ?>
              </span>
            </div>
            <div class="small text-truncate <?= $tmpl['slug'] === $activeSlug ? 'text-white-50' : 'text-muted' ?>">
              <code>{{<?= e($tmpl['slug']) ?>}}</code>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Center & Right: Template Editor & Live Preview -->
  <?php if ($selectedTemplate): ?>
    <div class="col-lg-5">
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
          <h5 class="fw-bold mb-0">Edit: <?= e($selectedTemplate['name']) ?></h5>
          <form method="post" onsubmit="return confirm('Reset this template to factory default?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_default">
            <input type="hidden" name="slug" value="<?= e($selectedTemplate['slug']) ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm" title="Reset template">
              <i class="bi bi-arrow-counterclockwise"></i> Reset Default
            </button>
          </form>
        </div>
        <div class="card-body p-4">
          <form method="post" id="templateForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_template">
            <input type="hidden" name="slug" value="<?= e($selectedTemplate['slug']) ?>">

            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" name="enabled" value="1" <?= !empty($selectedTemplate['enabled']) ? 'checked' : '' ?> id="tmplEnabled">
              <label class="form-check-label fw-semibold" for="tmplEnabled">Template Enabled</label>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Subject Line</label>
              <input type="text" name="subject" class="form-control" value="<?= e($selectedTemplate['subject']) ?>" required>
            </div>

            <!-- Placeholder Chips Palette -->
            <?php if ($variablesList): ?>
              <div class="mb-3">
                <label class="form-label small fw-semibold text-muted text-uppercase mb-1">
                  Click Placeholder Chip to Insert
                </label>
                <div class="d-flex flex-wrap gap-1">
                  <?php foreach ($variablesList as $var): ?>
                    <button type="button" class="btn btn-sm btn-light border py-0 px-2 font-monospace"
                            style="font-size: 11px;"
                            onclick="insertChip('{{<?= e($var) ?>}}')">
                      +{{<?= e($var) ?>}}
                    </button>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <div class="mb-4">
              <label class="form-label fw-semibold">HTML Body Content</label>
              <textarea name="body_html" id="bodyHtmlTextarea" class="form-control font-monospace" rows="12" style="font-size: 13px;" required><?= e($selectedTemplate['body_html']) ?></textarea>
              <div class="form-text">Basic inline HTML allowed (p, strong, a, h2, table, br). Scripts and on* handlers are automatically stripped.</div>
            </div>

            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-save me-1"></i> Save Template
              </button>
            </div>
          </form>

          <hr class="my-4">

          <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_sample">
            <input type="hidden" name="slug" value="<?= e($selectedTemplate['slug']) ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-send me-1"></i> Send Sample to <?= e($_SESSION['user']['email'] ?? 'me') ?>
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Right: Live Preview Iframe -->
    <div class="col-lg-4">
      <div class="card shadow-sm border-0 sticky-top" style="top: 90px;">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
          <h6 class="mb-0 fw-semibold"><i class="bi bi-eye text-primary me-2"></i> Rendered Preview</h6>
          <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" onclick="document.getElementById('previewFrame').src = document.getElementById('previewFrame').src;">
            <i class="bi bi-arrow-clockwise"></i> Refresh
          </button>
        </div>
        <div class="card-body p-0">
          <iframe id="previewFrame"
                  src="mail_templates.php?preview_slug=<?= urlencode($selectedTemplate['slug']) ?>"
                  style="width: 100%; height: 540px; border: none; background: #f4f4f5;"></iframe>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
function insertChip(text) {
  const textarea = document.getElementById('bodyHtmlTextarea');
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const val = textarea.value;
  textarea.value = val.substring(0, start) + text + val.substring(end);
  textarea.selectionStart = textarea.selectionEnd = start + text.length;
  textarea.focus();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
