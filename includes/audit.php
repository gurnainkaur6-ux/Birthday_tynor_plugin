<?php
/**
 * audit.php — lightweight audit-trail helper.
 *
 * Records who did what, when, and from where into the existing `audit_logs`
 * table. Every call is best-effort: a logging failure must never break the
 * action being audited.
 *
 *   audit_log($conn, 'UPDATE', 'employee_master', 'EMP_ID 202527',
 *             $oldArray, $newArray);
 *
 * action ∈ INSERT | UPDATE | DELETE | LOGIN | LOGOUT | EMAIL
 */

if (!function_exists('audit_log')) {
    function audit_log(
        PDO     $conn,
        string  $action,
        string  $table,
        ?string $recordId = null,
        ?array  $old = null,
        ?array  $new = null
    ): void {
        try {
            $stmt = $conn->prepare("
                INSERT INTO audit_logs
                    (table_name, record_id, action, old_values, new_values, performed_by, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $table,
                $recordId !== null ? substr($recordId, 0, 50) : '',
                $action,
                $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
                $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
                $_SESSION['admin_id'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? 'cli',
            ]);
        } catch (Throwable $e) {
            error_log('audit_log failed: ' . $e->getMessage());
        }
    }
}
