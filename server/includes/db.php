<?php
/**
 * DOCPA Tracker — Database Connection & Helpers
 */

require_once __DIR__ . '/config.php';
get_config();

/**
 * Get a PDO database connection.
 */
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            json_error('Database connection failed: ' . $e->getMessage(), 500);
        }
    }
    return $pdo;
}

/**
 * Begin a transaction.
 */
function db_begin(): void {
    get_db()->beginTransaction();
}

/**
 * Commit a transaction.
 */
function db_commit(): void {
    get_db()->commit();
}

/**
 * Rollback a transaction.
 */
function db_rollback(): void {
    if (get_db()->inTransaction()) {
        get_db()->rollBack();
    }
}

/**
 * Execute a query and return the statement.
 */
function db_query(string $sql, array $params = []): PDOStatement {
    try {
        $stmt = get_db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        json_error('Database query error: ' . $e->getMessage(), 500);
    }
}

/**
 * Fetch a single row.
 */
function db_fetch_one(string $sql, array $params = []): ?array {
    $stmt = db_query($sql, $params);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/**
 * Fetch all rows.
 */
function db_fetch_all(string $sql, array $params = []): array {
    return db_query($sql, $params)->fetchAll();
}

/**
 * Get the last inserted ID.
 */
function db_last_id(): string {
    return get_db()->lastInsertId();
}

/**
 * JSON response helper.
 */
function json_response(mixed $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Error response helper.
 */
function json_error(string $message, int $status = 400): void {
    json_response(['error' => true, 'message' => $message], $status);
}