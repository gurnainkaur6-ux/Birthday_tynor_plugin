<?php
/**
 * api/scheduler_api.php — JSON status/trigger API for the scheduler widget.
 *
 * NOTE: this used to be a second, near-complete copy of the HTML dashboard
 * in dashboard/scheduler.php (same tables, same queries, a different layout,
 * no nav, and a synchronous blocking trigger). That duplicate page was never
 * linked from anywhere in the app -- only assets/js/scheduler-monitor.js
 * called it, expecting JSON, and got back an HTML page instead. This file
 * now does what its name says: a small, real JSON API. The full management
 * page lives in exactly one place, dashboard/scheduler.php.
 *
 * GET  ?action=status   -> current scheduler status/stats as JSON
 * POST action=trigger   -> kick off a background run (CSRF-protected)
 */

require_once __DIR__ . '/../config/config.php'; // starts the session itself
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthenticated']);
    exit;
}
if (!user_can('system.health')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -- POST: manual trigger (mirrors dashboard/scheduler.php's async trigger) --
if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action !== 'trigger') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'unknown action']);
        exit;
    }
    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'invalid csrf token']);
        exit;
    }

    $scriptPath = __DIR__ . '/../cron/automated_scheduler.php';
    $phpPath    = PHP_BINARY;

    if (stripos(PHP_OS, 'WIN') === 0) {
        $cmd = "start /B \"\" \"$phpPath\" -f \"$scriptPath\" > NUL 2>&1";
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = "$phpPath $scriptPath > /dev/null 2>&1 &";
        exec($cmd);
    }

    require_once __DIR__ . '/../includes/audit.php';
    audit_log($conn, 'SEND', 'scheduler_runs', 'manual_trigger', null, [
        'triggered_by' => $_SESSION['admin_name'] ?? 'User',
        'job'          => 'daily_birthday_notification',
        'via'          => 'api',
    ]);

    echo json_encode(['success' => true, 'message' => 'Scheduler triggered.']);
    exit;
}

// -- GET: status snapshot for the monitoring widget --------------------
if (($_GET['action'] ?? '') !== 'status') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unknown action']);
    exit;
}

try {
    $stmtToday = $conn->prepare("
        SELECT * FROM scheduler_runs
        WHERE job_name = 'daily_birthday_notification'
          AND run_date = CURDATE()
        ORDER BY run_time DESC
        LIMIT 1
    ");
    $stmtToday->execute();
    $todayRun = $stmtToday->fetch(PDO::FETCH_ASSOC) ?: null;

    $todayBirthdayCount = (int) $conn->query("
        SELECT COUNT(*) FROM v_todays_birthdays
    ")->fetchColumn();

    $now     = new DateTime();
    $mid     = new DateTime('tomorrow 00:00:00');
    $diff    = $now->diff($mid);
    $nextRun = ['formatted' => $diff->format('%h hours %i minutes')];

    $schedulerRunning = $todayRun && $todayRun['status'] === 'running';

    echo json_encode([
        'success' => true,
        'data'    => [
            'today_run'            => $todayRun,
            'today_birthday_count' => $todayBirthdayCount,
            'system_status'        => [
                'scheduler_running' => $schedulerRunning,
                'test_mode'         => is_test_mode($conn),
                'sending_enabled'   => is_sending_enabled($conn),
            ],
            'next_run' => $nextRun,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'status_unavailable']);
}
