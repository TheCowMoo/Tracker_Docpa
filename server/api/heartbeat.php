<?php
/**
 * DOCPA Tracker — Heartbeat API
 *
 * POST /api/heartbeat.php — Send a heartbeat (marks user as active)
 * GET  /api/heartbeat.php — Check if user is currently active
 *
 * Body (JSON POST):
 *   session_id — Current session ID (optional; if not provided, finds active one)
 *   is_idle    — "1" if user is idle, "0" if active (optional; defaults to 0)
 *
 * Query params (GET):
 *   user_id    — Check specific user's status (admin only)
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

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        handle_heartbeat($user);
        break;
    case 'GET':
        handle_status($user);
        break;
    default:
        json_error('Method not allowed.', 405);
}

// ----------------------------------------------------------------

function handle_heartbeat(array $auth_user): void {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $is_idle = (int) ($input['is_idle'] ?? 0);
    $session_id = $input['session_id'] ?? null;

    if ($session_id) {
        // Verify session belongs to this user
        $session = db_fetch_one(
            'SELECT id, status FROM sessions WHERE id = ? AND user_id = ?',
            [$session_id, $auth_user['id']]
        );
        if (!$session) {
            json_error('Session not found or access denied.', 404);
        }
    } else {
        // Find the latest active/idle session
        $session = db_fetch_one(
            "SELECT id, status FROM sessions
             WHERE user_id = ? AND status IN ('active', 'idle')
             ORDER BY start_time DESC LIMIT 1",
            [$auth_user['id']]
        );
        if (!$session) {
            // No session exists — don't auto-create one; client should call upload first
            json_response([
                'success'    => true,
                'updated'    => false,
                'session_id' => null,
                'message'    => 'No active session. Start tracking by uploading a screenshot.',
            ]);
            return;
        }
    }

    $session_id = (int) $session['id'];

    // Update session status based on idle state
    $new_status = $is_idle ? 'idle' : 'active';
    db_query(
        "UPDATE sessions SET status = ?, end_time = NULL WHERE id = ?",
        [$new_status, $session_id]
    );

    // Log activity event
    db_query(
        'INSERT INTO activity_logs (user_id, session_id, event_type, event_data)
         VALUES (?, ?, ?, ?)',
        [
            $auth_user['id'],
            $session_id,
            $is_idle ? 'idle' : 'active',
            json_encode(['heartbeat' => true, 'source' => 'heartbeat']),
        ]
    );

    // Cleanup: if more than SESSION_TIMEOUT_MINUTES since last screenshot, mark gaps as idle
    $last_screenshot = db_fetch_one(
        'SELECT captured_at FROM screenshots
         WHERE session_id = ? AND is_idle = 0
         ORDER BY captured_at DESC LIMIT 1',
        [$session_id]
    );

    json_response([
        'success'    => true,
        'updated'    => true,
        'session_id' => $session_id,
        'status'     => $new_status,
        'message'    => $is_idle ? 'Marked as idle.' : 'Heartbeat received.',
    ]);
}

function handle_status(array $auth_user): void {
    $target_user_id = $auth_user['id'];

    // Admin can check any user
    if (!empty($_GET['user_id']) && $auth_user['username'] === 'admin') {
        $target_user_id = (int) $_GET['user_id'];
    }

    $session = db_fetch_one(
        "SELECT id, start_time, status, machine_name,
                TIMESTAMPDIFF(SECOND, start_time, NOW()) as elapsed_seconds
         FROM sessions
         WHERE user_id = ? AND status IN ('active', 'idle')
         ORDER BY start_time DESC LIMIT 1",
        [$target_user_id]
    );

    if (!$session) {
        json_response([
            'success' => true,
            'active'  => false,
            'message' => 'User is not currently in a session.',
        ]);
        return;
    }

    // Get last screenshot time
    $last_sc = db_fetch_one(
        'SELECT captured_at FROM screenshots
         WHERE session_id = ? ORDER BY captured_at DESC LIMIT 1',
        [$session['id']]
    );

    $idle_minutes = 0;
    if ($last_sc) {
        $last_ts = strtotime($last_sc['captured_at']);
        $idle_minutes = round((time() - $last_ts) / 60);
    }

    json_response([
        'success'        => true,
        'active'         => $session['status'] === 'active',
        'session_id'     => (int) $session['id'],
        'status'         => $session['status'],
        'started_at'     => $session['start_time'],
        'elapsed_seconds'=> (int) $session['elapsed_seconds'],
        'machine_name'   => $session['machine_name'],
        'idle_minutes'   => $idle_minutes,
    ]);
}