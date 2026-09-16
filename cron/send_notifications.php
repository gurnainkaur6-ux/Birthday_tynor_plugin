<?php
/**
 * send_notifications.php — "Send Now" confirmation gate.
 *
 * Web: shows exactly what will happen (how many recipients, which template,
 * test-mode status, poster/broadcast options) and requires an explicit
 * "Confirm & send" before anything goes out. The actual send is performed by
 * send_birthday_notification.php (which only proceeds on a confirmed POST).
 *
 * CLI: delegates straight to the sender (for cron/Task Scheduler).
 */
require_once __DIR__ . '/../config/config.php';

if (PHP_SAPI === 'cli') {
    require __DIR__ . '/send_birthday_notification.php';
    return;
}

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../includes/rbac.php';

require_feature($conn, 'send');

$catalog       = birthdayTemplateCatalog();
$activeTpl     = active_birthday_template($conn);
$attachPoster  = app_setting($conn, 'attach_poster', '1') === '1';
$sendBroadcast = app_setting($conn, 'send_broadcast', '0') === '1';
$testMode      = is_test_mode($conn);
$testRecipient = getenv('MAIL_TEST_RECIPIENT') ?: (getenv('SMTP_FROM_EMAIL') ?: '');

$bdayCount  = (int)$conn->query("SELECT COUNT(*) FROM v_todays_birthdays")->fetchColumn();
$otherCount = 0;
if ($sendBroadcast && $bdayCount > 0) {
    try {
        $otherCount = (int)$conn->query("
            SELECT COUNT(*) FROM v_employee_master_complete
            WHERE LOWER(employee_status)='active' AND email_status='Active'
              AND official_email IS NOT NULL AND official_email <> ''
              AND emp_id NOT IN (SELECT emp_id FROM v_todays_birthdays)
        ")->fetchColumn();
    } catch (Throwable $e) { $otherCount = 0; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Send Now | BDayNotify</title>
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/styles.css">
</head>
<body class="dashboard-page">
<?php
  $NAV_ACTIVE = 'send';
  $BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'Send']];
  require __DIR__ . '/../includes/topbar.php';
?>

<main id="main-content" class="dashboard-content">
  <div class="page-header"><h1>Send birthday emails now</h1></div>

  <?php if ($testMode): ?>
    <div class="alert alert-info">
      <strong>Test mode is ON.</strong> All emails will go to <strong><?= htmlspecialchars($testRecipient) ?></strong>
      (not real employees), with a <code>[TEST]</code> subject prefix.
    </div>
  <?php elseif (!is_sending_enabled($conn)): ?>
    <div class="alert alert-error">
      <strong>Production sending is paused.</strong> The kill switch is off, so confirming will
      <strong>not</strong> email anyone. Enable production sending in
      <a href="<?= BASE_URL ?>/dashboard/settings.php">Settings</a> first.
    </div>
  <?php else: ?>
    <div class="alert alert-error">
      <strong>Production mode.</strong> This will email <strong>real employees</strong>. Make sure the roster and addresses are correct.
    </div>
  <?php endif; ?>

  <div class="panel" style="max-width:640px;">
    <h2>Please confirm</h2>
    <?php if ($bdayCount === 0): ?>
      <p class="empty-state">No employees have a birthday today — there is nothing to send.</p>
      <a href="<?= BASE_URL ?>/dashboard/index.php" class="btn-secondary">← Back to dashboard</a>
    <?php else: ?>
      <ul style="line-height:1.9;font-size:.95rem;margin:0 0 1rem 1.1rem;">
        <li><strong><?= $bdayCount ?></strong> birthday email<?= $bdayCount === 1 ? '' : 's' ?> will be sent<?= $testMode ? ' (to the test address)' : '' ?>.</li>
        <li>Template: <strong>#<?= $activeTpl ?> — <?= htmlspecialchars($catalog[$activeTpl] ?? '') ?></strong>
            (<a href="<?= BASE_URL ?>/dashboard/preview_email.php">change / preview</a>)</li>
        <li>Personalized poster: <strong><?= $attachPoster ? 'attached' : 'off' ?></strong>.</li>
        <li>Company-wide announcement: <strong><?= $sendBroadcast ? ('ON — ' . $otherCount . ' more recipients') : 'off' ?></strong>.</li>
      </ul>

      <form method="POST" action="<?= BASE_URL ?>/cron/send_birthday_notification.php"
            onsubmit="return window.confirm('Send <?= $bdayCount ?> birthday email(s) now?');">
        <?= csrf_field() ?>
        <input type="hidden" name="confirm" value="1">
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
          <button type="submit" class="btn-primary">✓ Confirm &amp; send now</button>
          <a href="<?= BASE_URL ?>/dashboard/approvals.php" class="btn-secondary">Use approval workflow instead</a>
          <a href="<?= BASE_URL ?>/dashboard/index.php" class="btn-secondary">Cancel</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</main>
<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
</body>
</html>