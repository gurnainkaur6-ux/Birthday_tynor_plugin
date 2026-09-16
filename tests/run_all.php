<?php
/**
 * run_all.php — run the whole plugin test suite.
 *
 *   C:\xampp\php\php.exe tests/run_all.php
 *
 * Tests are pure-logic + DB-guard checks and never send real email.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../config/database.php';   // provides $conn

require_once __DIR__ . '/test_safe_redirect.php';
require_once __DIR__ . '/test_rbac.php';
require_once __DIR__ . '/test_feature_access.php';
require_once __DIR__ . '/test_send_guard.php';
require_once __DIR__ . '/test_csv_safety.php';

run_safe_redirect_tests();
run_rbac_tests();
run_feature_access_tests($conn);
run_send_guard_tests($conn);
run_csv_safety_tests();

exit(t_summary());
