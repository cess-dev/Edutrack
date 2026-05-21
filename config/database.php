<?php
/**
 * EduTrack — Database Connection Singleton
 *
 * Provides a single shared PDO instance for the entire request lifecycle.
 * Every model, controller, and API endpoint calls DB::connect() to get
 * the connection — no credentials are ever repeated anywhere else.
 *
 * Usage:
 *   $pdo = DB::connect();
 *   $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
 *   $stmt->execute([$id]);
 *   $row = $stmt->fetch();
 */

if (!defined('EDUTRACK_LOADED')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class DB
{
    /** @var PDO|null Holds the single shared connection */
    private static ?PDO $instance = null;

    /**
     * Returns the shared PDO connection, creating it on first call.
     *
     * PDO is configured with:
     *  - ERRMODE_EXCEPTION  — all DB errors throw a PDOException (catchable)
     *  - FETCH_ASSOC        — rows returned as associative arrays by default
     *  - EMULATE_PREPARES false — uses real prepared statements (safer)
     *  - charset utf8mb4    — full Unicode support in the DSN string
     *
     * @throws RuntimeException if the connection cannot be established
     * @return PDO
     */
    public static function connect(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci",
        ];

        try {
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log the real error internally; show nothing sensitive to the browser
            self::logConnectionError($e->getMessage());

            if (APP_ENV === 'development') {
                throw new RuntimeException(
                    'Database connection failed: ' . $e->getMessage()
                );
            }

            throw new RuntimeException(
                'A database error occurred. Please contact the system administrator.'
            );
        }

        return self::$instance;
    }

    /**
     * Explicitly close the connection.
     * Rarely needed — PHP closes it at end of request automatically —
     * but useful in long-running scripts or batch jobs.
     */
    public static function disconnect(): void
    {
        self::$instance = null;
    }

    /**
     * Convenience wrapper: prepare + execute in one call.
     * Returns the executed PDOStatement so you can call fetch/fetchAll.
     *
     * Example:
     *   $stmt = DB::query("SELECT * FROM users WHERE role = ? AND is_active = ?", ['student', 1]);
     *   $students = $stmt->fetchAll();
     *
     * @param  string $sql    Parameterised SQL string
     * @param  array  $params Values to bind (positional ? placeholders)
     * @return PDOStatement
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row. Returns null if no row matches.
     *
     * Example:
     *   $user = DB::row("SELECT * FROM users WHERE id = ?", [$id]);
     *
     * @param  string $sql
     * @param  array  $params
     * @return array|null
     */
    public static function row(string $sql, array $params = []): ?array
    {
        $result = self::query($sql, $params)->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Fetch all matching rows as an array of associative arrays.
     *
     * Example:
     *   $units = DB::rows("SELECT * FROM units WHERE course_id = ?", [$courseId]);
     *
     * @param  string $sql
     * @param  array  $params
     * @return array
     */
    public static function rows(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Run an INSERT, UPDATE, or DELETE and return the number of affected rows.
     *
     * Example:
     *   $affected = DB::execute(
     *       "UPDATE users SET is_active = ? WHERE id = ?",
     *       [0, $userId]
     *   );
     *
     * @param  string $sql
     * @param  array  $params
     * @return int  Number of rows affected
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /**
     * Run an INSERT and return the auto-incremented ID of the new row.
     *
     * Example:
     *   $newId = DB::insert(
     *       "INSERT INTO users (full_name, email, role) VALUES (?, ?, ?)",
     *       ['Jane Doe', 'jane@school.local', 'student']
     *   );
     *
     * @param  string $sql
     * @param  array  $params
     * @return string  Last insert ID (string because PDO returns it that way)
     */
    public static function insert(string $sql, array $params = []): string
    {
        self::query($sql, $params);
        return self::connect()->lastInsertId();
    }

    /**
     * Begin a transaction.
     * Use with commit() / rollback() for multi-step operations
     * (e.g. creating a user AND linking them to a parent in one atomic block).
     */
    public static function beginTransaction(): void
    {
        self::connect()->beginTransaction();
    }

    /** Commit the active transaction. */
    public static function commit(): void
    {
        self::connect()->commit();
    }

    /** Roll back the active transaction on error. */
    public static function rollback(): void
    {
        self::connect()->rollBack();
    }

    /**
     * Write a connection error to the log file without exposing
     * credentials or DSN details to output.
     */
    private static function logConnectionError(string $message): void
    {
        $logDir  = ROOT_PATH . '/logs';
        $logFile = $logDir . '/db_errors.log';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }

        $entry = sprintf(
            "[%s] DB connection error: %s\n",
            date('Y-m-d H:i:s'),
            $message
        );

        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /** Prevent instantiation — this class is used statically only */
    private function __construct() {}

    /** Prevent cloning */
    private function __clone() {}
}