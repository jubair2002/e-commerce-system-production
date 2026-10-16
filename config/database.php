<?php
/**
 * PDO Database connection (singleton).
 */
require_once __DIR__ . '/config.php';

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);

                // Keep DB timestamps consistent with configured timezone
                $tzName = function_exists('setting') ? setting('timezone', 'Asia/Dhaka') : 'Asia/Dhaka';
                if ($tzName === 'Asia/Dhaka') {
                    self::$instance->exec("SET time_zone = '+06:00'");
                } else {
                    try {
                        $tzObj = new DateTimeZone($tzName ?: 'UTC');
                        $offset = (new DateTime('now', $tzObj))->format('P');
                        self::$instance->exec("SET time_zone = '{$offset}'");
                    } catch (Throwable $tzErr) {
                        // Fallback gracefully if timezone offset cannot be computed
                    }
                }
            } catch (PDOException $e) {
                if (APP_ENV === 'development') {
                    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
                }
                die('Database connection failed.');
            }
        }
        return self::$instance;
    }
}

/** Convenience helper. */
function db(): PDO
{
    return Database::getConnection();
}
