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

        try {
            if (!function_exists('setting') || !function_exists('db')) {
                return;
            }

            // Check if monitoring is enabled
            if ((string)setting('monitor_enabled', '1') === '0') {
                return;
            }

            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $path = parse_url($uri, PHP_URL_PATH) ?: '/';
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

            // Categorize area
            $area = 'storefront';
            if (str_contains($path, '/admin/') || str_ends_with($path, '/admin')) {
                $area = 'admin';
            } elseif (str_contains($path, '/api/')) {
                $area = 'api';
            }

            $statusCode = http_response_code() ?: 200;
            $bytesOut = (int)(ob_get_length() ?: 0) + 320; // Include estimated HTTP headers
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

            // Track slow requests
            $slowThreshold = (int)setting('monitor_slow_request_ms', '1500');
            if ($durationMs >= $slowThreshold) {
                $stmtSlow = $pdo->prepare(
                    "INSERT INTO slow_requests (path, method, status_code, duration_ms, peak_mem, created_at)
                     VALUES (:path, :method, :status, :dur, :mem, NOW())"
                );
                $stmtSlow->execute([
                    ':path'   => substr($path, 0, 255),
                    ':method' => substr($method, 0, 10),
                    ':status' => $statusCode,
                    ':dur'    => $durationMs,
                    ':mem'    => $peakMem,
                ]);
            }
        } catch (Throwable $e) {
            // Silent — traffic monitoring must NEVER break application responses.
        }
    }
}

if (PHP_SAPI !== 'cli') {
    register_shutdown_function('traffic_record');
}
