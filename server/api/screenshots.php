<?php
/**
 * DOCPA Tracker — Screenshots API
 *
 * GET /api/screenshots.php — List screenshots for a session
 * GET /api/screenshots.php?action=image — Serve a screenshot image file
 *
 * Query params for listing:
 *   session_id — Session ID (required)
 *   from       — Start datetime (Y-m-d H:i:s)
 *   to         — End datetime (Y-m-d H:i:s)
 *   is_idle    — Filter: 0=active only, 1=idle only, omit=all
 *   limit      — Max results (default 100)
 *   offset     — Pagination offset (default 0)
 *
 * For serving an image:
 *   filename   — The filename stored in DB (e.g., 20260710_120000_1_abc123.jpg)
 *   thumbnail  — Set to "1" to return thumbnail instead of full image
 */

require_once __DIR__ . '/../includes/auth_middleware.php';

header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGINS);
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed. Use GET.', 405);
}

$user = authenticate();
$action = $_GET['action'] ?? '';

if ($action === 'image') {
    handle_serve_image($user);
} else {
    handle_list($user);
}

// ----------------------------------------------------------------

function handle_list(array $auth_user): void {
    $session_id = (int) ($_GET['session_id'] ?? 0);
    if ($session_id <= 0) {
        json_error('session_id is required.', 400);
    }

    // Verify the session belongs to the user (or user is admin)
    $session = db_fetch_one(
        'SELECT user_id FROM sessions WHERE id = ?',
        [$session_id]
    );
    if (!$session) {
        json_error('Session not found.', 404);
    }
    if ($session['user_id'] !== $auth_user['id'] && $auth_user['username'] !== 'admin') {
        json_error('Access denied.', 403);
    }

    $where = ['session_id = ?'];
    $params = [$session_id];

    if (!empty($_GET['from'])) {
        $where[] = 'captured_at >= ?';
        $params[] = $_GET['from'];
    }
    if (!empty($_GET['to'])) {
        $where[] = 'captured_at <= ?';
        $params[] = $_GET['to'];
    }
    if (isset($_GET['is_idle']) && $_GET['is_idle'] !== '') {
        $where[] = 'is_idle = ?';
        $params[] = (int) $_GET['is_idle'];
    }

    $limit = min((int) ($_GET['limit'] ?? 100), 500);
    $offset = max((int) ($_GET['offset'] ?? 0), 0);
    $where_clause = implode(' AND ', $where);

    // Count
    $count_row = db_fetch_one(
        "SELECT COUNT(*) as total FROM screenshots WHERE $where_clause",
        $params
    );
    $total = (int) ($count_row['total'] ?? 0);

    // Fetch
    $screenshots = db_fetch_all(
        "SELECT id, session_id, file_path, thumbnail_path, captured_at, is_idle, file_size_bytes, width, height
         FROM screenshots
         WHERE $where_clause
         ORDER BY captured_at DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );

    // Add public URLs
    $base_url = get_base_url();
    foreach ($screenshots as &$sc) {
        $sc['image_url']      = $base_url . '/api/screenshots.php?action=image&filename=' . urlencode($sc['file_path']) . '&api_key=' . urlencode($auth_user['api_key']);
        $sc['thumbnail_url']  = !empty($sc['thumbnail_path'])
            ? $base_url . '/api/screenshots.php?action=image&thumbnail=1&filename=' . urlencode($sc['thumbnail_path']) . '&api_key=' . urlencode($auth_user['api_key'])
            : null;
    }
    unset($sc);

    json_response([
        'success'     => true,
        'screenshots' => $screenshots,
        'total'       => $total,
        'limit'       => $limit,
        'offset'      => $offset,
    ]);
}

function handle_serve_image(array $auth_user): void {
    $filename  = $_GET['filename'] ?? '';
    $thumbnail = ($_GET['thumbnail'] ?? '') === '1';

    if (empty($filename)) {
        json_error('filename is required.', 400);
    }

    // Security: prevent directory traversal
    $filename = basename($filename);

    // Check if this screenshot belongs to the user (or user is admin)
    $sc = db_fetch_one(
        'SELECT id, user_id, file_path, thumbnail_path FROM screenshots WHERE file_path = ? OR thumbnail_path = ?',
        [$filename, $filename]
    );
    if (!$sc) {
        // If not in DB yet, allow viewing if filename is in the storage dir
        $dir = $thumbnail ? THUMBNAILS_DIR : SCREENSHOTS_DIR;
        $path = $dir . $filename;
        if (!file_exists($path)) {
            json_error('File not found.', 404);
        }
    } else {
        if ($sc['user_id'] !== $auth_user['id'] && $auth_user['username'] !== 'admin') {
            json_error('Access denied.', 403);
        }
        $dir = $thumbnail ? THUMBNAILS_DIR : SCREENSHOTS_DIR;
        $path = $dir . $filename;
        if (!file_exists($path)) {
            json_error('File not found on disk.', 404);
        }
    }

    // Serve the image
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime_types = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];
    $mime = $mime_types[$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

/**
 * Get the base URL of the current request.
 */
function get_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "$scheme://$host";
}