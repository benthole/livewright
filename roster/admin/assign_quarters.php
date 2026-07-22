<?php
/**
 * Bulk Quarter Assignment (Admin Only)
 *
 * Sibling of assign_teams.php: assigns a batch of people to a Quarter (Keap
 * custom field $quarter_field_id) and ensures each carries the roster sync tag
 * (525). Same two-phase flow (dry-run Match -> reviewed Apply).
 *
 * Dormant until $quarter_field_id is set in config.php (server-only): the page
 * shows a "create the field first" notice and the endpoints refuse to run.
 */

require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../keap_api.php');

require_auth();
if (!is_admin()) {
    header('Location: ../');
    exit;
}

$roster_sync_tag_id = 525;
$quarter_field_id = isset($quarter_field_id) ? (int)$quarter_field_id : 0;
$quarter_values = ['Q1', 'Q2', 'Q3', 'Q4'];

// --- Helpers -----------------------------------------------------------------
function aq_norm($name) {
    $s = strtolower(trim((string)$name));
    $s = preg_replace('/\([^)]*\)/', ' ', $s);
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}
function aq_field_of($contact, $fieldId) {
    if (empty($contact['custom_fields']) || !is_array($contact['custom_fields'])) return '';
    foreach ($contact['custom_fields'] as $f) {
        if (isset($f['id']) && (int)$f['id'] === (int)$fieldId) {
            return isset($f['content']) ? (string)$f['content'] : '';
        }
    }
    return '';
}
function aq_email_of($contact) {
    if (!empty($contact['email_addresses']) && is_array($contact['email_addresses'])) {
        return $contact['email_addresses'][0]['email'] ?? '';
    }
    return '';
}
function aq_json_out($payload) {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}
function aq_resolve_email($email, $localByEmail) {
    $email = strtolower(trim($email));
    if ($email === '') return 0;
    if (isset($localByEmail[$email])) return (int)$localByEmail[$email];
    $url = "https://api.infusionsoft.com/crm/rest/v1/contacts?" . http_build_query(['email' => $email, 'limit' => 5]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer " . get_keap_token(), "Content-Type: application/json"],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return 0;
    $data = json_decode($resp, true);
    $contacts = $data['contacts'] ?? [];
    return count($contacts) === 1 ? (int)($contacts[0]['id'] ?? 0) : 0;
}

// --- AJAX: MATCH (dry run) ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'match') {
    if ($quarter_field_id <= 0) aq_json_out(['success' => false, 'error' => 'Quarter field is not configured yet']);
    $input = json_decode(file_get_contents('php://input'), true);
    $assignments = $input['assignments'] ?? [];

    $byName = [];
    try {
        $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($conn->query("SELECT data FROM roster") as $row) {
            $d = json_decode($row['data'], true);
            if (!isset($d['id'])) continue;
            $full = aq_norm(($d['given_name'] ?? '') . ' ' . ($d['family_name'] ?? ''));
            if ($full !== '') {
                $byName[$full][] = [
                    'contact_id' => (int)$d['id'],
                    'email' => aq_email_of($d),
                    'current_quarter' => aq_field_of($d, $quarter_field_id),
                ];
            }
        }
    } catch (PDOException $e) {
        aq_json_out(['success' => false, 'error' => 'Roster DB error: ' . $e->getMessage()]);
    }

    $rows = [];
    foreach ($assignments as $a) {
        $name = trim($a['name'] ?? '');
        $quarter = trim($a['quarter'] ?? '');
        if ($name === '') continue;
        $norm = aq_norm($name);
        $result = [
            'name' => $name, 'quarter' => $quarter, 'status' => 'not_found',
            'contact_id' => null, 'email' => '', 'current_quarter' => '',
            'on_roster' => false, 'candidates' => [],
        ];

        if (isset($byName[$norm])) {
            if (count($byName[$norm]) === 1) {
                $m = $byName[$norm][0];
                $result['status'] = 'local';
                $result['contact_id'] = $m['contact_id'];
                $result['email'] = $m['email'];
                $result['current_quarter'] = $m['current_quarter'];
                $result['on_roster'] = true;
            } else {
                $result['status'] = 'ambiguous';
                foreach ($byName[$norm] as $m) {
                    $result['candidates'][] = ['contact_id' => $m['contact_id'], 'email' => $m['email']];
                }
            }
            $rows[] = $result;
            continue;
        }

        $tokens = preg_split('/\s+/', $norm);
        $given = $tokens[0] ?? '';
        $family = count($tokens) > 1 ? end($tokens) : '';
        $found = keap_search_contacts_by_name($given, $family, 25);
        if (isset($found['error'])) $found = [];
        if (empty($found) && $family !== '') {
            $found = keap_search_contacts_by_name($given, '', 25);
            if (isset($found['error'])) $found = [];
            $found = array_values(array_filter($found, function ($c) use ($family) {
                return stripos(($c['family_name'] ?? ''), $family) !== false;
            }));
        }

        if (count($found) === 1) {
            $c = $found[0];
            $result['status'] = 'keap';
            $result['contact_id'] = (int)($c['id'] ?? 0);
            $result['email'] = aq_email_of($c);
            $result['current_quarter'] = aq_field_of($c, $quarter_field_id);
        } elseif (count($found) > 1) {
            $result['status'] = 'ambiguous';
            foreach (array_slice($found, 0, 8) as $c) {
                $result['candidates'][] = [
                    'contact_id' => (int)($c['id'] ?? 0),
                    'email' => aq_email_of($c),
                    'name' => trim(($c['given_name'] ?? '') . ' ' . ($c['family_name'] ?? '')),
                ];
            }
        }
        $rows[] = $result;
    }
    aq_json_out(['success' => true, 'rows' => $rows]);
}

// --- AJAX: APPLY --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'apply') {
    if ($quarter_field_id <= 0) aq_json_out(['success' => false, 'error' => 'Quarter field is not configured yet']);
    $input = json_decode(file_get_contents('php://input'), true);
    $rows = $input['rows'] ?? [];

    // Allowed quarter values: fixed list + any live Keap options.
    $allowed = [];
    foreach ($quarter_values as $q) $allowed[$q] = true;
    $liveOpts = keap_get_custom_field_options($quarter_field_id);
    if (is_array($liveOpts) && !isset($liveOpts['error'])) {
        foreach ($liveOpts as $o) $allowed[$o] = true;
    }

    $token = get_keap_token();
    if (!$token) aq_json_out(['success' => false, 'error' => 'Could not get Keap token']);

    $localById = [];
    $localByEmail = [];
    try {
        $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($conn->query("SELECT id, data FROM roster")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = json_decode($r['data'], true);
            if (!isset($d['id'])) continue;
            $email = aq_email_of($d);
            $localById[(int)$d['id']] = [
                'row_id' => $r['id'], 'data' => $d,
                'name' => trim(($d['given_name'] ?? '') . ' ' . ($d['family_name'] ?? '')),
                'email' => $email, 'current_quarter' => aq_field_of($d, $quarter_field_id),
            ];
            if ($email !== '') $localByEmail[strtolower($email)] = (int)$d['id'];
        }
    } catch (PDOException $e) {
        $conn = null;
    }

    $results = ['success' => true, 'updated' => [], 'failed' => []];
    foreach ($rows as $row) {
        $contactId = (int)($row['contact_id'] ?? 0);
        $quarter = trim($row['quarter'] ?? '');
        $name = trim($row['name'] ?? '');

        if ($contactId <= 0 && !empty($row['override_email'])) {
            $contactId = aq_resolve_email($row['override_email'], $localByEmail);
        }
        if ($contactId <= 0) {
            $results['failed'][] = ['name' => $name, 'error' => 'No contact id (email not found or ambiguous)'];
            continue;
        }
        if ($quarter === '' || !isset($allowed[$quarter])) {
            $results['failed'][] = ['name' => $name, 'contact_id' => $contactId, 'error' => "Quarter not a valid Keap option: '$quarter'"];
            continue;
        }

        // 1) Roster sync tag (idempotent).
        $tagCh = curl_init("https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}/tags");
        curl_setopt_array($tagCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode(['tagIds' => [(int)$roster_sync_tag_id]]),
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", "Content-Type: application/json"],
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($tagCh);
        $tagCode = curl_getinfo($tagCh, CURLINFO_HTTP_CODE);
        curl_close($tagCh);
        $tagged = ($tagCode === 200 || $tagCode === 201);

        // 2) PATCH the quarter field.
        $ch = curl_init("https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode(['custom_fields' => [['id' => $quarter_field_id, 'content' => $quarter]]]),
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", "Content-Type: application/json"],
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $err = json_decode($resp, true);
            $results['failed'][] = ['name' => $name, 'contact_id' => $contactId, 'error' => $err['message'] ?? 'PATCH failed', 'http_code' => $httpCode];
            continue;
        }

        // 3) Local cache + change_log (best effort).
        $oldQuarter = '';
        if ($conn && isset($localById[$contactId])) {
            $info = $localById[$contactId];
            $oldQuarter = $info['current_quarter'];
            $data = $info['data'];
            $found = false;
            if (!empty($data['custom_fields']) && is_array($data['custom_fields'])) {
                foreach ($data['custom_fields'] as &$f) {
                    if (isset($f['id']) && (int)$f['id'] === (int)$quarter_field_id) { $f['content'] = $quarter; $found = true; break; }
                }
                unset($f);
            } else {
                $data['custom_fields'] = [];
            }
            if (!$found) $data['custom_fields'][] = ['id' => $quarter_field_id, 'content' => $quarter];
            try {
                $u = $conn->prepare("UPDATE roster SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $u->execute([json_encode($data), $info['row_id']]);
                $log = $conn->prepare("INSERT INTO change_log (keap_contact_id, contact_name, contact_email, field_changed, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)");
                $log->execute([$contactId, $info['name'], $info['email'], 'quarter', $oldQuarter, $quarter]);
            } catch (PDOException $e) { /* reconciles on next Refresh */ }
        }

        $results['updated'][] = [
            'name' => $name ?: ($localById[$contactId]['name'] ?? ''),
            'contact_id' => $contactId, 'quarter' => $quarter,
            'old_quarter' => $oldQuarter, 'tagged' => $tagged, 'was_on_roster' => isset($localById[$contactId]),
        ];
    }
    $results['total_updated'] = count($results['updated']);
    $results['total_failed'] = count($results['failed']);
    aq_json_out($results);
}

// --- Page render (GET) --------------------------------------------------------
// Pre-filled from the first assignment sheet's colors (Q1=green, Q2=yellow,
// Q3=light-orange, Q4=pink). Verify against your sheet before applying.
$seed = [
    'Q1' => [
        'Rob Craven', 'Katie Bigelow', 'Najmee Chowdhury', 'Tahsina Mehdi', 'Tracey Virtue',
        'Damilola Aremu', 'Ana Guingue', 'Elaine Virtue', 'Joyce Monton', 'Jason Guille',
        'Sayeed Chowdhury', 'Priscilla Clark', 'Maria Isabel Valencia', 'Lara Dickinson',
    ],
    'Q2' => [
        'Paul Skiba', 'Larissa Kozak', 'Laine DeLeo', 'Shannon Emmerson', 'Andrea White',
        'Cole Stevens', 'Jesse Eduria',
    ],
    'Q3' => [
        'Caren Glotfelty', 'Ingolf Karst', 'Simeitsa Stamoulas', 'Sang Ae Leblon', 'Troy Heiner',
        'Sayoko Hamilton', 'Kelsey Schneider', 'Sandy Campbell', 'Fred Chisolm', 'Jim Packard',
        'Erin Moran', 'Liz Rubin', 'James Ross', 'Marcela Valencia', 'Maksim Dvornikov',
    ],
    'Q4' => [
        'Alistair Moes', 'Sasha Zvodir', 'Tom Glaser', 'Wendy White', 'Alan Hippman',
    ],
];
$current_user = get_logged_in_user();
$configured = ($quarter_field_id > 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Quarter Assignment</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; background: #f4f6f8; color: #2d3748; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 24px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .sub { color: #718096; font-size: 14px; margin-bottom: 20px; }
        a.back { color: #17a2b8; text-decoration: none; font-size: 14px; }
        .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
        .col label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; }
        textarea { width: 100%; min-height: 240px; padding: 8px; border: 1px solid #cbd5e0; border-radius: 6px; font-size: 13px; font-family: ui-monospace, Menlo, monospace; resize: vertical; }
        .btn { padding: 10px 18px; border: 0; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: #17a2b8; color: #fff; }
        .btn-apply { background: #2f855a; color: #fff; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        .bar { margin: 18px 0; display: flex; gap: 10px; align-items: center; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; margin-top: 12px; font-size: 13px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #edf2f7; }
        th { background: #f7fafc; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #718096; }
        tr.st-local { background: #f0fff4; }
        tr.st-keap { background: #fffff0; }
        tr.st-ambiguous, tr.st-not_found { background: #fff5f5; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
        .b-local { background: #c6f6d5; color: #22543d; }
        .b-keap { background: #fefcbf; color: #744210; }
        .b-red { background: #fed7d7; color: #822727; }
        .override { width: 190px; padding: 5px; border: 1px solid #fc8181; border-radius: 4px; font-size: 12px; }
        .notice { padding: 12px 14px; border-radius: 6px; margin: 12px 0; font-size: 14px; }
        .notice-info { background: #ebf8ff; color: #2c5282; }
        .notice-warn { background: #fffaf0; color: #744210; }
        .notice-ok { background: #f0fff4; color: #22543d; }
        .notice-block { background: #fed7d7; color: #822727; }
        .muted { color: #a0aec0; }
    </style>
</head>
<body>
<div class="wrap">
    <a class="back" href="../">&larr; Back to roster</a>
    <h1>Bulk Quarter Assignment</h1>
    <div class="sub">
        Set each person's Quarter (Keap field <?php echo $configured ? (int)$quarter_field_id : '—'; ?>)
        and tag them for the roster sync (tag <?php echo (int)$roster_sync_tag_id; ?>) in one reviewed pass.
        One name per line under each quarter. Nothing is written until you review the matches and click Apply.
    </div>

    <?php if (!$configured): ?>
        <div class="notice notice-block">
            <strong>Not set up yet.</strong> Create a <strong>Quarter</strong> custom field in Keap
            (Settings &rarr; Custom Fields, type Dropdown, options Q1&nbsp;Q2&nbsp;Q3&nbsp;Q4), then set
            <code>$quarter_field_id</code> to that field's id in the server <code>config.php</code>.
            This tool stays disabled until then.
        </div>
    <?php else: ?>
        <div class="notice notice-info">
            <strong>Step 1.</strong> Edit the lists if needed, then <strong>Match</strong> to resolve each name to a Keap contact.
            The pre-filled lists come from your first assignment sheet's colors &mdash; please verify them.
        </div>

        <div class="cols">
            <?php foreach ($seed as $q => $names): ?>
                <div class="col">
                    <label><?php echo htmlspecialchars($q); ?> (<?php echo count($names); ?>)</label>
                    <textarea data-quarter="<?php echo htmlspecialchars($q); ?>"><?php echo htmlspecialchars(implode("\n", $names)); ?></textarea>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="bar">
            <button class="btn btn-primary" id="matchBtn" onclick="runMatch()">Match names</button>
            <span id="matchStatus" class="muted"></span>
        </div>

        <div id="reviewArea"></div>

        <div class="bar" id="applyBar" style="display:none;">
            <button class="btn btn-apply" id="applyBtn" onclick="runApply()">Apply checked rows</button>
            <span id="applyStatus" class="muted"></span>
        </div>

        <div id="resultArea"></div>
    <?php endif; ?>
</div>

<?php if ($configured): ?>
<script>
let MATCH_ROWS = [];

function gatherAssignments() {
    const out = [];
    document.querySelectorAll('textarea[data-quarter]').forEach(ta => {
        const quarter = ta.getAttribute('data-quarter');
        ta.value.split('\n').map(s => s.trim()).filter(Boolean).forEach(name => out.push({ name, quarter }));
    });
    return out;
}
function esc(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

async function runMatch() {
    const assignments = gatherAssignments();
    const btn = document.getElementById('matchBtn'), status = document.getElementById('matchStatus');
    btn.disabled = true;
    status.textContent = 'Matching ' + assignments.length + ' people…';
    document.getElementById('resultArea').innerHTML = '';
    try {
        const res = await fetch('assign_quarters.php?ajax=match', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assignments }),
        });
        const data = await res.json();
        if (!data.success) { status.textContent = 'Error: ' + (data.error || 'match failed'); btn.disabled = false; return; }
        MATCH_ROWS = data.rows;
        renderReview(data.rows);
        status.textContent = '';
    } catch (e) { status.textContent = 'Request failed: ' + e.message; }
    btn.disabled = false;
}

function renderReview(rows) {
    const counts = { local: 0, keap: 0, ambiguous: 0, not_found: 0 };
    rows.forEach(r => counts[r.status]++);
    let html = '<div class="notice notice-warn"><strong>Step 2. Review.</strong> '
        + '<span class="badge b-local">' + counts.local + ' on roster</span> '
        + '<span class="badge b-keap">' + counts.keap + ' found in Keap</span> '
        + '<span class="badge b-red">' + (counts.ambiguous + counts.not_found) + ' need attention</span> '
        + '— red rows are unchecked; paste a Keap contact ID or email to resolve, then check them.</div>';
    html += '<table><thead><tr><th>Apply</th><th>Name</th><th>Quarter</th><th>Match</th><th>Current</th><th>Status</th><th>Resolve (ID or email)</th></tr></thead><tbody>';
    rows.forEach((r, i) => {
        const needs = (r.status === 'ambiguous' || r.status === 'not_found');
        const badge = { local: 'b-local', keap: 'b-keap', ambiguous: 'b-red', not_found: 'b-red' }[r.status];
        const label = { local: 'on roster', keap: 'in Keap (will tag)', ambiguous: 'ambiguous', not_found: 'not found' }[r.status];
        let matchCell = r.email ? esc(r.email) : '<span class="muted">—</span>';
        if (r.status === 'ambiguous' && r.candidates.length) {
            matchCell = '<span class="muted">' + r.candidates.map(c => esc((c.name ? c.name + ' ' : '') + '#' + c.contact_id + (c.email ? ' ' + c.email : ''))).join('<br>') + '</span>';
        }
        html += '<tr class="st-' + r.status + '">'
            + '<td><input type="checkbox" data-i="' + i + '" ' + (needs ? '' : 'checked') + '></td>'
            + '<td>' + esc(r.name) + (r.on_roster ? '' : ' <span class="muted">(new)</span>') + '</td>'
            + '<td>' + esc(r.quarter) + '</td>'
            + '<td>' + matchCell + '</td>'
            + '<td>' + (r.current_quarter ? esc(r.current_quarter) : '<span class="muted">—</span>') + '</td>'
            + '<td><span class="badge ' + badge + '">' + label + '</span></td>'
            + '<td>' + (needs ? '<input class="override" data-i="' + i + '" placeholder="contact ID or email">' : '') + '</td>'
            + '</tr>';
    });
    html += '</tbody></table>';
    document.getElementById('reviewArea').innerHTML = html;
    document.getElementById('applyBar').style.display = 'flex';
}

async function runApply() {
    const rows = [], unresolved = [];
    document.querySelectorAll('#reviewArea input[type=checkbox]').forEach(cb => {
        if (!cb.checked) return;
        const i = +cb.getAttribute('data-i'), r = MATCH_ROWS[i];
        const ov = document.querySelector('#reviewArea input.override[data-i="' + i + '"]');
        let contactId = r.contact_id, overrideEmail = null;
        if (ov && ov.value.trim()) {
            const v = ov.value.trim();
            if (/^\d+$/.test(v)) contactId = parseInt(v, 10); else overrideEmail = v;
        }
        if (!contactId && !overrideEmail) { unresolved.push(r.name); return; }
        rows.push({ name: r.name, quarter: r.quarter, contact_id: contactId || 0, override_email: overrideEmail });
    });
    if (unresolved.length) { alert('These checked rows still have no contact ID or email:\n\n' + unresolved.join('\n')); return; }
    if (!rows.length) { alert('No rows checked.'); return; }
    if (!confirm('Apply quarter + roster tag to ' + rows.length + ' contact(s) in Keap?')) return;

    const btn = document.getElementById('applyBtn'), status = document.getElementById('applyStatus');
    btn.disabled = true;
    status.textContent = 'Applying ' + rows.length + ' contact(s)…';
    try {
        const res = await fetch('assign_quarters.php?ajax=apply', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rows }),
        });
        renderResult(await res.json());
        status.textContent = '';
    } catch (e) { status.textContent = 'Request failed: ' + e.message; }
    btn.disabled = false;
}

function renderResult(data) {
    if (!data.success && data.error) {
        document.getElementById('resultArea').innerHTML = '<div class="notice notice-warn">Error: ' + esc(data.error) + '</div>';
        return;
    }
    let html = '<div class="notice notice-ok"><strong>' + (data.total_updated || 0) + ' updated</strong>'
        + (data.total_failed ? ', <strong>' + data.total_failed + ' failed</strong>' : '')
        + '. Run the roster <strong>Refresh</strong> to pull newly-tagged people in fully.</div>';
    if ((data.updated || []).length) {
        html += '<table><thead><tr><th>Name</th><th>Quarter</th><th>Was</th><th>Tagged</th><th>New</th></tr></thead><tbody>';
        data.updated.forEach(u => {
            html += '<tr><td>' + esc(u.name) + '</td><td>' + esc(u.quarter) + '</td><td>' + (u.old_quarter ? esc(u.old_quarter) : '<span class="muted">—</span>')
                + '</td><td>' + (u.tagged ? '✓' : '<span class="muted">already</span>') + '</td><td>' + (u.was_on_roster ? '' : '✓ new') + '</td></tr>';
        });
        html += '</tbody></table>';
    }
    if ((data.failed || []).length) {
        html += '<div class="notice notice-warn"><strong>Failed:</strong><br>' + data.failed.map(f => esc(f.name + ' — ' + f.error)).join('<br>') + '</div>';
    }
    document.getElementById('resultArea').innerHTML = html;
    document.getElementById('applyBar').style.display = 'none';
}
</script>
<?php endif; ?>
</body>
</html>
