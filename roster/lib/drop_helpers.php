<?php
/**
 * Shared drop-workflow helpers.
 *
 * Used by:
 *   - drop_contact.php  (immediate web-triggered drop)
 *   - scheduled_drops.php  (admin "process due drops now" button)
 *   - process_scheduled_drops.php  (cron-style runner)
 *
 * The actual Keap calls + local roster cache update for ONE contact live in
 * drop_execute_one(). The caller is responsible for connecting the DB,
 * loading the roster cache, fetching a Keap token, and (if relevant) sending
 * the admin notification email.
 */

if (!function_exists('drop_keap_request_lib')) {
    function drop_keap_request_lib($method, $url, $token, $payload = null) {
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
}

if (!function_exists('drop_get_field_lib')) {
    function drop_get_field_lib($customFields, $fieldId) {
        if (is_array($customFields)) {
            foreach ($customFields as $field) {
                if (isset($field['id']) && (int)$field['id'] === (int)$fieldId) {
                    return isset($field['content']) ? $field['content'] : '';
                }
            }
        }
        return '';
    }
}

/**
 * Process a single contact drop (Keap PATCH + tag + note + local cache).
 *
 * Returns:
 *   [
 *     'success' => bool,
 *     'snapshot' => [ ... archived field values ... ],
 *     'warnings' => [...],
 *     'error' => string|null,  // set when success === false
 *   ]
 *
 * @param PDO   $conn               Open PDO connection to LiveWright DB.
 * @param int   $contactId          Keap contact id.
 * @param string $token             Keap OAuth token.
 * @param array $cfg                Drop config (cohort_field_id, individual_coach_field_id,
 *                                  individual_coach_field_id_old, group_coach_field_id,
 *                                  coaching_files_field_id, drop_tag_id).
 * @param array $rosterByContactId  Pre-loaded roster cache keyed by contact id.
 * @param string $actor             Who initiated the drop (for the Keap note).
 */
function drop_execute_one(PDO $conn, $contactId, $token, array $cfg, array $rosterByContactId, $actor) {
    $errors = [];

    if (!isset($rosterByContactId[$contactId])) {
        return ['success' => false, 'snapshot' => null, 'warnings' => [], 'error' => 'Contact not found in local roster'];
    }

    $data  = $rosterByContactId[$contactId]['data'];
    $rowId = $rosterByContactId[$contactId]['row_id'];
    $customFields = $data['custom_fields'] ?? [];

    $firstName = $data['given_name'] ?? '';
    $lastName  = $data['family_name'] ?? '';
    $contactName  = trim($firstName . ' ' . $lastName);
    $contactEmail = '';
    if (!empty($data['email_addresses']) && is_array($data['email_addresses'])) {
        $contactEmail = $data['email_addresses'][0]['email'] ?? '';
    }

    $individualCoach = drop_get_field_lib($customFields, $cfg['individual_coach_field_id']);
    if ($individualCoach === '' && !empty($cfg['individual_coach_field_id_old'])) {
        $individualCoach = drop_get_field_lib($customFields, $cfg['individual_coach_field_id_old']);
    }

    $snapshot = [
        'contact_id' => (int)$contactId,
        'name' => $contactName,
        'email' => $contactEmail,
        'team' => drop_get_field_lib($customFields, $cfg['cohort_field_id']),
        'individual_coach' => $individualCoach,
        'group_coach' => drop_get_field_lib($customFields, $cfg['group_coach_field_id']),
        'payment_option' => drop_get_field_lib($customFields, 107),
        'payment_frequency' => drop_get_field_lib($customFields, 109),
        'coaching_files' => drop_get_field_lib($customFields, $cfg['coaching_files_field_id']),
    ];

    // 1. PATCH contact
    $patchPayload = [
        'custom_fields' => [
            ['id' => (int)$cfg['cohort_field_id'], 'content' => '-dropped'],
            ['id' => (int)$cfg['individual_coach_field_id'], 'content' => ''],
            ['id' => (int)$cfg['group_coach_field_id'], 'content' => ''],
        ],
    ];
    $patchResp = drop_keap_request_lib(
        'PATCH',
        "https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}",
        $token,
        $patchPayload
    );
    if ($patchResp['http_code'] !== 200) {
        $msg = $patchResp['data']['message'] ?? 'Keap PATCH failed';
        return ['success' => false, 'snapshot' => $snapshot, 'warnings' => [], 'error' => $msg];
    }

    // 2. Apply drop tag
    $tagResp = drop_keap_request_lib(
        'POST',
        "https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}/tags",
        $token,
        ['tagIds' => [(int)$cfg['drop_tag_id']]]
    );
    if ($tagResp['http_code'] !== 200 && $tagResp['http_code'] !== 201) {
        $errors[] = 'tag_apply_failed(' . $tagResp['http_code'] . ')';
    }

    // 3. Keap note
    $noteLines = [];
    $noteLines[] = 'Dropped on ' . date('Y-m-d H:i T') . ' by ' . ($actor ?: 'unknown');
    $noteLines[] = '';
    $noteLines[] = 'Previous Team: ' . ($snapshot['team'] !== '' ? $snapshot['team'] : '(none)');
    $noteLines[] = 'Previous Individual Coach(es): ' . ($snapshot['individual_coach'] !== '' ? $snapshot['individual_coach'] : '(none)');
    $noteLines[] = 'Previous Group Coach: ' . ($snapshot['group_coach'] !== '' ? $snapshot['group_coach'] : '(none)');
    $noteLines[] = 'Payment Option: ' . ($snapshot['payment_option'] !== '' ? $snapshot['payment_option'] : '(none)');
    $noteLines[] = 'Payment Frequency: ' . ($snapshot['payment_frequency'] !== '' ? $snapshot['payment_frequency'] : '(none)');
    $noteLines[] = 'Coaching Files: ' . ($snapshot['coaching_files'] !== '' ? $snapshot['coaching_files'] : '(none)');
    $noteResp = drop_keap_request_lib(
        'POST',
        'https://api.infusionsoft.com/crm/rest/v1/notes',
        $token,
        [
            'contact_id' => (int)$contactId,
            'title' => 'Dropped from program',
            'body' => implode("\n", $noteLines),
            'type' => 'Other',
        ]
    );
    if ($noteResp['http_code'] !== 200 && $noteResp['http_code'] !== 201) {
        $errors[] = 'note_create_failed(' . $noteResp['http_code'] . ')';
    }

    // 4. Local roster cache update
    try {
        $found = ['team' => false, 'ind' => false, 'grp' => false];
        if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
            foreach ($data['custom_fields'] as &$field) {
                $fid = (int)($field['id'] ?? 0);
                if ($fid === (int)$cfg['cohort_field_id']) { $field['content'] = '-dropped'; $found['team'] = true; }
                elseif ($fid === (int)$cfg['individual_coach_field_id']) { $field['content'] = ''; $found['ind'] = true; }
                elseif ($fid === (int)$cfg['group_coach_field_id']) { $field['content'] = ''; $found['grp'] = true; }
            }
            unset($field);
        } else {
            $data['custom_fields'] = [];
        }
        if (!$found['team']) $data['custom_fields'][] = ['id' => (int)$cfg['cohort_field_id'], 'content' => '-dropped'];
        if (!$found['ind'])  $data['custom_fields'][] = ['id' => (int)$cfg['individual_coach_field_id'], 'content' => ''];
        if (!$found['grp'])  $data['custom_fields'][] = ['id' => (int)$cfg['group_coach_field_id'], 'content' => ''];

        $upd = $conn->prepare("UPDATE roster SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $upd->execute([json_encode($data), $rowId]);

        $log = $conn->prepare("INSERT INTO change_log (keap_contact_id, contact_name, contact_email, field_changed, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)");
        $log->execute([(int)$contactId, $contactName, $contactEmail, 'team', $snapshot['team'], '-dropped']);
        if ($snapshot['individual_coach'] !== '') {
            $log->execute([(int)$contactId, $contactName, $contactEmail, 'individual_coach', $snapshot['individual_coach'], '']);
        }
        if ($snapshot['group_coach'] !== '') {
            $log->execute([(int)$contactId, $contactName, $contactEmail, 'group_coach', $snapshot['group_coach'], '']);
        }
    } catch (PDOException $e) {
        $errors[] = 'db_update_failed';
    }

    return ['success' => true, 'snapshot' => $snapshot, 'warnings' => $errors, 'error' => null];
}

/**
 * Convenience: load the roster cache once, return contact-id-keyed map.
 */
function drop_load_roster_cache(PDO $conn) {
    $stmt = $conn->query("SELECT id, data FROM roster");
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = json_decode($row['data'], true);
        if (!isset($data['id'])) continue;
        $out[(int)$data['id']] = ['row_id' => $row['id'], 'data' => $data];
    }
    return $out;
}
