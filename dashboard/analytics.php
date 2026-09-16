<?php
/**
 * analytics.php — Email delivery & engagement analytics.
 *
 * All metrics are derived from notification_logs (send/fail/open tracking) and
 * employee_master (department distribution). Read-only, safe to open anytime.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rbac.php';

require_feature($conn, 'analytics');

// ── Defaults so the page always renders even if a query fails ──────
$totSent = $totFailed = $totOpened = $totSkipped = 0;
$successRate = $openRate = 0.0;
$trendLabels = $trendSent = $trendFailed = [];
$deptLabels  = $deptCounts = [];
$tplRows = [];
$recent  = [];
$err = null;

try {
    // 1. Lifetime status totals
    $rows = $conn->query("
        SELECT status, COUNT(*) AS c
        FROM notification_logs
        GROUP BY status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    $totSent    = (int)($rows['sent']    ?? 0);
    $totFailed  = (int)($rows['failed']  ?? 0);
    $totSkipped = (int)($rows['skipped'] ?? 0);

    // Opened = sent rows that have an open timestamp
    $totOpened = (int)$conn->query("
        SELECT COUNT(*) FROM notification_logs
        WHERE status = 'sent' AND email_opened_at IS NOT NULL
    ")->fetchColumn();

    $attempted   = $totSent + $totFailed;
    $successRate = $attempted > 0 ? round($totSent / $attempted * 100, 1) : 0.0;
    $openRate    = $totSent   > 0 ? round($totOpened / $totSent * 100, 1) : 0.0;

    // 2. Last 14 days trend (sent vs failed per day)
    $trend = $conn->query("
        SELECT sent_on,
               SUM(status = 'sent')   AS sent_c,
               SUM(status = 'failed') AS fail_c
        FROM notification_logs
        WHERE sent_on >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY sent_on
        ORDER BY sent_on ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Build a continuous 14-day axis (fill gaps with 0)
    $map = [];
    foreach ($trend as $r) $map[$r['sent_on']] = $r;
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $trendLabels[] = date('M j', strtotime($d));
        $trendSent[]   = (int)($map[$d]['sent_c'] ?? 0);
        $trendFailed[] = (int)($map[$d]['fail_c'] ?? 0);
    }

    // 3. Department distribution (active employees)
    $dept = $conn->query("
        SELECT department_name, COUNT(*) AS c
        FROM employee_master
        WHERE is_deleted = 0 AND LOWER(status) = 'active'
              AND department_name IS NOT NULL AND department_name <> ''
        GROUP BY department_name
        ORDER BY c DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dept as $d) { $deptLabels[] = $d['department_name']; $deptCounts[] = (int)$d['c']; }

    // 4. Template performance (sends + opens per template)
    $tplRows = $conn->query("
        SELECT
            COALESCE(template_id, 0)                              AS template_id,
            COUNT(*)                                             AS sends,
            SUM(email_opened_at IS NOT NULL)                     AS opens
        FROM notification_logs
        WHERE status = 'sent'
        GROUP BY COALESCE(template_id, 0)
        ORDER BY sends DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 5. Recent activity
    $recent = $conn->query("
        SELECT nl.created_at, nl.recipient_address, nl.subject, nl.status,
               nl.email_opened_at, e.full_name
        FROM notification_logs nl
        LEFT JOIN employee_master e ON e.emp_id = nl.emp_id
        ORDER BY nl.created_at DESC
        LIMIT 15
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $err = $e->getMessage();
}

// ── Birthday insights (independent of the email metrics above) ──────
$bdayToday = $bdayUpcoming7 = $bdayUpcoming30 = 0;
$monthCounts = array_fill(1, 12, 0);
$bdayByDept  = [];
try {
    $bdayToday      = (int) $conn->query("SELECT COUNT(*) FROM v_todays_birthdays")->fetchColumn();
    $bdayUpcoming7  = (int) $conn->query("SELECT COUNT(*) FROM v_upcoming_birthdays WHERE days_away <= 7")->fetchColumn();
    $bdayUpcoming30 = (int) $conn->query("SELECT COUNT(*) FROM v_upcoming_birthdays")->fetchColumn();
    foreach ($conn->query("SELECT MONTH(dm.date_of_birth) AS m, COUNT(*) AS c
                           FROM dob_master dm
                           JOIN employee_master e ON e.emp_id = dm.emp_id
                           WHERE e.is_deleted = 0 AND LOWER(e.status) = 'active'
                           GROUP BY MONTH(dm.date_of_birth)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mm = (int) $r['m'];
        if ($mm >= 1 && $mm <= 12) { $monthCounts[$mm] = (int) $r['c']; }
    }
    $bdayByDept = $conn->query("SELECT department_name, COUNT(*) AS c
                                FROM v_upcoming_birthdays
                                GROUP BY department_name ORDER BY c DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* birthday insights are optional; ignore */ }
$monthMax   = max(1, max($monthCounts));
$monthNames = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
               7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

$templateNames = [
    1 => 'Classic Corporate', 2 => 'Vibrant Confetti', 3 => 'Executive Gold',
    4 => 'Warm Typography', 5 => 'Tech / Cyber', 6 => 'Festive Balloons',
    7 => 'Gradient Wave', 8 => 'Dark Premium', 9 => 'Clean Card', 10 => 'Party Banner',
];
$jf = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics | BDayNotify</title>
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/styles.css">
<script src="<?= ASSETS_URL ?>/js/vendor/chart.umd.min.js"></script>
<style>
  .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.25rem;}
  .kpi{background:var(--w);border:1px solid var(--g2);border-radius:var(--rad);padding:1.25rem;box-shadow:var(--sh);position:relative;overflow:hidden;}
  .kpi::after{content:'';position:absolute;right:-14px;top:-14px;width:60px;height:60px;border-radius:50%;opacity:.10;}
  .kpi.k-sent::after{background:var(--brand-primary);} .kpi.k-open::after{background:var(--brand-soft);}
  .kpi.k-fail::after{background:var(--brand-muted);} .kpi.k-rate::after{background:var(--brand-primary);}
  .kpi .kpi-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--g4);}
  .kpi .kpi-value{font-size:2.1rem;font-weight:800;line-height:1.1;margin-top:.35rem;color:var(--g8);}
  .kpi .kpi-sub{font-size:.75rem;color:var(--g4);margin-top:.25rem;}
  .ring{width:74px;height:74px;border-radius:50%;background:var(--brand-tint);border:3px solid var(--brand-primary);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .ring-inner{width:56px;height:56px;border-radius:50%;background:var(--w);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.95rem;color:var(--g8);}
  .rate-card{display:flex;align-items:center;gap:1rem;}
  .analytics-charts{display:grid;grid-template-columns:2fr 1fr;gap:1rem;margin-bottom:1.25rem;}
  .chart-card{background:var(--w);border:1px solid var(--g2);border-radius:var(--rad);padding:1.25rem;box-shadow:var(--sh);}
  .chart-card h3{font-size:.85rem;font-weight:700;margin-bottom:.9rem;color:var(--g8);}
  .chart-card canvas{max-height:280px;}
  @media(max-width:900px){.analytics-charts{grid-template-columns:1fr;}}
</style>
</head>
<body class="dashboard-page">
<?php
  $NAV_ACTIVE = 'analytics';
  $BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'Analytics']];
  require __DIR__ . '/../includes/topbar.php';
?>

<main id="main-content" class="dashboard-content">
  <div class="page-header">
    <h1>Analytics</h1>
    <a href="<?= BASE_URL ?>/dashboard/logs.php" class="btn-secondary">View full logs →</a>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-error">Analytics query error: <?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <!-- Birthday insights -->
  <h2 style="margin:.25rem 0 .75rem;font-size:1.05rem;">Birthday insights</h2>
  <div class="kpi-grid">
    <div class="kpi k-sent"><div class="kpi-label">Birthdays today</div><div class="kpi-value"><?= number_format($bdayToday) ?></div><div class="kpi-sub">celebrated today</div></div>
    <div class="kpi k-open"><div class="kpi-label">Next 7 days</div><div class="kpi-value"><?= number_format($bdayUpcoming7) ?></div><div class="kpi-sub">upcoming birthdays</div></div>
    <div class="kpi k-rate"><div class="kpi-label">Next 30 days</div><div class="kpi-value"><?= number_format($bdayUpcoming30) ?></div><div class="kpi-sub">upcoming birthdays</div></div>
  </div>

  <div class="analytics-charts">
    <div class="chart-card">
      <h3>Birthdays by month (active employees)</h3>
      <div style="display:flex;flex-direction:column;gap:.4rem;">
        <?php foreach ($monthNames as $mi => $mn): $c = $monthCounts[$mi]; $pct = (int) round($c / $monthMax * 100); ?>
          <div style="display:flex;align-items:center;gap:.6rem;">
            <span style="width:34px;font-size:.78rem;color:#64748b;"><?= $mn ?></span>
            <div style="flex:1;background:#eef2f7;border-radius:6px;height:16px;overflow:hidden;"><div style="width:<?= $pct ?>%;height:100%;background:#2772CD;border-radius:6px;"></div></div>
            <span style="width:26px;text-align:right;font-size:.78rem;font-weight:600;"><?= $c ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="chart-card">
      <h3>Upcoming (30 days) by department</h3>
      <?php if (empty($bdayByDept)): ?>
        <p class="empty-state">No upcoming birthdays in the next 30 days.</p>
      <?php else: $dmax = max(array_map(fn($r) => (int) $r['c'], $bdayByDept)); ?>
        <div style="display:flex;flex-direction:column;gap:.5rem;">
          <?php foreach ($bdayByDept as $r): $pct = (int) round((int) $r['c'] / max(1, $dmax) * 100); ?>
            <div style="display:flex;align-items:center;gap:.6rem;">
              <span style="flex:0 0 140px;font-size:.78rem;color:#334155;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($r['department_name'] ?: '—') ?></span>
              <div style="flex:1;background:#eef2f7;border-radius:6px;height:16px;overflow:hidden;"><div style="width:<?= $pct ?>%;height:100%;background:#588ED4;border-radius:6px;"></div></div>
              <span style="width:26px;text-align:right;font-size:.78rem;font-weight:600;"><?= (int) $r['c'] ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <h2 style="margin:1.5rem 0 .75rem;font-size:1.05rem;">Email delivery</h2>

  <!-- KPI cards -->
  <div class="kpi-grid">
    <div class="kpi k-sent">
      <div class="kpi-label">Emails Sent</div>
      <div class="kpi-value"><?= number_format($totSent) ?></div>
      <div class="kpi-sub">Lifetime successful sends</div>
    </div>
    <div class="kpi k-open">
      <div class="kpi-label">Opened</div>
      <div class="kpi-value"><?= number_format($totOpened) ?></div>
      <div class="kpi-sub"><?= $openRate ?>% open rate</div>
    </div>
    <div class="kpi k-fail">
      <div class="kpi-label">Failed</div>
      <div class="kpi-value"><?= number_format($totFailed) ?></div>
      <div class="kpi-sub"><?= number_format($totSkipped) ?> skipped</div>
    </div>
    <div class="kpi k-rate">
      <div class="rate-card">
        <div class="ring" style="--p:<?= $successRate ?>">
          <div class="ring-inner"><?= $successRate ?>%</div>
        </div>
        <div>
          <div class="kpi-label">Success Rate</div>
          <div class="kpi-sub" style="margin-top:.4rem;"><?= number_format($totSent) ?> of <?= number_format($totSent + $totFailed) ?> attempts</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="analytics-charts">
    <div class="chart-card">
      <h3>Delivery trend — last 14 days</h3>
      <canvas id="trendChart"></canvas>
      
    </div>

    
    <div class="chart-card">
      <h3>Status breakdown</h3>
      <canvas id="statusChart"></canvas>
    </div>
  </div>

  <div class="analytics-charts">
    <div class="chart-card">
      <h3>Employees by department</h3>
      <canvas id="deptChart"></canvas>
    </div>
    <div class="chart-card">
      <h3>Template performance</h3>
      <?php if (empty($tplRows)): ?>
        <p class="empty-state">No sends recorded yet.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Template</th><th>Sends</th><th>Opens</th><th>Open %</th></tr></thead>
            <tbody>
            <?php foreach ($tplRows as $t):
              $tid = (int)$t['template_id'];
              $name = $templateNames[$tid] ?? ($tid === 0 ? 'Unspecified' : "Template #$tid");
              $op = (int)$t['sends'] > 0 ? round($t['opens'] / $t['sends'] * 100) : 0; ?>
              <tr>
                <td><?= htmlspecialchars($name) ?></td>
                <td><?= number_format((int)$t['sends']) ?></td>
                <td><?= number_format((int)$t['opens']) ?></td>
                <td><span class="badge badge-blue"><?= $op ?>%</span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent activity -->
  <div class="panel">
    <h2>Recent activity</h2>
    <?php if (empty($recent)): ?>
      <p class="empty-state">No email activity yet. Send a test from the Employees page to populate analytics.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>When</th><th>Employee</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Opened</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td><?= htmlspecialchars(date('M j, H:i', strtotime($r['created_at']))) ?></td>
              <td><?= htmlspecialchars($r['full_name'] ?? '—') ?></td>
              <td><?= htmlspecialchars($r['recipient_address']) ?></td>
              <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($r['subject'] ?? '—') ?></td>
              <td>
                <span class="badge <?= $r['status'] === 'sent' ? 'badge-active' : ($r['status'] === 'failed' ? 'badge-inactive' : 'badge-blue') ?>">
                  <?= htmlspecialchars(ucfirst($r['status'])) ?>
                </span>
              </td>
              <td><?= $r['email_opened_at'] ? '<i data-lucide="eye" style="width:14px;height:14px;vertical-align:-2px;"></i> ' . htmlspecialchars(date('M j', strtotime($r['email_opened_at']))) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</main>

<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
<script src="<?= ASSETS_URL ?>/js/toast.js"></script>
<script>
const CHART_FONT = '"Segoe UI", Inter, system-ui, sans-serif';
Chart.defaults.font.family = CHART_FONT;
Chart.defaults.color = '#595A5F';
// Approved palette only (no red/purple/green).
const C_PRIMARY = '#2772CD', C_DARK = '#215FB2', C_SOFT = '#588ED4', C_MUTED = '#98B3D8', C_SLATE = '#354153';

// Delivery trend (line)
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?= $jf($trendLabels) ?>,
    datasets: [
      { label: 'Sent', data: <?= $jf($trendSent) ?>, borderColor: C_PRIMARY, backgroundColor: 'rgba(39,114,205,.12)', fill: true, tension: .35, borderWidth: 2, pointRadius: 3 },
      { label: 'Failed', data: <?= $jf($trendFailed) ?>, borderColor: C_SLATE, backgroundColor: 'rgba(53,65,83,.08)', fill: true, tension: .35, borderWidth: 2, pointRadius: 3, borderDash: [4,3] }
    ]
  },
  options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

// Status breakdown (doughnut)
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: ['Sent', 'Opened', 'Failed', 'Skipped'],
    datasets: [{ data: [<?= (int)$totSent ?>, <?= (int)$totOpened ?>, <?= (int)$totFailed ?>, <?= (int)$totSkipped ?>],
      backgroundColor: [C_PRIMARY, C_SOFT, C_SLATE, C_MUTED], borderWidth: 0 }]
  },
  options: { responsive: true, cutout: '62%', plugins: { legend: { position: 'bottom' } } }
});

// Department distribution (bar)
const deptLabels = <?= $jf($deptLabels) ?>;
if (deptLabels.length) {
  new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: { labels: deptLabels, datasets: [{ label: 'Employees', data: <?= $jf($deptCounts) ?>,
      backgroundColor: C_PRIMARY, borderRadius: 4, maxBarThickness: 34 }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
  });
} else {
  document.getElementById('deptChart').closest('.chart-card').querySelector('canvas').outerHTML =
    '<p class="empty-state">No department data.</p>';
}
</script>
</body>
</html>