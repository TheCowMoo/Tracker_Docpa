<?php
/**
 * DOCPA Tracker — Statistics API
 *
 * GET /api/stats.php — Get activity statistics
 *
 * Query params:
 *   user_id  — User ID (admin only; defaults to authenticated user)
 *   from     — Start date (Y-m-d, defaults to 7 days ago)
 *   to       — End date (Y-m-d, defaults to today)
 *   group_by — "day" (default), "week", "hour"
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

try {

// Admin can view any user's stats
$target_user_id = $user['id'];
if (!empty($_GET['user_id']) && $user['username'] === 'admin') {
    $target_user_id = (int) $_GET['user_id'];
}

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d');
$group_by = $_GET['group_by'] ?? 'day';

// Validate group_by
if (!in_array($group_by, ['day', 'week', 'hour'])) {
    $group_by = 'day';
}

// Build the date grouping SQL
switch ($group_by) {
    case 'hour':
        $date_format = "DATE_FORMAT(s.start_time, '%Y-%m-%d %H:00:00')";
        break;
    case 'week':
        $date_format = "DATE_FORMAT(DATE_SUB(s.start_time, INTERVAL WEEKDAY(s.start_time) DAY), '%Y-%m-%d')";
        break;
    case 'day':
    default:
        $date_format = "DATE(s.start_time)";
        break;
}

// 1. Sessions summary
$sessions_summary = db_fetch_all(
    "SELECT
        $date_format as period,
        COUNT(*) as session_count,
        COALESCE(SUM(total_active_seconds), 0) as total_active_seconds,
        COALESCE(SUM(total_idle_seconds), 0) as total_idle_seconds
     FROM sessions s
     WHERE s.user_id = ?
       AND s.start_time >= ? AND s.start_time <= ? + INTERVAL 1 DAY
       AND status = 'ended'
     GROUP BY period
     ORDER BY period ASC",
    [$target_user_id, $from . ' 00:00:00', $to]
);

// 2. Screenshot counts per period
$screenshots_summary = db_fetch_all(
    "SELECT
        DATE(captured_at) as period,
        COUNT(*) as total_shots,
        SUM(CASE WHEN is_idle = 0 THEN 1 ELSE 0 END) as active_shots,
        SUM(CASE WHEN is_idle = 1 THEN 1 ELSE 0 END) as idle_shots
     FROM screenshots
     WHERE user_id = ?
       AND captured_at >= ? AND captured_at <= ? + INTERVAL 1 DAY
     GROUP BY period
     ORDER BY period ASC",
    [$target_user_id, $from . ' 00:00:00', $to]
);

// 3. Overall totals
$totals = db_fetch_one(
    "SELECT
        COUNT(DISTINCT s.id) as total_sessions,
        COALESCE(SUM(s.total_active_seconds), 0) as total_active_seconds,
        COALESCE(SUM(s.total_idle_seconds), 0) as total_idle_seconds,
        COUNT(sc.id) as total_screenshots,
        SUM(CASE WHEN sc.is_idle = 0 THEN 1 ELSE 0 END) as active_screenshots,
        SUM(CASE WHEN sc.is_idle = 1 THEN 1 ELSE 0 END) as idle_screenshots
     FROM sessions s
     LEFT JOIN screenshots sc ON sc.session_id = s.id
     WHERE s.user_id = ?
       AND s.start_time >= ? AND s.start_time <= ? + INTERVAL 1 DAY",
    [$target_user_id, $from . ' 00:00:00', $to]
);

// 4. Daily average (for the range)
$days_in_range = max((new DateTime($to))->diff(new DateTime($from))->days + 1, 1);
$totals['avg_active_minutes_per_day'] = round(
    ($totals['total_active_seconds'] / 60) / $days_in_range,
    1
);

// 5. Current streak (consecutive days with at least one session)
$streak_days = 0;
$check_date = new DateTime();
while (true) {
    $day_sessions = db_fetch_one(
        "SELECT COUNT(*) as cnt FROM sessions
         WHERE user_id = ? AND DATE(start_time) = ?
         LIMIT 1",
        [$target_user_id, $check_date->format('Y-m-d')]
    );
    if ($day_sessions && (int) $day_sessions['cnt'] > 0) {
        $streak_days++;
        $check_date->modify('-1 day');
    } else {
        break;
    }
}
$totals['current_streak_days'] = $streak_days;

// 6. Productivity score (active shots / total shots * 100)
$total_shots_all = (int) ($totals['active_screenshots'] ?? 0) + (int) ($totals['idle_screenshots'] ?? 0);
$totals['productivity_score'] = $total_shots_all > 0
    ? round(((int) ($totals['active_screenshots'] ?? 0) / $total_shots_all) * 100, 1)
    : 0;

json_response([
    'success'              => true,
    'user_id'              => $target_user_id,
    'from'                 => $from,
    'to'                   => $to,
    'group_by'             => $group_by,
    'sessions'             => $sessions_summary,
    'screenshots'          => $screenshots_summary,
    'totals'               => $totals,
]);

} catch (\Throwable $e) {
    json_error('Stats error: ' . $e->getMessage(), 500);
}
