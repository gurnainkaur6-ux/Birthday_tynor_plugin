<?php
/**
 * track_open.php — Email open-tracking pixel endpoint.
 *
 * Each outgoing email embeds a 1×1 transparent GIF via:
 *   <img src="BASE_URL/cron/track_open.php?token=XXXXX" width="1" height="1">
 *
 * When the recipient's email client loads the image:
 *   1. This script records email_opened_at = NOW() in notification_logs.
 *   2. Returns a real 1×1 transparent GIF so no broken-image icon appears.
 *
 * No authentication required (must be publicly accessible).
 * No session started (keeps it fast and cache-friendly).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// ── Record the open if token is valid ────────────────────────────
$token = isset($_GET['token']) ? preg_replace('/[^a-f0-9]/i', '', $_GET['token']) : '';

if (strlen($token) === 32) { // 16 random bytes → 32 hex chars
    try {
        $conn->prepare("
            UPDATE notification_logs
            SET    email_opened_at = NOW()
            WHERE  tracking_token  = ?
              AND  email_opened_at IS NULL
        ")->execute([$token]);
    } catch (PDOException $e) {
        // Silent fail — tracking must never break email delivery
        error_log('track_open error: ' . $e->getMessage());
    }
}

// ── Respond with a 1×1 transparent GIF (43 bytes) ────────────────
header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Length: 43');

// Minimal 1×1 transparent GIF binary
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
exit;