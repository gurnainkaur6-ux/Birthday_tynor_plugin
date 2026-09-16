<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';

require_feature($conn, 'logs');

// ── Retry a failed email (authorised senders only) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry') {
    $st   = ($_POST['status'] ?? '');
    $back = BASE_URL . '/dashboard/logs.php' . (in_array($st, ['sent', 'failed'], true) ? '?status=' . $st : '');

    if (!csrf_verify()) {
        $_SESSION['logs_flash'] = 'Invalid security token. Please try again.';
        header('Location: ' . $back); exit;
    }
    require_cap('communications.send');   // sending / retrying is a manager action
    require_once __DIR__ . '/../bdaynotify.php';
    require_once __DIR__ . '/../includes/audit.php';

    $logId = (int) ($_POST['log_id'] ?? 0);
    $r = bdaynotify_retry_birthday($logId);
    audit_log($conn, 'RETRY', 'notification_logs', (string) $logId, null, ['result' => $r['status']]);

    $_SESSION['logs_flash'] = $r['ok']
        ? 'Retry succeeded — the email was sent and this record is now marked Sent.'
        : ('Retry did not succeed: ' . ($r['error'] ?? 'unknown error') . ' (status: ' . $r['status'] . ').');
    header('Location: ' . $back); exit;
}

$logsFlash = $_SESSION['logs_flash'] ?? null;
unset($_SESSION['logs_flash']);

// ── Optional status filter (opened from the dashboard "Failed" card) ──
// Whitelisted value + prepared statement (no user input interpolated in SQL).
$statusFilter = $_GET['status'] ?? '';
$allowed      = ['sent', 'failed'];
$where = '';
$params = [];
if (in_array($statusFilter, $allowed, true)) {
    $where    = 'WHERE nl.status = ?';
    $params[] = $statusFilter;
} else {
    $statusFilter = '';
}

// ── Fetch logs joined with employee_master ────────────────────────
try {
    $stmt = $conn->prepare("
        SELECT
            nl.*,
            e.full_name,
            e.emp_id AS employee_code
        FROM notification_logs nl
        LEFT JOIN employee_master e ON e.emp_id = nl.emp_id
        {$where}
        ORDER BY nl.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (PDOException $ex) {
    $logs = [];
    error_log('logs.php error: ' . $ex->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Send history | Birthday Communications</title>
<meta name="description" content="Review successful, failed and test email deliveries for birthday communications.">
<link rel="stylesheet" href="<?= ASSETS_URL ?>assets/css/styles.css">
</head>
<body>
<?php
  $NAV_ACTIVE = 'logs';
  $BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'Send history']];
  require __DIR__ . '/../includes/topbar.php';
?>

<main id="main-content" class="dashboard-content">
  <?php if ($logsFlash): ?><div class="alert alert-info"><?= htmlspecialchars($logsFlash) ?></div><?php endif; ?>
  <div class="page-header">
    <h1>Send history</h1>
    <?php
      $sentCount   = count(array_filter($logs, fn($l) => $l['status'] === 'sent'));
      $failedCount = count(array_filter($logs, fn($l) => $l['status'] === 'failed'));
      $openedCount = count(array_filter($logs, fn($l) => !empty($l['email_opened_at'])));
    ?>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
      <span class="badge badge-active"><i data-lucide="check"></i> Sent: <?= $sentCount ?></span>
      <?php if ($failedCount): ?>
        <span class="badge badge-inactive"><i data-lucide="x"></i> Failed: <?= $failedCount ?></span>
      <?php endif; ?>
      <span class="badge badge-blue"><i data-lucide="eye"></i> Opened: <?= $openedCount ?></span>
      <span class="badge">Total: <?= count($logs) ?></span>
    </div>
  </div>

  <?php if ($statusFilter !== ''): ?>
    <div class="alert alert-info" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
      <span><i data-lucide="filter"></i> Showing only <strong><?= htmlspecialchars(ucfirst($statusFilter)) ?></strong> emails — <?= count($logs) ?> record(s).</span>
      <a class="btn-secondary" style="font-size:.8rem;padding:4px 10px;" href="<?= BASE_URL ?>/dashboard/logs.php"><i data-lucide="x"></i> Show all history</a>
    </div>
  <?php endif; ?>

  <div class="panel">
    <?php if (empty($logs)): ?>
      <p class="empty-state">
        <?= $statusFilter === 'failed'
            ? 'No failed emails. Every delivery so far has succeeded.'
            : ($statusFilter === 'sent'
                ? 'No sent emails recorded yet.'
                : 'No notification logs yet. Use "Send Now" to trigger emails.') ?>
      </p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Reference</th>
            <th>Date / Time</th>
            <th>Employee</th>
            <th>Email Sent To</th>
            <th>Type</th>
            <th>Send Status</th>
            <th>Delivered</th>
            <th>Opened</th>
            <th>Details / Error</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <?php
            $ref = $l['reference_id'] ?: ('COM-' . date('Y', strtotime((string)($l['created_at'] ?? 'now'))) . '-' . str_pad((string)$l['log_id'], 6, '0', STR_PAD_LEFT));
          ?>
          <tr>
            <td style="white-space:nowrap;font-size:0.78rem;font-family:ui-monospace,Consolas,monospace;color:#1a4fa0;">
              <?= htmlspecialchars($ref) ?>
            </td>
            <td style="white-space:nowrap;font-size:0.85rem;">
              <?= htmlspecialchars($l['created_at'] ?? '—') ?>
            </td>
            <td>
              <strong><?= htmlspecialchars($l['full_name'] ?? $l['employee_code'] ?? '—') ?></strong>
              <?php if (!empty($l['employee_code'])): ?>
                <br><small class="text-muted"><?= htmlspecialchars($l['employee_code']) ?></small>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($l['recipient_address'] ?? '—') ?></td>
            <td>
              <span class="badge badge-blue"><?= htmlspecialchars(ucfirst($l['email_type'] ?? '')) ?></span>
            </td>
            <td>
              <?php if ($l['status'] === 'sent'): ?>
                <span class="badge badge-active"><i data-lucide="check"></i> Sent</span>
              <?php elseif ($l['status'] === 'failed'): ?>
                <span class="badge badge-inactive"><i data-lucide="x"></i> Failed</span>
              <?php else: ?>
                <span class="badge"><i data-lucide="minus"></i> <?= htmlspecialchars($l['status']) ?></span>
              <?php endif; ?>
            </td>
            <td style="font-size:0.8rem;text-align:center;">
              <?php if (!empty($l['email_delivered_at']) || $l['status'] === 'sent'): ?>
                <span class="badge badge-active" title="Accepted by SMTP server"><i data-lucide="check"></i> Yes</span>
              <?php elseif ($l['status'] === 'failed'): ?>
                <span class="badge badge-inactive"><i data-lucide="x"></i> No</span>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:0.78rem;">—</span>
              <?php endif; ?>
            </td>
            <td style="font-size:0.8rem;">
              <?php if (!empty($l['email_opened_at'])): ?>
                <span class="badge badge-active" title="Opened at <?= htmlspecialchars($l['email_opened_at']) ?>">
                  <i data-lucide="eye"></i> <?= htmlspecialchars(date('d M, H:i', strtotime($l['email_opened_at']))) ?>
                </span>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:0.78rem;">Not yet</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($l['status'] === 'sent' || stripos($l['error_message'] ?? '', 'Simulated') !== false): ?>
                <a href="view_sent.php?id=<?= (int)$l['log_id'] ?>"
                   class="btn-secondary" style="font-size:0.75rem;padding:4px 8px;"><i data-lucide="eye"></i> View Email</a>
              <?php else: ?>
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                  <span class="text-muted" style="font-size:.8rem;" title="<?= htmlspecialchars($l['error_message'] ?? '') ?>">
                    <?= htmlspecialchars(($l['failure_category'] ?? '') !== '' ? ucfirst(str_replace('_', ' ', $l['failure_category'])) : 'Delivery issue') ?>
                  </span>
                  <?php if ($l['status'] === 'failed' && ($l['email_type'] ?? '') === 'personal' && !empty($l['emp_id']) && user_can('communications.send')): ?>
                    <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Retry sending this birthday email now?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="retry">
                      <input type="hidden" name="log_id" value="<?= (int)$l['log_id'] ?>">
                      <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES) ?>"><?php endif; ?>
                      <button type="submit" class="btn-secondary" style="font-size:.72rem;padding:4px 8px;"><i data-lucide="refresh-cw"></i> Retry</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</main>
<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
</body>
</html>