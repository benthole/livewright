<?php
/**
 * Scheduled Drops admin page.
 *
 * Shows pending scheduled drops (created via drop_contact.php with a
 * `scheduled_for` date). Admins can:
 *   - Cancel a pending drop
 *   - Run all due drops immediately ("Process due now")
 *
 * The same processing logic also runs from process_scheduled_drops.php
 * via cron.
 */

require_once('includes/auth.php');
require_once('keap_api.php');
require_once('config.php');
require_once(__DIR__ . '/lib/drop_helpers.php');

require_editor();

try {
    $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

$flash = '';
$flashType = '';

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE scheduled_drops SET status = 'cancelled' WHERE id = ? AND status = 'pending'");
            $stmt->execute([$id]);
            $flash = 'Scheduled drop cancelled.';
            $flashType = 'success';
        }
    } elseif ($action === 'process_due') {
        $token = get_keap_token();
        if (!$token) {
            $flash = 'Could not get Keap API token.';
            $flashType = 'error';
        } else {
            $today = date('Y-m-d');
            $due = $conn->prepare("SELECT * FROM scheduled_drops WHERE status = 'pending' AND scheduled_for <= ? ORDER BY scheduled_for, id");
            $due->execute([$today]);
            $rows = $due->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                $flash = 'No drops are due to run today.';
                $flashType = 'info';
            } else {
                $rosterByContactId = drop_load_roster_cache($conn);
                $currentUser = get_logged_in_user();
                $actor = $currentUser ? ($currentUser['email'] ?? $currentUser['name'] ?? 'unknown') : 'unknown';

                $cfg = [
                    'cohort_field_id' => $cohort_field_id,
                    'individual_coach_field_id' => $individual_coach_field_id,
                    'individual_coach_field_id_old' => $individual_coach_field_id_old ?? null,
                    'group_coach_field_id' => $group_coach_field_id,
                    'coaching_files_field_id' => $coaching_files_field_id,
                    'drop_tag_id' => $drop_tag_id,
                ];

                $processed = 0;
                $failed = 0;
                $upd = $conn->prepare("UPDATE scheduled_drops SET status = ?, processed_at = NOW(), notes = ? WHERE id = ?");

                foreach ($rows as $row) {
                    $r = drop_execute_one($conn, (int)$row['keap_contact_id'], $token, $cfg, $rosterByContactId, $actor);
                    if ($r['success']) {
                        $upd->execute(['processed', json_encode(['warnings' => $r['warnings']]), $row['id']]);
                        $processed++;
                    } else {
                        $upd->execute(['failed', $r['error'] ?? 'unknown error', $row['id']]);
                        $failed++;
                    }
                }
                $flash = "Processed {$processed} drop(s)." . ($failed ? " {$failed} failed." : '');
                $flashType = $failed ? 'error' : 'success';
            }
        }
    }
}

// ---------- Load lists for display ----------
$pending = $conn->query("SELECT * FROM scheduled_drops WHERE status = 'pending' ORDER BY scheduled_for, id")->fetchAll(PDO::FETCH_ASSOC);
$recent = $conn->query("SELECT * FROM scheduled_drops WHERE status IN ('processed','failed','cancelled') ORDER BY processed_at DESC, id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
$dueCount = 0;
foreach ($pending as $p) {
    if ($p['scheduled_for'] <= $today) $dueCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Scheduled Drops · LiveWright Roster</title>
<style>
    body { font-family: -apple-system, "Segoe UI", Arial, sans-serif; background: #f5f7fa; color: #1d1d1f; margin: 0; padding: 24px; }
    .wrap { max-width: 1100px; margin: 0 auto; }
    h1 { color: #2c3e50; margin: 0 0 6px; }
    a { color: #2c3e50; }
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
    .flash { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
    .flash.success { background: #e6f7ec; color: #248a3d; border: 1px solid #b6e3c5; }
    .flash.error { background: #fde7e7; color: #c0392b; border: 1px solid #f5b7b7; }
    .flash.info { background: #eaf3fb; color: #1f5380; border: 1px solid #b6d4ed; }
    table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 28px; }
    th, td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; text-align: left; vertical-align: top; font-size: 14px; }
    th { background: #f8fafc; color: #475569; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; }
    tr:last-child td { border-bottom: none; }
    .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .pill-pending { background: #fff3cd; color: #856404; }
    .pill-due { background: #fde7e7; color: #c0392b; }
    .pill-processed { background: #e6f7ec; color: #248a3d; }
    .pill-cancelled { background: #f1f5f9; color: #64748b; }
    .pill-failed { background: #fde7e7; color: #c0392b; }
    .btn { background: #2c3e50; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-danger { background: #c0392b; }
    .btn-secondary { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
    .empty { color: #94a3b8; font-style: italic; padding: 16px; }
</style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div>
            <h1>Scheduled Drops</h1>
            <a href="./" style="font-size: 13px;">← Back to roster</a>
        </div>
        <form method="POST" onsubmit="return confirm('Process all drops that are due (<?= htmlspecialchars($today) ?>)?');">
            <input type="hidden" name="action" value="process_due">
            <button class="btn btn-danger" type="submit" <?= $dueCount === 0 ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '' ?>>
                Process due now
                <?php if ($dueCount > 0): ?>(<?= $dueCount ?>)<?php endif; ?>
            </button>
        </form>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <h2 style="font-size: 16px; color: #475569; margin: 0 0 8px;">Pending</h2>
    <?php if (empty($pending)): ?>
        <div class="empty">No pending scheduled drops.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Scheduled for</th>
                    <th>Contact</th>
                    <th>Email</th>
                    <th>Team (snapshot)</th>
                    <th>Scheduled by</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending as $row):
                    $isDue = $row['scheduled_for'] <= $today;
                    $snap = $row['snapshot'] ? json_decode($row['snapshot'], true) : [];
                ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars(date('M j, Y', strtotime($row['scheduled_for']))) ?>
                            <?php if ($isDue): ?>
                                <span class="pill pill-due">DUE</span>
                            <?php else: ?>
                                <span class="pill pill-pending">pending</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['contact_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['contact_email'] ?? '') ?></td>
                        <td><?= htmlspecialchars($snap['team'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['scheduled_by'] ?? '') ?></td>
                        <td><?= htmlspecialchars(date('M j, Y', strtotime($row['created_at']))) ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Cancel this scheduled drop?');" style="display:inline;">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button class="btn btn-secondary" type="submit">Cancel</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2 style="font-size: 16px; color: #475569; margin: 24px 0 8px;">Recent (last 25)</h2>
    <?php if (empty($recent)): ?>
        <div class="empty">Nothing recent.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Scheduled for</th>
                    <th>Contact</th>
                    <th>Processed at</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><span class="pill pill-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                        <td><?= htmlspecialchars(date('M j, Y', strtotime($row['scheduled_for']))) ?></td>
                        <td><?= htmlspecialchars($row['contact_name'] ?? '') ?> &lt;<?= htmlspecialchars($row['contact_email'] ?? '') ?>&gt;</td>
                        <td><?= !empty($row['processed_at']) ? htmlspecialchars(date('M j, Y g:ia', strtotime($row['processed_at']))) : '—' ?></td>
                        <td style="color:#64748b; font-size:12px;"><?= htmlspecialchars($row['notes'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
