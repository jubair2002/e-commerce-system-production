<?php
/**
 * PDO Database connection (singleton) with connection-level timezone initialization
 * and DebugPDO query profiling for Server-Timing.
 */
require_once __DIR__ . '/config.php';

class DebugPDOStatement
{
    private PDOStatement $stmt;

    public function __construct(PDOStatement $stmt)
    {
        $this->stmt = $stmt;
    }

    public function execute(?array $params = null): bool
    {
        $t0 = microtime(true);
        $res = $this->stmt->execute($params);
        $dur = (microtime(true) - $t0) * 1000;
        DebugPDO::$queryCount++;
        DebugPDO::$totalMs += $dur;
        return $res;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0)
    {
        return $this->stmt->fetch($mode, $cursorOrientation, $cursorOffset);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, ...$args): array
    {
        return $this->stmt->fetchAll($mode, ...$args);
    }

    public function fetchColumn(int $column = 0)
    {
        return $this->stmt->fetchColumn($column);
    }

    public function rowCount(): int
    {
        return $this->stmt->rowCount();
    }

    public function bindValue($param, $value, int $type = PDO::PARAM_STR): bool
    {
        return $this->stmt->bindValue($param, $value, $type);
    }

    public function bindParam($param, &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, $driverOptions = null): bool
    {
        return $this->stmt->bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    public function __call(string $name, array $arguments)
    {
        return $this->stmt->$name(...$arguments);
    }
}

class DebugPDO extends PDO
{
    public static int $queryCount = 0;
    public static float $totalMs = 0.0;

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, ...$fetch_mode_args)
    {
        $t0 = microtime(true);
        $res = parent::query($query, ...($fetchMode ? array_merge([$fetchMode], $fetch_mode_args) : []));
        $dur = (microtime(true) - $t0) * 1000;
        self::$queryCount++;
        self::$totalMs += $dur;
        return $res;
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement)
    {
        $t0 = microtime(true);
        $res = parent::exec($statement);
        $dur = (microtime(true) - $t0) * 1000;
        self::$queryCount++;
        self::$totalMs += $dur;
        return $res;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        $stmt = parent::prepare($query, $options);
        return new DebugPDOStatement($stmt);
    }
}

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

            // Compute timezone offset from environment without extra DB queries
            $tz = function_exists('env') ? env('APP_TIMEZONE', 'Asia/Dhaka') : 'Asia/Dhaka';
            try {
                $offset = (new DateTime('now', new DateTimeZone($tz)))->format('P');
            } catch (Throwable $tzErr) {
                $offset = '+06:00';
            }

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // On high-traffic Linux production, persistent connections can be enabled:
                PDO::ATTR_PERSISTENT         => false,
                // Set timezone directly on initialization to save extra round-trips
                (defined('Pdo\Mysql::ATTR_INIT_COMMAND')
                    ? \Pdo\Mysql::ATTR_INIT_COMMAND
                    : (defined('PDO::MYSQL_ATTR_INIT_COMMAND') ? PDO::MYSQL_ATTR_INIT_COMMAND : 1002)
                ) => "SET time_zone = '{$offset}'",
            ];

            try {
                $isTiming = (defined('APP_ENV') && APP_ENV === 'development') || !empty($_GET['timing']);
                if ($isTiming) {
                    self::$instance = new DebugPDO($dsn, DB_USER, DB_PASS, $options);
                } else {
                    self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                }
            } catch (PDOException $e) {
                if (defined('APP_ENV') && APP_ENV === 'development') {
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
