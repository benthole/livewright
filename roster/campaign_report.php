<?php
// campaign_report.php — render a single broadcast report as HTML or JSON.

require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/lib/keap_campaign_report.php');

$format = $_GET['format'] ?? 'html';
$key    = $_GET['key']    ?? '';
$print  = !empty($_GET['print']);

if ($key === '' || !preg_match('/^[a-f0-9]{8,32}$/', $key)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing or invalid broadcast key']);
    exit;
}

// Auth: HTML requires session login; JSON can use bearer token OR session.
if ($format === 'json') {
    $token_hdr = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $token_hdr = trim($m[1]);
        }
    }
    $token_qs = $_GET['token'] ?? '';
    $provided = $token_hdr !== '' ? $token_hdr : $token_qs;
    $expected = $campaign_report_api_token ?? '';

    $auth_ok = false;
    if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
        $auth_ok = true;
    } else {
        require_once(__DIR__ . '/includes/auth.php');
        if (is_logged_in()) $auth_ok = true;
    }
    if (!$auth_ok) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
} else {
    require_once(__DIR__ . '/includes/auth.php');
    require_auth();
}

$config = [
    'consumer_email_domains' => $consumer_email_domains ?? [],
    'vip_tag_ids'            => $vip_tag_ids            ?? [],
    'test_email_patterns'    => $test_email_patterns    ?? [],
];

$report = kcr_build_report($key, $config);

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// HTML render.
if (isset($report['error'])) {
    http_response_code(404);
    echo '<!doctype html><meta charset=utf-8><p style="font-family:sans-serif; padding:40px;">' . htmlspecialchars($report['error']) . '</p>';
    exit;
}

$broadcast = $report['broadcast'];
$m = $report['metrics'];
$seg = $report['segmentation'];
$pct = function ($v) { return number_format($v * 100, 1) . '%'; };
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Campaign Report — <?= htmlspecialchars($broadcast['subject']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; background: #f5f6f8; margin: 0; color: #2c3e50; }
  header { background: #2c3e50; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
  header a, header button { color: #fff; text-decoration: none; margin-left: 16px; font-size: 14px; background: none; border: 1px solid rgba(255,255,255,.3); padding: 6px 12px; border-radius: 4px; cursor: pointer; font-family: inherit; }
  main { max-width: 960px; margin: 24px auto; padding: 0 24px; }
  .card { background: #fff; border-radius: 8px; padding: 20px 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
  h1 { font-size: 24px; margin: 0 0 4px; }
  h2 { font-size: 18px; margin: 0 0 12px; color: #34495e; }
  h3 { font-size: 15px; margin: 16px 0 8px; color: #55606d; }
  .meta { color: #6b7785; font-size: 13px; margin-bottom: 16px; }
  .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
  .kpi { background: #f6f8fa; padding: 14px; border-radius: 6px; text-align: center; }
  .kpi .num { font-size: 24px; font-weight: 700; color: #2c3e50; }
  .kpi .lbl { font-size: 12px; color: #90a0b0; text-transform: uppercase; letter-spacing: .5px; margin-top: 4px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #eef0f3; vertical-align: top; }
  th { background: #f6f8fa; font-size: 12px; text-transform: uppercase; color: #55606d; letter-spacing: .4px; }
  .tag { display: inline-block; padding: 2px 8px; background: #e8f4fa; color: #2c6a8c; border-radius: 10px; font-size: 11px; margin-right: 4px; }
  .tag.hot { background: #ffe8d9; color: #a64b00; }
  .tag.warn { background: #fff2cc; color: #7a5800; }
  .empty { color: #90a0b0; font-style: italic; padding: 12px 0; }
  .muted { color: #90a0b0; }
  details summary { cursor: pointer; font-weight: 600; color: #34495e; padding: 8px 0; }
  @media print {
    header, .no-print { display: none !important; }
    body { background: #fff; }
    .card { box-shadow: none; border: 1px solid #e0e4e8; page-break-inside: avoid; }
    main { max-width: 100%; margin: 0; padding: 0; }
  }
</style>
<?php if ($print): ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
<?php endif; ?>
</head>
<body>
<header class="no-print">
  <div>
    <strong>LiveWright</strong>
    <a href="campaign_reports.php">&larr; All Reports</a>
  </div>
  <div>
    <button onclick="window.print()">Print / Save as PDF</button>
    <a href="campaign_report.php?key=<?= urlencode($key) ?>&format=json">JSON</a>
    <a href="logout.php">Logout</a>
  </div>
</header>
<main>
  <div class="card">
    <h1><?= htmlspecialchars($broadcast['subject']) ?></h1>
    <div class="meta">
      Sent <?= htmlspecialchars($broadcast['sent_date']) ?> from <?= htmlspecialchars($broadcast['sent_from']) ?>
      &middot; Generated <?= htmlspecialchars(substr($report['generated_at'], 0, 16)) ?>Z
    </div>
    <div class="kpi-row">
      <div class="kpi"><div class="num"><?= number_format($m['delivered']) ?></div><div class="lbl">Delivered</div></div>
      <div class="kpi"><div class="num"><?= number_format($m['opens']) ?></div><div class="lbl">Opens (<?= $pct($m['open_rate']) ?>)</div></div>
      <div class="kpi"><div class="num"><?= number_format($m['clicks']) ?></div><div class="lbl">Clicks (<?= $pct($m['click_rate']) ?>)</div></div>
      <div class="kpi"><div class="num"><?= number_format($m['unopened']) ?></div><div class="lbl">Unopened</div></div>
      <div class="kpi"><div class="num"><?= number_format($m['opt_outs']) ?></div><div class="lbl">Opt-outs</div></div>
    </div>
  </div>

  <div class="card">
    <h2>Audience segmentation</h2>
    <table>
      <thead><tr><th>Segment</th><th class="num">Size</th><th class="num">Open rate</th><th class="num">Click rate</th></tr></thead>
      <tbody>
        <tr>
          <td>Consumer domains (gmail, yahoo, etc.)</td>
          <td><?= number_format($seg['consumer']['size']) ?></td>
          <td><?= $pct($seg['consumer']['open_rate']) ?></td>
          <td><?= $pct($seg['consumer']['click_rate']) ?></td>
        </tr>
        <tr>
          <td>Professional / organization domains</td>
          <td><?= number_format($seg['professional']['size']) ?></td>
          <td><?= $pct($seg['professional']['open_rate']) ?></td>
          <td><?= $pct($seg['professional']['click_rate']) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2>Clickers (<?= count($report['clickers']) ?>)</h2>
    <?php if (empty($report['clickers'])): ?>
      <div class="empty">No clicks recorded.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>Name</th><th>Email</th><th>Organization</th><th>Clicked at</th></tr></thead>
        <tbody>
        <?php foreach ($report['clickers'] as $c): ?>
          <tr>
            <td><?= htmlspecialchars($c['name']) ?></td>
            <td><?= htmlspecialchars($c['email']) ?></td>
            <td class="muted"><?= htmlspecialchars($c['organization']) ?></td>
            <td class="muted"><?= htmlspecialchars(substr($c['clicked_at'] ?? '', 0, 16)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if (!empty($report['vip_callouts'])): ?>
  <div class="card">
    <h2>VIP callouts</h2>
    <?php $any = false; foreach ($report['vip_callouts'] as $label => $list): if (empty($list)) continue; $any = true; ?>
      <h3><?= htmlspecialchars(str_replace('_', ' ', ucwords($label, '_'))) ?> (<?= count($list) ?>)</h3>
      <table>
        <thead><tr><th>Name</th><th>Email</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($list as $v): ?>
          <tr>
            <td><?= htmlspecialchars($v['name']) ?></td>
            <td><?= htmlspecialchars($v['email']) ?></td>
            <td>
              <?= $v['clicked'] ? '<span class="tag hot">Clicked</span>' : '' ?>
              <?= $v['opened'] && !$v['clicked'] ? '<span class="tag">Opened</span>' : '' ?>
              <?= !$v['opened'] ? '<span class="tag warn">Did not open</span>' : '' ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; if (!$any): ?>
      <div class="empty">No VIP tags configured or no tagged contacts in this broadcast. Set <code>$vip_tag_ids</code> in <code>roster/config.php</code>.</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Top professional openers (non-clickers)</h2>
    <p class="muted">Candidates for personal follow-up.</p>
    <?php if (empty($report['top_openers_professional'])): ?>
      <div class="empty">None.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>Name</th><th>Email</th><th>Organization</th></tr></thead>
        <tbody>
        <?php foreach ($report['top_openers_professional'] as $o): ?>
          <tr>
            <td><?= htmlspecialchars($o['name']) ?></td>
            <td><?= htmlspecialchars($o['email']) ?></td>
            <td class="muted"><?= htmlspecialchars($o['organization']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>List hygiene</h2>
    <?php
    $h = $report['hygiene'];
    $sections = [
      'missing_name'  => 'Missing first name',
      'all_caps_name' => 'ALL-CAPS first name',
      'duplicates'    => 'Duplicate contact records (same email, multiple IDs)',
      'test_junk'     => 'Test / internal addresses',
      'international' => 'International contacts',
      'opt_outs'      => 'Opt-outs in this send',
    ];
    foreach ($sections as $slug => $label): ?>
      <details <?= !empty($h[$slug]) ? 'open' : '' ?>>
        <summary><?= htmlspecialchars($label) ?> (<?= count($h[$slug]) ?>)</summary>
        <?php if (empty($h[$slug])): ?>
          <div class="empty">None.</div>
        <?php else: ?>
          <table>
            <tbody>
            <?php foreach (array_slice($h[$slug], 0, 100) as $row): ?>
              <tr>
                <td>
                  <?php
                  if (isset($row['name'])) echo htmlspecialchars($row['name']) . ' &middot; ';
                  echo htmlspecialchars($row['email'] ?? '');
                  if (isset($row['contact_ids'])) echo ' <span class="muted">(IDs: ' . htmlspecialchars(implode(', ', $row['contact_ids'])) . ')</span>';
                  if (isset($row['status']))      echo ' <span class="muted">[' . htmlspecialchars($row['status']) . ']</span>';
                  ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (count($h[$slug]) > 100): ?>
            <div class="muted">(+<?= count($h[$slug]) - 100 ?> more)</div>
          <?php endif; ?>
        <?php endif; ?>
      </details>
    <?php endforeach; ?>
  </div>
</main>
</body>
</html>
