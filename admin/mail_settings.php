<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');

$pageTitle = 'Mail Configuration';

$cfg = mail_config();

// Queue counts
$queueStats = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0];
try {
    $rows = db()->query("SELECT status, COUNT(*) as cnt FROM email_queue GROUP BY status")->fetchAll();
    foreach ($rows as $r) {
        $queueStats[$r['status']] = (int)$r['cnt'];
    }
} catch (Throwable $e) {}

// Last sent timestamp
$lastSent = null;
try {
    $lastSent = db()->query("SELECT sent_at FROM email_queue WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1")->fetchColumn();
} catch (Throwable $e) {}

// Handle AJAX test email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_test_email') {
    require_csrf();
    header('Content-Type: application/json; charset=utf-8');

    // Rate limit: 5 test emails per minute
    $_SESSION['test_mail_attempts'] = $_SESSION['test_mail_attempts'] ?? [];
    $now = time();
    $_SESSION['test_mail_attempts'] = array_filter($_SESSION['test_mail_attempts'], function ($t) use ($now) {
        return ($now - $t) < 60;
    });

    if (count($_SESSION['test_mail_attempts']) >= 5) {
        echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded: maximum 5 test emails per minute.']);
        exit;
    }
    $_SESSION['test_mail_attempts'][] = $now;

    $recipient = trim((string)($_POST['recipient'] ?? ''));
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Please enter a valid recipient email address.']);
        exit;
    }

    $siteName = setting('site_name', APP_NAME);
    $html = mail_wrap_html(
        "<h3>Test Email Successful!</h3><p>Your mail server settings are configured properly. Sent at <strong>" . date('Y-m-d H:i:s') . "</strong>.</p>",
        "Test email from {$siteName}"
    );

    $res = mail_send_now([
        'to_email'  => $recipient,
        'to_name'   => 'Admin',
        'subject'   => "Test email from {$siteName}",
        'body_html' => $html,
    ]);

    log_activity('mail.test', "Triggered test email to {$recipient} (result: " . ($res['ok'] ? 'success' : 'failed') . ")");

    echo json_encode($res);
    exit;
}

// Handle Process Queue Now
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_queue_now') {
    require_csrf();
    $res = mail_process_queue(50);
    flash('info', "Queue processed: {$res['processed']} attempted, {$res['sent']} sent, {$res['failed']} failed.");
    admin_redirect('mail_settings.php');
}

// Handle Form Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    require_csrf();

    $rules = [
        'mail_enabled'          => ['type' => 'bool'],
        'mail_driver'           => ['type' => 'enum', 'choices' => ['smtp', 'phpmail', 'log']],
        'mail_host'             => ['type' => 'text'],
        'mail_port'             => ['type' => 'int', 'min' => 1, 'max' => 65535],
        'mail_encryption'       => ['type' => 'enum', 'choices' => ['tls', 'ssl', 'none']],
        'mail_username'         => ['type' => 'text'],
        'mail_from_email'       => ['type' => 'email'],
        'mail_from_name'        => ['type' => 'text'],
        'mail_reply_to'         => ['type' => 'email'],
        'mail_timeout'          => ['type' => 'int', 'min' => 5, 'max' => 60],
        'mail_max_attempts'     => ['type' => 'int', 'min' => 1, 'max' => 10],
        'mail_send_mode'        => ['type' => 'enum', 'choices' => ['queue', 'instant']],
        'mail_admin_notify'     => ['type' => 'bool'],
        'mail_admin_recipients' => ['type' => 'text'],
        'mail_notify_low_stock' => ['type' => 'bool'],
        'mail_footer_text'      => ['type' => 'text'],
    ];

    $res = settings_save($_POST, $rules, 'mail');

    // Password handling (write-only: empty keeps old, non-empty encrypts with app_encrypt)
    $newPass = trim((string)($_POST['mail_password'] ?? ''));
    if ($newPass !== '') {
        $encPass = app_encrypt($newPass);
        update_setting('mail_password_enc', $encPass);
        log_activity('mail.password', 'Updated mail server password');
    }

    if ($res['errors']) {
        foreach ($res['errors'] as $e) flash('danger', $e);
    } else {
        flash('success', 'Mail settings saved.');
    }
    admin_redirect('mail_settings.php');
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="mb-1">Mail System Configuration</h3>
    <p class="text-muted small mb-0">Configure SMTP credentials, manage transactional email templates, and monitor delivery queue.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= e(admin_url('mail_templates.php')) ?>" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-file-earmark-code me-1"></i> Email Templates
    </a>
    <a href="<?= e(admin_url('mail_queue.php')) ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-list-columns-reverse me-1"></i> Mail Queue (<?= $queueStats['pending'] + $queueStats['failed'] ?>)
    </a>
  </div>
</div>

<!-- Warning Badges -->
<?php if (defined('APP_KEY') && APP_KEY === 'CHANGE_ME_TO_64_RANDOM_CHARS'): ?>
  <div class="alert alert-danger mb-4">
    <i class="bi bi-shield-exclamation me-1"></i>
    <strong>Security Alert:</strong> <code>APP_KEY</code> is set to the default placeholder. Please update <code>.env</code> or <code>config/config.php</code> to secure encrypted mail credentials.
  </div>
<?php endif; ?>

<?php if ($cfg['port'] == 465 && $cfg['encryption'] === 'tls'): ?>
  <div class="alert alert-warning mb-4">
    <i class="bi bi-exclamation-triangle me-1"></i> Port 465 typically uses SSL encryption rather than TLS. Check your provider's documentation.
  </div>
<?php endif; ?>

<!-- Health & Queue Panel -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-md-3">
    <div class="card stat-card shadow-sm border-0">
      <div class="stat-icon bg-indigo"><i class="bi bi-hourglass-split"></i></div>
      <div>
        <p class="stat-label">Pending in Queue</p>
        <p class="stat-value"><?= number_format($queueStats['pending']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="card stat-card shadow-sm border-0">
      <div class="stat-icon bg-emerald"><i class="bi bi-check2-circle"></i></div>
      <div>
        <p class="stat-label">Successfully Sent</p>
        <p class="stat-value"><?= number_format($queueStats['sent']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="card stat-card shadow-sm border-0">
      <div class="stat-icon bg-rose"><i class="bi bi-exclamation-octagon"></i></div>
      <div>
        <p class="stat-label">Failed Deliveries</p>
        <p class="stat-value text-danger"><?= number_format($queueStats['failed']) ?></p>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="card stat-card shadow-sm border-0 d-flex flex-column justify-content-center p-3">
      <div class="small text-muted">Last Sent: <strong><?= $lastSent ? date('M j, g:i a', strtotime($lastSent)) : 'Never' ?></strong></div>
      <form method="post" class="mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="process_queue_now">
        <button type="submit" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-arrow-repeat me-1"></i> Process Queue Now
        </button>
      </form>
    </div>
  </div>
</div>

<form method="post" id="mailSettingsForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_settings">

  <div class="row g-4">
    <!-- Connection Card -->
    <div class="col-lg-8">
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
          <h5 class="fw-bold mb-0"><i class="bi bi-hdd-network text-primary me-2"></i> SMTP Connection</h5>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" name="mail_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?> id="mailEnabledToggle">
            <label class="form-check-label fw-bold" for="mailEnabledToggle">Enable Mail System</label>
          </div>
        </div>
        <div class="card-body p-4">
          <!-- Provider Presets -->
          <div class="mb-4 p-3 bg-light rounded border">
            <label class="form-label small fw-semibold text-muted text-uppercase mb-1">
              <i class="bi bi-lightning-charge text-warning"></i> Load Provider Preset
            </label>
            <select id="presetSelect" class="form-select form-select-sm" onchange="applyPreset(this.value)">
              <option value="">-- Choose a pre-configured provider --</option>
              <option value="gmail">Gmail (smtp.gmail.com - Port 587 - TLS) [App Password required]</option>
              <option value="outlook">Outlook / Office 365 (smtp.office365.com - Port 587 - TLS)</option>
              <option value="zoho">Zoho Mail (smtp.zoho.com - Port 587 - TLS)</option>
              <option value="brevo">Brevo / Sendinblue (smtp-relay.brevo.com - Port 587 - TLS)</option>
              <option value="mailgun">Mailgun (smtp.mailgun.org - Port 587 - TLS)</option>
              <option value="ses">Amazon SES (email-smtp.us-east-1.amazonaws.com - Port 587 - TLS)</option>
              <option value="sendgrid">SendGrid (smtp.sendgrid.net - Port 587 - TLS)</option>
            </select>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Mail Driver</label>
              <select name="mail_driver" id="mailDriver" class="form-select">
                <option value="smtp" <?= $cfg['driver'] === 'smtp' ? 'selected' : '' ?>>SMTP (Recommended for production)</option>
                <option value="phpmail" <?= $cfg['driver'] === 'phpmail' ? 'selected' : '' ?>>PHP mail() (Local server agent)</option>
                <option value="log" <?= $cfg['driver'] === 'log' ? 'selected' : '' ?>>Log Only (Development: logs without sending)</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Send Mode</label>
              <select name="mail_send_mode" class="form-select">
                <option value="queue" <?= $cfg['send_mode'] === 'queue' ? 'selected' : '' ?>>Queue (Background cron runner - recommended)</option>
                <option value="instant" <?= $cfg['send_mode'] === 'instant' ? 'selected' : '' ?>>Instant (Send in shutdown handler)</option>
              </select>
            </div>

            <div class="col-md-8">
              <label class="form-label fw-semibold">SMTP Host</label>
              <input type="text" name="mail_host" id="mailHost" class="form-control" value="<?= e($cfg['host']) ?>" placeholder="e.g. smtp.gmail.com">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">SMTP Port</label>
              <input type="number" name="mail_port" id="mailPort" class="form-control" value="<?= e($cfg['port']) ?>" placeholder="587">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Encryption</label>
              <select name="mail_encryption" id="mailEnc" class="form-select">
                <option value="tls" <?= $cfg['encryption'] === 'tls' ? 'selected' : '' ?>>TLS (Port 587)</option>
                <option value="ssl" <?= $cfg['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                <option value="none" <?= $cfg['encryption'] === 'none' ? 'selected' : '' ?>>None</option>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Timeout (Seconds)</label>
              <input type="number" name="mail_timeout" class="form-control" min="5" max="60" value="<?= e($cfg['timeout']) ?>">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Max Delivery Retries</label>
              <input type="number" name="mail_max_attempts" class="form-control" min="1" max="10" value="<?= e($cfg['max_attempts']) ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">SMTP Username</label>
              <input type="text" name="mail_username" class="form-control" value="<?= e($cfg['username']) ?>" placeholder="e.g. yourname@gmail.com">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">SMTP Password</label>
              <input type="password" name="mail_password" class="form-control" placeholder="<?= !empty($cfg['password']) ? '•••••••• (saved - leave empty to keep)' : 'Enter password or App Password' ?>">
            </div>

            <hr class="my-3">

            <div class="col-md-6">
              <label class="form-label fw-semibold">From Email Address</label>
              <input type="email" name="mail_from_email" class="form-control" value="<?= e($cfg['from_email']) ?>" placeholder="orders@yourstore.com">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">From Name</label>
              <input type="text" name="mail_from_name" class="form-control" value="<?= e($cfg['from_name']) ?>" placeholder="Your Store Name">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Reply-To Email</label>
              <input type="email" name="mail_reply_to" class="form-control" value="<?= e($cfg['reply_to']) ?>" placeholder="support@yourstore.com">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Email Footer Note</label>
              <input type="text" name="mail_footer_text" class="form-control" value="<?= e($cfg['footer_text']) ?>" placeholder="Dhaka, Bangladesh · support@store.com">
            </div>
          </div>

          <div class="mt-4">
            <button type="submit" class="btn btn-primary px-4">
              <i class="bi bi-save me-1"></i> Save Mail Configuration
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Right: Test Mail & Notifications Cards -->
    <div class="col-lg-4">
      <!-- Test Email Card -->
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom">
          <h6 class="mb-0 fw-semibold"><i class="bi bi-send text-primary me-2"></i> Send Test Email</h6>
        </div>
        <div class="card-body">
          <p class="small text-muted">
            Send an instant test email using your current configuration to verify SMTP connectivity and authentication.
          </p>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Recipient Email</label>
            <input type="email" id="testRecipient" class="form-control form-control-sm" value="<?= e($_SESSION['user']['email'] ?? 'admin@example.com') ?>" required>
          </div>

          <button type="button" id="btnSendTest" class="btn btn-outline-primary btn-sm w-100" onclick="sendTestEmail()">
            <i class="bi bi-send-check me-1"></i> Send Test Email Now
          </button>

          <!-- Test Result Box -->
          <div id="testResultBox" class="mt-3" style="display: none;"></div>

          <!-- Collapsible SMTP Debug Log -->
          <div id="debugLogContainer" class="mt-2" style="display: none;">
            <a class="btn btn-link btn-sm text-decoration-none p-0" data-bs-toggle="collapse" href="#debugLogCollapse">
              <i class="bi bi-terminal me-1"></i> View SMTP Transcript
            </a>
            <div class="collapse mt-2" id="debugLogCollapse">
              <pre id="debugLogText" class="p-2 bg-dark text-success small rounded" style="max-height: 200px; overflow-y: auto; font-size: 11px;"></pre>
            </div>
          </div>
        </div>
      </div>

      <!-- Notifications Settings Card -->
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom">
          <h6 class="mb-0 fw-semibold"><i class="bi bi-bell text-primary me-2"></i> Notification Alerts</h6>
        </div>
        <div class="card-body">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="mail_admin_notify" value="1" <?= $cfg['admin_notify'] ? 'checked' : '' ?> id="adminNotifyToggle">
            <label class="form-check-label small fw-semibold" for="adminNotifyToggle">Admin New Order Alerts</label>
          </div>

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="mail_notify_low_stock" value="1" <?= $cfg['notify_low_stock'] ? 'checked' : '' ?> id="lowStockToggle">
            <label class="form-check-label small fw-semibold" for="lowStockToggle">Admin Low Stock Alerts</label>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold">Admin Recipient Emails</label>
            <input type="text" name="mail_admin_recipients" class="form-control form-control-sm" value="<?= e($cfg['admin_recipients']) ?>" placeholder="admin@store.com, manager@store.com">
            <div class="form-text" style="font-size: 11px;">Comma-separated list of administrative notification recipients.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

<script>
function applyPreset(val) {
  const presets = {
    gmail: { host: 'smtp.gmail.com', port: 587, enc: 'tls' },
    outlook: { host: 'smtp.office365.com', port: 587, enc: 'tls' },
    zoho: { host: 'smtp.zoho.com', port: 587, enc: 'tls' },
    brevo: { host: 'smtp-relay.brevo.com', port: 587, enc: 'tls' },
    mailgun: { host: 'smtp.mailgun.org', port: 587, enc: 'tls' },
    ses: { host: 'email-smtp.us-east-1.amazonaws.com', port: 587, enc: 'tls' },
    sendgrid: { host: 'smtp.sendgrid.net', port: 587, enc: 'tls' }
  };
  if (presets[val]) {
    document.getElementById('mailHost').value = presets[val].host;
    document.getElementById('mailPort').value = presets[val].port;
    document.getElementById('mailEnc').value = presets[val].enc;
    document.getElementById('mailDriver').value = 'smtp';
  }
}

function sendTestEmail() {
  const btn = document.getElementById('btnSendTest');
  const recipient = document.getElementById('testRecipient').value.trim();
  const resBox = document.getElementById('testResultBox');
  const debugCont = document.getElementById('debugLogContainer');
  const debugText = document.getElementById('debugLogText');

  if (!recipient) {
    alert('Please enter a recipient email.');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';
  resBox.style.display = 'none';
  debugCont.style.display = 'none';

  const formData = new FormData();
  formData.append('csrf_token', '<?= csrf_token() ?>');
  formData.append('action', 'send_test_email');
  formData.append('recipient', recipient);

  fetch('mail_settings.php', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send-check me-1"></i> Send Test Email Now';
    resBox.style.display = 'block';

    if (data.ok) {
      resBox.className = 'alert alert-success py-2 small mb-0';
      resBox.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Test email successfully dispatched!';
    } else {
      resBox.className = 'alert alert-danger py-2 small mb-0';
      resBox.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> <strong>Failed:</strong> ' + (data.error || 'Unknown error');
    }

    if (data.debug) {
      debugText.textContent = data.debug;
      debugCont.style.display = 'block';
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send-check me-1"></i> Send Test Email Now';
    resBox.style.display = 'block';
    resBox.className = 'alert alert-danger py-2 small mb-0';
    resBox.textContent = 'Network error while attempting test send: ' + err;
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
