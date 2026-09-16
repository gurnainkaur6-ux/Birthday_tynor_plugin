<?php
/**
 * test_send_guard.php — duplicate-send prevention, kill switch, test isolation.
 * Uses a synthetic emp_id and cleans up after itself. Sends NO email.
 */
require_once __DIR__ . '/../config/database.php';   // $conn
require_once __DIR__ . '/../includes/send_guard.php';

function run_send_guard_tests(PDO $conn): void
{
    t_section('Send guard: duplicate prevention');

    $emp = 'TEST_GUARD_' . getmypid() . '_' . random_int(1000, 9999);
    $cleanup = function () use ($conn, $emp) {
        $conn->prepare("DELETE FROM notification_logs WHERE emp_id = ?")->execute([$emp]);
    };
    $cleanup();

    // Remember env to restore later.
    $origTest = getenv('MAIL_TEST_MODE');
    $origSend = getenv('EMAIL_SENDING_ENABLED');

    // 1. First claim for (emp, personal, today) succeeds.
    $c1 = send_claim($conn, $emp, 'personal', 'x@example.com', 1, 'Subject');
    t_ok($c1['claimed'] === true && $c1['duplicate'] === false, 'first personal claim succeeds');
    t_ok(!empty($c1['reference']) && str_starts_with((string)$c1['reference'], 'COM-'), 'claim returns a COM- reference number');

    // 2. Second identical claim is rejected as a duplicate (no second send).
    $c2 = send_claim($conn, $emp, 'personal', 'x@example.com', 1, 'Subject');
    t_ok($c2['claimed'] === false && $c2['duplicate'] === true, 'second personal claim blocked as duplicate');

    // 3. A different email_type (anniversary) is an independent slot.
    $c3 = send_claim($conn, $emp, 'anniversary', 'x@example.com', null, 'Anniv');
    t_ok($c3['claimed'] === true, 'anniversary claim independent of personal');

    // 4. Exactly one 'personal' row exists for this employee today.
    $n = (int)$conn->query("SELECT COUNT(*) FROM notification_logs WHERE emp_id=" . $conn->quote($emp) . " AND email_type='personal' AND sent_on=CURDATE()")->fetchColumn();
    t_eq(1, $n, 'exactly one personal log row after duplicate attempt');

    // 5. A TEST send (email_type='test') does NOT collide with the personal slot.
    record_test_send($conn, $emp, 'tester@example.com', 1, '[TEST] Subject', 'sent', null, 'tok');
    $nt = (int)$conn->query("SELECT COUNT(*) FROM notification_logs WHERE emp_id=" . $conn->quote($emp) . " AND email_type='test' AND sent_on=CURDATE()")->fetchColumn();
    t_eq(1, $nt, 'test send recorded separately from production');
    $np = (int)$conn->query("SELECT COUNT(*) FROM notification_logs WHERE emp_id=" . $conn->quote($emp) . " AND email_type='personal' AND sent_on=CURDATE()")->fetchColumn();
    t_eq(1, $np, 'production personal slot unaffected by test send');

    t_section('Send guard: kill switch');
    putenv('MAIL_TEST_MODE=0'); putenv('EMAIL_SENDING_ENABLED=0');
    t_ok(production_send_blocked() === true, 'production blocked when sending disabled');
    putenv('MAIL_TEST_MODE=1'); putenv('EMAIL_SENDING_ENABLED=0');
    t_ok(production_send_blocked() === false, 'test mode always allowed (kill switch ignored)');
    putenv('MAIL_TEST_MODE=0'); putenv('EMAIL_SENDING_ENABLED=1');
    t_ok(production_send_blocked() === false, 'production allowed when explicitly enabled');

    t_section('Send guard: error categorization');
    t_eq('auth_failure', categorize_send_error('SMTP Error: Could not authenticate (535).'), 'auth failure categorized');
    t_eq('connection_timeout', categorize_send_error('Connection timed out'), 'timeout categorized');
    t_eq(null, categorize_send_error(null), 'no error → null category');

    // Restore env + clean up.
    putenv($origTest === false ? 'MAIL_TEST_MODE' : "MAIL_TEST_MODE={$origTest}");
    putenv($origSend === false ? 'EMAIL_SENDING_ENABLED' : "EMAIL_SENDING_ENABLED={$origSend}");
    $cleanup();
}

if (realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/lib.php';
    run_send_guard_tests($conn);
    exit(t_summary());
}
