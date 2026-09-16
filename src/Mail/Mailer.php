<?php

namespace App\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Thin SMTP wrapper around PHPMailer.
 * Used directly by scripts that don't go through the channel abstraction.
 */
class Mailer
{
    /**
     * @return array{success: bool, error: ?string}
     */
    public function send(
        string  $toEmail,
        string  $toName,
        string  $subject,
        string  $htmlBody,
        ?string $attachmentPath = null,
        ?string $attachmentName = null
    ): array {
        if (!class_exists(PHPMailer::class)) {
            $msg = 'PHPMailer not found. Run "composer install".';
            error_log($msg);
            return ['success' => false, 'error' => $msg];
        }

        $host     = defined('SMTP_HOST')     ? SMTP_HOST     : (getenv('SMTP_HOST')     ?: '');
        $username = defined('SMTP_USERNAME') ? SMTP_USERNAME : (getenv('SMTP_USERNAME') ?: '');
        $password = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : (getenv('SMTP_PASSWORD') ?: '');

        if (empty($host) || empty($username) || empty($password)) {
            $msg = 'SMTP credentials are incomplete. Check your environment variables.';
            error_log($msg);
            return ['success' => false, 'error' => $msg];
        }

        $mail = new PHPMailer(true);

        try {
            $encryption = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : (getenv('SMTP_ENCRYPTION') ?: 'tls');
            $port       = defined('SMTP_PORT')       ? (int) SMTP_PORT : (int)(getenv('SMTP_PORT') ?: 587);
            $fromEmail  = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : (getenv('SMTP_FROM_EMAIL') ?: $username);
            $fromName   = defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : (getenv('SMTP_FROM_NAME')  ?: 'System Admin');

            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $username;
            $mail->Password   = $password;
            $mail->SMTPSecure = ($encryption === 'ssl')
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $port;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($fromEmail, $this->stripHeaderInjection($fromName));
            $mail->addAddress($toEmail, $this->stripHeaderInjection($toName));

            $mail->isHTML(true);
            $mail->Subject = $this->stripHeaderInjection($subject);
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            if ($attachmentPath && is_file($attachmentPath)) {
                $mail->addAttachment($attachmentPath, $attachmentName ?? basename($attachmentPath));
            }

            $mail->send();
            return ['success' => true, 'error' => null];

        } catch (PHPMailerException $e) {
            error_log('Mail send failed for ' . $toEmail . ': ' . $mail->ErrorInfo);
            return ['success' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
        } catch (\Throwable $e) {
            error_log('Mail send failed for ' . $toEmail . ': ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function stripHeaderInjection(string $value): string
    {
        return trim(str_replace(["\r", "\n", "%0a", "%0d"], '', $value));
    }
}