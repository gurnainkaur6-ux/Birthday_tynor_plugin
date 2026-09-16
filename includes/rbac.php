<?php
/**
 * rbac.php — server-side role/capability authorization.
 *
 * This is the single source of truth for "who can do what". It is enforced
 * on the SERVER for every state-changing action and sensitive page — hiding a
 * button in the UI is never sufficient on its own.
 *
 * Usage (after auth_check.php, which guarantees a logged-in session):
 *   require_once __DIR__ . '/../includes/rbac.php';
 *   require_cap('employees.manage');          // dies 403 if not allowed
 *   if (user_can('communications.send')) { ...conditionally show a button... }
 *
 * Roles (stored in users.role):
 *   SuperAdmin  → "Administrator"        — full control incl. settings/SMTP/roles
 *   Admin       → "Communications manager"— employees, templates, prepare/approve/send
 *   Viewer      → "Viewer"               — read-only dashboards & history
 *
 * ERP role mapping (see docs/ERP_INTEGRATION.md) is applied at login time so
 * only these three internal roles ever reach this file.
 */

require_once __DIR__ . '/../config/config.php';

if (!defined('RBAC_LOADED')) {
    define('RBAC_LOADED', true);

    /**
     * Capability → list of roles allowed to use it.
     * Anything not listed is denied by default (fail-closed).
     */
    function rbac_matrix(): array
    {
        // Convenience groupings.
        $admin   = ['SuperAdmin'];                 // Administrator only
        $manager = ['SuperAdmin', 'Admin'];        // Administrator + Comms manager
        $all     = ['SuperAdmin', 'Admin', 'Viewer'];

        return [
            // Read-only — everyone who is logged in.
            'dashboard.view'        => $all,
            'employees.view'        => $all,
            'history.view'          => $all,
            'analytics.view'        => $all,

            // Employee data management.
            'employees.manage'      => $manager,   // add / edit / soft-delete / reactivate
            'employees.import'      => $manager,
            'employees.export'      => $manager,   // exports contain PII

            // Communication lifecycle.
            'communications.prepare'=> $manager,   // request approval, edit drafts
            'communications.approve'=> $admin,     // approve / reject — Administrator only (separation of duties)
            'communications.send'   => $manager,   // send now / retry / test sends
            'templates.manage'      => $manager,   // choose active template, toggle poster/broadcast

            // Governance & configuration — Administrator only.
            'audit.view'            => $manager,
            'system.health'         => $manager,
            'settings.manage'       => $admin,     // test/prod toggle, kill switch, sender identity
            'smtp.configure'        => $admin,
            'roles.manage'          => $admin,
        ];
    }

    /** The current user's internal role, or null if not logged in. */
    function current_role(): ?string
    {
        return $_SESSION['admin_role'] ?? null;
    }

    /** True if the current user's role is permitted the given capability. */
    function user_can(string $capability): bool
    {
        $role = current_role();
        if ($role === null) {
            return false;
        }
        $matrix = rbac_matrix();
        // Unknown capability → deny (fail-closed) and log the mistake.
        if (!array_key_exists($capability, $matrix)) {
            error_log("RBAC: unknown capability requested: {$capability}");
            return false;
        }
        return in_array($role, $matrix[$capability], true);
    }

    /**
     * Hard gate: allow the request to continue only if the user has the
     * capability, otherwise render a 403 page and stop. Logs every denial.
     */
    function require_cap(string $capability): void
    {
        if (user_can($capability)) {
            return;
        }
        $role = current_role() ?? 'none';
        error_log(sprintf(
            'RBAC DENY: role="%s" capability="%s" uri="%s" ip="%s"',
            $role,
            $capability,
            $_SERVER['REQUEST_URI'] ?? 'unknown',
            $_SERVER['REMOTE_ADDR'] ?? 'cli'
        ));
        rbac_render_403($capability);
    }

    // =================================================================
    //  Per-user FEATURE access (admin-granted enable/disable overrides)
    //
    //  A "feature" is a page/area of the app. Every feature has a set of
    //  ROLE DEFAULTS. On top of those, an administrator can explicitly
    //  ENABLE or DISABLE a feature for an individual user from
    //  Dashboard -> Users & access (stored in table user_feature_access,
    //  see database/upgrade_v4.sql). Resolution order:
    //
    //     1. SuperAdmin  -> always allowed (can never be locked out).
    //     2. explicit per-user override row (enabled/disabled) wins.
    //     3. otherwise    -> the feature's role default.
    //
    //  This governs PAGE ACCESS and NAVIGATION VISIBILITY. Sensitive
    //  state-changing actions inside a page keep their own require_cap()
    //  checks (separation of duties is preserved).
    // =================================================================

    /**
     * Catalog of grantable features, in navigation order.
     * key => [ label, nav group, lucide icon, default roles ].
     *
     * NOTE: Settings, SMTP and "Users & access" are deliberately NOT here —
     * they are administrator-exclusive and are never grantable, which is what
     * keeps the admin panel distinct from a normal user's panel.
     */
    function feature_catalog(): array
    {
        $all     = ['Viewer', 'Admin', 'SuperAdmin'];
        $manager = ['Admin', 'SuperAdmin'];

        return [
            // key        label              group             icon               default roles
            'dashboard' => ['Dashboard',       'Overview',       'layout-dashboard', $all],
            'employees' => ['Employees',       'People',         'users',            $all],
            'approvals' => ['Approvals',       'Communications', 'check-square',     $manager],
            'send'      => ['Send',            'Communications', 'send',             $manager],
            'logs'      => ['Send history',    'Communications', 'clock',            $all],
            'analytics' => ['Analytics',       'Communications', 'bar-chart-3',      $all],
            'templates' => ['Email templates', 'Content',        'mail',             $all],
            'channels'  => ['Channels',        'Content',        'radio',            $all],
            'audit'     => ['Audit log',       'Governance',     'shield',           $manager],
            'health'    => ['System health',   'Governance',     'activity',         $manager],
        ];
    }

    /** True if $role is in the feature's role-default set. */
    function feature_role_default(string $key, ?string $role): bool
    {
        if ($role === null) return false;
        $cat = feature_catalog();
        return isset($cat[$key]) && in_array($role, $cat[$key][3], true);
    }

    /**
     * All per-user override rows for one user as [feature_key => bool enabled].
     * Cached per-request. Fails OPEN to "no overrides" (so role defaults apply)
     * if the table does not exist yet or the query fails — the app keeps working
     * before upgrade_v4.sql is applied.
     */
    function user_feature_overrides(?PDO $conn, int $userId): array
    {
        static $cache = [];
        if ($userId <= 0) return [];
        if (array_key_exists($userId, $cache)) return $cache[$userId];

        $map = [];
        if ($conn instanceof PDO) {
            try {
                $st = $conn->prepare('SELECT feature_key, is_enabled FROM user_feature_access WHERE user_id = ?');
                $st->execute([$userId]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $map[$r['feature_key']] = ((int) $r['is_enabled'] === 1);
                }
            } catch (Throwable $e) {
                $map = [];   // table missing / query failed -> role defaults only
            }
        }
        $cache[$userId] = $map;
        return $map;
    }

    /**
     * Effective feature access for a specific user+role.
     * SuperAdmin bypasses everything; explicit override wins; else role default.
     */
    function user_feature_enabled(?PDO $conn, int $userId, ?string $role, string $key): bool
    {
        if ($role === 'SuperAdmin') return true;              // never lock out an admin
        if (!array_key_exists($key, feature_catalog())) return false;  // unknown -> deny
        $overrides = user_feature_overrides($conn, $userId);
        if (array_key_exists($key, $overrides)) return $overrides[$key];
        return feature_role_default($key, $role);
    }

    /** Convenience wrapper for the CURRENT session user. */
    function current_user_can_feature(?PDO $conn, string $key): bool
    {
        $role = current_role();
        $uid  = (int) ($_SESSION['admin_id'] ?? 0);
        if ($role === null || $uid <= 0) return false;
        return user_feature_enabled($conn, $uid, $role, $key);
    }

    /**
     * Hard gate for a whole feature/page. Renders a 403 and stops when the
     * current user may not access the feature. Logs every denial.
     */
    function require_feature(?PDO $conn, string $key): void
    {
        if (current_user_can_feature($conn, $key)) {
            return;
        }
        error_log(sprintf(
            'RBAC DENY (feature): role="%s" feature="%s" uri="%s" ip="%s"',
            current_role() ?? 'none',
            $key,
            $_SERVER['REQUEST_URI'] ?? 'unknown',
            $_SERVER['REMOTE_ADDR'] ?? 'cli'
        ));
        rbac_render_403('feature: ' . $key);
    }

    /**
     * Friendly 403 page. Always offers a safe way out (plugin dashboard and,
     * when configured, "Back to ERP") so a permission error is never a dead end.
     */
    function rbac_render_403(string $capability = ''): void
    {
        if (!headers_sent()) {
            http_response_code(403);
        }
        $B        = defined('BASE_URL') ? BASE_URL : '';
        $dash     = $B . '/dashboard/index.php';
        // Back to ERP link if the helper is available (Group 2).
        $erp      = function_exists('erp_back_url') ? erp_back_url() : '';
        $erpLink  = $erp !== ''
            ? '<a class="btn-secondary" href="' . htmlspecialchars($erp, ENT_QUOTES) . '">&larr; Back to ERP</a>'
            : '';
        $capNote  = $capability !== ''
            ? '<p style="color:#64748b;font-size:.85rem;">Required permission: <code>' . htmlspecialchars($capability, ENT_QUOTES) . '</code></p>'
            : '';
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>403 — Access denied</title>'
           . '<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f1f5f9;margin:0;'
           . 'display:flex;min-height:100vh;align-items:center;justify-content:center;color:#1e293b}'
           . '.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:2.25rem;max-width:460px;'
           . 'box-shadow:0 10px 30px rgba(2,6,23,.08);text-align:center}'
           . 'h1{font-size:1.4rem;margin:.25rem 0 .5rem}p{line-height:1.6;margin:.4rem 0}'
           . '.btn-secondary,.btn-primary{display:inline-block;margin:.35rem;padding:.6rem 1.1rem;border-radius:8px;'
           . 'text-decoration:none;font-weight:600;font-size:.9rem}'
           . '.btn-primary{background:#1a4fa0;color:#fff}.btn-secondary{background:#fff;color:#1a4fa0;border:1px solid #c7d7ef}</style>'
           . '</head><body><div class="card">'
           . '<div style="font-size:2.5rem">&#128274;</div>'
           . '<h1>Access denied</h1>'
           . '<p>Your role does not have permission to perform this action. '
           . 'If you believe this is a mistake, contact your administrator.</p>'
           . $capNote
           . '<div style="margin-top:1rem">'
           . '<a class="btn-primary" href="' . htmlspecialchars($dash, ENT_QUOTES) . '">Go to dashboard</a>'
           . $erpLink
           . '</div></div></body></html>';
        exit;
    }

    /**
     * HIGH FIX #17: Revoke all active sessions for a user when permissions change
     * 
     * This forces the user to re-authenticate when their permissions/roles change,
     * ensuring they cannot retain access with old session tokens.
     */
    if (!function_exists('revoke_user_sessions')) {
        function revoke_user_sessions(PDO $conn, int $userId, string $reason = ''): void
        {
            try {
                // Generate a revocation token
                $revocationToken = bin2hex(random_bytes(16));
                
                // Update user's session revocation token
                $stmt = $conn->prepare("
                    UPDATE users 
                    SET session_revocation_token = ?,
                        session_revocation_reason = ?,
                        last_role_change = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([$revocationToken, substr($reason, 0, 500), $userId]);
                
                // If sessions are DB-backed, clear them
                $stmt = $conn->prepare("DELETE FROM sessions WHERE user_id = ?");
                $stmt->execute([$userId]);
                
            } catch (Throwable $e) {
                error_log('Failed to revoke user sessions: ' . $e->getMessage());
            }
        }
    }

    /**
     * HIGH FIX #17: Check if current session has been revoked
     * 
     * This should be called on every request to detect permission changes.
     */
    if (!function_exists('check_session_revocation')) {
        function check_session_revocation(PDO $conn): bool
        {
            $userId = (int)($_SESSION['admin_id'] ?? 0);
            if ($userId <= 0) {
                return false;
            }
            
            try {
                $stmt = $conn->prepare("
                    SELECT session_revocation_token FROM users WHERE user_id = ?
                ");
                $stmt->execute([$userId]);
                $dbToken = $stmt->fetchColumn();
                
                $sessionToken = $_SESSION['_session_revocation_token'] ?? null;
                
                // If tokens don't match, session has been revoked
                if ($dbToken !== null && $sessionToken !== $dbToken) {
                    return true; // Session revoked
                }
                
            } catch (Throwable $e) {
                error_log('Failed to check session revocation: ' . $e->getMessage());
            }
            
            return false;
        }
    }
}
