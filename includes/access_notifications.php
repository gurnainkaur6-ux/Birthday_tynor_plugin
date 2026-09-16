<?php
/**
 * access_notifications.php — Email notifications for access control events
 * 
 * Sends emails when:
 * - User requests access (notify admins)
 * - Access is granted (notify user)
 * - Access is revoked (notify user)
 * 
 * All emails respect user notification preferences and test mode.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/settings.php';

if (!function_exists('notify_admins_access_request')) {
    /**
     * Notify administrators when a user requests access.
     * 
     * @param PDO $conn Database connection
     * @param int $requestId Access request ID
     * @return array{sent:int, failed:int} Count of notifications
     */
    function notify_admins_access_request(PDO $conn, int $requestId): array
    {
        $sent = 0;
        $failed = 0;
        
        // Get request details
        $stmt = $conn->prepare("
            SELECT ar.*, u.email as requester_email
            FROM access_requests ar
            JOIN users u ON u.user_id = ar.user_id
            WHERE ar.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            return ['sent' => 0, 'failed' => 1];
        }
        
        // Get all admins who want notifications
        $stmt = $conn->query("
            SELECT u.user_id, u.email, u.full_name
            FROM users u
            LEFT JOIN notification_preferences np ON np.user_id = u.user_id
            WHERE u.role IN ('SuperAdmin', 'Admin')
              AND u.is_active = 1
              AND (np.notify_access_request IS NULL OR np.notify_access_request = 1)
        ");
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $testMode = is_test_mode($conn);
        $testRecipient = getenv('MAIL_TEST_RECIPIENT') ?: getenv('SMTP_FROM_EMAIL');
        
        $subject = ($testMode ? '[TEST] ' : '') . 'Access Request Pending Review';
        $companyName = defined('COMPANY_NAME') ? COMPANY_NAME : 'The Company';
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        $reviewUrl = $baseUrl . '/dashboard/access_requests.php?request_id=' . $requestId;
        
        $html = build_access_request_email([
            'requester_name' => $request['user_name'],
            'requester_email' => $request['requester_email'],
            'feature' => $request['requested_feature'],
            'scope' => $request['requested_scope'],
            'justification' => $request['justification'],
            'requested_at' => $request['requested_at'],
            'review_url' => $reviewUrl,
            'company_name' => $companyName,
        ]);
        
        foreach ($admins as $admin) {
            $toEmail = $testMode ? $testRecipient : $admin['email'];
            $testNotice = $testMode 
                ? "<div style='background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;margin-bottom:16px;'>TEST MODE — intended recipient: " . htmlspecialchars($admin['email']) . "</div>"
                : '';
            
            $result = send_app_email(
                $toEmail,
                $subject,
                $testNotice . $html,
                "Access request from {$request['user_name']} for {$request['requested_feature']}. Review at: {$reviewUrl}"
            );
            
            $result['ok'] ? $sent++ : $failed++;
            
            // Log the notification
            try {
                $conn->prepare("
                    INSERT INTO audit_logs (table_name, record_id, action, new_values, performed_by, ip_address)
                    VALUES ('access_requests', ?, 'EMAIL', ?, NULL, ?)
                ")->execute([
                    (string)$requestId,
                    json_encode(['type' => 'admin_notification', 'admin_id' => $admin['user_id'], 'status' => $result['ok'] ? 'sent' : 'failed']),
                    $_SERVER['REMOTE_ADDR'] ?? 'system'
                ]);
            } catch (Throwable $e) {
                error_log('Failed to log access notification: ' . $e->getMessage());
            }
        }
        
        return ['sent' => $sent, 'failed' => $failed];
    }
}

if (!function_exists('notify_user_access_decision')) {
    /**
     * Notify user when their access request is approved or rejected.
     * 
     * @param PDO $conn Database connection
     * @param int $requestId Access request ID
     * @param string $decision 'approved' or 'rejected' or 'revoked'
     * @return bool True if notification sent successfully
     */
    function notify_user_access_decision(PDO $conn, int $requestId, string $decision): bool
    {
        // Get request and user details
        $stmt = $conn->prepare("
            SELECT ar.*, u.email as requester_email
            FROM access_requests ar
            JOIN users u ON u.user_id = ar.user_id
            WHERE ar.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            return false;
        }
        
        // Check user notification preferences
        $stmt = $conn->prepare("
            SELECT 
                CASE 
                    WHEN ? = 'approved' THEN COALESCE(np.notify_access_granted, 1)
                    WHEN ? = 'revoked' THEN COALESCE(np.notify_access_revoked, 1)
                    ELSE 1
                END as should_notify
            FROM users u
            LEFT JOIN notification_preferences np ON np.user_id = u.user_id
            WHERE u.user_id = ?
        ");
        $stmt->execute([$decision, $decision, $request['user_id']]);
        $shouldNotify = (bool)$stmt->fetchColumn();
        
        if (!$shouldNotify) {
            return true; // User doesn't want notifications - that's OK
        }
        
        $testMode = is_test_mode($conn);
        $toEmail = $testMode 
            ? (getenv('MAIL_TEST_RECIPIENT') ?: getenv('SMTP_FROM_EMAIL'))
            : $request['requester_email'];
        
        $companyName = defined('COMPANY_NAME') ? COMPANY_NAME : 'The Company';
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        
        $subject = ($testMode ? '[TEST] ' : '') . 'Access Request ' . ucfirst($decision);
        
        $html = build_access_decision_email([
            'user_name' => $request['user_name'],
            'decision' => $decision,
            'feature' => $request['requested_feature'],
            'scope' => $request['requested_scope'],
            'reviewed_by' => $request['reviewed_by_name'],
            'reviewed_at' => $request['reviewed_at'],
            'notes' => $request['notes'],
            'dashboard_url' => $baseUrl . '/dashboard/index.php',
            'company_name' => $companyName,
        ]);
        
        $testNotice = $testMode 
            ? "<div style='background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;margin-bottom:16px;'>TEST MODE — intended recipient: " . htmlspecialchars($request['requester_email']) . "</div>"
            : '';
        
        $result = send_app_email(
            $toEmail,
            $subject,
            $testNotice . $html,
            "Your access request for {$request['requested_feature']} has been {$decision}."
        );
        
        // Log the notification
        try {
            $conn->prepare("
                INSERT INTO audit_logs (table_name, record_id, action, new_values, performed_by, ip_address)
                VALUES ('access_requests', ?, 'EMAIL', ?, ?, ?)
            ")->execute([
                (string)$requestId,
                json_encode(['type' => 'user_notification', 'decision' => $decision, 'status' => $result['ok'] ? 'sent' : 'failed']),
                $request['reviewed_by'],
                $_SERVER['REMOTE_ADDR'] ?? 'system'
            ]);
        } catch (Throwable $e) {
            error_log('Failed to log access decision notification: ' . $e->getMessage());
        }
        
        return $result['ok'];
    }
}

if (!function_exists('build_access_request_email')) {
    /**
     * Build HTML email for admin notification of access request.
     */
    function build_access_request_email(array $data): string
    {
        $company = htmlspecialchars($data['company_name'] ?? 'The Company');
        $name = htmlspecialchars($data['requester_name'] ?? 'User');
        $email = htmlspecialchars($data['requester_email'] ?? '');
        $feature = htmlspecialchars($data['feature'] ?? 'Unknown');
        $scope = htmlspecialchars($data['scope'] ?? 'N/A');
        $justification = htmlspecialchars($data['justification'] ?? 'No justification provided');
        $requestedAt = htmlspecialchars($data['requested_at'] ?? '');
        $reviewUrl = htmlspecialchars($data['review_url'] ?? '#');
        
        return <<<HTML
<div style="font-family:sans-serif;max-width:600px;margin:0 auto;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
  <div style="background:#1a4fa0;padding:1.5rem;color:#fff;text-align:center">
    <h2 style="margin:0;font-size:1.3rem">🔐 Access Request Pending</h2>
  </div>
  <div style="padding:2rem;background:#fff">
    <p style="margin:0 0 1rem"><strong>{$name}</strong> has requested additional access privileges.</p>
    
    <table style="width:100%;border-collapse:collapse;margin:1.5rem 0">
      <tr>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600">Requester</td>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0">{$name}<br><small style="color:#64748b">{$email}</small></td>
      </tr>
      <tr>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600">Feature</td>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0">{$feature}</td>
      </tr>
      <tr>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600">Scope</td>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0">{$scope}</td>
      </tr>
      <tr>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600">Requested</td>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0">{$requestedAt}</td>
      </tr>
      <tr>
        <td style="padding:0.5rem;color:#64748b;font-weight:600;vertical-align:top">Justification</td>
        <td style="padding:0.5rem">{$justification}</td>
      </tr>
    </table>
    
    <div style="text-align:center;margin:2rem 0">
      <a href="{$reviewUrl}" style="display:inline-block;padding:0.75rem 1.5rem;background:#1a4fa0;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">Review Request</a>
    </div>
    
    <p style="color:#94a3b8;font-size:0.85em;margin-top:2rem;border-top:1px solid #e2e8f0;padding-top:1rem">
      This is an automated notification from {$company} Birthday Notification System. Please review the request promptly to avoid delays in system access.
    </p>
  </div>
</div>
HTML;
    }
}

if (!function_exists('build_access_decision_email')) {
    /**
     * Build HTML email for user notification of access decision.
     */
    function build_access_decision_email(array $data): string
    {
        $company = htmlspecialchars($data['company_name'] ?? 'The Company');
        $name = htmlspecialchars($data['user_name'] ?? 'User');
        $decision = $data['decision'] ?? 'processed';
        $feature = htmlspecialchars($data['feature'] ?? 'Unknown');
        $scope = htmlspecialchars($data['scope'] ?? 'N/A');
        $reviewedBy = htmlspecialchars($data['reviewed_by'] ?? 'Administrator');
        $reviewedAt = htmlspecialchars($data['reviewed_at'] ?? '');
        $notes = htmlspecialchars($data['notes'] ?? '');
        $dashboardUrl = htmlspecialchars($data['dashboard_url'] ?? '#');
        
        $icon = $decision === 'approved' ? '✅' : ($decision === 'revoked' ? '⚠️' : '❌');
        $color = $decision === 'approved' ? '#059669' : ($decision === 'revoked' ? '#f59e0b' : '#dc2626');
        $title = $decision === 'approved' ? 'Access Granted' : ($decision === 'revoked' ? 'Access Revoked' : 'Access Request Denied');
        
        $message = $decision === 'approved' 
            ? "Your access request has been approved. You now have access to the requested feature."
            : ($decision === 'revoked' 
                ? "Your access has been revoked by an administrator."
                : "Your access request has been reviewed and denied.");
        
        $notesHtml = $notes 
            ? "<tr><td style='padding:0.5rem;color:#64748b;font-weight:600;vertical-align:top'>Administrator Notes</td><td style='padding:0.5rem'>{$notes}</td></tr>"
            : '';
        
        return <<<HTML
<div style="font-family:sans-serif;max-width:600px;margin:0 auto;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
  <div style="background:{$color};padding:1.5rem;color:#fff;text-align:center">
    <h2 style="margin:0;font-size:1.3rem">{$icon} {$title}</h2>
  </div>
  <div style="padding:2rem;background:#fff">
    <p style="margin:0 0 1rem">Hello <strong>{$name}</strong>,</p>
    <p style="margin:0 0 1.5rem">{$message}</p>
    
    <table style="width:100%;border-collapse:collapse;margin:1.5rem 0">
      <tr>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600">Feature</td>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0">{$feature}</td>
      </tr>
      <tr>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600">Scope</td>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0">{$scope}</td>
      </tr>
      <tr>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600">Reviewed By</td>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0">{$reviewedBy}</td>
      </tr>
      <tr>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600">Reviewed At</td>
        <td style="padding:0.5rem;border-bottom:1px solid #e2e8f0">{$reviewedAt}</td>
      </tr>
      {$notesHtml}
    </table>
    
    <div style="text-align:center;margin:2rem 0">
      <a href="{$dashboardUrl}" style="display:inline-block;padding:0.75rem 1.5rem;background:#1a4fa0;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">Go to Dashboard</a>
    </div>
    
    <p style="color:#94a3b8;font-size:0.85em;margin-top:2rem;border-top:1px solid #e2e8f0;padding-top:1rem">
      This is an automated notification from {$company} Birthday Notification System. If you have questions about this decision, please contact your administrator.
    </p>
  </div>
</div>
HTML;
    }
}
