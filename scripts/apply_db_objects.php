<?php
/**
 * apply_db_objects.php — installs the DB-tier triggers, procedures and the
 * employee_change_log table (the objects documented in
 * database/triggers_procedures.sql).
 *
 * WHY THIS EXISTS: public/setup.php imports .sql by splitting on ';', which
 * cannot handle stored-procedure / trigger bodies. This applier sends each
 * object to the server as ONE statement via PDO (no DELIMITER needed), so it
 * works everywhere and is safe to re-run (DROP IF EXISTS + CREATE).
 *
 * ACCESS: CLI, or a signed-in SuperAdmin over the web (fail-closed).
 */
require_once __DIR__ . '/../config/config.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth_check.php';
    require_once __DIR__ . '/../includes/rbac.php';
    require_cap('settings.manage');   // SuperAdmin only
    header('Content-Type: application/json');
}

require __DIR__ . '/../config/database.php';   // $conn

$results = [];
$run = function (string $label, string $sql) use ($conn, &$results) {
    try { $conn->exec($sql); $results[$label] = 'ok'; }
    catch (Throwable $e) { $results[$label] = 'ERR: ' . $e->getMessage(); }
};

// 1) DB-tier change history table.
$run('employee_change_log', "
    CREATE TABLE IF NOT EXISTS employee_change_log (
        change_id   BIGINT AUTO_INCREMENT PRIMARY KEY,
        emp_id      VARCHAR(50) NOT NULL,
        old_status  VARCHAR(20) DEFAULT NULL,
        new_status  VARCHAR(20) DEFAULT NULL,
        old_deleted TINYINT(1)  DEFAULT NULL,
        new_deleted TINYINT(1)  DEFAULT NULL,
        changed_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ecl_emp (emp_id),
        INDEX idx_ecl_changed_at (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 2) UNIQUE official_email — only if no duplicates exist and not already present.
try {
    $dups = $conn->query("SELECT official_email, COUNT(*) c FROM email_master GROUP BY official_email HAVING c > 1")
                 ->fetchAll(PDO::FETCH_ASSOC);
    $exists = (int)$conn->query("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE table_schema = DATABASE() AND table_name='email_master' AND index_name='uq_email_address'")->fetchColumn();
    if ($exists) {
        $results['uq_email_address'] = 'already present';
    } elseif ($dups) {
        $results['uq_email_address'] = 'SKIPPED — duplicate emails exist: ' . json_encode($dups);
    } else {
        $conn->exec("ALTER TABLE email_master ADD UNIQUE INDEX uq_email_address (official_email)");
        $results['uq_email_address'] = 'created';
    }
} catch (Throwable $e) {
    $results['uq_email_address'] = 'ERR: ' . $e->getMessage();
}

// 3) Validation triggers — no future DOB.
$run('drop trg_dob_before_insert', "DROP TRIGGER IF EXISTS trg_dob_before_insert");
$run('trg_dob_before_insert',
    "CREATE TRIGGER trg_dob_before_insert BEFORE INSERT ON dob_master FOR EACH ROW
     BEGIN
        IF NEW.date_of_birth > CURDATE() THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Date of birth cannot be in the future.';
        END IF;
     END");
$run('drop trg_dob_before_update', "DROP TRIGGER IF EXISTS trg_dob_before_update");
$run('trg_dob_before_update',
    "CREATE TRIGGER trg_dob_before_update BEFORE UPDATE ON dob_master FOR EACH ROW
     BEGIN
        IF NEW.date_of_birth > CURDATE() THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Date of birth cannot be in the future.';
        END IF;
     END");

// 4) Validation triggers — email format (regex uses [.] to avoid backslash escaping).
$emailRe = '^[^@[:space:]]+@[^@[:space:]]+[.][^@[:space:]]+$';
$run('drop trg_email_before_insert', "DROP TRIGGER IF EXISTS trg_email_before_insert");
$run('trg_email_before_insert',
    "CREATE TRIGGER trg_email_before_insert BEFORE INSERT ON email_master FOR EACH ROW
     BEGIN
        IF NEW.official_email NOT REGEXP '{$emailRe}' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid official email format.';
        END IF;
     END");
$run('drop trg_email_before_update', "DROP TRIGGER IF EXISTS trg_email_before_update");
$run('trg_email_before_update',
    "CREATE TRIGGER trg_email_before_update BEFORE UPDATE ON email_master FOR EACH ROW
     BEGIN
        IF NEW.official_email NOT REGEXP '{$emailRe}' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid official email format.';
        END IF;
     END");

// 5) Change-log trigger — record status / soft-delete transitions.
$run('drop trg_emp_after_update', "DROP TRIGGER IF EXISTS trg_emp_after_update");
$run('trg_emp_after_update',
    "CREATE TRIGGER trg_emp_after_update AFTER UPDATE ON employee_master FOR EACH ROW
     BEGIN
        IF (NEW.status <> OLD.status) OR (NEW.is_deleted <> OLD.is_deleted) THEN
            INSERT INTO employee_change_log (emp_id, old_status, new_status, old_deleted, new_deleted)
            VALUES (OLD.emp_id, OLD.status, NEW.status, OLD.is_deleted, NEW.is_deleted);
        END IF;
     END");

// 6) Procedures — soft delete + restore (single audit source; app delegates here).
$run('drop sp_soft_delete_employee', "DROP PROCEDURE IF EXISTS sp_soft_delete_employee");
$run('sp_soft_delete_employee',
    "CREATE PROCEDURE sp_soft_delete_employee (IN p_emp_id VARCHAR(50), IN p_actor INT)
     BEGIN
        UPDATE employee_master
           SET is_deleted = 1, status = 'inactive', updated_at = NOW()
         WHERE emp_id = p_emp_id AND is_deleted = 0;
        INSERT INTO audit_logs (table_name, record_id, action, new_values, performed_by)
        VALUES ('employee_master', p_emp_id, 'DELETE', JSON_OBJECT('soft_deleted', TRUE), p_actor);
     END");
$run('drop sp_restore_employee', "DROP PROCEDURE IF EXISTS sp_restore_employee");
$run('sp_restore_employee',
    "CREATE PROCEDURE sp_restore_employee (IN p_emp_id VARCHAR(50), IN p_actor INT)
     BEGIN
        UPDATE employee_master
           SET is_deleted = 0, status = 'active', updated_at = NOW()
         WHERE emp_id = p_emp_id AND is_deleted = 1;
        INSERT INTO audit_logs (table_name, record_id, action, new_values, performed_by)
        VALUES ('employee_master', p_emp_id, 'UPDATE', JSON_OBJECT('restored', TRUE), p_actor);
     END");

if ($isCli) {
    foreach ($results as $k => $v) echo str_pad($k, 32) . $v . "\n";
} else {
    echo json_encode(['ok' => true, 'results' => $results], JSON_PRETTY_PRINT);
}
