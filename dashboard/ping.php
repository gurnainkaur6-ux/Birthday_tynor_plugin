<?php
/**
 * ping.php — session keep-alive / status probe for the idle-timeout UI.
 *
 * Returns JSON (never HTML) so the client script can react without following a
 * redirect. It intentionally does NOT include auth_check.php (which redirects
 * to the login page). The session is only refreshed on an explicit, genuine
 * user action (POST ?keepalive=1 — e.g. "Stay signed in" or throttled real
 * activity), so a truly idle session still expires server-side. A GET probe
 * reports the remaining time WITHOUT extending the session.
 */
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$timeout = defined('SESSION_IDLE_TIMEOUT') ? SESSION_IDLE_TIMEOUT : 300;

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'unauthenticated']);
    exit;
}

$idle = time() - (int) ($_SESSION['last_activity'] ?? time());

if ($idle > $timeout) {
    // Expire the session exactly like auth_check.php would.
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'expired']);
    exit;
}

// Explicit keep-alive from a genuine user action → refresh the idle clock.
if (($_GET['keepalive'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['last_activity'] = time();
    echo json_encode(['ok' => true, 'remaining' => $timeout]);
    exit;
}

// Passive status probe — does NOT extend the session.
echo json_encode(['ok' => true, 'remaining' => max(0, $timeout - $idle)]);