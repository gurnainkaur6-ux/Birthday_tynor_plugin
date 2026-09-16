<?php
/**
 * employees_io.php — Employee CSV Import / Export.
 *
 * Zero external dependencies (uses PHP fgetcsv/fputcsv) so it runs on any host.
 * Excel opens and saves CSV natively, so this fully supports the company's
 * spreadsheet workflow without requiring PhpSpreadsheet on the server.
 *
 *   GET  ?action=export    → download all employees as CSV
 *   GET  ?action=template  → download a blank CSV template (headers + example)
 *   POST action=import     → parse uploaded CSV, validate, upsert, redirect back
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/csv_safe.php';

// Import/export are part of the Employees feature: block entirely when it is
// disabled for this user. Per-action manager capabilities are still enforced
// below (export/import require their own rights).
require_feature($conn, 'employees');

// Canonical column order shared by template, export and import.
const CSV_COLUMNS = [
    'emp_id', 'emp_name', 'first_name', 'last_name', 'email',
    'department_name', 'designation_name', 'mobile_no', 'status',
    'dob', 'joining_date', 'hod_name', 'mentor_name',
    'plant_location', 'plant_name', 'plant_id', 'photo_path',
];

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

/* ─────────────────────────── TEMPLATE ─────────────────────────── */
if ($action === 'template') {
    require_cap('employees.view');
    stream_csv('bdaynotify_employee_template.csv', function ($out) {
        fputcsv($out, CSV_COLUMNS);
        fputcsv($out, [
            'EMP_ID 202599', 'Vikram Singh', 'Vikram', 'Singh', 'vikram.s@tynor.in',
            'Information Technology', 'Deputy Manager', '9876543210', 'active',
            '1990-07-21', '2018-04-01', 'Amarjit Singh', 'Anvinderjit Singh',
            'Mohali', 'Corporate Head Office', 'PLANT_001', 'img/emp1.jpg',
        ]);
    });
}

/* ──────────────────────────── EXPORT ──────────────────────────── */
if ($action === 'export') {
    require_cap('employees.export');   // exports contain employee PII
    audit_log($conn, 'EXPORT', 'employee_master', 'csv_export');
    $rows = [];
    try {
        $rows = $conn->query("SELECT * FROM employee_view ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        try { $rows = $conn->query("SELECT * FROM v_employee_master_complete ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e2) { $rows = []; }
    }

    stream_csv('bdaynotify_employees_' . date('Ymd_His') . '.csv', function ($out) use ($rows) {
        fputcsv($out, CSV_COLUMNS);
        foreach ($rows as $e) {
            // csv_safe_cell() neutralises spreadsheet formula injection.
            fputcsv($out, array_map('csv_safe_cell', [
                $e['emp_id']                                   ?? '',
                $e['full_name']       ?? $e['emp_name']        ?? '',
                $e['first_name']                               ?? '',
                $e['last_name']                                ?? '',
                $e['official_email']  ?? $e['email']           ?? '',
                $e['department_name'] ?? $e['department']      ?? '',
                $e['designation_name']?? $e['designation']     ?? '',
                $e['mobile_no']       ?? $e['phone_number']    ?? '',
                $e['employee_status'] ?? $e['status']          ?? 'active',
                $e['date_of_birth']   ?? $e['dob_formatted']   ?? '',
                $e['joining_date']    ?? $e['date_of_joining'] ?? '',
                $e['hod_name']                                 ?? '',
                $e['mentor_name']                              ?? '',
                $e['plant_location']                           ?? '',
                $e['plant_name']                               ?? '',
                $e['plant_id']                                 ?? '',
                $e['photo_path']      ?? $e['photo']           ?? '',
            ]));
        }
    });
}

/* ──────────────────────────── IMPORT ──────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'import') {
    require_cap('employees.import');
    $summary = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'total' => 0];

    if (!csrf_verify()) {
        $summary['errors'][] = 'Invalid security token. Please refresh and try again.';
    } elseif (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $summary['errors'][] = 'No file uploaded or upload failed.';
    } else {
        $ext = strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            $summary['errors'][] = 'Please upload a .csv file. In Excel use "Save As → CSV (Comma delimited)".';
        } else {
            $fh = fopen($_FILES['csv']['tmp_name'], 'r');
            if (!$fh) {
                $summary['errors'][] = 'Could not read the uploaded file.';
            } else {
                // Header row → build a name→index map (case/space tolerant).
                $header = fgetcsv($fh);
                if ($header === false) {
                    $summary['errors'][] = 'The file appears to be empty.';
                } else {
                    // Strip UTF-8 BOM from first header cell if present.
                    if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
                    $idx = [];
                    foreach ($header as $i => $h) {
                        $key = strtolower(trim(str_replace([' ', '.'], '_', (string)$h)));
                        $idx[$key] = $i;
                    }
                    // Aliases so real Excel headers also map cleanly.
                    $aliases = [
                        'emp_code' => 'emp_id', 'employee_id' => 'emp_id', 'employee_code' => 'emp_id',
                        'full_name' => 'emp_name', 'name' => 'emp_name',
                        'official_email' => 'email', 'email_address' => 'email',
                        'department' => 'department_name', 'designation' => 'designation_name',
                        'mobile' => 'mobile_no', 'mobile_number' => 'mobile_no', 'phone_number' => 'mobile_no',
                        'date_of_birth' => 'dob', 'birth_date' => 'dob',
                        'date_of_joining' => 'joining_date', 'doj' => 'joining_date',
                        'plant' => 'plant_location', 'plant_location_name' => 'plant_name',
                        'photo' => 'photo_path', 'photo_url' => 'photo_path',
                    ];
                    $col = function (array $row, string $name) use ($idx, $aliases) {
                        if (isset($idx[$name]))                        return trim((string)($row[$idx[$name]] ?? ''));
                        foreach ($aliases as $a => $canon) {
                            if ($canon === $name && isset($idx[$a]))   return trim((string)($row[$idx[$a]] ?? ''));
                        }
                        return '';
                    };

                    $line = 1;
                    while (($row = fgetcsv($fh)) !== false) {
                        $line++;
                        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue; // blank line
                        $summary['total']++;

                        $empId = $col($row, 'emp_id');
                        $name  = $col($row, 'emp_name');
                        $email = $col($row, 'email');
                        $dob   = normalize_date($col($row, 'dob'));

                        // Validation
                        if ($empId === '' || $name === '') {
                            $summary['skipped']++;
                            $summary['errors'][] = "Row $line: missing emp_id or name — skipped.";
                            continue;
                        }
                        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $summary['skipped']++;
                            $summary['errors'][] = "Row $line ($empId): invalid email '$email' — skipped.";
                            continue;
                        }
                        if ($dob === null && $col($row, 'dob') !== '') {
                            $summary['errors'][] = "Row $line ($empId): unrecognized DOB '{$col($row,'dob')}' — stored empty.";
                        }
                        // Future DOB guard (the dob_master trigger also enforces this).
                        if ($dob !== null && $dob > date('Y-m-d')) {
                            $summary['skipped']++;
                            $summary['errors'][] = "Row $line ($empId): date of birth '$dob' is in the future — skipped.";
                            continue;
                        }
                        // Reject an email already assigned to a DIFFERENT employee
                        // (official_email is UNIQUE — prevents duplicate contacts).
                        if ($email !== '') {
                            $dupE = $conn->prepare("SELECT emp_id FROM email_master WHERE official_email = ? AND emp_id <> ? LIMIT 1");
                            $dupE->execute([$email, $empId]);
                            if ($dupE->fetchColumn()) {
                                $summary['skipped']++;
                                $summary['errors'][] = "Row $line ($empId): email '$email' already belongs to another employee — skipped.";
                                continue;
                            }
                        }

                        $first = $col($row, 'first_name');
                        $last  = $col($row, 'last_name');
                        if ($first === '' || $last === '') {
                            $parts = preg_split('/\s+/', $name);
                            $first = $first !== '' ? $first : ($parts[0] ?? $name);
                            $last  = $last  !== '' ? $last  : (count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '');
                        }
                        $status = strtolower($col($row, 'status')) === 'inactive' ? 'inactive' : 'active';
                        $joining = normalize_date($col($row, 'joining_date'));

                        $plantId   = $col($row, 'plant_id');
                        $plantName = $col($row, 'plant_name');
                        $plantLoc  = $col($row, 'plant_location');

                        try {
                            $conn->beginTransaction();

                            // Auto-create the plant if a plant_id is supplied that doesn't
                            // exist yet — otherwise the FK on employee_master.plant_id fails
                            // and bulk import of new plants would be impossible.
                            if ($plantId !== '') {
                                $conn->prepare("
                                    INSERT INTO plant_master (plant_id, plant_name, plant_location, is_active)
                                    VALUES (?, ?, ?, 1)
                                    ON DUPLICATE KEY UPDATE
                                        plant_name = VALUES(plant_name),
                                        plant_location = VALUES(plant_location)
                                ")->execute([
                                    $plantId,
                                    $plantName !== '' ? $plantName : 'Unassigned',
                                    $plantLoc  !== '' ? $plantLoc  : 'Unassigned',
                                ]);
                            }

                            // Does it already exist? (drives inserted vs updated count)
                            $exists = $conn->prepare("SELECT 1 FROM employee_master WHERE emp_id = ?");
                            $exists->execute([$empId]);
                            $isUpdate = (bool)$exists->fetchColumn();

                            $conn->prepare("
                                INSERT INTO employee_master
                                    (emp_id, full_name, first_name, last_name, department_name, designation_name,
                                     mobile_no, status, joining_date, hod_name, mentor_name, plant_location, plant_name, plant_id, is_deleted)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                                ON DUPLICATE KEY UPDATE
                                    full_name=VALUES(full_name), first_name=VALUES(first_name), last_name=VALUES(last_name),
                                    department_name=VALUES(department_name), designation_name=VALUES(designation_name),
                                    mobile_no=VALUES(mobile_no), status=VALUES(status), joining_date=VALUES(joining_date),
                                    hod_name=VALUES(hod_name), mentor_name=VALUES(mentor_name),
                                    plant_location=VALUES(plant_location), plant_name=VALUES(plant_name),
                                    plant_id=VALUES(plant_id), is_deleted=0
                            ")->execute([
                                $empId, $name, $first, $last,
                                $col($row, 'department_name'), $col($row, 'designation_name'),
                                $col($row, 'mobile_no'), $status, $joining ?: null,
                                $col($row, 'hod_name') ?: null, $col($row, 'mentor_name') ?: null,
                                $plantLoc, $plantName,
                                $plantId ?: null,
                            ]);

                            if ($dob) {
                                $conn->prepare("
                                    INSERT INTO dob_master (dob_id, emp_id, date_of_birth) VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE date_of_birth=VALUES(date_of_birth)
                                ")->execute(['Dob_' . $empId, $empId, $dob]);
                            }
                            if ($email !== '') {
                                $conn->prepare("
                                    INSERT INTO email_master (email_id, emp_id, official_email) VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE official_email=VALUES(official_email)
                                ")->execute(['Email_' . $empId, $empId, $email]);
                            }
                            $photo = $col($row, 'photo_path');
                            if ($photo !== '') {
                                $photoId = 'PHOTO_' . preg_replace('/[^a-zA-Z0-9]/', '', $empId);
                                $conn->prepare("
                                    INSERT INTO photo_master (photo_id, emp_id, photo_path) VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE photo_path=VALUES(photo_path)
                                ")->execute([$photoId, $empId, $photo]);
                            }

                            $conn->commit();
                            $isUpdate ? $summary['updated']++ : $summary['inserted']++;
                        } catch (Throwable $ex) {
                            if ($conn->inTransaction()) $conn->rollBack();
                            $summary['skipped']++;
                            $summary['errors'][] = "Row $line ($empId): " . $ex->getMessage();
                        }
                    }
                }
                fclose($fh);
            }
        }
    }

    // Cap error list so the flash message stays readable.
    if (count($summary['errors']) > 12) {
        $extra = count($summary['errors']) - 12;
        $summary['errors'] = array_slice($summary['errors'], 0, 12);
        $summary['errors'][] = "…and $extra more.";
    }

    require_once __DIR__ . '/../includes/audit.php';
    audit_log($conn, 'IMPORT', 'employee_master', 'csv-import', null, [
        'inserted' => $summary['inserted'], 'updated' => $summary['updated'],
        'skipped'  => $summary['skipped'],  'total'   => $summary['total'],
    ]);

    $_SESSION['import_summary'] = $summary;
    header('Location: ' . BASE_URL . '/dashboard/employees.php#import');
    exit;
}

// Fallback: nothing matched.
header('Location: ' . BASE_URL . '/dashboard/employees.php');
exit;

/* ─────────────────────────── helpers ──────────────────────────── */

/** Stream a CSV download with a UTF-8 BOM (so Excel shows accents correctly). */
function stream_csv(string $filename, callable $writer): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
    $writer($out);
    fclose($out);
    exit;
}

/** Accepts common date formats and returns Y-m-d, or null if unparseable/empty. */
function normalize_date(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'Y/m/d', 'd-M-Y', 'j M Y', 'M j, Y'];
    foreach ($formats as $f) {
        $dt = DateTime::createFromFormat($f, $raw);
        if ($dt && $dt->format($f) === $raw) return $dt->format('Y-m-d');
    }
    $ts = strtotime($raw);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}
