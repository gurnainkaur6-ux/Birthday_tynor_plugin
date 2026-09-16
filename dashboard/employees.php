<?php
/**
 * employees.php — Employee Roster Management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/rbac.php';

require_feature($conn, 'employees');

$msg = $msgType = '';
$reopenAdd = false;

// ── AJAX Endpoint Handler for Real-Time Employee ID Validation ──
if (isset($_GET['action']) && $_GET['action'] === 'check_emp_id') {
    header('Content-Type: application/json');
    $checkId = trim($_GET['emp_id'] ?? '');
    
    if ($checkId === '') {
        echo json_encode(['exists' => false, 'error' => 'Employee ID cannot be empty.']);
        exit;
    }
    
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $checkId)) {
        echo json_encode(['exists' => false, 'error' => 'Invalid format. Only alphanumeric characters, dashes, and underscores are allowed.']);
        exit;
    }
    
    try {
        $stmt = $conn->prepare("SELECT 1 FROM employee_master WHERE emp_id = ? LIMIT 1");
        $stmt->execute([$checkId]);
        $exists = (bool)$stmt->fetchColumn();
        
        echo json_encode(['exists' => $exists, 'msg' => $exists ? 'This Employee ID already exists in the system.' : 'ID is available.']);
    } catch (Exception $e) {
        echo json_encode(['exists' => false, 'error' => 'Database validation error.']);
    }
    exit;
}

// ── Strict Employee ID Validation & Generation Helper ──
function get_next_employee_id($conn) {
    try {
        $stmt = $conn->query("SELECT emp_id FROM employee_master ORDER BY id DESC LIMIT 1");
        $lastId = $stmt->fetchColumn();

        if (!$lastId) {
            return 'EMP_2026_001';
        }

        if (preg_match('/(\d+)$/', $lastId, $matches)) {
            $nextNum = (int)$matches[1] + 1;
            return 'EMP_2026_' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        }

        return 'EMP_' . time();
    } catch (Exception $e) {
        return 'EMP_' . date('Ymd') . '_' . rand(100, 999);
    }
}

// Check comprehensive existence across active and deleted records
function is_emp_id_globally_exists($conn, $empId) {
    if (empty($empId)) return false;
    $stmt = $conn->prepare("SELECT 1 FROM employee_master WHERE emp_id = ? LIMIT 1");
    $stmt->execute([trim($empId)]);
    return (bool)$stmt->fetchColumn();
}

$nextAutoId = get_next_employee_id($conn);

// Ensure the auto-generated fallback ID is strictly unique
while (is_emp_id_globally_exists($conn, $nextAutoId)) {
    if (preg_match('/(\d+)$/', $nextAutoId, $matches)) {
        $nextNum = (int)$matches[1] + 1;
        $nextAutoId = preg_replace('/\d+$/', str_pad($nextNum, strlen($matches[1]), '0', STR_PAD_LEFT), $nextAutoId);
    } else {
        $nextAutoId = 'EMP_' . time() . '_' . rand(10, 99);
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $msg = 'Invalid security token. Please refresh and try again.';
        $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        require_cap('employees.manage');

        if ($action === 'add') {
            try {
                $conn->beginTransaction();

                $empId         = trim($_POST['emp_id'] ?? '');
                if ($empId === '') {
                    throw new Exception('Employee ID is required.');
                }

                // ── Enhanced Strict Validation Block for Step 1 / Form Submission ──
                if (is_emp_id_globally_exists($conn, $empId)) {
                    throw new Exception("Employee ID '$empId' already exists in the database. Please enter a unique ID.");
                }

                // Additional strict regex structure validation for employee ID format security (alphanumeric, dash, underscore)
                if (!preg_match('/^[A-Za-z0-9_\-]+$/', $empId)) {
                    throw new Exception('Invalid Employee ID format. Only alphanumeric characters, dashes, and underscores are allowed.');
                }

                $empName       = trim($_POST['emp_name']        ?? '');
                $firstName     = trim($_POST['first_name']      ?? '');
                $lastName      = trim($_POST['last_name']       ?? '');
                $email         = trim($_POST['email']           ?? '');
                $deptName      = trim($_POST['department_name'] ?? '');
                $desigName     = trim($_POST['designation_name']?? '');
                $mobileNo      = trim($_POST['mobile_no']       ?? '');
                $status        = trim($_POST['status']          ?? 'active');
                $dob           = trim($_POST['dob']             ?? '');
                
                $joiningDate   = trim($_POST['joining_date']    ?? '');
                $joiningDate   = !empty($joiningDate) ? $joiningDate : null;
                
                $hodName       = trim($_POST['hod_name']        ?? '');
                $hodName       = !empty($hodName) ? $hodName : null;
                
                $mentorName    = trim($_POST['mentor_name']     ?? '');
                $mentorName    = !empty($mentorName) ? $mentorName : null;

                $plantLocation = trim($_POST['plant_location']  ?? '');
                $plantName     = trim($_POST['plant_name']      ?? '');
                $plantId       = trim($_POST['plant_id']        ?? '');
                $plantId       = !empty($plantId) ? $plantId : null;

                if (mb_strlen($empId) > 50) throw new Exception('Employee ID is too long (max 50 characters).');
                if ($empName === '')       throw new Exception('Full name is required.');
                if ($firstName === '')     throw new Exception('First name is required.');
                if ($lastName === '')      throw new Exception('Last name is required.');
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('A valid email address is required.');
                if ($deptName === '')      throw new Exception('Department is required.');
                if ($desigName === '')     throw new Exception('Designation is required.');
                if ($mobileNo === '')      throw new Exception('Mobile number is required.');
                if ($dob === '')           throw new Exception('Date of birth is required.');
                if ($plantLocation === '') throw new Exception('Plant location is required.');
                if ($plantName === '')     throw new Exception('Plant name is required.');

                if (preg_match('/\d/u', $firstName . $lastName)) throw new Exception('First and last name cannot contain numbers.');
                if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $mobileNo)) throw new Exception('Please enter a valid mobile number (7–20 digits).');
                $dobTs = strtotime($dob);
                if ($dobTs === false)  throw new Exception('Please enter a valid date of birth.');
                if ($dobTs > time())   throw new Exception('Date of birth cannot be in the future.');

                $dupEmail = $conn->prepare("SELECT 1 FROM email_master WHERE official_email = ? LIMIT 1");
                $dupEmail->execute([$email]);
                if ($dupEmail->fetchColumn()) throw new Exception('That email address is already assigned to another employee.');

                $photoPath = null;
                $photoKey  = null;

                if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $fileTmpPath   = $_FILES['photo']['tmp_name'];
                    $fileName      = $_FILES['photo']['name'];
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                    if (!in_array($fileExtension, $allowedExtensions)) {
                        throw new Exception('Invalid photo format. Only JPG, JPEG, PNG, WEBP, and GIF are allowed.');
                    }

                    if (($_FILES['photo']['size'] ?? 0) > 5 * 1024 * 1024) {
                        throw new Exception('Photo is too large. Maximum size is 5 MB.');
                    }
                    $imgInfo = @getimagesize($fileTmpPath);
                    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                    if ($imgInfo === false || !in_array($imgInfo['mime'] ?? '', $allowedMime, true)) {
                        throw new Exception('The uploaded file is not a valid image.');
                    }

                    $uploadDir = __DIR__ . '/../uploads/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $timeHash    = time();
                    $cleanEmpId  = preg_replace('/[^a-zA-Z0-9]/', '', $empId);
                    $newFileName = 'emp_' . $cleanEmpId . '_' . $timeHash . '.' . $fileExtension;
                    $destPath    = $uploadDir . $newFileName;

                    if (move_uploaded_file($fileTmpPath, $destPath)) {
                        $photoPath = 'uploads/' . $newFileName;
                        $photoKey  = 'KEY_' . strtoupper($cleanEmpId) . '_' . $timeHash;
                    } else {
                        throw new Exception('There was an error moving the uploaded file.');
                    }
                }

                $stmt = $conn->prepare("
                    INSERT INTO employee_master
                        (emp_id, full_name, first_name, last_name, department_name, designation_name,
                         mobile_no, status, joining_date, hod_name, mentor_name, plant_location, plant_name, plant_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $empId, $empName, $firstName, $lastName, $deptName, $desigName,
                    $mobileNo, $status, $joiningDate, $hodName, $mentorName, $plantLocation, $plantName, $plantId
                ]);

                if ($photoPath) {
                    $photoId = 'PHOTO_' . preg_replace('/[^a-zA-Z0-9]/', '', $empId) . '_' . time();
                    $conn->prepare("
                        INSERT INTO photo_master (photo_id, emp_id, photo_path, photo_key)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path), photo_key = VALUES(photo_key)
                    ")->execute([$photoId, $empId, $photoPath, $photoKey]);
                }

                $conn->prepare("
                    INSERT INTO dob_master (dob_id, emp_id, date_of_birth)
                    VALUES (?, ?, ?)
                ")->execute(['Dob_' . $empId, $empId, $dob]);

                $conn->prepare("
                    INSERT INTO email_master (email_id, emp_id, official_email)
                    VALUES (?, ?, ?)
                ")->execute(['Email_' . $empId, $empId, $email]);

                $conn->commit();
                audit_log($conn, 'INSERT', 'employee_master', $empId, null, [
                    'full_name' => $empName, 'email' => $email,
                    'department' => $deptName, 'designation' => $desigName,
                ]);
                $msg = 'Employee added successfully' . ($photoPath ? ' with photo.' : '.');
                $msgType = 'success';

                $nextAutoId = get_next_employee_id($conn);

            } catch (PDOException $ex) {
                if ($conn->inTransaction()) $conn->rollBack();
                if ($ex->getCode() === '23000') {
                    $msg = 'An employee with this Employee ID or email already exists in the system.';
                } else {
                    error_log('Add employee DB error: ' . $ex->getMessage());
                    $msg = 'Could not add the employee due to a database error. Please try again.';
                }
                $msgType = 'error';
                $reopenAdd = true;
            } catch (Exception $ex) {
                if ($conn->inTransaction()) $conn->rollBack();
                $msg = $ex->getMessage();
                $msgType = 'error';
                $reopenAdd = true;
            }

        } elseif ($action === 'delete' && !empty($_POST['emp_id'])) {
            $empId = trim($_POST['emp_id']);
            $actor = (int)($_SESSION['admin_id'] ?? 0);
            try {
                $stmt = $conn->prepare("CALL sp_soft_delete_employee(?, ?)");
                $stmt->execute([$empId, $actor]);
                $stmt->closeCursor();
                $msg = 'Employee removed. They are hidden from the roster and can be restored from "View removed".';
                $msgType = 'success';
            } catch (PDOException $ex) {
                error_log('soft delete failed: ' . $ex->getMessage());
                $msg = 'Remove failed due to a database error.';
                $msgType = 'error';
            }

        } elseif ($action === 'restore' && !empty($_POST['emp_id'])) {
            $empId = trim($_POST['emp_id']);
            $actor = (int)($_SESSION['admin_id'] ?? 0);
            try {
                $stmt = $conn->prepare("CALL sp_restore_employee(?, ?)");
                $stmt->execute([$empId, $actor]);
                $stmt->closeCursor();
                $msg = 'Employee restored to the active roster.';
                $msgType = 'success';
            } catch (PDOException $ex) {
                error_log('restore failed: ' . $ex->getMessage());
                $msg = 'Restore failed due to a database error.';
                $msgType = 'error';
            }
        }
    }
}

$showRemoved    = ($_GET['deleted'] ?? '') === '1';
$birthdayFilter = $showRemoved ? '' : ($_GET['birthday'] ?? '');
$q              = trim((string)($_GET['q'] ?? ''));
$filterLabel    = '';
$params         = [];
$selectCols     = '*';
$orderBy        = 'ORDER BY full_name ASC';
$fallbackFrom   = null;

if ($showRemoved) {
    $filterLabel = 'Removed (soft-deleted) employees';
    $selectCols  = 'e.*, em.official_email, dm.date_of_birth, pm.photo_path';
    $from        = "FROM employee_master e
        LEFT JOIN email_master em ON em.emp_id = e.emp_id
        LEFT JOIN dob_master   dm ON dm.emp_id = e.emp_id
        LEFT JOIN photo_master pm ON pm.emp_id = e.emp_id
        WHERE e.is_deleted = 1";
    $orderBy     = 'ORDER BY e.full_name ASC';
} elseif ($birthdayFilter === 'today') {
    $filterLabel = 'Birthdays today';
    $from        = "FROM v_todays_birthdays";
} elseif ($birthdayFilter === 'upcoming') {
    $filterLabel = 'Upcoming birthdays (next 7 days)';
    $from        = "FROM v_upcoming_birthdays WHERE days_away <= 7";
    $orderBy     = 'ORDER BY days_away ASC, full_name ASC';
} else {
    $birthdayFilter = '';
    $from         = "FROM employee_view";
    $fallbackFrom = "FROM v_employee_master_complete";
    if ($q !== '') {
        $cond = " WHERE (full_name LIKE ? OR emp_id LIKE ? OR official_email LIKE ?
                        OR department_name LIKE ? OR designation_name LIKE ? OR plant_name LIKE ?)";
        $from         .= $cond;
        $fallbackFrom .= $cond;
        $params = array_fill(0, 6, '%' . $q . '%');
    }
}

$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$total   = 0;
$activeFrom = $from;
try {
    $cs = $conn->prepare("SELECT COUNT(*) $from"); $cs->execute($params); $total = (int)$cs->fetchColumn();
} catch (PDOException $ex) {
    if ($fallbackFrom) {
        try { $cs = $conn->prepare("SELECT COUNT(*) $fallbackFrom"); $cs->execute($params); $total = (int)$cs->fetchColumn(); $activeFrom = $fallbackFrom; }
        catch (PDOException $e2) { $total = 0; $msg = 'Error loading employees.'; $msgType = 'error'; }
    } else { $msg = 'Error loading employees.'; $msgType = 'error'; }
}
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$employees = [];
try {
    $ds = $conn->prepare("SELECT $selectCols $activeFrom $orderBy LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
    $ds->execute($params);
    $employees = $ds->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) {
    $employees = [];
    $msg = 'Error loading employees.'; $msgType = 'error';
}

$importSummary = $_SESSION['import_summary'] ?? null;
unset($_SESSION['import_summary']);

$old = fn(string $k) => htmlspecialchars((string)($_POST[$k] ?? ''), ENT_QUOTES);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Employees | BDayNotify</title>
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/styles.css">
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/theme.css">
<style>
  .column-toggle-panel { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 15px; padding: 12px; background: #fafafa; border: 1px solid #e0e0e0; border-radius: 6px; font-size: 0.85rem; }
  .column-toggle-panel label { display: flex; align-items: center; gap: 4px; cursor: pointer; user-select: none; }
  .table-wrap { overflow-x: auto; max-width: 100%; }
  .emp-photo-thumb { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
  .form-grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; }
  .btn-sm { padding: 5px 10px; font-size: 0.8rem; border-radius: 4px; border: none; cursor: pointer; }
  .btn-primary.btn-sm { background: #2772CD; color: white; }
  .btn-primary.btn-sm:hover { background: #215FB2; }
  .btn-danger.btn-sm { background: #FFFFFF; color: #354153; border: 1px solid #B3C8E3; }
  .btn-danger.btn-sm:hover { background: rgba(53,65,83,.06); }
  
  .filter-bar { display: flex; flex-wrap: wrap; gap: 10px; background: #f4f6f9; padding: 12px; border-radius: 6px; margin-bottom: 15px; align-items: center; border: 1px solid #e2e8f0; }
  .filter-bar input, .filter-bar select { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.88rem; outline: none; }
  .filter-bar input:focus, .filter-bar select:focus { border-color: #2772CD; }
  .filter-bar .search-input { flex: 1; min-width: 200px; }
  .btn-reset { padding: 8px 14px; background: #FFFFFF; color: #215FB2; border: 1px solid #98B3D8; border-radius: 6px; cursor: pointer; font-size: 0.85rem; }
  .btn-reset:hover { background: rgba(39,114,205,.08); }

  .filter-bar { box-shadow: var(--shadow-2, var(--sh-m)); border: 1px solid var(--gray-3, var(--g2)); border-radius: var(--radius-3, var(--rad)); transition: box-shadow var(--ease-3, ease) .2s; }
  .filter-bar:focus-within { box-shadow: var(--shadow-3, var(--sh-l)); }
  .filter-bar .search-wrap { position: relative; display: flex; align-items: center; flex: 1; min-width: 220px; }
  .filter-bar .search-wrap > svg { position: absolute; left: .7rem; color: var(--g4); pointer-events: none; }
  .filter-bar .search-wrap .search-input { width: 100%; padding-left: 2.2rem; }
  .emp-search-ind { display: inline-flex; align-items: center; gap: .4rem; color: var(--b7); font-size: .82rem; font-weight: 600; }
  .htmx-indicator { opacity: 0; transition: opacity var(--ease-2, ease) .18s; }
  .htmx-request.htmx-indicator, .htmx-request .htmx-indicator { opacity: 1; }
  .emp-search-ind .spin { width: 15px; height: 15px; border: 2px solid var(--b1); border-top-color: var(--b7); border-radius: 50%; animation: empspin .6s linear infinite; }
  @keyframes empspin { to { transform: rotate(360deg); } }
  #emp-results { transition: opacity var(--ease-2, ease) .15s; }
  #emp-results.htmx-request { opacity: .55; }
  @media (prefers-reduced-motion: reduce) { .emp-search-ind .spin { animation: none; } #emp-results, .filter-bar { transition: none; } }
</style>
</head>
<body
  data-base-url="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>"
  <?php if ($msg): ?>data-toast-msg="<?= htmlspecialchars($msg, ENT_QUOTES) ?>" data-toast-type="<?= $msgType === 'error' ? 'error' : 'success' ?>"<?php endif; ?>
  <?php if ($reopenAdd): ?>data-reopen-add="1"<?php endif; ?>
  <?php if ($importSummary): ?>data-import-summary="<?= htmlspecialchars(json_encode($importSummary), ENT_QUOTES) ?>"<?php endif; ?>
>
<?php
  $NAV_ACTIVE = 'employees';
  $BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'Employees']];
  require __DIR__ . '/../includes/topbar.php';
?>

<main id="main-content" class="dashboard-content">
  <div class="page-header">
    <h1>Employees</h1>
    <?php if (user_can('employees.manage')): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button class="btn-secondary" id="btn-io"><i data-lucide="arrow-down-up"></i> Import / Export</button>
      <?php if ($showRemoved): ?>
        <a class="btn-secondary" href="<?= BASE_URL ?>/dashboard/employees.php"><i data-lucide="corner-up-left"></i> Active roster</a>
      <?php else: ?>
        <a class="btn-secondary" href="<?= BASE_URL ?>/dashboard/employees.php?deleted=1"><i data-lucide="archive"></i> View removed</a>
      <?php endif; ?>
      <button class="btn-primary" id="btn-add"><i data-lucide="user-plus"></i> Add Employee</button>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <?php if ($birthdayFilter !== ''): ?>
    <div class="alert alert-info" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
      <span><i data-lucide="filter"></i> Showing <strong><?= htmlspecialchars($filterLabel) ?></strong> — <?= count($employees) ?> employee(s).</span>
      <a class="btn-secondary btn-sm" href="<?= BASE_URL ?>/dashboard/employees.php"><i data-lucide="x"></i> Show all employees</a>
    </div>
  <?php endif; ?>

  <?php if ($showRemoved): ?>
    <div class="alert alert-info" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
      <span><i data-lucide="archive"></i> Showing <strong>removed (soft-deleted) employees</strong> — <?= count($employees) ?>. They are hidden from the roster and excluded from birthday sends; Restore returns them.</span>
      <a class="btn-secondary btn-sm" href="<?= BASE_URL ?>/dashboard/employees.php"><i data-lucide="corner-up-left"></i> Back to active roster</a>
    </div>
  <?php endif; ?>

  <div id="io-panel" class="panel hidden" style="border-left:4px solid var(--b7);">
    <h2>Bulk Import / Export (CSV · Excel-compatible)</h2>
    <p class="text-muted" style="margin-bottom:1rem;">
      Export your roster, or bulk-add employees from a spreadsheet. In Excel choose
      <strong>Save As → CSV (Comma delimited)</strong>, then upload here. Existing
      <code>emp_id</code>s are updated; new ones are inserted.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;">
      <div style="background:var(--g1);border-radius:var(--rad-s);padding:1rem;">
        <h3 style="margin-bottom:.6rem;">1. Get a template</h3>
        <p class="text-muted" style="margin-bottom:.7rem;">Download a ready-to-fill CSV with the correct column headers and an example row.</p>
        <a class="btn-secondary" href="<?= BASE_URL ?>/dashboard/employees_io.php?action=template">⬇ Download template</a>
      </div>
      <div style="background:var(--g1);border-radius:var(--rad-s);padding:1rem;">
        <h3 style="margin-bottom:.6rem;">2. Export current roster</h3>
        <p class="text-muted" style="margin-bottom:.7rem;">Download all <?= count($employees) ?> employees as a CSV for backup or editing.</p>
        <a class="btn-secondary" href="<?= BASE_URL ?>/dashboard/employees_io.php?action=export">⬇ Export CSV</a>
      </div>
      <div style="background:var(--g1);border-radius:var(--rad-s);padding:1rem;">
        <h3 style="margin-bottom:.6rem;">3. Import CSV</h3>
        <form method="POST" action="<?= BASE_URL ?>/dashboard/employees_io.php" enctype="multipart/form-data"
              onsubmit="return document.getElementById('csv-file').files.length > 0;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="import">
          <input type="file" id="csv-file" name="csv" accept=".csv,text/csv" required style="margin-bottom:.7rem;">
          <button type="submit" class="btn-primary">⬆ Upload & Import</button>
        </form>
      </div>
    </div>

    <?php if ($importSummary): ?>
      <div id="import" class="alert <?= empty($importSummary['errors']) ? 'alert-success' : 'alert-info' ?>" style="margin-top:1rem;">
        <strong>Import complete.</strong>
        Processed <?= (int)$importSummary['total'] ?> row(s):
        <?= (int)$importSummary['inserted'] ?> added,
        <?= (int)$importSummary['updated'] ?> updated,
        <?= (int)$importSummary['skipped'] ?> skipped.
        <?php if (!empty($importSummary['errors'])): ?>
          <ul style="margin:.5rem 0 0 1.1rem;font-size:.82rem;">
            <?php foreach ($importSummary['errors'] as $er): ?>
              <li><?= htmlspecialchars($er) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div id="emp-id-gate" class="panel hidden" style="border-left:4px solid var(--b7,#1a4fa0);">
    <h2>Add Employee — Step 1: Employee ID Validation</h2>
    <p class="text-muted" style="margin-bottom:.8rem;">Enter the Employee ID first. We strictly verify uniqueness and valid string format before opening the full record form.</p>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-start;">
      <div class="form-group" style="flex:1;min-width:240px;">
        <label for="gate-emp-id">EMP_ID * (Alphanumeric/String)</label>
        <input type="text" id="gate-emp-id" placeholder="EMP_2026_001" value="<?= htmlspecialchars($nextAutoId) ?>" autocomplete="off">
        <div id="gate-id-feedback" style="font-size:0.8rem;margin-top:0.3rem;font-weight:600;"></div>
      </div>
      <div style="display:flex;gap:.5rem;padding-top:1.55rem;">
        <button type="button" class="btn-primary" id="gate-continue">Check & continue</button>
        <button type="button" class="btn-secondary" id="gate-cancel">Cancel</button>
      </div>
    </div>
  </div>

  <div id="add-form" class="panel hidden">
    <h2>New Employee Record — Step 2</h2>
    <form method="POST" enctype="multipart/form-data" id="add-emp-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="form-grid-3">
        
        <div class="form-group">
          <label>EMP_ID * (Validated Unique)</label>
          <input type="text" name="emp_id" id="form-emp-id" required readonly value="<?= htmlspecialchars($nextAutoId) ?>" style="background-color: #f1f5f9; cursor: not-allowed; font-weight: bold;">
          <!-- Helper message removed completely per request -->
        </div>

        <div class="form-group">
          <label>EMP_NAME (Full Name) *</label>
          <input type="text" name="emp_name" required placeholder="Vikram Singh" value="<?= $old('emp_name') ?>">
        </div>

        <div class="form-group">
          <label>First_Name *</label>
          <input type="text" name="first_name" required placeholder="Vikram" value="<?= $old('first_name') ?>">
        </div>

        <div class="form-group">
          <label>Last_Name *</label>
          <input type="text" name="last_name" required placeholder="Singh" value="<?= $old('last_name') ?>">
        </div>

        <div class="form-group">
          <label>Email *</label>
          <input type="email" name="email" required placeholder="VIKRAM.S@TYNOR.IN" value="<?= $old('email') ?>">
        </div>

        <div class="form-group">
          <label>Department *</label>
          <input type="text" name="department_name" required placeholder="Information Technology" value="<?= $old('department_name') ?>">
        </div>

        <div class="form-group">
          <label>Designation *</label>
          <input type="text" name="designation_name" required placeholder="Deputy Manager" value="<?= $old('designation_name') ?>">
        </div>

        <div class="form-group">
          <label>Mobile No. *</label>
          <input type="text" name="mobile_no" required placeholder="2345678998" value="<?= $old('mobile_no') ?>">
        </div>

        <div class="form-group">
          <label>Status *</label>
          <select name="status" required>
            <option value="active"   <?= (($_POST['status'] ?? 'active') === 'active')   ? 'selected' : '' ?>>active</option>
            <option value="inactive" <?= (($_POST['status'] ?? '')       === 'inactive') ? 'selected' : '' ?>>inactive</option>
          </select>
        </div>

        <div class="form-group">
          <label>Date of Birth *</label>
          <input type="date" name="dob" required max="<?= date('Y-m-d') ?>" value="<?= $old('dob') ?>">
        </div>

        <div class="form-group">
          <label>Date of Joining (Optional)</label>
          <input type="date" name="joining_date" value="<?= $old('joining_date') ?>">
        </div>

        <div class="form-group">
          <label>HOD Name (Optional)</label>
          <input type="text" name="hod_name" placeholder="Amarjit Singh" value="<?= $old('hod_name') ?>">
        </div>

        <div class="form-group">
          <label>Mentor Name (Optional)</label>
          <input type="text" name="mentor_name" placeholder="Anvinderjit Singh" value="<?= $old('mentor_name') ?>">
        </div>

        <div class="form-group">
          <label>Plant_location *</label>
          <input type="text" name="plant_location" required placeholder="Mohali" value="<?= $old('plant_location') ?>">
        </div>

        <div class="form-group">
          <label>Plant_Name *</label>
          <input type="text" name="plant_name" required placeholder="Corporate Head Office" value="<?= $old('plant_name') ?>">
        </div>

        <div class="form-group">
          <label>Plant ID (Optional)</label>
          <input type="text" name="plant_id" placeholder="PLANT_001" value="<?= $old('plant_id') ?>">
        </div>

        <div class="form-group">
          <label>Upload Photo (From Device/PC)</label>
          <input type="file" name="photo" accept="image/*">
        </div>

      </div>

      <div class="flex gap-2 mt-2" style="margin-top: 15px;">
        <button type="submit" class="btn-primary" id="add-save-btn">Save Complete Record</button>
        <button type="button" class="btn-secondary" id="add-form-cancel">Cancel</button>
      </div>
    </form>
  </div>

  <div class="panel">
    <div class="tab-bar" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
      <div>
        <span id="emp-count-label" class="tab-btn active" style="cursor:default;">
          <?php if ($showRemoved): ?>Removed employees (<?= (int)$total ?>)
          <?php elseif ($birthdayFilter !== ''): ?><?= htmlspecialchars($filterLabel) ?> (<?= (int)$total ?>)
          <?php elseif ($q !== ''): ?>Search results (<?= (int)$total ?>)
          <?php else: ?>Master Roster (<?= (int)$total ?>)<?php endif; ?>
        </span>
        <?php if (!$showRemoved && $birthdayFilter !== 'today'): ?>
          <a class="tab-btn" href="<?= BASE_URL ?>/dashboard/employees.php?birthday=today"><i data-lucide="cake"></i> Today's Birthdays</a>
        <?php endif; ?>
      </div>
      <div>
        <button class="btn-secondary" id="btn-toggle-columns" style="font-size:0.85rem;"><i data-lucide="columns-2"></i> Toggle Columns</button>
      </div>
    </div>

    <?php if (!$showRemoved && $birthdayFilter === ''): ?>
    <form method="get" action="<?= BASE_URL ?>/dashboard/employees.php" class="filter-bar" role="search">
      <label for="emp-search" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;">Search employees</label>
      <span class="search-wrap">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="text" id="emp-search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" class="search-input"
               placeholder="Search by name, ID, email, department, plant…" autocomplete="off" aria-controls="emp-results"
               hx-get="<?= BASE_URL ?>/dashboard/employees.php"
               hx-trigger="keyup changed delay:350ms"
               hx-target="#emp-results" hx-select="#emp-results" hx-swap="innerHTML"
               hx-select-oob="#emp-count-label" hx-push-url="true" hx-indicator="#emp-search-ind">
      </span>
      <span class="emp-search-ind htmx-indicator" id="emp-search-ind" role="status" aria-live="polite"><span class="spin" aria-hidden="true"></span> Searching…</span>
      <button type="submit" class="btn-primary" style="padding:8px 16px;">Search</button>
      <?php if ($q !== ''): ?><a class="btn-reset" href="<?= BASE_URL ?>/dashboard/employees.php">Clear</a><?php endif; ?>
    </form>
    <?php endif; ?>

    <div id="column-toggle-panel" class="column-toggle-panel" style="display:none;">
      <strong>Toggle Columns: </strong>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="0"> EMP_ID</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="1"> EMP_NAME</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="2"> First_Name</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="3"> Last_Name</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="4"> Email</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="5"> Department</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="6"> Designation</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="7"> Mobile No.</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="8"> Status</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="9"> Birthday</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="10"> Date of Joining</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="11"> HOD Name</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="12"> Mentor Name</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="13"> Plant_location</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="14"> Plant_Name</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="15"> Photo</label>
      <label><input type="checkbox" checked class="col-toggle-checkbox" data-col-index="16"> Action</label>
    </div>

    <div class="tab-panel active" id="tab-all">
      <div id="emp-results" aria-live="polite">
      <?php if (empty($employees)): ?>
        <p class="empty-state">
          <?php if ($showRemoved): ?>No removed employees — the roster is fully active.
          <?php elseif ($q !== ''): ?>No employees match &ldquo;<?= htmlspecialchars($q) ?>&rdquo;. <a href="<?= BASE_URL ?>/dashboard/employees.php">Clear search</a>.
          <?php elseif ($birthdayFilter !== ''): ?>No employees in this view.
          <?php else: ?>No employees yet. Use &ldquo;Add Employee&rdquo; to create the first record.<?php endif; ?>
        </p>
      <?php else: ?>
      <div class="table-wrap">
        <table class="data-table" id="emp-table">
          <thead>
            <tr>
              <th>EMP_ID</th>
              <th>EMP_NAME</th>
              <th>First_Name</th>
              <th>Last_Name</th>
              <th>Email</th>
              <th>Department</th>
              <th>Designation</th>
              <th>Mobile No.</th>
              <th>Status</th>
              <th>Birthday</th>
              <th>Date of Joining</th>
              <th>HOD Name</th>
              <th>Mentor Name</th>
              <th>Plant_location</th>
              <th>Plant_Name</th>
              <th>Photo</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($employees as $e): 
            $fullName  = $e['full_name'] ?? $e['emp_name'] ?? '';
            $nameParts = explode(' ', trim($fullName));
            $firstName = $e['first_name'] ?? $nameParts[0] ?? '—';
            $lastName  = $e['last_name']  ?? (count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '—');
          ?>
            <tr>
              <td><span class="badge badge-blue"><?= htmlspecialchars($e['emp_id'] ?? '') ?></span></td>
              <td><strong><?= htmlspecialchars($fullName ?: '—') ?></strong></td>
              <td><?= htmlspecialchars($firstName) ?></td>
              <td><?= htmlspecialchars($lastName) ?></td>
              <td><?= htmlspecialchars($e['official_email'] ?? $e['email'] ?? '—') ?></td>
              <td><?= htmlspecialchars($e['department_name'] ?? $e['department'] ?? '—') ?></td>
              <td><?= htmlspecialchars($e['designation_name'] ?? $e['designation'] ?? '—') ?></td>
              <td><?= htmlspecialchars($e['mobile_no'] ?? $e['phone_number'] ?? '—') ?></td>
              <td>
                <span class="badge <?= strtolower($e['employee_status'] ?? $e['status'] ?? 'active') === 'active' ? 'badge-active' : 'badge-danger' ?>">
                  <?= htmlspecialchars(ucfirst($e['employee_status'] ?? $e['status'] ?? 'active')) ?>
                </span>
              </td>
              <td>
                <?php $dobRaw = $e['date_of_birth'] ?? ''; ?>
                <?= $dobRaw ? htmlspecialchars(date('d M', strtotime((string)$dobRaw))) : '—' ?>
                <?php if (($e['is_birthday_today'] ?? 'No') === 'Yes'): ?>
                  <span class="badge badge-active">Today!</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars(!empty($e['joining_date']) ? $e['joining_date'] : ($e['date_of_joining'] ?? $e['doj'] ?? '—')) ?></td>
              <td><?= htmlspecialchars($e['hod_name'] ?? '—') ?></td>
              <td><?= htmlspecialchars($e['mentor_name'] ?? '—') ?></td>
              <td><?= htmlspecialchars($e['plant_location'] ?? '—') ?></td>
              <td><?= htmlspecialchars($e['plant_name'] ?? '—') ?></td>
              <td>
                <?= function_exists('employee_avatar_html') ? employee_avatar_html($fullName, $e['photo_path'] ?? $e['photo'] ?? '', 32) : '' ?>
              </td>
              <td>
                <div style="display:flex;gap:6px;align-items:center;">
                  <?php if ($showRemoved): ?>
                    <?php if (user_can('employees.manage')): ?>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Restore <?= htmlspecialchars(addslashes($fullName)) ?> to the active roster?')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="restore">
                      <input type="hidden" name="emp_id" value="<?= htmlspecialchars($e['emp_id'] ?? '') ?>">
                      <button type="submit" class="btn-primary btn-sm"><i data-lucide="rotate-ccw"></i> Restore</button>
                    </form>
                    <?php else: ?>
                    <span class="text-muted" style="font-size:.8rem;">View only</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <?php if (user_can('employees.manage')): ?>
                    <a href="<?= BASE_URL ?>/dashboard/employee_edit.php?emp_id=<?= urlencode($e['emp_id'] ?? '') ?>"
                       class="btn-secondary btn-sm" title="Edit this employee"><i data-lucide="pencil"></i> Edit</a>
                    <?php endif; ?>
                    <?php if (user_can('communications.send')): ?>
                    <form method="POST" action="send_test.php" style="display:inline" target="_blank">
                      <?= csrf_field() ?>
                      <input type="hidden" name="emp_id" value="<?= htmlspecialchars($e['emp_id'] ?? '') ?>">
                      <button type="submit" class="btn-primary btn-sm" title="Send test birthday email for this employee"><i data-lucide="mail"></i> Test</button>
                    </form>
                    <?php endif; ?>
                    <?php if (user_can('employees.manage')): ?>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Soft-delete <?= htmlspecialchars(addslashes($fullName)) ?>? This is reversible.')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="emp_id" value="<?= htmlspecialchars($e['emp_id'] ?? '') ?>">
                      <button type="submit" class="btn-danger btn-sm">Remove</button>
                    </form>
                    <?php endif; ?>
                    <?php if (!user_can('employees.manage') && !user_can('communications.send')): ?>
                    <span class="text-muted" style="font-size:.8rem;">View only</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1):
          $pgUrl = function (int $p) use ($q, $birthdayFilter, $showRemoved) {
              $a = ['page' => $p];
              if ($q !== '')              $a['q'] = $q;
              if ($birthdayFilter !== '') $a['birthday'] = $birthdayFilter;
              if ($showRemoved)           $a['deleted'] = '1';
              return BASE_URL . '/dashboard/employees.php?' . http_build_query($a);
          };
      ?>
        <div class="pager" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-top:1rem;flex-wrap:wrap;">
          <?php if ($page > 1): ?>
            <a class="btn-secondary btn-sm" href="<?= $pgUrl($page - 1) ?>"
               hx-get="<?= $pgUrl($page - 1) ?>" hx-target="#emp-results" hx-select="#emp-results" hx-swap="innerHTML" hx-select-oob="#emp-count-label" hx-push-url="true" hx-indicator="#emp-search-ind">&larr; Prev</a>
          <?php else: ?>
            <span class="btn-secondary btn-sm" style="opacity:.45;cursor:default;">&larr; Prev</span>
          <?php endif; ?>
          <span class="text-muted" style="font-size:.85rem;">Page <?= (int)$page ?> of <?= (int)$pages ?> · <?= (int)$total ?> total</span>
          <?php if ($page < $pages): ?>
            <a class="btn-secondary btn-sm" href="<?= $pgUrl($page + 1) ?>"
               hx-get="<?= $pgUrl($page + 1) ?>" hx-target="#emp-results" hx-select="#emp-results" hx-swap="innerHTML" hx-select-oob="#emp-count-label" hx-push-url="true" hx-indicator="#emp-search-ind">Next &rarr;</a>
          <?php else: ?>
            <span class="btn-secondary btn-sm" style="opacity:.45;cursor:default;">Next &rarr;</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
<script src="<?= ASSETS_URL ?>/js/toast.js"></script>
<script src="<?= ASSETS_URL ?>/js/employees.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const gateInput = document.getElementById('gate-emp-id');
    const feedback = document.getElementById('gate-id-feedback');
    const continueBtn = document.getElementById('gate-continue');

    if (gateInput && continueBtn) {
        let debounceTimer;
        gateInput.addEventListener('input', function() {
            const val = gateInput.value.trim();
            clearTimeout(debounceTimer);
            
            if (!val) {
                feedback.textContent = 'Employee ID cannot be empty.';
                feedback.style.color = '#e11d48';
                continueBtn.disabled = true;
                return;
            }

            const formatRegex = /^[A-Za-z0-9_\-]+$/;
            if (!formatRegex.test(val)) {
                feedback.textContent = 'Invalid format. Use alphanumeric characters, dashes, and underscores only.';
                feedback.style.color = '#e11d48';
                continueBtn.disabled = true;
                return;
            }

            feedback.textContent = 'Checking uniqueness...';
            feedback.style.color = '#2563eb';

            debounceTimer = setTimeout(() => {
                fetch(`<?= BASE_URL ?>/dashboard/employees.php?action=check_emp_id&emp_id=` + encodeURIComponent(val))
                    .then(res => res.json())
                    .then(data => {
                        if (data.exists) {
                            feedback.textContent = 'Error: This Employee ID already exists in the system!';
                            feedback.style.color = '#e11d48';
                            continueBtn.disabled = true;
                        } else {
                            feedback.textContent = 'Employee ID is available and valid.';
                            feedback.style.color = '#16a34a';
                            continueBtn.disabled = false;
                        }
                    })
                    .catch(() => {
                        feedback.textContent = 'Could not verify ID availability.';
                        feedback.style.color = '#e11d48';
                    });
            }, 300);
        });

        gateInput.dispatchEvent(new Event('input'));
    }
});
</script>
</body>
</html>