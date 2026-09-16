<?php
/**
 * auth_check.php — Redirect unauthenticated visitors to login.
 *
 * Include at the top of any protected page AFTER config.php.
 */

require_once __DIR__ . '/../config/config.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

// ── Idle timeout ───────────────────────────────────────────────────
// Expire after SESSION_IDLE_TIMEOUT seconds (5 minutes) of inactivity so an
// unattended session cannot be reused. The client shows a warning shortly
// before this (see includes/topbar.php); the login page shows a clear
// "session expired" notice (?expired=1). Constant defined in config.php.
if (!empty($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ' . BASE_URL . '/auth/login.php?expired=1');
    exit;
}
$_SESSION['last_activity'] = time();

// Rotate session ID every 30 minutes to limit the session-hijacking window.
if (empty($_SESSION['created_at'])) {
    $_SESSION['created_at'] = time();
} elseif (time() - $_SESSION['created_at'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['created_at'] = time();
}