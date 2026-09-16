<?php
/**
 * smtp_test.php — SMTP connection & send test (CLI only).
 *
 * Reads SMTP settings from your .env file — NEVER hardcode credentials here.
 * Run from a terminal:
 *     C:\xampp\php\php.exe smtp_test.php
 *
 * It sends one test message to MAIL_TEST_RECIPIENT (or SMTP_FROM_EMAIL) so you
 * can confirm delivery without emailing real employees.
 */

// Refuse to run over HTTP — this prints verbose SMTP debug and must never be
// reachable from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit("Forbidden: smtp_test.php can only be run from the command line.\n");
}

require_once __DIR__ . '/config/config.php';   // loads .env into getenv()

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Vendor not found. Run: composer install\n");
    exit(1);
}
require_once $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/** Small helper: read an env var or empty string. */
function env_or_empty(string $key): string {
    $v = getenv($key);
    return ($v === false) ? '' : trim($v);
}

$host    = env_or_empty('SMTP_HOST');
$port    = (int) (env_or_empty('SMTP_PORT') ?: 587);
$user    = env_or_empty('SMTP_USERNAME') ?: env_or_empty('SMTP_USER');
$pass    = env_or_empty('SMTP_PASSWORD') ?: env_or_empty('SMTP_PASS');
$enc     = strtolower(env_or_empty('SMTP_ENCRYPTION') ?: 'tls');
$from    = env_or_empty('SMTP_FROM_EMAIL') ?: $user;
$company = env_or_empty('COMPANY_NAME') ?: 'The Company';
$sendTo  = env_or_empty('MAIL_TEST_RECIPIENT') ?: $from;

echo "=== BDayNotify SMTP Test ===\n\n";
echo "[ Extensions ]\n";
echo "  openssl : " . (extension_loaded('openssl') ? "OK" : "MISSING") . "\n";
echo "  gd      : " . (extension_loaded('gd') ? "OK" : "MISSING") . "\n\n";

// Validate configuration WITHOUT printing the password.
$missing = [];
if ($host === '') $missing[] = 'SMTP_HOST';
if ($user === '') $missing[] = 'SMTP_USERNAME';
if ($pass === '') $missing[] = 'SMTP_PASSWORD';
if ($missing) {
    fwrite(STDERR, "SMTP is not fully configured. Missing in .env: " . implode(', ', $missing) . "\n");
    fwrite(STDERR, "Copy .env.example to .env and fill in your SMTP details.\n");
    exit(1);
}
if ($sendTo === '') {
    fwrite(STDERR, "No recipient. Set MAIL_TEST_RECIPIENT (or SMTP_FROM_EMAIL) in .env.\n");
    exit(1);
}

echo "[ Config ] host={$host} port={$port} enc={$enc} user={$user} (password hidden)\n";
echo "[ Sending test to {$sendTo} ]\n";

$mail = new PHPMailer(true);
try {
    $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = 'echo';
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->Port       = $port;
    if ($enc === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($enc === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }
    $mail->CharSet = 'UTF-8';

    $mail->setFrom($from, $company . ' HR Team');
    $mail->addAddress($sendTo);
    $mail->isHTML(true);
    $mail->Subject = '[TEST] BDayNotify SMTP Test - ' . date('d M Y H:i');
    $mail->Body    = '<p>SMTP test from <strong>' . htmlspecialchars($company)
                   . ' BDayNotify</strong>. If you received this, SMTP delivery is working.</p>';
    $mail->AltBody = 'SMTP test successful — BDayNotify SMTP is configured correctly.';

    $mail->send();
    echo "\n\nSUCCESS - test email sent to {$sendTo}\n";
} catch (Exception $e) {
    fwrite(STDERR, "\n\nFAILED - " . $mail->ErrorInfo . "\n");
    fwrite(STDERR, "Common fixes: verify SMTP_USERNAME/SMTP_PASSWORD, use an app password for Gmail,\n"
                 . "and confirm the host/port/encryption in .env.\n");
    exit(1);
}
