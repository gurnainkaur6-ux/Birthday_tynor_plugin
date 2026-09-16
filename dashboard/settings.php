<?php
/**
 * settings.php — operational settings (Administrator only).
 *
 * In-app control of the safety-critical sending switches without editing .env:
 *   • Test mode  (all mail → test recipient, [TEST] subject)
 *   • Production sending kill switch (real employees emailed only when ON)
 *   • Delivery options: attach poster, company broadcast
 *
 * Sender identity / SMTP credentials are shown READ-ONLY (never the password)
 * and remain managed in .env. Every change is written to the audit trail.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/erp.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/mailer.php';

require_cap('settings.manage');   // Administrator only

if (!defined('APP_VERSION')) define('APP_VERSION', '1.2.0');

// ── Save (Post/Redirect/Get so a refresh never re-submits) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['settings_flash'] = ['type' => 'error', 'msg' => 'Invalid security token. Please retry.'];
        header('Location: ' . BASE_URL . '/dashboard/settings.php'); exit;
    }

    $before = [
        'mail_test_mode'        => is_test_mode($conn) ? '1' : '0',
        'email_sending_enabled' => is_sending_enabled($conn) ? '1' : '0',
        'attach_poster'         => app_setting($conn, 'attach_poster', '1'),
        'send_broadcast'        => app_setting($conn, 'send_broadcast', '0'),
    ];

    // Radio: mode = 'test' | 'production'
    $mode        = ($_POST['mail_mode'] ?? 'test') === 'production' ? '0' : '1'; // stored as mail_test_mode
    $sending     = isset($_POST['email_sending_enabled']) ? '1' : '0';
    $poster      = isset($_POST['attach_poster']) ? '1' : '0';
    $broadcast   = isset($_POST['send_broadcast']) ? '1' : '0';

    app_setting_set($conn, 'mail_test_mode', $mode);
    app_setting_set($conn, 'email_sending_enabled', $sending);
    app_setting_set($conn, 'attach_poster', $poster);
    app_setting_set($conn, 'send_broadcast', $broadcast);

    $after = [
        'mail_test_mode'        => $mode,
        'email_sending_enabled' => $sending,
        'attach_poster'         => $poster,
        'send_broadcast'        => $broadcast,
    ];
    // Audit only the fields that actually changed.
    $changed = array_keys(array_filter($after, fn($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
    if ($changed) {
        audit_log($conn, 'SETTINGS', 'app_settings', 'operational',
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($after, array_flip($changed)));
    }

    $note = ($mode === '0' && $sending === '1')
        ? 'Settings saved. PRODUCTION sending is now LIVE — real employees will be emailed.'
        : 'Settings saved.';
    $_SESSION['settings_flash'] = ['type' => 'success', 'msg' => $note];
    header('Location: ' . BASE_URL . '/dashboard/settings.php'); exit;
}

$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);

$testMode   = is_test_mode($conn);
$sendingOn  = is_sending_enabled($conn);
$attach     = app_setting($conn, 'attach_poster', '1') === '1';
$broadcast  = app_setting($conn, 'send_broadcast', '0') === '1';
$activeTpl  = active_birthday_template($conn);
$smtpOk     = function_exists('app_smtp_enabled') && app_smtp_enabled();

// Sender identity (read-only, never the password).
$fromEmail  = getenv('SMTP_FROM_EMAIL') ?: (getenv('SMTP_USERNAME') ?: '');
$fromName   = getenv('SMTP_FROM_NAME') ?: 'HR Team';
$replyTo    = getenv('MAIL_REPLY_TO_ADDRESS') ?: $fromEmail;
$smtpHost   = getenv('SMTP_HOST') ?: '';
$smtpPort   = getenv('SMTP_PORT') ?: '';
$smtpEnc    = getenv('SMTP_ENCRYPTION') ?: 'tls';

$NAV_ACTIVE = 'settings';
$PAGE_TITLE = 'Settings';
$BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'Settings']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings | Birthday Communications</title>
<link rel="stylesheet" href="<?= htmlspecialchars(ASSETS_URL, ENT_QUOTES) ?>/css/styles.css">
<style>
  .set-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;}
  .set-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.25rem;}
  .set-card h2{font-size:1rem;margin-bottom:.75rem;}
  .radio-row{display:flex;gap:1rem;flex-wrap:wrap;margin:.5rem 0;}
  .radio-opt{flex:1;min-width:180px;border:1px solid #cbd5e1;border-radius:10px;padding:.8rem;cursor:pointer;}
  .radio-opt input{margin-right:.4rem;}
  .radio-opt.prod{border-color:#fca5a5;background:#fef2f2;}
  .radio-opt.test{border-color:#a7f3d0;background:#f0fdf4;}
  .switch-row{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.6rem 0;border-top:1px solid #f1f5f9;}
  .def-list .def-row{display:flex;justify-content:space-between;gap:1rem;padding:.35rem 0;border-bottom:1px solid #f1f5f9;font-size:.9rem;}
  .def-k{color:#64748b;} .def-v{font-weight:600;color:#1e293b;word-break:break-all;}
</style>
</head>
<body class="dashboard-page">
<?php require __DIR__ . '/../includes/topbar.php'; ?>
<main id="main-content" class="dashboard-content">
  <div class="page-header">
    <h1>Settings</h1>
    <div class="page-sub">Control how and when birthday communications are sent. Changes are recorded in the audit log.</div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <?php if (!$testMode && !$sendingOn): ?>
    <div class="alert alert-info">Production mode is selected but sending is paused by the kill switch — no employees will be emailed until you enable production sending below.</div>
  <?php endif; ?>

  <form method="POST" id="settings-form">
    <?= csrf_field() ?>
    <div class="set-grid">

      <div class="set-card">
        <h2>Sending mode</h2>
        <p class="text-muted" style="font-size:.85rem;margin-bottom:.5rem;">Test mode routes every message to the test recipient with a <code>[TEST]</code> subject. Production mode emails real employees.</p>
        <div class="radio-row">
          <label class="radio-opt test">
            <input type="radio" name="mail_mode" value="test" <?= $testMode ? 'checked' : '' ?>>
            <strong>Test mode</strong><br><span class="text-muted" style="font-size:.8rem;">Safe — no real employees emailed</span>
          </label>
          <label class="radio-opt prod">
            <input type="radio" name="mail_mode" value="production" <?= !$testMode ? 'checked' : '' ?>>
            <strong>Production</strong><br><span class="text-muted" style="font-size:.8rem;">Real employees will be emailed</span>
          </label>
        </div>
        <div class="switch-row">
          <div><strong>Production sending kill switch</strong><br>
            <span class="text-muted" style="font-size:.8rem;">Must be ON for any real employee email to leave the system.</span></div>
          <label class="switch"><input type="checkbox" name="email_sending_enabled" value="1" <?= $sendingOn ? 'checked' : '' ?>> Enabled</label>
        </div>
        <div class="switch-row" style="border-top:none;">
          <span class="text-muted" style="font-size:.82rem;">Current test recipient: <strong><?= htmlspecialchars(getenv('MAIL_TEST_RECIPIENT') ?: '(not set)') ?></strong></span>
        </div>
      </div>

      <div class="set-card">
        <h2>Delivery options</h2>
        <div class="switch-row" style="border-top:none;">
          <div><strong>Attach personalised poster</strong><br><span class="text-muted" style="font-size:.8rem;">PNG card attached to each birthday email.</span></div>
          <label class="switch"><input type="checkbox" name="attach_poster" value="1" <?= $attach ? 'checked' : '' ?>> On</label>
        </div>
        <div class="switch-row">
          <div><strong>Company-wide announcement</strong><br><span class="text-muted" style="font-size:.8rem;">Notify all other staff of today's birthdays.</span></div>
          <label class="switch"><input type="checkbox" name="send_broadcast" value="1" <?= $broadcast ? 'checked' : '' ?>> On</label>
        </div>
        <div class="switch-row">
          <span class="def-k">Active template</span>
          <span class="def-v">#<?= (int)$activeTpl ?> · <a href="<?= BASE_URL ?>/dashboard/preview_email.php">change</a></span>
        </div>
      </div>

      <div class="set-card">
        <h2>Sender identity <span class="text-muted" style="font-weight:400;font-size:.78rem;">(read-only — set in .env)</span></h2>
        <div class="def-list">
          <div class="def-row"><span class="def-k">From email</span><span class="def-v"><?= htmlspecialchars($fromEmail ?: '(not set)') ?></span></div>
          <div class="def-row"><span class="def-k">From name</span><span class="def-v"><?= htmlspecialchars($fromName) ?></span></div>
          <div class="def-row"><span class="def-k">Reply-to</span><span class="def-v"><?= htmlspecialchars($replyTo ?: '(none)') ?></span></div>
          <div class="def-row"><span class="def-k">SMTP host</span><span class="def-v"><?= htmlspecialchars($smtpHost ?: '(not set)') ?></span></div>
          <div class="def-row"><span class="def-k">SMTP port / enc</span><span class="def-v"><?= htmlspecialchars(($smtpPort ?: '—') . ' / ' . $smtpEnc) ?></span></div>
          <div class="def-row"><span class="def-k">SMTP status</span><span class="def-v"><?= $smtpOk ? 'Configured' : 'Not configured' ?></span></div>
        </div>
        <p class="text-muted" style="font-size:.8rem;margin-top:.5rem;">The SMTP password is never displayed. Test connectivity from <a href="<?= BASE_URL ?>/dashboard/system_health.php">System health</a>.</p>
      </div>

      <div class="set-card">
        <h2>Environment</h2>
        <div class="def-list">
          <div class="def-row"><span class="def-k">Environment</span><span class="def-v"><?= htmlspecialchars(app_env()) ?></span></div>
          <div class="def-row"><span class="def-k">Plugin version</span><span class="def-v"><?= htmlspecialchars(APP_VERSION) ?></span></div>
          <div class="def-row"><span class="def-k">Back to ERP</span><span class="def-v"><?= erp_is_configured() ? 'Configured' : 'Not configured' ?></span></div>
          <div class="def-row"><span class="def-k">Timezone</span><span class="def-v"><?= htmlspecialchars(date_default_timezone_get()) ?></span></div>
        </div>
      </div>

    </div>

    <div style="display:flex;gap:.75rem;margin-top:1.25rem;">
      <button type="submit" class="btn-primary" id="save-btn">Save settings</button>
      <a href="<?= BASE_URL ?>/dashboard/index.php" class="btn-secondary">Cancel</a>
    </div>
  </form>
</main>
<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
<script>
// Confirm before switching to live production sending; guard against double submit.
(function () {
  var form = document.getElementById('settings-form');
  var wasTest = <?= $testMode ? 'true' : 'false' ?>;
  form.addEventListener('submit', function (e) {
    var prod = form.querySelector('input[name="mail_mode"][value="production"]').checked;
    var sending = form.querySelector('input[name="email_sending_enabled"]').checked;
    if (prod && sending && wasTest) {
      if (!confirm('Switch to PRODUCTION and enable sending?\n\nReal employees will receive birthday emails. Continue?')) {
        e.preventDefault(); return false;
      }
    }
    var btn = document.getElementById('save-btn');
    btn.disabled = true; btn.textContent = 'Saving…';
  });
})();
</script>
</body>
</html>
