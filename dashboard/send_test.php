<?php
/**
 * send_test.php — send ONE real birthday email + poster to the safe test
 * recipient (MAIL_TEST_RECIPIENT), for a chosen employee. Never emails the
 * real employee. Logs the attempt so it appears in Send history / Logs.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../includes/poster_generator.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/send_guard.php';

require_feature($conn, 'send');        // belongs to the Send feature
require_cap('communications.send');    // and sending requires send rights

$ok = false; $message = ''; $detail = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    $message = 'Invalid request. Please use the Send test button on the dashboard.';
} else {
    $empId      = trim($_POST['emp_id'] ?? '');
    // Default to the ACTIVE template so a test matches what real sends use.
    $templateId = (int)($_POST['template_id'] ?? active_birthday_template($conn));
    if ($templateId < 1 || $templateId > 10) $templateId = active_birthday_template($conn);
    $attachPoster = app_setting($conn, 'attach_poster', '1') === '1';

    $recipient = getenv('MAIL_TEST_RECIPIENT') ?: (getenv('SMTP_FROM_EMAIL') ?: getenv('SMTP_USERNAME'));

    if (!$recipient) {
        $message = 'No test recipient configured. Set MAIL_TEST_RECIPIENT in .env.';
    } elseif ($empId === '') {
        $message = 'Please choose an employee first.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM v_employee_master_complete WHERE emp_id = ? LIMIT 1");
        $stmt->execute([$empId]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$emp) {
            $message = 'Employee not found: ' . $empId;
        } else {
            $name    = $emp['full_name'] ?: 'Team Member';
            $token   = bin2hex(random_bytes(16));
            $trackB  = defined('BASE_URL') ? BASE_URL : '';
            $pixel   = "<img src=\"{$trackB}/cron/track_open.php?token={$token}\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none\">";
            $notice  = "<div style=\"background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;font-family:Arial,sans-serif;font-size:13px;\">TEST EMAIL — a birthday card for <strong>"
                     . htmlspecialchars($name) . "</strong> (intended address: " . htmlspecialchars($emp['official_email'] ?? 'n/a') . ")</div>";
            $subject = '[TEST] 🎂 Happy Birthday, ' . $name . '!';
            $html    = $notice . getBirthdayEmailTemplate($templateId, $emp) . $pixel;

            // Poster (skipped gracefully if GD is unavailable or attach is off)
            $tmp = '';
            if ($attachPoster && function_exists('imagecreatetruecolor')) {
                $im = generateBirthdayPosterGD($conn, $empId);
                if ($im) { $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bday_' . preg_replace('/\W/', '', $empId) . '.png'; imagepng($im, $tmp); imagedestroy($im); }
            }

            $res = send_app_email($recipient, $subject, $html, "Happy Birthday, {$name}! (BDayNotify test email)", $tmp ?: null, 'Happy_Birthday.png');
            if ($tmp && is_file($tmp)) @unlink($tmp);

            $status = $res['ok'] ? 'sent' : 'failed';
            $ok = $res['ok'];
            if ($res['ok']) {
                $message = $res['simulated']
                    ? 'Simulated (SMTP not configured). No email left the server.'
                    : 'Test email sent to ' . $recipient . '.';
            } else {
                $message = 'Send failed: ' . $res['error'];
            }

            // Record as a TEST send (email_type='test') so it is tracked
            // separately and never occupies an employee's production slot —
            // test sends must not affect duplicate protection.
            record_test_send($conn, $empId, $recipient, $templateId, $subject, $status, $res['error'] ?? null, $token);

            // Governance trail: a test email was sent (section 21).
            require_once __DIR__ . '/../includes/audit.php';
            audit_log($conn, 'SEND', 'notification_logs', 'test:' . $empId, null,
                ['type' => 'test', 'status' => $status, 'to' => $recipient]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Send test email | BDayNotify</title>
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/styles.css">
</head>
<body class="dashboard-page">
<?php
  $NAV_ACTIVE = 'send';
  $BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'Send', 'url' => BASE_URL . '/cron/send_notifications.php'], ['label' => 'Test email']];
  require __DIR__ . '/../includes/topbar.php';
?>
<main id="main-content" class="dashboard-content">
  <div class="page-header"><h1>Send test email</h1></div>
  <div class="panel">
    <div class="alert alert-<?= $ok ? 'success' : 'error' ?>"><?= htmlspecialchars($message) ?></div>
    <p style="color:#475569;font-size:.9rem;">Test emails go only to the configured test recipient — real employees are never contacted.</p>
    <div style="display:flex;gap:.75rem;margin-top:1rem;">
      <a href="<?= BASE_URL ?>/dashboard/index.php" class="btn-secondary">← Back to dashboard</a>
      <a href="<?= BASE_URL ?>/dashboard/logs.php"  class="btn-secondary"><i data-lucide="clipboard-list"></i> View logs</a>
    </div>
  </div>
</main>
<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
</body>
</html>
