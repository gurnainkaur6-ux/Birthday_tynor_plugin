<?php

namespace App\Notifications;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Email delivery channel — uses PHPMailer + SMTP credentials from env/constants.
 */
class EmailChannel implements NotificationChannelInterface
{
    private string $channelKey = 'email';

    public function getChannelKey(): string
    {
        return $this->channelKey;
    }

    public function supports(string $notificationType): bool
    {
        return in_array($notificationType, ['personal', 'announcement'], true);
    }

    public function isConfigured(): bool
    {
        $host     = defined('SMTP_HOST')     ? (string) SMTP_HOST     : (getenv('SMTP_HOST')     ?: '');
        $username = defined('SMTP_USERNAME') ? (string) SMTP_USERNAME : (getenv('SMTP_USERNAME') ?: '');
        $password = defined('SMTP_PASSWORD') ? (string) SMTP_PASSWORD : (getenv('SMTP_PASSWORD') ?: '');

        return $host !== '' && $username !== '' && $password !== '';
    }

    public function isPerRecipient(): bool
    {
        return true;
    }

    public function send(array $recipient, string $subject, string $body, array $options = []): NotificationResult
    {
        $emailAddress  = $recipient['email'] ?? null;
        $recipientName = $recipient['name']  ?? '';

        if (empty($emailAddress)) {
            return new NotificationResult(
                success: false,
                providerName: 'PHPMailer',
                errorMessage: 'Recipient email address is missing.',
                skipped: true
            );
        }

        if (!class_exists(PHPMailer::class)) {
            return new NotificationResult(
                success: false,
                providerName: 'PHPMailer',
                errorMessage: 'PHPMailer not found. Run "composer install" to install dependencies.',
                skipped: true
            );
        }

        if (!$this->isConfigured()) {
            return new NotificationResult(
                success: false,
                providerName: 'PHPMailer',
                errorMessage: 'Email channel is not configured (missing SMTP credentials).',
                skipped: true
            );
        }

        $mail = new PHPMailer(true);

        try {
            $host       = defined('SMTP_HOST')       ? SMTP_HOST       : (getenv('SMTP_HOST') ?: '');
            $username   = defined('SMTP_USERNAME')   ? SMTP_USERNAME   : (getenv('SMTP_USERNAME') ?: '');
            $password   = defined('SMTP_PASSWORD')   ? SMTP_PASSWORD   : (getenv('SMTP_PASSWORD') ?: '');
            $port       = defined('SMTP_PORT')       ? (int) SMTP_PORT : (int)(getenv('SMTP_PORT') ?: 587);
            $encryption = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : (getenv('SMTP_ENCRYPTION') ?: 'tls');
            $fromEmail  = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : (getenv('SMTP_FROM_EMAIL') ?: $username);
            $fromName   = defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : (getenv('SMTP_FROM_NAME')  ?: 'HR Team');

            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $username;
            $mail->Password   = $password;
            $mail->Port       = $port;
            $mail->CharSet    = 'UTF-8';
            $mail->SMTPSecure = ($encryption === 'ssl')
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($emailAddress, $recipientName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<p>'], ["\n", "\n\n"], $body));

            $mail->send();

            return new NotificationResult(
                success: true,
                providerName: 'PHPMailer',
                referenceId: $mail->getLastMessageID()
            );

        } catch (PHPMailerException $e) {
            return new NotificationResult(
                success: false,
                providerName: 'PHPMailer',
                errorMessage: $mail->ErrorInfo ?: $e->getMessage()
            );
        } catch (\Throwable $e) {
            return new NotificationResult(
                success: false,
                providerName: 'System',
                errorMessage: 'Internal mailer error: ' . $e->getMessage()
            );
        }
    }
}