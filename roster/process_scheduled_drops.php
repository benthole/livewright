<?php
/**
 * Cron entry point — process scheduled drops that are now due.
 *
 * Run from cron, e.g.:
 *   0 7 * * * /usr/bin/php /home/runcloud/webapps/app-checkout-livewright/roster/process_scheduled_drops.php
 *
 * Web access is allowed for ad-hoc admin runs but only via a shared secret
 * passed as ?key=... (set $scheduled_drops_cron_key in roster/config.php).
 */

require_once(__DIR__ . '/keap_api.php');
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/lib/drop_helpers.php');

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    // HTTP access: require shared secret to avoid being publicly callable.
    $key = $_GET['key'] ?? '';
    $expected = isset($scheduled_drops_cron_key) ? $scheduled_drops_cron_key : '';
    if ($expected === '' || !hash_equals((string)$expected, (string)$key)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
    header('Content-Type: text/plain');
}

try {
    $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$token = get_keap_token();
if (!$token) {
    fwrite(STDERR, "Could not get Keap API token.\n");
    exit(1);
}

$today = date('Y-m-d');
$stmt = $conn->prepare("SELECT * FROM scheduled_drops WHERE status = 'pending' AND scheduled_for <= ? ORDER BY scheduled_for, id");
$stmt->execute([$today]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Scheduled-drops processor — " . date('Y-m-d H:i:s') . "\n";
echo "Found " . count($rows) . " due drop(s).\n";

if (empty($rows)) {
    exit(0);
}

$rosterByContactId = drop_load_roster_cache($conn);

$cfg = [
    'cohort_field_id' => $cohort_field_id,
    'individual_coach_field_id' => $individual_coach_field_id,
    'individual_coach_field_id_old' => $individual_coach_field_id_old ?? null,
    'group_coach_field_id' => $group_coach_field_id,
    'coaching_files_field_id' => $coaching_files_field_id,
    'drop_tag_id' => $drop_tag_id,
];

$upd = $conn->prepare("UPDATE scheduled_drops SET status = ?, processed_at = NOW(), notes = ? WHERE id = ?");
$processed = 0;
$failed = 0;

foreach ($rows as $row) {
    $r = drop_execute_one($conn, (int)$row['keap_contact_id'], $token, $cfg, $rosterByContactId, 'cron');
    if ($r['success']) {
        $upd->execute(['processed', json_encode(['warnings' => $r['warnings']]), $row['id']]);
        $processed++;
        echo "  [OK]   #{$row['id']} {$row['contact_name']} ({$row['keap_contact_id']})\n";
    } else {
        $upd->execute(['failed', $r['error'] ?? 'unknown error', $row['id']]);
        $failed++;
        echo "  [FAIL] #{$row['id']} {$row['contact_name']} ({$row['keap_contact_id']}) — {$r['error']}\n";
    }
}

echo "Done. processed={$processed} failed={$failed}\n";
exit($failed > 0 ? 2 : 0);
