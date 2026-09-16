<?php
/**
 * test_csv_safety.php — CSV formula-injection neutralisation.
 */
require_once __DIR__ . '/../includes/csv_safe.php';

function run_csv_safety_tests(): void
{
    t_section('CSV formula-injection safety');

    t_eq("'=HYPERLINK(\"http://evil\")", csv_safe_cell('=HYPERLINK("http://evil")'), 'leading = neutralised');
    t_eq("'+1+2",  csv_safe_cell('+1+2'),  'leading + neutralised');
    t_eq("'-2+3",  csv_safe_cell('-2+3'),  'leading - neutralised');
    t_eq("'@SUM",  csv_safe_cell('@SUM'),  'leading @ neutralised');
    t_eq('Vikram Singh', csv_safe_cell('Vikram Singh'), 'normal name unchanged');
    t_eq('VIKRAM.S@TYNOR.IN', csv_safe_cell('VIKRAM.S@TYNOR.IN'), 'email (@ not leading) unchanged');
    t_eq('', csv_safe_cell(''), 'empty stays empty');
}

if (realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/lib.php';
    run_csv_safety_tests();
    exit(t_summary());
}
