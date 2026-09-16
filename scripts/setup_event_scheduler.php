<?php
/**
 * setup_event_scheduler.php — Setup MySQL Event Scheduler for automatic birthday emails
 * 
 * This script:
 * 1. Enables the MySQL Event Scheduler
 * 2. Imports the birthday event scheduler SQL
 * 3. Verifies the event was created
 * 4. Shows configuration instructions
 * 
 * Usage: php scripts/setup_event_scheduler.php
 */

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    session_start();
    $isAdmin = ($_SESSION['admin_role'] ?? '') === 'SuperAdmin';
    if (!$isAdmin) {
        http_response_code(403);
        die("Access denied.");
    }
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo $isCli ? "\n" : "<!DOCTYPE html><html><head><title>Event Scheduler Setup</title><style>body{font-family:sans-serif;max-width:900px;margin:2rem auto;padding:1rem;}pre{background:#f1f5f9;padding:1rem;border-radius:4px;overflow-x:auto;}.ok{color:#059669;}.error{color:#dc2626;}.warn{color:#f59e0b;}</style></head><body><pre>\n";

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║        MySQL EVENT SCHEDULER SETUP FOR BIRTHDAY EMAILS        ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$step = 0;
$errors = [];
$warnings = [];

try {
    // ══════════════════════════════════════════════════════════════
    // STEP 1: Check Event Scheduler Status
    // ══════════════════════════════════════════════════════════════
    $step++;
    echo "[$step/5] Checking Event Scheduler status...\n";
    
    $stmt = $conn->query("SELECT @@global.event_scheduler as status");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $schedulerStatus = (int)$result['status'];
    
    if ($schedulerStatus === 1) {
        echo "  ✓ Event Scheduler is ENABLED\n\n";
    } else {
        echo "  ⚠ Event Scheduler is DISABLED\n";
        echo "  → Attempting to enable...\n";
        
        try {
            $conn->exec("SET GLOBAL event_scheduler = ON");
            echo "  ✓ Event Scheduler ENABLED\n\n";
        } catch (PDOException $e) {
            $warnings[] = "Could not enable Event Scheduler via SQL. May need manual setup in my.cnf";
            echo "  ✗ Cannot enable via SQL (check my.cnf)\n\n";
        }
    }
    
    // ══════════════════════════════════════════════════════════════
    // STEP 2: Import Event Scheduler SQL
    // ══════════════════════════════════════════════════════════════
    $step++;
    echo "[$step/5] Importing event scheduler schema...\n";
    
    $sqlFile = __DIR__ . '/../database/birthday_event_scheduler.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Execute statements (simple split)
    $statements = array_filter(
        array_map('trim', preg_split('/;(?=\s*(?:DROP|CREATE|SET|INSERT|ALTER|DELIMITER))/i', $sql)),
        fn($s) => !empty($s) && strpos($s, '/*') === false
    );
    
    $executed = 0;
    foreach ($statements as $stmt) {
        if (empty(trim($stmt)) || strpos($stmt, 'DELIMITER') !== false) continue;
        
        try {
            $conn->exec($stmt);
            $executed++;
        } catch (PDOException $e) {
            // Some statements might fail if objects exist, that's ok
            if (strpos($e->getMessage(), 'already exists') === false && 
                strpos($e->getMessage(), 'Syntax error') === false) {
                throw $e;
            }
        }
    }
    
    echo "  ✓ Executed $executed SQL statements\n";
    echo "  ✓ Event scheduler schema installed\n\n";
    
    // ══════════════════════════════════════════════════════════════
    // STEP 3: Verify Event Creation
    // ══════════════════════════════════════════════════════════════
    $step++;
    echo "[$step/5] Verifying event creation...\n";
    
    $stmt = $conn->query("
        SELECT EVENT_NAME, STATUS, EVENT_SCHEDULE
        FROM information_schema.EVENTS
        WHERE EVENT_SCHEMA = DATABASE()
        AND EVENT_NAME = 'evt_daily_birthday_notification'
    ");
    
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($event) {
        echo "  ✓ Event created: " . $event['EVENT_NAME'] . "\n";
        echo "  ✓ Status: " . $event['STATUS'] . "\n";
        echo "  ✓ Schedule: Daily at 12:00 AM\n\n";
    } else {
        throw new Exception("Event was not created. Check MySQL error logs.");
    }
    
    // ══════════════════════════════════════════════════════════════
    // STEP 4: Check Stored Procedures
    // ══════════════════════════════════════════════════════════════
    $step++;
    echo "[$step/5] Verifying stored procedures...\n";
    
    $procedures = [
        'sp_find_todays_birthdays',
        'sp_send_birthday_emails',
        'sp_send_broadcast_birthday_message',
        'sp_check_event_status',
        'sp_enable_event'
    ];
    
    $procedureCount = 0;
    foreach ($procedures as $proc) {
        $stmt = $conn->query("
            SELECT COUNT(*) as count
            FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()
            AND ROUTINE_NAME = '$proc'
            AND ROUTINE_TYPE = 'PROCEDURE'
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ((int)$result['count'] > 0) {
            echo "  ✓ $proc\n";
            $procedureCount++;
        }
    }
    
    echo "  → Total: $procedureCount / " . count($procedures) . " procedures\n\n";
    
    // ══════════════════════════════════════════════════════════════
    // STEP 5: Check Tables
    // ══════════════════════════════════════════════════════════════
    $step++;
    echo "[$step/5] Verifying database tables...\n";
    
    $tables = ['daily_birthday_queue', 'event_execution_log'];
    $tableCount = 0;
    
    foreach ($tables as $table) {
        $stmt = $conn->query("
            SELECT COUNT(*) as count
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '$table'
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ((int)$result['count'] > 0) {
            echo "  ✓ $table\n";
            $tableCount++;
        }
    }
    
    echo "  → Total: $tableCount / " . count($tables) . " tables\n\n";
    
    // ══════════════════════════════════════════════════════════════
    // SUMMARY
    // ══════════════════════════════════════════════════════════════
    echo str_repeat("═", 65) . "\n";
    echo "SETUP SUMMARY\n";
    echo str_repeat("═", 65) . "\n\n";
    
    echo "✓ Event Scheduler Status: " . ($schedulerStatus ? "ENABLED" : "DISABLED") . "\n";
    echo "✓ SQL Statements Executed: $executed\n";
    echo "✓ Event Created: evt_daily_birthday_notification\n";
    echo "✓ Procedures Created: $procedureCount / " . count($procedures) . "\n";
    echo "✓ Tables Created: $tableCount / " . count($tables) . "\n\n";
    
    if (!empty($warnings)) {
        echo "⚠ WARNINGS:\n";
        foreach ($warnings as $warning) {
            echo "  - $warning\n";
        }
        echo "\n";
    }
    
    echo "═" . str_repeat("═", 63) . "═\n";
    echo "HOW IT WORKS:\n";
    echo "═" . str_repeat("═", 63) . "═\n\n";
    
    echo "1. Every day at 12:00 AM, the event automatically triggers\n";
    echo "2. Event finds all employees with birthday TODAY\n";
    echo "3. Event logs birthday emails for each employee\n";
    echo "4. Event sends broadcast message to all users\n";
    echo "5. All activity is recorded in notification_logs table\n\n";
    
    echo "═" . str_repeat("═", 63) . "═\n";
    echo "MANUAL TESTING:\n";
    echo "═" . str_repeat("═", 63) . "═\n\n";
    
    echo "To test the event manually (don't wait for 12 AM):\n\n";
    echo "1. Create a test employee with birthday today:\n";
    echo "   INSERT INTO dob_master (emp_id, dob) VALUES ('TEST001', CURDATE());\n\n";
    
    echo "2. Run the event procedures manually:\n";
    echo "   CALL sp_find_todays_birthdays();\n";
    echo "   CALL sp_send_birthday_emails();\n";
    echo "   CALL sp_send_broadcast_birthday_message();\n\n";
    
    echo "3. Check results:\n";
    echo "   SELECT * FROM daily_birthday_queue;\n";
    echo "   SELECT * FROM notification_logs ORDER BY created_at DESC LIMIT 5;\n\n";
    
    echo "═" . str_repeat("═", 63) . "═\n";
    echo "ENABLE/DISABLE EVENT:\n";
    echo "═" . str_repeat("═", 63) . "═\n\n";
    
    echo "Enable the event:\n";
    echo "  CALL sp_enable_event('evt_daily_birthday_notification', 1);\n\n";
    
    echo "Disable the event:\n";
    echo "  CALL sp_enable_event('evt_daily_birthday_notification', 0);\n\n";
    
    echo "Check event status:\n";
    echo "  CALL sp_check_event_status();\n\n";
    
    echo "═" . str_repeat("═", 63) . "═\n";
    echo "CONFIGURATION:\n";
    echo "═" . str_repeat("═", 63) . "═\n\n";
    
    echo "Edit app_settings to control behavior:\n\n";
    
    echo "1. Enable/disable broadcast messages:\n";
    echo "   UPDATE app_settings SET setting_value = '1'\n";
    echo "   WHERE setting_key = 'send_broadcast';\n\n";
    
    echo "2. Enable/disable the event:\n";
    echo "   CALL sp_enable_event('evt_daily_birthday_notification', 1);\n\n";
    
    echo "═" . str_repeat("═", 63) . "═\n";
    echo "IMPORTANT NOTES:\n";
    echo "═" . str_repeat("═", 63) . "═\n\n";
    
    echo "• Event Scheduler must be enabled (check: SELECT @@global.event_scheduler;)\n";
    echo "• If Event Scheduler is OFF, add to my.cnf: event_scheduler=ON\n";
    echo "• Restart MySQL after editing my.cnf\n";
    echo "• Event runs automatically - no manual trigger needed\n";
    echo "• Check notification_logs table to see email history\n";
    echo "• No API needed - pure MySQL native solution\n\n";
    
    if ($schedulerStatus === 1 && $event && $procedureCount >= 3 && $tableCount >= 2) {
        echo str_repeat("═", 65) . "\n";
        echo $isCli ? "✓ SETUP COMPLETE! Event Scheduler is ready!\n" : "<span class='ok'>✓ SETUP COMPLETE! Event Scheduler is ready!</span>\n";
        echo str_repeat("═", 65) . "\n";
    } else {
        echo str_repeat("═", 65) . "\n";
        echo "⚠ Setup completed with some issues. Review above.\n";
        echo str_repeat("═", 65) . "\n";
    }
    
} catch (Exception $e) {
    echo "\n" . str_repeat("═", 65) . "\n";
    echo "ERROR\n";
    echo str_repeat("═", 65) . "\n";
    echo $e->getMessage() . "\n";
    echo str_repeat("═", 65) . "\n";
    exit(1);
}

echo "\n";
echo date('Y-m-d H:i:s') . " - Setup completed.\n";

echo $isCli ? "\n" : "</pre></body></html>\n";

exit(0);
