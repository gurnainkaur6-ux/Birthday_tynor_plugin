<?php

namespace App\Notifications;

/**
 * Microsoft Teams channel — posts to Teams Workflows Webhooks.
 */
class TeamsChannel implements NotificationChannelInterface
{
    private string $channelKey = 'teams';

    public function getChannelKey(): string
    {
        return $this->channelKey;
    }

    public function supports(string $notificationType): bool
    {
        return in_array($notificationType, ['announcement'], true);
    }

    public function isConfigured(): bool
    {
        return $this->getConfig('TEAMS_WEBHOOK_URL') !== '';
    }

    public function isPerRecipient(): bool
    {
        return false;
    }

    public function send(array $recipient, string $subject, string $body, array $options = []): NotificationResult
    {
        if (!$this->isConfigured()) {
            return new NotificationResult(
                success: false,
                providerName: 'MS Teams',
                errorMessage: 'Teams channel is not configured (missing TEAMS_WEBHOOK_URL).',
                referenceId: null,
                skipped: true
            );
        }

        try {
            $webhookUrl = $this->getConfig('TEAMS_WEBHOOK_URL');

            $plainText = trim(strip_tags(str_replace(
                ['<li>', '</li>', '<br>', '<br/>', '<p>'],
                ["\n- ", '', "\n", "\n", "\n\n"],
                $body
            )));

            $payload = [
                '@type'      => 'MessageCard',
                '@context'   => 'http://schema.org/extensions',
                'themeColor' => '4F46E5',
                'summary'    => $subject,
                'title'      => $subject,
                'text'       => $plainText,
            ];

            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_TIMEOUT        => 10,
            ]);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return new NotificationResult(
                    success: false,
                    providerName: 'MS Teams',
                    errorMessage: 'cURL error: ' . $curlError
                );
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return new NotificationResult(
                    success: true,
                    providerName: 'MS Teams',
                    errorMessage: null,
                    referenceId: null
                );
            }

            return new NotificationResult(
                success: false,
                providerName: 'MS Teams',
                errorMessage: "Webhook returned HTTP {$httpCode}: " . substr((string) $response, 0, 200)
            );

        } catch (\Throwable $e) {
            return new NotificationResult(
                success: false,
                providerName: 'System',
                errorMessage: 'Internal Teams error: ' . $e->getMessage()
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