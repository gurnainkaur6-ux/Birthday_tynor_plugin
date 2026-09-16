<?php
/**
 * test_feature_access.php — SQL-backed per-user feature-access privilege layer.
 *
 * Exercises includes/rbac.php: feature_catalog(), role defaults, the SuperAdmin
 * bypass, fail-closed behaviour, and per-user overrides (user_feature_access)
 * winning over role defaults. The override checks create a throwaway user and
 * delete it again (cascade removes its rows) — no real account is touched.
 */
require_once __DIR__ . '/../includes/rbac.php';

function run_feature_access_tests(PDO $conn): void
{
    t_section('Feature access (SQL-backed per-user privileges)');

    // A user_id with no override rows -> pure role-default resolution.
    $NONE = 2147483000;

    // ── Catalog sanity ──────────────────────────────────────────────
    $cat = feature_catalog();
    t_ok(isset($cat['dashboard'], $cat['employees'], $cat['approvals'], $cat['channels'], $cat['logs']),
        'catalog contains the expected grantable features');
    t_ok(!isset($cat['settings']) && !isset($cat['users']) && !isset($cat['smtp']),
        'settings / users / smtp are NOT grantable (admin-exclusive)');

    // ── SuperAdmin bypass (never locked out) ────────────────────────
    t_ok(user_feature_enabled($conn, $NONE, 'SuperAdmin', 'approvals'), 'SuperAdmin has every feature');
    t_ok(user_feature_enabled($conn, $NONE, 'SuperAdmin', 'channels'),  'SuperAdmin has channels');

    // ── Viewer role defaults (dashboard + content, read-only) ───────
    t_ok(user_feature_enabled($conn, $NONE, 'Viewer', 'dashboard'),  'viewer default: dashboard enabled');
    t_ok(user_feature_enabled($conn, $NONE, 'Viewer', 'employees'),  'viewer default: employees enabled');
    t_ok(user_feature_enabled($conn, $NONE, 'Viewer', 'logs'),       'viewer default: send history enabled');
    t_ok(user_feature_enabled($conn, $NONE, 'Viewer', 'templates'),  'viewer default: email templates enabled');
    t_ok(user_feature_enabled($conn, $NONE, 'Viewer', 'channels'),   'viewer default: channels enabled (content)');
    t_ok(!user_feature_enabled($conn, $NONE, 'Viewer', 'approvals'), 'viewer default: approvals DISABLED');
    t_ok(!user_feature_enabled($conn, $NONE, 'Viewer', 'send'),      'viewer default: send DISABLED');
    t_ok(!user_feature_enabled($conn, $NONE, 'Viewer', 'audit'),     'viewer default: audit DISABLED');
    t_ok(!user_feature_enabled($conn, $NONE, 'Viewer', 'health'),    'viewer default: system health DISABLED');

    // ── Manager (Admin) role defaults (operational features) ────────
    t_ok(user_feature_enabled($conn, $NONE, 'Admin', 'approvals'), 'manager default: approvals enabled');
    t_ok(user_feature_enabled($conn, $NONE, 'Admin', 'send'),      'manager default: send enabled');
    t_ok(user_feature_enabled($conn, $NONE, 'Admin', 'audit'),     'manager default: audit enabled');
    t_ok(user_feature_enabled($conn, $NONE, 'Admin', 'health'),    'manager default: system health enabled');

    // ── Fail-closed ─────────────────────────────────────────────────
    t_ok(!user_feature_enabled($conn, $NONE, 'Viewer', 'does_not_exist'), 'unknown feature denied (fail-closed)');
    t_ok(!user_feature_enabled($conn, $NONE, null, 'dashboard'),          'no role -> denied');

    // ── Per-user overrides win over role defaults ───────────────────
    // Needs the user_feature_access table (upgrade_v4.sql); skip cleanly if absent.
    $hasTable = false;
    try { $conn->query("SELECT 1 FROM user_feature_access LIMIT 1"); $hasTable = true; }
    catch (Throwable $e) { $hasTable = false; }

    if (!$hasTable) {
        t_ok(true, 'SKIP override tests (user_feature_access table not present — run upgrade_v4.sql)');
        return;
    }

    $email = '__fa_test_' . bin2hex(random_bytes(4)) . '@local.test';
    $uid = 0;
    try {
        $conn->prepare("INSERT INTO users (full_name, email, password, role, is_active, status)
                        VALUES ('Feature Access Test', ?, '!', 'Viewer', 1, 'approved')")->execute([$email]);
        $uid = (int) $conn->lastInsertId();

        // Enable a feature the Viewer does NOT get by default, and disable one it
        // DOES get by default. Insert BOTH before the first read so the per-request
        // override cache in user_feature_overrides() sees them.
        $conn->prepare("INSERT INTO user_feature_access (user_id, feature_key, is_enabled, updated_by)
                        VALUES (?, 'approvals', 1, NULL), (?, 'dashboard', 0, NULL)")
             ->execute([$uid, $uid]);

        t_ok(user_feature_enabled($conn, $uid, 'Viewer', 'approvals'),
            'override ENABLES a non-default feature for a viewer');
        t_ok(!user_feature_enabled($conn, $uid, 'Viewer', 'dashboard'),
            'override DISABLES a default feature for a viewer');
        t_ok(user_feature_enabled($conn, $uid, 'Viewer', 'channels'),
            'un-overridden feature still follows the role default');
        t_ok(user_feature_enabled($conn, $uid, 'SuperAdmin', 'dashboard'),
            'SuperAdmin ignores a disable override');
    } finally {
        if ($uid > 0) {
            // ON DELETE CASCADE removes the override rows too.
            $conn->prepare("DELETE FROM users WHERE user_id = ?")->execute([$uid]);
        }
    }
}

if (realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/lib.php';
    require_once __DIR__ . '/../config/database.php';
    run_feature_access_tests($conn);
    exit(t_summary());
}
