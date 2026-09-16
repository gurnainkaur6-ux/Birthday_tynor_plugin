<?php
/**
 * send_birthday_notification.php  (also accessible via send_notifications.php)
 * ─────────────────────────────────────────────────────────────────────────────
 * Runs daily (cron) or manually from the dashboard.
 * 1. Sends a personalised birthday card to each birthday employee.
 * 2. Sends a company-wide announcement to ALL other employees.
 * 3. Logs every attempt (with delivery status + open-tracking token) to
 *    notification_logs.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../includes/poster_generator.php';
require_once __DIR__ . '/../includes/send_guard.php';

// ── Scheduler run logging (feeds Scheduler Management dashboard stats) ────
$isCli         = (PHP_SAPI === 'cli');
// A verified CRON_SECRET (see cron/automated_scheduler.php) is as trustworthy
// as CLI access for the purpose of this gate — both bypass the interactive
// admin-session/CSRF checks below, since neither has a browser session.
$isTrustedCron  = $isCli || defined('CRON_TRUSTED_RUN');
$runStart       = microtime(true);
$runTriggeredBy = $isCli ? 'cron' : (defined('CRON_TRUSTED_RUN') ? 'cron (web trigger)' : ($_SESSION['admin_name'] ?? 'dashboard'));

function log_scheduler_run(PDO $conn, string $status, array $stats = [], ?string $errorMsg = null, ?float $startedAt = null, string $triggeredBy = 'scheduler'): void {
    $execMs = $startedAt ? (int) round((microtime(true) - $startedAt) * 1000) : null;
    try {
        $stmt = $conn->prepare("
            INSERT INTO scheduler_runs
                (job_name, run_date, run_time, status, employees_found, emails_sent, emails_failed,
                 broadcast_emails_sent, broadcast_emails_failed, execution_time_ms, error_message,
                 completed_at, triggered_by)
            VALUES
                ('daily_birthday_notification', CURDATE(), NOW(), :status, :found, :sent, :failed,
                 :bsent, :bfailed, :exec_ms, :error, :completed_at, :triggered_by)
            ON DUPLICATE KEY UPDATE
                run_time = NOW(), status = VALUES(status),
                employees_found = VALUES(employees_found), emails_sent = VALUES(emails_sent),
                emails_failed = VALUES(emails_failed), broadcast_emails_sent = VALUES(broadcast_emails_sent),
                broadcast_emails_failed = VALUES(broadcast_emails_failed),
                execution_time_ms = COALESCE(VALUES(execution_time_ms), execution_time_ms),
                error_message = VALUES(error_message), completed_at = VALUES(completed_at),
                triggered_by = VALUES(triggered_by)
        ");
        $stmt->execute([
            ':status'       => $status,
            ':found'        => $stats['found']   ?? 0,
            ':sent'         => $stats['sent']    ?? 0,
            ':failed'       => $stats['failed']  ?? 0,
            ':bsent'        => $stats['bsent']   ?? 0,
            ':bfailed'      => $stats['bfailed'] ?? 0,
            ':exec_ms'      => $execMs,
            ':error'        => $errorMsg,
            ':completed_at' => in_array($status, ['completed', 'failed'], true) ? date('Y-m-d H:i:s') : null,
            ':triggered_by' => $triggeredBy,
        ]);
    } catch (Throwable $e) {
        // Logging must never break the actual send.
    }
}

if (!$isTrustedCron) {
    require_once __DIR__ . '/../includes/auth_check.php';
    require_once __DIR__ . '/../includes/csrf.php';
    require_once __DIR__ . '/../includes/rbac.php';
    require_feature($conn, 'send');        // respect a disabled Send feature (per-user privilege)
    require_cap('communications.send');    // and the role capability to send
    // Guard: never blast from the web without an explicit confirmation.
    // Preview is allowed; everything else is redirected to the confirmation gate.
    $isPreview = isset($_GET['preview']);
    $confirmed = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === '1' && csrf_verify());
    if (!$isPreview && !$confirmed) {
        header('Location: ' . BASE_URL . '/cron/send_notifications.php');
        exit;
    }
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    die("PHPMailer not found. Run: composer require phpmailer/phpmailer\n");
}
require_once $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\SMTP;

// ── SMTP config from .env ──────────────────────────────────────────
$smtpHost    = getenv('SMTP_HOST')       ?: '';
$smtpPort    = (int)(getenv('SMTP_PORT') ?: 587);
$smtpUser    = getenv('SMTP_USERNAME')   ?: '';
$smtpPass    = getenv('SMTP_PASSWORD')   ?: '';
$fromEmail   = getenv('SMTP_FROM_EMAIL') ?: 'hr@company.com';
$fromName    = getenv('SMTP_FROM_NAME')  ?: 'HR Team';
$encType     = strtolower(trim(getenv('SMTP_ENCRYPTION') ?: 'tls'));
$throttleMs  = (int)(getenv('MAIL_THROTTLE_MS') ?: 400);

$smtpEnabled = !empty($smtpHost) && !empty($smtpUser) && !empty($smtpPass);

// ── Test mode: redirect ALL mail to a single safe recipient ──────
$testMode      = is_test_mode($conn);
$testRecipient = getenv('MAIL_TEST_RECIPIENT') ?: $fromEmail;

// ── Kill switch: in production, real employees are emailed only when
//    production sending is enabled. Test mode is always allowed (goes to the
//    test recipient). Enforced per-send below via $blocked.
$blocked = production_send_blocked($conn);

// ── Base URL for tracking pixel (always defined by config/config.php) ──
$trackBase = defined('BASE_URL') ? BASE_URL : '';

// ── Delivery settings (admin-chosen, from Email Preview page) ────
require_once __DIR__ . '/../includes/settings.php';
$activeTpl     = active_birthday_template($conn);
$attachPoster  = app_setting($conn, 'attach_poster', '1') === '1';
$sendBroadcast = app_setting($conn, 'send_broadcast', '0') === '1';

// ── 1. Fetch Today's Birthday Employees ──────────────────────────
try {
    // SECURITY FIX: Use prepared statement to prevent SQL injection
    $stmt = $conn->prepare("
        SELECT emp_id, full_name, official_email, department_name,
               designation_name, photo_path, current_age, plant_name, dob_formatted
        FROM v_todays_birthdays
        WHERE official_email IS NOT NULL AND official_email <> ''
          AND emp_id IN (SELECT emp_id FROM employee_master WHERE birthday_enabled = 1)
    ");
    $stmt->execute();
    $birthdayEmps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    log_scheduler_run($conn, 'failed', [], 'DB error fetching birthdays: ' . $e->getMessage(), $runStart, $runTriggeredBy);
    showResult('error', 'DB error fetching birthdays: ' . $e->getMessage());
    exit;
}

// ── 2. Preview Mode (no actual send) ─────────────────────────────
if (isset($_GET['preview'])) {
    $sampleName  = $birthdayEmps[0]['full_name']       ?? 'John Doe';
    $sampleDept  = $birthdayEmps[0]['department_name'] ?? 'Marketing';
    $sampleDesig = $birthdayEmps[0]['designation_name'] ?? 'Manager';
    echo "<!DOCTYPE html><html><body style='background:#e2e8f0;padding:2rem;font-family:sans-serif;'>";
    echo "<h1 style='text-align:center'>Email Output Preview</h1>";
    echo "<p style='text-align:center;color:#475569'>Today's birthday employees: <strong>" . count($birthdayEmps) . "</strong></p>";
    echo "<div style='display:flex;gap:2rem;justify-content:center;flex-wrap:wrap;'>";
    echo "<div><h3>1. Personal Birthday Card</h3>";
    echo "<div style='box-shadow:0 10px 25px rgba(0,0,0,0.1);border-radius:16px;background:#fff;'>";
    echo getPersonalEmailTemplate(1, $sampleName, $sampleDept, $sampleDesig);
    echo "</div></div>";
    echo "<div><h3>2. Company Broadcast Announcement</h3>";
    echo "<div style='box-shadow:0 10px 25px rgba(0,0,0,0.1);border-radius:12px;background:#fff;'>";
    $sample = !empty($birthdayEmps) ? $birthdayEmps : [['full_name'=>'John Doe','department_name'=>'Marketing','designation_name'=>'Manager']];
    echo buildBroadcastEmail($sample);
    echo "</div></div></div>";
    echo "<div style='text-align:center;margin-top:2rem;'><a href='../dashboard/index.php' style='padding:10px 20px;background:#1a4fa0;color:#fff;text-decoration:none;border-radius:6px;'>← Back to Dashboard</a></div>";
    echo "</body></html>";
    exit;
}

// Only log a 'running' row for genuine execution attempts (manual trigger
// or cron) — never for ?preview= requests, so preview never overwrites
// or masks a real run's stats for today (they share the same
// job_name + run_date unique key).
log_scheduler_run($conn, 'running', [], null, $runStart, $runTriggeredBy);

if (empty($birthdayEmps)) {
    log_scheduler_run($conn, 'completed', ['found' => 0], null, $runStart, $runTriggeredBy);
    showResult('info', 'No active employee birthdays found for today. No emails sent.');
    exit;
}

// ── 3. Fetch All OTHER Active Employees for broadcast (only if enabled) ──
$otherEmps = [];
if ($sendBroadcast) {
    try {
        $birthdayIds  = array_column($birthdayEmps, 'emp_id');
        $placeholders = $birthdayIds ? implode(',', array_fill(0, count($birthdayIds), '?')) : "''";
        $stmt = $conn->prepare("
            SELECT
                v.emp_id,
                v.full_name,
                v.official_email
            FROM v_employee_master_complete v
            WHERE LOWER(v.employee_status) = 'active'
              AND v.email_status    = 'Active'
              AND v.official_email IS NOT NULL
              AND v.official_email  != ''
              AND v.emp_id NOT IN ($placeholders)
        ");
        $stmt->execute($birthdayIds);
        $otherEmps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $otherEmps = [];
    }
}

$results = [];

// ── Helper: configure PHPMailer ───────────────────────────────────
function getMailer(): PHPMailer {
    global $smtpHost, $smtpPort, $smtpUser, $smtpPass, $fromEmail, $fromName, $encType;
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $smtpHost;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;
    $mail->Port       = $smtpPort;

    // Encryption
    if ($encType === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encType === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    // Local dev: disable certificate verification (remove in production)
    if (in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true) || PHP_SAPI === 'cli') {
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];
    }

    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);
    $mail->isHTML(true);
    return $mail;
}

// (Logging is now handled centrally by includes/send_guard.php via
//  send_claim()/send_finalize(); the old logAttempt() helper was removed.)

// ── 4. Send Personalised Birthday Emails ─────────────────────────
$announceHtml = buildBroadcastEmail($birthdayEmps);

foreach ($birthdayEmps as $emp) {
    $toEmail = trim($emp['official_email'] ?? '');
    $toName  = trim($emp['full_name']      ?? '');
    $empId   = $emp['emp_id']               ?? null;

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) continue;

    // Test mode: send to the safe recipient, keep the real one on record.
    $intendedEmail = $toEmail;
    if ($testMode) $toEmail = $testRecipient;

    $templateId  = $activeTpl;   // admin-chosen active template (not random)
    $trackToken  = bin2hex(random_bytes(16));
    $trackPixel  = "<img src=\"{$trackBase}/cron/track_open.php?token={$trackToken}\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none\">";
    $subject     = ($testMode ? '[TEST] ' : '') . '🎂 Happy Birthday, ' . $toName . '!';
    $testNotice  = $testMode
        ? "<div style=\"background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;font-family:Arial,sans-serif;font-size:13px;\">TEST MODE — intended recipient: " . htmlspecialchars($intendedEmail) . "</div>"
        : '';
    $html        = $testNotice . getBirthdayEmailTemplate($templateId, $emp) . $trackPixel;

    // Kill switch: skip real sends when production sending is disabled.
    if ($blocked) {
        $results[] = ['email' => $intendedEmail, 'name' => $toName, 'type' => 'Personal Birthday', 'status' => 'skipped (sending disabled)'];
        continue;
    }
    // Atomic duplicate-send guard: reserve today's slot for this employee.
    // A second cron run / double submit / concurrent worker will fail the
    // claim here and skip WITHOUT sending a second email.
    $claim = send_claim($conn, $empId, 'personal', $toEmail, $templateId, $subject);
    if (!$claim['claimed']) {
        $results[] = ['email' => $toEmail, 'name' => $toName, 'type' => 'Personal Birthday',
                      'status' => $claim['duplicate'] ? 'skipped (already sent today)' : 'failed (claim error)'];
        continue;
    }

    // Attach poster image (only when enabled + GD available)
    $tempFile = '';
    if ($attachPoster && function_exists('imagecreatetruecolor')) {
        $im = generateBirthdayPosterGD($conn, (string)$empId);
        if ($im) {
            $safe     = preg_replace('/[^A-Za-z0-9]/', '_', (string)$empId);
            $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'birthday_' . $safe . '.png';
            imagepng($im, $tempFile);
            imagedestroy($im);
        }
    }

    try {
        if (!$smtpEnabled) {
            // ── SIMULATION MODE (no SMTP configured) ──────────────
            send_finalize($conn, (int) $claim['log_id'], 'sent', 'Simulated — no SMTP configured', $trackToken);
            $results[] = ['email' => $toEmail, 'name' => $toName, 'type' => 'Personal Birthday', 'status' => 'sent (simulated)'];
        } else {
            $mail = getMailer();
            $mail->addAddress($toEmail, $toName);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = "Happy Birthday {$toName}! Wishing you a wonderful day from " . (defined('COMPANY_NAME') ? COMPANY_NAME : 'the team') . ".";
            if ($tempFile && is_file($tempFile)) {
                $mail->addAttachment($tempFile, 'Happy_Birthday_' . str_replace(' ', '_', $toName) . '.png');
            }
            $mail->send();
            send_finalize($conn, (int) $claim['log_id'], 'sent', null, $trackToken);
            $results[] = ['email' => $toEmail, 'name' => $toName, 'type' => 'Personal Birthday', 'status' => 'sent'];
        }
    } catch (MailException $ex) {
        $errInfo = isset($mail) ? $mail->ErrorInfo : $ex->getMessage();
        send_finalize($conn, (int) $claim['log_id'], 'failed', $errInfo, $trackToken);
        $results[] = ['email' => $toEmail, 'name' => $toName, 'type' => 'Personal Birthday', 'status' => 'failed'];
    } finally {
        if ($tempFile && is_file($tempFile)) @unlink($tempFile);
    }

    if ($throttleMs > 0) usleep($throttleMs * 1000);
}

// ── 5. Send Broadcast Announcement to All Others ──────────────────
if ($sendBroadcast && !empty($otherEmps) && !empty($birthdayEmps)) {
    $broadcastSubject = ($testMode ? '[TEST] ' : '') . '🎉 Today\'s Company Birthdays at ' . (defined('COMPANY_NAME') ? COMPANY_NAME : 'our company') . '!';

    foreach ($otherEmps as $emp) {
        $toEmail = trim($emp['official_email'] ?? '');
        $toName  = trim($emp['full_name']       ?? '');
        $empId   = $emp['emp_id']                ?? null;

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) continue;

        $intendedEmail = $toEmail;
        if ($testMode) $toEmail = $testRecipient;

        $trackToken = bin2hex(random_bytes(16));
        $trackPixel = "<img src=\"{$trackBase}/cron/track_open.php?token={$trackToken}\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none\">";
        $testNotice = $testMode
            ? "<div style=\"background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;font-family:Arial,sans-serif;font-size:13px;\">TEST MODE — intended recipient: " . htmlspecialchars($intendedEmail) . "</div>"
            : '';
        $html       = $testNotice . $announceHtml . $trackPixel;

        // Kill switch + atomic duplicate guard (one announcement per person/day).
        if ($blocked) {
            $results[] = ['email' => $intendedEmail, 'name' => $toName, 'type' => 'Broadcast', 'status' => 'skipped (sending disabled)'];
            continue;
        }
        $claim = send_claim($conn, $empId, 'announcement', $toEmail, null, $broadcastSubject);
        if (!$claim['claimed']) {
            $results[] = ['email' => $toEmail, 'name' => $toName, 'type' => 'Broadcast',
                          'status' => $claim['duplicate'] ? 'skipped (already sent today)' : 'failed (claim error)'];
            continue;
        }

        try {
            if (!$smtpEnabled) {
                send_finalize($conn, (int) $claim['log_id'], 'sent', 'Simulated — no SMTP configured', $trackToken);
                $results[] = ['email' => $toEmail, 'name' => $toName, 'type' => 'Broadcast', 'status' => 'sent (simulated)'];
            } else {
                $mail = getMailer();
                $mail->addAddress($toEmail, $toName);
                $mail->Subject = $broadcastSubject;
                $mail->Body    = $html;
                $mail->AltBody = "Check the company portal for today's birthday announcements.";
                $mail->send();
                send_finalize($conn, (int) $claim['log_id'], 'sent', null, $trackToken);
                $results[] = ['email' => $toEmail, 'name' => $toName, 'type' => 'Broadcast', 'status' => 'sent'];
            }
        } catch (MailException $ex) {
            $errInfo = isset($mail) ? $mail->ErrorInfo : $ex->getMessage();
            send_finalize($conn, (int) $claim['log_id'], 'failed', $errInfo, $trackToken);
            $results[] = ['email' => $toEmail, 'name' => $toName, 'type' => 'Broadcast', 'status' => 'failed'];
        }

        if ($throttleMs > 0) usleep($throttleMs * 1000);
    }
}

// ── Governance audit: who triggered this batch and the outcome ────
$auditSent = count(array_filter($results, fn($r) => str_starts_with($r['status'], 'sent')));
$auditSkip = count(array_filter($results, fn($r) => str_starts_with($r['status'], 'skipped')));
require_once __DIR__ . '/../includes/audit.php';
audit_log($conn, 'SEND', 'notification_logs', 'birthday:' . date('Y-m-d'), null, [
    'sent'      => $auditSent,
    'skipped'   => $auditSkip,
    'failed'    => count($results) - $auditSent - $auditSkip,
    'test_mode' => $testMode ? 1 : 0,
]);

$personalSent    = count(array_filter($results, fn($r) => $r['type'] === 'Personal Birthday' && str_starts_with($r['status'], 'sent')));
$personalFailed  = count(array_filter($results, fn($r) => $r['type'] === 'Personal Birthday' && $r['status'] === 'failed'));
$broadcastSentCt = count(array_filter($results, fn($r) => $r['type'] === 'Broadcast' && str_starts_with($r['status'], 'sent')));
$broadcastFailCt = count(array_filter($results, fn($r) => $r['type'] === 'Broadcast' && $r['status'] === 'failed'));

log_scheduler_run($conn, 'completed', [
    'found'   => count($birthdayEmps),
    'sent'    => $personalSent,
    'failed'  => $personalFailed,
    'bsent'   => $broadcastSentCt,
    'bfailed' => $broadcastFailCt,
], null, $runStart, $runTriggeredBy);

// ── Render CLI output ─────────────────────────────────────────────
if ($isCli) {
    foreach ($results as $r) {
        echo "[{$r['type']}] [{$r['status']}] {$r['name']} <{$r['email']}>\n";
    }
    exit;
}

// ── Render Web Output ─────────────────────────────────────────────
$sentCount    = count(array_filter($results, fn($r) => str_starts_with($r['status'], 'sent')));
$skippedCount = count(array_filter($results, fn($r) => str_starts_with($r['status'], 'skipped')));
$failedCount  = count($results) - $sentCount - $skippedCount;
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Send Notifications | BDayNotify</title>
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/styles.css"></head>
<body>
<?php $NAV_ACTIVE = 'send'; require __DIR__ . '/../includes/topbar.php'; ?>
<main id="main-content" class="dashboard-content">
  <div class="page-header">
    <h1>Notification results</h1>
    <div style="display:flex;gap:.75rem;">
      <span class="badge badge-active"><i data-lucide="check"></i> Sent: <?= $sentCount ?></span>
      <?php if ($skippedCount): ?>
        <span class="badge badge-blue"><i data-lucide="minus"></i> Skipped: <?= $skippedCount ?></span>
      <?php endif; ?>
      <?php if ($failedCount): ?>
        <span class="badge badge-inactive"><i data-lucide="x"></i> Failed: <?= $failedCount ?></span>
      <?php endif; ?>
      <span class="badge badge-blue">Total: <?= count($results) ?></span>
    </div>
  </div>

  <?php if (!$smtpEnabled): ?>
  <div class="alert alert-info" style="margin-bottom:1rem;">
    <strong>Simulation mode.</strong> SMTP is not configured. Emails were NOT actually sent — they are logged as "simulated". Configure <code>SMTP_HOST</code>, <code>SMTP_USERNAME</code>, and <code>SMTP_PASSWORD</code> in your <code>.env</code> file to enable real email sending.
  </div>
  <?php endif; ?>

  <div class="panel">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Employee</th><th>Email</th><th>Type</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
          <tr>
            <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
            <td><?= htmlspecialchars($r['email']) ?></td>
            <td><span class="badge badge-blue"><?= htmlspecialchars($r['type']) ?></span></td>
            <td>
              <?php if (str_starts_with($r['status'], 'sent')): ?>
                <span class="badge badge-active"><i data-lucide="check"></i> <?= htmlspecialchars($r['status']) ?></span>
              <?php elseif (str_starts_with($r['status'], 'skipped')): ?>
                <span class="badge badge-blue"><i data-lucide="minus"></i> <?= htmlspecialchars($r['status']) ?></span>
              <?php else: ?>
                <span class="badge badge-inactive"><i data-lucide="x"></i> <?= htmlspecialchars($r['status']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($results)): ?>
          <tr><td colspan="4" class="empty-state" style="text-align:center">No emails processed today.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="mt-2" style="display:flex;gap:.75rem;margin-top:1rem;">
      <a href="<?= BASE_URL ?>/dashboard/index.php" class="btn-secondary">← Back to Dashboard</a>
      <a href="<?= BASE_URL ?>/dashboard/logs.php"  class="btn-secondary"><i data-lucide="clipboard-list"></i> View Logs</a>
    </div>
  </div>
</main>
<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
</body></html>

<?php
// ── Utilities ─────────────────────────────────────────────────────
function showResult(string $type, string $msg): void {
    if (PHP_SAPI === 'cli') { die("[{$type}] {$msg}\n"); }
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:3rem'>"
       . "<div style='max-width:500px;background:#fff;border:1px solid #e2e8f0;padding:2rem;border-radius:8px'>"
       . "<h2>BDayNotify</h2><p>" . htmlspecialchars($msg) . "</p>"
       . "<a href='../dashboard/index.php'>← Dashboard</a></div></body></html>";
    exit;
}

function buildBroadcastEmail(array $birthdayEmps): string {
    $c    = defined('COMPANY_NAME') ? htmlspecialchars(COMPANY_NAME) : 'The Company';
    $list = '';
    foreach ($birthdayEmps as $e) {
        $n   = htmlspecialchars($e['full_name']);
        $dep = htmlspecialchars($e['department_name']  ?? 'General');
        $des = htmlspecialchars($e['designation_name'] ?? 'Employee');
        $list .= "<li style='margin-bottom:12px;padding:12px;background:#f8fafc;border-left:4px solid #1a4fa0;border-radius:4px;'>
                    <strong>{$n}</strong><br>
                    <span style='color:#475569;font-size:0.9em'>{$des} — {$dep} Department</span>
                  </li>";
    }
    return <<<HTML
    <div style="font-family:sans-serif;max-width:600px;margin:0 auto;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
      <div style="background:#1a4fa0;padding:1.5rem;color:#fff;text-align:center">
        <h2>🎉 Company Birthdays Today!</h2>
      </div>
      <div style="padding:2rem;background:#fff">
        <p>Hi Team,</p>
        <p>Please join us in wishing a very Happy Birthday to our colleagues celebrating today at <strong>{$c}</strong>:</p>
        <ul style="list-style:none;padding:0;margin:1.5rem 0">
          {$list}
        </ul>
        <p>If you see them today, be sure to wish them well! 🎂</p>
        <p style="color:#94a3b8;font-size:0.85em;margin-top:2rem">— {$c} HR Team</p>
      </div>
    </div>
    HTML;
}