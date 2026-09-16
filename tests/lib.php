<?php
/**
 * tests/lib.php — tiny zero-dependency test helpers (CLI only).
 *
 * These tests NEVER send real email — they exercise pure logic and the
 * database guard. Run all with:  php tests/run_all.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tests run from the command line only.\n");
}

$GLOBALS['__t_pass'] = 0;
$GLOBALS['__t_fail'] = 0;
$GLOBALS['__t_fails'] = [];

function t_ok(bool $cond, string $label): void
{
    if ($cond) {
        $GLOBALS['__t_pass']++;
        echo "  PASS  {$label}\n";
    } else {
        $GLOBALS['__t_fail']++;
        $GLOBALS['__t_fails'][] = $label;
        echo "  FAIL  {$label}\n";
    }
}

function t_eq($expected, $actual, string $label): void
{
    $cond = ($expected === $actual);
    if (!$cond) {
        $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
    t_ok($cond, $label);
}

function t_section(string $name): void
{
    echo "\n== {$name} ==\n";
}

function t_summary(): int
{
    $p = $GLOBALS['__t_pass']; $f = $GLOBALS['__t_fail'];
    echo "\n----------------------------------------\n";
    echo "TOTAL: " . ($p + $f) . "  PASS: {$p}  FAIL: {$f}\n";
    if ($f > 0) {
        echo "Failures:\n";
        foreach ($GLOBALS['__t_fails'] as $lbl) echo "  - {$lbl}\n";
    }
    return $f === 0 ? 0 : 1;
}
