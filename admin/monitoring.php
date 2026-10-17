<?php
/**
 * System Monitoring & Infrastructure Dashboard
 * Real-time CPU, RAM, Disk, Network, Bandwidth, Database & PHP health.
 */
require_once __DIR__ . '/includes/auth.php';
require_role('admin');

$pageTitle = 'Server Monitoring';
$pdo = db();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'run_collector') {
        $collectorScript = __DIR__ . '/../cron/collect_metrics.php';
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($collectorScript) . ' --force --verbose 2>&1';
        $output = shell_exec($cmd);
        log_activity('monitoring.manual_run', 'Triggered manual metrics collection');
        flash('success', 'Metrics collector executed successfully.');
        admin_redirect('monitoring.php');
    }

    if ($action === 'save_rule') {
        $ruleId    = (int)($_POST['rule_id'] ?? 0);
        $metric    = trim((string)($_POST['metric'] ?? ''));
        $operator  = in_array($_POST['operator'] ?? '', ['>', '<'], true) ? $_POST['operator'] : '>';
        $threshold = max(0, (float)($_POST['threshold'] ?? 0));
        $forMin    = max(1, min(60, (int)($_POST['for_minutes'] ?? 5)));
        $notify    = !empty($_POST['notify_email']) ? 1 : 0;
        $enabled   = !empty($_POST['enabled']) ? 1 : 0;

        $allowedMetrics = ['cpu_percent', 'mem_percent', 'disk_percent', 'load_1', 'db_threads', 'error_rate'];
        if (in_array($metric, $allowedMetrics, true) && $threshold > 0) {
            if ($ruleId > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE monitor_alert_rules
                     SET metric = :m, operator = :op, threshold = :th, for_minutes = :fm, notify_email = :ne, enabled = :en
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':m'  => $metric,
                    ':op' => $operator,
                    ':th' => $threshold,
                    ':fm' => $forMin,
                    ':ne' => $notify,
                    ':en' => $enabled,
                    ':id' => $ruleId,
                ]);
                flash('success', 'Alert rule updated.');
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO monitor_alert_rules (metric, operator, threshold, for_minutes, notify_email, enabled)
                     VALUES (:m, :op, :th, :fm, :ne, :en)"
                );
                $stmt->execute([
                    ':m'  => $metric,
                    ':op' => $operator,
                    ':th' => $threshold,
                    ':fm' => $forMin,
                    ':ne' => $notify,
                    ':en' => $enabled,
                ]);
                flash('success', 'New alert rule created.');
            }
        } else {
            flash('danger', 'Invalid metric or threshold values provided.');
        }
        admin_redirect('monitoring.php#alertsTab');
    }

    if ($action === 'delete_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0) {
            $pdo->prepare("DELETE FROM monitor_alert_rules WHERE id = :id")->execute([':id' => $ruleId]);
            flash('info', 'Alert rule removed.');
        }
        admin_redirect('monitoring.php#alertsTab');
    }

    if ($action === 'test_fire_alert') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $adminEmail = setting('mail_admin_recipients', setting('site_email', 'admin@example.com'));
        $subject = "[TEST ALERT] Server Monitoring Alert Simulation";
        $body = "<h3>Test Alert Notification</h3><p>This is a simulated alert from Server Monitoring. Your notification pipeline is operational.</p><p>Timestamp: <strong>" . date('Y-m-d H:i:s') . "</strong></p>";

        if (function_exists('mail_queue')) {
            mail_queue($adminEmail, $subject, mail_wrap_html($body, $subject));
            flash('success', "Test alert email queued for {$adminEmail}. Check Mail Queue.");
        } else {
            flash('warning', 'Mailer module not found.');
        }
        admin_redirect('monitoring.php#alertsTab');
    }

    if ($action === 'purge_data') {
        $days = max(1, (int)($_POST['retention_days'] ?? 7));
        $pdo->query("DELETE FROM system_metrics WHERE collected_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
        $pdo->query("DELETE FROM network_samples WHERE collected_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
        $pdo->query("DELETE FROM traffic_minute WHERE bucket < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
        $pdo->query("DELETE FROM slow_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
        flash('success', "Purged monitoring records older than {$days} days.");
        admin_redirect('monitoring.php');
    }
}

// Check collector status
$latestSample = $pdo->query("SELECT * FROM system_metrics ORDER BY id DESC LIMIT 1")->fetch() ?: [];
$lastRunTs = !empty($latestSample['collected_at']) ? strtotime($latestSample['collected_at']) : 0;
$collectorLag = time() - $lastRunTs;
$isCollectorRunning = ($lastRunTs > 0 && $collectorLag <= 180);

// Alert Rules
$alertRules = [];
try {
    $alertRules = $pdo->query("SELECT * FROM monitor_alert_rules ORDER BY id ASC")->fetchAll();
} catch (Throwable $e) {}

// Server Information
$phpInfo = [
    'version'       => PHP_VERSION,
    'sapi'          => PHP_SAPI,
    'memory_limit'  => ini_get('memory_limit'),
    'max_exec'      => ini_get('max_execution_time') . 's',
    'upload_max'    => ini_get('upload_max_filesize'),
    'post_max'      => ini_get('post_max_size'),
    'opcache'       => function_exists('opcache_get_status') && !empty(opcache_get_status(false)['opcache_enabled']),
    'pdo_mysql'     => extension_loaded('pdo_mysql'),
    'openssl'       => extension_loaded('openssl'),
    'mbstring'      => extension_loaded('mbstring'),
    'gd'            => extension_loaded('gd'),
    'intl'          => extension_loaded('intl'),
    'curl'          => extension_loaded('curl'),
];

// MySQL Information
$dbVersion = 'Unknown';
$dbSize = 0;
$topTables = [];
try {
    $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn() ?: 'MySQL';
    $dbSize = (int)$pdo->query("SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetchColumn();
    $topTablesRaw = $pdo->query(
        "SELECT TABLE_NAME, (DATA_LENGTH + INDEX_LENGTH) as size_bytes, TABLE_ROWS
         FROM information_schema.TABLES
         WHERE table_schema = DATABASE()
         ORDER BY size_bytes DESC
         LIMIT 6"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($topTablesRaw as $row) {
        $topTables[] = array_change_key_case($row, CASE_LOWER);
    }
} catch (Throwable $e) {}

// Storage & uploads breakdown
$uploadsPath = UPLOADS_PATH;
$uploadsSize = 0;
if (is_dir($uploadsPath)) {
    try {
        $sizeIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsPath, FilesystemIterator::SKIP_DOTS));
        foreach ($sizeIterator as $file) {
            $uploadsSize += $file->getSize();
        }
    } catch (Throwable $e) {}
}

$externalUrl = setting('monitor_external_url', '');

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h4 class="fw-bold mb-1"><i class="bi bi-cpu-fill text-primary me-2"></i> Infrastructure & Server Health</h4>
    <p class="text-muted small mb-0">Real-time metrics, host resource utilization, database latency, and application bandwidth.</p>
  </div>

  <div class="d-flex flex-wrap align-items-center gap-2">
    <!-- Time Range Selector -->
    <div class="btn-group btn-group-sm" role="group" id="rangeButtonGroup">
      <button type="button" class="btn btn-outline-secondary range-btn" data-range="live">Live (5s)</button>
      <button type="button" class="btn btn-primary range-btn active" data-range="1h">1h</button>
      <button type="button" class="btn btn-outline-secondary range-btn" data-range="6h">6h</button>
      <button type="button" class="btn btn-outline-secondary range-btn" data-range="24h">24h</button>
      <button type="button" class="btn btn-outline-secondary range-btn" data-range="7d">7d</button>
      <button type="button" class="btn btn-outline-secondary range-btn" data-range="30d">30d</button>
    </div>

    <!-- Interface Selector -->
    <select id="ifaceSelect" class="form-select form-select-sm" style="width: auto;">
      <option value="auto">Interface: Auto</option>
    </select>

    <!-- Auto Refresh Toggle -->
    <button type="button" class="btn btn-outline-secondary btn-sm" id="autoRefreshBtn" title="Toggle Auto Refresh">
      <i class="bi bi-arrow-repeat spin-on-active"></i> <span id="autoRefreshLabel">Auto (30s)</span>
    </button>

    <!-- Export CSV -->
    <a href="#" id="exportCsvBtn" class="btn btn-outline-secondary btn-sm" title="Download CSV dataset">
      <i class="bi bi-file-earmark-spreadsheet"></i> CSV
    </a>

    <!-- Manual Run Collector -->
    <form method="post" class="d-inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="run_collector">
      <button type="submit" class="btn btn-light btn-sm border" title="Execute metrics collection immediately">
        <i class="bi bi-play-circle text-primary"></i> Run Now
      </button>
    </form>
  </div>
</div>

<?php if (!$isCollectorRunning): ?>
  <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center justify-content-between mb-4">
    <div class="d-flex align-items-center">
      <i class="bi bi-exclamation-triangle-fill fs-4 text-warning me-3"></i>
      <div>
        <h6 class="fw-bold mb-0">Metrics Collector Not Running</h6>
        <div class="small text-muted">
          Last sample recorded: <?= $lastRunTs ? format_date($latestSample['collected_at']) : 'Never' ?>.
          Add the following cron task to your Linux server (or Windows Task Scheduler) to enable automatic sampling:
          <code class="d-block mt-1 user-select-all bg-white p-1 rounded border">* * * * * php <?= e(realpath(__DIR__ . '/../cron/collect_metrics.php')) ?> >/dev/null 2>&1</code>
        </div>
      </div>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="run_collector">
      <button type="submit" class="btn btn-warning btn-sm fw-semibold">
        <i class="bi bi-play-fill"></i> Run Collector Now
      </button>
    </form>
  </div>
<?php endif; ?>

<!-- Stat Tiles Row -->
<div class="row g-3 mb-4">
  <!-- CPU % -->
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card h-100 shadow-sm border-0 stat-tile" id="tileCpuCard">
      <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
          <span>CPU Utilization</span>
          <i class="bi bi-cpu fs-5"></i>
        </div>
        <div class="d-flex align-items-baseline gap-2">
          <span class="fs-4 fw-bold" id="tileCpuVal">--%</span>
          <span class="small text-muted" id="tileCpuCores">(-- cores)</span>
        </div>
        <div class="progress mt-2" style="height: 5px;">
          <div class="progress-bar bg-success" id="tileCpuBar" style="width: 0%;"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Load Average -->
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card h-100 shadow-sm border-0 stat-tile">
      <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
          <span>Load (1/5/15)</span>
          <i class="bi bi-speedometer fs-5"></i>
        </div>
        <div class="fs-5 fw-bold" id="tileLoadVal">-- / -- / --</div>
        <div class="small text-muted mt-1" id="tileLoadStatus">Host capacity</div>
      </div>
    </div>
  </div>

  <!-- RAM Memory -->
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card h-100 shadow-sm border-0 stat-tile" id="tileMemCard">
      <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
          <span>RAM Used</span>
          <i class="bi bi-memory fs-5"></i>
        </div>
        <div class="d-flex align-items-baseline gap-2">
          <span class="fs-4 fw-bold" id="tileMemVal">--%</span>
          <span class="small text-muted" id="tileMemSub">(-- / -- GB)</span>
        </div>
        <div class="progress mt-2" style="height: 5px;">
          <div class="progress-bar bg-info" id="tileMemBar" style="width: 0%;"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Disk Storage -->
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card h-100 shadow-sm border-0 stat-tile" id="tileDiskCard">
      <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
          <span>Disk Usage</span>
          <i class="bi bi-hdd-network fs-5"></i>
        </div>
        <div class="d-flex align-items-baseline gap-2">
          <span class="fs-4 fw-bold" id="tileDiskVal">--%</span>
          <span class="small text-muted" id="tileDiskSub">(-- / -- GB)</span>
        </div>
        <div class="progress mt-2" style="height: 5px;">
          <div class="progress-bar bg-primary" id="tileDiskBar" style="width: 0%;"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Network Rate -->
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card h-100 shadow-sm border-0 stat-tile">
      <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
          <span>Network I/O</span>
          <i class="bi bi-arrow-down-up fs-5"></i>
        </div>
        <div class="small fw-bold">↓ <span id="tileNetRx">0 KB/s</span></div>
        <div class="small fw-bold text-muted mt-1">↑ <span id="tileNetTx">0 KB/s</span></div>
      </div>
    </div>
  </div>

  <!-- Database & Traffic -->
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card h-100 shadow-sm border-0 stat-tile">
      <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
          <span>Traffic & MySQL</span>
          <i class="bi bi-database fs-5"></i>
        </div>
        <div class="d-flex justify-content-between">
          <span class="small text-muted">QPS:</span>
          <span class="fw-bold" id="tileDbQps">--</span>
        </div>
        <div class="d-flex justify-content-between mt-1">
          <span class="small text-muted">Req/m:</span>
          <span class="fw-bold" id="tileReqMin">--</span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Charts Grid -->
<div class="row g-4 mb-4">
  <!-- Chart 1: CPU Utilization & Load Average -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-activity text-primary me-2"></i> CPU % & Load Average</h6>
        <span class="badge text-bg-light border text-muted">Dual Y-Axis</span>
      </div>
      <div class="card-body">
        <div style="height: 250px;">
          <canvas id="chartCpuLoad"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Chart 2: Memory & Swap Area -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-layers text-primary me-2"></i> Memory & Swap Utilization</h6>
        <span class="badge text-bg-light border text-muted">Area (%)</span>
      </div>
      <div class="card-body">
        <div style="height: 250px;">
          <canvas id="chartMemory"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Chart 3: Network Throughput -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-ethernet text-primary me-2"></i> Network Throughput (RX / TX)</h6>
        <span class="badge text-bg-light border text-muted" id="chartIfaceLabel">Interface: auto</span>
      </div>
      <div class="card-body">
        <div style="height: 250px;">
          <canvas id="chartNetwork"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Chart 4: Application Bandwidth & Monthly Quota -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-bar-chart-fill text-primary me-2"></i> Application Bandwidth (In / Out)</h6>
        <span class="badge text-bg-light border text-muted" id="quotaHeaderBadge">Month-to-date</span>
      </div>
      <div class="card-body">
        <div id="quotaContainer" class="mb-3 d-none">
          <div class="d-flex justify-content-between small text-muted mb-1">
            <span>Monthly Quota Usage</span>
            <span id="quotaText">0 / 0 GB (0%)</span>
          </div>
          <div class="progress" style="height: 8px;">
            <div class="progress-bar bg-success" id="quotaBar" style="width: 0%;"></div>
          </div>
        </div>
        <div style="height: 220px;">
          <canvas id="chartBandwidth"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Chart 5: Requests/min & Response Latency -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-stopwatch text-primary me-2"></i> Requests / min & Avg Response Time</h6>
        <span class="badge text-bg-light border text-muted">ms Latency</span>
      </div>
      <div class="card-body">
        <div style="height: 250px;">
          <canvas id="chartRequests"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Chart 6: HTTP Response Status Mix -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-pie-chart text-primary me-2"></i> HTTP Response Status Mix</h6>
        <span class="badge text-bg-light border text-muted">2xx / 3xx / 4xx / 5xx</span>
      </div>
      <div class="card-body">
        <div style="height: 250px;">
          <canvas id="chartStatus"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Tabs Section: Slow Requests, Server Environment, Alert Rules, Integrations -->
<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-white p-0 border-bottom">
    <ul class="nav nav-tabs px-3 pt-2" id="monitorTabs" role="tablist">
      <li class="nav-item">
        <button class="nav-link active fw-medium" data-bs-toggle="tab" data-bs-target="#slowReqTab">
          <i class="bi bi-clock-history me-1"></i> Slow Requests (<span id="slowReqCount">0</span>)
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link fw-medium" data-bs-toggle="tab" data-bs-target="#diagnosticsTab">
          <i class="bi bi-hdd-stack me-1"></i> System & Storage Diagnostics
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link fw-medium" id="alertsTabLink" data-bs-toggle="tab" data-bs-target="#alertsTab">
          <i class="bi bi-bell me-1"></i> Alert Rules (<?= count($alertRules) ?>)
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link fw-medium" data-bs-toggle="tab" data-bs-target="#externalTab">
          <i class="bi bi-box-arrow-up-right me-1"></i> External Integrations
        </button>
      </li>
    </ul>
  </div>

  <div class="card-body p-4">
    <div class="tab-content">
      <!-- TAB 1: SLOW REQUESTS -->
      <div class="tab-pane fade show active" id="slowReqTab">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="fw-bold mb-0">Recent Slow Requests (Threshold: <?= e(setting('monitor_slow_request_ms', '1500')) ?> ms)</h6>
          <span class="text-muted small">Capped at top 10 recent transactions</span>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Time</th>
                <th>Method</th>
                <th>Request Path</th>
                <th>Status</th>
                <th>Duration</th>
                <th>Queries</th>
                <th>Peak RAM</th>
              </tr>
            </thead>
            <tbody id="slowRequestsTableBody">
              <tr><td colspan="7" class="text-center text-muted py-3">No slow requests recorded.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 2: SYSTEM & STORAGE DIAGNOSTICS -->
      <div class="tab-pane fade" id="diagnosticsTab">
        <div class="row g-4">
          <!-- PHP Configuration -->
          <div class="col-md-6 col-xl-4">
            <div class="card h-100 border">
              <div class="card-header bg-light py-2 fw-semibold">
                <i class="bi bi-code-slash text-primary me-2"></i> PHP Runtime Environment
              </div>
              <div class="card-body p-3">
                <table class="table table-sm table-borderless mb-0">
                  <tr><td class="text-muted">PHP Version:</td><td class="fw-bold text-end"><?= e($phpInfo['version']) ?> (<?= e($phpInfo['sapi']) ?>)</td></tr>
                  <tr><td class="text-muted">Memory Limit:</td><td class="fw-bold text-end"><?= e($phpInfo['memory_limit']) ?></td></tr>
                  <tr><td class="text-muted">Max Execution Time:</td><td class="fw-bold text-end"><?= e($phpInfo['max_exec']) ?></td></tr>
                  <tr><td class="text-muted">Upload Max Filesize:</td><td class="fw-bold text-end"><?= e($phpInfo['upload_max']) ?></td></tr>
                  <tr><td class="text-muted">Post Max Size:</td><td class="fw-bold text-end"><?= e($phpInfo['post_max']) ?></td></tr>
                  <tr>
                    <td class="text-muted">OPcache Enabled:</td>
                    <td class="text-end">
                      <?= $phpInfo['opcache'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Disabled</span>' ?>
                    </td>
                  </tr>
                </table>
                <hr class="my-2">
                <div class="small fw-semibold mb-2">Required Extensions:</div>
                <div class="d-flex flex-wrap gap-1">
                  <?php foreach (['pdo_mysql', 'openssl', 'mbstring', 'gd', 'intl', 'curl'] as $ext): ?>
                    <span class="badge <?= extension_loaded($ext) ? 'text-bg-success' : 'text-bg-danger' ?>">
                      <?= $ext ?> <?= extension_loaded($ext) ? '✔' : '✖' ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- MySQL Database -->
          <div class="col-md-6 col-xl-4">
            <div class="card h-100 border">
              <div class="card-header bg-light py-2 fw-semibold">
                <i class="bi bi-database-check text-primary me-2"></i> Database Diagnostics
              </div>
              <div class="card-body p-3">
                <table class="table table-sm table-borderless mb-0">
                  <tr><td class="text-muted">Database Engine:</td><td class="fw-bold text-end"><?= e($dbVersion) ?></td></tr>
                  <tr><td class="text-muted">Total DB Size:</td><td class="fw-bold text-end"><?= round($dbSize / 1048576, 2) ?> MB</td></tr>
                  <tr><td class="text-muted">Active Connections:</td><td class="fw-bold text-end" id="dbThreadsSpan"><?= e($latestSample['db_threads'] ?? 1) ?></td></tr>
                </table>
                <hr class="my-2">
                <div class="small fw-semibold mb-2">Largest Database Tables:</div>
                <ul class="list-group list-group-flush small">
                  <?php foreach ($topTables as $tbl): ?>
                    <li class="list-group-item d-flex justify-content-between px-0 py-1">
                      <span><?= e($tbl['table_name']) ?> <span class="text-muted">(<?= number_format((int)$tbl['table_rows']) ?> rows)</span></span>
                      <strong><?= round((float)$tbl['size_bytes'] / 1048576, 2) ?> MB</strong>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
          </div>

          <!-- Storage Breakdown & Cleanup -->
          <div class="col-12 col-xl-4">
            <div class="card h-100 border">
              <div class="card-header bg-light py-2 fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-archive text-primary me-2"></i> Storage Breakdown</span>
              </div>
              <div class="card-body p-3">
                <table class="table table-sm table-borderless mb-0">
                  <tr><td class="text-muted">Uploads Directory:</td><td class="fw-bold text-end"><?= round($uploadsSize / 1048576, 2) ?> MB</td></tr>
                  <tr><td class="text-muted">Metrics Retention:</td><td class="fw-bold text-end"><?= e(setting('monitor_retention_days', '14')) ?> Days</td></tr>
                </table>

                <hr class="my-3">
                <h6 class="fw-semibold small">Manual Data Retention Cleanup</h6>
                <form method="post" onsubmit="return confirm('Purge historical monitoring and traffic logs older than selected days?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="purge_data">
                  <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text">Keep Last</span>
                    <input type="number" name="retention_days" class="form-control" value="7" min="1" max="90">
                    <span class="input-group-text">Days</span>
                    <button type="submit" class="btn btn-outline-danger">Purge</button>
                  </div>
                  <div class="form-text">Deletes rows from system_metrics, traffic_minute, and slow_requests.</div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB 3: ALERT RULES -->
      <div class="tab-pane fade" id="alertsTab">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h6 class="fw-bold mb-0">System Health Alert Triggers</h6>
            <div class="small text-muted">Evaluated on every collector pass. Notifications dispatched to admin recipients.</div>
          </div>
          <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#ruleModal" onclick="prepareAddRule()">
            <i class="bi bi-plus-circle me-1"></i> Add Alert Rule
          </button>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Metric</th>
                <th>Condition</th>
                <th>Duration Threshold</th>
                <th>Email Dispatch</th>
                <th>Status</th>
                <th>Last Triggered</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($alertRules as $rule): ?>
                <tr>
                  <td><strong><?= e(str_replace('_', ' ', strtoupper($rule['metric']))) ?></strong></td>
                  <td>
                    <span class="badge bg-secondary font-monospace"><?= e($rule['operator']) ?> <?= e($rule['threshold']) ?><?= str_contains($rule['metric'], 'percent') ? '%' : '' ?></span>
                  </td>
                  <td>for ≥ <?= (int)$rule['for_minutes'] ?> min</td>
                  <td>
                    <?= $rule['notify_email'] ? '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Enabled</span>' : '<span class="text-muted">None</span>' ?>
                  </td>
                  <td>
                    <span class="badge <?= $rule['enabled'] ? 'bg-success' : 'bg-secondary' ?>">
                      <?= $rule['enabled'] ? 'Active' : 'Disabled' ?>
                    </span>
                  </td>
                  <td class="small text-muted">
                    <?= !empty($rule['last_fired_at']) ? format_date($rule['last_fired_at']) : 'Never' ?>
                  </td>
                  <td class="text-end">
                    <form method="post" class="d-inline" onsubmit="return confirm('Send a test alert email?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="test_fire_alert">
                      <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                      <button type="submit" class="btn btn-outline-secondary btn-sm" title="Test Alert Email">
                        <i class="bi bi-send"></i> Test
                      </button>
                    </form>

                    <button type="button" class="btn btn-light btn-sm border" onclick='editRule(<?= json_encode($rule) ?>)'>
                      <i class="bi bi-pencil"></i>
                    </button>

                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this alert rule?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete_rule">
                      <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 4: EXTERNAL INTEGRATIONS -->
      <div class="tab-pane fade" id="externalTab">
        <div class="row g-4">
          <div class="col-lg-6">
            <h6 class="fw-bold mb-2"><i class="bi bi-link-45deg text-primary me-2"></i> Dedicated APM / Monitoring Add-ons</h6>
            <p class="text-muted small">
              This panel functions independently with zero third-party dependencies. If you prefer high-frequency (1-second) kernel metrics or Prometheus dashboards, you can run open-source daemons on your server and link them here.
            </p>

            <div class="card bg-light border-0 mb-3 p-3">
              <h6 class="fw-bold mb-1">Option A: Netdata (Free, Open-Source)</h6>
              <div class="small text-muted mb-2">Runs locally on port 19999 with zero configuration:</div>
              <pre class="bg-dark text-white p-2 rounded small mb-2">bash &lt;(curl -SsL https://get.netdata.cloud/kickstart.sh)</pre>
            </div>

            <div class="card bg-light border-0 p-3">
              <h6 class="fw-bold mb-1">Option B: Glances (Python-based)</h6>
              <div class="small text-muted mb-2">Runs locally on port 61208:</div>
              <pre class="bg-dark text-white p-2 rounded small mb-0">pip install glances &amp;&amp; glances -w</pre>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card border">
              <div class="card-header bg-white py-3 fw-semibold">
                External Monitoring URL
              </div>
              <div class="card-body">
                <p class="small text-muted">Configure external monitor URL in <a href="<?= e(admin_url('settings.php?tab=seo')) ?>">Settings</a> or via <code>monitor_external_url</code>.</p>
                <?php if (!empty($externalUrl)): ?>
                  <a href="<?= e($externalUrl) ?>" target="_blank" class="btn btn-primary">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Open Netdata / Grafana Dashboard
                  </a>
                  <?php if (str_starts_with(strtolower($externalUrl), 'https://') && setting('monitor_external_embed', '0') == '1'): ?>
                    <div class="ratio ratio-16x9 mt-3 border rounded overflow-hidden shadow-sm">
                      <iframe src="<?= e($externalUrl) ?>" title="External Dashboard" allowfullscreen></iframe>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="text-muted small italic">No external monitoring URL configured yet.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Alert Rule Create / Edit -->
<div class="modal fade" id="ruleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="ruleForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_rule">
        <input type="hidden" name="rule_id" id="modalRuleId" value="0">

        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="modalRuleTitle">Add Alert Rule</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Target Metric</label>
            <select name="metric" id="modalMetric" class="form-select" required>
              <option value="cpu_percent">CPU Utilization (%)</option>
              <option value="mem_percent">Memory Utilization (%)</option>
              <option value="disk_percent">Disk Space Used (%)</option>
              <option value="load_1">System Load 1m</option>
              <option value="db_threads">Database Active Threads</option>
            </select>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-4">
              <label class="form-label small fw-semibold">Condition</label>
              <select name="operator" id="modalOperator" class="form-select">
                <option value=">">&gt; (Greater than)</option>
                <option value="<">&lt; (Less than)</option>
              </select>
            </div>
            <div class="col-8">
              <label class="form-label small fw-semibold">Threshold</label>
              <input type="number" step="0.1" name="threshold" id="modalThreshold" class="form-control" placeholder="85.0" required>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold">Duration (Consecutive Minutes)</label>
            <input type="number" name="for_minutes" id="modalForMinutes" class="form-control" value="5" min="1" max="60" required>
            <div class="form-text">Must breach threshold continuously for this duration before firing.</div>
          </div>

          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="notify_email" value="1" id="modalNotify" checked>
            <label class="form-check-label fw-medium" for="modalNotify">Send Email Notification to Admin</label>
          </div>

          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="enabled" value="1" id="modalEnabled" checked>
            <label class="form-check-label fw-medium" for="modalEnabled">Enable Rule</label>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i> Save Alert Rule</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let currentRange = '1h';
    let currentIface = 'auto';
    let autoRefreshActive = true;
    let refreshTimer = null;

    // Charts references
    let chartCpuLoad = null;
    let chartMemory  = null;
    let chartNetwork = null;
    let chartBandwidth = null;
    let chartRequests = null;
    let chartStatus  = null;

    function formatBytes(bytes, decimals = 1) {
        if (!bytes || bytes <= 0) return '0 B';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    function formatRate(bytesSec) {
        if (!bytesSec || bytesSec <= 0) return '0 B/s';
        return formatBytes(bytesSec) + '/s';
    }

    function createCharts() {
        const gridColor = 'rgba(150, 150, 150, 0.15)';

        // 1. CPU & Load
        chartCpuLoad = new Chart(document.getElementById('chartCpuLoad').getContext('2d'), {
            type: 'line',
            data: { labels: [], datasets: [
                { label: 'CPU %', data: [], borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.1)', yAxisID: 'yCpu', fill: true, tension: 0.3 },
                { label: 'Load 1m', data: [], borderColor: '#f59e0b', borderDash: [4, 4], yAxisID: 'yLoad', fill: false, tension: 0.3 }
            ]},
            options: { responsive: true, maintainAspectRatio: false, scales: {
                x: { grid: { color: gridColor } },
                yCpu: { type: 'linear', position: 'left', min: 0, max: 100, ticks: { callback: v => v + '%' }, grid: { color: gridColor } },
                yLoad: { type: 'linear', position: 'right', min: 0, grid: { drawOnChartArea: false } }
            }}
        });

        // 2. Memory & Swap
        chartMemory = new Chart(document.getElementById('chartMemory').getContext('2d'), {
            type: 'line',
            data: { labels: [], datasets: [
                { label: 'RAM Used %', data: [], borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.15)', fill: true, tension: 0.3 },
                { label: 'Swap Used %', data: [], borderColor: '#6b7280', backgroundColor: 'rgba(107, 114, 128, 0.1)', fill: true, tension: 0.3 }
            ]},
            options: { responsive: true, maintainAspectRatio: false, scales: {
                x: { grid: { color: gridColor } },
                y: { min: 0, max: 100, ticks: { callback: v => v + '%' }, grid: { color: gridColor } }
            }}
        });

        // 3. Network Throughput
        chartNetwork = new Chart(document.getElementById('chartNetwork').getContext('2d'), {
            type: 'line',
            data: { labels: [], datasets: [
                { label: 'RX Rate ↓', data: [], borderColor: '#0ea5e9', fill: false, tension: 0.2 },
                { label: 'TX Rate ↑', data: [], borderColor: '#8b5cf6', fill: false, tension: 0.2 }
            ]},
            options: { responsive: true, maintainAspectRatio: false, scales: {
                x: { grid: { color: gridColor } },
                y: { grid: { color: gridColor }, ticks: { callback: v => formatRate(v) } }
            }}
        });

        // 4. Bandwidth
        chartBandwidth = new Chart(document.getElementById('chartBandwidth').getContext('2d'), {
            type: 'bar',
            data: { labels: [], datasets: [
                { label: 'Outbound', data: [], backgroundColor: '#3b82f6' },
                { label: 'Inbound', data: [], backgroundColor: '#93c5fd' }
            ]},
            options: { responsive: true, maintainAspectRatio: false, scales: {
                x: { stacked: true, grid: { color: gridColor } },
                y: { stacked: true, grid: { color: gridColor }, ticks: { callback: v => formatBytes(v) } }
            }}
        });

        // 5. Requests & Response Time
        chartRequests = new Chart(document.getElementById('chartRequests').getContext('2d'), {
            type: 'line',
            data: { labels: [], datasets: [
                { label: 'Requests', data: [], type: 'bar', backgroundColor: 'rgba(99, 102, 241, 0.5)', yAxisID: 'yReq' },
                { label: 'Avg Latency (ms)', data: [], type: 'line', borderColor: '#ef4444', yAxisID: 'yRt', tension: 0.3 }
            ]},
            options: { responsive: true, maintainAspectRatio: false, scales: {
                x: { grid: { color: gridColor } },
                yReq: { type: 'linear', position: 'left', min: 0, grid: { color: gridColor } },
                yRt: { type: 'linear', position: 'right', min: 0, ticks: { callback: v => v + ' ms' }, grid: { drawOnChartArea: false } }
            }}
        });

        // 6. HTTP Status Mix
        chartStatus = new Chart(document.getElementById('chartStatus').getContext('2d'), {
            type: 'bar',
            data: { labels: [], datasets: [
                { label: '2xx OK', data: [], backgroundColor: '#10b981' },
                { label: '3xx Redirect', data: [], backgroundColor: '#3b82f6' },
                { label: '4xx Client Err', data: [], backgroundColor: '#f59e0b' },
                { label: '5xx Server Err', data: [], backgroundColor: '#ef4444' }
            ]},
            options: { responsive: true, maintainAspectRatio: false, scales: {
                x: { stacked: true, grid: { color: gridColor } },
                y: { stacked: true, grid: { color: gridColor } }
            }}
        });
    }

    createCharts();

    function fetchMonitorData() {
        const url = '<?= e(admin_url("ajax/monitor_data.php")) ?>?range=' + encodeURIComponent(currentRange) + '&iface=' + encodeURIComponent(currentIface);

        fetch(url)
            .then(res => res.json())
            .then(data => {
                updateTiles(data.tiles);
                updateCharts(data.series);
                updateBandwidth(data.bandwidth);
                updateTables(data.tables);
                updateCsvLink();
            })
            .catch(err => {
                console.error("Monitoring fetch failed:", err);
            });
    }

    function updateTiles(t) {
        if (!t) return;

        // CPU
        const cpu = t.cpu_percent !== null ? t.cpu_percent : 0;
        document.getElementById('tileCpuVal').textContent = (t.cpu_percent !== null ? t.cpu_percent.toFixed(1) + '%' : 'N/A');
        document.getElementById('tileCpuCores').textContent = '(' + (t.cpu_cores || 1) + ' cores)';
        const cpuBar = document.getElementById('tileCpuBar');
        cpuBar.style.width = Math.min(100, cpu) + '%';
        cpuBar.className = 'progress-bar ' + (cpu >= 90 ? 'bg-danger' : (cpu >= 70 ? 'bg-warning' : 'bg-success'));

        // Load
        const l1 = t.load_1 !== null ? t.load_1.toFixed(2) : '--';
        const l5 = t.load_5 !== null ? t.load_5.toFixed(2) : '--';
        const l15 = t.load_15 !== null ? t.load_15.toFixed(2) : '--';
        document.getElementById('tileLoadVal').textContent = l1 + ' / ' + l5 + ' / ' + l15;

        // RAM
        const ramPct = t.mem_percent || 0;
        document.getElementById('tileMemVal').textContent = ramPct.toFixed(1) + '%';
        document.getElementById('tileMemSub').textContent = '(' + (t.mem_used / 1073741824).toFixed(1) + ' / ' + (t.mem_total / 1073741824).toFixed(1) + ' GB)';
        const memBar = document.getElementById('tileMemBar');
        memBar.style.width = Math.min(100, ramPct) + '%';
        memBar.className = 'progress-bar ' + (ramPct >= 90 ? 'bg-danger' : (ramPct >= 75 ? 'bg-warning' : 'bg-info'));

        // Disk
        const diskPct = t.disk_percent || 0;
        document.getElementById('tileDiskVal').textContent = diskPct.toFixed(1) + '%';
        document.getElementById('tileDiskSub').textContent = '(' + (t.disk_used / 1073741824).toFixed(0) + ' / ' + (t.disk_total / 1073741824).toFixed(0) + ' GB)';
        const diskBar = document.getElementById('tileDiskBar');
        diskBar.style.width = Math.min(100, diskPct) + '%';
        diskBar.className = 'progress-bar ' + (diskPct >= 95 ? 'bg-danger' : (diskPct >= 85 ? 'bg-warning' : 'bg-primary'));

        // Network
        document.getElementById('tileNetRx').textContent = formatRate(t.net_rx_rate);
        document.getElementById('tileNetTx').textContent = formatRate(t.net_tx_rate);

        // DB / Req
        document.getElementById('tileDbQps').textContent = t.db_qps !== null ? t.db_qps.toFixed(1) : '--';
        document.getElementById('tileReqMin').textContent = t.req_per_min || 0;
    }

    function updateCharts(s) {
        if (!s) return;
        const labels = s.labels || [];

        // CPU & Load
        chartCpuLoad.data.labels = labels;
        chartCpuLoad.data.datasets[0].data = s.cpu || [];
        chartCpuLoad.data.datasets[1].data = s.load || [];
        chartCpuLoad.update('none');

        // Memory
        chartMemory.data.labels = labels;
        chartMemory.data.datasets[0].data = s.mem || [];
        chartMemory.data.datasets[1].data = s.swap || [];
        chartMemory.update('none');

        // Network
        chartNetwork.data.labels = labels;
        chartNetwork.data.datasets[0].data = s.net_rx || [];
        chartNetwork.data.datasets[1].data = s.net_tx || [];
        chartNetwork.update('none');

        // Bandwidth
        chartBandwidth.data.labels = labels;
        chartBandwidth.data.datasets[0].data = s.bw_out || [];
        chartBandwidth.data.datasets[1].data = s.bw_in || [];
        chartBandwidth.update('none');

        // Requests & Latency
        chartRequests.data.labels = labels;
        chartRequests.data.datasets[0].data = s.req || [];
        chartRequests.data.datasets[1].data = s.rt_avg || [];
        chartRequests.update('none');

        // Status Mix
        chartStatus.data.labels = labels;
        chartStatus.data.datasets[0].data = s.status ? s.status.s2xx : [];
        chartStatus.data.datasets[1].data = s.status ? s.status.s3xx : [];
        chartStatus.data.datasets[2].data = s.status ? s.status.s4xx : [];
        chartStatus.data.datasets[3].data = s.status ? s.status.s5xx : [];
        chartStatus.update('none');
    }

    function updateBandwidth(bw) {
        if (!bw) return;
        const quotaContainer = document.getElementById('quotaContainer');
        if (bw.quota_gb > 0) {
            quotaContainer.classList.remove('d-none');
            const usedGb = (bw.month_bytes / 1073741824).toFixed(2);
            document.getElementById('quotaText').textContent = usedGb + ' / ' + bw.quota_gb + ' GB (' + bw.quota_percent + '%)';
            const qb = document.getElementById('quotaBar');
            qb.style.width = Math.min(100, bw.quota_percent) + '%';
            qb.className = 'progress-bar ' + (bw.quota_percent >= 90 ? 'bg-danger' : (bw.quota_percent >= 75 ? 'bg-warning' : 'bg-success'));
        } else {
            quotaContainer.classList.add('d-none');
        }
    }

    function updateTables(tb) {
        if (!tb) return;

        // Interfaces Dropdown
        const ifSelect = document.getElementById('ifaceSelect');
        const currentVal = ifSelect.value;
        if (tb.interfaces && tb.interfaces.length > 0) {
            ifSelect.innerHTML = '';
            tb.interfaces.forEach(iface => {
                const opt = document.createElement('option');
                opt.value = iface;
                opt.textContent = iface === 'auto' ? 'Interface: Auto' : iface;
                if (iface === currentVal) opt.selected = true;
                ifSelect.appendChild(opt);
            });
        }

        // Slow requests
        const tbody = document.getElementById('slowRequestsTableBody');
        const countSpan = document.getElementById('slowReqCount');
        const slow = tb.slow || [];
        countSpan.textContent = slow.length;

        if (slow.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No slow requests recorded.</td></tr>';
            return;
        }

        let html = '';
        slow.forEach(r => {
            const statusClass = r.status_code >= 500 ? 'bg-danger' : (r.status_code >= 400 ? 'bg-warning' : 'bg-success');
            html += `<tr>
                <td class="small text-muted">${r.created_at}</td>
                <td><span class="badge bg-secondary font-monospace">${r.method}</span></td>
                <td><code>${r.path}</code></td>
                <td><span class="badge ${statusClass}">${r.status_code || 200}</span></td>
                <td class="fw-bold text-danger">${r.duration_ms} ms</td>
                <td class="small text-muted font-monospace">${r.query_count !== null && r.query_count !== undefined ? r.query_count : '--'}</td>
                <td class="small text-muted">${r.peak_mem ? formatBytes(r.peak_mem) : '--'}</td>
            </tr>`;
        });
        tbody.innerHTML = html;
    }

    function updateCsvLink() {
        const btn = document.getElementById('exportCsvBtn');
        btn.href = '<?= e(admin_url("ajax/monitor_data.php")) ?>?range=' + encodeURIComponent(currentRange) + '&iface=' + encodeURIComponent(currentIface) + '&format=csv';
    }

    // Range Button Handlers
    document.querySelectorAll('.range-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.range-btn').forEach(b => {
                b.classList.remove('btn-primary', 'active');
                b.classList.add('btn-outline-secondary');
            });
            this.classList.remove('btn-outline-secondary');
            this.classList.add('btn-primary', 'active');

            currentRange = this.getAttribute('data-range');
            fetchMonitorData();
        });
    });

    // Interface Select Handler
    document.getElementById('ifaceSelect').addEventListener('change', function () {
        currentIface = this.value;
        document.getElementById('chartIfaceLabel').textContent = 'Interface: ' + currentIface;
        fetchMonitorData();
    });

    // Auto Refresh Handler
    const autoBtn = document.getElementById('autoRefreshBtn');
    const autoLabel = document.getElementById('autoRefreshLabel');

    function resetTimer() {
        if (refreshTimer) clearInterval(refreshTimer);
        if (autoRefreshActive) {
            const interval = (currentRange === 'live') ? 5000 : 30000;
            refreshTimer = setInterval(fetchMonitorData, interval);
        }
    }

    autoBtn.addEventListener('click', function () {
        autoRefreshActive = !autoRefreshActive;
        if (autoRefreshActive) {
            autoLabel.textContent = (currentRange === 'live') ? 'Live (5s)' : 'Auto (30s)';
            autoBtn.classList.remove('btn-outline-danger');
            autoBtn.classList.add('btn-outline-secondary');
        } else {
            autoLabel.textContent = 'Paused';
            autoBtn.classList.remove('btn-outline-secondary');
            autoBtn.classList.add('btn-outline-danger');
        }
        resetTimer();
    });

    // Initial Load
    fetchMonitorData();
    resetTimer();

    // Alert Rule Modal helpers
    window.prepareAddRule = function() {
        document.getElementById('modalRuleTitle').textContent = 'Add Alert Rule';
        document.getElementById('modalRuleId').value = '0';
        document.getElementById('modalMetric').value = 'cpu_percent';
        document.getElementById('modalOperator').value = '>';
        document.getElementById('modalThreshold').value = '85';
        document.getElementById('modalForMinutes').value = '5';
        document.getElementById('modalNotify').checked = true;
        document.getElementById('modalEnabled').checked = true;
    };

    window.editRule = function(rule) {
        document.getElementById('modalRuleTitle').textContent = 'Edit Alert Rule';
        document.getElementById('modalRuleId').value = rule.id;
        document.getElementById('modalMetric').value = rule.metric;
        document.getElementById('modalOperator').value = rule.operator;
        document.getElementById('modalThreshold').value = rule.threshold;
        document.getElementById('modalForMinutes').value = rule.for_minutes;
        document.getElementById('modalNotify').checked = (rule.notify_email == 1);
        document.getElementById('modalEnabled').checked = (rule.enabled == 1);
        const modal = new bootstrap.Modal(document.getElementById('ruleModal'));
        modal.show();
    };

    // Activate tab if URL has hash (e.g. #alertsTab)
    if (window.location.hash) {
        const triggerEl = document.querySelector(`button[data-bs-target="${window.location.hash}"]`);
        if (triggerEl) {
            const tab = new bootstrap.Tab(triggerEl);
            tab.show();
        }
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
