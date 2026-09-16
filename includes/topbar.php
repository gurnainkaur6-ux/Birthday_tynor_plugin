<?php
/**
 * topbar.php — shared enterprise app shell (fixed sidebar + top utility bar).
 *
 * Every dashboard page includes this once, right after <body>:
 *   $NAV_ACTIVE = 'dashboard';
 *   $PAGE_TITLE = 'Dashboard';
 *   $BREADCRUMBS = [ ['label'=>'Dashboard'] ];   // optional
 *   require __DIR__ . '/../includes/topbar.php';
 *
 * Provides a global "Back to ERP" control (upper-left) on every authenticated
 * page when ERP_BASE_URL is configured; otherwise the control is hidden and
 * System Health surfaces a diagnostic warning (never a broken link).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/erp.php';
require_once __DIR__ . '/../includes/breadcrumbs.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/rbac.php';

$NAV_ACTIVE = $NAV_ACTIVE ?? '';
$B = defined('BASE_URL') ? BASE_URL : '';
$U = $_SESSION['admin_name'] ?? 'Admin';
$R = $_SESSION['admin_role'] ?? '';
$__conn     = $conn ?? null;                   // present on dashboard pages
$testMode   = is_test_mode($__conn);
$sendingOff = !$testMode && !is_sending_enabled($__conn);
$PAGE_TITLE = $PAGE_TITLE ?? 'Birthday Communications';
$erpUrl     = erp_back_url();                 // '' when not configured/unsafe
$envName    = app_env();
$BREADCRUMBS = $BREADCRUMBS ?? [];

// Full navigation definition. Each item declares how it is gated:
//   'feat' => '<feature key>'  -> shown when current_user_can_feature() is true
//                                 (admin-granted, per-user; see includes/rbac.php)
//   'cap'  => '<capability>'   -> shown when user_can() is true (admin-exclusive)
// Items the user may not access are removed and empty groups are hidden, so a
// normal member sees a lean panel while an administrator sees the full app —
// the panels genuinely differ. Every target page still enforces access server-
// side, so hiding a link is defence-in-depth, never the only control.
$navDef = [
    'Overview' => [
        'dashboard' => ["$B/dashboard/index.php", 'Dashboard', 'layout-dashboard', 'feat', 'dashboard'],
    ],
    'People' => [
        'employees' => ["$B/dashboard/employees.php", 'Employees', 'users', 'feat', 'employees'],
    ],
    'Communications' => [
        'approvals' => ["$B/dashboard/approvals.php", 'Approvals', 'check-square', 'feat', 'approvals'],
        'send'      => ["$B/cron/send_notifications.php", 'Send', 'send', 'feat', 'send'],
        'scheduler' => ["$B/dashboard/scheduler.php", 'Scheduler', 'calendar', 'feat', 'scheduler'],
        'logs'      => ["$B/dashboard/logs.php", 'Send history', 'clock', 'feat', 'logs'],
        'analytics' => ["$B/dashboard/analytics.php", 'Analytics', 'bar-chart-3', 'feat', 'analytics'],
    ],
    'Content' => [
        'preview'  => ["$B/dashboard/preview_email.php", 'Email templates', 'mail', 'feat', 'templates'],
        'channels' => ["$B/dashboard/notification_channels.php", 'Channels', 'radio', 'feat', 'channels'],
    ],
    'Governance' => [
        'users'    => ["$B/dashboard/users.php", 'Users & access', 'users-round', 'cap', 'roles.manage'],
        'audit'    => ["$B/dashboard/audit_log.php", 'Audit log', 'shield', 'feat', 'audit'],
        'health'   => ["$B/dashboard/system_health.php", 'System health', 'activity', 'feat', 'health'],
        'settings' => ["$B/dashboard/settings.php", 'Settings', 'settings', 'cap', 'settings.manage'],
    ],
];

$__canFeat = fn(string $k): bool => function_exists('current_user_can_feature') && current_user_can_feature($__conn, $k);
$__canCap  = fn(string $c): bool => function_exists('user_can') && user_can($c);

$groups = [];
foreach ($navDef as $groupLabel => $items) {
    $visible = [];
    foreach ($items as $navKey => [$navUrl, $navText, $navIcon, $gateType, $gateVal]) {
        if ($gateType === 'cap' ? $__canCap($gateVal) : $__canFeat($gateVal)) {
            $visible[$navKey] = [$navUrl, $navText, $navIcon];
        }
    }
    if ($visible) {
        $groups[$groupLabel] = $visible;
    }
}
unset($navDef, $items, $navKey, $navUrl, $navText, $navIcon, $gateType, $gateVal, $visible, $groupLabel);

// Sidebar panel tier badge — makes the three access levels visually distinct:
//   SuperAdmin -> Administrator, Admin -> Manager, everyone else -> Member.
if ($R === 'SuperAdmin') {
    $panelKind = 'is-admin';   $panelLabel = 'Administrator panel'; $panelIcon = 'shield-check';
    $panelTitle = 'Full administrator access, including Settings and Users & access.';
} elseif ($R === 'Admin') {
    $panelKind = 'is-manager'; $panelLabel = 'Manager panel';       $panelIcon = 'shield';
    $panelTitle = 'Manager access to operational features (no Settings or Users & access).';
} else {
    $panelKind = 'is-member';  $panelLabel = 'Member panel';        $panelIcon = 'user-round';
    $panelTitle = 'Standard member access — ask an administrator to enable more features.';
}
$isAdminPanel = ($panelKind === 'is-admin');
?>
<link rel="stylesheet" href="<?= $B ?>/assets/css/theme.css">
<style>
/* Global shell additions: Back-to-ERP control, breadcrumbs, env indicator. */
.erp-back{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;margin-right:10px;
  border:1px solid var(--border-soft,#c7d7ef);border-radius:8px;background:#fff;color:#1a4fa0;
  font-weight:600;font-size:.85rem;text-decoration:none;white-space:nowrap;}
.erp-back:hover{background:#eef4fd;}
.erp-back:focus-visible{outline:2px solid #1a4fa0;outline-offset:2px;}
.breadcrumbs{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:.82rem;color:#64748b;}
.breadcrumbs .crumb{color:#1a4fa0;text-decoration:none;}
.breadcrumbs .crumb:hover{text-decoration:underline;}
.breadcrumbs .crumb-current{color:#334155;font-weight:600;}
.breadcrumbs .crumb-sep{color:#94a3b8;}
.appbar a:focus-visible,.appbar button:focus-visible,.side-link:focus-visible{outline:2px solid #1a4fa0;outline-offset:2px;}
.env-pill.env-staging{background:#fef3c7;color:#92400e;}
.env-pill.env-local,.env-pill.env-testing{background:#e0e7ff;color:#3730a3;}
.skip-link{position:absolute;left:-999px;top:0;background:#1a4fa0;color:#fff;padding:8px 14px;border-radius:0 0 8px 0;z-index:1000;}
.skip-link:focus{left:0;}
/* Panel-type badge under the brand — makes the admin vs member panel obvious. */
.side-panel-type{display:flex;align-items:center;gap:7px;margin:0 12px 10px;padding:6px 10px;border-radius:8px;
  font-size:.72rem;font-weight:700;letter-spacing:.02em;text-transform:uppercase;}
.side-panel-type i{width:14px;height:14px;}
.side-panel-type.is-admin{background:rgba(26,79,160,.14);color:#1a4fa0;border:1px solid rgba(26,79,160,.25);}
.side-panel-type.is-manager{background:rgba(13,148,136,.14);color:#0f766e;border:1px solid rgba(13,148,136,.25);}
.side-panel-type.is-member{background:rgba(100,116,139,.14);color:#475569;border:1px solid rgba(100,116,139,.22);}
</style>

<a href="#main-content" class="skip-link">Skip to main content</a>

<aside class="sidebar" id="app-sidebar">
  <a class="sidebar-brand" href="<?= $B ?>/dashboard/index.php">
    <i data-lucide="cake"></i><span>Birthday Comms</span>
  </a>
  <div class="side-panel-type <?= $panelKind ?>" title="<?= htmlspecialchars($panelTitle, ENT_QUOTES) ?>">
    <i data-lucide="<?= $panelIcon ?>"></i>
    <span><?= $panelLabel ?></span>
  </div>
  <nav class="side-nav" aria-label="Main">
    <?php foreach ($groups as $label => $items): ?>
      <div class="side-group">
        <div class="side-group-label"><?= htmlspecialchars($label) ?></div>
        <?php foreach ($items as $key => [$url, $text, $icon]): ?>
          <a class="side-link<?= $key === $NAV_ACTIVE ? ' active' : '' ?>" href="<?= $url ?>"<?= $key === $NAV_ACTIVE ? ' aria-current="page"' : '' ?>>
            <i data-lucide="<?= $icon ?>"></i><span><?= htmlspecialchars($text) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>
</aside>
<?php
// Prevent the nav loop variables from leaking into the including page's scope
// (a page may define its own $icon/$url/$text/etc. before including this shell).
unset($label, $items, $key, $url, $text, $icon);
?>
<div class="side-backdrop" id="side-backdrop" onclick="document.getElementById('app-sidebar').classList.remove('open');this.classList.remove('open');"></div>

<header class="appbar">
  <button class="side-toggle" type="button" aria-label="Toggle navigation" aria-controls="app-sidebar"
          onclick="document.getElementById('app-sidebar').classList.toggle('open');document.getElementById('side-backdrop').classList.toggle('open');">
    <i data-lucide="menu"></i>
  </button>

  <?php if ($erpUrl !== ''): ?>
    <a class="erp-back" href="<?= htmlspecialchars($erpUrl, ENT_QUOTES) ?>" title="Return to the main ERP system">
      <i data-lucide="arrow-left"></i><span>Back to ERP</span>
    </a>
  <?php endif; ?>

  <span class="appbar-title">
    <?php if (!empty($BREADCRUMBS)): ?>
      <?= render_breadcrumbs($BREADCRUMBS) ?>
    <?php else: ?>
      <?= htmlspecialchars($PAGE_TITLE) ?>
    <?php endif; ?>
  </span>
  <span class="appbar-spacer"></span>
  <div class="appbar-actions">
    <?php if ($envName !== 'production'): ?>
      <span class="env-pill env-<?= htmlspecialchars($envName) ?>" title="Environment: <?= htmlspecialchars($envName) ?>"><?= htmlspecialchars(strtoupper($envName)) ?></span>
    <?php endif; ?>
    <?php if ($testMode): ?><span class="env-pill" title="Test mode: mail is redirected to the test address">TEST MODE</span><?php endif; ?>
    <?php if ($sendingOff): ?><span class="env-pill" style="background:#fee2e2;color:#991b1b;" title="Production sending is disabled (kill switch). Real employees are not emailed.">SENDING PAUSED</span><?php endif; ?>
    <a href="<?= $B ?>/dashboard/system_health.php" title="System health" aria-label="System health"><i data-lucide="activity"></i></a>
    <span class="appbar-title appbar-user" style="font-weight:500;color:var(--text-secondary)"><i data-lucide="user-round"></i> <?= htmlspecialchars($U) ?><?= $R ? ' · ' . htmlspecialchars($R) : '' ?></span>

<a href="<?= $B ?>/auth/logout.php" class="btn-logout" aria-label="Logout">Logout</a>

  </div>
</header>
<script>
// Close the mobile sidebar with Escape or when focus leaves it.
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    var sb = document.getElementById('app-sidebar');
    var bd = document.getElementById('side-backdrop');
    if (sb) sb.classList.remove('open');
    if (bd) bd.classList.remove('open');
  }
});
</script>

<!-- ── Idle session-timeout warning (auto sign-out after inactivity) ── -->
<div id="idle-modal" class="idle-modal" hidden>
  <div class="idle-modal__backdrop"></div>
  <div class="idle-modal__box" role="alertdialog" aria-modal="true" aria-labelledby="idle-title" aria-describedby="idle-desc">
    <h2 id="idle-title" style="margin:0 0 .5rem;font-size:1.15rem;">Are you still there?</h2>
    <p id="idle-desc" style="margin:0 0 1rem;color:#475569;font-size:.92rem;">
      For your security you'll be signed out in <strong id="idle-count">60</strong> second(s) due to inactivity.
    </p>
    <div style="display:flex;gap:.6rem;justify-content:flex-end;flex-wrap:wrap;">
      <a class="btn-secondary" href="<?= $B ?>/auth/logout.php">Sign out now</a>
      <button type="button" class="btn-primary" id="idle-stay">Stay signed in</button>
    </div>
  </div>
</div>
<style>
  .idle-modal{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;padding:20px;}
  .idle-modal[hidden]{display:none;}
  .idle-modal__backdrop{position:absolute;inset:0;background:rgba(15,23,42,.55);}
  .idle-modal__box{position:relative;background:#fff;border-radius:12px;padding:1.5rem;width:min(400px,100%);
      box-shadow:0 20px 60px rgba(2,6,23,.35);}
</style>
<script>
(function () {
  var TIMEOUT = <?= (int) (defined('SESSION_IDLE_TIMEOUT') ? SESSION_IDLE_TIMEOUT : 300) ?>;
  var WARN    = <?= (int) (defined('SESSION_IDLE_WARNING') ? SESSION_IDLE_WARNING : 60) ?>;
  var BASE    = <?= json_encode($B) ?>;
  var PING    = BASE + '/dashboard/ping.php';
  var EXPIRED = BASE + '/auth/logout.php?expired=1';

  var lastActivity = Date.now();
  var lastPing     = Date.now();
  var warning      = false;
  var modal   = document.getElementById('idle-modal');
  var countEl = document.getElementById('idle-count');
  if (!modal) return;

  function idleSeconds() { return (Date.now() - lastActivity) / 1000; }

  // Keep-alive only on genuine user actions (throttled). A truly idle user
  // sends nothing, so the server-side session still expires on schedule.
  function keepAlive() {
    lastPing = Date.now();
    fetch(PING + '?keepalive=1', { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { if (r.status === 401) location.href = EXPIRED; })
      .catch(function () {});
  }

  function onActivity() {
    if (warning) return;                    // while warning, only the button counts
    lastActivity = Date.now();
    if ((Date.now() - lastPing) / 1000 > TIMEOUT / 2) keepAlive();
  }

  function stay() {
    lastActivity = Date.now();
    warning = false;
    modal.hidden = true;
    keepAlive();
  }

  function tick() {
    var idle = idleSeconds();
    if (idle >= TIMEOUT) { location.href = EXPIRED; return; }
    if (idle >= (TIMEOUT - WARN)) {
      if (!warning) { warning = true; modal.hidden = false; }
      if (countEl) countEl.textContent = Math.max(0, Math.ceil(TIMEOUT - idle));
    } else if (warning) {
      warning = false; modal.hidden = true;
    }
  }

  ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (ev) {
    document.addEventListener(ev, onActivity, { passive: true });
  });
  var stayBtn = document.getElementById('idle-stay');
  if (stayBtn) stayBtn.addEventListener('click', stay);

  setInterval(tick, 1000);
})();
</script>