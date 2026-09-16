<?php
/**
 * check_emp_id.php — AJAX availability check for a new Employee ID.
 *
 * Used by the two-step "Add Employee" flow: the ID is validated BEFORE the full
 * form is revealed, so a duplicate is caught up front. This is a convenience
 * check only — employees.php re-checks on submit and the emp_id PRIMARY KEY is
 * the final guarantee against duplicates (defence in depth).
 *
 * Returns JSON: { ok, valid, exists, message }.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';   // must be logged in
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Only users who may add employees can probe IDs.
if (!user_can('employees.manage')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to add employees.']);
    exit;
}

// Also respect the per-user Employees feature grant (JSON 403, not the HTML
// page require_feature() would render, so the AJAX caller gets clean output).
if (!current_user_can_feature($conn, 'employees')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'The Employees feature is disabled for your account.']);
    exit;
}

// State-changing-style probe → require POST + CSRF (no enumeration via GET).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request. Please refresh and try again.']);
    exit;
}

$empId = trim((string) ($_POST['emp_id'] ?? ''));

if ($empId === '') {
    echo json_encode(['ok' => true, 'valid' => false, 'exists' => false, 'message' => 'Please enter an Employee ID.']);
    exit;
}
if (mb_strlen($empId) > 50) {
    echo json_encode(['ok' => true, 'valid' => false, 'exists' => false, 'message' => 'Employee ID is too long (max 50 characters).']);
    exit;
}

try {
    $st = $conn->prepare("SELECT 1 FROM employee_master WHERE emp_id = ? LIMIT 1");
    $st->execute([$empId]);
    $exists = (bool) $st->fetchColumn();
} catch (Throwable $e) {
    error_log('check_emp_id error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not check the Employee ID right now.']);
    exit;
}

echo json_encode([
    'ok'      => true,
    'valid'   => true,
    'exists'  => $exists,
    'emp_id'  => $empId,
    'message' => $exists
        ? 'An employee with this Employee ID already exists.'
        : 'This Employee ID is available.',
]);
