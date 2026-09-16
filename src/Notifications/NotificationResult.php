<?php

namespace App\Notifications;

/**
 * Value object returned by every notification channel send() call.
 */
class NotificationResult
{
    // NOTE: kept as normal properties (not `readonly`) so the codebase runs on
    // PHP 8.0+, not just 8.1+. Treat these as write-once by convention.
    public bool    $success;
    public string  $providerName;
    public ?string $referenceId;
    public ?string $errorMessage;
    public bool    $skipped;

    public function __construct(
        bool    $success,
        string  $providerName  = 'Unknown',
        ?string $referenceId   = null,
        ?string $errorMessage  = null,
        bool    $skipped       = false
    ) {
        $this->success      = $success;
        $this->providerName = $providerName;
        $this->referenceId  = $referenceId;
        $this->errorMessage = $errorMessage;
        $this->skipped      = $skipped;
    }

    public function isSuccess(): bool  { return $this->success; }
    public function isSkipped(): bool  { return $this->skipped; }
    public function getError():  ?string { return $this->errorMessage; }
}