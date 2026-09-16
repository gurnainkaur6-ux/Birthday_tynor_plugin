<?php
/**
 * require_role.php — Role-based access control helper.
 *
 * Usage (after auth_check.php):
 *   require_once __DIR__ . '/../includes/require_role.php';
 *   require_role(['SuperAdmin', 'Admin']);
 */

require_once __DIR__ . '/../config/config.php';

function require_role(array $allowedRoles): void
{
    $role = $_SESSION['admin_role'] ?? null;

    if (!$role || !in_array($role, $allowedRoles, true)) {
        error_log(
            'RBAC: blocked role "' . ($role ?? 'none') . '" from '
            . ($_SERVER['REQUEST_URI'] ?? 'unknown')
        );
        http_response_code(403);
        die(
            '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;padding:2rem;">'
            . '<h2>403 &mdash; Access Denied</h2>'
            . '<p>Your role does not have permission to view this page.</p>'
            . '<p><a href="' . BASE_URL . '/dashboard/index.php">&larr; Back to dashboard</a></p>'
            . '</body></html>'
        );
    }
}