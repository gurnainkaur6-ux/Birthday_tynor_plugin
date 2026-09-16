<?php
/**
 * setup.php — Database installer / migrator.
 *
 * SECURITY: This script DROPS and recreates every table and reseeds sample
 * data. It is therefore locked down:
 *   • On a FRESH install (no users yet) it may be run once, after an explicit
 *     confirmation click.
 *   • On an EXISTING install it can only be run by a signed-in SuperAdmin who
 *     types the confirmation phrase — this prevents accidental data loss.
 *
 * It never prints credentials. Delete or block this file after installation.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';   // provides $conn
require_once __DIR__ . '/../includes/csrf.php';

/* ── Determine install state & authorization ─────────────────────────── */
$existingUsers = null;
try {
    $existingUsers = (int) $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (Throwable $e) {
    $existingUsers = null; // users table doesn't exist yet → fresh install
}
$freshInstall  = ($existingUsers === null || $existingUsers === 0);
$loggedSuper   = !empty($_SESSION['admin_id']) && (($_SESSION['admin_role'] ?? '') === 'SuperAdmin');

// Only a fresh install OR a signed-in SuperAdmin may reach the installer.
if (!$freshInstall && !$loggedSuper) {
    http_response_code(403);
    $B = defined('BASE_URL') ? BASE_URL : '';
    echo '<!DOCTYPE html><meta charset="UTF-8"><title>Setup locked</title>'
       . '<div style="font-family:system-ui,Arial,sans-serif;max-width:520px;margin:4rem auto;padding:2rem;'
       . 'border:1px solid #e2e8f0;border-radius:12px;text-align:center">'
       . '<h2>Setup is locked</h2>'
       . '<p>This system is already installed. Re-running setup would erase data, so it is '
       . 'restricted to a signed-in administrator.</p>'
       . '<p><a href="' . htmlspecialchars($B, ENT_QUOTES) . '/auth/login.php">Sign in as administrator</a></p>'
       . '</div>';
    exit;
}

/* ── Run only on an explicit, confirmed POST ─────────────────────────── */
$isPost    = ($_SERVER['REQUEST_METHOD'] === 'POST');
$phrase    = trim($_POST['confirm_phrase'] ?? '');
$confirmed = $isPost && csrf_verify()
           && ($freshInstall ? (($_POST['confirm'] ?? '') === '1')
                             : (strtoupper($phrase) === 'REINSTALL'));

$msgs = [];
$errs = [];
$didRun = false;

if ($confirmed) {
    $didRun = true;

    /** Import a .sql file statement-by-statement, collecting errors. */
    $importSql = function (string $file, string $label) use ($conn, &$msgs, &$errs) {
        if (!is_file($file)) {
            $msgs[] = "Skipped {$label} (file not present).";
            return;
        }
        $keep = [];
        foreach (explode("\n", file_get_contents($file)) as $line) {
            if (preg_match('/^\s*--/', $line)) continue;
            $keep[] = $line;
        }
        $stmts = array_filter(array_map('trim', explode(';', implode("\n", $keep))), static fn($s) => $s !== '');
        $ok = 0;
        foreach ($stmts as $stmt) {
            try { $conn->exec($stmt); $ok++; }
            catch (PDOException $e) {
                $lbl = preg_replace('/\s+/', ' ', substr($stmt, 0, 70));
                $errs[] = htmlspecialchars($lbl) . " … : " . htmlspecialchars($e->getMessage());
            }
        }
        $msgs[] = "{$label}: executed {$ok} statement(s).";
    };

    $importSql(APP_ROOT . '/database/birthday_system.sql',      'Base schema');
    $importSql(APP_ROOT . '/database/sample_data_extended.sql', 'Extended sample roster');
    $importSql(APP_ROOT . '/database/upgrade.sql',              'Upgrade migration (approvals + settings)');
    $importSql(APP_ROOT . '/database/upgrade_v2.sql',           'Upgrade v2 (integrity + settings)');
    $importSql(APP_ROOT . '/database/upgrade_v3.sql',           'Upgrade v3 (user account lifecycle: status)');
    $importSql(APP_ROOT . '/database/upgrade_v4.sql',           'Upgrade v4 (per-user feature access privileges)');

    try {
        $emp  = (int)$conn->query("SELECT COUNT(*) FROM employee_master")->fetchColumn();
        $usr  = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $view = (int)$conn->query("SELECT COUNT(*) FROM v_employee_master_complete")->fetchColumn();
        $msgs[] = "{$emp} employees, {$usr} admin user(s), {$view} rows in the core view.";
        $msgs[] = "A default administrator account is seeded by the schema — sign in and change its "
                . "password immediately (see INSTALLATION.md). Production email sending stays DISABLED "
                . "until an administrator enables it in Settings.";
    } catch (PDOException $e) {
        $errs[] = "Verification query failed: " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup | BDayNotify</title>
<link rel="stylesheet" href="<?= htmlspecialchars(ASSETS_URL, ENT_QUOTES) ?>/css/styles.css">
<style>body{display:flex;align-items:center;justify-content:center;min-height:100vh}.setup-box{background:#fff;border:1px solid #c7d9f0;border-radius:12px;padding:2.5rem 2rem;max-width:680px;width:100%;box-shadow:0 4px 20px rgba(0,80,180,.08)}.setup-box h1{color:#1a4fa0;margin-bottom:1.25rem}.msg{padding:.6rem 1rem;border-radius:8px;margin:.4rem 0;font-size:.9rem;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}.err{background:#fef2f2;border-color:#fecaca;color:#b91c1c}.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:.75rem 1rem;border-radius:8px;margin:.75rem 0}.go{display:inline-block;margin-top:1.5rem;background:#1a4fa0;color:#fff;padding:.75rem 2rem;border-radius:8px;text-decoration:none;font-weight:700;border:none;cursor:pointer;font-size:.95rem}input[type=text]{padding:.6rem;border:1px solid #cbd5e1;border-radius:6px;width:100%;margin:.5rem 0}</style>
</head>
<body>
<div class="setup-box">
  <h1>Database setup</h1>

  <?php if ($didRun): ?>
    <?php foreach ($errs as $e): ?><div class="msg err"><?= $e ?></div><?php endforeach; ?>
    <?php foreach ($msgs as $m): ?><div class="msg"><?= $m ?></div><?php endforeach; ?>
    <?php if (!$errs): ?>
      <p style="color:#64748b;font-size:.85rem;margin-top:1rem">Setup complete. For security, delete or block this file now.</p>
      <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>/auth/login.php" class="go">Go to login &rarr;</a>
    <?php endif; ?>
  <?php else: ?>
    <?php if ($freshInstall): ?>
      <div class="warn"><strong>Fresh installation.</strong> This will create the schema and load the
        starter roster. Click below to proceed.</div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="confirm" value="1">
        <button type="submit" class="go">Install database</button>
      </form>
    <?php else: ?>
      <div class="warn"><strong>Warning — destructive.</strong> This system already contains
        <?= (int)$existingUsers ?> admin user(s) and live data. Re-running setup will
        <strong>drop and recreate every table</strong>, erasing current employees, send history and
        audit records. Back up your database first. To proceed, type <code>REINSTALL</code> below.</div>
      <form method="POST" onsubmit="return confirm('This permanently erases current data. Continue?');">
        <?= csrf_field() ?>
        <input type="text" name="confirm_phrase" placeholder="Type REINSTALL to confirm" autocomplete="off">
        <button type="submit" class="go" style="background:#b91c1c">Erase &amp; reinstall</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
