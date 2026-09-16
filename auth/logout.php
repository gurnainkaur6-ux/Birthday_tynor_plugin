<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Log the logout action before wiping the session
if (!empty($_SESSION['admin_id'])) {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO audit_logs (table_name, record_id, action, performed_by, ip_address)
             VALUES ('users', :uid, 'LOGOUT', :uid, :ip)"
        );
        $stmt->execute([
            'uid' => $_SESSION['admin_id'],
            'ip'  => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    } catch (PDOException $e) {
        error_log('Logout audit log error: ' . $e->getMessage());
    }
}

// Clear session data completely
$_SESSION = [];

// Expire the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

// Preserve the "expired due to inactivity" notice when the idle-timeout logic
// routes the user through logout (so the session is destroyed cleanly first).
$suffix = (($_GET['expired'] ?? '') === '1') ? '?expired=1' : '';
header('Location: ' . BASE_URL . '/auth/login.php' . $suffix);
exit;