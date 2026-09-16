<?php
/**
 * sql_validate.php — SQL validation harness for the dashboard (safe, read-only).
 *
 * Prepares (server-side, never executes) every distinct SQL statement used by
 * the dashboard pages, plus the user_feature_access feature-access queries.
 * PDO::prepare() with ATTR_EMULATE_PREPARES=false sends PREPARE to MariaDB, so
 * any unknown table/column/view/procedure or syntax error throws PDOException.
 *
 * It changes no data. Run it after schema/migration changes to catch a query
 * that references a renamed or missing column before it fails in production.
 *
 *   php scripts/sql_validate.php     # exits 0 when all pass, 1 on any failure
 */

// Developer tool: command line only — never reachable over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit("Forbidden: sql_validate.php runs from the command line only.\n");
}

require_once __DIR__ . '/../config/database.php';   // gives $conn (PDO)

/** @var array<string, array<string,string>> file => [label => sql] */
$checks = [
    'index.php' => [
        'today count'        => "SELECT COUNT(*) FROM v_todays_birthdays",
        'upcoming7 count'    => "SELECT COUNT(*) FROM v_upcoming_birthdays WHERE days_away <= 7",
        'pending approvals'  => "SELECT COUNT(*) FROM birthday_approvals WHERE status = 'pending'",
        'failed today'       => "SELECT COUNT(*) FROM notification_logs WHERE status = 'failed' AND DATE(created_at) = CURDATE()",
        'sent today ids'     => "SELECT DISTINCT emp_id FROM notification_logs WHERE status='sent' AND sent_on = CURDATE()",
        'today list'         => "SELECT emp_id, full_name, department_name FROM v_todays_birthdays LIMIT 25",
        'upcoming list'      => "SELECT emp_id, full_name, department_name, birthday_display, days_away FROM v_upcoming_birthdays LIMIT 12",
        'last send'          => "SELECT MAX(created_at) FROM notification_logs WHERE status='sent'",
        'failed total'       => "SELECT COUNT(*) FROM notification_logs WHERE status='failed'",
        'recent audit'       => "SELECT a.action, a.table_name, a.created_at, u.full_name AS actor FROM audit_logs a LEFT JOIN users u ON u.user_id = a.performed_by ORDER BY a.created_at DESC LIMIT 8",
    ],
    'logs.php' => [
        'retry lookup (implicit via bdaynotify) - list filtered' => "SELECT nl.*, e.full_name, e.emp_id AS employee_code FROM notification_logs nl LEFT JOIN employee_master e ON e.emp_id = nl.emp_id WHERE nl.status = ? ORDER BY nl.created_at DESC LIMIT 200",
        'list unfiltered'    => "SELECT nl.*, e.full_name, e.emp_id AS employee_code FROM notification_logs nl LEFT JOIN employee_master e ON e.emp_id = nl.emp_id ORDER BY nl.created_at DESC LIMIT 200",
    ],
    'analytics.php' => [
        'status totals'      => "SELECT status, COUNT(*) AS c FROM notification_logs GROUP BY status",
        'opened total'       => "SELECT COUNT(*) FROM notification_logs WHERE status = 'sent' AND email_opened_at IS NOT NULL",
        'trend 14d'          => "SELECT sent_on, SUM(status = 'sent') AS sent_c, SUM(status = 'failed') AS fail_c FROM notification_logs WHERE sent_on >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY sent_on ORDER BY sent_on ASC",
        'dept distribution'  => "SELECT department_name, COUNT(*) AS c FROM employee_master WHERE is_deleted = 0 AND LOWER(status) = 'active' AND department_name IS NOT NULL AND department_name <> '' GROUP BY department_name ORDER BY c DESC LIMIT 10",
        'template perf'      => "SELECT COALESCE(template_id, 0) AS template_id, COUNT(*) AS sends, SUM(email_opened_at IS NOT NULL) AS opens FROM notification_logs WHERE status = 'sent' GROUP BY COALESCE(template_id, 0) ORDER BY sends DESC LIMIT 10",
        'recent activity'    => "SELECT nl.created_at, nl.recipient_address, nl.subject, nl.status, nl.email_opened_at, e.full_name FROM notification_logs nl LEFT JOIN employee_master e ON e.emp_id = nl.emp_id ORDER BY nl.created_at DESC LIMIT 15",
    ],
    'view_sent.php' => [
        'single log join'    => "SELECT l.*, v.full_name, v.first_name, v.last_name, v.department_name, v.designation_name AS designation, v.dob_formatted, v.photo_path FROM notification_logs l LEFT JOIN v_employee_master_complete v ON v.emp_id = l.emp_id WHERE l.log_id = ? LIMIT 1",
    ],
    'approvals.php' => [
        'batch lookup'       => "SELECT * FROM birthday_approvals WHERE batch_date = CURDATE() AND batch_type = ?",
        'request upsert'     => "INSERT INTO birthday_approvals (batch_date, batch_type, recipient_count, status, requested_by, requested_by_name) VALUES (CURDATE(), ?, ?, 'pending', ?, ?) ON DUPLICATE KEY UPDATE recipient_count=VALUES(recipient_count), status='pending', requested_by=VALUES(requested_by), requested_by_name=VALUES(requested_by_name), approved_by=NULL, approved_by_name=NULL, decided_at=NULL",
        'approve'            => "UPDATE birthday_approvals SET status='approved', approved_by=?, approved_by_name=?, decided_at=NOW() WHERE batch_date=CURDATE() AND batch_type=? AND status='pending'",
        'reject'             => "UPDATE birthday_approvals SET status='rejected', approved_by=?, approved_by_name=?, decided_at=NOW() WHERE batch_date=CURDATE() AND batch_type=? AND status IN ('pending','approved')",
        'send'               => "UPDATE birthday_approvals SET status='sent', sent_at=NOW(), sent_count=?, failed_count=? WHERE batch_date=CURDATE() AND batch_type=?",
        'history'            => "SELECT * FROM birthday_approvals ORDER BY created_at DESC LIMIT 20",
    ],
    'preview_email.php' => [
        'employee by id'     => "SELECT * FROM v_employee_master_complete WHERE emp_id = ? LIMIT 1",
        'sample employee'    => "SELECT * FROM v_employee_master_complete ORDER BY full_name LIMIT 1",
        'employee options'   => "SELECT emp_id, full_name FROM v_employee_master_complete ORDER BY full_name ASC",
    ],
    'notification_channels.php' => [
        'add'                => "INSERT INTO notification_channels (channel_name, channel_key, is_active) VALUES (:name, :key, 1)",
        'toggle'             => "UPDATE notification_channels SET is_active = 1 - is_active WHERE id = :id",
        'delete'             => "DELETE FROM notification_channels WHERE id = :id",
        'list'               => "SELECT * FROM notification_channels ORDER BY channel_name",
    ],
    'send_test.php' => [
        'employee by id'     => "SELECT * FROM v_employee_master_complete WHERE emp_id = ? LIMIT 1",
    ],
    'users.php' => [
        'active superadmins' => "SELECT COUNT(*) FROM users WHERE role='SuperAdmin' AND status='approved' AND is_active=1",
        'target user'        => "SELECT user_id, full_name, email, role, is_active, status FROM users WHERE user_id = ? LIMIT 1",
        'approve/reactivate' => "UPDATE users SET status='approved', is_active=1 WHERE user_id=?",
        'reject'             => "UPDATE users SET status='rejected', is_active=0 WHERE user_id=?",
        'disable'            => "UPDATE users SET status='disabled', is_active=0 WHERE user_id=?",
        'set_role'           => "UPDATE users SET role=? WHERE user_id=?",
        'list users'         => "SELECT user_id, full_name, email, role, is_active, status, created_at FROM users ORDER BY (status='pending') DESC, full_name ASC",
        'feature reset'      => "DELETE FROM user_feature_access WHERE user_id=? AND feature_key=?",
        'feature upsert'     => "INSERT INTO user_feature_access (user_id, feature_key, is_enabled, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_by = VALUES(updated_by)",
    ],
    'rbac.php (feature layer)' => [
        'load overrides'     => "SELECT feature_key, is_enabled FROM user_feature_access WHERE user_id = ?",
    ],
    'audit_log.php' => [
        'filtered'           => "SELECT a.*, u.full_name AS actor FROM audit_logs a LEFT JOIN users u ON u.user_id = a.performed_by WHERE a.action = ? ORDER BY a.created_at DESC LIMIT 300",
        'unfiltered'         => "SELECT a.*, u.full_name AS actor FROM audit_logs a LEFT JOIN users u ON u.user_id = a.performed_by ORDER BY a.created_at DESC LIMIT 300",
    ],
    'employees.php' => [
        'dup id'             => "SELECT 1 FROM employee_master WHERE emp_id = ? LIMIT 1",
        'dup email'          => "SELECT 1 FROM email_master WHERE official_email = ? LIMIT 1",
        'insert employee'    => "INSERT INTO employee_master (emp_id, full_name, first_name, last_name, department_name, designation_name, mobile_no, status, joining_date, hod_name, mentor_name, plant_location, plant_name, plant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        'insert photo'       => "INSERT INTO photo_master (photo_id, emp_id, photo_path, photo_key) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path), photo_key = VALUES(photo_key)",
        'insert dob'         => "INSERT INTO dob_master (dob_id, emp_id, date_of_birth) VALUES (?, ?, ?)",
        'insert email'       => "INSERT INTO email_master (email_id, emp_id, official_email) VALUES (?, ?, ?)",
        'soft delete proc'   => "CALL sp_soft_delete_employee(?, ?)",
        'restore proc'       => "CALL sp_restore_employee(?, ?)",
        'count employee_view'=> "SELECT COUNT(*) FROM employee_view",
        'count fallback view'=> "SELECT COUNT(*) FROM v_employee_master_complete",
        'count todays'       => "SELECT COUNT(*) FROM v_todays_birthdays",
        'count upcoming'     => "SELECT COUNT(*) FROM v_upcoming_birthdays WHERE days_away <= 7",
        'count search'       => "SELECT COUNT(*) FROM employee_view WHERE (full_name LIKE ? OR emp_id LIKE ? OR official_email LIKE ? OR department_name LIKE ? OR designation_name LIKE ? OR plant_name LIKE ?)",
        'count removed'      => "SELECT COUNT(*) FROM employee_master e LEFT JOIN email_master em ON em.emp_id = e.emp_id LEFT JOIN dob_master dm ON dm.emp_id = e.emp_id LEFT JOIN photo_master pm ON pm.emp_id = e.emp_id WHERE e.is_deleted = 1",
        'list default'       => "SELECT * FROM employee_view ORDER BY full_name ASC LIMIT 25 OFFSET 0",
        'list removed'       => "SELECT e.*, em.official_email, dm.date_of_birth, pm.photo_path FROM employee_master e LEFT JOIN email_master em ON em.emp_id = e.emp_id LEFT JOIN dob_master dm ON dm.emp_id = e.emp_id LEFT JOIN photo_master pm ON pm.emp_id = e.emp_id WHERE e.is_deleted = 1 ORDER BY e.full_name ASC LIMIT 25 OFFSET 0",
    ],
    'employee_edit.php' => [
        'load join'          => "SELECT e.*, em.official_email, em.email_status, dm.date_of_birth, pm.photo_path FROM employee_master e LEFT JOIN email_master em ON em.emp_id = e.emp_id LEFT JOIN dob_master dm ON dm.emp_id = e.emp_id LEFT JOIN photo_master pm ON pm.emp_id = e.emp_id WHERE e.emp_id = ? LIMIT 1",
        'dup email'          => "SELECT emp_id FROM email_master WHERE official_email = ? AND emp_id <> ? LIMIT 1",
        'update employee'    => "UPDATE employee_master SET full_name = ?, first_name = ?, last_name = ?, department_name = ?, designation_name = ?, mobile_no = ?, status = ?, birthday_enabled = ?, joining_date = ?, hod_name = ?, mentor_name = ?, plant_location = ?, plant_name = ?, plant_id = ?, updated_at = NOW() WHERE emp_id = ?",
        'email upsert'       => "INSERT INTO email_master (email_id, emp_id, official_email) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE official_email = VALUES(official_email)",
        'dob upsert'         => "INSERT INTO dob_master (dob_id, emp_id, date_of_birth) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE date_of_birth = VALUES(date_of_birth)",
        'photo upsert'       => "INSERT INTO photo_master (photo_id, emp_id, photo_path, photo_key) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path), photo_key = VALUES(photo_key)",
    ],
    'employees_io.php' => [
        'export view'        => "SELECT * FROM employee_view ORDER BY full_name ASC",
        'export fallback'    => "SELECT * FROM v_employee_master_complete ORDER BY full_name ASC",
        'plant upsert'       => "INSERT INTO plant_master (plant_id, plant_name, plant_location, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE plant_name = VALUES(plant_name), plant_location = VALUES(plant_location)",
        'exists'             => "SELECT 1 FROM employee_master WHERE emp_id = ?",
        'employee upsert'    => "INSERT INTO employee_master (emp_id, full_name, first_name, last_name, department_name, designation_name, mobile_no, status, joining_date, hod_name, mentor_name, plant_location, plant_name, plant_id, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), first_name=VALUES(first_name), last_name=VALUES(last_name), department_name=VALUES(department_name), designation_name=VALUES(designation_name), mobile_no=VALUES(mobile_no), status=VALUES(status), joining_date=VALUES(joining_date), hod_name=VALUES(hod_name), mentor_name=VALUES(mentor_name), plant_location=VALUES(plant_location), plant_name=VALUES(plant_name), plant_id=VALUES(plant_id), is_deleted=0",
        'dob upsert'         => "INSERT INTO dob_master (dob_id, emp_id, date_of_birth) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE date_of_birth=VALUES(date_of_birth)",
        'dup email'          => "SELECT emp_id FROM email_master WHERE official_email = ? AND emp_id <> ? LIMIT 1",
        'email upsert'       => "INSERT INTO email_master (email_id, emp_id, official_email) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE official_email=VALUES(official_email)",
        'photo upsert'       => "INSERT INTO photo_master (photo_id, emp_id, photo_path) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE photo_path=VALUES(photo_path)",
    ],
    'check_emp_id.php' => [
        'exists'             => "SELECT 1 FROM employee_master WHERE emp_id = ? LIMIT 1",
    ],
];

$pass = 0; $fail = 0; $failures = [];
foreach ($checks as $file => $stmts) {
    echo "\n== {$file} ==\n";
    foreach ($stmts as $label => $sql) {
        try {
            $st = $conn->prepare($sql);
            // Preparing is enough to validate objects+syntax; do not execute.
            $st = null;
            echo "  [PASS] {$label}\n";
            $pass++;
        } catch (Throwable $e) {
            echo "  [FAIL] {$label} :: " . $e->getMessage() . "\n";
            $failures[] = "{$file} / {$label}: " . $e->getMessage();
            $fail++;
        }
    }
}

echo "\n=====================================================\n";
echo "SQL VALIDATION: {$pass} passed, {$fail} failed.\n";
if ($failures) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
}
echo "=====================================================\n";

exit($fail === 0 ? 0 : 1);
