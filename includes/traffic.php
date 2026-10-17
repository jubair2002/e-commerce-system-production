<?php
/**
 * Application traffic & bandwidth monitor.
 * Records request duration, memory, bandwidth in/out, and status code.
 * Non-blocking, 100% try/catch isolated.
 */

if (!defined('APP_INIT_TIME')) {
    define('APP_INIT_TIME', microtime(true));
}

if (!isset($GLOBALS['_t0'])) {
    $GLOBALS['_t0'] = APP_INIT_TIME;
}

// Start output buffering to capture response bytes if not already started
if (PHP_SAPI !== 'cli' && ob_get_level() === 0) {
    ob_start();
}

/**
 * Call once the page HTML is fully built (footer end / before exit in API files).
 * 1) saves + unlocks the session, 2) sends the response to the browser,
 * so shutdown work (traffic log, mail queue) never delays the user or the next click.
 * NOTE: Never write to $_SESSION after app_finish_response().
 */
function app_finish_response(): void
{
    static $done = false;
    if ($done || PHP_SAPI === 'cli') return;
    $done = true;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // unlock session NOW so next click never waits
    }

    $GLOBALS['_bytes_out'] = (int)(ob_get_length() ?: 0);

    // Send Server-Timing header if headers not yet sent
    if (!headers_sent()) {
        $appDur = (int)max(1, round((microtime(true) - ($GLOBALS['_t0'] ?? APP_INIT_TIME)) * 1000));
        $qCount = class_exists('DebugPDO', false) ? DebugPDO::$queryCount : 0;
        $dbDur = class_exists('DebugPDO', false) ? (int)round(DebugPDO::$totalMs) : 0;
        header("Server-Timing: app;dur={$appDur}, db;dur={$dbDur}, q;desc=\"{$qCount} queries\"");
    }

    if (function_exists('fastcgi_finish_request')) { // PHP-FPM / Nginx
        fastcgi_finish_request();
        return;
    }

    // Apache mod_php / XAMPP: close the connection manually
    ignore_user_abort(true);
    if (!headers_sent() && ob_get_level() > 0) {
        header('Content-Length: ' . (int)ob_get_length());
        header('Connection: close');
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

if (!function_exists('traffic_record')) {
    /**
     * Record traffic snapshot on request shutdown.
     */
    function traffic_record(bool $force = false): void
    {
        // Never run in CLI mode unless explicitly forced (for testing)
        if (PHP_SAPI === 'cli' && !$force) {
            return;
        }

        // Release session lock immediately if still active
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $path = parse_url($uri, PHP_URL_PATH) ?: '/';

            // Skip static asset requests entirely
            if (str_starts_with($path, '/assets/')) {
                return;
            }

            $statusCode = http_response_code() ?: 200;

            // Skip traffic DB write for 304 Not Modified availability checks
            if (str_contains($path, 'availability.php') && $statusCode === 304) {
                return;
            }

            if (!function_exists('db')) {
                return;
            }

            // Check monitor_enabled using cached settings only (zero extra query)
            $cache = function_exists('setting_cache') ? setting_cache() : [];
            if (isset($cache['monitor_enabled']) && (string)$cache['monitor_enabled'] === '0') {
                return;
            }

            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

            // Categorize area
            $area = 'storefront';
            if (str_contains($path, '/admin/') || str_ends_with($path, '/admin')) {
                $area = 'admin';
            } elseif (str_contains($path, '/api/')) {
                $area = 'api';
            }

            $bytesOut = ($GLOBALS['_bytes_out'] ?? (int)(ob_get_length() ?: 0)) + 320; // Include estimated HTTP headers
            $bytesIn = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            $durationMs = (int)max(1, round((microtime(true) - ($GLOBALS['_t0'] ?? APP_INIT_TIME)) * 1000));
            $peakMem = function_exists('memory_get_peak_usage') ? memory_get_peak_usage(true) : null;

            $s2xx = ($statusCode >= 200 && $statusCode < 300) ? 1 : 0;
            $s3xx = ($statusCode >= 300 && $statusCode < 400) ? 1 : 0;
            $s4xx = ($statusCode >= 400 && $statusCode < 500) ? 1 : 0;
            $s5xx = ($statusCode >= 500) ? 1 : 0;

            $pdo = db();

            // 1 cheap atomic upsert per minute bucket
            $stmt = $pdo->prepare(
                "INSERT INTO traffic_minute
                  (bucket, area, requests, bytes_out, bytes_in, total_ms, max_ms, s2xx, s3xx, s4xx, s5xx)
                 VALUES
                  (DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:00'), :area, 1, :bytes_out, :bytes_in, :dur1, :dur2, :s2, :s3, :s4, :s5)
                 ON DUPLICATE KEY UPDATE
                  requests  = requests + 1,
                  bytes_out = bytes_out + VALUES(bytes_out),
                  bytes_in  = bytes_in + VALUES(bytes_in),
                  total_ms  = total_ms + VALUES(total_ms),
                  max_ms    = GREATEST(max_ms, VALUES(max_ms)),
                  s2xx      = s2xx + VALUES(s2xx),
                  s3xx      = s3xx + VALUES(s3xx),
                  s4xx      = s4xx + VALUES(s4xx),
                  s5xx      = s5xx + VALUES(s5xx)"
            );

            $stmt->execute([
                ':area'      => $area,
                ':bytes_out' => $bytesOut,
                ':bytes_in'  => $bytesIn,
                ':dur1'      => $durationMs,
                ':dur2'      => $durationMs,
                ':s2'        => $s2xx,
                ':s3'        => $s3xx,
                ':s4'        => $s4xx,
                ':s5'        => $s5xx,
            ]);

            // Track slow requests (rate-limited max once per path per minute)
            $slowThreshold = isset($cache['monitor_slow_request_ms']) ? (int)$cache['monitor_slow_request_ms'] : 1500;
            if ($durationMs >= $slowThreshold) {
                $throttleDir = sys_get_temp_dir() . '/slow_req_locks';
                if (!is_dir($throttleDir)) {
                    @mkdir($throttleDir, 0777, true);
                }
                $throttleFile = $throttleDir . '/' . md5($path);
                $now = time();

                if (!file_exists($throttleFile) || ($now - filemtime($throttleFile) >= 60)) {
                    @touch($throttleFile);
                    $qCount = class_exists('DebugPDO', false) ? DebugPDO::$queryCount : null;

                    $stmtSlow = $pdo->prepare(
                        "INSERT INTO slow_requests (path, method, status_code, duration_ms, query_count, peak_mem, created_at)
                         VALUES (:path, :method, :status, :dur, :qc, :mem, NOW())"
                    );
                    $stmtSlow->execute([
                        ':path'   => substr($path, 0, 255),
                        ':method' => substr($method, 0, 10),
                        ':status' => $statusCode,
                        ':dur'    => $durationMs,
                        ':qc'     => $qCount,
                        ':mem'    => $peakMem,
                    ]);
                }
            }
        } catch (Throwable $e) {
            // Silent — traffic monitoring must NEVER break application responses.
        }
    }
}

if (PHP_SAPI !== 'cli') {
    register_shutdown_function('traffic_record');
}
