<?php

$mysqlAvailable = in_array('mysql', PDO::getAvailableDrivers(), true);
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if ($baseUrl === '/' || $baseUrl === '.') {
	$baseUrl = '';
}
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>BDayNotify setup</title>
	<style>
		body { margin: 0; padding: 2rem; background: #f4f7fb; color: #172033; font: 16px/1.5 system-ui, sans-serif; }
		main { max-width: 640px; margin: 5vh auto; padding: 2rem; background: #fff; border: 1px solid #d7e0ec; border-radius: 12px; box-shadow: 0 8px 30px rgba(30, 60, 100, .08); }
		h1 { margin-top: 0; color: #174ea6; }
		.status { padding: .8rem 1rem; border-radius: 8px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; }
		.actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1.5rem; }
		a { display: inline-block; padding: .7rem 1rem; border-radius: 7px; background: #174ea6; color: #fff; text-decoration: none; font-weight: 700; }
		a.secondary { background: #e8eef8; color: #174ea6; }
		code { background: #eef2f7; padding: .1rem .3rem; border-radius: 4px; }
	</style>
</head>
<body>
<main>
	<h1>BDayNotify</h1>
	<?php if ($mysqlAvailable): ?>
		<p>PHP can connect to MySQL. If this is a new installation, run setup first.</p>
		<div class="actions">
			<a href="<?= htmlspecialchars($baseUrl . '/public/setup.php', ENT_QUOTES) ?>">Set up database</a>
			<a class="secondary" href="<?= htmlspecialchars($baseUrl . '/auth/login.php', ENT_QUOTES) ?>">Go to login</a>
		</div>
	<?php else: ?>
		<div class="status"><strong>One setup step is still needed:</strong> the PHP MySQL driver is not available on this server.</div>
		<p>Install or enable MySQL/MariaDB and the PHP <code>pdo_mysql</code> extension, then restart PHP or Apache.</p>
		<p>After that, copy <code>.env.example</code> to <code>.env</code>, enter your database settings, and return here.</p>
	<?php endif; ?>
</main>
</body>
</html>