<?php
/**
 * settings.php — tiny key/value settings store (app_settings table).
 *
 *   app_setting($conn, 'active_birthday_template', '1');   // read (with default)
 *   app_setting_set($conn, 'active_birthday_template', '5'); // write
 *
 * Values are cached per-request. Reads never throw (fall back to the default),
 * so the app keeps working even before the migration has run.
 */

if (!function_exists('app_setting')) {
    function app_setting(PDO $conn, string $key, ?string $default = null): ?string
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                foreach ($conn->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                    $cache[$k] = $v;
                }
            } catch (Throwable $e) {
                $cache = []; // table not present yet — use defaults
            }
        }
        return array_key_exists($key, $cache) ? $cache[$key] : $default;
    }
}

if (!function_exists('app_setting_set')) {
    function app_setting_set(PDO $conn, string $key, string $value): bool
    {
        try {
            $conn->prepare("
                INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute([$key, $value]);
            return true;
        } catch (Throwable $e) {
            error_log('app_setting_set failed: ' . $e->getMessage());
            return false;
        }
    }
}

/** Convenience: the active birthday template id (1..10), always valid. */
if (!function_exists('active_birthday_template')) {
    function active_birthday_template(PDO $conn): int
    {
        $t = (int) app_setting($conn, 'active_birthday_template', '1');
        return ($t >= 1 && $t <= 10) ? $t : 1;
    }
}

/**
 * Effective Test Mode. Precedence: DB app_settings('mail_test_mode') when set,
 * otherwise the MAIL_TEST_MODE env var. Lets the Settings page flip test↔prod
 * without editing .env, while .env remains the safe default for fresh installs.
 * Pass $conn where available; without it, falls back to env only.
 */
if (!function_exists('is_test_mode')) {
    function is_test_mode(?PDO $conn = null): bool
    {
        if ($conn instanceof PDO) {
            $v = app_setting($conn, 'mail_test_mode', null);
            if ($v !== null) {
                return $v === '1';
            }
        }
        return getenv('MAIL_TEST_MODE') === '1';
    }
}

/**
 * Effective production-sending switch (kill switch). Precedence: DB
 * app_settings('email_sending_enabled') when set, otherwise EMAIL_SENDING_ENABLED.
 */
if (!function_exists('is_sending_enabled')) {
    function is_sending_enabled(?PDO $conn = null): bool
    {
        if ($conn instanceof PDO) {
            $v = app_setting($conn, 'email_sending_enabled', null);
            if ($v !== null) {
                return $v === '1';
            }
        }
        return getenv('EMAIL_SENDING_ENABLED') === '1';
    }
}
