<?php

namespace App\Notifications;

/**
 * Contract every notification channel must fulfil.
 */
interface NotificationChannelInterface
{
    /** Unique slug used to identify this channel in the database (e.g. "email"). */
    public function getChannelKey(): string;

    /** Returns true when this channel can handle the given notification type. */
    public function supports(string $notificationType): bool;

    /** Returns true when the channel has all required credentials / config. */
    public function isConfigured(): bool;

    /**
     * Send a notification to a single recipient.
     *
     * @param array{email?: string, name?: string} $recipient
     * @param array<string, mixed>                  $options   Extra channel-specific data
     */
    public function send(
        array  $recipient,
        string $subject,
        string $body,
        array  $options = []
    ): NotificationResult;
}