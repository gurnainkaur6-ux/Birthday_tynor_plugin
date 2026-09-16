<?php
/**
 * preflight.php — pre-deployment environment check (CLI).
 *
 * Run this on the target server BEFORE going live to confirm it can run the
 * plugin. It checks PHP version, extensions, Composer vendor, .env config,
 * database connectivity, and writable folders — without needing the web server.
 *
 *   php scripts/preflight.php
 *
 * Exit code 0 = ready (no hard failures); 1 = blocking problems found.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line: php scripts/preflight.php\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';   // loads .env into getenv()

$fail = 0; $warn = 0;
function line(string $state, string $label, string $detail = ''): void {
    global $fail, $warn;
    if ($state === 'FAIL') $fail++;
    if ($state === 'WARN') $warn++;
    $tag = ['OK' => '[ OK ]', 'WARN' => '[WARN]', 'FAIL' => '[FAIL]'][$state] ?? '[ .. ]';
    echo str_pad($tag, 7) . $label . ($detail !== '' ? '  —  ' . $detail : '') . "\n";
}

echo "=== BDayNotify preflight check ===\n\n";

// PHP version (8.0+ required).
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
line($phpOk ? 'OK' : 'FAIL', 'PHP version ' . PHP_VERSION, $phpOk ? '' : 'requires 8.0 or newer');

// Extensions.
$req = ['pdo_mysql', 'mbstring', 'openssl'];
$opt = ['gd' => 'birthday posters', 'fileinfo' => 'upload validation', 'curl' => 'some mail transports'];
foreach ($req as $e) {
    line(extension_loaded($e) ? 'OK' : 'FAIL', "Extension: $e", extension_loaded($e) ? '' : 'required — enable in php.ini');
}
foreach ($opt as $e => $why) {
    line(extension_loaded($e) ? 'OK' : 'WARN', "Extension: $e", extension_loaded($e) ? '' : "optional ($why)");
}

// Composer vendor.
$vendor = is_file($root . '/vendor/autoload.php');
line($vendor ? 'OK' : 'FAIL', 'Composer dependencies', $vendor ? 'vendor/autoload.php present' : "run 'composer install'");

// .env presence + required keys.
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    line('FAIL', '.env file', 'missing — copy .env.example to .env and edit');
} else {
    line('OK', '.env file', 'present');
    $missing = [];
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $k) {
        if (getenv($k) === false || getenv($k) === '') $missing[] = $k;
    }
    line($missing ? 'FAIL' : 'OK', 'Required DB settings', $missing ? 'missing: ' . implode(', ', $missing) : 'set');

    $smtpMissing = [];
    foreach (['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL'] as $k) {
        if (getenv($k) === false || getenv($k) === '') $smtpMissing[] = $k;
    }
    line($smtpMissing ? 'WARN' : 'OK', 'SMTP settings', $smtpMissing ? 'missing: ' . implode(', ', $smtpMissing) . ' (email will simulate)' : 'set');
}

// Database connectivity + whether the schema is installed.
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'birthday_system';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
try {
    $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    line('OK', "MySQL connection ({$dbHost}:{$dbPort})", "connected as '{$dbUser}'");
    $dbExists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=" . $pdo->quote($dbName))->fetchColumn();
    if (!$dbExists) {
        line('WARN', "Database '{$dbName}'", "does not exist yet — run public/setup.php to create it");
    } else {
        $pdo->exec("USE `{$dbName}`");
        $hasTable = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=" . $pdo->quote($dbName) . " AND table_name='employee_master'")->fetchColumn();
        line($hasTable ? 'OK' : 'WARN', "Schema in '{$dbName}'", $hasTable ? 'employee_master present' : 'not installed yet — run setup.php');
    }
} catch (Throwable $e) {
    line('FAIL', "MySQL connection ({$dbHost}:{$dbPort})", 'cannot connect — check DB_* in .env and that MySQL is running');
}

// Writable folders.
$uploads = $root . '/uploads';
$upOk = is_dir($uploads) ? is_writable($uploads) : @mkdir($uploads, 0775, true);
line($upOk ? 'OK' : 'FAIL', 'uploads/ writable', $upOk ? '' : 'chmod the folder so the web user can write (Linux: chown/chmod 775)');

// Production-safety warnings.
$env = strtolower((string) getenv('APP_ENV')) ?: 'production';
$debug = in_array(strtolower((string) getenv('APP_DEBUG')), ['1', 'true', 'on', 'yes'], true);
if ($env === 'production' && $debug) {
    line('WARN', 'Debug mode', 'APP_DEBUG is ON in production — set APP_DEBUG=0');
} else {
    line('OK', 'Debug mode', $debug ? 'on (non-production)' : 'off');
}
$sendOn  = getenv('EMAIL_SENDING_ENABLED') === '1';
$testOn  = getenv('MAIL_TEST_MODE') === '1';
line('OK', 'Sending mode', $testOn ? 'TEST mode (safe)' : ($sendOn ? 'PRODUCTION sending ENABLED' : 'production sending disabled (safe default)'));

echo "\n----------------------------------------\n";
echo ($fail === 0)
    ? "READY: no blocking problems ({$warn} warning" . ($warn === 1 ? '' : 's') . ").\n"
    : "NOT READY: {$fail} blocking problem" . ($fail === 1 ? '' : 's') . ", {$warn} warning" . ($warn === 1 ? '' : 's') . ".\n";
exit($fail === 0 ? 0 : 1);
