<?php
/**
 * access_control.php — User privilege-based record filtering
 * 
 * Implements row-level security: users only see employee records they have
 * permission to access based on their access level (all, department, plant,
 * specific employees, or none).
 * 
 * SuperAdmin always sees everything. Other roles are restricted by their
 * user_access_levels entries.
 * 
 * Usage:
 *   $sql = apply_access_filter($conn, $baseQuery, 'e');
 *   $employees = get_accessible_employees($conn, $userId);
 */

require_once __DIR__ . '/../config/config.php';

/**
 * CRITICAL FIX #1: Validate access_value to prevent SQL injection
 * 
 * Ensures access_value contains only expected values for each access_type.
 * This prevents attackers from storing malicious SQL in the database.
 */
if (!function_exists('validate_access_value')) {
    function validate_access_value(string $accessType, ?string $accessValue): bool
    {
        // Normalize the access type
        $accessType = strtolower(trim($accessType));
        $accessValue = trim($accessValue ?? '');
        
        switch ($accessType) {
            case 'all':
                // Must be empty for 'all' access
                return empty($accessValue);
                
            case 'none':
                // Must be empty for 'none' access
                return empty($accessValue);
                
            case 'department':
                // Department name - allow only alphanumeric, spaces, hyphens, underscores
                if (empty($accessValue) || mb_strlen($accessValue) > 100) {
                    return false;
                }
                // Validate characters
                return preg_match('/^[a-zA-Z0-9\s\-_\.]+$/u', $accessValue) === 1;
                
            case 'plant':
                // Plant ID - allow only alphanumeric and limited special chars
                if (empty($accessValue) || mb_strlen($accessValue) > 50) {
                    return false;
                }
                return preg_match('/^[a-zA-Z0-9\-_]+$/u', $accessValue) === 1;
                
            case 'employee':
                // Comma-separated employee IDs
                if (empty($accessValue) || mb_strlen($accessValue) > 5000) {
                    return false;
                }
                $empIds = array_map('trim', explode(',', $accessValue));
                if (count($empIds) > 1000) {
                    return false; // Limit to 1000 employees per access level
                }
                // Each ID should be alphanumeric
                foreach ($empIds as $id) {
                    if (empty($id) || !preg_match('/^[a-zA-Z0-9\-_.]+$/', $id) || mb_strlen($id) > 50) {
                        return false;
                    }
                }
                return true;
                
            default:
                return false;
        }
    }
}

if (!function_exists('get_user_access_level')) {
    /**
     * Get the user's highest access level.
     * 
     * @return array{type:string, value:?string} or null if no access
     */
    function get_user_access_level(PDO $conn, int $userId): ?array
    {
        // SuperAdmin always has 'all' access
        $stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();
        
        if ($role === 'SuperAdmin') {
            return ['type' => 'all', 'value' => null];
        }
        
        // Check user_access_levels table
        $stmt = $conn->prepare("
            SELECT access_type, access_value
            FROM user_access_levels
            WHERE user_id = ? 
              AND is_active = 1
              AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY 
                CASE access_type
                    WHEN 'all' THEN 1
                    WHEN 'department' THEN 2
                    WHEN 'plant' THEN 3
                    WHEN 'employee' THEN 4
                    ELSE 5
                END
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            // No explicit access level = restricted to none
            return ['type' => 'none', 'value' => null];
        }
        
        return [
            'type' => $row['access_type'],
            'value' => $row['access_value']
        ];
    }
}

if (!function_exists('apply_access_filter')) {
    /**
     * Apply user access restrictions to a SQL WHERE clause.
     * 
     * @param PDO $conn Database connection
     * @param int $userId User ID to check access for
     * @param string $tableAlias Alias of employee_master in query (e.g., 'e', 'emp')
     * @return string SQL WHERE condition to append (without 'WHERE' keyword)
     */
    function apply_access_filter(PDO $conn, int $userId, string $tableAlias = 'e'): string
    {
        $access = get_user_access_level($conn, $userId);
        
        if (!$access || $access['type'] === 'none') {
            // No access - return impossible condition
            return "1 = 0";
        }
        
        if ($access['type'] === 'all') {
            // Full access - no restriction
            return "1 = 1";
        }
        
        $t = $conn->quote($tableAlias);
        
        switch ($access['type']) {
            case 'department':
                if (!$access['value']) {
                    return "1 = 0";
                }
                $dept = $conn->quote($access['value']);
                return "{$t}.department_name = {$dept}";
                
            case 'plant':
                if (!$access['value']) {
                    return "1 = 0";
                }
                $plant = $conn->quote($access['value']);
                return "{$t}.plant_id = {$plant}";
                
            case 'employee':
                if (!$access['value']) {
                    return "1 = 0";
                }
                // access_value contains comma-separated emp_ids
                $empIds = array_map('trim', explode(',', $access['value']));
                $empIds = array_filter($empIds);
                if (empty($empIds)) {
                    return "1 = 0";
                }
                $quoted = array_map([$conn, 'quote'], $empIds);
                return "{$t}.emp_id IN (" . implode(',', $quoted) . ")";
                
            default:
                return "1 = 0";
        }
    }
}

if (!function_exists('user_can_access_employee')) {
    /**
     * Check if a user can access a specific employee record.
     * 
     * @param PDO $conn Database connection
     * @param int $userId User ID
     * @param string $empId Employee ID to check
     * @return bool True if user can access this employee
     */
    function user_can_access_employee(PDO $conn, int $userId, string $empId): bool
    {
        $access = get_user_access_level($conn, $userId);
        
        if (!$access || $access['type'] === 'none') {
            return false;
        }
        
        if ($access['type'] === 'all') {
            return true;
        }
        
        // Fetch employee details
        $stmt = $conn->prepare("
            SELECT department_name, plant_id
            FROM employee_master
            WHERE emp_id = ? AND is_deleted = 0
        ");
        $stmt->execute([$empId]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$emp) {
            return false;
        }
        
        switch ($access['type']) {
            case 'department':
                return $emp['department_name'] === $access['value'];
                
            case 'plant':
                return $emp['plant_id'] === $access['value'];
                
            case 'employee':
                $empIds = array_map('trim', explode(',', $access['value']));
                return in_array($empId, $empIds, true);
                
            default:
                return false;
        }
    }
}

if (!function_exists('get_accessible_employee_ids')) {
    /**
     * Get list of all employee IDs the user can access.
     * WARNING: For 'all' access, returns empty array (meaning all).
     * 
     * @param PDO $conn Database connection
     * @param int $userId User ID
     * @return array Array of emp_ids, or empty array if access level is 'all'
     */
    function get_accessible_employee_ids(PDO $conn, int $userId): array
    {
        $access = get_user_access_level($conn, $userId);
        
        if (!$access || $access['type'] === 'none') {
            return [];
        }
        
        if ($access['type'] === 'all') {
            // Empty array signals "all employees" - don't enumerate
            return [];
        }
        
        $filter = apply_access_filter($conn, $userId, 'e');
        
        $sql = "SELECT e.emp_id 
                FROM employee_master e 
                WHERE e.is_deleted = 0 AND ({$filter})";
        
        $stmt = $conn->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

if (!function_exists('format_access_level')) {
    /**
     * Human-readable description of access level.
     */
    function format_access_level(?array $access): string
    {
        if (!$access) {
            return 'No access';
        }
        
        switch ($access['type']) {
            case 'all':
                return 'All employees';
            case 'department':
                return 'Department: ' . ($access['value'] ?? 'Unknown');
            case 'plant':
                return 'Plant: ' . ($access['value'] ?? 'Unknown');
            case 'employee':
                $count = count(array_filter(explode(',', $access['value'] ?? '')));
                return "Specific employees ({$count})";
            case 'none':
            default:
                return 'No access';
        }
    }
}
