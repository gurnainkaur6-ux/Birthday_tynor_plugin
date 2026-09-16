<?php
/**
 * access_requests.php — Access request management interface
 * 
 * Features:
 * - Users can request additional access to features/data
 * - Admins review and approve/reject requests
 * - Email notifications sent to admins and users
 * - Audit trail of all access decisions
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/access_control.php';
require_once __DIR__ . '/../includes/access_notifications.php';

// Feature access: any authenticated user can view and create requests
require_feature($conn, 'dashboard');

$currentUserId = (int)($_SESSION['admin_id'] ?? 0);
$currentUserName = $_SESSION['admin_name'] ?? 'User';
$currentUserEmail = $_SESSION['admin_email'] ?? '';
$currentUserRole = $_SESSION['admin_role'] ?? 'Viewer';

// Only admins can approve/reject
$canManageRequests = user_can('roles.manage');

$flash = '';
$flashType = 'success';

// ══════════════════════════════════════════════════════════════════
// HANDLE FORM SUBMISSIONS
// ══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        // ──────────────────────────────────────────────────────────
        // CREATE NEW ACCESS REQUEST
        // ──────────────────────────────────────────────────────────
        if ($action === 'create') {
            $feature = trim($_POST['requested_feature'] ?? '');
            $scope = trim($_POST['requested_scope'] ?? '');
            $justification = trim($_POST['justification'] ?? '');
            
            // Validate inputs
            if (empty($feature)) {
                throw new Exception('Feature is required');
            }
            
            if (strlen($justification) < 10) {
                throw new Exception('Please provide a detailed justification (minimum 10 characters)');
            }
            
            // Create request
            $stmt = $conn->prepare("
                INSERT INTO access_requests 
                    (user_id, user_name, user_email, requested_feature, requested_scope, justification, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $currentUserId,
                $currentUserName,
                $currentUserEmail,
                $feature,
                $scope,
                $justification
            ]);
            
            $requestId = $conn->lastInsertId();
            
            // Audit log
            audit_log($conn, 'INSERT', 'access_requests', (string)$requestId, null, [
                'feature' => $feature,
                'scope' => $scope,
                'user_id' => $currentUserId
            ]);
            
            $conn->commit();
            
            // Send email notifications to admins
            $notifyResult = notify_admins_access_request($conn, $requestId);
            
            $flash = 'Access request submitted successfully. Administrators have been notified and will review your request.';
            $flashType = 'success';
            
        }
        // ──────────────────────────────────────────────────────────
        // APPROVE REQUEST
        // ──────────────────────────────────────────────────────────
        elseif ($action === 'approve' && $canManageRequests) {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            $accessType = trim($_POST['access_type'] ?? 'department');
            $accessValue = trim($_POST['access_value'] ?? '');
            $expiresAt = trim($_POST['expires_at'] ?? '');
            
            if (!$requestId) {
                throw new Exception('Invalid request ID');
            }
            
            // HIGH FIX #12: Validate access_type against whitelist
            $validAccessTypes = ['all', 'department', 'plant', 'employee', 'none'];
            if (!in_array($accessType, $validAccessTypes, true)) {
                throw new Exception('Invalid access type: ' . htmlspecialchars($accessType));
            }
            
            // HIGH FIX #12: Validate access_value using access_control validation
            require_once __DIR__ . '/../includes/access_control.php';
            if (!validate_access_value($accessType, $accessValue)) {
                throw new Exception('Invalid access value for type: ' . htmlspecialchars($accessType));
            }
            
            // Get request details
            $stmt = $conn->prepare("SELECT * FROM access_requests WHERE request_id = ?");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request || $request['status'] !== 'pending') {
                throw new Exception('Request not found or already processed');
            }
            
            // Update request status
            $stmt = $conn->prepare("
                UPDATE access_requests 
                SET status = 'approved',
                    reviewed_by = ?,
                    reviewed_by_name = ?,
                    reviewed_at = NOW(),
                    notes = ?
                WHERE request_id = ?
            ");
            $stmt->execute([$currentUserId, $currentUserName, $notes, $requestId]);
            
            // Grant access level
            $stmt = $conn->prepare("
                INSERT INTO user_access_levels 
                    (user_id, access_type, access_value, granted_by, expires_at, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    access_value = VALUES(access_value),
                    granted_by = VALUES(granted_by),
                    expires_at = VALUES(expires_at),
                    is_active = 1,
                    granted_at = NOW()
            ");
            $stmt->execute([
                $request['user_id'],
                $accessType,
                $accessValue ?: null,
                $currentUserId,
                $expiresAt ?: null
            ]);
            
            // Audit log
            audit_log($conn, 'APPROVE', 'access_requests', (string)$requestId, 
                ['status' => 'pending'],
                ['status' => 'approved', 'reviewer' => $currentUserName, 'access_granted' => $accessType]
            );
            
            $conn->commit();
            
            // Send email notification to user
            notify_user_access_decision($conn, $requestId, 'approved');
            
            $flash = 'Access request approved and user has been notified.';
            $flashType = 'success';
        }
        // ──────────────────────────────────────────────────────────
        // REJECT REQUEST
        // ──────────────────────────────────────────────────────────
        elseif ($action === 'reject' && $canManageRequests) {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            if (!$requestId) {
                throw new Exception('Invalid request ID');
            }
            
            $stmt = $conn->prepare("SELECT * FROM access_requests WHERE request_id = ?");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request || $request['status'] !== 'pending') {
                throw new Exception('Request not found or already processed');
            }
            
            // Update request status
            $stmt = $conn->prepare("
                UPDATE access_requests 
                SET status = 'rejected',
                    reviewed_by = ?,
                    reviewed_by_name = ?,
                    reviewed_at = NOW(),
                    notes = ?
                WHERE request_id = ?
            ");
            $stmt->execute([$currentUserId, $currentUserName, $notes, $requestId]);
            
            // Audit log
            audit_log($conn, 'REJECT', 'access_requests', (string)$requestId,
                ['status' => 'pending'],
                ['status' => 'rejected', 'reviewer' => $currentUserName]
            );
            
            $conn->commit();
            
            // Send email notification to user
            notify_user_access_decision($conn, $requestId, 'rejected');
            
            $flash = 'Access request rejected and user has been notified.';
            $flashType = 'success';
        }
        // ──────────────────────────────────────────────────────────
        // REVOKE ACCESS
        // ──────────────────────────────────────────────────────────
        elseif ($action === 'revoke' && $canManageRequests) {
            $userId = (int)($_POST['user_id'] ?? 0);
            $accessType = trim($_POST['access_type'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            
            if (!$userId || !$accessType) {
                throw new Exception('Invalid parameters');
            }
            
            // Revoke access
            $stmt = $conn->prepare("
                UPDATE user_access_levels 
                SET is_active = 0
                WHERE user_id = ? AND access_type = ?
            ");
            $stmt->execute([$userId, $accessType]);
            
            // Create revocation record in access_requests
            $stmt = $conn->prepare("
                INSERT INTO access_requests 
                    (user_id, user_name, user_email, requested_feature, status, reviewed_by, reviewed_by_name, reviewed_at, notes)
                SELECT ?, u.full_name, u.email, ?, 'revoked', ?, ?, NOW(), ?
                    FROM users u WHERE u.user_id = ?
            ");
            $stmt->execute([$userId, "Access revoked: {$accessType}", $currentUserId, $currentUserName, $reason, $userId]);
            
            $requestId = $conn->lastInsertId();
            
            // Audit log
            audit_log($conn, 'UPDATE', 'user_access_levels', "{$userId}:{$accessType}", 
                ['is_active' => 1],
                ['is_active' => 0, 'revoked_by' => $currentUserName]
            );
            
            // HIGH FIX #17: Revoke all sessions for this user so permission change takes effect
            require_once __DIR__ . '/../includes/rbac.php';
            revoke_user_sessions($conn, $userId, "Access type '{$accessType}' revoked: {$reason}");
            
            $conn->commit();
            
            // Send email notification to user
            notify_user_access_decision($conn, $requestId, 'revoked');
            
            $flash = 'Access revoked and user has been notified.';
            $flashType = 'success';
        }
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $flash = 'Error: ' . $e->getMessage();
        $flashType = 'error';
    }
}

// ══════════════════════════════════════════════════════════════════
// FETCH DATA FOR DISPLAY
// ══════════════════════════════════════════════════════════════════

// Get user's own requests
$stmt = $conn->prepare("
    SELECT * FROM access_requests 
    WHERE user_id = ?
    ORDER BY requested_at DESC
    LIMIT 20
");
$stmt->execute([$currentUserId]);
$myRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending requests (for admins)
$pendingRequests = [];
if ($canManageRequests) {
    $pendingRequests = $conn->query("
        SELECT * FROM access_requests 
        WHERE status = 'pending'
        ORDER BY requested_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Get current access level
$myAccessLevel = get_user_access_level($conn, $currentUserId);
$myAccessDescription = format_access_level($myAccessLevel);

// Get all users' access levels (for admins)
$allAccessLevels = [];
if ($canManageRequests) {
    $allAccessLevels = $conn->query("
        SELECT 
            u.user_id,
            u.full_name,
            u.email,
            u.role,
            ual.access_type,
            ual.access_value,
            ual.is_active,
            ual.expires_at,
            ual.granted_at
        FROM users u
        LEFT JOIN user_access_levels ual ON ual.user_id = u.user_id AND ual.is_active = 1
        WHERE u.is_active = 1
        ORDER BY u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Available departments and plants for dropdowns
$departments = $conn->query("SELECT DISTINCT department_name FROM department_master WHERE status = 'Active' ORDER BY department_name")->fetchAll(PDO::FETCH_COLUMN);
$plants = $conn->query("SELECT plant_id, plant_name, plant_location FROM plant_master WHERE is_active = 1 ORDER BY plant_name")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Access Requests | BDayNotify</title>
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/styles.css">
<style>
.access-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:1.5rem; margin-bottom:1rem; }
.access-badge { display:inline-block; padding:0.25rem 0.75rem; border-radius:6px; font-size:0.85rem; font-weight:600; }
.access-badge.pending { background:#fef3c7; color:#92400e; }
.access-badge.approved { background:#d1fae5; color:#065f46; }
.access-badge.rejected { background:#fee2e2; color:#991b1b; }
.access-badge.revoked { background:#fef3c7; color:#92400e; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
@media (max-width:768px) { .form-grid { grid-template-columns:1fr; } }
</style>
</head>
<body class="dashboard-page">
<?php
$NAV_ACTIVE = 'access';
$BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'Access Requests']];
require __DIR__ . '/../includes/topbar.php';
?>

<main id="main-content" class="dashboard-content">
  <div class="page-header">
    <h1>🔐 Access Requests</h1>
    <span class="text-muted">Request and manage data access permissions</span>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flashType === 'error' ? 'error' : 'success' ?>">
      <?= htmlspecialchars($flash) ?>
    </div>
  <?php endif; ?>

  <!-- Current Access Level -->
  <div class="panel">
    <h2>Your Current Access Level</h2>
    <p style="font-size:1.1rem;font-weight:600;color:#059669;">
      <?= htmlspecialchars($myAccessDescription) ?>
    </p>
    <p class="text-muted">This determines which employee records you can view and manage.</p>
  </div>

  <!-- Request New Access -->
  <div class="panel">
    <h2>Request Additional Access</h2>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="requested_feature">Feature/Scope *</label>
          <select name="requested_feature" id="requested_feature" class="form-control" required>
            <option value="">Select feature...</option>
            <option value="employees_all">All Employee Records</option>
            <option value="employees_department">Specific Department</option>
            <option value="employees_plant">Specific Plant</option>
            <option value="send_notifications">Send Birthday Notifications</option>
            <option value="view_analytics">View Analytics</option>
            <option value="manage_templates">Manage Email Templates</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="requested_scope">Scope (optional)</label>
          <input type="text" name="requested_scope" id="requested_scope" class="form-control" 
                 placeholder="e.g., HR Department, Plant Mumbai">
        </div>
      </div>
      
      <div class="form-group">
        <label for="justification">Business Justification *</label>
        <textarea name="justification" id="justification" class="form-control" rows="4" required
                  placeholder="Please explain why you need this access and how it relates to your role..."></textarea>
        <small class="text-muted">Minimum 10 characters</small>
      </div>
      
      <button type="submit" class="btn-primary">
        <i data-lucide="send"></i> Submit Request
      </button>
    </form>
  </div>

  <!-- Pending Requests (Admins Only) -->
  <?php if ($canManageRequests && !empty($pendingRequests)): ?>
  <div class="panel">
    <h2>⏳ Pending Requests Requiring Review</h2>
    <?php foreach ($pendingRequests as $req): ?>
      <div class="access-card">
        <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:1rem;">
          <div>
            <strong style="font-size:1.1rem;"><?= htmlspecialchars($req['user_name']) ?></strong>
            <br><span class="text-muted"><?= htmlspecialchars($req['user_email']) ?></span>
          </div>
          <span class="access-badge pending">Pending</span>
        </div>
        
        <div style="margin:0.5rem 0;">
          <strong>Feature:</strong> <?= htmlspecialchars($req['requested_feature']) ?>
          <?php if ($req['requested_scope']): ?>
            <br><strong>Scope:</strong> <?= htmlspecialchars($req['requested_scope']) ?>
          <?php endif; ?>
        </div>
        
        <div style="background:#f8fafc;padding:0.75rem;border-left:3px solid #1a4fa0;margin:1rem 0;">
          <strong>Justification:</strong><br>
          <?= nl2br(htmlspecialchars($req['justification'])) ?>
        </div>
        
        <small class="text-muted">Requested: <?= htmlspecialchars($req['requested_at']) ?></small>
        
        <!-- Approval Form -->
        <details style="margin-top:1rem;">
          <summary style="cursor:pointer;color:#1a4fa0;font-weight:600;">Review & Decide</summary>
          <div style="margin-top:1rem;padding:1rem;background:#f8fafc;border-radius:6px;">
            <form method="POST" style="margin-bottom:1rem;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
              
              <div class="form-group">
                <label>Grant Access Type</label>
                <select name="access_type" class="form-control" required>
                  <option value="department">Department Access</option>
                  <option value="plant">Plant Access</option>
                  <option value="all">All Records</option>
                </select>
              </div>
              
              <div class="form-group">
                <label>Access Value</label>
                <input type="text" name="access_value" class="form-control" 
                       placeholder="e.g., department name or plant_id">
              </div>
              
              <div class="form-group">
                <label>Expires At (optional)</label>
                <input type="date" name="expires_at" class="form-control">
              </div>
              
              <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="2" 
                          placeholder="Optional notes for the user"></textarea>
              </div>
              
              <button type="submit" class="btn-primary">✓ Approve</button>
            </form>
            
            <form method="POST" onsubmit="return confirm('Reject this access request?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
              
              <div class="form-group">
                <label>Rejection Reason</label>
                <textarea name="notes" class="form-control" rows="2" required
                          placeholder="Explain why this request is being denied"></textarea>
              </div>
              
              <button type="submit" class="btn-danger">✗ Reject</button>
            </form>
          </div>
        </details>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- My Requests History -->
  <div class="panel">
    <h2>Your Request History</h2>
    <?php if (empty($myRequests)): ?>
      <p class="empty-state">No requests yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Feature</th>
              <th>Scope</th>
              <th>Status</th>
              <th>Requested</th>
              <th>Reviewed</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($myRequests as $req): ?>
              <tr>
                <td><?= htmlspecialchars($req['requested_feature']) ?></td>
                <td><?= htmlspecialchars($req['requested_scope'] ?: '—') ?></td>
                <td><span class="access-badge <?= $req['status'] ?>"><?= htmlspecialchars(ucfirst($req['status'])) ?></span></td>
                <td><?= htmlspecialchars($req['requested_at']) ?></td>
                <td>
                  <?php if ($req['reviewed_at']): ?>
                    <?= htmlspecialchars($req['reviewed_at']) ?><br>
                    <small class="text-muted">by <?= htmlspecialchars($req['reviewed_by_name'] ?? 'Unknown') ?></small>
                    <?php if ($req['notes']): ?>
                      <br><small style="font-style:italic;"><?= htmlspecialchars($req['notes']) ?></small>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted">Pending review</span>
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
