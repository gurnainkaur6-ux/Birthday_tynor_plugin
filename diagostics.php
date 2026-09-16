<?php
/**
 * diagostics.php — legacy diagnostics entry point.
 *
 * The old standalone version was PUBLIC and printed .env values (including
 * partial secrets), used stale hard-coded paths, and duplicated the maintained
 * System Health page. It is now an authenticated redirect to that page, which
 * performs the same checks safely (no secret values are ever displayed).
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth_check.php';          // must be signed in
require_once __DIR__ . '/includes/rbac.php';
require_cap('system.health');                                // managers/admins only

header('Location: ' . BASE_URL . '/dashboard/system_health.php');
exit;
