<?php
/**
 * mailer.php — single, reusable SMTP send helper (PHPMailer wrapper).
 *
 * Centralises SMTP configuration so the cron sender, the "Send test" action
 * and the System Health diagnostic all behave identically.
 *
 *   send_app_email($to, $subject, $html, $alt, $attachmentPath, $attachmentName)
 *       -> ['ok'=>bool, 'simulated'=>bool, 'error'=>?string, 'debug'=>string]
 *
 *   test_smtp_connection()
 *       -> ['ok'=>bool, 'error'=>?string, 'debug'=>string]
 *
 *   app_smtp_enabled(): bool
 */
require_once __DIR__ . '/../config/config.php';

use PHPMailer\PHPMailer\PHPMailer;

if (!function_exists('app_mailer_config')) {
    function app_mailer_config(): array
    {
        return [
            'host'      => getenv('SMTP_HOST') ?: '',
            'port'      => (int) (getenv('SMTP_PORT') ?: 587),
            'user'      => getenv('SMTP_USERNAME') ?: (getenv('SMTP_USER') ?: ''),
            'pass'      => getenv('SMTP_PASSWORD') ?: (getenv('SMTP_PASS') ?: ''),
            'from'      => getenv('SMTP_FROM_EMAIL') ?: (getenv('MAIL_FROM_ADDRESS') ?: ''),
            'from_name' => getenv('SMTP_FROM_NAME') ?: 'HR Team',
            'enc'       => strtolower(trim(getenv('SMTP_ENCRYPTION') ?: 'tls')),
        ];
    }
}

if (!function_exists('app_smtp_enabled')) {
    function app_smtp_enabled(): bool
    {
        $c = app_mailer_config();
        return $c['host'] !== '' && $c['user'] !== '' && $c['pass'] !== '';
    }
}

if (!function_exists('app_build_mailer')) {
    /** Build a configured PHPMailer instance (throws if PHPMailer missing). */
    function app_build_mailer(): PHPMailer
    {
        require_once __DIR__ . '/../vendor/autoload.php';
        $c = app_mailer_config();

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host     = $c['host'];
        $mail->Port     = $c['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $c['user'];
        $mail->Password = $c['pass'];

        if ($c['enc'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($c['enc'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;
        }

        // Local/dev convenience: relax cert verification on localhost / CLI.
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (PHP_SAPI === 'cli' || in_array($host, ['localhost', '127.0.0.1'], true) || str_starts_with($host, 'localhost')) {
            $mail->SMTPOptions = ['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]];
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($c['from'] ?: $c['user'], $c['from_name']);
        $mail->isHTML(true);
        return $mail;
    }
}

if (!function_exists('send_app_email')) {
    /**
     * CRITICAL FIX #10: Sanitize email headers to prevent CRLF injection attacks
     * 
     * This function strips carriage returns and newlines from email headers,
     * preventing attackers from injecting additional headers or email content.
     */
    function sanitize_email_header(string $value): string
    {
        // Remove CRLF characters that could be used to inject headers
        $value = str_replace(["\r", "\n", "\r\n"], '', $value);
        // Limit length to prevent abuse
        return mb_substr($value, 0, 255);
    }
    
    function send_app_email(
        string  $to,
        string  $subject,
        string  $html,
        string  $altBody = '',
        ?string $attachmentPath = null,
        ?string $attachmentName = null
    ): array {
        // No SMTP configured → simulate (never hard-fail the UI).
        if (!app_smtp_enabled()) {
            return ['ok' => true, 'simulated' => true, 'error' => null,
                    'debug' => 'SMTP not configured — simulated send.'];
        }

        $debug = '';
        try {
            $mail = app_build_mailer();
            $mail->SMTPDebug   = 2;
            $mail->Debugoutput = function ($str) use (&$debug) { $debug .= trim($str) . "\n"; };
            $mail->addAddress($to);
            
            // CRITICAL FIX #10: Sanitize Subject and From to prevent header injection
            $mail->Subject = sanitize_email_header($subject);
            
            $mail->Body    = $html;
            $mail->AltBody = $altBody !== '' ? $altBody : strip_tags($html);
            if ($attachmentPath && is_file($attachmentPath)) {
                $mail->addAttachment($attachmentPath, $attachmentName ?: basename($attachmentPath));
            }
            $mail->send();
            return ['ok' => true, 'simulated' => false, 'error' => null, 'debug' => $debug];
        } catch (\Throwable $e) {
            $err = isset($mail) && $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage();
            return ['ok' => false, 'simulated' => false, 'error' => $err, 'debug' => $debug];
        }
    }
}

if (!function_exists('test_smtp_connection')) {
    /** Connect + authenticate against the SMTP server without sending mail. */
    function test_smtp_connection(): array
    {
        if (!app_smtp_enabled()) {
            return ['ok' => false, 'error' => 'SMTP is not fully configured (host/username/password).', 'debug' => ''];
        }
        $debug = '';
        try {
            $mail = app_build_mailer();
            $mail->SMTPDebug   = 2;
            $mail->Debugoutput = function ($str) use (&$debug) { $debug .= trim($str) . "\n"; };
            $ok = $mail->smtpConnect($mail->SMTPOptions ?? []);
            if ($ok) { $mail->smtpClose(); }
            return ['ok' => (bool) $ok, 'error' => $ok ? null : ($mail->ErrorInfo ?: 'Connection failed'), 'debug' => $debug];
        } catch (\Throwable $e) {
            $err = isset($mail) && $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage();
            return ['ok' => false, 'error' => $err, 'debug' => $debug];
        }
    }
}
