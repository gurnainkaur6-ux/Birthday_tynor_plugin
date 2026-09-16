<?php
/**
 * view_sent.php — review a single logged communication (print-friendly).
 *
 * Shows the exact rendered email for one notification_logs row, with its
 * reference number, status and test/production marker. Read-only; no raw
 * database errors are ever shown to the browser.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/email_templates.php';

// This page shows one Send history entry, so it is part of the "logs" feature.
// Gating it here (not just history.view) means disabling Send history for a
// user also blocks the per-message view — no back door via ?id=.
require_feature($conn, 'logs');

$logId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$logId) {
    $_SESSION['logs_flash'] = 'Select a communication from the list to view it.';
    header('Location: ' . BASE_URL . '/dashboard/logs.php'); exit;
}

$log = null;
try {
    $stmt = $conn->prepare("
        SELECT l.*, v.full_name, v.first_name, v.last_name,
               v.department_name, v.designation_name AS designation,
               v.dob_formatted, v.photo_path
        FROM notification_logs l
        LEFT JOIN v_employee_master_complete v ON v.emp_id = l.emp_id
        WHERE l.log_id = ? LIMIT 1
    ");
    $stmt->execute([$logId]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('view_sent query error: ' . $e->getMessage());   // logged, not shown
    $_SESSION['logs_flash'] = 'That communication could not be loaded.';
    header('Location: ' . BASE_URL . '/dashboard/logs.php'); exit;
}

if (!$log) {
    $_SESSION['logs_flash'] = 'Communication not found (it may have been cleared).';
    header('Location: ' . BASE_URL . '/dashboard/logs.php'); exit;
}

$name = trim((string)($log['full_name'] ?? ''));
if ($name === '') $name = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
if ($name === '') $name = 'Employee';

$templateId = (int)($log['template_id'] ?? 1);
if ($templateId < 1 || $templateId > 10) $templateId = 1;

$reference = $log['reference_id'] ?: ('COM-' . date('Y', strtotime((string)($log['created_at'] ?? 'now'))) . '-' . str_pad((string)$logId, 6, '0', STR_PAD_LEFT));
$isTest    = ($log['email_type'] ?? '') === 'test';
$status    = (string)($log['status'] ?? '');
$sentWhen  = htmlspecialchars((string)($log['created_at'] ?? $log['sent_on'] ?? '—'));

$html = getBirthdayEmailTemplate($templateId, [
    'full_name'        => $name,
    'department_name'  => $log['department_name'] ?? '',
    'designation_name' => $log['designation'] ?? '',
    'dob_formatted'    => $log['dob_formatted'] ?? '',
    'photo_path'       => $log['photo_path'] ?? '',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Communication <?= htmlspecialchars($reference) ?> | Birthday Communications</title>
<link rel="stylesheet" href="<?= htmlspecialchars(ASSETS_URL, ENT_QUOTES) ?>/css/styles.css">
<style>
  body{background:#e2e8f0;font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;padding:2rem;}
  .review-bar{max-width:640px;margin:0 auto 1rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;}
  .review-meta{font-size:.82rem;color:#475569;}
  .review-meta .ref{font-weight:700;color:#1a4fa0;}
  .pill{display:inline-block;padding:.15rem .5rem;border-radius:999px;font-size:.72rem;font-weight:700;}
  .pill.test{background:#fef3c7;color:#92400e;} .pill.prod{background:#dcfce7;color:#166534;}
  .email-frame{box-shadow:0 10px 25px rgba(0,0,0,.12);border-radius:16px;background:#fff;max-width:640px;margin:0 auto;overflow:hidden;}
  .btn{padding:.5rem 1rem;background:#fff;color:#1a4fa0;text-decoration:none;border-radius:8px;border:1px solid #c7d7ef;font-weight:600;font-size:.85rem;}
  @media print { .review-bar .btn { display:none; } body{background:#fff;padding:0;} .email-frame{box-shadow:none;} }
</style>
</head>
<body>
  <div class="review-bar">
    <a href="<?= BASE_URL ?>/dashboard/logs.php" class="btn">&larr; Back to send history</a>
    <div class="review-meta">
      <span class="ref"><?= htmlspecialchars($reference) ?></span> ·
      Status: <?= htmlspecialchars(ucfirst($status)) ?> ·
      <?= $isTest ? '<span class="pill test">TEST</span>' : '<span class="pill prod">PRODUCTION</span>' ?><br>
      Recorded: <?= $sentWhen ?> · Template #<?= $templateId ?>
      <?php if ($isTest): ?><br>Intended for a real employee — this was a TEST copy to the test recipient.<?php endif; ?>
    </div>
    <a href="#" onclick="window.print();return false;" class="btn">Print</a>
  </div>
  <div class="email-frame"><?= $html ?></div>
</body>
</html>
