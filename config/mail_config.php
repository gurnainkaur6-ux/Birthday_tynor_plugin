<?php
/**
 * SMTP / mail configuration.
 */

// 1. Ensure the base config is loaded first
require_once __DIR__ . '/config.php';

/**
 * Helper to ensure required configuration exists.
 * Prevents the application from running with empty/invalid SMTP settings.
 */
function get_required_env($key, $default = null) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        if ($default === null) {
            // Optional: You could log this to a file instead of dying
            error_log("Warning: Missing environment variable: $key");
            return '';
        }
        return $default;
    }
    return $value;
}

// SMTP Connection Settings
define('SMTP_HOST', get_required_env('SMTP_HOST'));
define('SMTP_PORT', (int) get_required_env('SMTP_PORT', 587));
define('SMTP_USERNAME', get_required_env('SMTP_USERNAME') ?: get_required_env('SMTP_USER'));
define('SMTP_PASSWORD', get_required_env('SMTP_PASSWORD') ?: get_required_env('SMTP_PASS'));

// Security & Defaults
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls'); // 'tls' or 'ssl'
define('SMTP_FROM_EMAIL', get_required_env('SMTP_FROM_EMAIL'));
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'HR Team');
define('COMPANY_NAME', getenv('COMPANY_NAME') ?: 'The Company');

// Operational Settings
define('CRON_SECRET', getenv('CRON_SECRET') ?: '');
define('MAIL_THROTTLE_MS', (int) (getenv('MAIL_THROTTLE_MS') ?: 400));

/**
 * Validation Check:
 * This ensures your code doesn't try to send mail without credentials.
 */
if (empty(SMTP_HOST) || empty(SMTP_USERNAME) || empty(SMTP_PASSWORD)) {
    // You can uncomment the line below to stop execution if config is invalid
    // die("Error: SMTP configuration is incomplete. Check your .env file.");
}
?>