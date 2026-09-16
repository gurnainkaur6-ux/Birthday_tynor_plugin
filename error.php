<?php
/**
 * error.php — friendly, self-contained error page (404 / 403 / 500 / …).
 *
 * Wired via .htaccess ErrorDocument directives, and safe to link to directly.
 * Never exposes server details or stack traces. Always offers a way out
 * (dashboard, sign in, and "Back to ERP" when configured) so an error is never
 * a dead end.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/erp.php';

// Determine the status code from the query or Apache's redirect status.
$code = (int) ($_GET['code'] ?? ($_SERVER['REDIRECT_STATUS'] ?? 0));
$map = [
    400 => ['Bad request', 'The request could not be understood.'],
    403 => ['Access denied', 'You do not have permission to view this page.'],
    404 => ['Page not found', 'The page you were looking for does not exist or has moved.'],
    500 => ['Something went wrong', 'An unexpected error occurred. The team has been notified.'],
    503 => ['Temporarily unavailable', 'The system is undergoing maintenance. Please try again shortly.'],
];
if (!isset($map[$code])) {
    $code = 404;
}
if (!headers_sent()) {
    http_response_code($code);
}
[$title, $message] = $map[$code];

$B        = defined('BASE_URL') ? BASE_URL : '';
$loggedIn = !empty($_SESSION['admin_id']);
$erp      = function_exists('erp_back_url') ? erp_back_url() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $code ?> — <?= htmlspecialchars($title) ?></title>
<style>
  body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f1f5f9;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;color:#1e293b;}
  .card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:2.5rem;max-width:480px;text-align:center;box-shadow:0 10px 30px rgba(2,6,23,.08);}
  .code{font-size:3.5rem;font-weight:800;color:#1a4fa0;line-height:1;}
  h1{font-size:1.35rem;margin:.5rem 0;}
  p{line-height:1.6;color:#475569;}
  .actions{margin-top:1.25rem;display:flex;gap:.5rem;justify-content:center;flex-wrap:wrap;}
  a.btn{display:inline-block;padding:.6rem 1.15rem;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;}
  .btn-primary{background:#1a4fa0;color:#fff;} .btn-secondary{background:#fff;color:#1a4fa0;border:1px solid #c7d7ef;}
</style>
</head>
<body>
  <div class="card">
    <div class="code"><?= $code ?></div>
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($message) ?></p>
    <div class="actions">
      <?php if ($loggedIn): ?>
        <a class="btn btn-primary" href="<?= htmlspecialchars($B, ENT_QUOTES) ?>/dashboard/index.php">Go to dashboard</a>
      <?php else: ?>
        <a class="btn btn-primary" href="<?= htmlspecialchars($B, ENT_QUOTES) ?>/auth/login.php">Sign in</a>
      <?php endif; ?>
      <?php if ($erp !== ''): ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars($erp, ENT_QUOTES) ?>">&larr; Back to ERP</a>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
