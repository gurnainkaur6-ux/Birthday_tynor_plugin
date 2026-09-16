<?php
/**
 * generate_sample_data.php — produce a large, realistic synthetic employee
 * roster to demonstrate that the system scales to a real company size.
 *
 * It writes database/sample_data_extended.sql (idempotent INSERT ... ON
 * DUPLICATE KEY UPDATE statements) which is imported by public/setup.php after
 * the base schema. Re-running is always safe.
 *
 *   Usage:  php scripts/generate_sample_data.php [count]
 *
 * The data is fully synthetic (no real people) and modelled on a typical HR
 * dataset: names, departments, designations, plants, DOBs spread across the
 * whole year, joining dates, emails, mobiles, and a rotating set of sample
 * photos. DOBs are deliberately spread so "Today's" and "Upcoming" birthdays
 * always have entries to show.
 */

$count = isset($argv[1]) ? max(1, (int)$argv[1]) : 260;

$firstNames = [
    'Aarav','Vivaan','Aditya','Vihaan','Arjun','Reyansh','Krishna','Ishaan','Rohan','Kabir',
    'Ananya','Diya','Aadhya','Saanvi','Pari','Anika','Navya','Myra','Sara','Ira',
    'Rahul','Sneha','Priya','Neha','Pooja','Kavya','Riya','Simran','Manpreet','Harleen',
    'Amanpreet','Jaskaran','Gurleen','Karan','Nikhil','Varun','Siddharth','Ayaan','Dhruv','Kunal',
    'James','Emma','Olivia','Liam','Sophia','Noah','Ava','Ethan','Mia','Lucas',
    'Isabella','Mason','Chloe','Daniel','Natalie','Marcus','Elena','Oliver','Grace','Henry',
    'Wei','Mei','Hiroshi','Yuki','Sofia','Mateo','Lucia','Diego','Fatima','Omar',
];
$lastNames = [
    'Sharma','Verma','Singh','Kaur','Gupta','Patel','Kumar','Mehta','Chopra','Malhotra',
    'Reddy','Nair','Iyer','Das','Bose','Rao','Joshi','Bhatt','Chauhan','Sethi',
    'Carter','Martinez','Chen','Brooks','Peterson','Silva','Gallagher','Wright','Rossi','Jenkins',
    'Taylor','Foster','Hansen','Kim','Vance','Sterling','Rostova','Schmidt','Nguyen','Khan',
];

// Departments that already exist in department_master (so the chart includes them).
$departments = [
    'Information Technology','E-Commerce','Finance and Accounts','HR and Admin',
    'Industrial Engineering','Knitting','Maintenance','Marketing','Mechanical','Moulding',
    'Projects','Purchase','Quality','Research & Development','RM Store',
    'Sales and Marketing Domestic','Sales and Marketing Exports','Sales Domestic',
    'SCM - Finished Goods','SFG Cutting','Stitching and SFG Cutting','Logistics',
];
$designations = [
    'Executive','Deputy Manager','Assistant Manager','Manager','Sr.Manager','Intern','CDO','CIO',
];
$plants = [
    'PLNT_ID24560','PLNT_ID24561','PLNT_ID24562','PLNT_ID24563','PLNT_ID24564','PLNT_ID24565',
    'PLNT_ID24566','PLNT_ID24567','PLNT_ID24568','PLNT_ID24569','PLNT_ID24570','PLNT_ID24571',
    'PLNT_ID24572','PLNT_ID24573','PLNT_ID24574','PLNT_ID24575','PLNT_ID24576','PLNT_ID24577',
    'PLNT_ID24578','PLNT_ID24579','PLNT_ID24580','PLNT_ID24581',
];

/** SQL-escape a value (single quotes doubled), or return NULL literal. */
function q($v): string {
    if ($v === null) return 'NULL';
    return "'" . str_replace("'", "''", (string)$v) . "'";
}

mt_srand(20260721); // deterministic output so re-generation is stable

$startNum = 202565; // continue after the existing seed (…202564)
$empRows = $dobRows = $emailRows = $photoRows = [];
$usedEmails = [];

for ($i = 0; $i < $count; $i++) {
    $num   = $startNum + $i;
    $empId = "EMP_ID $num";

    $first = $firstNames[array_rand($firstNames)];
    $last  = $lastNames[array_rand($lastNames)];
    $full  = "$first $last";

    $dept   = $departments[array_rand($departments)];
    $desig  = $designations[array_rand($designations)];
    $plant  = $plants[array_rand($plants)];
    $mobile = '9' . str_pad((string)mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
    $status = (mt_rand(1, 100) <= 92) ? 'active' : 'inactive'; // ~8% inactive

    // Joining date: random within the last ~15 years.
    $joinTs = strtotime(sprintf('-%d days', mt_rand(30, 5475)));
    $joining = date('Y-m-d', $joinTs);

    // DOB: spread across the whole year so birthdays always appear.
    // ~4% get today's month/day and ~10% land within the next 30 days.
    $year = mt_rand(1980, 2001);
    $roll = mt_rand(1, 100);
    if ($roll <= 4) {
        $md = date('m-d');                                   // birthday today
    } elseif ($roll <= 14) {
        $md = date('m-d', strtotime('+' . mt_rand(1, 30) . ' days')); // upcoming
    } else {
        $mon = mt_rand(1, 12);
        $day = mt_rand(1, 28);
        $md  = sprintf('%02d-%02d', $mon, $day);
    }
    $dob = "$year-$md";

    // Unique-ish email.
    $slug = strtolower(preg_replace('/[^a-z]/i', '', $first . '.' . $last));
    $email = $slug . '@tynor.example.com';
    if (isset($usedEmails[$email])) { $email = $slug . $num . '@tynor.example.com'; }
    $usedEmails[$email] = true;

    $empRows[] = '(' . implode(',', [
        q($empId), q($full), q($first), q($last), q($dept), q($desig),
        'NULL', 'NULL', q($plant), q($mobile), q($joining), q($status),
    ]) . ')';

    $dobRows[]   = '(' . implode(',', [q("DOB_$num"), q($empId), q($dob)]) . ')';
    $emailRows[] = '(' . implode(',', [q("EMAIL_$num"), q($empId), q($email), "'Active'"]) . ')';

    // ~28% get one of the 10 shipped sample photos; the rest use initials.
    if (mt_rand(1, 100) <= 28) {
        $n = (($i % 10) + 1);
        $photoRows[] = '(' . implode(',', [q("PHOTO_$num"), q($empId), q("img/emp$n.jpg"), q("KEY_$num")]) . ')';
    }
}

/** Emit chunked multi-row INSERTs with an upsert tail. */
function insertBlock(string $table, string $cols, array $rows, string $updateTail, int $chunk = 50): string {
    $out = '';
    foreach (array_chunk($rows, $chunk) as $group) {
        $out .= "INSERT INTO {$table} ({$cols}) VALUES\n"
              . implode(",\n", $group)
              . "\nON DUPLICATE KEY UPDATE {$updateTail};\n\n";
    }
    return $out;
}

$sql  = "-- =====================================================================\n";
$sql .= "-- sample_data_extended.sql  — AUTO-GENERATED synthetic roster\n";
$sql .= "-- Generated by scripts/generate_sample_data.php on " . date('Y-m-d H:i') . "\n";
$sql .= "-- Rows: {$count} employees.  Safe to re-run (idempotent upserts).\n";
$sql .= "-- =====================================================================\n\n";

$sql .= insertBlock(
    'employee_master',
    'emp_id, full_name, first_name, last_name, department_name, designation_name, hod_name, mentor_name, plant_id, mobile_no, joining_date, status',
    $empRows,
    'full_name=VALUES(full_name), department_name=VALUES(department_name), designation_name=VALUES(designation_name), plant_id=VALUES(plant_id), mobile_no=VALUES(mobile_no), joining_date=VALUES(joining_date), status=VALUES(status), is_deleted=0'
);
$sql .= insertBlock('dob_master', 'dob_id, emp_id, date_of_birth', $dobRows, 'date_of_birth=VALUES(date_of_birth)');
$sql .= insertBlock('email_master', 'email_id, emp_id, official_email, email_status', $emailRows, 'official_email=VALUES(official_email)');
$sql .= insertBlock('photo_master', 'photo_id, emp_id, photo_path, photo_key', $photoRows, 'photo_path=VALUES(photo_path)');

$sql .= "UPDATE dob_master dm JOIN employee_master e ON e.emp_id = dm.emp_id SET dm.full_name = e.full_name;\n";

$outFile = dirname(__DIR__) . '/database/sample_data_extended.sql';
file_put_contents($outFile, $sql);

echo "Wrote {$count} employees to {$outFile}\n";
echo "  employee_master : " . count($empRows) . " rows\n";
echo "  dob_master      : " . count($dobRows) . " rows\n";
echo "  email_master    : " . count($emailRows) . " rows\n";
echo "  photo_master    : " . count($photoRows) . " rows\n";
