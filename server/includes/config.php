<?php
/**
 * DOCPA Tracker — Configuration
 *
 * Loads config.local.php first if it exists, then sets defaults
 * for any constants not already defined.
 */

// Load local override first (DB credentials, storage paths)
$local_config = __DIR__ . '/config.local.php';
if (file_exists($local_config)) {
    require_once $local_config;
}

// Defaults — only set if not already defined by config.local.php
defined('DB_HOST')              or define('DB_HOST', 'localhost');
defined('DB_PORT')              or define('DB_PORT', 3306);
defined('DB_NAME')              or define('DB_NAME', 'docpa_tracker');
defined('DB_USER')              or define('DB_USER', 'docpa');
defined('DB_PASS')              or define('DB_PASS', '');

defined('SCREENSHOTS_DIR')      or define('SCREENSHOTS_DIR', __DIR__ . '/../data/screenshots/');
defined('THUMBNAILS_DIR')       or define('THUMBNAILS_DIR', __DIR__ . '/../data/thumbnails/');
defined('MAX_SCREENSHOT_AGE_DAYS') or define('MAX_SCREENSHOT_AGE_DAYS', 90);
defined('THUMBNAIL_WIDTH')      or define('THUMBNAIL_WIDTH', 320);
defined('THUMBNAIL_HEIGHT')     or define('THUMBNAIL_HEIGHT', 240);

defined('API_KEY_BYTES')        or define('API_KEY_BYTES', 32);
defined('SESSION_TIMEOUT_MINUTES') or define('SESSION_TIMEOUT_MINUTES', 5);
defined('MAX_UPLOAD_SIZE_MB')   or define('MAX_UPLOAD_SIZE_MB', 5);
defined('ALLOWED_ORIGINS')      or define('ALLOWED_ORIGINS', '*');

defined('KEEP_FULL_RES_DAYS')   or define('KEEP_FULL_RES_DAYS', 7);
defined('KEEP_THUMBNAIL_DAYS')  or define('KEEP_THUMBNAIL_DAYS', 90);