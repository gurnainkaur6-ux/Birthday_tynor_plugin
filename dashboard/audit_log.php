<?php
/**
 * audit_log.php — Audit trail viewer (who did what, when, from where).
 * Restricted to Admin / SuperAdmin.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

require_feature($conn, 'audit');

$actionFilter = trim($_GET['action'] ?? '');
$valid = ['INSERT', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'EMAIL',
          'SEND', 'RETRY', 'APPROVE', 'REJECT', 'SETTINGS', 'EXPORT', 'IMPORT', 'SYNC'];

try {
    if ($actionFilter !== '' && in_array($actionFilter, $valid, true)) {
        $stmt = $conn->prepare("
            SELECT a.*, u.full_name AS actor
            FROM audit_logs a
            LEFT JOIN users u ON u.user_id = a.performed_by
            WHERE a.action = ?
            ORDER BY a.created_at DESC
            LIMIT 300
        ");
        $stmt->execute([$actionFilter]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $conn->query("
            SELECT a.*, u.full_name AS actor
            FROM audit_logs a
            LEFT JOIN users u ON u.user_id = a.performed_by
            ORDER BY a.created_at DESC
            LIMIT 300
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $rows = [];
    $err = $e->getMessage();
}

// Counts per action for the summary chips.
$counts = [];
try {
    foreach ($conn->query("SELECT action, COUNT(*) c FROM audit_logs GROUP BY action")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[$r['action']] = (int)$r['c'];
    }
} catch (Throwable $e) { /* ignore */ }

$badgeFor = fn($a) => in_array($a, ['DELETE'], true) ? 'badge-inactive'
    : (in_array($a, ['LOGIN', 'INSERT'], true) ? 'badge-active' : 'badge-blue');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Trail | BDayNotify</title>
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/styles.css">
<style>
  .audit-json{font-family:ui-monospace,Consolas,monospace;font-size:.75rem;color:#475569;max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .chip-row{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;}
  .chip{padding:.3rem .7rem;border-radius:999px;font-size:.78rem;border:1px solid var(--g2);background:#fff;color:var(--g6);text-decoration:none;}
  .chip.active,.chip:hover{background:var(--b7);color:#fff;border-color:var(--b7);}
</style>
</head>
<body class="dashboard-page">
<?php
  $NAV_ACTIVE = 'audit';
  $BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'Audit log']];
  require __DIR__ . '/../includes/topbar.php';
?>

<main id="main-content" class="dashboard-content">
  <div class="page-header">
    <h1>Audit Trail</h1>
    <span class="text-muted">Most recent 300 events</span>
  </div>

  <?php if (!empty($err)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <div class="panel">
    <div class="chip-row">
      <a class="chip <?= $actionFilter === '' ? 'active' : '' ?>" href="?">All</a>
      <?php foreach ($valid as $a): ?>
        <a class="chip <?= $actionFilter === $a ? 'active' : '' ?>" href="?action=<?= $a ?>">
          <?= $a ?><?= isset($counts[$a]) ? ' (' . $counts[$a] . ')' : '' ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($rows)): ?>
      <p class="empty-state">No audit events recorded yet. Actions like logins, employee changes, imports and sends will appear here.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Object</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $detail = $r['new_values'] ?: $r['old_values'] ?: ''; ?>
          <tr>
            <td style="white-space:nowrap;font-size:.82rem;"><?= htmlspecialchars(date('M j, H:i:s', strtotime($r['created_at']))) ?></td>
            <td><?= htmlspecialchars($r['actor'] ?? ('user #' . ($r['performed_by'] ?? '—'))) ?></td>
            <td><span class="badge <?= $badgeFor($r['action']) ?>"><?= htmlspecialchars($r['action']) ?></span></td>
            <td style="font-size:.82rem;"><strong><?= htmlspecialchars($r['table_name']) ?></strong><?= $r['record_id'] ? '<br><small class="text-muted">' . htmlspecialchars($r['record_id']) . '</small>' : '' ?></td>
            <td><span class="audit-json" title="<?= htmlspecialchars($detail) ?>"><?= htmlspecialchars($detail ?: '—') ?></span></td>
            <td style="font-size:.8rem;color:#64748b;"><?= htmlspecialchars($r['ip_address'] ?? '—') ?></td>
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
