<?php
// campaign_reports.php — list recent Keap broadcasts.

require_once(__DIR__ . '/includes/auth.php');
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/lib/keap_campaign_report.php');

require_auth();

$current_user = get_logged_in_user();
$days = isset($_GET['days']) ? max(7, min(365, (int)$_GET['days'])) : 90;

$error = null;
$broadcasts = [];
try {
    $broadcasts = kcr_list_broadcasts($days);
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Campaign Reports — LiveWright</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f6f8; margin: 0; color: #2c3e50; }
  header { background: #2c3e50; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
  header a { color: #fff; text-decoration: none; margin-left: 16px; font-size: 14px; }
  main { max-width: 1100px; margin: 24px auto; padding: 0 24px; }
  h1 { margin: 0 0 12px; font-size: 22px; }
  .subtitle { color: #6b7785; margin: 0 0 24px; font-size: 14px; }
  .filter { margin-bottom: 16px; font-size: 14px; }
  .filter select, .filter input { padding: 6px 8px; font-size: 14px; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
  th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eef0f3; vertical-align: top; font-size: 14px; }
  th { background: #f0f2f5; font-weight: 600; color: #55606d; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; }
  tr:last-child td { border-bottom: none; }
  .subject { font-weight: 600; }
  .muted { color: #90a0b0; font-size: 12px; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .pct { color: #6b7785; font-size: 12px; }
  .btn { display: inline-block; padding: 6px 12px; background: #3498db; color: #fff; border-radius: 4px; text-decoration: none; font-size: 13px; }
  .btn:hover { background: #2980b9; }
  .error { background: #fde2e2; color: #7a2020; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
  .empty { background: #fff; padding: 40px; text-align: center; color: #90a0b0; border-radius: 6px; }
</style>
</head>
<body>
<header>
  <div>
    <strong>LiveWright</strong>
    <a href="index.php">Roster</a>
    <a href="campaign_reports.php">Campaign Reports</a>
  </div>
  <div>
    <span style="opacity:.8; font-size:13px;"><?= htmlspecialchars($current_user['name']) ?></span>
    <a href="logout.php">Logout</a>
  </div>
</header>
<main>
  <h1>Campaign Reports</h1>
  <p class="subtitle">Keap broadcasts sent in the last <?= (int)$days ?> days. Click a row to generate a report.</p>

  <form class="filter" method="get">
    <label>Look back:
      <select name="days" onchange="this.form.submit()">
        <?php foreach ([30, 60, 90, 180, 365] as $d): ?>
          <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>>Last <?= $d ?> days</option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>

  <?php if ($error): ?>
    <div class="error">Error loading broadcasts: <?= htmlspecialchars($error) ?></div>
  <?php elseif (empty($broadcasts)): ?>
    <div class="empty">No broadcasts found in this window. Try a longer look-back.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Sent</th>
          <th>Subject</th>
          <th>From</th>
          <th class="num">Recipients</th>
          <th class="num">Opens</th>
          <th class="num">Clicks</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($broadcasts as $b):
        $open_rate  = $b['recipients'] ? round(100 * $b['opens']  / $b['recipients'], 1) : 0;
        $click_rate = $b['recipients'] ? round(100 * $b['clicks'] / $b['recipients'], 1) : 0;
      ?>
        <tr>
          <td><?= htmlspecialchars($b['sent_date']) ?></td>
          <td class="subject"><?= htmlspecialchars($b['subject']) ?></td>
          <td class="muted"><?= htmlspecialchars($b['sent_from']) ?></td>
          <td class="num"><?= number_format($b['recipients']) ?></td>
          <td class="num"><?= number_format($b['opens']) ?> <span class="pct">(<?= $open_rate ?>%)</span></td>
          <td class="num"><?= number_format($b['clicks']) ?> <span class="pct">(<?= $click_rate ?>%)</span></td>
          <td><a class="btn" href="campaign_report.php?key=<?= urlencode($b['key']) ?>">Report</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>
</body>
</html>
