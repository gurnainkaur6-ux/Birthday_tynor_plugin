<?php
/**
 * employee_edit.php — edit a single employee.
 *
 * Edits employee_master + the related email/dob/photo rows in one transaction,
 * plus the per-person "birthday emails enabled" flag. Uses Post/Redirect/Get so
 * a refresh never re-saves, warns about unsaved changes, confirms deactivation,
 * and records an audit entry with before/after values.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/audit.php';

require_feature($conn, 'employees');   // page belongs to the Employees feature
require_cap('employees.manage');       // and editing requires management rights

/** Load an employee (including soft-deleted) with related rows, or null. */
function load_employee(PDO $conn, string $empId): ?array
{
    $stmt = $conn->prepare("
        SELECT e.*, em.official_email, em.email_status,
               dm.date_of_birth, pm.photo_path
        FROM employee_master e
        LEFT JOIN email_master em ON em.emp_id = e.emp_id
        LEFT JOIN dob_master   dm ON dm.emp_id = e.emp_id
        LEFT JOIN photo_master pm ON pm.emp_id = e.emp_id
        WHERE e.emp_id = ? LIMIT 1
    ");
    $stmt->execute([$empId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$empId = trim($_GET['emp_id'] ?? $_POST['emp_id'] ?? '');
if ($empId === '') {
    header('Location: ' . BASE_URL . '/dashboard/employees.php'); exit;
}

$flash = $_SESSION['emp_edit_flash'] ?? null;
unset($_SESSION['emp_edit_flash']);
$errors = [];
$posted = null;

// ── Handle save ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $current = load_employee($conn, $empId);
        if (!$current) {
            $_SESSION['emp_edit_flash'] = ['type' => 'error', 'msg' => 'Employee not found.'];
            header('Location: ' . BASE_URL . '/dashboard/employees.php'); exit;
        }

        // Collect + trim input.
        $posted = [
            'full_name'        => trim($_POST['emp_name'] ?? ''),
            'first_name'       => trim($_POST['first_name'] ?? ''),
            'last_name'        => trim($_POST['last_name'] ?? ''),
            'official_email'   => trim($_POST['email'] ?? ''),
            'department_name'  => trim($_POST['department_name'] ?? ''),
            'designation_name' => trim($_POST['designation_name'] ?? ''),
            'mobile_no'        => trim($_POST['mobile_no'] ?? ''),
            'status'           => (($_POST['status'] ?? 'active') === 'inactive') ? 'inactive'
                                    : ((($_POST['status'] ?? '') === 'suspended') ? 'suspended' : 'active'),
            'birthday_enabled' => isset($_POST['birthday_enabled']) ? 1 : 0,
            'date_of_birth'    => trim($_POST['dob'] ?? ''),
            'joining_date'     => trim($_POST['joining_date'] ?? ''),
            'hod_name'         => trim($_POST['hod_name'] ?? ''),
            'mentor_name'      => trim($_POST['mentor_name'] ?? ''),
            'plant_location'   => trim($_POST['plant_location'] ?? ''),
            'plant_name'       => trim($_POST['plant_name'] ?? ''),
            'plant_id'         => trim($_POST['plant_id'] ?? ''),
        ];

        // Validation (server-side is authoritative).
        if ($posted['full_name'] === '')                                        $errors[] = 'Full name is required.';
        if ($posted['first_name'] === '')                                       $errors[] = 'First name is required.';
        if ($posted['official_email'] === '' || !filter_var($posted['official_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
        if ($posted['department_name'] === '')                                  $errors[] = 'Department is required.';
        if ($posted['designation_name'] === '')                                 $errors[] = 'Designation is required.';
        if ($posted['date_of_birth'] === '' || !strtotime($posted['date_of_birth'])) $errors[] = 'A valid date of birth is required.';
        // Consistency with the Add form + DB triggers (defence in depth).
        if (preg_match('/\d/u', $posted['first_name'] . $posted['last_name'])) $errors[] = 'First and last name cannot contain numbers.';
        if ($posted['mobile_no'] !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $posted['mobile_no'])) $errors[] = 'Please enter a valid mobile number (7–20 digits).';
        if ($posted['date_of_birth'] !== '' && ($ts = strtotime($posted['date_of_birth'])) !== false && $ts > time()) $errors[] = 'Date of birth cannot be in the future.';

        // Duplicate email check (another employee already using this address).
        if (!$errors) {
            $dup = $conn->prepare("SELECT emp_id FROM email_master WHERE official_email = ? AND emp_id <> ? LIMIT 1");
            $dup->execute([$posted['official_email'], $empId]);
            if ($dup->fetchColumn()) {
                $errors[] = 'That email address is already assigned to another employee.';
            }
        }

        // Optional photo replacement (validate real image + size).
        $newPhotoPath = null;
        if (!$errors && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $errors[] = 'Invalid photo format. Allowed: JPG, JPEG, PNG, WEBP, GIF.';
            } elseif (($_FILES['photo']['size'] ?? 0) > 5 * 1024 * 1024) {
                $errors[] = 'Photo is too large. Maximum size is 5 MB.';
            } else {
                $info = @getimagesize($_FILES['photo']['tmp_name']);
                if ($info === false || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                    $errors[] = 'The uploaded file is not a valid image.';
                } else {
                    $dir = __DIR__ . '/../uploads/';
                    if (!is_dir($dir)) @mkdir($dir, 0755, true);
                    $clean = preg_replace('/[^a-zA-Z0-9]/', '', $empId);
                    $fname = 'emp_' . $clean . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $dir . $fname)) {
                        $newPhotoPath = 'uploads/' . $fname;
                    } else {
                        $errors[] = 'Could not save the uploaded photo.';
                    }
                }
            }
        }

        if (!$errors) {
            try {
                $conn->beginTransaction();

                $conn->prepare("
                    UPDATE employee_master SET
                        full_name = ?, first_name = ?, last_name = ?, department_name = ?, designation_name = ?,
                        mobile_no = ?, status = ?, birthday_enabled = ?, joining_date = ?,
                        hod_name = ?, mentor_name = ?, plant_location = ?, plant_name = ?, plant_id = ?, updated_at = NOW()
                    WHERE emp_id = ?
                ")->execute([
                    $posted['full_name'], $posted['first_name'], ($posted['last_name'] ?: null),
                    $posted['department_name'], $posted['designation_name'], ($posted['mobile_no'] ?: null),
                    $posted['status'], $posted['birthday_enabled'], ($posted['joining_date'] ?: null),
                    ($posted['hod_name'] ?: null), ($posted['mentor_name'] ?: null),
                    ($posted['plant_location'] ?: null), ($posted['plant_name'] ?: null), ($posted['plant_id'] ?: null),
                    $empId,
                ]);

                $conn->prepare("
                    INSERT INTO email_master (email_id, emp_id, official_email) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE official_email = VALUES(official_email)
                ")->execute(['Email_' . $empId, $empId, $posted['official_email']]);

                $conn->prepare("
                    INSERT INTO dob_master (dob_id, emp_id, date_of_birth) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE date_of_birth = VALUES(date_of_birth)
                ")->execute(['Dob_' . $empId, $empId, $posted['date_of_birth']]);

                if ($newPhotoPath !== null) {
                    $conn->prepare("
                        INSERT INTO photo_master (photo_id, emp_id, photo_path, photo_key) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path), photo_key = VALUES(photo_key)
                    ")->execute(['PHOTO_' . preg_replace('/[^a-zA-Z0-9]/', '', $empId), $empId, $newPhotoPath, 'KEY_' . time()]);
                }

                $conn->commit();

                // Audit with a safe before/after diff (no sensitive fields here).
                $before = [
                    'full_name' => $current['full_name'] ?? '', 'email' => $current['official_email'] ?? '',
                    'department' => $current['department_name'] ?? '', 'status' => $current['status'] ?? '',
                    'birthday_enabled' => (int)($current['birthday_enabled'] ?? 1),
                ];
                $after = [
                    'full_name' => $posted['full_name'], 'email' => $posted['official_email'],
                    'department' => $posted['department_name'], 'status' => $posted['status'],
                    'birthday_enabled' => $posted['birthday_enabled'],
                ];
                audit_log($conn, 'UPDATE', 'employee_master', $empId, $before, $after);

                $_SESSION['emp_edit_flash'] = ['type' => 'success', 'msg' => 'Employee updated successfully.'];
                header('Location: ' . BASE_URL . '/dashboard/employee_edit.php?emp_id=' . urlencode($empId)); exit;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                $errors[] = 'Save failed: ' . $e->getMessage();
            }
        }
    }
}

// ── Load for display (merge posted values back on validation error) ──
$emp = load_employee($conn, $empId);
if (!$emp) {
    $_SESSION['emp_edit_flash'] = ['type' => 'error', 'msg' => 'Employee not found: ' . $empId];
    header('Location: ' . BASE_URL . '/dashboard/employees.php'); exit;
}
if ($posted !== null) {
    $emp = array_merge($emp, $posted);   // keep user input after a validation error
}

$val = fn($k) => htmlspecialchars((string)($emp[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$status = strtolower((string)($emp['status'] ?? 'active'));
$bdayOn = (int)($emp['birthday_enabled'] ?? 1) === 1;

$NAV_ACTIVE = 'employees';
$PAGE_TITLE = 'Edit employee';
$BREADCRUMBS = [
    ['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'],
    ['label' => 'Employees', 'url' => BASE_URL . '/dashboard/employees.php'],
    ['label' => 'Edit employee'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit employee | Birthday Communications</title>
<link rel="stylesheet" href="<?= htmlspecialchars(ASSETS_URL, ENT_QUOTES) ?>/css/styles.css">
<style>
  .form-grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:15px;}
  .form-group label{display:block;font-size:.82rem;color:#475569;margin-bottom:.25rem;font-weight:600;}
  .form-group input,.form-group select{width:100%;padding:.55rem;border:1px solid #cbd5e1;border-radius:6px;}
  .req::after{content:" *";color:#dc2626;}
  .toggle-card{background:#f0fdf4;border:1px solid #a7f3d0;border-radius:10px;padding:1rem;margin:1rem 0;display:flex;justify-content:space-between;align-items:center;gap:1rem;}
  .toggle-card.off{background:#fef2f2;border-color:#fecaca;}
</style>
</head>
<body class="dashboard-page">
<?php require __DIR__ . '/../includes/topbar.php'; ?>
<main id="main-content" class="dashboard-content">
  <div class="page-header">
    <h1>Edit employee</h1>
    <div class="page-sub"><?= $val('emp_id') ?> · <?= $val('full_name') ?></div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-error"><strong>Please fix the following:</strong>
      <ul style="margin:.4rem 0 0 1.1rem;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ((int)($emp['is_deleted'] ?? 0) === 1): ?>
    <div class="alert alert-info">This employee is currently removed (soft-deleted). Saving will keep them removed unless you also set status active — restoring is handled from the roster.</div>
  <?php endif; ?>

  <div class="panel">
    <form method="POST" enctype="multipart/form-data" id="emp-form">
      <?= csrf_field() ?>
      <input type="hidden" name="emp_id" value="<?= $val('emp_id') ?>">

      <div class="toggle-card <?= $bdayOn ? '' : 'off' ?>">
        <div>
          <strong>Birthday emails</strong><br>
          <span class="text-muted" style="font-size:.85rem;">When off, the scheduler skips this employee — no birthday email is prepared or sent.</span>
        </div>
        <label class="switch"><input type="checkbox" name="birthday_enabled" value="1" <?= $bdayOn ? 'checked' : '' ?>> Enabled</label>
      </div>

      <div class="form-grid-3">
        <div class="form-group"><label>EMP_ID</label><input type="text" value="<?= $val('emp_id') ?>" disabled></div>
        <div class="form-group"><label class="req">Full name</label><input type="text" name="emp_name" required value="<?= $val('full_name') ?>"></div>
        <div class="form-group"><label class="req">First name</label><input type="text" name="first_name" required value="<?= $val('first_name') ?>"></div>
        <div class="form-group"><label>Last name</label><input type="text" name="last_name" value="<?= $val('last_name') ?>"></div>
        <div class="form-group"><label class="req">Email</label><input type="email" name="email" required value="<?= $val('official_email') ?>"></div>
        <div class="form-group"><label class="req">Department</label><input type="text" name="department_name" required value="<?= $val('department_name') ?>"></div>
        <div class="form-group"><label class="req">Designation</label><input type="text" name="designation_name" required value="<?= $val('designation_name') ?>"></div>
        <div class="form-group"><label>Mobile no.</label><input type="text" name="mobile_no" value="<?= $val('mobile_no') ?>"></div>
        <div class="form-group">
          <label class="req">Status</label>
          <select name="status">
            <option value="active"    <?= $status === 'active' ? 'selected' : '' ?>>active</option>
            <option value="inactive"  <?= $status === 'inactive' ? 'selected' : '' ?>>inactive</option>
            <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>suspended</option>
          </select>
        </div>
        <div class="form-group"><label class="req">Date of birth</label><input type="date" name="dob" required max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars(substr((string)($emp['date_of_birth'] ?? ''), 0, 10), ENT_QUOTES) ?>"></div>
        <div class="form-group"><label>Date of joining</label><input type="date" name="joining_date" value="<?= htmlspecialchars(substr((string)($emp['joining_date'] ?? ''), 0, 10), ENT_QUOTES) ?>"></div>
        <div class="form-group"><label>HOD name</label><input type="text" name="hod_name" value="<?= $val('hod_name') ?>"></div>
        <div class="form-group"><label>Mentor name</label><input type="text" name="mentor_name" value="<?= $val('mentor_name') ?>"></div>
        <div class="form-group"><label>Plant location</label><input type="text" name="plant_location" value="<?= $val('plant_location') ?>"></div>
        <div class="form-group"><label>Plant name</label><input type="text" name="plant_name" value="<?= $val('plant_name') ?>"></div>
        <div class="form-group"><label>Plant ID</label><input type="text" name="plant_id" value="<?= $val('plant_id') ?>"></div>
        <div class="form-group">
          <label>Replace photo</label>
          <input type="file" name="photo" accept="image/*">
          <div style="margin-top:.4rem;display:flex;align-items:center;gap:.5rem;">
            <?= employee_avatar_html($emp['full_name'] ?? '', $emp['photo_path'] ?? '', 44) ?>
            <span class="text-muted" style="font-size:.78rem;">
              <?= employee_photo_url($emp['photo_path'] ?? '') !== '' ? 'Current photo' : 'No individual photo — a default avatar is shown until one is uploaded.' ?>
            </span>
          </div>
        </div>
      </div>

      <div style="display:flex;gap:.75rem;margin-top:1.25rem;">
        <button type="submit" class="btn-primary" id="save-btn">Save changes</button>
        <a href="<?= BASE_URL ?>/dashboard/employees.php" class="btn-secondary" id="cancel-btn">Cancel</a>
      </div>
    </form>
  </div>
</main>
<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
<script>
(function () {
  var form = document.getElementById('emp-form');
  var dirty = false;
  form.addEventListener('input', function () { dirty = true; });

  // Confirm deactivation explicitly.
  var startStatus = <?= json_encode($status) ?>;
  form.addEventListener('submit', function (e) {
    var sel = form.querySelector('select[name="status"]').value;
    if (startStatus === 'active' && sel !== 'active') {
      if (!confirm('Set this employee to "' + sel + '"? They will stop receiving birthday emails.')) {
        e.preventDefault(); return false;
      }
    }
    dirty = false; // allow navigation after a real submit
    var b = document.getElementById('save-btn'); b.disabled = true; b.textContent = 'Saving…';
  });

  // Unsaved-changes warning (only when real changes exist).
  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });
  // Cancel should not trigger the warning.
  document.getElementById('cancel-btn').addEventListener('click', function () { dirty = false; });
})();
</script>
</body>
</html>