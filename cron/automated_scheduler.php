<?php
/**
 * automated_scheduler.php — the once-a-day cron / Windows Task Scheduler
 * entry point for the midnight birthday run.
 *
 * This file owns ONLY the unattended-trigger authentication (CLI, or a web
 * hit with a verified ?key=CRON_SECRET for services that can't run PHP CLI).
 * The actual sending — duplicate-send guards, poster generation, Test Mode,
 * the kill switch, and scheduler_runs logging — lives in the single, tested
 * pipeline in send_birthday_notification.php, which this file delegates to.
 * (A separate outbox/notify_enqueue pipeline was previously referenced here
 * but was never actually built — that dead code has been removed in favor
 * of reusing the pipeline that already works.)
 *
 * ACCESS: CLI (no key), or web with ?key=CRON_SECRET for unattended triggers.
 *
 *   C:\xampp\php\php.exe cron\automated_scheduler.php
 *   /usr/bin/php cron/automated_scheduler.php
 */

require_once __DIR__ . '/../config/config.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $secret = getenv('CRON_SECRET') ?: '';
    $key    = (string) ($_GET['key'] ?? '');
    if ($secret === '' || !hash_equals($secret, $key)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
    // A verified CRON_SECRET is as trustworthy as CLI access — run the real
    // send pipeline without requiring a logged-in admin session.
    if (!defined('CRON_TRUSTED_RUN')) define('CRON_TRUSTED_RUN', true);
}

require __DIR__ . '/send_birthday_notification.php';
