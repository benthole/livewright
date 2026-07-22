<?php
/**
 * Bulk Team Assignment (Admin Only)
 *
 * Assign a batch of people to teams (Keap custom field 45) in one reviewed pass,
 * and ensure each one carries the roster sync tag (525) so they show up on the
 * roster. Designed for re-cohorting from a spreadsheet.
 *
 * Two phases, nothing is written until the admin confirms:
 *   1. Match  (?ajax=match): resolve each "name -> team" to a Keap contact,
 *              first against the local roster, then via a Keap name search.
 *              Returns a review list; writes nothing.
 *   2. Apply  (?ajax=apply): for each confirmed row, apply tag 525 (idempotent)
 *              and PATCH the team field, update the local roster cache, and log
 *              to change_log.
 *
 * Reuses the PATCH + change_log pattern from update_cohort.php and the tag POST
 * pattern from lib/drop_helpers.php. Keap access runs server-side (get_keap_token).
 */

require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/ui.php');
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../keap_api.php');

require_auth();
if (!is_admin()) {
    header('Location: ../');
    exit;
}
$current_user = get_logged_in_user();

// Roster sync tag: contacts must carry this to appear on the roster.
$roster_sync_tag_id = 525;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Normalize a name for matching: lowercase, drop parentheticals & punctuation. */
function at_norm($name) {
    $s = strtolower(trim((string)$name));
    $s = preg_replace('/\([^)]*\)/', ' ', $s);   // remove "(Alex)" etc.
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);  // drop punctuation
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/** Extract the team (field 45) content from a Keap contact's custom_fields. */
function at_team_of($contact, $fieldId) {
    if (empty($contact['custom_fields']) || !is_array($contact['custom_fields'])) return '';
    foreach ($contact['custom_fields'] as $f) {
        if (isset($f['id']) && (int)$f['id'] === (int)$fieldId) {
            return isset($f['content']) ? (string)$f['content'] : '';
        }
    }
    return '';
}

/** First email address from a Keap contact. */
function at_email_of($contact) {
    if (!empty($contact['email_addresses']) && is_array($contact['email_addresses'])) {
        return $contact['email_addresses'][0]['email'] ?? '';
    }
    return '';
}

function at_json_out($payload) {
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/** Resolve an email to a Keap contact id: local roster map first, then Keap. */
function at_resolve_email($email, $localByEmail) {
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

// ---------------------------------------------------------------------------
// AJAX: MATCH  (dry run — no writes)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'match') {
    $input = json_decode(file_get_contents('php://input'), true);
    $assignments = $input['assignments'] ?? [];

    // Load the local roster once and index it by normalized name + by email.
    $byName = [];   // normFull => [ {contact_id, email, current_team} ... ]
    $byEmail = [];  // email    => {contact_id, current_team}
    try {
        $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($conn->query("SELECT data FROM roster") as $row) {
            $d = json_decode($row['data'], true);
            if (!isset($d['id'])) continue;
            $full = at_norm(($d['given_name'] ?? '') . ' ' . ($d['family_name'] ?? ''));
            $email = at_email_of($d);
            $rec = [
                'contact_id'   => (int)$d['id'],
                'email'        => $email,
                'current_team' => at_team_of($d, $cohort_field_id),
            ];
            if ($full !== '') $byName[$full][] = $rec;
            if ($email !== '') $byEmail[strtolower($email)] = $rec;
        }
    } catch (PDOException $e) {
        at_json_out(['success' => false, 'error' => 'Roster DB error: ' . $e->getMessage()]);
    }

    $rows = [];
    foreach ($assignments as $a) {
        $name = trim($a['name'] ?? '');
        $team = trim($a['team'] ?? '');
        if ($name === '') continue;

        $norm = at_norm($name);
        $result = [
            'name' => $name, 'team' => $team,
            'status' => 'not_found', 'contact_id' => null,
            'email' => '', 'current_team' => '', 'on_roster' => false,
            'candidates' => [],
        ];

        // 1) Local roster: exact normalized full-name match.
        if (isset($byName[$norm])) {
            if (count($byName[$norm]) === 1) {
                $m = $byName[$norm][0];
                $result['status'] = 'local';
                $result['contact_id'] = $m['contact_id'];
                $result['email'] = $m['email'];
                $result['current_team'] = $m['current_team'];
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

        // 2) Keap name search (people not on the roster yet). given = first token,
        //    family = last token; fall back to given-only if the full search is empty.
        $tokens = preg_split('/\s+/', $norm);
        $given = $tokens[0] ?? '';
        $family = count($tokens) > 1 ? end($tokens) : '';

        $found = keap_search_contacts_by_name($given, $family, 25);
        if (isset($found['error'])) { $found = []; }
        if (empty($found) && $family !== '') {
            $found = keap_search_contacts_by_name($given, '', 25);
            if (isset($found['error'])) { $found = []; }
            // Narrow the given-only results by requiring the family token to appear.
            $found = array_values(array_filter($found, function ($c) use ($family) {
                return stripos(($c['family_name'] ?? ''), $family) !== false;
            }));
        }

        if (count($found) === 1) {
            $c = $found[0];
            $result['status'] = 'keap';
            $result['contact_id'] = (int)($c['id'] ?? 0);
            $result['email'] = at_email_of($c);
            $result['current_team'] = at_team_of($c, $cohort_field_id);
            $result['on_roster'] = false;
        } elseif (count($found) > 1) {
            $result['status'] = 'ambiguous';
            foreach (array_slice($found, 0, 8) as $c) {
                $result['candidates'][] = [
                    'contact_id' => (int)($c['id'] ?? 0),
                    'email'      => at_email_of($c),
                    'name'       => trim(($c['given_name'] ?? '') . ' ' . ($c['family_name'] ?? '')),
                ];
            }
        }

        $rows[] = $result;
    }

    at_json_out(['success' => true, 'rows' => $rows]);
}

// ---------------------------------------------------------------------------
// AJAX: APPLY  (writes to Keap + local roster + change_log)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'apply') {
    $input = json_decode(file_get_contents('php://input'), true);
    $rows = $input['rows'] ?? [];

    // Allowed team values: live Keap options for field 45 + config seed.
    $allowed = [];
    $liveOpts = keap_get_custom_field_options($cohort_field_id);
    if (is_array($liveOpts) && !isset($liveOpts['error'])) {
        foreach ($liveOpts as $o) $allowed[$o] = true;
    }
    foreach (($cohorts['active'] ?? []) as $o) $allowed[$o] = true;
    foreach (($cohorts['functional'] ?? []) as $o) $allowed[$o] = true;
    foreach (($cohorts['inactive'] ?? []) as $o) $allowed[$o] = true;

    $token = get_keap_token();
    if (!$token) at_json_out(['success' => false, 'error' => 'Could not get Keap token']);

    // Local roster maps: contact_id => info, and email => contact_id.
    $localById = [];
    $localByEmail = [];
    try {
        $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $conn->query("SELECT id, data FROM roster");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = json_decode($r['data'], true);
            if (!isset($d['id'])) continue;
            $email = at_email_of($d);
            $localById[(int)$d['id']] = [
                'row_id'       => $r['id'],
                'data'         => $d,
                'name'         => trim(($d['given_name'] ?? '') . ' ' . ($d['family_name'] ?? '')),
                'email'        => $email,
                'current_team' => at_team_of($d, $cohort_field_id),
            ];
            if ($email !== '') $localByEmail[strtolower($email)] = (int)$d['id'];
        }
    } catch (PDOException $e) {
        $conn = null; // Keap writes can still proceed; local cache update is skipped.
    }

    $results = ['success' => true, 'updated' => [], 'failed' => []];

    foreach ($rows as $row) {
        $contactId = (int)($row['contact_id'] ?? 0);
        $team = trim($row['team'] ?? '');
        $name = trim($row['name'] ?? '');

        // Red rows may be resolved by a pasted email instead of an id.
        if ($contactId <= 0 && !empty($row['override_email'])) {
            $contactId = at_resolve_email($row['override_email'], $localByEmail);
        }

        if ($contactId <= 0) {
            $results['failed'][] = ['name' => $name, 'error' => 'No contact id (email not found or ambiguous)'];
            continue;
        }
        if ($team === '' || !isset($allowed[$team])) {
            $results['failed'][] = ['name' => $name, 'contact_id' => $contactId, 'error' => "Team not a valid Keap option: '$team'"];
            continue;
        }

        // 1) Apply the roster sync tag (idempotent — no-op if already present).
        $tagCh = curl_init("https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}/tags");
        curl_setopt_array($tagCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode(['tagIds' => [(int)$roster_sync_tag_id]]),
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
            CURLOPT_TIMEOUT        => 20,
        ]);
        curl_exec($tagCh);
        $tagCode = curl_getinfo($tagCh, CURLINFO_HTTP_CODE);
        curl_close($tagCh);
        $tagged = ($tagCode === 200 || $tagCode === 201);

        // 2) PATCH the team custom field (field 45).
        $ch = curl_init("https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_POSTFIELDS     => json_encode(['custom_fields' => [['id' => $cohort_field_id, 'content' => $team]]]),
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $err = json_decode($resp, true);
            $results['failed'][] = [
                'name' => $name, 'contact_id' => $contactId,
                'error' => $err['message'] ?? 'PATCH failed', 'http_code' => $httpCode,
            ];
            continue;
        }

        // 3) Update local roster cache + change_log (best effort).
        $oldTeam = '';
        if ($conn && isset($localById[$contactId])) {
            $info = $localById[$contactId];
            $oldTeam = $info['current_team'];
            $data = $info['data'];

            $found = false;
            if (!empty($data['custom_fields']) && is_array($data['custom_fields'])) {
                foreach ($data['custom_fields'] as &$f) {
                    if (isset($f['id']) && (int)$f['id'] === (int)$cohort_field_id) {
                        $f['content'] = $team; $found = true; break;
                    }
                }
                unset($f);
            } else {
                $data['custom_fields'] = [];
            }
            if (!$found) $data['custom_fields'][] = ['id' => $cohort_field_id, 'content' => $team];

            try {
                $u = $conn->prepare("UPDATE roster SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $u->execute([json_encode($data), $info['row_id']]);
                $log = $conn->prepare("INSERT INTO change_log (keap_contact_id, contact_name, contact_email, field_changed, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)");
                $log->execute([$contactId, $info['name'], $info['email'], 'team', $oldTeam, $team]);
            } catch (PDOException $e) {
                // Keap already updated; local cache will reconcile on next Refresh.
            }
        }

        $results['updated'][] = [
            'name' => $name ?: ($localById[$contactId]['name'] ?? ''),
            'contact_id' => $contactId, 'team' => $team,
            'old_team' => $oldTeam, 'tagged' => $tagged, 'was_on_roster' => isset($localById[$contactId]),
        ];
    }

    $results['total_updated'] = count($results['updated']);
    $results['total_failed'] = count($results['failed']);
    at_json_out($results);
}

// ---------------------------------------------------------------------------
// Page render (GET) — pre-filled team lists from the assignment spreadsheet.
// ---------------------------------------------------------------------------
$seed = [
    'Purple Power People' => [
        'Alan Hippman', 'Elaine Virtue', 'Erin Moran', 'Fred Chisolm', 'Jesse Eduria',
        'Jim Packard', 'Lara Dickinson', 'Najmee Chowdhury', 'Paul Skiba', 'Sasha Zvodir',
        'Simeitsa Stamoulas', 'Vanessa Wei',
    ],
    'Brilliant Black Bears' => [
        'Ana Guingue', 'Caren Glotfelty', 'Cole Stevens', 'James Ross', 'Laine DeLeo',
        'Maksim Dvornikov', 'Priscilla Clark', 'Robin Spencer', 'Sayoko Hamilton',
        'Shannon Emmerson', 'Troy Heiner', 'Wendy White',
    ],
    'The Intentionals' => [
        'Alistair Moes', 'Andrea White', 'Damilola Aremu', 'Joyce Monton', 'Kelsey Schneider',
        'Marcela Valencia', 'Rob Craven', 'Tahsina Mehdi', 'Tom Glaser', 'Tracey Virtue', 'Yann Dang',
    ],
    'Fly Higher' => [
        'Ingolf Karst', 'Jennifer Cline', 'Katie Bigelow', 'Larissa Kozak', 'Liz Rubin',
        'Maria Isabel Valencia', 'Sandy Campbell', 'Sang Ae Leblon', 'Sayeed Chowdhury',
        'Will Weller', 'Jason Guille',
    ],
    'Concierge Group' => [
        'Matt Edelman', 'Dave Stamm', 'Yasmin Curtis',
    ],
];
$current_user = get_logged_in_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Team Assignment</title>
    <?php roster_ui_styles(); ?>
    <style>
        /* Bulk Team Assignment — page-specific (tokens/base/topbar in includes/ui.php) */
        .wrap { max-width: 1100px; margin: 0 auto; padding: 8px 24px 48px; }
        .tool-intro { margin: 22px 0 20px; }
        .tool-intro h2 { font-size: 17px; font-weight: 600; margin: 0 0 4px; color: var(--ink); }
        .sub { color: var(--ink-soft); font-size: 14px; max-width: 70ch; }
        .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; }
        .col label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: var(--ink); }
        textarea { width: 100%; min-height: 220px; padding: 10px; border: 1px solid var(--line-strong); border-radius: var(--r-sm); font-size: 13px; font-family: ui-monospace, Menlo, monospace; resize: vertical; background: var(--surface); color: var(--ink); }
        textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgb(from var(--focus) r g b / 0.20); }
        .btn { padding: 10px 18px; border: 1px solid transparent; border-radius: var(--r-sm); font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer; transition: background var(--dur) var(--ease); }
        .btn-primary { background: var(--accent); color: oklch(0.99 0.003 85); }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-apply { background: var(--ok); color: oklch(0.99 0.003 85); }
        .btn-apply:hover { background: oklch(0.46 0.09 150); }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        .bar { margin: 18px 0; display: flex; gap: 10px; align-items: center; }
        table { width: 100%; border-collapse: collapse; background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-md); overflow: hidden; margin-top: 12px; font-size: 13px; }
        th, td { text-align: left; padding: 9px 12px; border-bottom: 1px solid var(--line); }
        th { background: var(--surface-sunk); font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); font-weight: 600; }
        tr.st-local  { background: var(--ok-bg); }
        tr.st-keap   { background: var(--warn-bg); }
        tr.st-ambiguous, tr.st-not_found { background: var(--danger-bg); }
        .badge { display: inline-block; padding: 3px 9px; border-radius: var(--r-sm); font-size: 11px; font-weight: 700; }
        .b-local  { background: var(--tag-group-bg); color: var(--tag-group-ink); }
        .b-keap   { background: var(--warn-bg); color: var(--warn-ink); }
        .b-red    { background: var(--danger-bg); color: var(--danger-ink); }
        .override { width: 190px; padding: 6px 8px; border: 1px solid var(--danger); border-radius: var(--r-sm); font-size: 12px; background: var(--surface); color: var(--ink); }
        .notice { padding: 12px 14px; border-radius: var(--r-sm); margin: 12px 0; font-size: 14px; }
        .notice-info { background: var(--accent-weak); color: var(--accent-ink); }
        .notice-warn { background: var(--warn-bg); color: var(--warn-ink); }
        .notice-ok { background: var(--ok-bg); color: var(--ok); }
        .muted { color: var(--ink-faint); }
        code { background: var(--surface-sunk); padding: 1px 5px; border-radius: 4px; font-size: 0.92em; }
    </style>
</head>
<body class="rui">
<?php roster_ui_topbar([
    'base' => '../',
    'active' => 'assign_teams',
    'page_title' => 'Assign Teams',
    'user' => $current_user,
    'is_admin' => true,
]); ?>
<div class="wrap">
    <div class="tool-intro">
        <h2>Bulk Team Assignment</h2>
        <div class="sub">
            Assign people to teams (Keap field 45) and tag them for the roster sync (tag <?php echo (int)$roster_sync_tag_id; ?>)
            in one reviewed pass. One name per line under each team. Nothing is written until you review the matches and click Apply.
        </div>
    </div>

    <div class="notice notice-info">
        <strong>Step 1.</strong> Edit the lists if needed, then <strong>Match</strong> to resolve each name to a Keap contact.
        Names are matched against the roster first, then searched in Keap. You'll review every match before anything is saved.
    </div>

    <div class="cols">
        <?php foreach ($seed as $team => $names): ?>
            <div class="col">
                <label><?php echo htmlspecialchars($team); ?> (<?php echo count($names); ?>)</label>
                <textarea data-team="<?php echo htmlspecialchars($team); ?>"><?php echo htmlspecialchars(implode("\n", $names)); ?></textarea>
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
</div>

<script>
let MATCH_ROWS = [];

function gatherAssignments() {
    const out = [];
    document.querySelectorAll('textarea[data-team]').forEach(ta => {
        const team = ta.getAttribute('data-team');
        ta.value.split('\n').map(s => s.trim()).filter(Boolean).forEach(name => {
            out.push({ name, team });
        });
    });
    return out;
}

async function runMatch() {
    const assignments = gatherAssignments();
    const btn = document.getElementById('matchBtn');
    const status = document.getElementById('matchStatus');
    btn.disabled = true;
    status.textContent = 'Matching ' + assignments.length + ' people against the roster and Keap…';
    document.getElementById('resultArea').innerHTML = '';
    try {
        const res = await fetch('assign_teams.php?ajax=match', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assignments }),
        });
        const data = await res.json();
        if (!data.success) { status.textContent = 'Error: ' + (data.error || 'match failed'); btn.disabled = false; return; }
        MATCH_ROWS = data.rows;
        renderReview(data.rows);
        status.textContent = '';
    } catch (e) {
        status.textContent = 'Request failed: ' + e.message;
    }
    btn.disabled = false;
}

function esc(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

function renderReview(rows) {
    const counts = { local: 0, keap: 0, ambiguous: 0, not_found: 0 };
    rows.forEach(r => counts[r.status]++);

    let html = '<div class="notice notice-warn"><strong>Step 2. Review.</strong> '
        + '<span class="badge b-local">' + counts.local + ' on roster</span> '
        + '<span class="badge b-keap">' + counts.keap + ' found in Keap</span> '
        + '<span class="badge b-red">' + (counts.ambiguous + counts.not_found) + ' need attention</span> '
        + '— red rows are unchecked; paste a Keap contact ID or email to resolve, then check them.</div>';

    html += '<table><thead><tr><th>Apply</th><th>Name</th><th>Team</th><th>Match</th><th>Current team</th><th>Status</th><th>Resolve (ID or email)</th></tr></thead><tbody>';
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
            + '<td>' + esc(r.team) + '</td>'
            + '<td>' + matchCell + '</td>'
            + '<td>' + (r.current_team ? esc(r.current_team) : '<span class="muted">—</span>') + '</td>'
            + '<td><span class="badge ' + badge + '">' + label + '</span></td>'
            + '<td>' + (needs ? '<input class="override" data-i="' + i + '" placeholder="contact ID or email">' : '') + '</td>'
            + '</tr>';
    });
    html += '</tbody></table>';
    document.getElementById('reviewArea').innerHTML = html;
    document.getElementById('applyBar').style.display = 'flex';
}

async function runApply() {
    // Build the confirmed row list from checked checkboxes.
    const rows = [];
    const unresolved = [];
    document.querySelectorAll('#reviewArea input[type=checkbox]').forEach(cb => {
        if (!cb.checked) return;
        const i = +cb.getAttribute('data-i');
        const r = MATCH_ROWS[i];
        const ov = document.querySelector('#reviewArea input.override[data-i="' + i + '"]');
        let contactId = r.contact_id;
        let overrideEmail = null;
        if (ov && ov.value.trim()) {
            const v = ov.value.trim();
            if (/^\d+$/.test(v)) { contactId = parseInt(v, 10); }
            else { overrideEmail = v; }
        }
        if (!contactId && !overrideEmail) { unresolved.push(r.name); return; }
        rows.push({ name: r.name, team: r.team, contact_id: contactId || 0, override_email: overrideEmail });
    });

    if (unresolved.length) {
        alert('These checked rows still have no contact ID or email:\n\n' + unresolved.join('\n'));
        return;
    }
    if (!rows.length) { alert('No rows checked.'); return; }
    if (!confirm('Apply team + roster tag to ' + rows.length + ' contact(s) in Keap? This writes to Keap and cannot be auto-undone.')) return;

    const btn = document.getElementById('applyBtn');
    const status = document.getElementById('applyStatus');
    btn.disabled = true;
    status.textContent = 'Applying ' + rows.length + ' contact(s)…';
    try {
        const res = await fetch('assign_teams.php?ajax=apply', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rows }),
        });
        const data = await res.json();
        renderResult(data);
        status.textContent = '';
    } catch (e) {
        status.textContent = 'Request failed: ' + e.message;
    }
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
        html += '<table><thead><tr><th>Name</th><th>Team set</th><th>Was</th><th>Tagged</th><th>New to roster</th></tr></thead><tbody>';
        data.updated.forEach(u => {
            html += '<tr><td>' + esc(u.name) + '</td><td>' + esc(u.team) + '</td><td>' + (u.old_team ? esc(u.old_team) : '<span class="muted">—</span>')
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
<?php roster_ui_menu_js(); ?>
</body>
</html>
