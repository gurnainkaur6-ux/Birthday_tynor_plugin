<?php
/**
 * send_guard.php — central duplicate-send protection + kill switch.
 *
 * Every production send path (daily cron, "Send now", approvals, plugin API)
 * MUST route through these helpers so a birthday/anniversary email can be sent
 * at most once per employee per day, regardless of how many times a job runs
 * or how many workers run concurrently.
 *
 * How it works:
 *   1. send_claim() performs an ATOMIC INSERT of a 'processing' row keyed by
 *      the unique index (emp_id, channel, sent_on, email_type). If a row
 *      already exists (another run already sent/claimed today) the INSERT fails
 *      on the duplicate key and we DO NOT send — this is the guarantee.
 *   2. The caller sends the email only when the claim succeeds.
 *   3. send_finalize() updates that same row to sent/failed with details.
 *
 * Test emails use email_type='test' and DO NOT go through the claim, so they
 * never occupy an employee's production slot (they must not affect duplicate
 * protection) and remain repeatable.
 *
 * The kill switch (EMAIL_SENDING_ENABLED) is enforced here too: in production
 * mode, real employees are only emailed when sending is explicitly enabled.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/settings.php';

if (!defined('SEND_GUARD_LOADED')) {
    define('SEND_GUARD_LOADED', true);

    /**
     * Is a REAL (production) employee send currently blocked?
     * Test mode is always allowed (it only ever reaches the test recipient).
     * In production mode, sending requires the kill switch to be ON.
     * Both flags honour the in-app Settings override (DB) then the env default.
     */
    function production_send_blocked(?PDO $conn = null): bool
    {
        if (is_test_mode($conn)) {
            return false;
        }
        return !is_sending_enabled($conn);
    }

    /** Map an SMTP/provider error string to a coarse failure category. */
    function categorize_send_error(?string $err): ?string
    {
        if ($err === null || $err === '') {
            return null;
        }
        $e = strtolower($err);
        if (str_contains($e, 'authenticat') || str_contains($e, '535') || str_contains($e, 'username and password')) return 'auth_failure';
        if (str_contains($e, 'timed out') || str_contains($e, 'timeout'))                                             return 'connection_timeout';
        if (str_contains($e, 'could not connect') || str_contains($e, 'connection refused') || str_contains($e, 'smtp connect')) return 'connection_failed';
        if (str_contains($e, 'mailbox') && (str_contains($e, 'unavailable') || str_contains($e, 'full')))             return 'mailbox_unavailable';
        if (str_contains($e, 'invalid address') || str_contains($e, 'no such user') || str_contains($e, 'recipient address rejected')) return 'invalid_recipient';
        if (str_contains($e, 'rate') || str_contains($e, 'too many') || str_contains($e, 'quota'))                    return 'rate_limited';
        if (str_contains($e, 'spam') || str_contains($e, 'blocked') || str_contains($e, 'rejected'))                  return 'rejected';
        if (str_contains($e, 'attachment') && str_contains($e, 'size'))                                               return 'attachment_too_large';
        return 'unknown_error';
    }

    /**
     * Atomically claim today's send slot for a production email.
     *
     * @return array{claimed:bool, log_id:?int, reference:?string, duplicate:bool, error?:string}
     *         claimed=false + duplicate=true  → already sent/claimed today (skip)
     *         claimed=false + duplicate=false → unexpected DB error (do NOT send)
     */
    function send_claim(PDO $conn, ?string $empId, string $emailType,
                        string $recipientForRecord, ?int $templateId, ?string $subject): array
    {
        try {
            $conn->prepare(
                "INSERT INTO notification_logs
                    (emp_id, channel, recipient_address, email_type, provider_name,
                     template_id, subject, request_time, status, sent_on, created_at)
                 VALUES (?, 'email', ?, ?, 'SMTP', ?, ?, NOW(), 'processing', CURDATE(), NOW())"
            )->execute([$empId, $recipientForRecord, $emailType, $templateId, $subject]);

            $logId = (int) $conn->lastInsertId();
            $ref   = 'COM-' . date('Y') . '-' . str_pad((string) $logId, 6, '0', STR_PAD_LEFT);
            try {
                $conn->prepare("UPDATE notification_logs SET reference_id = ? WHERE log_id = ?")
                     ->execute([$ref, $logId]);
            } catch (Throwable $e) { /* reference is best-effort */ }

            return ['claimed' => true, 'log_id' => $logId, 'reference' => $ref, 'duplicate' => false];
        } catch (PDOException $e) {
            // 23000 = integrity constraint violation (duplicate unique key).
            if ($e->getCode() === '23000') {
                return ['claimed' => false, 'log_id' => null, 'reference' => null, 'duplicate' => true];
            }
            // Unexpected error: fail safe — do NOT send (avoid a possible duplicate).
            error_log('send_claim error: ' . $e->getMessage());
            return ['claimed' => false, 'log_id' => null, 'reference' => null, 'duplicate' => false, 'error' => $e->getMessage()];
        }
    }

    /** Finalise a claimed row after the send attempt completes. */
    function send_finalize(PDO $conn, int $logId, string $status, ?string $error, ?string $token): void
    {
        $cat = in_array($status, ['failed', 'permanently_failed'], true) ? categorize_send_error($error) : null;
        try {
            $conn->prepare(
                "UPDATE notification_logs
                    SET status = ?, error_message = ?, failure_category = ?,
                        tracking_token = COALESCE(?, tracking_token), response_time = NOW()
                  WHERE log_id = ?"
            )->execute([$status, $error, $cat, $token, $logId]);
        } catch (Throwable $e) {
            error_log('send_finalize failed: ' . $e->getMessage());
        }
    }

    /**
     * Record a TEST send (email_type='test'). Never claims a production slot and
     * never blocked by the kill switch. Idempotent upsert per employee/day.
     */
    function record_test_send(PDO $conn, ?string $empId, string $recipient, ?int $templateId,
                              string $subject, string $status, ?string $error, ?string $token): void
    {
        $cat = $status !== 'sent' ? categorize_send_error($error) : null;
        try {
            $conn->prepare(
                "INSERT INTO notification_logs
                    (emp_id, channel, recipient_address, email_type, provider_name, template_id, subject,
                     request_time, response_time, status, error_message, failure_category, tracking_token, sent_on, created_at)
                 VALUES (?, 'email', ?, 'test', 'SMTP', ?, ?, NOW(), NOW(), ?, ?, ?, ?, CURDATE(), NOW())
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status), error_message = VALUES(error_message),
                    failure_category = VALUES(failure_category), template_id = VALUES(template_id),
                    tracking_token = VALUES(tracking_token), response_time = NOW(), retry_count = retry_count + 1"
            )->execute([$empId, $recipient, $templateId, $subject, $status, $error, $cat, $token]);
        } catch (Throwable $e) {
            error_log('record_test_send failed: ' . $e->getMessage());
        }
    }
}
