<?php
/**
 * users.php — user & access management (Administrator / SuperAdmin only).
 *
 * View pending registrations and every account; approve, reject, disable,
 * reactivate, and change roles. All mutations are POST + CSRF protected,
 * authorised server-side via require_cap('roles.manage'), and written to the
 * audit trail. Guardrails prevent an admin from locking themselves out or
 * removing the last active SuperAdmin.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/audit.php';

require_cap('roles.manage');   // SuperAdmin only (fail-closed)

$me    = (int) ($_SESSION['admin_id'] ?? 0);
$ROLES = ['Viewer', 'Admin', 'SuperAdmin'];

/** Count accounts that can currently sign in as SuperAdmin. */
function active_superadmins(PDO $conn): int
{
    return (int) $conn->query(
        "SELECT COUNT(*) FROM users WHERE role='SuperAdmin' AND status='approved' AND is_active=1"
    )->fetchColumn();
}

$flash = $_SESSION['users_flash'] ?? null;
unset($_SESSION['users_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect = fn() => header('Location: ' . BASE_URL . '/dashboard/users.php');

    if (!csrf_verify()) {
        $_SESSION['users_flash'] = ['type' => 'error', 'msg' => 'Invalid security token. Please retry.'];
        $redirect(); exit;
    }

    $action = $_POST['action'] ?? '';
    $uid    = (int) ($_POST['user_id'] ?? 0);

    $st = $conn->prepare("SELECT user_id, full_name, email, role, is_active, status FROM users WHERE user_id = ? LIMIT 1");
    $st->execute([$uid]);
    $target = $st->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        $_SESSION['users_flash'] = ['type' => 'error', 'msg' => 'User not found.'];
        $redirect(); exit;
    }

    // Never let an admin change their OWN status/role from here.
    if ($uid === $me && in_array($action, ['reject', 'disable', 'set_role'], true)) {
        $_SESSION['users_flash'] = ['type' => 'error', 'msg' => 'You cannot change your own account status or role.'];
        $redirect(); exit;
    }

    // Would this action strip the last active SuperAdmin of access/role?
    $targetIsActiveSA = ($target['role'] === 'SuperAdmin' && $target['status'] === 'approved' && (int)$target['is_active'] === 1);
    $lastSA = $targetIsActiveSA && active_superadmins($conn) <= 1;

    try {
        if ($action === 'approve' || $action === 'reactivate') {
            $conn->prepare("UPDATE users SET status='approved', is_active=1 WHERE user_id=?")->execute([$uid]);
            audit_log($conn, 'APPROVE', 'users', (string)$uid,
                ['status' => $target['status']], ['status' => 'approved', 'is_active' => 1]);
            $_SESSION['users_flash'] = ['type' => 'success', 'msg' => htmlspecialchars($target['full_name']) . ' is now approved and active.'];

        } elseif ($action === 'reject') {
            if ($lastSA) throw new RuntimeException('You cannot reject the last active SuperAdmin.');
            $conn->prepare("UPDATE users SET status='rejected', is_active=0 WHERE user_id=?")->execute([$uid]);
            audit_log($conn, 'REJECT', 'users', (string)$uid,
                ['status' => $target['status']], ['status' => 'rejected', 'is_active' => 0]);
            $_SESSION['users_flash'] = ['type' => 'success', 'msg' => 'Account request rejected.'];

        } elseif ($action === 'disable') {
            if ($lastSA) throw new RuntimeException('You cannot disable the last active SuperAdmin.');
            $conn->prepare("UPDATE users SET status='disabled', is_active=0 WHERE user_id=?")->execute([$uid]);
            audit_log($conn, 'UPDATE', 'users', (string)$uid,
                ['status' => $target['status']], ['status' => 'disabled', 'is_active' => 0]);
            $_SESSION['users_flash'] = ['type' => 'success', 'msg' => htmlspecialchars($target['full_name']) . ' has been disabled.'];

        } elseif ($action === 'set_role') {
            $newRole = in_array($_POST['role'] ?? '', $ROLES, true) ? $_POST['role'] : null;
            if ($newRole === null) throw new RuntimeException('Invalid role.');
            if ($targetIsActiveSA && $newRole !== 'SuperAdmin' && active_superadmins($conn) <= 1) {
                throw new RuntimeException('You cannot change the role of the last active SuperAdmin.');
            }
            if ($newRole !== $target['role']) {
                $conn->prepare("UPDATE users SET role=? WHERE user_id=?")->execute([$newRole, $uid]);
                audit_log($conn, 'UPDATE', 'users', (string)$uid,
                    ['role' => $target['role']], ['role' => $newRole]);
                $_SESSION['users_flash'] = ['type' => 'success', 'msg' => 'Role updated to ' . htmlspecialchars($newRole) . '.'];
            } else {
                $_SESSION['users_flash'] = ['type' => 'info', 'msg' => 'Role unchanged.'];
            }

        } elseif ($action === 'toggle_feature' || $action === 'reset_feature') {
            // Per-user feature grant. Administrators already have everything, so
            // there is nothing to toggle for a SuperAdmin target.
            if ($target['role'] === 'SuperAdmin') {
                throw new RuntimeException('Administrators already have full access to every feature.');
            }
            $featureKey = (string) ($_POST['feature_key'] ?? '');
            $catalog    = feature_catalog();
            if (!array_key_exists($featureKey, $catalog)) {
                throw new RuntimeException('Unknown feature.');
            }
            $label = $catalog[$featureKey][0];

            if ($action === 'reset_feature') {
                $conn->prepare("DELETE FROM user_feature_access WHERE user_id=? AND feature_key=?")
                     ->execute([$uid, $featureKey]);
                audit_log($conn, 'UPDATE', 'user_feature_access', $uid . ':' . $featureKey,
                    null, ['reset_to_role_default' => $featureKey]);
                $_SESSION['users_flash'] = ['type' => 'info',
                    'msg' => $label . ' reset to the role default for ' . $target['full_name'] . '.'];
            } else {
                $enable = ((int) ($_POST['enable'] ?? 0) === 1) ? 1 : 0;
                $conn->prepare(
                    "INSERT INTO user_feature_access (user_id, feature_key, is_enabled, updated_by)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_by = VALUES(updated_by)"
                )->execute([$uid, $featureKey, $enable, $me]);
                audit_log($conn, 'UPDATE', 'user_feature_access', $uid . ':' . $featureKey,
                    null, ['feature' => $featureKey, 'is_enabled' => $enable]);
                $_SESSION['users_flash'] = ['type' => 'success',
                    'msg' => $label . ($enable ? ' enabled' : ' disabled') . ' for ' . $target['full_name'] . '.'];
            }

        } else {
            $_SESSION['users_flash'] = ['type' => 'error', 'msg' => 'Unknown action.'];
        }
    } catch (Throwable $e) {
        $_SESSION['users_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }

    // Feature toggles return to the same user's access editor so it stays open.
    if (in_array($action, ['toggle_feature', 'reset_feature'], true) && $uid > 0) {
        header('Location: ' . BASE_URL . '/dashboard/users.php?access=' . $uid);
    } else {
        $redirect();
    }
    exit;
}

// ── Load users (pending first, then by name) ────────────────────────
try {
    $users = $conn->query(
        "SELECT user_id, full_name, email, role, is_active, status, created_at
         FROM users
         ORDER BY (status='pending') DESC, full_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $users = [];
}
$pending = array_values(array_filter($users, fn($u) => $u['status'] === 'pending'));

// Access editor target (opened via ?access=<uid> or the "Manage access" button).
$accessUid  = (int) ($_GET['access'] ?? 0);
$accessUser = null;
if ($accessUid > 0) {
    foreach ($users as $u) {
        if ((int) $u['user_id'] === $accessUid) { $accessUser = $u; break; }
    }
}

$statusBadge = fn($s) => $s === 'approved' ? 'badge-active'
    : ($s === 'pending' ? 'badge-blue' : 'badge-inactive');

$NAV_ACTIVE  = 'users';
$PAGE_TITLE  = 'Users';
$BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'Users & access']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Users & access | Birthday Communications</title>
<link rel="stylesheet" href="<?= htmlspecialchars(ASSETS_URL, ENT_QUOTES) ?>/css/styles.css">
<style>
  .u-actions{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;}
  .u-actions form{display:inline;margin:0;}
  .btn-xs{font-size:.75rem;padding:.28rem .6rem;border-radius:6px;border:1px solid #cbd5e1;background:#fff;cursor:pointer;}
  .btn-xs.approve{background:#1a4fa0;color:#fff;border-color:#1a4fa0;}
  .btn-xs.danger{color:#b91c1c;border-color:#fca5a5;}
  .u-role select{padding:.3rem .4rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.8rem;}
  .u-self{font-size:.72rem;color:#64748b;}
  a.btn-xs{display:inline-flex;align-items:center;gap:4px;text-decoration:none;color:#1a4fa0;}
  a.btn-xs.approve{color:#fff;}
  /* Per-user feature access editor */
  .access-group{margin-bottom:.5rem;}
  .access-group-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#64748b;margin:.9rem 0 .45rem;}
  .access-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(255px,1fr));gap:.6rem;}
  .access-item{border:1px solid #e2e8f0;border-radius:10px;padding:.7rem .8rem;display:flex;flex-direction:column;gap:.55rem;background:#fff;}
  .access-item-main{display:flex;align-items:center;gap:8px;font-weight:600;font-size:.9rem;color:#1e293b;}
  .access-item-main i{width:16px;height:16px;color:#1a4fa0;flex:0 0 auto;}
  .access-item-status{display:flex;align-items:center;gap:.5rem;}
  .access-src{font-size:.7rem;color:#94a3b8;}
  .access-item-actions{display:flex;gap:.4rem;flex-wrap:wrap;}
  .access-item-actions form{display:inline;margin:0;}
</style>
</head>
<body class="dashboard-page">
<?php require __DIR__ . '/../includes/topbar.php'; ?>
<main id="main-content" class="dashboard-content">
  <div class="page-header">
    <h1>Users &amp; access</h1>
    <div class="page-sub">Approve new registrations and manage roles. Every change is audited.</div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'info' ? 'info' : 'success') ?>">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <?php if ($accessUser): ?>
    <div class="panel" style="border-left:4px solid #1a4fa0;margin-bottom:1.25rem;">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
        <h2 style="margin:0;"><i data-lucide="sliders-horizontal"></i> Feature access &mdash; <?= htmlspecialchars($accessUser['full_name']) ?></h2>
        <a class="btn-xs" href="<?= BASE_URL ?>/dashboard/users.php"><i data-lucide="x"></i> Close</a>
      </div>
      <p class="text-muted" style="font-size:.85rem;margin:.5rem 0 1rem;">
        Role <span class="badge badge-blue"><?= htmlspecialchars($accessUser['role']) ?></span>.
        Enable or disable individual features for this user. Only you (an administrator) can change these, every change is audited, and it takes effect on the user's next page load.
      </p>

      <?php if ($accessUser['role'] === 'SuperAdmin'): ?>
        <div class="alert alert-info">Administrators have full access to every feature &mdash; there is nothing to toggle.</div>
      <?php else:
        $ov = user_feature_overrides($conn, (int) $accessUser['user_id']);
        $byGroup = [];
        foreach (feature_catalog() as $fkey => $meta) { $byGroup[$meta[1]][$fkey] = $meta; }
      ?>
        <?php foreach ($byGroup as $gLabel => $feats): ?>
          <div class="access-group">
            <div class="access-group-label"><?= htmlspecialchars($gLabel) ?></div>
            <div class="access-grid">
              <?php foreach ($feats as $fkey => $meta):
                $eff         = user_feature_enabled($conn, (int) $accessUser['user_id'], $accessUser['role'], $fkey);
                $hasOverride = array_key_exists($fkey, $ov);
              ?>
                <div class="access-item">
                  <div class="access-item-main">
                    <i data-lucide="<?= htmlspecialchars($meta[2]) ?>"></i>
                    <span><?= htmlspecialchars($meta[0]) ?></span>
                  </div>
                  <div class="access-item-status">
                    <span class="badge <?= $eff ? 'badge-active' : 'badge-inactive' ?>"><?= $eff ? 'Enabled' : 'Disabled' ?></span>
                    <span class="access-src"><?= $hasOverride ? 'Custom' : 'Role default' ?></span>
                  </div>
                  <div class="access-item-actions">
                    <form method="POST">
                      <?= csrf_field() ?>
                      <input type="hidden" name="user_id" value="<?= (int) $accessUser['user_id'] ?>">
                      <input type="hidden" name="feature_key" value="<?= htmlspecialchars($fkey, ENT_QUOTES) ?>">
                      <input type="hidden" name="enable" value="<?= $eff ? 0 : 1 ?>">
                      <button class="btn-xs <?= $eff ? 'danger' : 'approve' ?>" name="action" value="toggle_feature">
                        <?= $eff ? 'Disable' : 'Enable' ?>
                      </button>
                    </form>
                    <?php if ($hasOverride): ?>
                      <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int) $accessUser['user_id'] ?>">
                        <input type="hidden" name="feature_key" value="<?= htmlspecialchars($fkey, ENT_QUOTES) ?>">
                        <button class="btn-xs" name="action" value="reset_feature" title="Remove this override and follow the role default">Reset</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($pending): ?>
    <div class="panel" style="border-left:4px solid #1a4fa0;margin-bottom:1.25rem;">
      <h2 style="margin-bottom:.5rem;"><i data-lucide="user-check"></i> Pending registrations (<?= count($pending) ?>)</h2>
      <p class="text-muted" style="font-size:.85rem;margin-bottom:.75rem;">These people requested access and cannot sign in until approved.</p>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Name</th><th>Email</th><th>Requested</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($pending as $u): ?>
            <tr>
              <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
              <td><?= htmlspecialchars($u['email']) ?></td>
              <td style="white-space:nowrap;"><?= htmlspecialchars(date('d M Y', strtotime((string)$u['created_at']))) ?></td>
              <td>
                <div class="u-actions">
                  <form method="POST"><?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                    <button class="btn-xs approve" name="action" value="approve"><i data-lucide="check"></i> Approve</button>
                  </form>
                  <form method="POST" onsubmit="return confirm('Reject this account request?');"><?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                    <button class="btn-xs danger" name="action" value="reject">Reject</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="panel">
    <h2 style="margin-bottom:.5rem;">All accounts (<?= count($users) ?>)</h2>
    <?php if (empty($users)): ?>
      <p class="empty-state">No user accounts found.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Access</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $self = ((int)$u['user_id'] === $me); ?>
          <tr>
            <td><strong><?= htmlspecialchars($u['full_name']) ?></strong><?= $self ? ' <span class="u-self">(you)</span>' : '' ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td>
              <?php if ($self): ?>
                <span class="badge badge-blue"><?= htmlspecialchars($u['role']) ?></span>
              <?php else: ?>
                <form method="POST" class="u-role"><?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                  <select name="role" onchange="this.form.submit()" aria-label="Change role for <?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>">
                    <?php foreach ($ROLES as $r): ?>
                      <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="hidden" name="action" value="set_role">
                  <noscript><button class="btn-xs" name="action" value="set_role">Save</button></noscript>
                </form>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= $statusBadge($u['status']) ?>"><?= htmlspecialchars(ucfirst($u['status'])) ?></span></td>
            <td>
              <?php if ($u['role'] === 'SuperAdmin'): ?>
                <span class="u-self">Full access</span>
              <?php else: ?>
                <a class="btn-xs<?= ($accessUid === (int) $u['user_id']) ? ' approve' : '' ?>" href="<?= BASE_URL ?>/dashboard/users.php?access=<?= (int) $u['user_id'] ?>#access"><i data-lucide="sliders-horizontal"></i> Manage access</a>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($self): ?>
                <span class="u-self">Manage another admin to change your account.</span>
              <?php else: ?>
                <div class="u-actions">
                  <?php if ($u['status'] !== 'approved' || (int)$u['is_active'] !== 1): ?>
                    <form method="POST"><?= csrf_field() ?>
                      <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                      <button class="btn-xs approve" name="action" value="reactivate"><i data-lucide="check"></i> Approve / activate</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($u['status'] === 'approved' && (int)$u['is_active'] === 1): ?>
                    <form method="POST" onsubmit="return confirm('Disable <?= htmlspecialchars(addslashes($u['full_name'])) ?>? They will not be able to sign in.');"><?= csrf_field() ?>
                      <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                      <button class="btn-xs danger" name="action" value="disable">Disable</button>
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