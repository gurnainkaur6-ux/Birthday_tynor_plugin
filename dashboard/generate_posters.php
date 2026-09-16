<?php
/**
 * generate_poster.php — Dynamic birthday wish poster generator.
 * Programmatically generates a personalized wish card for employees.
 * Delegates work to includes/poster_generator.php.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/poster_generator.php';

// ── 1. Security Check ────────────────────────────────────────────────
$authorized = false;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$cronSecret = (string) getenv('CRON_SECRET');
if (!empty($_SESSION['admin_id'])) {
    // Authenticated dashboard user. The poster lives under Content -> Email
    // templates, so honour that per-user feature grant. Server-to-server cron
    // calls below use CRON_SECRET (no session) and are unaffected.
    require_once __DIR__ . '/../includes/rbac.php';
    $authorized = current_user_can_feature($conn, 'templates');
} elseif (PHP_SAPI === 'cli') {
    // Local generation from a CLI script.
    $authorized = true;
} elseif (
    $cronSecret !== ''
    && isset($_GET['token'])
    && hash_equals($cronSecret, (string) $_GET['token'])
) {
    // Server-to-server fallback. Constant-time compare; never bypasses on an
    // empty secret. NOTE: passing secrets in the query string can leak into
    // access logs — prefer the authenticated session path where possible.
    $authorized = true;
}

if (!$authorized) {
    http_response_code(403);
    die('Forbidden: Unauthorized Access.');
}

// ── 2. Load Employee Details ─────────────────────────────────────────
$empCode = trim($_GET['emp_id'] ?? $_GET['emp_code'] ?? '');
if ($empCode === '') {
    http_response_code(400);
    die('Bad Request: Missing emp_id parameter.');
}

$im = generateBirthdayPosterGD($conn, $empCode);

if (!$im) {
    http_response_code(404);
    die('Employee not found or graphic generation failed.');
}

// ── 3. Output Image ──────────────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] == 1) {
    header('Content-Disposition: attachment; filename="birthday_wish_' . $empCode . '.png"');
}
header('Content-Type: image/png');
imagepng($im);
imagedestroy($im);
exit;