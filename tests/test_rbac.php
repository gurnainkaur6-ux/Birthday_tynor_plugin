<?php
/**
 * test_rbac.php — capability matrix enforcement (server-side authorization).
 */
require_once __DIR__ . '/../includes/rbac.php';

function run_rbac_tests(): void
{
    t_section('RBAC capability matrix');

    $as = function (string $role) { $_SESSION['admin_role'] = $role; };

    // Viewer: read-only.
    $as('Viewer');
    t_ok(user_can('dashboard.view'),        'viewer can view dashboard');
    t_ok(user_can('employees.view'),        'viewer can view employees');
    t_ok(!user_can('employees.manage'),     'viewer CANNOT manage employees');
    t_ok(!user_can('employees.export'),     'viewer CANNOT export PII');
    t_ok(!user_can('communications.send'),  'viewer CANNOT send');
    t_ok(!user_can('communications.approve'),'viewer CANNOT approve');
    t_ok(!user_can('settings.manage'),      'viewer CANNOT manage settings');

    // Admin = Communications manager.
    $as('Admin');
    t_ok(user_can('employees.manage'),       'manager can manage employees');
    t_ok(user_can('communications.prepare'), 'manager can request approval');
    t_ok(!user_can('communications.approve'),'manager CANNOT approve (separation of duties)');
    t_ok(user_can('communications.send'),    'manager can send/test');
    t_ok(user_can('templates.manage'),      'manager can manage templates');
    t_ok(user_can('audit.view'),            'manager can view audit');
    t_ok(!user_can('settings.manage'),      'manager CANNOT manage settings');
    t_ok(!user_can('smtp.configure'),       'manager CANNOT configure SMTP');
    t_ok(!user_can('roles.manage'),         'manager CANNOT manage roles');

    // SuperAdmin = Administrator (everything).
    $as('SuperAdmin');
    t_ok(user_can('settings.manage'),        'admin can manage settings');
    t_ok(user_can('smtp.configure'),         'admin can configure SMTP');
    t_ok(user_can('roles.manage'),           'admin can manage roles');
    t_ok(user_can('communications.approve'), 'admin can approve');
    t_ok(user_can('communications.send'),    'admin can send');

    // Fail-closed behaviour.
    t_ok(!user_can('does.not.exist'),       'unknown capability denied (fail-closed)');
    $_SESSION['admin_role'] = null;
    t_ok(!user_can('dashboard.view'),       'no role → denied');
}

if (realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/lib.php';
    run_rbac_tests();
    exit(t_summary());
}
