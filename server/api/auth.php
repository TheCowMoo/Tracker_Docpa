<?php
/**
 * DOCPA Tracker — Authentication API
 *
 * POST /api/auth.php?action=register — Register a new user (generates API key)
 * POST /api/auth.php?action=login    — Get user info by API key
 * GET  /api/auth.php?action=verify   — Verify an API key is valid
 *
 * Body (JSON):
 *   register: { "username": "...", "display_name": "..." }
 *   login:    { "api_key": "..." }
 */

require_once __DIR__ . '/../includes/db.php';

header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGINS);
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'register':
            handle_register();
            break;
        case 'login':
            handle_login();
            break;
        case 'verify':
            handle_verify();
            break;
        default:
            json_error('Unknown action. Use ?action=register, ?action=login, or ?action=verify.', 400);
    }
} catch (\Throwable $e) {
    json_error('Internal server error: ' . $e->getMessage(), 500);
}

// ----------------------------------------------------------------

function handle_register(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['username'])) {
        json_error('Username is required.', 400);
    }

    $username = trim($input['username']);
    $display_name = trim($input['display_name'] ?? $username);

    // Check if user already exists
    $existing = db_fetch_one('SELECT id FROM users WHERE username = ?', [$username]);
    if ($existing) {
        json_error('Username already exists.', 409);
    }

    // Generate API key
    $api_key = bin2hex(random_bytes(API_KEY_BYTES));

    // Create user
    db_query(
        'INSERT INTO users (username, display_name, api_key) VALUES (?, ?, ?)',
        [$username, $display_name, $api_key]
    );

    $user_id = db_last_id();

    json_response([
        'success'  => true,
        'user'     => [
            'id'       => (int) $user_id,
            'username' => $username,
            'api_key'  => $api_key,
        ],
        'message'  => 'User registered successfully. Save your API key — it will not be shown again.',
    ], 201);
}

function handle_login(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $api_key = $input['api_key'] ?? '';

    if (empty($api_key)) {
        json_error('API key is required.', 400);
    }

    $user = db_fetch_one(
        'SELECT id, username, display_name, is_active, created_at FROM users WHERE api_key = ?',
        [$api_key]
    );

    if (!$user) {
        json_error('Invalid API key.', 401);
    }

    if (!$user['is_active']) {
        json_error('Account is disabled.', 403);
    }

    json_response([
        'success' => true,
        'user'    => $user,
    ]);
}

function handle_verify(): void {
    require_once __DIR__ . '/../includes/auth_middleware.php';
    $user = authenticate();
    json_response([
        'success' => true,
        'user'    => [
            'id'           => (int) $user['id'],
            'username'     => $user['username'],
            'display_name' => $user['display_name'],
        ],
    ]);
}