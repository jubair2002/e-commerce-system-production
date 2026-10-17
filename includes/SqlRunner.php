<?php
/**
 * SqlRunner — portable executor for MySQL / MariaDB SQL files through PDO.
 *
 * Handles everything a real MySQL client does that a naive
 * `explode(';') + exec()` does not:
 *
 *   • Strings      '..'  ".."       (with '' / "" and backslash escapes)
 *   • Identifiers  `..`             (with `` escape)
 *   • Comments     -- …   # …   /* … *\/
 *   • DELIMITER    // … //
 *   • Buffered     result sets are fully drained so the next query never
 *                  fails with "Cannot execute queries while other unbuffered
 *                  queries are active" (error 2014).
 *
 * Works on MySQL 5.7 / 8.0 and MariaDB 10.x; PHP 7.4+.
 */
final class SqlRunner
{
    /** @var PDO */
    private $pdo;

    /** @var int */
    private $currentLine = 0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;

        // Make absolutely sure every result set is buffered client-side.
        $driverOpts = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? $this->driverOptions($pdo)
            : null;
        if ($driverOpts !== null) {
            $pdo->setAttribute($driverOpts, true);
        }
    }

    /** Return the MYSQL_ATTR_USE_BUFFERED_QUERY constant if it exists. */
    private function driverOptions(PDO $pdo): ?int
    {
        return defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')
            ? PDO::MYSQL_ATTR_USE_BUFFERED_QUERY
            : null;
    }

    /**
     * Execute a whole .sql file.
     *
     * @param string   $path
     * @param string[] $skipPatterns   regexes; matching statements are skipped
     *                                 (default: skip CREATE DATABASE / USE, so
     *                                 the schema can be applied to any DB name)
     * @return int  number of statements executed
     */
    public function runFile(
        string $path,
        array  $skipPatterns = ['/^\s*CREATE\s+DATABASE\b/i', '/^\s*USE\b/i']
    ): int {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("SQL file not readable: $path");
        }
        return $this->runStatements($this->split((string) file_get_contents($path)), $skipPatterns);
    }

    /** Execute a raw SQL string. */
    public function run(
        string $sql,
        array  $skipPatterns = ['/^\s*CREATE\s+DATABASE\b/i', '/^\s*USE\b/i']
    ): int {
        return $this->runStatements($this->split($sql), $skipPatterns);
    }

    /** Execute a list of pre-split statements. */
    public function runStatements(array $statements, array $skipPatterns = []): int
    {
        $executed = 0;

        foreach ($statements as $i => $sql) {
            foreach ($skipPatterns as $pattern) {
                if (preg_match($pattern, $sql)) {
                    continue 2;
                }
            }

            try {
                $this->execute($sql);
                $executed++;
            } catch (Throwable $e) {
                $preview = preg_replace('/\s+/', ' ', substr($sql, 0, 200));
                throw new RuntimeException(
                    'Statement #' . ($i + 1) . ' failed: ' . $e->getMessage()
                    . "\n  SQL: " . $preview . (strlen($sql) > 200 ? '…' : ''),
                    0,
                    $e
                );
            }
        }

        return $executed;
    }

    /**
     * Run one statement and *fully* drain every result set it produces.
     *
     * This is what makes EXECUTE-of-SELECT safe to follow with DEALLOCATE,
     * and it's what makes CALL / stored procedures safe in general.
     */
    private function execute(string $sql): void
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            $info = $this->pdo->errorInfo();
            throw new RuntimeException($info[2] ?? 'Unknown SQL error');
        }

        // Consume the primary result set (may be empty for DDL/DML).
        try {
            $stmt->fetchAll();
        } catch (Throwable $e) {
            // statement produced no result set — that's fine
        }

        // Consume any extra result sets (PREPARE/EXECUTE, CALL, …).
        while ($this->safeNextRowset($stmt)) {
            try {
                $stmt->fetchAll();
            } catch (Throwable $e) {
                // ignore
            }
        }

        try {
            $stmt->closeCursor();
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function safeNextRowset(PDOStatement $stmt): bool
    {
        try {
            return $stmt->nextRowset();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Split a MySQL dump into executable statements.
     * Correctly ignores ; inside strings, identifiers, and comments,
     * and respects DELIMITER directives.
     *
     * @return string[]
     */
    public function split(string $sql): array
    {
        $out   = [];
        $buf   = '';
        $len   = strlen($sql);
        $delim = ';';
        $i     = 0;

        // normal | single | double | backtick | line_comment | block_comment
        $state = 'normal';

        while ($i < $len) {
            $ch   = $sql[$i];
            $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

            // ----- DELIMITER directive (must start a line, normal state) -----
            if ($state === 'normal'
                && ($i === 0 || $sql[$i - 1] === "\n")
                && $this->keywordAt($sql, $i, 'DELIMITER')
            ) {
                $eol  = strpos($sql, "\n", $i);
                if ($eol === false) {
                    $eol = $len;
                }
                $line = trim(substr($sql, $i + 9, $eol - ($i + 9)));
                if ($line !== '') {
                    $delim = $line;
                }
                $buf = '';
                $i   = $eol + 1;
                continue;
            }

            switch ($state) {
                case 'normal':
                    // line comment
                    if ($ch === '-' && $next === '-'
                        && ($i + 2 >= $len || ctype_space($sql[$i + 2]))
                    ) {
                        $state = 'line_comment';
                        $i += 2;
                        continue 2;
                    }
                    if ($ch === '#') {
                        $state = 'line_comment';
                        $i++;
                        continue 2;
                    }
                    // block comment
                    if ($ch === '/' && $next === '*') {
                        $state = 'block_comment';
                        $i += 2;
                        continue 2;
                    }
                    // quotes
                    if ($ch === "'") { $state = 'single';   $buf .= $ch; $i++; continue 2; }
                    if ($ch === '"') { $state = 'double';   $buf .= $ch; $i++; continue 2; }
                    if ($ch === '`') { $state = 'backtick'; $buf .= $ch; $i++; continue 2; }

                    // statement terminator (may be multi-char, e.g. //)
                    if (substr($sql, $i, strlen($delim)) === $delim) {
                        $stmt = trim($buf);
                        if ($stmt !== '') {
                            $out[] = $stmt;
                        }
                        $buf = '';
                        $i += strlen($delim);
                        continue 2;
                    }

                    $buf .= $ch;
                    $i++;
                    break;

                case 'single':
                    $buf .= $ch;
                    if ($ch === '\\' && $next !== '') {
                        $buf .= $next;
                        $i += 2;
                        continue 2;
                    }
                    if ($ch === "'") {
                        if ($next === "'") { $buf .= $next; $i += 2; continue 2; }
                        $state = 'normal';
                    }
                    $i++;
                    break;

                case 'double':
                    $buf .= $ch;
                    if ($ch === '\\' && $next !== '') {
                        $buf .= $next;
                        $i += 2;
                        continue 2;
                    }
                    if ($ch === '"') {
                        if ($next === '"') { $buf .= $next; $i += 2; continue 2; }
                        $state = 'normal';
                    }
                    $i++;
                    break;

                case 'backtick':
                    $buf .= $ch;
                    if ($ch === '`') {
                        if ($next === '`') { $buf .= $next; $i += 2; continue 2; }
                        $state = 'normal';
                    }
                    $i++;
                    break;

                case 'line_comment':
                    if ($ch === "\n") {
                        $state = 'normal';
                        $buf  .= "\n"; // preserve newline so "SELECT 1 --c\n;" still splits
                    }
                    $i++;
                    break;

                case 'block_comment':
                    if ($ch === '*' && $next === '/') {
                        $state = 'normal';
                        $i += 2;
                        continue 2;
                    }
                    $i++;
                    break;
            }
        }

        $stmt = trim($buf);
        if ($stmt !== '') {
            $out[] = $stmt;
        }

        return $out;
    }

    private function keywordAt(string $s, int $pos, string $kw): bool
    {
        $kl = strlen($kw);
        if (strcasecmp(substr($s, $pos, $kl), $kw) !== 0) {
            return false;
        }
        return !($pos + $kl < strlen($s) && preg_match('/[A-Za-z0-9_]/', $s[$pos + $kl]));
    }
}
