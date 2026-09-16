<?php
/**
 * fix_common_issues.php — Automatic fixes for common installation issues
 * 
 * This script automatically fixes common issues found during validation:
 * - Grants SuperAdmins full access
 * - Creates missing notification preferences
 * - Ensures birthday_enabled column exists
 * - Verifies foreign key constraints
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

echo $isCli ? "\n" : "<!DOCTYPE html><html><head><title>Fix Common Issues</title><style>body{font-family:sans-serif;max-width:800px;margin:2rem auto;padding:1rem;}pre{background:#f1f5f9;padding:1rem;border-radius:4px;}.ok{color:#059669;}.error{color:#dc2626;}</style></head><body><h1>Fixing Common Issues</h1><pre>\n";

$fixed = 0;
$errors = 0;

function fix($description, $sqlOrCallback) {
    global $conn, $fixed, $errors, $isCli;
    
    try {
        if (is_callable($sqlOrCallback)) {
            $result = $sqlOrCallback();
        } else {
            $result = $conn->exec($sqlOrCallback);
        }
        
        $fixed++;
        echo $isCli ? "✓ " : "<span class='ok'>✓</span> ";
        echo "$description\n";
        return $result;
    } catch (PDOException $e) {
        $errors++;
        echo $isCli ? "✗ " : "<span class='error'>✗</span> ";
        echo "$description - ERROR: " . $e->getMessage() . "\n";
        return false;
    }
}

echo "Starting fixes at " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 70) . "\n\n";

// ══════════════════════════════════════════════════════════════════
// 1. GRANT SUPERADMINS FULL ACCESS
// ══════════════════════════════════════════════════════════════════
echo "1. Ensuring SuperAdmins have full access...\n";

$result = fix("Grant 'all' access to SuperAdmins", "
    INSERT IGNORE INTO user_access_levels (user_id, access_type, access_value, granted_by)
    SELECT user_id, 'all', NULL, NULL
    FROM users
    WHERE role = 'SuperAdmin' AND is_active = 1
");

echo "   → $result SuperAdmin(s) granted access\n\n";

// ══════════════════════════════════════════════════════════════════
// 2. CREATE NOTIFICATION PREFERENCES
// ══════════════════════════════════════════════════════════════════
echo "2. Creating missing notification preferences...\n";

$result = fix("Create notification preferences for all users", "
    INSERT IGNORE INTO notification_preferences (user_id)
    SELECT user_id FROM users WHERE is_active = 1
");

echo "   → $result preference record(s) created\n\n";

// ══════════════════════════════════════════════════════════════════
// 3. ENSURE BIRTHDAY_ENABLED COLUMN EXISTS
// ══════════════════════════════════════════════════════════════════
echo "3. Checking birthday_enabled column...\n";

fix("Add birthday_enabled column if missing", "
    ALTER TABLE employee_master
    ADD COLUMN IF NOT EXISTS birthday_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER status
");

echo "\n";

// ══════════════════════════════════════════════════════════════════
// 4. UPDATE APP SETTINGS DEFAULTS
// ══════════════════════════════════════════════════════════════════
echo "4. Setting default app settings...\n";

fix("Set default active_birthday_template", "
    INSERT INTO app_settings (setting_key, setting_value) VALUES ('active_birthday_template', '1')
    ON DUPLICATE KEY UPDATE setting_key = setting_key
");

fix("Set default attach_poster", "
    INSERT INTO app_settings (setting_key, setting_value) VALUES ('attach_poster', '1')
    ON DUPLICATE KEY UPDATE setting_key = setting_key
");

fix("Set default send_broadcast", "
    INSERT INTO app_settings (setting_key, setting_value) VALUES ('send_broadcast', '0')
    ON DUPLICATE KEY UPDATE setting_key = setting_key
");

echo "\n";

// ══════════════════════════════════════════════════════════════════
// 5. CLEAN UP ORPHANED RECORDS
// ══════════════════════════════════════════════════════════════════
echo "5. Cleaning up orphaned records...\n";

// Delete access requests for deleted users
$result = fix("Remove access requests for deleted users", "
    DELETE FROM access_requests
    WHERE user_id NOT IN (SELECT user_id FROM users)
");
echo "   → $result orphaned access request(s) removed\n";

// Delete access levels for deleted users
$result = fix("Remove access levels for deleted users", "
    DELETE FROM user_access_levels
    WHERE user_id NOT IN (SELECT user_id FROM users)
");
echo "   → $result orphaned access level(s) removed\n";

echo "\n";

// ══════════════════════════════════════════════════════════════════
// 6. VERIFY CRITICAL INDEXES
// ══════════════════════════════════════════════════════════════════
echo "6. Verifying critical indexes...\n";

$indexes = [
    ['email_master', 'idx_email_lookup', 'official_email'],
    ['dob_master', 'idx_dob_month_day_lookup', 'birth_month_day, emp_id'],
];

foreach ($indexes as [$table, $indexName, $columns]) {
    fix("Add index $table.$indexName", "
        ALTER TABLE $table ADD INDEX IF NOT EXISTS $indexName ($columns)
    ");
}

echo "\n";

// ══════════════════════════════════════════════════════════════════
// SUMMARY
// ══════════════════════════════════════════════════════════════════
echo str_repeat("=", 70) . "\n";
echo "FIXES APPLIED SUMMARY\n";
echo str_repeat("=", 70) . "\n";
echo "Fixed: $fixed\n";
echo "Errors: $errors\n";
echo "\n";

if ($errors === 0) {
    echo $isCli ? "✓ " : "<span class='ok'>✓</span> ";
    echo "ALL FIXES APPLIED SUCCESSFULLY!\n";
} else {
    echo $isCli ? "✗ " : "<span class='error'>✗</span> ";
    echo "SOME FIXES FAILED - Review errors above\n";
}

echo "\nCompleted at " . date('Y-m-d H:i:s') . "\n";

echo $isCli ? "\n" : "</pre><p><a href='validate_installation.php'>Run Validation Again</a></p></body></html>\n";

exit($errors > 0 ? 1 : 0);
