<?php
/**
 * approvals.php — Approval workflow for celebration sends.
 *
 * Flow:  HR requests a batch  →  an Admin/SuperAdmin approves (or rejects)  →
 * once approved it can be sent. Every step is written to the audit trail.
 * Birthday celebration sends only.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../bdaynotify.php';

// Access to the Approvals feature is admin-granted (default: managers). Approve/
// reject/send remain SuperAdmin-only via communications.approve (see below), so
// a granted member can only REQUEST a batch — separation of duties is kept.
require_feature($conn, 'approvals');

$role      = $_SESSION['admin_role'] ?? 'Viewer';
$canApprove = user_can('communications.approve');
$me        = (int)($_SESSION['admin_id'] ?? 0);
$myName    = $_SESSION['admin_name'] ?? 'User';
$today     = date('Y-m-d');
$flash = $flashType = '';

/** Load today's batch row for a type (or null). */
function batchFor(PDO $conn, string $type): ?array
{
    $s = $conn->prepare("SELECT * FROM birthday_approvals WHERE batch_date = CURDATE() AND batch_type = ?");
    $s->execute([$type]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Today's birthday recipients (anniversaries were removed). */
function recipientsFor(string $type = 'birthday'): array
{
    return bdaynotify_todays_birthdays();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    $type   = 'birthday';   // anniversaries removed — only birthday batches exist
    $count  = count(recipientsFor($type));

    if ($action === 'request') {
        $conn->prepare("
            INSERT INTO birthday_approvals (batch_date, batch_type, recipient_count, status, requested_by, requested_by_name)
            VALUES (CURDATE(), ?, ?, 'pending', ?, ?)
            ON DUPLICATE KEY UPDATE recipient_count=VALUES(recipient_count), status='pending',
                requested_by=VALUES(requested_by), requested_by_name=VALUES(requested_by_name),
                approved_by=NULL, approved_by_name=NULL, decided_at=NULL
        ")->execute([$type, $count, $me, $myName]);
        audit_log($conn, 'UPDATE', 'birthday_approvals', $today . ':' . $type, null, ['status' => 'pending', 'recipients' => $count]);
        $flash = ucfirst($type) . " batch submitted for approval ({$count} recipients).";
        $flashType = 'success';

    } elseif ($action === 'approve' && $canApprove) {
        $conn->prepare("UPDATE birthday_approvals SET status='approved', approved_by=?, approved_by_name=?, decided_at=NOW()
                        WHERE batch_date=CURDATE() AND batch_type=? AND status='pending'")
             ->execute([$me, $myName, $type]);
        audit_log($conn, 'UPDATE', 'birthday_approvals', $today . ':' . $type, null, ['status' => 'approved', 'by' => $myName]);
        $flash = ucfirst($type) . ' batch approved. It can now be sent.';
        $flashType = 'success';

    } elseif ($action === 'reject' && $canApprove) {
        $conn->prepare("UPDATE birthday_approvals SET status='rejected', approved_by=?, approved_by_name=?, decided_at=NOW()
                        WHERE batch_date=CURDATE() AND batch_type=? AND status IN ('pending','approved')")
             ->execute([$me, $myName, $type]);
        audit_log($conn, 'UPDATE', 'birthday_approvals', $today . ':' . $type, null, ['status' => 'rejected', 'by' => $myName]);
        $flash = ucfirst($type) . ' batch rejected. Nothing was sent.';
        $flashType = 'success';

    } elseif ($action === 'send' && $canApprove) {
        $batch = batchFor($conn, $type);
        if (!$batch || $batch['status'] !== 'approved') {
            $flash = 'That batch is not approved yet — it cannot be sent.';
            $flashType = 'error';
        } else {
            $sent = $failed = 0;
            foreach (recipientsFor($type) as $emp) {
                $r = bdaynotify_send_birthday($emp['emp_id']);
                $r['ok'] ? $sent++ : $failed++;
            }
            $conn->prepare("UPDATE birthday_approvals SET status='sent', sent_at=NOW(), sent_count=?, failed_count=?
                            WHERE batch_date=CURDATE() AND batch_type=?")
                 ->execute([$sent, $failed, $type]);
            audit_log($conn, 'EMAIL', 'birthday_approvals', $today . ':' . $type, null,
                ['sent' => $sent, 'failed' => $failed, 'by' => $myName]);
            $flash = ucfirst($type) . " batch sent — {$sent} delivered, {$failed} failed.";
            $flashType = $failed ? 'info' : 'success';
        }
    }
}

// Current state (birthday batch only).
$recips = recipientsFor('birthday');
$state  = ['birthday' => ['batch' => batchFor($conn, 'birthday'), 'recipients' => $recips, 'count' => count($recips)]];

// Recent approval history.
try {
    $history = $conn->query("SELECT * FROM birthday_approvals ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $history = []; }

$statusBadge = fn($s) => $s === 'approved' ? 'badge-active' : ($s === 'rejected' ? 'badge-inactive'
    : ($s === 'sent' ? 'badge-blue' : 'badge-blue'));

/**
 * Small round thumbnail for the recipient list — real photo when available,
 * otherwise a coloured initials circle (matches the email card avatar).
 */
function approval_thumb(array $rcpt, int $size = 36): string
{
    $name  = trim((string)($rcpt['full_name'] ?? ''));
    $photo = trim((string)($rcpt['photo_path'] ?? ''));
    if ($photo !== '' && function_exists('photo_url')) $photo = photo_url($photo);

    $base = "width:{$size}px;height:{$size}px;border-radius:50%;flex:0 0 auto;";
    if ($photo !== '') {
        return '<img src="' . htmlspecialchars($photo, ENT_QUOTES) . '" width="' . $size . '" height="' . $size . '" '
             . 'alt="" loading="lazy" style="' . $base . 'object-fit:cover;border:1px solid #e2e8f0;background:#f1f5f9;">';
    }
    $initials = function_exists('bday_initials')
        ? bday_initials($name)
        : strtoupper(mb_substr($name, 0, 1, 'UTF-8'));
    $fs = (int)round($size * 0.42);
    return '<span aria-hidden="true" style="' . $base . 'display:inline-flex;align-items:center;justify-content:center;'
         . 'background:#1a4fa0;color:#fff;font-weight:700;font-size:' . $fs . 'px;font-family:Arial,Helvetica,sans-serif;">'
         . htmlspecialchars($initials !== '' ? $initials : '?', ENT_QUOTES) . '</span>';
}

function renderBatchCard(string $type, array $s, bool $canApprove, string $testMode, int $activeTpl): void
{
    $label   = 'Birthdays';
    $icon    = 'cake';
    $batch   = $s['batch'];
    $count   = $s['count'];
    $status  = $batch['status'] ?? 'none';
    $badge   = $status === 'approved' ? 'badge-active' : ($status === 'rejected' ? 'badge-inactive' : 'badge-blue');
    ?>
    <div class="panel" style="flex:1;min-width:300px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;">
        <h2 style="margin:0;"><i data-lucide="<?= $icon ?>"></i> Today's <?= $label ?></h2>
        <?php if ($status !== 'none'): ?>
          <span class="badge <?= $badge ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
        <?php endif; ?>
      </div>
      <p class="text-muted" style="margin-bottom:.5rem;"><strong><?= $count ?></strong> recipient(s) celebrating today.</p>

      <?php if (!empty($s['recipients'])): ?>
        <details open style="margin-bottom:1rem;">
          <summary style="cursor:pointer;font-weight:600;font-size:.85rem;color:var(--b7,#1a4fa0);margin-bottom:.4rem;">
            Who will be emailed (<?= $count ?>) — click to expand/collapse
          </summary>
          <ul style="list-style:none;padding:0;margin:.4rem 0 0;max-height:230px;overflow:auto;border:1px solid var(--border-soft,#e2e8f0);border-radius:8px;">
            <?php foreach ($s['recipients'] as $rcpt):
              $pv    = ['render' => 1, 'emp_id' => (string)($rcpt['emp_id'] ?? ''), 'template' => $activeTpl];
              $pvUrl = BASE_URL . '/dashboard/preview_email.php?' . http_build_query($pv);
            ?>
              <li style="padding:.5rem .7rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;">
                <?= approval_thumb($rcpt, 36) ?>
                <span style="flex:1 1 auto;min-width:0;">
                  <strong style="font-weight:600;"><?= htmlspecialchars($rcpt['full_name'] ?? '—') ?></strong>
                  <?php if (!empty($rcpt['designation_name'])): ?>
                    <span class="text-muted" style="font-size:.78rem;"> · <?= htmlspecialchars($rcpt['designation_name']) ?></span>
                  <?php endif; ?>
                  <br>
                  <span class="text-muted" style="font-size:.76rem;"><?= htmlspecialchars($rcpt['official_email'] ?? '') ?></span>
                </span>
                <span style="text-align:right;white-space:nowrap;display:flex;flex-direction:column;align-items:flex-end;gap:5px;">
                  <span class="text-muted" style="font-size:.78rem;">
                    <?= htmlspecialchars($rcpt['department_name'] ?? '') ?>
                  </span>
                  <a href="<?= htmlspecialchars($pvUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener"
                     class="preview-email-link" data-title="<?= htmlspecialchars($rcpt['full_name'] ?? 'Recipient', ENT_QUOTES) ?>"
                     onclick="return openEmailPreview(this);">
                    <i data-lucide="eye" style="width:13px;height:13px;"></i> Preview email
                  </a>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>

      <?php if ($batch): ?>
        <div style="font-size:.82rem;color:#475569;margin-bottom:1rem;line-height:1.6;">
          <?php if ($batch['requested_by_name']): ?>Requested by <strong><?= htmlspecialchars($batch['requested_by_name']) ?></strong><br><?php endif; ?>
          <?php if ($batch['approved_by_name']): ?><?= $status === 'rejected' ? 'Rejected' : 'Approved' ?> by <strong><?= htmlspecialchars($batch['approved_by_name']) ?></strong><br><?php endif; ?>
          <?php if ($batch['status'] === 'sent'): ?>Sent: <strong><?= (int)$batch['sent_count'] ?></strong> · Failed: <strong><?= (int)$batch['failed_count'] ?></strong><?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="<?= $type ?>">
        <?php if ($count === 0): ?>
          <span class="text-muted">Nothing to send today.</span>
        <?php elseif ($status === 'none' || $status === 'rejected'): ?>
          <button class="btn-primary" name="action" value="request">Request approval</button>
        <?php elseif ($status === 'pending'): ?>
          <?php if ($canApprove): ?>
            <button class="btn-primary" name="action" value="approve">Approve</button>
            <button class="btn-danger"  name="action" value="reject">Reject</button>
          <?php else: ?>
            <span class="text-muted">Awaiting approval from an Administrator.</span>
          <?php endif; ?>
        <?php elseif ($status === 'approved'): ?>
          <?php if ($canApprove): ?>
            <button class="btn-primary" name="action" value="send" onclick="return confirm('Send <?= $count ?> <?= $label ?> email(s) now?');"><i data-lucide="send"></i> Send now<?= $testMode === '1' ? ' (test mode)' : '' ?></button>
            <button class="btn-danger"  name="action" value="reject">Reject</button>
          <?php else: ?>
            <span class="text-muted">Approved — an Admin can send it.</span>
          <?php endif; ?>
        <?php elseif ($status === 'sent'): ?>
          <span class="badge badge-active"><i data-lucide="check"></i> Completed</span>
        <?php endif; ?>
      </form>
    </div>
    <?php
}

$testMode  = getenv('MAIL_TEST_MODE') === '1' ? '1' : '0';
// Active birthday template — so "Preview email" shows the card recipients actually get.
$activeTpl = function_exists('active_birthday_template') ? active_birthday_template($conn) : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Approvals | BDayNotify</title>
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/styles.css">

<style>
  .preview-email-link{font-size:.75rem;display:inline-flex;align-items:center;gap:3px;color:#1a4fa0;
      text-decoration:none;font-weight:600;cursor:pointer;}
  .preview-email-link:hover{text-decoration:underline;}
  .preview-modal{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;}
  .preview-modal[hidden]{display:none;}
  .preview-modal__backdrop{position:absolute;inset:0;background:rgba(15,23,42,.55);}
  .preview-modal__dialog{position:relative;background:#fff;border-radius:12px;width:min(680px,100%);max-height:90vh;
      display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.35);}
  .preview-modal__head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;
      padding:.7rem 1rem;border-bottom:1px solid #e2e8f0;}
  .preview-modal__head strong{font-size:.95rem;color:#0f172a;}
  .preview-modal__actions{display:flex;align-items:center;gap:.5rem;}
  .preview-modal__open{font-size:.75rem;padding:.32rem .65rem;border:1px solid #cbd5e1;border-radius:6px;
      color:#1a4fa0;text-decoration:none;font-weight:600;}
  .preview-modal__open:hover{background:#f0f7ff;border-color:#93c5fd;}
  .preview-modal__close{background:none;border:0;cursor:pointer;color:#475569;padding:.25rem;line-height:0;border-radius:6px;}
  .preview-modal__close:hover{background:#f1f5f9;color:#0f172a;}
  .preview-modal__frame{flex:1 1 auto;width:100%;height:70vh;border:0;background:#e2e8f0;display:block;}
</style>
</head>
<body class="dashboard-page">
<?php
  $NAV_ACTIVE = 'approvals';
  $BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'Approvals']];
  require __DIR__ . '/../includes/topbar.php';
?>

<main id="main-content" class="dashboard-content">
  <div class="page-header">
    <h1>Approval Workflow</h1>
    <span class="text-muted">Request → approve → send. Every step is audited.</span>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flashType === 'error' ? 'error' : ($flashType === 'info' ? 'info' : 'success') ?>"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <?php if (!$canApprove): ?>
    <div class="alert alert-info">You are signed in as <strong><?= htmlspecialchars($role) ?></strong>. You can request batches; approving and sending require an Admin or SuperAdmin.</div>
  <?php endif; ?>

  <div style="display:flex;gap:1.25rem;flex-wrap:wrap;">
    <?php renderBatchCard('birthday', $state['birthday'], $canApprove, $testMode, $activeTpl); ?>
  </div>

  <div class="panel" style="margin-top:1.25rem;">
    <h2>Recent approval history</h2>
    <?php if (empty($history)): ?>
      <p class="empty-state">No approval batches yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Date</th><th>Type</th><th>Recipients</th><th>Status</th><th>Requested by</th><th>Decided by</th><th>Result</th></tr></thead>
          <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td style="white-space:nowrap;"><?= htmlspecialchars($h['batch_date']) ?></td>
              <td><span class="badge badge-blue"><?= htmlspecialchars(ucfirst($h['batch_type'])) ?></span></td>
              <td><?= (int)$h['recipient_count'] ?></td>
              <td><span class="badge <?= $statusBadge($h['status']) ?>"><?= htmlspecialchars(ucfirst($h['status'])) ?></span></td>
              <td><?= htmlspecialchars($h['requested_by_name'] ?? '—') ?></td>
              <td><?= htmlspecialchars($h['approved_by_name'] ?? '—') ?></td>
              <td><?= $h['status'] === 'sent' ? ((int)$h['sent_count'] . ' sent / ' . (int)$h['failed_count'] . ' failed') : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</main>

<!-- Email preview modal — shows the exact card a recipient will receive. -->
<div id="email-preview-modal" class="preview-modal" hidden aria-hidden="true">
  <div class="preview-modal__backdrop" data-close="1"></div>
  <div class="preview-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="email-preview-title">
    <div class="preview-modal__head">
      <strong id="email-preview-title">Email preview</strong>
      <div class="preview-modal__actions">
        <a id="email-preview-open" class="preview-modal__open" href="#" target="_blank" rel="noopener">Open in new tab</a>
        <button type="button" class="preview-modal__close" data-close="1" aria-label="Close preview"><i data-lucide="x"></i></button>
      </div>
    </div>
    <iframe id="email-preview-frame" class="preview-modal__frame" title="Email preview" src="about:blank"></iframe>
  </div>
</div>

<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
<script>
(function () {
  var modal    = document.getElementById('email-preview-modal');
  var frame    = document.getElementById('email-preview-frame');
  var title    = document.getElementById('email-preview-title');
  var openLink = document.getElementById('email-preview-open');

  // Opens the modal. Returns false to cancel the link's default (new-tab) nav.
  // If the modal isn't present, returns true so the link still works as a fallback.
  window.openEmailPreview = function (a) {
    if (!modal || !frame) return true;
    var url = a.getAttribute('href');
    var who = a.getAttribute('data-title') || 'Recipient';
    frame.src = url;
    if (openLink) openLink.setAttribute('href', url);
    if (title)    title.textContent = 'Email preview — ' + who;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    return false;
  };

  window.closeEmailPreview = function () {
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    frame.src = 'about:blank';
    document.body.style.overflow = '';
  };

  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target.closest('[data-close]')) window.closeEmailPreview();
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal && !modal.hidden) window.closeEmailPreview();
  });
})();
</script>
</body>
</html>
