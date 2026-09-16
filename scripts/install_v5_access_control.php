<?php
/**
 * install_v5_access_control.php — Install access control upgrade
 * 
 * Run this ONCE after updating code to create new tables and indexes.
 * Can be run from command line or web browser.
 * 
 * Usage (CLI): php scripts/install_v5_access_control.php
 * Usage (Web): Navigate to: /scripts/install_v5_access_control.php
 */

// Allow web access only for admins
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    session_start();
    $isAdmin = ($_SESSION['admin_role'] ?? '') === 'SuperAdmin';
    if (!$isAdmin) {
        http_response_code(403);
        die("Access denied. SuperAdmin role required.");
    }
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$startTime = microtime(true);
$errors = [];
$warnings = [];
$success = [];

echo $isCli ? "\n" : "<!DOCTYPE html><html><head><title>Install v5 Access Control</title><style>body{font-family:sans-serif;max-width:800px;margin:2rem auto;padding:1rem;}pre{background:#f1f5f9;padding:1rem;border-radius:4px;overflow:auto;}.success{color:#059669;}.error{color:#dc2626;}.warning{color:#f59e0b;}</style></head><body><h1>Installing v5 Access Control Upgrade</h1><pre>\n";

function log_msg($msg, $type = 'info') {
    global $isCli;
    $prefix = match($type) {
        'success' => $isCli ? '[✓] ' : '<span class="success">[✓]</span> ',
        'error' => $isCli ? '[✗] ' : '<span class="error">[✗]</span> ',
        'warning' => $isCli ? '[!] ' : '<span class="warning">[!]</span> ',
        default => '[i] '
    };
    echo $prefix . $msg . ($isCli ? "\n" : "<br>\n");
}

try {
    log_msg("Starting installation at " . date('Y-m-d H:i:s'));
    log_msg("Database: " . getenv('DB_NAME'));
    log_msg("========================================");
    
    // ══════════════════════════════════════════════════════════════
    // 1. READ SQL FILE
    // ══════════════════════════════════════════════════════════════
    $sqlFile = __DIR__ . '/../database/upgrade_v5_access_control.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: {$sqlFile}");
    }
    
    $sql = file_get_contents($sqlFile);
    log_msg("SQL file loaded: " . number_format(strlen($sql)) . " bytes");
    
    // ══════════════════════════════════════════════════════════════
    // 2. PARSE AND EXECUTE STATEMENTS
    // ══════════════════════════════════════════════════════════════
    // Split on semicolons (simple parser - works for our DDL)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== '' && !str_starts_with($s, '--') && !str_starts_with($s, '/*')
    );
    
    log_msg("Found " . count($statements) . " SQL statements to execute");
    log_msg("========================================");
    
    $executed = 0;
    $skipped = 0;
    
    foreach ($statements as $i => $statement) {
        // Skip comments
        if (preg_match('/^(--|\/\*|SET|DROP VIEW|DROP TRIGGER|DROP PROCEDURE)/', $statement)) {
            continue;
        }
        
        try {
            // Execute statement
            $conn->exec($statement);
            
            // Extract what was created/altered
            if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $statement, $m)) {
                log_msg("Created/verified table: {$m[1]}", 'success');
                $success[] = "Table: {$m[1]}";
            } elseif (preg_match('/ALTER TABLE (\w+) ADD (COLUMN|INDEX)/i', $statement, $m)) {
                log_msg("Altered table: {$m[1]}", 'success');
                $success[] = "Altered: {$m[1]}";
            } elseif (preg_match('/INSERT IGNORE INTO (\w+)/i', $statement, $m)) {
                log_msg("Inserted default data into: {$m[1]}", 'success');
                $success[] = "Data: {$m[1]}";
            } else {
                log_msg("Executed statement " . ($i + 1), 'success');
            }
            
            $executed++;
            
        } catch (PDOException $e) {
            $errMsg = $e->getMessage();
            
            // Check if it's a harmless "already exists" error
            if (str_contains($errMsg, 'Duplicate column') || 
                str_contains($errMsg, 'Duplicate key') ||
                str_contains($errMsg, 'already exists')) {
                $skipped++;
                log_msg("Skipped (already exists): statement " . ($i + 1), 'warning');
                $warnings[] = substr($errMsg, 0, 100);
            } else {
                log_msg("FAILED statement " . ($i + 1) . ": " . substr($errMsg, 0, 150), 'error');
                $errors[] = substr($errMsg, 0, 200);
            }
        }
    }
    
    log_msg("========================================");
    log_msg("Execution Summary:", 'info');
    log_msg("  Executed: {$executed}", 'success');
    log_msg("  Skipped: {$skipped}", 'warning');
    log_msg("  Errors: " . count($errors), count($errors) > 0 ? 'error' : 'success');
    
    // ══════════════════════════════════════════════════════════════
    // 3. VERIFY INSTALLATION
    // ══════════════════════════════════════════════════════════════
    log_msg("========================================");
    log_msg("Verifying installation...");
    
    $requiredTables = [
        'access_requests',
        'user_access_levels',
        'notification_preferences',
        'scheduler_runs'
    ];
    
    foreach ($requiredTables as $table) {
        $stmt = $conn->query("SHOW TABLES LIKE '{$table}'");
        if ($stmt->rowCount() > 0) {
            log_msg("Table '{$table}' exists", 'success');
        } else {
            log_msg("Table '{$table}' NOT FOUND", 'error');
            $errors[] = "Missing table: {$table}";
        }
    }
    
    // Check indexes
    log_msg("========================================");
    log_msg("Checking key indexes...");
    
    $indexChecks = [
        ['email_master', 'idx_email_lookup'],
        ['dob_master', 'idx_dob_month_day_lookup'],
        ['employee_master', 'idx_emp_dept_status'],
        ['notification_logs', 'idx_notif_emp_date'],
    ];
    
    foreach ($indexChecks as [$table, $index]) {
        $stmt = $conn->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$index}'");
        if ($stmt->rowCount() > 0) {
            log_msg("Index '{$index}' on '{$table}' exists", 'success');
        } else {
            log_msg("Index '{$index}' on '{$table}' missing", 'warning');
            $warnings[] = "Missing index: {$table}.{$index}";
        }
    }
    
    // ══════════════════════════════════════════════════════════════
    // 4. POST-INSTALLATION TASKS
    // ══════════════════════════════════════════════════════════════
    log_msg("========================================");
    log_msg("Running post-installation tasks...");
    
    // Grant SuperAdmins full access if not already set
    try {
        $stmt = $conn->exec("
            INSERT IGNORE INTO user_access_levels (user_id, access_type, access_value, granted_by)
            SELECT user_id, 'all', NULL, NULL
            FROM users
            WHERE role = 'SuperAdmin' AND is_active = 1
        ");
        log_msg("Granted 'all' access to SuperAdmins: {$stmt} records", 'success');
    } catch (PDOException $e) {
        log_msg("Could not grant SuperAdmin access: " . $e->getMessage(), 'warning');
    }
    
    // Create default notification preferences
    try {
        $stmt = $conn->exec("
            INSERT IGNORE INTO notification_preferences (user_id)
            SELECT user_id FROM users WHERE is_active = 1
        ");
        log_msg("Created notification preferences: {$stmt} records", 'success');
    } catch (PDOException $e) {
        log_msg("Could not create notification preferences: " . $e->getMessage(), 'warning');
    }
    
    // ══════════════════════════════════════════════════════════════
    // 5. FINAL SUMMARY
    // ══════════════════════════════════════════════════════════════
    $duration = number_format((microtime(true) - $startTime) * 1000, 2);
    
    log_msg("========================================");
    log_msg("Installation completed in {$duration}ms", 'success');
    log_msg("========================================");
    
    if (count($errors) === 0) {
        log_msg("✓ Installation successful!", 'success');
        log_msg("\nNEXT STEPS:");
        log_msg("1. Set up Windows Task Scheduler (see docs/SCHEDULER_SETUP.md)");
        log_msg("2. Configure access levels for users in Dashboard > Access Requests");
        log_msg("3. Test access filtering by logging in as different users");
        log_msg("4. Review System Health dashboard for any issues");
    } else {
        log_msg("✗ Installation completed with errors", 'error');
        log_msg("\nERRORS:");
        foreach ($errors as $err) {
            log_msg("  - {$err}", 'error');
        }
    }
    
    if (count($warnings) > 0) {
        log_msg("\nWARNINGS:");
        foreach (array_slice($warnings, 0, 5) as $warn) {
            log_msg("  - {$warn}", 'warning');
        }
    }
    
} catch (Throwable $e) {
    log_msg("FATAL ERROR: " . $e->getMessage(), 'error');
    log_msg("Trace: " . $e->getTraceAsString(), 'error');
    exit(1);
}

echo $isCli ? "\n" : "</pre></body></html>\n";
exit(count($errors) > 0 ? 1 : 0);
