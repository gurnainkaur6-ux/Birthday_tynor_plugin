<?php

namespace App\Notifications;

/**
 * WhatsApp delivery channel — uses Meta Cloud API / WhatsApp Business API.
 */
class WhatsappChannel implements NotificationChannelInterface
{
    private string $channelKey = 'whatsapp';

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
        return $this->getConfig('WHATSAPP_TOKEN') !== ''
            && $this->getConfig('WHATSAPP_PHONE_NUMBER_ID') !== '';
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
                providerName: 'WhatsApp API',
                errorMessage: 'Recipient phone number is missing.',
                referenceId: null,
                skipped: true
            );
        }

        if (!$this->isConfigured()) {
            return new NotificationResult(
                success: false,
                providerName: 'WhatsApp API',
                errorMessage: 'WhatsApp channel is not configured (missing WhatsApp credentials).',
                referenceId: null,
                skipped: true
            );
        }

        try {
            $token         = $this->getConfig('WHATSAPP_TOKEN');
            $phoneNumberId = $this->getConfig('WHATSAPP_PHONE_NUMBER_ID');

            $messageText = trim(strip_tags($body));

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $toPhone,
                'type'              => 'text',
                'text'              => [
                    'preview_url' => false,
                    'body'        => $messageText,
                ],
            ];

            $ch = curl_init("https://graph.facebook.com/v18.0/{$phoneNumberId}/messages");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$token}",
                    'Content-Type: application/json',
                ],
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
                    providerName: 'WhatsApp API',
                    errorMessage: 'cURL error: ' . $curlError
                );
            }

            $decoded = json_decode((string) $response, true);
            if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['messages'][0]['id'])) {
                return new NotificationResult(
                    success: true,
                    providerName: 'WhatsApp API',
                    errorMessage: null,
                    referenceId: $decoded['messages'][0]['id']
                );
            }

            $errorMsg = $decoded['error']['message'] ?? ("API returned HTTP {$httpCode}: " . substr((string) $response, 0, 200));

            return new NotificationResult(
                success: false,
                providerName: 'WhatsApp API',
                errorMessage: $errorMsg
            );

        } catch (\Throwable $e) {
            return new NotificationResult(
                success: false,
                providerName: 'System',
                errorMessage: 'Internal WhatsApp error: ' . $e->getMessage()
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