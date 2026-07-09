<?php
/**
 * DOCPA Tracker — Authentication Middleware
 *
 * Include this file at the top of any endpoint that requires authentication.
 * Expects either:
 *   1. Authorization: Bearer <api_key> header, OR
 *   2. X-API-Key: <api_key> header, OR
 *   3. ?api_key=<api_key> query parameter
 */

require_once __DIR__ . '/db.php';

/**
 * Authenticate the request and return the user array.
 * Calls json_error() and exits if auth fails.
 */
function authenticate(): array {
    $api_key = '';

    // 1. Check Authorization: Bearer header
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $parts = explode(' ', $_SERVER['HTTP_AUTHORIZATION'], 2);
        if (count($parts) === 2 && strtolower($parts[0]) === 'bearer') {
            $api_key = trim($parts[1]);
        }
    }

    // 2. Check X-API-Key header
    if (empty($api_key) && !empty($_SERVER['HTTP_X_API_KEY'])) {
        $api_key = trim($_SERVER['HTTP_X_API_KEY']);
    }

    // 3. Check query parameter
    if (empty($api_key) && !empty($_GET['api_key'])) {
        $api_key = trim($_GET['api_key']);
    }

    if (empty($api_key)) {
        json_error('Authentication required. Provide API key via Authorization header or ?api_key= parameter.', 401);
    }

    $user = db_fetch_one(
        'SELECT id, username, display_name, api_key, email, is_active, created_at
         FROM users WHERE api_key = ? AND is_active = 1',
        [$api_key]
    );

    if (!$user) {
        json_error('Invalid or inactive API key.', 401);
    }

    return $user;
}

/**
 * Get the authenticated user without exiting on failure.
 * Returns null if not authenticated.
 */
function try_authenticate(): ?array {
    $api_key = '';

    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $parts = explode(' ', $_SERVER['HTTP_AUTHORIZATION'], 2);
        if (count($parts) === 2 && strtolower($parts[0]) === 'bearer') {
            $api_key = trim($parts[1]);
        }
    }

    if (empty($api_key) && !empty($_SERVER['HTTP_X_API_KEY'])) {
        $api_key = trim($_SERVER['HTTP_X_API_KEY']);
    }

    if (empty($api_key) && !empty($_GET['api_key'])) {
        $api_key = trim($_GET['api_key']);
    }

    if (empty($api_key)) {
        return null;
    }

    return db_fetch_one(
        'SELECT id, username, display_name, api_key, is_active, created_at
         FROM users WHERE api_key = ? AND is_active = 1',
        [$api_key]
    );
}