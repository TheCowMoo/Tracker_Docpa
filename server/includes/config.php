<?php
/**
 * DOCPA Tracker — Configuration
 *
 * Copy this file to config.local.php and fill in your actual credentials.
 * config.local.php is gitignored and takes precedence if it exists.
 */

// Database
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'docpa_tracker');
define('DB_USER', 'docpa');
define('DB_PASS', '');

// Storage
define('SCREENSHOTS_DIR', __DIR__ . '/../data/screenshots/');
define('THUMBNAILS_DIR', __DIR__ . '/../data/thumbnails/');
define('MAX_SCREENSHOT_AGE_DAYS', 90);
define('THUMBNAIL_WIDTH', 320);
define('THUMBNAIL_HEIGHT', 240);

// Session & Security
define('API_KEY_BYTES', 32);
define('SESSION_TIMEOUT_MINUTES', 5);
define('MAX_UPLOAD_SIZE_MB', 5);
define('ALLOWED_ORIGINS', '*');

// Upload retention
define('KEEP_FULL_RES_DAYS', 7);
define('KEEP_THUMBNAIL_DAYS', 90);

/**
 * Get effective config (load local override if present)
 */
function get_config(): void {
    $local = __DIR__ . '/config.local.php';
    if (file_exists($local)) {
        require_once $local;
    }
}