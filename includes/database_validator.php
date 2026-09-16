<?php
/**
 * database_validator.php — Comprehensive database validation functions
 * 
 * Validates:
 * - Table existence and structure
 * - Column definitions
 * - Indexes
 * - Foreign key constraints
 * - Views, procedures, triggers
 * - Data integrity
 * - Performance
 */

class DatabaseValidator
{
    private $conn;
    private $database;
    private $errors = [];
    private $warnings = [];
    private $successes = [];
    
    public function __construct($pdo_connection, $database_name = null)
    {
        $this->conn = $pdo_connection;
        $this->database = $database_name ?: $this->getCurrentDatabase();
    }
    
    /**
     * Get current database name
     */
    private function getCurrentDatabase()
    {
        $stmt = $this->conn->query("SELECT DATABASE() as db");
        return $stmt->fetchColumn();
    }
    
    /**
     * Run complete validation
     */
    public function validateAll()
    {
        return [
            'tables' => $this->validateTables(),
            'columns' => $this->validateColumns(),
            'indexes' => $this->validateIndexes(),
            'constraints' => $this->validateConstraints(),
            'views' => $this->validateViews(),
            'procedures' => $this->validateProcedures(),
            'triggers' => $this->validateTriggers(),
            'data' => $this->validateData(),
            'integrity' => $this->validateIntegrity(),
            'performance' => $this->validatePerformance(),
            'summary' => $this->getSummary()
        ];
    }
    
    /**
     * Validate all required tables exist
     */
    public function validateTables()
    {
        $requiredTables = [
            'access_requests',
            'user_access_levels',
            'notification_preferences',
            'scheduler_runs',
            'scheduler_configuration',
            'notification_logs',
            'audit_logs',
            'app_settings',
            'api_audit_log'
        ];
        
        $results = [];
        
        foreach ($requiredTables as $table) {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) 
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ");
            $stmt->execute([$this->database, $table]);
            $exists = (int)$stmt->fetchColumn() > 0;
            
            if ($exists) {
                $this->success("Table exists: $table");
            } else {
                $this->error("Table missing: $table");
            }
            
            $results[$table] = $exists;
        }
        
        return $results;
    }
    
    /**
     * Validate table columns
     */
    public function validateColumns()
    {
        $tableColumns = [
            'access_requests' => [
                'id', 'user_id', 'feature_requested', 'access_type', 'access_value',
                'justification', 'status', 'requested_by', 'approved_by', 'requested_at',
                'responded_at', 'notes'
            ],
            'user_access_levels' => [
                'id', 'user_id', 'access_type', 'access_value', 'granted_by',
                'granted_at', 'revoked_at', 'is_active', 'notes'
            ],
            'notification_preferences' => [
                'id', 'user_id', 'birthday_notifications', 'access_request_notifications',
                'access_decision_notifications', 'system_notifications',
                'scheduler_failure_notifications', 'digest_frequency', 'notification_email'
            ],
            'scheduler_runs' => [
                'run_id', 'job_name', 'run_date', 'run_time', 'status',
                'employees_found', 'emails_sent', 'emails_failed', 'execution_time_ms',
                'error_message', 'error_code', 'completed_at', 'triggered_by'
            ]
        ];
        
        $results = [];
        
        foreach ($tableColumns as $table => $requiredColumns) {
            $stmt = $this->conn->prepare("
                SELECT COLUMN_NAME 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ");
            $stmt->execute([$this->database, $table]);
            $actualColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $missingColumns = array_diff($requiredColumns, $actualColumns);
            
            if (empty($missingColumns)) {
                $this->success("All columns exist in: $table");
                $results[$table] = true;
            } else {
                $this->error("Missing columns in $table: " . implode(', ', $missingColumns));
                $results[$table] = false;
            }
        }
        
        return $results;
    }
    
    /**
     * Validate indexes
     */
    public function validateIndexes()
    {
        $requiredIndexes = [
            'access_requests' => [
                'idx_access_user_status',
                'idx_access_status_date'
            ],
            'user_access_levels' => [
                'idx_user_access_type',
                'idx_access_active'
            ],
            'scheduler_runs' => [
                'idx_scheduler_date',
                'idx_scheduler_status'
            ],
            'notification_logs' => [
                'idx_notif_emp_date',
                'idx_notif_channel_status'
            ]
        ];
        
        $results = [];
        
        foreach ($requiredIndexes as $table => $indexes) {
            $results[$table] = [];
            
            foreach ($indexes as $index) {
                $stmt = $this->conn->prepare("
                    SELECT COUNT(*) 
                    FROM information_schema.STATISTICS 
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
                ");
                $stmt->execute([$this->database, $table, $index]);
                $exists = (int)$stmt->fetchColumn() > 0;
                
                if ($exists) {
                    $this->success("Index exists: $table.$index");
                } else {
                    $this->warning("Missing index: $table.$index (performance may be affected)");
                }
                
                $results[$table][$index] = $exists;
            }
        }
        
        return $results;
    }
    
    /**
     * Validate foreign key constraints
     */
    public function validateConstraints()
    {
        $stmt = $this->conn->prepare("
            SELECT 
                CONSTRAINT_NAME,
                TABLE_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME
        ");
        $stmt->execute([$this->database]);
        $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($constraints) > 0) {
            $this->success("Found " . count($constraints) . " foreign key constraints");
        } else {
            $this->warning("No foreign key constraints found");
        }
        
        return $constraints;
    }
    
    /**
     * Validate views
     */
    public function validateViews()
    {
        $requiredViews = [
            'v_pending_access_requests',
            'v_active_access_levels',
            'v_scheduler_health',
            'v_notification_delivery_status'
        ];
        
        $results = [];
        
        foreach ($requiredViews as $view) {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) 
                FROM information_schema.VIEWS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ");
            $stmt->execute([$this->database, $view]);
            $exists = (int)$stmt->fetchColumn() > 0;
            
            if ($exists) {
                $this->success("View exists: $view");
            } else {
                $this->warning("View missing: $view");
            }
            
            $results[$view] = $exists;
        }
        
        return $results;
    }
    
    /**
     * Validate stored procedures
     */
    public function validateProcedures()
    {
        $requiredProcedures = [
            'sp_validate_access_level',
            'sp_grant_user_access',
            'sp_revoke_user_access',
            'sp_log_notification',
            'sp_get_user_access_scope'
        ];
        
        $results = [];
        
        foreach ($requiredProcedures as $proc) {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) 
                FROM information_schema.ROUTINES 
                WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = ? AND ROUTINE_TYPE = 'PROCEDURE'
            ");
            $stmt->execute([$this->database, $proc]);
            $exists = (int)$stmt->fetchColumn() > 0;
            
            if ($exists) {
                $this->success("Procedure exists: $proc");
            } else {
                $this->warning("Procedure missing: $proc");
            }
            
            $results[$proc] = $exists;
        }
        
        return $results;
    }
    
    /**
     * Validate triggers
     */
    public function validateTriggers()
    {
        $requiredTriggers = [
            'trg_access_requests_validate_before_insert',
            'trg_access_requests_validate_before_update',
            'trg_notification_logs_validate_before_insert',
            'trg_notification_logs_validate_before_update',
            'trg_scheduler_runs_validate_before_insert',
            'trg_audit_logs_before_insert'
        ];
        
        $results = [];
        
        foreach ($requiredTriggers as $trigger) {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) 
                FROM information_schema.TRIGGERS 
                WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ?
            ");
            $stmt->execute([$this->database, $trigger]);
            $exists = (int)$stmt->fetchColumn() > 0;
            
            if ($exists) {
                $this->success("Trigger exists: $trigger");
            } else {
                $this->warning("Trigger missing: $trigger");
            }
            
            $results[$trigger] = $exists;
        }
        
        return $results;
    }
    
    /**
     * Validate data integrity
     */
    public function validateData()
    {
        $results = [];
        
        // Check notification preferences exist for all users
        try {
            $stmt = $this->conn->query("
                SELECT COUNT(*) as missing
                FROM users u
                LEFT JOIN notification_preferences np ON u.user_id = np.user_id
                WHERE u.is_active = 1 AND np.id IS NULL
            ");
            $missing = (int)$stmt->fetchColumn();
            
            if ($missing === 0) {
                $this->success("All active users have notification preferences");
                $results['notification_preferences'] = true;
            } else {
                $this->warning("$missing user(s) missing notification preferences");
                $results['notification_preferences'] = false;
            }
        } catch (Exception $e) {
            $this->warning("Cannot validate notification preferences: " . $e->getMessage());
            $results['notification_preferences'] = null;
        }
        
        // Check SuperAdmins have full access
        try {
            $stmt = $this->conn->query("
                SELECT COUNT(*) as count
                FROM users u
                LEFT JOIN user_access_levels ual ON ual.user_id = u.user_id AND ual.access_type = 'all'
                WHERE u.role = 'SuperAdmin' AND u.is_active = 1 AND ual.id IS NULL
            ");
            $missing = (int)$stmt->fetchColumn();
            
            if ($missing === 0) {
                $this->success("SuperAdmins have full access");
                $results['superadmin_access'] = true;
            } else {
                $this->warning("$missing SuperAdmin(s) missing 'all' access level");
                $results['superadmin_access'] = false;
            }
        } catch (Exception $e) {
            $this->warning("Cannot check SuperAdmin access: " . $e->getMessage());
            $results['superadmin_access'] = null;
        }
        
        return $results;
    }
    
    /**
     * Validate referential integrity
     */
    public function validateIntegrity()
    {
        $results = [];
        
        // Check for orphaned access_requests
        try {
            $stmt = $this->conn->query("
                SELECT COUNT(*) as orphaned
                FROM access_requests ar
                LEFT JOIN users u ON ar.user_id = u.user_id
                WHERE u.user_id IS NULL
            ");
            $orphaned = (int)$stmt->fetchColumn();
            $results['orphaned_access_requests'] = $orphaned;
            
            if ($orphaned === 0) {
                $this->success("No orphaned access_requests records");
            } else {
                $this->error("Found $orphaned orphaned access_requests records");
            }
        } catch (Exception $e) {
            $results['orphaned_access_requests'] = null;
        }
        
        // Check for orphaned access_levels
        try {
            $stmt = $this->conn->query("
                SELECT COUNT(*) as orphaned
                FROM user_access_levels ual
                LEFT JOIN users u ON ual.user_id = u.user_id
                WHERE u.user_id IS NULL
            ");
            $orphaned = (int)$stmt->fetchColumn();
            $results['orphaned_access_levels'] = $orphaned;
            
            if ($orphaned === 0) {
                $this->success("No orphaned user_access_levels records");
            } else {
                $this->error("Found $orphaned orphaned user_access_levels records");
            }
        } catch (Exception $e) {
            $results['orphaned_access_levels'] = null;
        }
        
        return $results;
    }
    
    /**
     * Validate performance metrics
     */
    public function validatePerformance()
    {
        $results = [];
        
        // Table sizes
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    TABLE_NAME,
                    TABLE_ROWS,
                    ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) as size_mb
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = ?
                  AND TABLE_NAME IN ('access_requests', 'user_access_levels', 'scheduler_runs', 'notification_logs')
                ORDER BY TABLE_ROWS DESC
            ");
            $stmt->execute([$this->database]);
            $sizes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $results['table_sizes'] = $sizes;
            $this->success("Table sizes analyzed");
        } catch (Exception $e) {
            $results['table_sizes'] = null;
        }
        
        return $results;
    }
    
    /**
     * Get validation summary
     */
    public function getSummary()
    {
        $total = count($this->successes) + count($this->errors) + count($this->warnings);
      $successPercent = $total > 0 ? ($this->successes / $total) * 100 : 0;
        return [
            'total_checks' => $total,
            'passed' => count($this->successes),
            'errors' => count($this->errors),
            'warnings' => count($this->warnings),
            'success_percentage' => round($successPercent, 1),
            'status' => count($this->errors) === 0 ? 'PASSED' : 'FAILED'
        ];
    }
    
    /**
     * Get all results
     */
    public function getResults()
    {
        return [
            'successes' => $this->successes,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'summary' => $this->getSummary()
        ];
    }
    
    /**
     * Helper: Record success
     */
    private function success($message)
    {
        $this->successes[] = $message;
    }
    
    /**
     * Helper: Record error
     */
    private function error($message)
    {
        $this->errors[] = $message;
    }
    
    /**
     * Helper: Record warning
     */
    private function warning($message)
    {
        $this->warnings[] = $message;
    }
}

// Export functions for backward compatibility
function validate_database_schema($conn, $database = null)
{
    $validator = new DatabaseValidator($conn, $database);
    return $validator->validateAll();
}

function check_table_exists($conn, $database, $table)
{
    $validator = new DatabaseValidator($conn, $database);
    $results = $validator->validateTables();
    return isset($results[$table]) ? $results[$table] : false;
}

function check_view_exists($conn, $database, $view)
{
    $validator = new DatabaseValidator($conn, $database);
    $results = $validator->validateViews();
    return isset($results[$view]) ? $results[$view] : false;
}

function check_procedure_exists($conn, $database, $procedure)
{
    $validator = new DatabaseValidator($conn, $database);
    $results = $validator->validateProcedures();
    return isset($results[$procedure]) ? $results[$procedure] : false;
}
