<?php
/**
 * System Metrics & Server Health Collector.
 * Designed to run via CLI cron every minute.
 *
 * Crontab example (Linux):
 * * * * * * php /home/abir/Downloads/php-ecommerce-admin-panel/cron/collect_metrics.php >/dev/null 2>&1
 *
 * Windows Task Scheduler:
 * Action: Start a program -> php.exe
 * Arguments: C:\path\to\cron\collect_metrics.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

require_once __DIR__ . '/../includes/shop_bootstrap.php';

// Command-line flags
$opts = getopt('', ['force', 'verbose']);
$verbose = isset($opts['verbose']);

if ($verbose) {
    echo "[" . date('Y-m-d H:i:s') . "] Starting system metrics collection...\n";
}

// Lock file protection
$lockDir = __DIR__ . '/../storage';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockFile = $lockDir . '/collect_metrics.lock';
$lockHandle = fopen($lockFile, 'c+');

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if ($verbose) {
        echo "Collector already running. Skipping execution.\n";
    }
    exit(0);
}

// State storage for delta calculations (QPS, CPU, network rates)
$stateFile = $lockDir . '/monitor_state.json';
$state = [];
if (file_exists($stateFile)) {
    $raw = @file_get_contents($stateFile);
    if ($raw) {
        $state = json_decode($raw, true) ?: [];
    }
}

$now = time();
$collectedAt = date('Y-m-d H:i:s', $now);

// -----------------------------------------------------------------
// 1. Gather Host OS Metrics (Linux / Windows fallback)
// -----------------------------------------------------------------
$isLinux = (PHP_OS_FAMILY === 'Linux');

$cpuPercent = null;
$cpuCores   = 1;
$load1      = null;
$load5      = null;
$load15     = null;
$memTotal   = null;
$memUsed    = null;
$swapTotal  = null;
$swapUsed   = null;
$diskTotal  = null;
$diskUsed   = null;
$uptimeSec  = null;
$netRxBytes = 0;
$netTxBytes = 0;
$netRxRate  = 0;
$netTxRate  = 0;
$ifaceSamples = [];

if ($isLinux) {
    // --- Load Average & CPU Cores ---
    if (function_exists('sys_getloadavg')) {
        $loads = sys_getloadavg();
        if (is_array($loads) && count($loads) >= 3) {
            $load1  = round((float)$loads[0], 2);
            $load5  = round((float)$loads[1], 2);
            $load15 = round((float)$loads[2], 2);
        }
    }

    if (is_readable('/proc/cpuinfo')) {
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        $cpuCores = max(1, substr_count($cpuinfo, 'processor'));
    }

    // --- CPU Utilization % via /proc/stat ---
    if (is_readable('/proc/stat')) {
        $statLine = fgets(fopen('/proc/stat', 'r'));
        if (preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $statLine, $m)) {
            $user   = (int)$m[1];
            $nice   = (int)$m[2];
            $system = (int)$m[3];
            $idle   = (int)$m[4];
            $iowait = (int)$m[5];
            $irq    = (int)$m[6];
            $softirq= (int)$m[7];
            $steal  = (int)$m[8];

            $totalCpu = $user + $nice + $system + $idle + $iowait + $irq + $softirq + $steal;
            $idleCpu  = $idle + $iowait;

            if (isset($state['last_cpu_total'], $state['last_cpu_idle'])) {
                $dTotal = $totalCpu - (int)$state['last_cpu_total'];
                $dIdle  = $idleCpu - (int)$state['last_cpu_idle'];
                if ($dTotal > 0) {
                    $cpuPercent = round((($dTotal - $dIdle) / $dTotal) * 100, 2);
                }
            } else {
                // If first run, take a quick 100ms sample to seed initial CPU
                usleep(100000);
                $statLine2 = fgets(fopen('/proc/stat', 'r'));
                if (preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $statLine2, $m2)) {
                    $t2 = (int)$m2[1] + (int)$m2[2] + (int)$m2[3] + (int)$m2[4] + (int)$m2[5] + (int)$m2[6] + (int)$m2[7] + (int)$m2[8];
                    $i2 = (int)$m2[4] + (int)$m2[5];
                    $dt = $t2 - $totalCpu;
                    $di = $i2 - $idleCpu;
                    if ($dt > 0) {
                        $cpuPercent = round((($dt - $di) / $dt) * 100, 2);
                    }
                }
            }
            $state['last_cpu_total'] = $totalCpu;
            $state['last_cpu_idle']  = $idleCpu;
        }
    }

    // --- Memory & Swap via /proc/meminfo ---
    if (is_readable('/proc/meminfo')) {
        $meminfo = file_get_contents('/proc/meminfo');
        $mTotal = 0; $mAvailable = 0; $sTotal = 0; $sFree = 0;

        if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $m)) $mTotal = (int)$m[1] * 1024;
        if (preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $meminfo, $m)) {
            $mAvailable = (int)$m[1] * 1024;
        } elseif (preg_match('/MemFree:\s+(\d+)\s+kB/i', $meminfo, $m)) {
            // Fallback: MemFree + Buffers + Cached
            $mFree = (int)$m[1] * 1024;
            $buffers = preg_match('/Buffers:\s+(\d+)\s+kB/i', $meminfo, $mb) ? (int)$mb[1] * 1024 : 0;
            $cached  = preg_match('/Cached:\s+(\d+)\s+kB/i', $meminfo, $mc) ? (int)$mc[1] * 1024 : 0;
            $mAvailable = $mFree + $buffers + $cached;
        }

        if (preg_match('/SwapTotal:\s+(\d+)\s+kB/i', $meminfo, $m)) $sTotal = (int)$m[1] * 1024;
        if (preg_match('/SwapFree:\s+(\d+)\s+kB/i', $meminfo, $m)) $sFree = (int)$m[1] * 1024;

        if ($mTotal > 0) {
            $memTotal = $mTotal;
            $memUsed  = max(0, $mTotal - $mAvailable);
        }
        if ($sTotal > 0) {
            $swapTotal = $sTotal;
            $swapUsed  = max(0, $sTotal - $sFree);
        }
    }

    // --- Uptime via /proc/uptime ---
    if (is_readable('/proc/uptime')) {
        $up = file_get_contents('/proc/uptime');
        $parts = explode(' ', trim($up));
        $uptimeSec = (int)$parts[0];
    }

    // --- Network Interfaces via /proc/net/dev ---
    if (is_readable('/proc/net/dev')) {
        $netLines = file('/proc/net/dev');
        $prevIfaceState = $state['ifaces'] ?? [];
        $lastCheckTime  = $state['last_net_time'] ?? ($now - 60);
        $timeDelta      = max(1, $now - $lastCheckTime);

        $totalRxRate = 0;
        $totalTxRate = 0;
        $totalRxBytes = 0;
        $totalTxBytes = 0;

        foreach ($netLines as $line) {
            if (!str_contains($line, ':')) continue;
            [$ifaceName, $data] = explode(':', $line, 2);
            $iface = trim($ifaceName);
            $cols = preg_split('/\s+/', trim($data));
            if (count($cols) < 9) continue;

            $rx = (int)$cols[0];
            $tx = (int)$cols[8];

            // Calculate rate
            $prevRx = $prevIfaceState[$iface]['rx'] ?? null;
            $prevTx = $prevIfaceState[$iface]['tx'] ?? null;

            $rxRate = 0;
            $txRate = 0;
            if ($prevRx !== null && $rx >= $prevRx) {
                $rxRate = (int)round(($rx - $prevRx) / $timeDelta);
            }
            if ($prevTx !== null && $tx >= $prevTx) {
                $txRate = (int)round(($tx - $prevTx) / $timeDelta);
            }

            $ifaceSamples[] = [
                'iface'    => $iface,
                'rx_bytes' => $rx,
                'tx_bytes' => $tx,
                'rx_rate'  => $rxRate,
                'tx_rate'  => $txRate,
            ];

            // Ignore loopback and virtual docker/veth bridges in primary system stats
            if ($iface !== 'lo' && !str_starts_with($iface, 'docker') && !str_starts_with($iface, 'veth') && !str_starts_with($iface, 'br-')) {
                $totalRxBytes += $rx;
                $totalTxBytes += $tx;
                $totalRxRate  += $rxRate;
                $totalTxRate  += $txRate;
            }

            $prevIfaceState[$iface] = ['rx' => $rx, 'tx' => $tx];
        }

        $state['ifaces'] = $prevIfaceState;
        $state['last_net_time'] = $now;

        $netRxBytes = $totalRxBytes;
        $netTxBytes = $totalTxBytes;
        $netRxRate  = $totalRxRate;
        $netTxRate  = $totalTxRate;
    }
} else {
    // Windows / Non-Linux Fallbacks
    $loads = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    if ($loads && count($loads) >= 3) {
        $load1 = round((float)$loads[0], 2);
        $load5 = round((float)$loads[1], 2);
        $load15 = round((float)$loads[2], 2);
    }
}

// --- Disk Storage (Cross-Platform) ---
$webRoot = realpath(__DIR__ . '/..');
$dTotal = @disk_total_space($webRoot);
$dFree  = @disk_free_space($webRoot);
if ($dTotal !== false && $dFree !== false && $dTotal > 0) {
    $diskTotal = (int)$dTotal;
    $diskUsed  = max(0, (int)($dTotal - $dFree));
}

// -----------------------------------------------------------------
// 2. Gather MySQL Status & Health
// -----------------------------------------------------------------
$dbThreads = null;
$dbQps     = null;
$dbSlow    = null;
$dbSize    = null;

try {
    $pdo = db();

    // SHOW GLOBAL STATUS
    $statusRows = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Questions', 'Slow_queries', 'Uptime')")->fetchAll(PDO::FETCH_KEY_PAIR);

    $dbThreads = isset($statusRows['Threads_connected']) ? (int)$statusRows['Threads_connected'] : null;
    $dbSlow    = isset($statusRows['Slow_queries']) ? (int)$statusRows['Slow_queries'] : null;

    $curQuestions = isset($statusRows['Questions']) ? (int)$statusRows['Questions'] : null;
    $lastQuestions = $state['last_questions'] ?? null;
    $lastQTime     = $state['last_questions_time'] ?? null;

    if ($curQuestions !== null && $lastQuestions !== null && $lastQTime !== null) {
        $dt = max(1, $now - $lastQTime);
        if ($curQuestions >= $lastQuestions) {
            $dbQps = round(($curQuestions - $lastQuestions) / $dt, 2);
        }
    }
    $state['last_questions'] = $curQuestions;
    $state['last_questions_time'] = $now;

    // Database Size in bytes
    $stmtSize = $pdo->query(
        "SELECT SUM(data_length + index_length) AS db_size
         FROM information_schema.TABLES
         WHERE table_schema = DATABASE()"
    );
    $dbSize = (int)($stmtSize->fetchColumn() ?: 0);
} catch (Throwable $e) {
    if ($verbose) {
        echo "Error querying MySQL metrics: " . $e->getMessage() . "\n";
    }
}

// -----------------------------------------------------------------
// 3. Persist Metrics to Database
// -----------------------------------------------------------------
try {
    $pdo = db();

    // Insert system_metrics snapshot
    $stmtSM = $pdo->prepare(
        "INSERT INTO system_metrics
          (collected_at, load_1, load_5, load_15, cpu_percent, cpu_cores,
           mem_total, mem_used, swap_total, swap_used, disk_total, disk_used,
           net_rx_bytes, net_tx_bytes, net_rx_rate, net_tx_rate,
           db_threads, db_qps, db_slow_queries, db_size_bytes, php_version, uptime_sec)
         VALUES
          (:time, :l1, :l5, :l15, :cpu, :cores,
           :mt, :mu, :st, :su, :dt, :du,
           :rx_b, :tx_b, :rx_r, :tx_r,
           :dbt, :qps, :slow, :dbs, :php, :up)"
    );

    $stmtSM->execute([
        ':time'  => $collectedAt,
        ':l1'    => $load1,
        ':l5'    => $load5,
        ':l15'   => $load15,
        ':cpu'   => $cpuPercent,
        ':cores' => $cpuCores,
        ':mt'    => $memTotal,
        ':mu'    => $memUsed,
        ':st'    => $swapTotal,
        ':su'    => $swapUsed,
        ':dt'    => $diskTotal,
        ':du'    => $diskUsed,
        ':rx_b'  => $netRxBytes,
        ':tx_b'  => $netTxBytes,
        ':rx_r'  => $netRxRate,
        ':tx_r'  => $netTxRate,
        ':dbt'   => $dbThreads,
        ':qps'   => $dbQps,
        ':slow'  => $dbSlow,
        ':dbs'   => $dbSize,
        ':php'   => PHP_VERSION,
        ':up'    => $uptimeSec,
    ]);

    // Insert network_samples per interface
    if (!empty($ifaceSamples)) {
        $stmtNS = $pdo->prepare(
            "INSERT INTO network_samples (collected_at, iface, rx_bytes, tx_bytes, rx_rate, tx_rate)
             VALUES (:time, :iface, :rx_b, :tx_b, :rx_r, :tx_r)"
        );
        foreach ($ifaceSamples as $sample) {
            $stmtNS->execute([
                ':time'  => $collectedAt,
                ':iface' => substr($sample['iface'], 0, 40),
                ':rx_b'  => $sample['rx_bytes'],
                ':tx_b'  => $sample['tx_bytes'],
                ':rx_r'  => $sample['rx_rate'],
                ':tx_r'  => $sample['tx_rate'],
            ]);
        }
    }

    if ($verbose) {
        echo "  Saved metrics at {$collectedAt}: CPU={$cpuPercent}%, Load={$load1}, RAM=" . round(($memUsed / ($memTotal ?: 1)) * 100) . "%\n";
    }
} catch (Throwable $e) {
    if ($verbose) {
        echo "Database insert error: " . $e->getMessage() . "\n";
    }
}

// -----------------------------------------------------------------
// 4. Evaluate Alert Rules
// -----------------------------------------------------------------
try {
    $rules = $pdo->query("SELECT * FROM monitor_alert_rules WHERE enabled = 1")->fetchAll();
    foreach ($rules as $rule) {
        $metric = $rule['metric'];
        $op     = $rule['operator'];
        $thresh = (float)$rule['threshold'];
        $forMin = max(1, (int)$rule['for_minutes']);
        $lastFired = !empty($rule['last_fired_at']) ? strtotime($rule['last_fired_at']) : 0;

        // Rate limit: Do not re-fire same rule within 60 minutes
        if (($now - $lastFired) < 3600 && !isset($opts['force'])) {
            continue;
        }

        // Determine column to test
        $col = null;
        if ($metric === 'cpu_percent') {
            $col = 'cpu_percent';
        } elseif ($metric === 'mem_percent') {
            $col = '(mem_used / mem_total) * 100';
        } elseif ($metric === 'disk_percent') {
            $col = '(disk_used / disk_total) * 100';
        } elseif ($metric === 'load_1') {
            $col = 'load_1';
        } elseif ($metric === 'db_threads') {
            $col = 'db_threads';
        }

        if ($col) {
            $stmtCheck = $pdo->prepare(
                "SELECT AVG(val) as avg_val, COUNT(*) as cnt
                 FROM (
                    SELECT id, ({$col}) as val
                    FROM system_metrics
                    ORDER BY id DESC
                    LIMIT {$forMin}
                 ) t"
            );
            $stmtCheck->execute();
            $checkRes = $stmtCheck->fetch();

            if ($checkRes && (int)$checkRes['cnt'] >= min($forMin, 2)) {
                $avgVal = (float)$checkRes['avg_val'];
                $isTriggered = ($op === '>') ? ($avgVal > $thresh) : ($avgVal < $thresh);

                if ($isTriggered) {
                    $alertSubject = sprintf("[ALERT] %s %.1f%% %s %.1f%% for %d min", $metric, $avgVal, $op, $thresh, $forMin);
                    $alertHtml = sprintf(
                        "<h3>System Health Alert Triggered</h3>
                         <p><strong>Metric:</strong> %s</p>
                         <p><strong>Current Average:</strong> %.2f</p>
                         <p><strong>Threshold:</strong> %s %.2f for %d minutes</p>
                         <p><strong>Time:</strong> %s</p>",
                        htmlspecialchars($metric, ENT_QUOTES, 'UTF-8'),
                        $avgVal,
                        $op,
                        $thresh,
                        $forMin,
                        $collectedAt
                    );

                    // Send alert via mail_queue if enabled
                    if (!empty($rule['notify_email'])) {
                        $adminEmail = setting('mail_admin_recipients', setting('site_email', 'admin@example.com'));
                        if (function_exists('mail_queue')) {
                            mail_queue($adminEmail, $alertSubject, mail_wrap_html($alertHtml, $alertSubject), null, [
                                'template_slug' => 'system_alert',
                            ]);
                        }
                    }

                    // Update last_fired_at
                    $pdo->prepare("UPDATE monitor_alert_rules SET last_fired_at = NOW() WHERE id = :id")
                        ->execute([':id' => $rule['id']]);

                    log_activity('monitoring.alert', "Fired alert: {$alertSubject}");

                    if ($verbose) {
                        echo "  [TRIGGERED] {$alertSubject}\n";
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    if ($verbose) {
        echo "Alert evaluation error: " . $e->getMessage() . "\n";
    }
}

// -----------------------------------------------------------------
// 5. Daily Data Retention Purge
// -----------------------------------------------------------------
$lastPurge = (int)($state['last_purge_time'] ?? 0);
if (($now - $lastPurge) > 86400) {
    $retentionDays = max(1, (int)setting('monitor_retention_days', '14'));
    try {
        $pdo->query("DELETE FROM system_metrics WHERE collected_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY) LIMIT 5000");
        $pdo->query("DELETE FROM network_samples WHERE collected_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY) LIMIT 5000");
        $pdo->query("DELETE FROM traffic_minute WHERE bucket < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY) LIMIT 5000");
        $pdo->query("DELETE FROM slow_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY) LIMIT 5000");
        $state['last_purge_time'] = $now;

        if ($verbose) {
            echo "  Purged metrics older than {$retentionDays} days.\n";
        }
    } catch (Throwable $e) {}
}

// Save state to disk
@file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));

// Release lock
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

if ($verbose) {
    echo "[" . date('Y-m-d H:i:s') . "] Completed successfully.\n";
}
exit(0);
