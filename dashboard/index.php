<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/erp.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../includes/mailer.php';

require_feature($conn, 'dashboard');

function initials(?string $name): string {
    $name = trim((string)$name);
    if ($name === '') return '—';
    $p = preg_split('/\s+/u', $name);
    return mb_strtoupper(mb_substr($p[0] ?? '', 0, 1, 'UTF-8') . (count($p) > 1 ? mb_substr(end($p), 0, 1, 'UTF-8') : ''), 'UTF-8');
}

$activeTpl     = active_birthday_template($conn);
$activeTplName = birthdayTemplateCatalog()[$activeTpl] ?? '';
$attachPoster  = app_setting($conn, 'attach_poster', '1') === '1';

$bdayToday = $upcoming7 = $pendingApproval = $failedToday = 0;
$comm = $recent = [];
$lastSend = null; $failedTotal = 0; $smtpOk = false;

try {
    $bdayToday       = (int)$conn->query("SELECT COUNT(*) FROM v_todays_birthdays")->fetchColumn();
    $upcoming7       = (int)$conn->query("SELECT COUNT(*) FROM v_upcoming_birthdays WHERE days_away <= 7")->fetchColumn();
    $pendingApproval = (int)$conn->query("SELECT COUNT(*) FROM birthday_approvals WHERE status = 'pending'")->fetchColumn();
    $failedToday     = (int)$conn->query("SELECT COUNT(*) FROM notification_logs WHERE status = 'failed' AND DATE(created_at) = CURDATE()")->fetchColumn();

    // Employees already sent to today (to mark rows Sent vs Ready).
    $sentIds = $conn->query("SELECT DISTINCT emp_id FROM notification_logs WHERE status='sent' AND sent_on = CURDATE()")->fetchAll(PDO::FETCH_COLUMN);
    $sentIds = array_flip($sentIds ?: []);

    // Build the unified "Upcoming communications" list (no birth year, no age).
    foreach ($conn->query("SELECT emp_id, full_name, department_name FROM v_todays_birthdays LIMIT 25") as $r) {
        $comm[] = ['emp_id'=>$r['emp_id'],'name'=>$r['full_name'],'dept'=>$r['department_name'],'occasion'=>'Birthday','date'=>'Today','sort'=>0,
                   'status'=> isset($sentIds[$r['emp_id']]) ? 'sent' : 'ready'];
    }
    foreach ($conn->query("SELECT emp_id, full_name, department_name, birthday_display, days_away FROM v_upcoming_birthdays LIMIT 12") as $r) {
        $comm[] = ['emp_id'=>$r['emp_id'],'name'=>$r['full_name'],'dept'=>$r['department_name'],'occasion'=>'Birthday',
                   'date'=>$r['birthday_display'],'sort'=>(int)$r['days_away'],'status'=>'scheduled','in'=>(int)$r['days_away']];
    }
    usort($comm, fn($a,$b) => $a['sort'] <=> $b['sort']);
    $comm = array_slice($comm, 0, 12);

    $lastSend    = $conn->query("SELECT MAX(created_at) FROM notification_logs WHERE status='sent'")->fetchColumn();
    $failedTotal = (int)$conn->query("SELECT COUNT(*) FROM notification_logs WHERE status='failed'")->fetchColumn();
    $smtpOk      = function_exists('app_smtp_enabled') ? app_smtp_enabled() : false;

    $recent = $conn->query("
        SELECT a.action, a.table_name, a.created_at, u.full_name AS actor
        FROM audit_logs a LEFT JOIN users u ON u.user_id = a.performed_by
        ORDER BY a.created_at DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Dashboard error: ' . $e->getMessage());
}

$NAV_ACTIVE = 'dashboard';
$PAGE_TITLE = 'Dashboard';
$BREADCRUMBS = [['label' => 'Dashboard']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | Birthday Communications</title>
<link rel="stylesheet" href="<?= htmlspecialchars(ASSETS_URL, ENT_QUOTES) ?>/css/styles.css">

<style>
  /* Clickable summary cards — each opens its matching filtered list. */
  a.summary-link{text-decoration:none;color:inherit;display:block;transition:box-shadow .15s,transform .15s,border-color .15s;}
  a.summary-link:hover{box-shadow:0 6px 18px rgba(2,6,23,.10);transform:translateY(-1px);border-color:#93c5fd;}
  a.summary-link:focus-visible{outline:2px solid #1a4fa0;outline-offset:2px;}
  a.summary-link .s-hint{color:#1a4fa0;font-size:.78rem;font-weight:600;display:inline-flex;align-items:center;gap:3px;margin-top:.35rem;}
  a.summary-link .s-hint i{width:13px;height:13px;}
  
</style>
</head>
<body>
<?php require __DIR__ . '/../includes/topbar.php'; ?>

<main id="main-content" class="dashboard-content">

  <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
    <div>
      <h1>Dashboard</h1>
      <div class="page-sub">Monitor upcoming birthdays, approvals and email delivery.</div>
    </div>
    <div style="display:flex;gap:8px;">
      <a class="btn-secondary" href="<?= BASE_URL ?>/dashboard/employees.php"><i data-lucide="user-plus"></i> Add employee</a>
      <a class="btn-primary" href="<?= BASE_URL ?>/dashboard/approvals.php"><i data-lucide="send"></i> Create communication</a>
    </div>
  </div>

  <!-- Summary strip — every card opens its matching filtered list (server-side). -->
  <div class="summary-strip">
    <a class="summary-item summary-link" href="<?= BASE_URL ?>/dashboard/employees.php?birthday=today" aria-label="View <?= $bdayToday ?> birthday(s) today">
      <div class="s-label"><i data-lucide="cake"></i> Birthdays today</div>
      <div class="s-value"><?= $bdayToday ?></div>
      <div class="s-hint">View list <i data-lucide="arrow-right"></i></div>
    </a>
    <a class="summary-item summary-link" href="<?= BASE_URL ?>/dashboard/employees.php?birthday=upcoming" aria-label="View upcoming birthdays in the next 7 days">
      <div class="s-label"><i data-lucide="calendar-days"></i> Upcoming in 7 days</div>
      <div class="s-value"><?= $upcoming7 ?></div>
      <div class="s-hint">View list <i data-lucide="arrow-right"></i></div>
    </a>
    <a class="summary-item summary-link" href="<?= BASE_URL ?>/dashboard/approvals.php" aria-label="Review <?= $pendingApproval ?> pending approval(s)">
      <div class="s-label"><i data-lucide="check-square"></i> Pending approval</div>
      <div class="s-value"><?= $pendingApproval ?></div>
      <div class="s-hint">Review queue <i data-lucide="arrow-right"></i></div>
    </a>
    <a class="summary-item summary-link" href="<?= BASE_URL ?>/dashboard/logs.php?status=failed" aria-label="View <?= $failedToday ?> failed email(s) today">
      <div class="s-label"><i data-lucide="alert-triangle"></i> Failed today</div>
      <div class="s-value"><?= $failedToday ?></div>
      <div class="s-hint">View failed <i data-lucide="arrow-right"></i></div>
    </a>
  </div>

  <div class="ops-grid">
    <!-- Primary: upcoming communications -->
    <div class="panel" style="padding:0;overflow:hidden;">
      <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border-soft);">
        <h2 style="margin:0;font-size:16px;">Upcoming communications</h2>
        <a href="<?= BASE_URL ?>/dashboard/approvals.php" style="font-size:13px;">Approvals →</a>
      </div>
      <?php if (empty($comm)): ?>
        <p class="empty-state">No upcoming birthdays in the next 30 days. New occasions appear here automatically.</p>
      <?php else: ?>
      <div class="table-wrap" style="border:none;border-radius:0;">
        <table class="data-table">
          <thead><tr><th>Employee</th><th>Department</th><th>Occasion</th><th>Date</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($comm as $c): ?>
            <tr>
              <td style="display:flex;align-items:center;gap:9px;">
                <span class="mini-avatar"><?= htmlspecialchars(initials($c['name'])) ?></span>
                <strong style="font-weight:600;"><?= htmlspecialchars($c['name']) ?></strong>
              </td>
              <td><?= htmlspecialchars($c['dept'] ?? '—') ?></td>
              <td><?= htmlspecialchars($c['occasion']) ?></td>
              <td><?= htmlspecialchars($c['date']) ?><?= isset($c['in']) ? ' <span class="status-muted" style="font-size:12px;">(in '.$c['in'].'d)</span>' : '' ?></td>
              <td>
                <?php if ($c['status'] === 'sent'): ?>
                  <span class="status"><i data-lucide="check"></i> Sent</span>
                <?php elseif ($c['status'] === 'ready'): ?>
                  <span class="status-strong status"><i data-lucide="dot"></i> Ready to send</span>
                <?php else: ?>
                  <span class="status-muted status"><i data-lucide="clock"></i> Scheduled</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;">
                <a href="<?= BASE_URL ?>/dashboard/preview_email.php?emp_id=<?= urlencode($c['emp_id'] ?? '') ?>&template=<?= (int)$activeTpl ?>" class="btn-secondary btn-sm" style="padding:.28rem .6rem;">Preview</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Secondary: system status -->
    <div style="display:flex;flex-direction:column;gap:24px;">
      <div class="panel">
        <h2 style="font-size:15px;margin-bottom:10px;">System status</h2>
        <div class="def-list">
          <div class="def-row"><span class="def-k">SMTP</span><span class="def-v"><?= $smtpOk ? 'Configured' : 'Not configured' ?></span></div>
          <div class="def-row"><span class="def-k">Mail mode</span><span class="def-v"><?= is_test_mode($conn) ? 'Test' : 'Production' ?></span></div>
          <div class="def-row"><span class="def-k">Production sending</span><span class="def-v"><?= is_sending_enabled($conn) ? 'Enabled' : 'Disabled (kill switch)' ?></span></div>
          <div class="def-row"><span class="def-k">Back to ERP</span><span class="def-v"><?php if (erp_is_configured()): ?>Configured<?php else: ?><span title="Set ERP_BASE_URL in .env">Not configured</span><?php endif; ?></span></div>
          <div class="def-row"><span class="def-k">Active template</span><span class="def-v">#<?= (int)$activeTpl ?></span></div>
          <div class="def-row"><span class="def-k">Poster attachment</span><span class="def-v"><?= $attachPoster ? 'On' : 'Off' ?></span></div>
          <div class="def-row"><span class="def-k">Failed messages</span><span class="def-v"><?= $failedTotal ?></span></div>
          <div class="def-row"><span class="def-k">Last successful send</span><span class="def-v"><?= $lastSend ? htmlspecialchars(date('d M, H:i', strtotime($lastSend))) : '—' ?></span></div>
        </div>
        <div style="margin-top:12px;"><a href="<?= BASE_URL ?>/dashboard/system_health.php" style="font-size:13px;">View system health →</a></div>
      </div>

      <div class="panel">
        <h2 style="font-size:15px;margin-bottom:10px;">Recent activity</h2>
        <?php if (empty($recent)): ?>
          <p class="text-muted" style="font-size:13px;">No recent activity recorded.</p>
        <?php else: ?>
          <div class="def-list">
            <?php foreach ($recent as $a): ?>
              <div class="def-row" style="flex-direction:column;align-items:flex-start;gap:2px;">
                <span class="def-v" style="font-weight:600;"><?= htmlspecialchars(ucfirst(strtolower($a['action']))) ?> · <?= htmlspecialchars($a['table_name']) ?></span>
                <span class="def-k" style="font-size:12px;"><?= htmlspecialchars($a['actor'] ?? 'system') ?> · <?= htmlspecialchars(date('d M, H:i', strtotime($a['created_at']))) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <div style="margin-top:12px;"><a href="<?= BASE_URL ?>/dashboard/audit_log.php" style="font-size:13px;">View audit log →</a></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</main>
<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
</body>
</html>
