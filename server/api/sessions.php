<?php
/**
 * DOCPA Tracker — Sessions API
 *
 * GET /api/sessions.php — List sessions (with optional filters)
 * POST /api/sessions.php?action=end — End the current active session
 * GET /api/sessions.php?action=current — Get the current active session
 *
 * Query params for listing:
 *   user_id    — Filter by user ID (default: authenticated user)
 *   from       — Start date (Y-m-d)
 *   to         — End date (Y-m-d)
 *   status     — active|idle|ended
 *   limit      — Max results (default 50)
 *   offset     — Pagination offset (default 0)
 */

require_once __DIR__ . '/../includes/auth_middleware.php';

header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGINS);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$user = authenticate();
$action = $_GET['action'] ?? '';

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if ($action === 'current') {
            handle_get_current($user);
        } else {
            handle_list($user);
        }
        break;
    case 'POST':
        if ($action === 'end') {
            handle_end($user);
        } else {
            json_error('Unknown action. Use ?action=end', 400);
        }
        break;
    default:
        json_error('Method not allowed.', 405);
}

// ----------------------------------------------------------------

function handle_list(array $auth_user): void {
    $where = ['s.user_id = ?'];
    $params = [$auth_user['id']];

    // Admin can view any user's sessions
    if (!empty($_GET['user_id']) && $auth_user['username'] === 'admin') {
        $where = ['s.user_id = ?'];
        $params = [(int) $_GET['user_id']];
    }

    if (!empty($_GET['from'])) {
        $where[] = 's.start_time >= ?';
        $params[] = $_GET['from'] . ' 00:00:00';
    }

    if (!empty($_GET['to'])) {
        $where[] = 's.start_time <= ?';
        $params[] = $_GET['to'] . ' 23:59:59';
    }

    if (!empty($_GET['status'])) {
        $where[] = 's.status = ?';
        $params[] = $_GET['status'];
    }

    $limit = min((int) ($_GET['limit'] ?? 50), 500);
    $offset = max((int) ($_GET['offset'] ?? 0), 0);

    $where_clause = implode(' AND ', $where);

    // Get total count
    $count_row = db_fetch_one(
        "SELECT COUNT(*) as total FROM sessions s WHERE $where_clause",
        $params
    );
    $total = (int) ($count_row['total'] ?? 0);

    // Get sessions
    $sessions = db_fetch_all(
        "SELECT s.id, s.user_id, u.display_name, s.start_time, s.end_time,
                s.total_active_seconds, s.total_idle_seconds, s.status, s.machine_name
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE $where_clause
         ORDER BY s.start_time DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );

    // Add screenshot count per session
    foreach ($sessions as &$session) {
        $sc = db_fetch_one(
            'SELECT COUNT(*) as cnt FROM screenshots WHERE session_id = ?',
            [$session['id']]
        );
        $session['screenshot_count'] = (int) ($sc['cnt'] ?? 0);
    }
    unset($session);

    json_response([
        'success'  => true,
        'sessions' => $sessions,
        'total'    => $total,
        'limit'    => $limit,
        'offset'   => $offset,
    ]);
}

function handle_get_current(array $auth_user): void {
    $session = db_fetch_one(
        "SELECT s.id, s.user_id, u.display_name, s.start_time, s.end_time,
                s.total_active_seconds, s.total_idle_seconds, s.status, s.machine_name
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.user_id = ? AND s.status IN ('active', 'idle')
         ORDER BY s.start_time DESC LIMIT 1",
        [$auth_user['id']]
    );

    if (!$session) {
        json_response([
            'success' => true,
            'session' => null,
            'message' => 'No active session.',
        ]);
        return;
    }

    // Count screenshots
    $sc = db_fetch_one(
        'SELECT COUNT(*) as cnt FROM screenshots WHERE session_id = ?',
        [$session['id']]
    );
    $session['screenshot_count'] = (int) ($sc['cnt'] ?? 0);

    // Calculate elapsed time
    $start = new DateTime($session['start_time']);
    $now   = new DateTime();
    $elapsed = $start->diff($now);
    $session['elapsed_seconds'] = $start->getTimestamp() - $now->getTimestamp();

    json_response([
        'success' => true,
        'session' => $session,
    ]);
}

function handle_end(array $auth_user): void {
    $session = db_fetch_one(
        "SELECT id, start_time FROM sessions
         WHERE user_id = ? AND status IN ('active', 'idle')
         ORDER BY start_time DESC LIMIT 1",
        [$auth_user['id']]
    );

    if (!$session) {
        json_error('No active session to end.', 404);
    }

    // Calculate total active/idle time from screenshots
    $stats = db_fetch_one(
        "SELECT
            COUNT(*) as total_shots,
            SUM(CASE WHEN is_idle = 0 THEN 1 ELSE 0 END) as active_shots,
            SUM(CASE WHEN is_idle = 1 THEN 1 ELSE 0 END) as idle_shots
         FROM screenshots WHERE session_id = ?",
        [$session['id']]
    );

    $total_shots   = max((int) ($stats['total_shots'] ?? 0), 1);
    $active_shots  = (int) ($stats['active_shots'] ?? 0);
    $idle_shots    = (int) ($stats['idle_shots'] ?? 0);

    // Estimate time distribution based on screenshot ratio
    $start_ts = strtotime($session['start_time']);
    $elapsed = max(time() - $start_ts, 0);
    $total_active = (int) round(($active_shots / $total_shots) * $elapsed);
    $total_idle   = (int) round(($idle_shots / $total_shots) * $elapsed);

    db_query(
        "UPDATE sessions SET status = 'ended', end_time = NOW(),
         total_active_seconds = ?, total_idle_seconds = ?
         WHERE id = ?",
        [$total_active, $total_idle, $session['id']]
    );

    // Log session end
    db_query(
        'INSERT INTO activity_logs (user_id, session_id, event_type, event_data)
         VALUES (?, ?, "session_end", ?)',
        [$auth_user['id'], $session['id'], json_encode([
            'elapsed_seconds' => $elapsed,
            'active_seconds'  => $total_active,
            'idle_seconds'    => $total_idle,
        ])]
    );

    json_response([
        'success'        => true,
        'session_id'     => (int) $session['id'],
        'duration'       => $elapsed,
        'active_seconds' => $total_active,
        'idle_seconds'   => $total_idle,
        'message'        => 'Session ended.',
    ]);
}