<?php
/**
 * system_health.php — deployment & runtime diagnostics.
 *
 * Confirms the app can run on the current host (PHP version, extensions,
 * writable paths, DB, required env vars) and lets an admin test SMTP without
 * sending to real employees. Safe to open on a fresh server to verify install.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/settings.php';

require_feature($conn, 'health');

if (!defined('APP_VERSION')) define('APP_VERSION', '1.1.0');

$actionMsg = null; $actionOk = null; $actionDetail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    // Live SMTP tests / diagnostic sends touch mail config → administrators only.
    require_cap('smtp.configure');
    $act = $_POST['action'] ?? '';
    if ($act === 'test_smtp') {
        $r = test_smtp_connection();
        $actionOk = $r['ok'];
        $actionMsg = $r['ok'] ? 'SMTP connection successful.' : ('SMTP connection failed: ' . $r['error']);
    } elseif ($act === 'diag_email') {
        $to = getenv('MAIL_TEST_RECIPIENT') ?: (getenv('SMTP_FROM_EMAIL') ?: getenv('SMTP_USERNAME'));
        if (!$to) {
            $actionOk = false; $actionMsg = 'No recipient available. Set MAIL_TEST_RECIPIENT or SMTP_FROM_EMAIL in .env.';
        } else {
            $html = '<p>This is a diagnostic email from ' . htmlspecialchars(COMPANY_NAME) . ' BDayNotify.</p>'
                  . '<p>If you received this, SMTP delivery is working.</p><p>Sent: ' . date('Y-m-d H:i:s') . '</p>';
            $r = send_app_email($to, '[DIAGNOSTIC] BDayNotify SMTP test', $html, 'BDayNotify diagnostic email.');
            $actionOk = $r['ok'];
            $actionMsg = $r['ok']
                ? ($r['simulated'] ? 'Simulated (SMTP not configured).' : 'Diagnostic email sent to ' . $to)
                : ('Send failed: ' . $r['error']);
        }
    }
}

/* ── Build the checks ──────────────────────────────────────────────── */
$checks = [];
function chk(&$checks, $label, $state, $detail = '') { $checks[] = compact('label', 'state', 'detail'); }

// PHP version
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
chk($checks, 'PHP version', $phpOk ? 'ok' : 'fail', PHP_VERSION . ($phpOk ? '' : ' — 8.0+ required'));

// Extensions
$exts = ['pdo_mysql' => 'fail', 'gd' => 'warn', 'mbstring' => 'fail', 'openssl' => 'fail', 'fileinfo' => 'warn', 'curl' => 'warn'];
foreach ($exts as $ext => $sev) {
    $loaded = extension_loaded($ext);
    chk($checks, "Extension: {$ext}", $loaded ? 'ok' : $sev,
        $loaded ? 'loaded' : ($ext === 'gd' ? 'needed for posters (enable in php.ini, restart web server)' : 'not loaded'));
}

// Writable uploads
$uploads = APP_ROOT . '/uploads';
$wOk = is_dir($uploads) ? is_writable($uploads) : @mkdir($uploads, 0775, true);
chk($checks, 'uploads/ writable', $wOk ? 'ok' : 'fail', $uploads);

// .env present
$envFile = APP_ROOT . '/.env';
chk($checks, '.env file', is_file($envFile) ? 'ok' : 'fail', is_file($envFile) ? 'present' : 'missing (copy .env.example)');

// Required env vars
$required = ['DB_HOST', 'DB_NAME', 'DB_USER'];
$smtpVars = ['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL'];
$missReq  = array_filter($required, fn($k) => (getenv($k) === false || getenv($k) === ''));
chk($checks, 'Required DB variables', empty($missReq) ? 'ok' : 'fail', empty($missReq) ? 'all set' : 'missing: ' . implode(', ', $missReq));
$missSmtp = array_filter($smtpVars, fn($k) => (getenv($k) === false || getenv($k) === ''));
chk($checks, 'SMTP variables', empty($missSmtp) ? 'ok' : 'warn', empty($missSmtp) ? 'all set' : 'missing: ' . implode(', ', $missSmtp));

// DB connection + counts + views + version
$dbVersion = '—'; $empCount = $viewCount = $logCount = null; $dbState = 'ok'; $dbDetail = 'connected';
try {
    $dbVersion = $conn->query('SELECT VERSION()')->fetchColumn();
    $empCount  = (int) $conn->query('SELECT COUNT(*) FROM employee_master')->fetchColumn();
    $logCount  = (int) $conn->query('SELECT COUNT(*) FROM notification_logs')->fetchColumn();
    $viewCount = (int) $conn->query('SELECT COUNT(*) FROM v_employee_master_complete')->fetchColumn();
} catch (Throwable $e) {
    $dbState = 'fail'; $dbDetail = 'query error: ' . $e->getMessage();
}
chk($checks, 'Database connection', $dbState, $dbDetail);
chk($checks, 'Core view (v_employee_master_complete)', $viewCount === null ? 'fail' : 'ok', $viewCount === null ? 'missing' : "{$viewCount} rows");

// ── Host ERP integration ───────────────────────────────────────────
require_once __DIR__ . '/../config/erp.php';
$erpRaw = trim((string) getenv('ERP_BASE_URL'));
if ($erpRaw === '') {
    chk($checks, 'Host ERP URL', 'warn',
        'Not configured. Add ERP_BASE_URL to the environment configuration to enable the "Back to ERP" navigation control.');
} elseif (erp_back_url() === '') {
    chk($checks, 'Host ERP URL', 'fail',
        'Configured but invalid or unsafe for this environment' . (app_env() === 'production' ? ' (production requires https and a non-localhost host)' : '') . ' — the value is ignored and the control is hidden.');
} else {
    chk($checks, 'Host ERP URL', 'ok', erp_back_url());
}

// ── Environment safety ──────────────────────────────────────────────
$envName = app_env();
$debugOn = in_array(strtolower((string) getenv('APP_DEBUG')), ['1', 'true', 'on', 'yes'], true);
if ($envName === 'production' && $debugOn) {
    chk($checks, 'Debug mode', 'fail', 'APP_DEBUG is enabled in production — disable it (set APP_DEBUG=0).');
} else {
    chk($checks, 'Debug mode', 'ok', $debugOn ? 'on (non-production)' : 'off');
}

// ── Sending master switch (kill switch) ─────────────────────────────
$sendingOn = is_sending_enabled($conn);
$testMode  = is_test_mode($conn);
if ($testMode) {
    chk($checks, 'Email sending', 'ok', 'Test mode is ON — all mail is redirected to the test recipient; real employees are never emailed.');
} elseif (!$sendingOn) {
    chk($checks, 'Email sending', 'warn', 'Production sending is DISABLED (kill switch off). No real employees will be emailed until an administrator enables it in Settings.');
} else {
    chk($checks, 'Email sending', 'ok', 'Production sending is ENABLED — real employees will be emailed.');
}

$counts = [
    'PHP version'      => PHP_VERSION,
    'Database version' => $dbVersion,
    'App version'      => APP_VERSION,
    'Environment'      => $envName,
    'Employees'        => $empCount ?? '—',
    'Emails logged'    => $logCount ?? '—',
    'Timezone'         => date_default_timezone_get(),
    'Base URL'         => BASE_URL,
    'Host ERP URL'     => erp_is_configured() ? erp_back_url() : 'not configured',
    'Test mode'        => $testMode ? 'ON' : 'OFF',
    'Sending'          => $testMode ? 'test mode' : ($sendingOn ? 'production ON' : 'production OFF'),
];

$fails = count(array_filter($checks, fn($c) => $c['state'] === 'fail'));
$warns = count(array_filter($checks, fn($c) => $c['state'] === 'warn'));
if (!function_exists('hstate_badge')) {
    function hstate_badge(string $s): string { return $s === 'ok' ? 'badge-active' : ($s === 'warn' ? 'badge-blue' : 'badge-inactive'); }
    function hstate_icon(string $s): string { return $s === 'ok' ? 'OK' : ($s === 'warn' ? 'WARN' : 'FAIL'); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Health | BDayNotify</title>
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/styles.css">
<style>
  .health-summary{display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem;}
  .kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.5rem;margin-bottom:1rem;}
  .kv div{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.6rem .8rem;font-size:.85rem;}
  .kv strong{display:block;color:#64748b;font-weight:600;font-size:.72rem;text-transform:uppercase;margin-bottom:.15rem;}
  .health-actions{display:flex;gap:.6rem;flex-wrap:wrap;margin:1rem 0;}
</style>
</head>
<body class="dashboard-page">
<?php
  $NAV_ACTIVE = 'health';
  $BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'System health']];
  require __DIR__ . '/../includes/topbar.php';
?>

<main id="main-content" class="dashboard-content">
  <div class="page-header">
    <h1>System health</h1>
    <div class="health-summary">
      <span class="badge <?= $fails ? 'badge-inactive' : 'badge-active' ?>"><?= $fails ? "$fails failed" : 'All critical checks passed' ?></span>
      <?php if ($warns): ?><span class="badge badge-blue"><?= $warns ?> warning<?= $warns > 1 ? 's' : '' ?></span><?php endif; ?>
    </div>
  </div>

  <?php if ($actionMsg !== null): ?>
    <div class="alert alert-<?= $actionOk ? 'success' : 'error' ?>"><?= htmlspecialchars($actionMsg) ?></div>
  <?php endif; ?>

  <div class="panel">
    <h2>Environment</h2>
    <div class="kv">
      <?php foreach ($counts as $k => $v): ?>
        <div><strong><?= htmlspecialchars($k) ?></strong><?= htmlspecialchars((string) $v) ?></div>
      <?php endforeach; ?>
    </div>

    <div class="health-actions">
      <form method="POST" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="test_smtp">
        <button class="btn-secondary" type="submit">Test SMTP connection</button>
      </form>
      <form method="POST" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="diag_email">
        <button class="btn-secondary" type="submit">Send diagnostic email</button>
      </form>
    </div>
  </div>

  <div class="panel">
    <h2>Checks</h2>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th style="width:80px">Status</th><th>Check</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($checks as $c): ?>
          <tr>
            <td><span class="badge <?= hstate_badge($c['state']) ?>"><?= hstate_icon($c['state']) ?></span></td>
            <td><?= htmlspecialchars($c['label']) ?></td>
            <td style="color:#475569;font-size:.85rem;"><?= htmlspecialchars($c['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
</body>
</html>
