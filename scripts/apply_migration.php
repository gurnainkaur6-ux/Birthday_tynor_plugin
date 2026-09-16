<?php
/**
 * apply_migration.php — CLI migration runner.
 *
 *   C:\xampp\php\php.exe scripts/apply_migration.php database/upgrade_v2.sql
 *
 * Applies a .sql file statement-by-statement against the configured database.
 * Intended for controlled upgrades. Additive migrations in this project use
 * "IF [NOT] EXISTS" so they are safe to re-run. CLI only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: run this from the command line.\n");
}

require __DIR__ . '/../config/database.php';   // provides $conn

$arg = $argv[1] ?? '';
if ($arg === '') {
    fwrite(STDERR, "Usage: php scripts/apply_migration.php <path-to-sql>\n");
    exit(1);
}

$path = realpath($arg) ?: realpath(__DIR__ . '/../' . ltrim($arg, '/\\'));
if (!$path || !is_file($path)) {
    fwrite(STDERR, "File not found: {$arg}\n");
    exit(1);
}

$keep = [];
foreach (explode("\n", file_get_contents($path)) as $line) {
    if (preg_match('/^\s*--/', $line)) continue;   // strip comment-only lines
    $keep[] = $line;
}
$statements = array_filter(array_map('trim', explode(';', implode("\n", $keep))), fn($s) => $s !== '');

$ok = 0; $fail = 0;
foreach ($statements as $stmt) {
    try {
        $conn->exec($stmt);
        $ok++;
    } catch (PDOException $e) {
        $fail++;
        fwrite(STDERR, 'ERR: ' . substr(preg_replace('/\s+/', ' ', $stmt), 0, 90) . ' : ' . $e->getMessage() . "\n");
    }
}
echo "Applied {$ok} statement(s), {$fail} error(s) from " . basename($path) . "\n";
exit($fail > 0 ? 1 : 0);
