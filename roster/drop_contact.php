<?php
// drop_contact.php - Drop workflow for roster contacts.
//
// For each contact:
//   1. Capture a snapshot of current team, coaches, payment info, and coaching files.
//   2. Set team to '-dropped' and clear individual (137) and group (49) coach fields in Keap.
//   3. Apply the configured drop tag.
//   4. Create a Keap Note archiving the previous settings.
//   5. Update the local roster cache + change_log.
//
// After all contacts are processed, send a single notification email via the Keap
// email API to the configured admin addresses (Sebastian, Maja, Elizabeth),
// summarising who was dropped and their archived settings.

require_once('includes/auth.php');
require_once('keap_api.php');
require_once('config.php');
require_once(__DIR__ . '/lib/drop_helpers.php');

header('Content-Type: application/json');

require_editor();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$contactIds = isset($input['contact_ids']) ? $input['contact_ids'] : [];
$contactIds = array_values(array_filter(array_map('intval', $contactIds), function ($id) { return $id > 0; }));

if (empty($contactIds)) {
    echo json_encode(['success' => false, 'error' => 'No contacts selected']);
    exit;
}

// Optional: schedule the drop for a future date instead of executing now.
// When set, we record rows in scheduled_drops and short-circuit; the daily
// processor (process_scheduled_drops.php) will run them when the date arrives.
$scheduledFor = isset($input['scheduled_for']) ? trim($input['scheduled_for']) : '';
if ($scheduledFor !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledFor)) {
        echo json_encode(['success' => false, 'error' => 'Invalid scheduled_for date (expected YYYY-MM-DD)']);
        exit;
    }
    if (strtotime($scheduledFor) < strtotime(date('Y-m-d'))) {
        echo json_encode(['success' => false, 'error' => 'scheduled_for cannot be in the past']);
        exit;
    }
}

$token = get_keap_token();
if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Could not get Keap API token']);
    exit;
}

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------

function drop_keap_request($method, $url, $token, $payload = null) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json",
        ],
        CURLOPT_TIMEOUT => 20,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'http_code' => $httpCode,
        'body' => $resp,
        'data' => json_decode($resp, true),
    ];
}

function drop_get_field($customFields, $fieldId) {
    if (is_array($customFields)) {
        foreach ($customFields as $field) {
            if (isset($field['id']) && (int)$field['id'] === (int)$fieldId) {
                return isset($field['content']) ? $field['content'] : '';
            }
        }
    }
    return '';
}

function drop_build_note_body($snapshot, $actor) {
    $lines = [];
    $lines[] = 'Dropped on ' . date('Y-m-d H:i T') . ' by ' . ($actor ?: 'unknown');
    $lines[] = '';
    $lines[] = 'Previous Team: ' . ($snapshot['team'] !== '' ? $snapshot['team'] : '(none)');
    $lines[] = 'Previous Individual Coach(es): ' . ($snapshot['individual_coach'] !== '' ? $snapshot['individual_coach'] : '(none)');
    $lines[] = 'Previous Group Coach: ' . ($snapshot['group_coach'] !== '' ? $snapshot['group_coach'] : '(none)');
    $lines[] = 'Payment Option: ' . ($snapshot['payment_option'] !== '' ? $snapshot['payment_option'] : '(none)');
    $lines[] = 'Payment Frequency: ' . ($snapshot['payment_frequency'] !== '' ? $snapshot['payment_frequency'] : '(none)');
    $lines[] = 'Coaching Files: ' . ($snapshot['coaching_files'] !== '' ? $snapshot['coaching_files'] : '(none)');
    return implode("\n", $lines);
}

// Look up Keap contact IDs for a list of email addresses.
// Returns a map email => contact_id (only for emails that resolved).
function drop_lookup_contact_ids_by_email($emails, $token) {
    $out = [];
    foreach ($emails as $email) {
        $email = trim($email);
        if ($email === '') continue;
        $url = 'https://api.infusionsoft.com/crm/rest/v1/contacts?email=' . urlencode($email) . '&limit=1';
        $r = drop_keap_request('GET', $url, $token);
        if ($r['http_code'] === 200 && !empty($r['data']['contacts'][0]['id'])) {
            $out[$email] = (int)$r['data']['contacts'][0]['id'];
        }
    }
    return $out;
}

// ------------------------------------------------------------
// Load local roster cache to build snapshots
// ------------------------------------------------------------

try {
    $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$rosterByContactId = drop_load_roster_cache($conn);

$currentUser = get_logged_in_user();
$actor = $currentUser ? ($currentUser['email'] ?? $currentUser['name'] ?? 'unknown') : 'unknown';

// ------------------------------------------------------------
// Scheduled (deferred) path: record rows and return without executing
// ------------------------------------------------------------
if ($scheduledFor !== '') {
    $scheduled = [];
    $skipped = [];
    $stmt = $conn->prepare(
        "INSERT INTO scheduled_drops (keap_contact_id, contact_name, contact_email, scheduled_for, snapshot, scheduled_by)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($contactIds as $cid) {
        if (!isset($rosterByContactId[$cid])) {
            $skipped[] = ['id' => $cid, 'error' => 'Not in local roster'];
            continue;
        }
        $data = $rosterByContactId[$cid]['data'];
        $name = trim(($data['given_name'] ?? '') . ' ' . ($data['family_name'] ?? ''));
        $email = '';
        if (!empty($data['email_addresses']) && is_array($data['email_addresses'])) {
            $email = $data['email_addresses'][0]['email'] ?? '';
        }
        // Capture a lightweight snapshot of the fields we'll later archive.
        $snap = [
            'team' => drop_get_field_lib($data['custom_fields'] ?? [], $cohort_field_id),
            'individual_coach' => drop_get_field_lib($data['custom_fields'] ?? [], $individual_coach_field_id),
            'group_coach' => drop_get_field_lib($data['custom_fields'] ?? [], $group_coach_field_id),
        ];
        $stmt->execute([$cid, $name, $email, $scheduledFor, json_encode($snap), $actor]);
        $scheduled[] = ['id' => $cid, 'name' => $name, 'email' => $email];
    }
    echo json_encode([
        'success' => true,
        'mode' => 'scheduled',
        'scheduled_for' => $scheduledFor,
        'scheduled' => $scheduled,
        'skipped' => $skipped,
        'total_scheduled' => count($scheduled),
    ], JSON_PRETTY_PRINT);
    exit;
}

$results = [
    'success' => true,
    'dropped' => [],
    'failed' => [],
    'snapshots' => [],
];

// ------------------------------------------------------------
// Process each contact
// ------------------------------------------------------------

$dropCfg = [
    'cohort_field_id' => $cohort_field_id,
    'individual_coach_field_id' => $individual_coach_field_id,
    'individual_coach_field_id_old' => $individual_coach_field_id_old ?? null,
    'group_coach_field_id' => $group_coach_field_id,
    'coaching_files_field_id' => $coaching_files_field_id,
    'drop_tag_id' => $drop_tag_id,
];

foreach ($contactIds as $contactId) {
    $r = drop_execute_one($conn, $contactId, $token, $dropCfg, $rosterByContactId, $actor);

    if (!$r['success']) {
        $results['failed'][] = ['id' => $contactId, 'error' => $r['error']];
        continue;
    }

    $results['dropped'][] = [
        'id' => $contactId,
        'name' => $r['snapshot']['name'] ?? '',
        'email' => $r['snapshot']['email'] ?? '',
        'warnings' => $r['warnings'],
    ];
    $results['snapshots'][] = $r['snapshot'];
}

// ------------------------------------------------------------
// Send notification email via Keap to admin addresses
// ------------------------------------------------------------

$results['notification'] = ['sent' => false];

if (!empty($results['snapshots']) && !empty($drop_notification_emails)) {
    $adminMap = drop_lookup_contact_ids_by_email($drop_notification_emails, $token);
    $adminContactIds = array_values($adminMap);
    $missing = array_values(array_diff($drop_notification_emails, array_keys($adminMap)));

    if (!empty($adminContactIds)) {
        // Build email content
        $rowsHtml = '';
        $rowsText = '';
        foreach ($results['snapshots'] as $s) {
            $nameLine = trim($s['name']) . ($s['email'] ? ' <' . $s['email'] . '>' : '');
            $rowsHtml .= '<tr><td colspan="2" style="padding-top:14px;border-top:1px solid #ddd;font-weight:bold;">'
                . htmlspecialchars($nameLine) . '</td></tr>';
            $fields = [
                'Previous Team' => $s['team'],
                'Individual Coach(es)' => $s['individual_coach'],
                'Group Coach' => $s['group_coach'],
                'Payment Option' => $s['payment_option'],
                'Payment Frequency' => $s['payment_frequency'],
                'Coaching Files' => $s['coaching_files'],
            ];
            foreach ($fields as $label => $val) {
                $display = $val !== '' ? $val : '(none)';
                $rowsHtml .= '<tr><td style="padding:3px 12px 3px 0;color:#555;">' . htmlspecialchars($label) . ':</td>'
                    . '<td style="padding:3px 0;">' . htmlspecialchars($display) . '</td></tr>';
            }
            $rowsText .= "\n" . $nameLine . "\n";
            foreach ($fields as $label => $val) {
                $rowsText .= '  ' . $label . ': ' . ($val !== '' ? $val : '(none)') . "\n";
            }
        }

        $count = count($results['snapshots']);
        $subject = 'Roster drop notification: ' . $count . ' contact' . ($count === 1 ? '' : 's') . ' dropped';

        $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
            . '<body style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#333;">'
            . '<p>The following contact' . ($count === 1 ? ' has' : 's have') . ' been dropped from the roster by '
            . htmlspecialchars($actor) . ' on ' . htmlspecialchars(date('Y-m-d H:i T')) . '.</p>'
            . '<p><strong>Sebastian:</strong> please stop any auto payments and future manual triggers for the contact'
            . ($count === 1 ? '' : 's') . ' below.</p>'
            . '<table style="border-collapse:collapse;font-size:13px;">' . $rowsHtml . '</table>'
            . '</body></html>';

        $textBody = 'The following contact(s) have been dropped from the roster by ' . $actor
            . ' on ' . date('Y-m-d H:i T') . ".\n\n"
            . "Sebastian: please stop any auto payments and future manual triggers for the contact(s) below.\n"
            . $rowsText;

        // XML-RPC sendEmail
        $contactListXml = '';
        foreach ($adminContactIds as $id) {
            $contactListXml .= "<value><i4>{$id}</i4></value>";
        }

        $fromAddress = 'LiveWright Roster <no-reply@livewright.com>';

        $xmlRequest = '<?xml version="1.0" encoding="UTF-8"?>
<methodCall>
    <methodName>APIEmailService.sendEmail</methodName>
    <params>
        <param><value><string>' . htmlspecialchars($token) . '</string></value></param>
        <param><value><array><data>' . $contactListXml . '</data></array></value></param>
        <param><value><string>' . htmlspecialchars($fromAddress) . '</string></value></param>
        <param><value><string>~Contact.Email~</string></value></param>
        <param><value><string></string></value></param>
        <param><value><string></string></value></param>
        <param><value><string>Multipart</string></value></param>
        <param><value><string>' . htmlspecialchars($subject) . '</string></value></param>
        <param><value><string>' . htmlspecialchars($htmlBody) . '</string></value></param>
        <param><value><string>' . htmlspecialchars($textBody) . '</string></value></param>
    </params>
</methodCall>';

        $ch = curl_init('https://api.infusionsoft.com/crm/xmlrpc/v1');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xmlRequest,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token",
                "Content-Type: application/xml",
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $emailOk = ($httpCode === 200 && (strpos($resp, '<boolean>1</boolean>') !== false || strpos($resp, '<boolean>true</boolean>') !== false));

        $results['notification'] = [
            'sent' => $emailOk,
            'recipients' => array_keys($adminMap),
            'unresolved_emails' => $missing,
        ];
        if (!$emailOk) {
            $faultMsg = null;
            if (preg_match('/<string>([^<]+)<\/string>/', $resp, $m)) $faultMsg = $m[1];
            $results['notification']['error'] = $faultMsg ?: ('HTTP ' . $httpCode);
        }
    } else {
        $results['notification'] = [
            'sent' => false,
            'error' => 'None of the configured admin emails resolved to Keap contacts.',
            'unresolved_emails' => $missing,
        ];
    }
}

$results['total_requested'] = count($contactIds);
$results['total_dropped'] = count($results['dropped']);
$results['total_failed'] = count($results['failed']);

echo json_encode($results, JSON_PRETTY_PRINT);
