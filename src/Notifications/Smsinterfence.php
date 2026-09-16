<?php

namespace App\Notifications;

/**
 * SMS delivery channel — uses Twilio REST API.
 */
class SmsChannel implements NotificationChannelInterface
{
    private string $channelKey = 'sms';

    public function getChannelKey(): string
    {
        return $this->channelKey;
    }

    public function supports(string $notificationType): bool
    {
        return in_array($notificationType, ['personal'], true);
    }

    public function isConfigured(): bool
    {
        return $this->getConfig('SMS_ACCOUNT_SID') !== ''
            && $this->getConfig('SMS_AUTH_TOKEN') !== ''
            && $this->getConfig('SMS_FROM_NUMBER') !== '';
    }

    public function isPerRecipient(): bool
    {
        return true;
    }

    public function send(array $recipient, string $subject, string $body, array $options = []): NotificationResult
    {
        $toPhone = $recipient['phone'] ?? $recipient['mobile'] ?? null;

        if (empty($toPhone)) {
            return new NotificationResult(
                success: false,
                providerName: 'Twilio SMS',
                errorMessage: 'Recipient phone number is missing.',
                referenceId: null,
                skipped: true
            );
        }

        if (!$this->isConfigured()) {
            return new NotificationResult(
                success: false,
                providerName: 'Twilio SMS',
                errorMessage: 'SMS channel is not configured (missing SMS credentials).',
                referenceId: null,
                skipped: true
            );
        }

        try {
            $accountSid = $this->getConfig('SMS_ACCOUNT_SID');
            $authToken  = $this->getConfig('SMS_AUTH_TOKEN');
            $fromNumber = $this->getConfig('SMS_FROM_NUMBER');

            $smsBody = trim(strip_tags($body));

            $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => "{$accountSid}:{$authToken}",
                CURLOPT_POSTFIELDS     => http_build_query([
                    'To'   => $toPhone,
                    'From' => $fromNumber,
                    'Body' => mb_substr($smsBody, 0, 300),
                ]),
                CURLOPT_TIMEOUT => 10,
            ]);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return new NotificationResult(
                    success: false,
                    providerName: 'Twilio SMS',
                    errorMessage: 'cURL error: ' . $curlError
                );
            }

            $decoded = json_decode((string) $response, true);
            if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['sid'])) {
                return new NotificationResult(
                    success: true,
                    providerName: 'Twilio SMS',
                    errorMessage: null,
                    referenceId: $decoded['sid']
                );
            }

            return new NotificationResult(
                success: false,
                providerName: 'Twilio SMS',
                errorMessage: "API returned HTTP {$httpCode}: " . substr((string) $response, 0, 200)
            );

        } catch (\Throwable $e) {
            return new NotificationResult(
                success: false,
                providerName: 'System',
                errorMessage: 'Internal SMS error: ' . $e->getMessage()
            );
        }
    }

    /**
     * Safely fetches a config value from defined constants or environment variables.
     */
    private function getConfig(string $key): string
    {
        if (defined($key)) {
            return (string) constant($key);
        }

        return (string) (getenv($key) ?: '');
    }
}