<?php
/**
 * preview_email.php — Email Preview Engine.
 *
 *  • Gallery / dropdown of the 10 built-in responsive birthday templates.
 *  • Choose any employee; the template renders live with their real data
 *    (Name, Photo, Designation, Department, DOB) inside a preview iframe.
 *  • ?render=1&template=N&emp_id=... returns ONLY the email HTML (iframe src).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/rbac.php';

// Anyone signed in can preview templates; only managers may change the active one.
require_feature($conn, 'templates');

$catalog = birthdayTemplateCatalog();
$flash = '';

// ── Save the "active" template + delivery options (drives real sends) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && ($_POST['action'] ?? '') === 'set_active') {
    require_cap('templates.manage');
    $tpl = (int)($_POST['template'] ?? 1);
    if ($tpl < 1 || $tpl > 10) $tpl = 1;
    app_setting_set($conn, 'active_birthday_template', (string)$tpl);
    app_setting_set($conn, 'attach_poster',  isset($_POST['attach_poster'])  ? '1' : '0');
    app_setting_set($conn, 'send_broadcast', isset($_POST['send_broadcast']) ? '1' : '0');
    audit_log($conn, 'UPDATE', 'app_settings', 'birthday', null,
        ['active_template' => $tpl, 'attach_poster' => isset($_POST['attach_poster']), 'send_broadcast' => isset($_POST['send_broadcast'])]);
    $flash = 'Saved. Template ' . $tpl . ' (' . ($catalog[$tpl] ?? '') . ') is now used for all birthday emails.';
}

$activeTpl      = active_birthday_template($conn);
$attachPoster   = app_setting($conn, 'attach_poster', '1') === '1';
$sendBroadcast  = app_setting($conn, 'send_broadcast', '0') === '1';

/* ── Helper: fetch one employee row (or a sensible sample) ──────────── */
function fetchPreviewEmployee(PDO $conn, ?string $empId): array
{
    if ($empId) {
        $s = $conn->prepare("SELECT * FROM v_employee_master_complete WHERE emp_id = ? LIMIT 1");
        $s->execute([$empId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }
    $row = $conn->query("SELECT * FROM v_employee_master_complete ORDER BY full_name LIMIT 1")
                ->fetch(PDO::FETCH_ASSOC);
    return $row ?: [
        'full_name'        => 'Jamie Rivera',
        'department_name'  => 'Information Technology',
        'designation_name' => 'Deputy Manager',
        'dob_formatted'    => '12 Apr 1991',
        'photo_path'       => '',
        'current_age'      => 34,
    ];
}

/* ── RENDER MODE: output only the email HTML (used by the iframe) ─────
 * ?render=1&emp_id=...                     → active/selected birthday card
 * ?render=1&template=N&emp_id=...          → specific birthday template
 */
if (isset($_GET['render'])) {
    $tid = (int)($_GET['template'] ?? 1);
    if ($tid < 1 || $tid > 10) $tid = 1;
    $emp = fetchPreviewEmployee($conn, isset($_GET['emp_id']) ? trim($_GET['emp_id']) : null);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<style>body{margin:0;padding:24px;background:#e2e8f0;font-family:Arial,sans-serif;}</style></head><body>';
    echo getBirthdayEmailTemplate($tid, $emp);
    echo '</body></html>';
    exit;
}

/* ── PAGE MODE ──────────────────────────────────────────────────────── */
$employees = $conn->query("SELECT emp_id, full_name FROM v_employee_master_complete ORDER BY full_name ASC")
                  ->fetchAll(PDO::FETCH_ASSOC);

$selectedEmp = isset($_GET['emp_id']) ? trim($_GET['emp_id']) : ($employees[0]['emp_id'] ?? '');
$selectedTpl = (int)($_GET['template'] ?? $activeTpl);   // default to the active template
if ($selectedTpl < 1 || $selectedTpl > 10) $selectedTpl = 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Preview | BDayNotify</title>
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/styles.css">
<style>
  .preview-layout { display:grid; grid-template-columns: 320px 1fr; gap:1.25rem; align-items:start; }
  @media (max-width: 900px){ .preview-layout{ grid-template-columns:1fr; } }
  .tpl-gallery { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; margin-top:.75rem; }
  .tpl-chip { border:1px solid #e2e8f0; border-radius:8px; padding:.6rem .5rem; font-size:.78rem; cursor:pointer;
              background:#fff; text-align:center; transition:.15s; line-height:1.25; }
  .tpl-chip:hover { border-color:#93c5fd; background:#f0f7ff; }
  .tpl-chip.active { border-color:#1a4fa0; background:#1a4fa0; color:#fff; font-weight:600; }
  .tpl-chip .num { display:block; font-size:.7rem; opacity:.7; }
  .preview-frame-wrap { border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; background:#e2e8f0;
                        box-shadow:0 8px 24px rgba(0,0,0,.08); }
  .preview-frame-wrap iframe { width:100%; height:640px; border:0; display:block; background:#e2e8f0; }
  .field label { font-size:.8rem; color:#475569; font-weight:600; display:block; margin-bottom:.25rem; }
  .field select { width:100%; padding:.55rem .6rem; border:1px solid #cbd5e1; border-radius:6px; }
</style>
</head>
<body class="dashboard-page">
<?php
  $NAV_ACTIVE = 'preview';
  $BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'Email templates']];
  require __DIR__ . '/../includes/topbar.php';
?>

<main id="main-content" class="dashboard-content">
  <div class="page-header">
    <h1>Email templates</h1>
    <span class="page-sub">Choose the template every birthday email will use — this is exactly what recipients get.</span>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <div class="alert alert-info" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
    <strong>Active template:</strong>
    #<?= $activeTpl ?> — <?= htmlspecialchars($catalog[$activeTpl] ?? '', ENT_QUOTES) ?>.
    This is what "Send Now", scheduled sends and approvals will use.
    <?= $attachPoster ? '📎 Poster attached.' : 'Poster off.' ?>
  </div>

  <div class="panel">
    <div class="preview-layout">
      <!-- Controls -->
      <div>
        <div class="field" style="margin-bottom:1rem;">
          <label for="emp-select">Preview with employee (live data)</label>
          <select id="emp-select" onchange="refreshPreview()">
            <?php if (empty($employees)): ?>
              <option value="">No employees found</option>
            <?php else: foreach ($employees as $e): ?>
              <option value="<?= htmlspecialchars($e['emp_id'], ENT_QUOTES, 'UTF-8') ?>"
                <?= $e['emp_id'] === $selectedEmp ? 'selected' : '' ?>>
                <?= htmlspecialchars($e['full_name'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; endif; ?>
          </select>
        </div>

        <div class="field">
          <label for="tpl-select">Template</label>
          <select id="tpl-select" onchange="setTemplate(parseInt(this.value))">
            <?php foreach ($catalog as $id => $name): ?>
              <option value="<?= $id ?>" <?= $id === $selectedTpl ? 'selected' : '' ?>>
                <?= $id ?>. <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="tpl-gallery" id="tpl-gallery">
          <?php foreach ($catalog as $id => $name): ?>
            <div class="tpl-chip <?= $id === $selectedTpl ? 'active' : '' ?>" data-tpl="<?= $id ?>" onclick="setTemplate(<?= $id ?>)">
              <span class="num">Template <?= $id ?><?= $id === $activeTpl ? ' · active' : '' ?></span>
              <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Make the previewed template the one that actually sends (managers only) -->
        <?php if (user_can('templates.manage')): ?>
        <form method="POST" style="margin-top:1rem;border-top:1px solid #e2e8f0;padding-top:1rem;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_active">
          <input type="hidden" name="template" id="active-tpl-input" value="<?= $selectedTpl ?>">
          <label style="display:flex;align-items:center;gap:.5rem;margin:0 0 .5rem;font-size:.85rem;text-transform:none;letter-spacing:0;">
            <input type="checkbox" name="attach_poster" <?= $attachPoster ? 'checked' : '' ?> style="width:auto;"> Attach a personalized poster to each email
          </label>
          <label style="display:flex;align-items:center;gap:.5rem;margin:0 0 .8rem;font-size:.85rem;text-transform:none;letter-spacing:0;">
            <input type="checkbox" name="send_broadcast" <?= $sendBroadcast ? 'checked' : '' ?> style="width:auto;"> Also send a company-wide "today's birthdays" announcement
          </label>
          <button type="submit" class="btn-primary" style="width:100%;">✓ Use this template for all sends</button>
        </form>
        <?php endif; ?>

        <!-- Send a test of the currently-previewed template (senders only) -->
        <?php if (user_can('communications.send')): ?>
        <form method="POST" action="<?= BASE_URL ?>/dashboard/send_test.php" target="_blank" style="margin-top:.6rem;">
          <?= csrf_field() ?>
          <input type="hidden" name="emp_id" id="test-emp-input" value="<?= htmlspecialchars($selectedEmp, ENT_QUOTES) ?>">
          <input type="hidden" name="template_id" id="test-tpl-input" value="<?= $selectedTpl ?>">
          <button type="submit" class="btn-secondary" style="width:100%;"><i data-lucide="mail"></i> Send this as a test email</button>
        </form>
        <?php endif; ?>
        <?php if (!user_can('templates.manage') && !user_can('communications.send')): ?>
          <p class="text-muted" style="margin-top:1rem;border-top:1px solid #e2e8f0;padding-top:1rem;font-size:.8rem;">You can preview every template. Choosing the active template and sending tests require additional permissions from an administrator.</p>
        <?php endif; ?>
        <p class="text-muted" style="margin-top:.5rem;font-size:.75rem;">The preview on the right is the exact email body. A poster is attached on real sends when enabled.</p>
      </div>

      <!-- Live preview -->
      <div>
        <div class="preview-frame-wrap">
          <iframe id="preview-frame" title="Email live preview"></iframe>
        </div>
      </div>
    </div>
  </div>

  <!-- Poster attachment (relocated here under Content/Templates) -->
  <div class="panel" style="margin-top:24px;">
    <h2 style="font-size:16px;margin-bottom:2px;">Poster attachment</h2>
    <p class="page-sub" style="margin-bottom:12px;">
      A personalized poster is generated for each employee and attached to their birthday email
      <?= $attachPoster ? 'when sending' : '(currently disabled above)' ?>. Preview it for the selected employee.
    </p>
    <div style="border:1px solid var(--brand-border);border-radius:8px;overflow:hidden;max-width:620px;background:var(--surface-page);">
      <img id="poster-img" src="" alt="Birthday poster preview" style="width:100%;display:block;">
    </div>
    <div style="margin-top:10px;">
      <a id="poster-dl" class="btn-secondary" href="#" target="_blank"><i data-lucide="download"></i> Download poster</a>
    </div>
  </div>
</main>

<script>
  const BASE = '<?= BASE_URL ?>';
  let currentTpl = <?= $selectedTpl ?>;

  function syncInputs() {
    const emp = document.getElementById('emp-select').value;
    document.getElementById('active-tpl-input').value = currentTpl;
    document.getElementById('test-tpl-input').value = currentTpl;
    document.getElementById('test-emp-input').value = emp;
  }

  function refreshPreview() {
    const emp = document.getElementById('emp-select').value;
    const url = BASE + '/dashboard/preview_email.php?render=1&template=' + encodeURIComponent(currentTpl)
              + '&emp_id=' + encodeURIComponent(emp) + '&t=' + Date.now();
    document.getElementById('preview-frame').src = url;
    var posterUrl = BASE + '/dashboard/generate_posters.php?emp_id=' + encodeURIComponent(emp) + '&t=' + Date.now();
    var pimg = document.getElementById('poster-img'); if (pimg) pimg.src = posterUrl;
    var pdl = document.getElementById('poster-dl'); if (pdl) pdl.href = posterUrl + '&download=1';
    syncInputs();
  }

  function setTemplate(id) {
    currentTpl = id;
    document.getElementById('tpl-select').value = id;
    document.querySelectorAll('.tpl-chip').forEach(c => {
      c.classList.toggle('active', parseInt(c.dataset.tpl, 10) === id);
    });
    refreshPreview();
  }

  document.addEventListener('DOMContentLoaded', refreshPreview);
</script>
<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
</body>
</html>