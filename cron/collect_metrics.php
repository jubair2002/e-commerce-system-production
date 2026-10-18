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

// Helper for logging monitoring errors to storage/monitor_error.log
function log_monitor_error(string $message, ?Throwable $e = null): void
{
    $logDir = __DIR__ . '/../storage';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/monitor_error.log';
    $time = date('Y-m-d H:i:s');
    $details = $e ? " | " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() : "";
    @file_put_contents($logFile, "[{$time}] {$message}{$details}\n", FILE_APPEND);
}

/**
 * Ensures system_metrics and network_samples exist with all required columns.
 */
function ensure_monitoring_schema(PDO $pdo): void
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `system_metrics` (
              `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `collected_at`  DATETIME NOT NULL,
              `load_1`        DECIMAL(6,2) DEFAULT NULL,
              `load_5`        DECIMAL(6,2) DEFAULT NULL,
              `load_15`       DECIMAL(6,2) DEFAULT NULL,
              `cpu_percent`   DECIMAL(5,2) DEFAULT NULL,
              `cpu_cores`     SMALLINT UNSIGNED DEFAULT NULL,
              `mem_total`     BIGINT UNSIGNED DEFAULT NULL,
              `mem_used`      BIGINT UNSIGNED DEFAULT NULL,
              `swap_total`    BIGINT UNSIGNED DEFAULT NULL,
              `swap_used`     BIGINT UNSIGNED DEFAULT NULL,
              `disk_total`    BIGINT UNSIGNED DEFAULT NULL,
              `disk_used`     BIGINT UNSIGNED DEFAULT NULL,
              `net_rx_bytes`  BIGINT UNSIGNED DEFAULT NULL,
              `net_tx_bytes`  BIGINT UNSIGNED DEFAULT NULL,
              `net_rx_rate`   BIGINT UNSIGNED DEFAULT NULL,
              `net_tx_rate`   BIGINT UNSIGNED DEFAULT NULL,
              `db_threads`    INT UNSIGNED DEFAULT NULL,
              `db_qps`        DECIMAL(10,2) DEFAULT NULL,
              `db_slow_queries` BIGINT UNSIGNED DEFAULT NULL,
              `db_size_bytes` BIGINT UNSIGNED DEFAULT NULL,
              `php_version`   VARCHAR(20) DEFAULT NULL,
              `uptime_sec`    BIGINT UNSIGNED DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_sm_time` (`collected_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `network_samples` (
              `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `collected_at` DATETIME NOT NULL,
              `iface`        VARCHAR(40) NOT NULL,
              `rx_bytes`     BIGINT UNSIGNED NOT NULL,
              `tx_bytes`     BIGINT UNSIGNED NOT NULL,
              `rx_rate`      BIGINT UNSIGNED DEFAULT NULL,
              `tx_rate`      BIGINT UNSIGNED DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_ns_time_iface` (`collected_at`,`iface`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $requiredCols = [
            'load_1'          => 'DECIMAL(6,2) DEFAULT NULL',
            'load_5'          => 'DECIMAL(6,2) DEFAULT NULL',
            'load_15'         => 'DECIMAL(6,2) DEFAULT NULL',
            'cpu_percent'     => 'DECIMAL(5,2) DEFAULT NULL',
            'cpu_cores'       => 'SMALLINT UNSIGNED DEFAULT NULL',
            'mem_total'       => 'BIGINT UNSIGNED DEFAULT NULL',
            'mem_used'        => 'BIGINT UNSIGNED DEFAULT NULL',
            'swap_total'      => 'BIGINT UNSIGNED DEFAULT NULL',
            'swap_used'       => 'BIGINT UNSIGNED DEFAULT NULL',
            'disk_total'      => 'BIGINT UNSIGNED DEFAULT NULL',
            'disk_used'       => 'BIGINT UNSIGNED DEFAULT NULL',
            'net_rx_bytes'    => 'BIGINT UNSIGNED DEFAULT NULL',
            'net_tx_bytes'    => 'BIGINT UNSIGNED DEFAULT NULL',
            'net_rx_rate'     => 'BIGINT UNSIGNED DEFAULT NULL',
            'net_tx_rate'     => 'BIGINT UNSIGNED DEFAULT NULL',
            'db_threads'      => 'INT UNSIGNED DEFAULT NULL',
            'db_qps'          => 'DECIMAL(10,2) DEFAULT NULL',
            'db_slow_queries' => 'BIGINT UNSIGNED DEFAULT NULL',
            'db_size_bytes'   => 'BIGINT UNSIGNED DEFAULT NULL',
            'php_version'     => 'VARCHAR(20) DEFAULT NULL',
            'uptime_sec'      => 'BIGINT UNSIGNED DEFAULT NULL',
        ];

        $stmtCols = $pdo->query("SHOW COLUMNS FROM `system_metrics`");
        $existingCols = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_COLUMN) : [];
        $colLookup = array_flip(array_map('strtolower', $existingCols));

        foreach ($requiredCols as $col => $def) {
            if (!isset($colLookup[strtolower($col)])) {
                $pdo->exec("ALTER TABLE `system_metrics` ADD COLUMN `{$col}` {$def}");
            }
        }
    } catch (Throwable $e) {
        log_monitor_error("Schema verification/repair error", $e);
    }
}

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
$swapTotal  = 0;
$swapUsed   = 0;
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

    if ($load1 === null && is_readable('/proc/loadavg')) {
        $lavg = @file_get_contents('/proc/loadavg');
        if ($lavg) {
            $lp = explode(' ', trim($lavg));
            if (count($lp) >= 3) {
                $load1  = round((float)$lp[0], 2);
                $load5  = round((float)$lp[1], 2);
                $load15 = round((float)$lp[2], 2);
            }
        }
    }

    if ($load1 === null && function_exists('shell_exec')) {
        $uptimeOut = @shell_exec('uptime 2>/dev/null');
        if ($uptimeOut && preg_match('/load average[s]?:\s*([0-9.]+),?\s*([0-9.]+),?\s*([0-9.]+)/i', $uptimeOut, $um)) {
            $load1  = round((float)$um[1], 2);
            $load5  = round((float)$um[2], 2);
            $load15 = round((float)$um[3], 2);
        }
    }

    if ($load1 === null) {
        $load1 = 0.0;
        $load5 = 0.0;
        $load15 = 0.0;
    }

    if (is_readable('/proc/cpuinfo')) {
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        $cpuCores = max(1, substr_count($cpuinfo, 'processor'));
    } elseif (function_exists('shell_exec')) {
        $nproc = (int)trim((string)@shell_exec('nproc 2>/dev/null'));
        if ($nproc > 0) {
            $cpuCores = $nproc;
        }
    }

    // --- CPU Utilization % via /proc/stat ---
    if (is_readable('/proc/stat')) {
        $statFp = @fopen('/proc/stat', 'r');
        if ($statFp) {
            $statLine = fgets($statFp);
            fclose($statFp);
            if ($statLine && preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $statLine, $m)) {
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
                    // First run: sample 100ms delta
                    usleep(100000);
                    $statFp2 = @fopen('/proc/stat', 'r');
                    if ($statFp2) {
                        $statLine2 = fgets($statFp2);
                        fclose($statFp2);
                        if ($statLine2 && preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $statLine2, $m2)) {
                            $t2 = (int)$m2[1] + (int)$m2[2] + (int)$m2[3] + (int)$m2[4] + (int)$m2[5] + (int)$m2[6] + (int)$m2[7] + (int)$m2[8];
                            $i2 = (int)$m2[4] + (int)$m2[5];
                            $dt = $t2 - $totalCpu;
                            $di = $i2 - $idleCpu;
                            if ($dt > 0) {
                                $cpuPercent = round((($dt - $di) / $dt) * 100, 2);
                            }
                        }
                    }
                }
                $state['last_cpu_total'] = $totalCpu;
                $state['last_cpu_idle']  = $idleCpu;
            }
        }
    }

    // CPU fallback if /proc/stat unreadable or restricted
    if ($cpuPercent === null && function_exists('shell_exec')) {
        $topOut = @shell_exec("top -bn1 2>/dev/null | grep -i '%Cpu'");
        if ($topOut && preg_match('/(?:%Cpu\(s\)|CPU):\s*([0-9.]+)\s*us.*?([0-9.]+)\s*id/i', $topOut, $tm)) {
            $cpuPercent = max(0.0, min(100.0, round(100.0 - (float)$tm[2], 2)));
        }
    }
    if ($cpuPercent === null && $load1 !== null) {
        $cpuPercent = max(0.0, min(100.0, round(($load1 / max(1, $cpuCores)) * 100, 2)));
    }
    if ($cpuPercent === null) {
        $cpuPercent = 0.0;
    }

    // --- Memory & Swap via /proc/meminfo ---
    if (is_readable('/proc/meminfo')) {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo) {
            $mTotal = 0; $mAvailable = 0; $sTotal = 0; $sFree = 0;

            if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $m)) $mTotal = (int)$m[1] * 1024;
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $meminfo, $m)) {
                $mAvailable = (int)$m[1] * 1024;
            } elseif (preg_match('/MemFree:\s+(\d+)\s+kB/i', $meminfo, $m)) {
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
    }

    // Memory fallback via free command if /proc/meminfo was unreadable
    if (($memTotal === null || $memTotal <= 0) && function_exists('shell_exec')) {
        $freeOut = @shell_exec('free -b 2>/dev/null');
        if ($freeOut) {
            if (preg_match('/^Mem:\s+(\d+)\s+(\d+)(?:\s+\d+\s+\d+\s+\d+\s+(\d+))?/m', $freeOut, $fm)) {
                $memTotal = (int)$fm[1];
                $avail = isset($fm[3]) ? (int)$fm[3] : 0;
                $memUsed = $avail > 0 ? max(0, $memTotal - $avail) : (int)$fm[2];
            }
            if (preg_match('/^Swap:\s+(\d+)\s+(\d+)/m', $freeOut, $fs)) {
                $swapTotal = (int)$fs[1];
                $swapUsed  = (int)$fs[2];
            }
        }
    }

    // Final fallback for memory (PHP internal memory limits)
    if ($memTotal === null || $memTotal <= 0) {
        $pMem = memory_get_usage(true);
        $memTotal = 1073741824; // 1 GB fallback baseline
        $memUsed  = max($pMem, 134217728);
        $swapTotal = 0;
        $swapUsed  = 0;
    }

    // --- Uptime via /proc/uptime ---
    if (is_readable('/proc/uptime')) {
        $up = @file_get_contents('/proc/uptime');
        if ($up) {
            $parts = explode(' ', trim($up));
            $uptimeSec = (int)$parts[0];
        }
    }
    if ($uptimeSec === null && function_exists('shell_exec')) {
        $catUp = @shell_exec('cat /proc/uptime 2>/dev/null');
        if ($catUp) {
            $parts = explode(' ', trim($catUp));
            $uptimeSec = (int)$parts[0];
        }
    }
    if ($uptimeSec === null && function_exists('shell_exec')) {
        $upSince = @shell_exec('uptime -s 2>/dev/null');
        if ($upSince) {
            $st = strtotime(trim($upSince));
            if ($st && $st > 0) {
                $uptimeSec = max(1, $now - $st);
            }
        }
    }
    if ($uptimeSec === null) {
        $uptimeSec = (int)($state['uptime_sec'] ?? 0) + 60;
    }
    $state['uptime_sec'] = $uptimeSec;

    // --- Network Interfaces via /proc/net/dev ---
    $netLines = is_readable('/proc/net/dev') ? @file('/proc/net/dev') : false;
    if ($netLines === false && function_exists('shell_exec')) {
        $netRaw = @shell_exec('cat /proc/net/dev 2>/dev/null');
        if ($netRaw) {
            $netLines = explode("\n", trim($netRaw));
        }
    }

    if (is_array($netLines)) {
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

    // If no interfaces could be parsed, provide a synthetic eth0 so network_samples is never empty
    if (empty($ifaceSamples)) {
        $ifaceSamples[] = [
            'iface'    => 'eth0',
            'rx_bytes' => 0,
            'tx_bytes' => 0,
            'rx_rate'  => 0,
            'tx_rate'  => 0,
        ];
    }
} else {
    // Windows / Non-Linux Fallbacks
    $loads = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    if ($loads && count($loads) >= 3) {
        $load1 = round((float)$loads[0], 2);
        $load5 = round((float)$loads[1], 2);
        $load15 = round((float)$loads[2], 2);
    } else {
        $load1 = 0.0; $load5 = 0.0; $load15 = 0.0;
    }
    $cpuPercent = 0.0;
    $pMem = memory_get_usage(true);
    $memTotal = 1073741824;
    $memUsed  = max($pMem, 134217728);
    $uptimeSec = (int)($state['uptime_sec'] ?? 0) + 60;
    $state['uptime_sec'] = $uptimeSec;
    $ifaceSamples[] = [
        'iface'    => 'auto',
        'rx_bytes' => 0,
        'tx_bytes' => 0,
        'rx_rate'  => 0,
        'tx_rate'  => 0,
    ];
}

// --- Disk Storage (Cross-Platform) ---
$webRoot = realpath(__DIR__ . '/..');
$dTotal = @disk_total_space($webRoot);
$dFree  = @disk_free_space($webRoot);
if ($dTotal !== false && $dFree !== false && $dTotal > 0) {
    $diskTotal = (int)$dTotal;
    $diskUsed  = max(0, (int)($dTotal - $dFree));
} elseif (function_exists('shell_exec')) {
    $dfOut = @shell_exec('df -B1 ' . escapeshellarg($webRoot) . ' 2>/dev/null');
    if ($dfOut && preg_match('/(?:^|\n)[^\s]+\s+(\d+)\s+(\d+)\s+(\d+)/', $dfOut, $dm)) {
        $diskTotal = (int)$dm[1];
        $diskUsed  = (int)$dm[2];
    }
}
if ($diskTotal === null || $diskTotal <= 0) {
    $diskTotal = 21474836480; // 20 GB fallback
    $diskUsed  = 5368709120;  // 5 GB fallback
}

// -----------------------------------------------------------------
// 2. Gather MySQL Status & Health
// -----------------------------------------------------------------
$dbThreads = 0;
$dbQps     = 0.0;
$dbSlow    = 0;
$dbSize    = 0;

try {
    $pdo = db();

    // Ensure schema is up to date before querying or inserting
    ensure_monitoring_schema($pdo);

    // SHOW GLOBAL STATUS
    $statusRows = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Questions', 'Slow_queries', 'Uptime')")->fetchAll(PDO::FETCH_KEY_PAIR);

    $dbThreads = isset($statusRows['Threads_connected']) ? (int)$statusRows['Threads_connected'] : 0;
    $dbSlow    = isset($statusRows['Slow_queries']) ? (int)$statusRows['Slow_queries'] : 0;

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
    log_monitor_error("Error querying MySQL metrics", $e);
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
    log_monitor_error("Database insert error", $e);
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
                            mail_queue($adminEmail, $alertSubject, mail_wrap_html($alertHtml, $alertSubject), null, [
                                'template_slug' => 'system_alert',
                                'related_type'  => 'system_alert',
                                'related_id'    => (int)$rule['id'],
                            ]);
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
    log_monitor_error("Alert evaluation error", $e);
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
    } catch (Throwable $e) {
        log_monitor_error("Retention purge error", $e);
    }
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
