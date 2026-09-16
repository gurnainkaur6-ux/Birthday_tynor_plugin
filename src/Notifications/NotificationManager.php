<?php

namespace App\Notifications;

use PDO;
use Throwable;

class NotificationManager
{
    private PDO $conn;
    /** @var array<string, NotificationChannelInterface> */
    private array $channels = [];

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function registerChannel(NotificationChannelInterface $channel): void
    {
        $this->channels[$channel->getChannelKey()] = $channel;
    }

    public function registerDefaultChannels(): void
    {
        if (!class_exists(EmailChannel::class)) {
            return;
        }

        $emailChannel = new EmailChannel();
        if ($this->isChannelActive($emailChannel->getChannelKey())) {
            $this->registerChannel($emailChannel);
        }
    }

    public function sendPersonalBirthdayWish(array $employee, string $subject, string $body, array $options = []): array
    {
        $recipient = [
            'email' => $employee['official_email'] ?? $employee['email'] ?? null,
            'name'  => $employee['full_name'] ?? $employee['name'] ?? '',
        ];

        return $this->dispatch('personal', $recipient, $subject, $body, $options);
    }

    public function sendCompanyAnnouncement(array $recipients, string $subject, string $body, array $options = []): array
    {
        $results = [];
        foreach ($recipients as $recipient) {
            $normalized = [
                'email' => $recipient['official_email'] ?? $recipient['email'] ?? null,
                'name'  => $recipient['full_name'] ?? $recipient['name'] ?? '',
            ];
            $results[] = $this->dispatch('announcement', $normalized, $subject, $body, $options);
        }

        return $results;
    }

    private function dispatch(string $notificationType, array $recipient, string $subject, string $body, array $options = []): array
    {
        $results = [];
        foreach ($this->channels as $channelKey => $channel) {
            if (!$channel->supports($notificationType)) {
                continue;
            }

            $results[$channelKey] = $channel->send($recipient, $subject, $body, $options);
        }

        return $results;
    }

    private function isChannelActive(string $channelKey): bool
    {
        try {
            $stmt = $this->conn->prepare('SELECT is_active FROM notification_channels WHERE channel_key = :channel_key LIMIT 1');
            $stmt->execute(['channel_key' => $channelKey]);
            $row = $stmt->fetch();
            return (bool) ($row['is_active'] ?? 0);
        } catch (Throwable) {
            return true;
        }
    }
}