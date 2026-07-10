<?php
/**
 * DOCPA Tracker — Screenshot Upload API
 *
 * POST /api/upload.php
 *
 * Multipart form-data:
 *   file     — JPEG image file (required)
 *   api_key  — User API key (or use Authorization header)
 *   is_idle  — "1" if user was idle when captured (optional)
 *   session_id — Existing session ID to continue (optional)
 *   width    — Screen width in pixels (optional)
 *   height   — Screen height in pixels (optional)
 *   machine  — Machine hostname (optional)
 */

require_once __DIR__ . '/../includes/auth_middleware.php';

header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGINS);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed. Use POST.', 405);
}

// Authenticate
$user = authenticate();

try {

// Validate file
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_error('File upload failed or no file provided.', 400);
}

$file = $_FILES['file'];

// Validate file size
$max_bytes = MAX_UPLOAD_SIZE_MB * 1024 * 1024;
if ($file['size'] > $max_bytes) {
    json_error('File too large. Maximum ' . MAX_UPLOAD_SIZE_MB . ' MB.', 413);
}

// Validate mime type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
    json_error('Only JPEG, PNG, or WebP images are accepted.', 415);
}

// Ensure storage directories exist
$screenshots_dir = SCREENSHOTS_DIR;
$thumbnails_dir  = THUMBNAILS_DIR;
if (!is_dir($screenshots_dir)) {
    mkdir($screenshots_dir, 0755, true);
}
if (!is_dir($thumbnails_dir)) {
    mkdir($thumbnails_dir, 0755, true);
}

// Generate unique filename
$ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
$filename = date('Ymd_His') . '_' . $user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$filepath = $screenshots_dir . $filename;
$thumbpath = $thumbnails_dir . 'thumb_' . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    json_error('Failed to save file.', 500);
}

// Create thumbnail
$thumbnail_created = false;
try {
    list($orig_w, $orig_h) = getimagesize($filepath);
} catch (\Throwable $e) {
    $orig_w = 0;
    $orig_h = 0;
}

if ($orig_w > 0 && $orig_h > 0) {
    try {
        $src_image = imagecreatefromstring(file_get_contents($filepath));
        if ($src_image) {
            $tw = THUMBNAIL_WIDTH;
            $th = THUMBNAIL_HEIGHT;
            $ratio = min($tw / $orig_w, $th / $orig_h);
            $new_w = (int) round($orig_w * $ratio);
            $new_h = (int) round($orig_h * $ratio);
            $thumb_image = imagecreatetruecolor($new_w, $new_h);
            imagecopyresampled($thumb_image, $src_image, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
            imagejpeg($thumb_image, $thumbpath, 60);
            imagedestroy($thumb_image);
            imagedestroy($src_image);
            $thumbnail_created = true;
        }
    } catch (\Throwable $e) {
        // Thumbnail failure is non-fatal
    }
}

// Session management
$is_idle = ($_POST['is_idle'] ?? '0') === '1';
$machine = $_POST['machine'] ?? gethostname();
$screen_w = (int) ($_POST['width'] ?? $orig_w);
$screen_h = (int) ($_POST['height'] ?? $orig_h);
$session_id = null;

// Find or create an active session
$existing_session = db_fetch_one(
    'SELECT id FROM sessions
     WHERE user_id = ? AND status IN ("active", "idle")
     ORDER BY start_time DESC LIMIT 1',
    [$user['id']]
);

if ($existing_session) {
    $session_id = (int) $existing_session['id'];
    // Update session status based on idle state
    $new_status = $is_idle ? 'idle' : 'active';
    db_query(
        'UPDATE sessions SET status = ?, machine_name = ? WHERE id = ?',
        [$new_status, $machine, $session_id]
    );
} else {
    // Start a new session
    db_query(
        'INSERT INTO sessions (user_id, start_time, status, ip_address, machine_name)
         VALUES (?, NOW(), ?, ?, ?)',
        [$user['id'], $is_idle ? 'idle' : 'active', $_SERVER['REMOTE_ADDR'] ?? '', $machine]
    );
    $session_id = (int) db_last_id();
}

// Insert screenshot record
$file_size = filesize($filepath);
db_query(
    'INSERT INTO screenshots (session_id, user_id, file_path, thumbnail_path, captured_at, is_idle, file_size_bytes, width, height)
     VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?)',
    [
        $session_id,
        $user['id'],
        $filename,
        $thumbnail_created ? 'thumb_' . $filename : null,
        $is_idle ? 1 : 0,
        $file_size,
        $screen_w,
        $screen_h,
    ]
);

$screenshot_id = (int) db_last_id();

// Log activity
db_query(
    'INSERT INTO activity_logs (user_id, session_id, event_type, event_data)
     VALUES (?, ?, ?, ?)',
    [
        $user['id'],
        $session_id,
        $is_idle ? 'idle' : 'active',
        json_encode(['screenshot_id' => $screenshot_id, 'file_size' => $file_size]),
    ]
);

// Cleanup old screenshots (runs periodically ~1% of uploads)
if (random_int(1, 100) === 50) {
    cleanup_old_files();
}

json_response([
    'success'      => true,
    'session_id'   => $session_id,
    'screenshot_id'=> $screenshot_id,
    'filename'     => $filename,
    'thumbnail'    => $thumbnail_created,
    'is_idle'      => $is_idle,
], 201);

} catch (\Throwable $e) {
    json_error('Upload error: ' . $e->getMessage(), 500);
}

// ----------------------------------------------------------------

function cleanup_old_files(): void {
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . MAX_SCREENSHOT_AGE_DAYS . ' days'));

    $old_screenshots = db_fetch_all(
        'SELECT id, file_path, thumbnail_path FROM screenshots WHERE captured_at < ?',
        [$cutoff]
    );

    foreach ($old_screenshots as $s) {
        // Delete physical files
        $full = SCREENSHOTS_DIR . $s['file_path'];
        if (file_exists($full)) @unlink($full);

        if (!empty($s['thumbnail_path'])) {
            $thumb = THUMBNAILS_DIR . $s['thumbnail_path'];
            if (file_exists($thumb)) @unlink($thumb);
        }
    }

    // Delete DB records
    db_query('DELETE FROM screenshots WHERE captured_at < ?', [$cutoff]);
    db_query('DELETE FROM activity_logs WHERE created_at < ?', [$cutoff]);

    // End stale sessions
    db_query(
        "UPDATE sessions SET status = 'ended', end_time = NOW()
         WHERE status IN ('active', 'idle')
         AND start_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
}