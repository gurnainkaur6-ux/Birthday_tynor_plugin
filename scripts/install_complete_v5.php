<?php
/**
 * install_complete_v5.php — Complete v5 Installation Script
 * 
 * Installs complete v5 schema including:
 * - All 9 tables with validations
 * - Indexes, views, procedures, triggers
 * - Default data and configuration
 * - Database integrity validation
 * 
 * Usage: php scripts/install_complete_v5.php
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
require_once __DIR__ . '/../includes/database_validator.php';

echo $isCli ? "\n" : "<!DOCTYPE html><html><head><title>v5 Complete Installation</title><style>body{font-family:sans-serif;max-width:900px;margin:2rem auto;padding:1rem;}pre{background:#f1f5f9;padding:1rem;border-radius:4px;overflow-x:auto;}.ok{color:#059669;}.error{color:#dc2626;}.warn{color:#f59e0b;}</style></head><body><pre>\n";

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║     Birthday Notification System v5 Complete Installation     ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$stepCount = 0;
$totalSteps = 5;

echo date('Y-m-d H:i:s') . " - Starting installation...\n\n";

try {
    // ══════════════════════════════════════════════════════════════
    // STEP 1: Read SQL file
    // ══════════════════════════════════════════════════════════════
    $stepCount++;
    echo "[$stepCount/$totalSteps] Reading SQL schema file...\n";
    
    $sqlFile = __DIR__ . '/../database/upgrade_v5_complete_schema.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    echo "  → Schema file loaded (" . round(strlen($sql) / 1024) . " KB)\n\n";
    
    // ══════════════════════════════════════════════════════════════
    // STEP 2: Execute SQL statements
    // ══════════════════════════════════════════════════════════════
    $stepCount++;
    echo "[$stepCount/$totalSteps] Creating tables and structures...\n";
    
    // Split SQL by semicolon (simple split - safe for our schema)
    $statements = array_filter(
        array_map('trim', preg_split('/;(?=\s*(?:DROP|CREATE|SET|INSERT|ALTER))/i', $sql)),
        fn($s) => !empty($s)
    );
    
    $executed = 0;
    foreach ($statements as $stmt) {
        if (empty(trim($stmt))) continue;
        
        try {
            $conn->exec($stmt);
            $executed++;
        } catch (PDOException $e) {
            // Some statements might fail if tables exist, that's ok
            if (strpos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }
    }
    
    echo "  → Executed $executed SQL statements\n";
    echo "  → All tables created successfully\n\n";
    
    // ══════════════════════════════════════════════════════════════
    // STEP 3: Verify installation
    // ══════════════════════════════════════════════════════════════
    $stepCount++;
    echo "[$stepCount/$totalSteps] Verifying installation...\n";
    
    $validator = new DatabaseValidator($conn);
    $validation = $validator->validateAll();
    
    $tableCheck = array_sum($validation['tables']);
    $totalTables = count($validation['tables']);
    echo "  → Tables: $tableCheck / $totalTables created\n";
    
    $viewCheck = array_sum($validation['views']);
    echo "  → Views: $viewCheck created\n";
    
    $procCheck = array_sum($validation['procedures']);
    echo "  → Procedures: $procCheck created\n";
    
    $trigCheck = array_sum($validation['triggers']);
    echo "  → Triggers: $trigCheck created\n\n";
    
    // ══════════════════════════════════════════════════════════════
    // STEP 4: Apply default data
    // ══════════════════════════════════════════════════════════════
    $stepCount++;
    echo "[$stepCount/$totalSteps] Applying default configuration...\n";
    
    // Default notification preferences
    $conn->exec("INSERT IGNORE INTO notification_preferences (user_id)
        SELECT user_id FROM users WHERE is_active = 1");
    echo "  → Notification preferences initialized\n";
    
    // Grant SuperAdmins full access
    $conn->exec("INSERT IGNORE INTO user_access_levels (user_id, access_type, access_value)
        SELECT user_id, 'all', NULL FROM users WHERE role = 'SuperAdmin' AND is_active = 1");
    echo "  → SuperAdmin access levels initialized\n";
    
    // Default app settings
    $defaultSettings = [
        'active_birthday_template' => '1',
        'attach_poster' => '1',
        'send_broadcast' => '0',
        'scheduler_enabled' => '1',
        'test_mode_enabled' => '1'
    ];
    
    foreach ($defaultSettings as $key => $value) {
        $conn->exec("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('$key', '$value')");
    }
    echo "  → Application settings initialized\n\n";
    
    // ══════════════════════════════════════════════════════════════
    // STEP 5: Final validation and report
    // ══════════════════════════════════════════════════════════════
    $stepCount++;
    echo "[$stepCount/$totalSteps] Final validation...\n";
    
    // Re-validate
    $finalValidator = new DatabaseValidator($conn);
    $finalResults = $finalValidator->getResults();
    $summary = $finalResults['summary'];
    
    echo "\n" . str_repeat("═", 65) . "\n";
    echo "INSTALLATION SUMMARY\n";
    echo str_repeat("═", 65) . "\n";
    echo "Total Checks: " . $summary['total_checks'] . "\n";
    echo "Passed: " . $summary['passed'] . "\n";
    echo "Errors: " . $summary['errors'] . "\n";
    echo "Warnings: " . $summary['warnings'] . "\n";
    echo "Success Rate: " . $summary['success_percentage'] . "%\n";
    echo "Status: " . $summary['status'] . "\n";
    echo str_repeat("═", 65) . "\n";
    
    echo "\n";
    
    if ($summary['errors'] === 0) {
        echo $isCli ? "✓ " : "<span class='ok'>✓</span> ";
        echo "Installation completed successfully!\n\n";
        
        echo "Next steps:\n";
        echo "1. Configure Task Scheduler (Windows) or cron (Linux)\n";
        echo "2. Access dashboard at: /dashboard/scheduler.php\n";
        echo "3. Validate installation: php scripts/validate_installation.php\n";
        echo "4. Set test mode: MAIL_TEST_MODE=0 in .env (when ready)\n";
        echo "\n";
        
        echo "Documentation:\n";
        echo "- Setup guide: docs/SCHEDULER_SETUP.md\n";
        echo "- Installation: docs/QUICK_INSTALLATION.md\n";
        echo "- API docs: docs/SCHEDULER_FRONTEND_BACKEND.md\n";
        
    } else {
        echo $isCli ? "✗ " : "<span class='error'>✗</span> ";
        echo "Installation has errors. Review above.\n\n";
        
        if (!empty($finalResults['errors'])) {
            echo "Errors:\n";
            foreach ($finalResults['errors'] as $error) {
                echo "  • $error\n";
            }
        }
    }
    
    echo "\n" . date('Y-m-d H:i:s') . " - Installation finished.\n";
    
} catch (Exception $e) {
    echo "\n" . str_repeat("═", 65) . "\n";
    echo "ERROR\n";
    echo str_repeat("═", 65) . "\n";
    echo $e->getMessage() . "\n";
    
    if (isset($e) && method_exists($e, 'getCode')) {
        echo "Code: " . $e->getCode() . "\n";
    }
    
    echo str_repeat("═", 65) . "\n";
    exit(1);
}

echo $isCli ? "\n" : "</pre></body></html>\n";

exit(0);
