<?php
/**
 * validate_installation.php — Validate the v5 installation
 * 
 * Checks for:
 * - File existence
 * - PHP syntax errors
 * - Database tables
 * - Required functions
 * - Configuration issues
 * 
 * Run after deployment to ensure everything is working.
 */

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

echo $isCli ? "\n" : "<!DOCTYPE html><html><head><title>Installation Validation</title><style>body{font-family:sans-serif;max-width:900px;margin:2rem auto;padding:1rem;}pre{background:#f1f5f9;padding:1rem;border-radius:4px;}.ok{color:#059669;}.error{color:#dc2626;}.warning{color:#f59e0b;}</style></head><body><h1>Installation Validation Report</h1><pre>\n";

$errors = [];
$warnings = [];
$passed = 0;
$failed = 0;

function check($description, $condition, $errorMsg = '') {
    global $passed, $failed, $errors, $isCli;
    if ($condition) {
        $passed++;
        echo $isCli ? "✓ " : "<span class='ok'>✓</span> ";
        echo $description . "\n";
        return true;
    } else {
        $failed++;
        echo $isCli ? "✗ " : "<span class='error'>✗</span> ";
        echo $description . "\n";
        if ($errorMsg) {
            $errors[] = $errorMsg;
            echo "  ERROR: " . $errorMsg . "\n";
        }
        return false;
    }
}

function warn($message) {
    global $warnings, $isCli;
    $warnings[] = $message;
    echo $isCli ? "⚠ " : "<span class='warning'>⚠</span> ";
    echo $message . "\n";
}

echo "Starting validation at " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 70) . "\n\n";

// ══════════════════════════════════════════════════════════════════
// 1. FILE EXISTENCE CHECKS
// ══════════════════════════════════════════════════════════════════
echo "FILE EXISTENCE CHECKS\n";
echo str_repeat("-", 70) . "\n";

$requiredFiles = [
    'database/upgrade_v5_access_control.sql',
    'cron/automated_scheduler.php',
    'includes/access_control.php',
    'includes/access_notifications.php',
    'dashboard/access_requests.php',
    'dashboard/scheduler.php',
    'api/scheduler_api.php',
    'scripts/install_v5_access_control.php',
    'assets/js/scheduler-monitor.js',
    'assets/css/scheduler-widget.css',
];

foreach ($requiredFiles as $file) {
    $path = __DIR__ . '/../' . $file;
    check("File exists: $file", file_exists($path), "File not found: $file");
}

echo "\n";

// ══════════════════════════════════════════════════════════════════
// 2. DATABASE TABLE CHECKS
// ══════════════════════════════════════════════════════════════════
echo "DATABASE TABLE CHECKS\n";
echo str_repeat("-", 70) . "\n";

$requiredTables = [
    'access_requests',
    'user_access_levels',
    'notification_preferences',
    'scheduler_runs',
    'employee_master',
    'users',
    'notification_logs',
];

foreach ($requiredTables as $table) {
    try {
        $stmt = $conn->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;
        check("Table exists: $table", $exists, "Table missing: $table");
    } catch (PDOException $e) {
        check("Table exists: $table", false, "Error checking table: " . $e->getMessage());
    }
}

echo "\n";

// ══════════════════════════════════════════════════════════════════
// 3. DATABASE INDEX CHECKS
// ══════════════════════════════════════════════════════════════════
echo "DATABASE INDEX CHECKS\n";
echo str_repeat("-", 70) . "\n";

$requiredIndexes = [
    ['email_master', 'idx_email_lookup'],
    ['dob_master', 'idx_dob_month_day_lookup'],
    ['employee_master', 'idx_emp_dept_status'],
    ['notification_logs', 'idx_notif_emp_date'],
];

foreach ($requiredIndexes as [$table, $index]) {
    try {
        $stmt = $conn->query("SHOW INDEX FROM $table WHERE Key_name = '$index'");
        $exists = $stmt->rowCount() > 0;
        if ($exists) {
            check("Index exists: $table.$index", true);
        } else {
            warn("Index missing: $table.$index (performance may be affected)");
        }
    } catch (PDOException $e) {
        warn("Cannot check index: $table.$index - " . $e->getMessage());
    }
}

echo "\n";

// ══════════════════════════════════════════════════════════════════
// 4. FUNCTION AVAILABILITY CHECKS
// ══════════════════════════════════════════════════════════════════
echo "FUNCTION AVAILABILITY CHECKS\n";
echo str_repeat("-", 70) . "\n";

// Load required files
require_once __DIR__ . '/../includes/access_control.php';
require_once __DIR__ . '/../includes/access_notifications.php';
require_once __DIR__ . '/../includes/settings.php';

$requiredFunctions = [
    'get_user_access_level',
    'apply_access_filter',
    'user_can_access_employee',
    'notify_admins_access_request',
    'notify_user_access_decision',
    'app_setting',
    'app_setting_set',
];

foreach ($requiredFunctions as $func) {
    check("Function exists: $func", function_exists($func), "Function not found: $func");
}

echo "\n";

// ══════════════════════════════════════════════════════════════════
// 5. CONFIGURATION CHECKS
// ══════════════════════════════════════════════════════════════════
echo "CONFIGURATION CHECKS\n";
echo str_repeat("-", 70) . "\n";

check("BASE_URL defined", defined('BASE_URL'), "BASE_URL not defined");
check("SMTP_HOST configured", getenv('SMTP_HOST') !== false && getenv('SMTP_HOST') !== '', "SMTP not configured");
check("Database connection", $conn instanceof PDO, "Database connection failed");

// Check if PHPMailer is available
$autoload = __DIR__ . '/../vendor/autoload.php';
check("PHPMailer installed", file_exists($autoload), "Run: composer require phpmailer/phpmailer");

echo "\n";

// ══════════════════════════════════════════════════════════════════
// 6. SECURITY CHECKS
// ══════════════════════════════════════════════════════════════════
echo "SECURITY CHECKS\n";
echo str_repeat("-", 70) . "\n";

// Check for SQL injection fix
$schedulerFile = __DIR__ . '/../cron/send_birthday_notification.php';
$content = file_get_contents($schedulerFile);
$hasPreparedStatement = strpos($content, '$stmt = $conn->prepare(') !== false;
$hasUnsafeQuery = strpos($content, '$conn->query("SELECT') !== false;

check("SQL injection fix applied", $hasPreparedStatement && !$hasUnsafeQuery, "send_birthday_notification.php may have SQL injection vulnerability");

// Check CSRF protection
$csrfFile = __DIR__ . '/../includes/csrf.php';
check("CSRF protection available", file_exists($csrfFile), "CSRF protection not found");

// Check session security
$sessionSecure = ini_get('session.cookie_httponly') === '1';
if (!$sessionSecure) {
    warn("Session cookies not set to HttpOnly (security risk)");
}

echo "\n";

// ══════════════════════════════════════════════════════════════════
// 7. DATA INTEGRITY CHECKS
// ══════════════════════════════════════════════════════════════════
echo "DATA INTEGRITY CHECKS\n";
echo str_repeat("-", 70) . "\n";

try {
    // Check SuperAdmins have access
    $stmt = $conn->query("
        SELECT COUNT(*) as count
        FROM users u
        LEFT JOIN user_access_levels ual ON ual.user_id = u.user_id AND ual.access_type = 'all'
        WHERE u.role = 'SuperAdmin' AND u.is_active = 1 AND ual.id IS NULL
    ");
    $missingAccess = (int)$stmt->fetchColumn();
    if ($missingAccess > 0) {
        warn("$missingAccess SuperAdmin(s) missing 'all' access level - run: INSERT INTO user_access_levels (user_id, access_type) SELECT user_id, 'all' FROM users WHERE role='SuperAdmin'");
    } else {
        check("SuperAdmins have full access", true);
    }
} catch (PDOException $e) {
    warn("Cannot check SuperAdmin access: " . $e->getMessage());
}

try {
    // Check notification preferences
    $stmt = $conn->query("
        SELECT COUNT(*) as count
        FROM users u
        LEFT JOIN notification_preferences np ON np.user_id = u.user_id
        WHERE u.is_active = 1 AND np.id IS NULL
    ");
    $missingPrefs = (int)$stmt->fetchColumn();
    if ($missingPrefs > 0) {
        warn("$missingPrefs user(s) missing notification preferences - they will use defaults");
    } else {
        check("All users have notification preferences", true);
    }
} catch (PDOException $e) {
    warn("Cannot check notification preferences: " . $e->getMessage());
}

echo "\n";

// ══════════════════════════════════════════════════════════════════
// 8. SCHEDULER CONFIGURATION CHECK
// ══════════════════════════════════════════════════════════════════
echo "SCHEDULER CONFIGURATION CHECK\n";
echo str_repeat("-", 70) . "\n";

// Check for recent scheduler runs
try {
    $stmt = $conn->query("
        SELECT COUNT(*) as count
        FROM scheduler_runs
        WHERE run_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");
    $recentRuns = (int)$stmt->fetchColumn();
    
    if ($recentRuns > 0) {
        check("Scheduler has run recently ($recentRuns runs in last 7 days)", true);
    } else {
        warn("Scheduler has not run in the last 7 days - may not be configured");
        warn("See docs/SCHEDULER_SETUP.md for setup instructions");
    }
} catch (PDOException $e) {
    warn("Cannot check scheduler runs: " . $e->getMessage());
}

echo "\n";

// ══════════════════════════════════════════════════════════════════
// SUMMARY
// ══════════════════════════════════════════════════════════════════
echo str_repeat("=", 70) . "\n";
echo "VALIDATION SUMMARY\n";
echo str_repeat("=", 70) . "\n";

$total = $passed + $failed;
$percentage = $total > 0 ? ($passed / $total) * 100 : 0;

echo "Passed: $passed / $total (" . number_format($percentage, 1) . "%)\n";
echo "Failed: $failed\n";
echo "Warnings: " . count($warnings) . "\n";

echo "\n";

if ($failed === 0 && count($warnings) === 0) {
    echo $isCli ? "✓ " : "<span class='ok'>✓</span> ";
    echo "ALL CHECKS PASSED - Installation is valid!\n";
} elseif ($failed === 0) {
    echo $isCli ? "⚠ " : "<span class='warning'>⚠</span> ";
    echo "INSTALLATION VALID with " . count($warnings) . " warning(s)\n";
} else {
    echo $isCli ? "✗ " : "<span class='error'>✗</span> ";
    echo "INSTALLATION HAS ISSUES - Review errors above\n";
}

echo "\n";

if (!empty($errors)) {
    echo "CRITICAL ERRORS TO FIX:\n";
    foreach ($errors as $i => $error) {
        echo ($i + 1) . ". $error\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "WARNINGS (non-critical):\n";
    foreach ($warnings as $i => $warning) {
        echo ($i + 1) . ". $warning\n";
    }
    echo "\n";
}

echo "Validation completed at " . date('Y-m-d H:i:s') . "\n";

echo $isCli ? "\n" : "</pre></body></html>\n";

exit($failed > 0 ? 1 : 0);
