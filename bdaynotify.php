<?php
/**
 * bdaynotify.php — PLUGIN / INTEGRATION API
 * ============================================================================
 * Single include point that lets any existing PHP system use BDayNotify as a
 * reusable "plugin" — without the dashboard UI or a login session.
 *
 *   require_once '/path/to/tynor/bdaynotify.php';
 *
 *   $today = bdaynotify_todays_birthdays();      // who has a birthday today
 *   bdaynotify_send_birthday('EMP_ID 202527');   // send one card + poster
 *   $summary = bdaynotify_run_daily();            // send to everyone today
 *   bdaynotify_upsert_employee([...]);            // push an employee in
 *
 * Design notes for integrators:
 *   • Every public function is prefixed `bdaynotify_` so it never clashes with
 *     the host application's own functions.
 *   • All paths are resolved from this file (__DIR__), so it works no matter
 *     where the host app includes it from.
 *   • It reuses the SAME helpers as the dashboard and cron (mailer, templates,
 *     poster, logging) so behaviour — including Test Mode — is identical.
 *   • No output is produced on include; it only defines functions.
 * ============================================================================
 */

require_once __DIR__ . '/config/config.php';

if (!defined('BDAYNOTIFY_VERSION')) define('BDAYNOTIFY_VERSION', '1.2.0');

/**
 * Boot the plugin and return the shared PDO connection.
 * Safe to call repeatedly — the connection is created once and cached.
 */
function bdaynotify_boot(): PDO
{
    static $conn = null;
    if ($conn instanceof PDO) return $conn;

    require __DIR__ . '/config/database.php';   // defines $conn
    require_once __DIR__ . '/includes/email_templates.php';
    require_once __DIR__ . '/includes/poster_generator.php';
    require_once __DIR__ . '/includes/mailer.php';
    require_once __DIR__ . '/includes/settings.php';
    require_once __DIR__ . '/includes/send_guard.php';

    return $conn;
}

/** Return the plugin version string. */
function bdaynotify_version(): string
{
    return BDAYNOTIFY_VERSION;
}

/** Employees whose birthday is today (only those with a valid email). */
function bdaynotify_todays_birthdays(): array
{
    $conn = bdaynotify_boot();
    return $conn->query("
        SELECT emp_id, full_name, official_email, department_name,
               designation_name, photo_path, current_age, plant_name, dob_formatted
        FROM v_todays_birthdays
        WHERE official_email IS NOT NULL AND official_email <> ''
          AND emp_id IN (SELECT emp_id FROM employee_master WHERE birthday_enabled = 1)
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/** Employees with a birthday in the next $days days. */
function bdaynotify_upcoming_birthdays(int $days = 30): array
{
    $conn = bdaynotify_boot();
    $rows = $conn->query("SELECT * FROM v_upcoming_birthdays")->fetchAll(PDO::FETCH_ASSOC);
    if ($days >= 30) return $rows;
    return array_values(array_filter($rows, fn($r) => (int)($r['days_away'] ?? 999) <= $days));
}

/**
 * Send ONE birthday email (with poster attachment + open tracking) for an
 * employee. Honours Test Mode and writes to notification_logs, exactly like the
 * dashboard's "Send test" and the daily cron.
 *
 * @return array{ok:bool, simulated:bool, error:?string, recipient:string, intended:string, status:string}
 */
function bdaynotify_send_birthday(string $empId, ?int $templateId = null): array
{
    $conn = bdaynotify_boot();

    $stmt = $conn->prepare("SELECT * FROM v_employee_master_complete WHERE emp_id = ? LIMIT 1");
    $stmt->execute([$empId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        return ['ok' => false, 'simulated' => false, 'error' => "Employee not found: $empId",
                'recipient' => '', 'intended' => '', 'status' => 'failed'];
    }

    $name     = $emp['full_name'] ?: 'Team Member';
    $intended = trim($emp['official_email'] ?? '');
    $testMode = is_test_mode($conn);
    $recipient = $testMode
        ? (getenv('MAIL_TEST_RECIPIENT') ?: (getenv('SMTP_FROM_EMAIL') ?: getenv('SMTP_USERNAME')))
        : $intended;

    if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'simulated' => false, 'error' => 'No valid recipient email.',
                'recipient' => (string)$recipient, 'intended' => $intended, 'status' => 'failed'];
    }

    // Default to the ADMIN-CHOSEN active template (not random) so every send
    // matches what was previewed/approved.
    if ($templateId === null || $templateId < 1 || $templateId > 10) {
        $templateId = active_birthday_template($conn);
    }

    // Kill switch: never email a real employee in production unless enabled.
    if (production_send_blocked($conn)) {
        return ['ok' => false, 'simulated' => false,
                'error' => 'Production sending is disabled (kill switch).',
                'recipient' => $recipient, 'intended' => $intended, 'status' => 'skipped'];
    }

    $token   = bin2hex(random_bytes(16));
    $base    = defined('BASE_URL') ? BASE_URL : '';
    $pixel   = "<img src=\"{$base}/cron/track_open.php?token={$token}\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none\">";
    $notice  = $testMode
        ? "<div style=\"background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;font-family:Arial,sans-serif;font-size:13px;\">TEST MODE — intended recipient: " . htmlspecialchars($intended) . "</div>"
        : '';
    $subject = ($testMode ? '[TEST] ' : '') . '🎂 Happy Birthday, ' . $name . '!';
    $html    = $notice . getBirthdayEmailTemplate($templateId, $emp) . $pixel;

    // Atomic duplicate-send guard: reserve today's slot for this employee.
    // If another run already sent/claimed today, we skip WITHOUT sending.
    $claim = send_claim($conn, $empId, 'personal', $recipient, $templateId, $subject);
    if (!$claim['claimed']) {
        return [
            'ok' => false, 'simulated' => false,
            'error' => $claim['duplicate']
                ? 'Already sent today — duplicate prevented.'
                : ('Could not reserve send: ' . ($claim['error'] ?? 'database error')),
            'recipient' => $recipient, 'intended' => $intended,
            'status' => $claim['duplicate'] ? 'skipped' : 'failed',
        ];
    }

    // Poster (skipped gracefully if GD is unavailable or the admin turned it off).
    $tmp = '';
    if (app_setting($conn, 'attach_poster', '1') === '1' && function_exists('imagecreatetruecolor')) {
        $im = generateBirthdayPosterGD($conn, $empId);
        if ($im) {
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bday_' . preg_replace('/\W/', '', $empId) . '.png';
            imagepng($im, $tmp);
            imagedestroy($im);
        }
    }

    $res = send_app_email($recipient, $subject, $html,
        "Happy Birthday, {$name}!", $tmp ?: null, 'Happy_Birthday.png');
    if ($tmp && is_file($tmp)) @unlink($tmp);

    $status = $res['ok'] ? 'sent' : 'failed';
    send_finalize($conn, (int) $claim['log_id'], $status, $res['error'] ?? null, $token);

    return [
        'ok'        => $res['ok'],
        'simulated' => $res['simulated'] ?? false,
        'error'     => $res['error'] ?? null,
        'recipient' => $recipient,
        'intended'  => $intended,
        'status'    => $status,
    ];
}

/**
 * Run the daily job: send a birthday card to every employee whose birthday is
 * today. Returns a summary. Safe to call from the host system's own scheduler.
 *
 * @return array{total:int, sent:int, failed:int, details:array}
 */
function bdaynotify_run_daily(): array
{
    $throttle = (int)(getenv('MAIL_THROTTLE_MS') ?: 400);
    $out = ['total' => 0, 'sent' => 0, 'failed' => 0, 'details' => []];

    foreach (bdaynotify_todays_birthdays() as $emp) {
        $r = bdaynotify_send_birthday($emp['emp_id']);
        $out['total']++;
        $r['ok'] ? $out['sent']++ : $out['failed']++;
        $out['details'][] = ['emp_id' => $emp['emp_id'], 'name' => $emp['full_name'], 'status' => $r['status']];
        if ($throttle > 0) usleep($throttle * 1000);
    }
    return $out;
}

/**
 * Add or update an employee from the host system (e.g. sync from an HR module).
 * Only emp_id and full_name are required; everything else is optional.
 *
 * @param array $d Keys: emp_id, full_name, first_name, last_name, email, dob
 *                 (YYYY-MM-DD), department_name, designation_name, mobile_no,
 *                 status, plant_id, plant_name, plant_location, photo_path.
 * @return array{ok:bool, action:string, error:?string}
 */
function bdaynotify_upsert_employee(array $d): array
{
    $conn  = bdaynotify_boot();
    $empId = trim((string)($d['emp_id'] ?? ''));
    $name  = trim((string)($d['full_name'] ?? ''));
    if ($empId === '' || $name === '') {
        return ['ok' => false, 'action' => 'none', 'error' => 'emp_id and full_name are required.'];
    }

    $parts = preg_split('/\s+/', $name);
    $first = trim((string)($d['first_name'] ?? ($parts[0] ?? $name)));
    $last  = trim((string)($d['last_name']  ?? (count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '')));
    $status = strtolower((string)($d['status'] ?? 'active')) === 'inactive' ? 'inactive' : 'active';

    try {
        $conn->beginTransaction();

        // Resolve plant safely: only set a plant_id that exists (auto-create if
        // a name is supplied) — otherwise leave it NULL to respect the FK.
        $plantId = trim((string)($d['plant_id'] ?? ''));
        if ($plantId !== '') {
            $chk = $conn->prepare("SELECT 1 FROM plant_master WHERE plant_id = ?");
            $chk->execute([$plantId]);
            if (!$chk->fetchColumn()) {
                if (!empty($d['plant_name'])) {
                    $conn->prepare("INSERT INTO plant_master (plant_id, plant_name, plant_location, is_active)
                                    VALUES (?, ?, ?, 1)")
                         ->execute([$plantId, $d['plant_name'], $d['plant_location'] ?? 'Unassigned']);
                } else {
                    $plantId = ''; // unknown plant, no name → skip the FK
                }
            }
        }

        $exists = $conn->prepare("SELECT 1 FROM employee_master WHERE emp_id = ?");
        $exists->execute([$empId]);
        $action = $exists->fetchColumn() ? 'updated' : 'inserted';

        $conn->prepare("
            INSERT INTO employee_master
                (emp_id, full_name, first_name, last_name, department_name, designation_name,
                 mobile_no, status, plant_id, is_deleted)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE
                full_name=VALUES(full_name), first_name=VALUES(first_name), last_name=VALUES(last_name),
                department_name=VALUES(department_name), designation_name=VALUES(designation_name),
                mobile_no=VALUES(mobile_no), status=VALUES(status), plant_id=VALUES(plant_id), is_deleted=0
        ")->execute([
            $empId, $name, $first, $last,
            (string)($d['department_name'] ?? 'General'),
            (string)($d['designation_name'] ?? 'Employee'),
            (string)($d['mobile_no'] ?? ''), $status,
            $plantId !== '' ? $plantId : null,
        ]);

        if (!empty($d['dob'])) {
            $conn->prepare("INSERT INTO dob_master (dob_id, emp_id, date_of_birth) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE date_of_birth=VALUES(date_of_birth)")
                 ->execute(['DOB_' . $empId, $empId, $d['dob']]);
        }
        if (!empty($d['email'])) {
            $conn->prepare("INSERT INTO email_master (email_id, emp_id, official_email) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE official_email=VALUES(official_email)")
                 ->execute(['EMAIL_' . $empId, $empId, $d['email']]);
        }
        if (!empty($d['photo_path'])) {
            $conn->prepare("INSERT INTO photo_master (photo_id, emp_id, photo_path) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE photo_path=VALUES(photo_path)")
                 ->execute(['PHOTO_' . $empId, $empId, $d['photo_path']]);
        }

        $conn->commit();
        return ['ok' => true, 'action' => $action, 'error' => null];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        return ['ok' => false, 'action' => 'none', 'error' => $e->getMessage()];
    }
}

/** Internal: record a send attempt in notification_logs (same shape as cron). */
function bdaynotify_log_attempt(PDO $conn, ?string $empId, string $to, int $templateId,
                                string $subject, string $status, ?string $error, string $token): void
{
    try {
        $conn->prepare("
            INSERT INTO notification_logs
                (emp_id, channel, recipient_address, email_type, provider_name, template_id, subject,
                 request_time, response_time, status, error_message, tracking_token, sent_on)
            VALUES (?, 'email', ?, 'personal', 'SMTP', ?, ?, NOW(), NOW(), ?, ?, ?, CURDATE())
            ON DUPLICATE KEY UPDATE status=VALUES(status), error_message=VALUES(error_message),
                template_id=VALUES(template_id), tracking_token=VALUES(tracking_token),
                response_time=NOW(), retry_count=retry_count+1
        ")->execute([$empId, $to, $templateId, $subject, $status, $error, $token]);
    } catch (Throwable $e) {
        error_log('bdaynotify_log_attempt failed: ' . $e->getMessage());
    }
}

/**
 * Retry a previously FAILED birthday send, reusing its existing log row so no
 * new duplicate-guard slot is claimed. Honours Test Mode + the kill switch,
 * updates the SAME notification_logs row to sent/failed and bumps retry_count.
 *
 * @return array{ok:bool, simulated:bool, error:?string, status:string}
 */
function bdaynotify_retry_birthday(int $logId): array
{
    $conn = bdaynotify_boot();

    $stmt = $conn->prepare("SELECT * FROM notification_logs WHERE log_id = ? LIMIT 1");
    $stmt->execute([$logId]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$log) {
        return ['ok' => false, 'simulated' => false, 'error' => 'Log entry not found.', 'status' => 'failed'];
    }
    if (!in_array($log['status'], ['failed', 'permanently_failed'], true)) {
        return ['ok' => false, 'simulated' => false, 'error' => 'Only failed emails can be retried.', 'status' => (string)$log['status']];
    }
    if (($log['email_type'] ?? '') !== 'personal') {
        return ['ok' => false, 'simulated' => false, 'error' => 'Only birthday emails can be retried here.', 'status' => (string)$log['status']];
    }

    $empId = (string)($log['emp_id'] ?? '');
    $es = $conn->prepare("SELECT * FROM v_employee_master_complete WHERE emp_id = ? LIMIT 1");
    $es->execute([$empId]);
    $emp = $es->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        return ['ok' => false, 'simulated' => false, 'error' => "Employee not found: $empId", 'status' => 'failed'];
    }

    $name     = $emp['full_name'] ?: 'Team Member';
    $intended = trim($emp['official_email'] ?? '');
    $testMode = is_test_mode($conn);
    $recipient = $testMode
        ? (getenv('MAIL_TEST_RECIPIENT') ?: (getenv('SMTP_FROM_EMAIL') ?: getenv('SMTP_USERNAME')))
        : $intended;

    if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'simulated' => false, 'error' => 'No valid recipient email.', 'status' => 'failed'];
    }
    if (production_send_blocked($conn)) {
        return ['ok' => false, 'simulated' => false, 'error' => 'Production sending is disabled (kill switch).', 'status' => 'skipped'];
    }

    $templateId = (int)($log['template_id'] ?: 0);
    if ($templateId < 1 || $templateId > 10) $templateId = active_birthday_template($conn);

    $token   = bin2hex(random_bytes(16));
    $base    = defined('BASE_URL') ? BASE_URL : '';
    $pixel   = "<img src=\"{$base}/cron/track_open.php?token={$token}\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none\">";
    $notice  = $testMode
        ? "<div style=\"background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 12px;font-family:Arial,sans-serif;font-size:13px;\">TEST MODE — intended recipient: " . htmlspecialchars($intended) . "</div>"
        : '';
    $subject = ($testMode ? '[TEST] ' : '') . '🎂 Happy Birthday, ' . $name . '!';
    $html    = $notice . getBirthdayEmailTemplate($templateId, $emp) . $pixel;

    // Optional poster (skipped gracefully if GD is off or disabled).
    $tmp = '';
    if (app_setting($conn, 'attach_poster', '1') === '1' && function_exists('imagecreatetruecolor')) {
        $im = generateBirthdayPosterGD($conn, $empId);
        if ($im) {
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bday_' . preg_replace('/\W/', '', $empId) . '.png';
            imagepng($im, $tmp);
            imagedestroy($im);
        }
    }

    $res = send_app_email($recipient, $subject, $html, "Happy Birthday, {$name}!", $tmp ?: null, 'Happy_Birthday.png');
    if ($tmp && is_file($tmp)) @unlink($tmp);

    $status = $res['ok'] ? 'sent' : 'failed';
    $cat    = $res['ok'] ? null : (function_exists('categorize_send_error') ? categorize_send_error($res['error'] ?? null) : null);

    // Update the SAME row (no new claim) and count the retry.
    try {
        $conn->prepare(
            "UPDATE notification_logs
                SET status = ?, error_message = ?, failure_category = ?,
                    tracking_token = ?, response_time = NOW(), retry_count = retry_count + 1
              WHERE log_id = ?"
        )->execute([$status, $res['error'] ?? null, $cat, $token, $logId]);
    } catch (Throwable $e) {
        error_log('bdaynotify_retry_birthday update failed: ' . $e->getMessage());
    }

    return ['ok' => $res['ok'], 'simulated' => $res['simulated'] ?? false, 'error' => $res['error'] ?? null, 'status' => $status];
}
