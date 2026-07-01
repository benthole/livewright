<?php
// keap_api.php

require_once('../settings.php');

function get_keap_token() {
    // These variables must be defined in settings.php
    global $dbHost, $dbName, $dbUser, $dbPass, $appUrlShort;

    $token = '';

    try {
        $conn = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $query = "SELECT access_token FROM inf_settings, apps
                  WHERE apps.app_url = inf_settings.app_url
                  AND apps.url_short = :app_url
                  ORDER BY updated_on DESC
                  LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->execute(['app_url' => $appUrlShort]);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $token = $row['access_token'];
        }

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Token DB connection failed: ' . $e->getMessage()]);
        exit;
    }

    return $token;
}

/**
 * Fetch the option labels defined for a Keap contact custom field.
 *
 * Reads the contact model (which lists every custom field and, for
 * dropdown/radio fields, its options) and returns the option labels for
 * the requested field id. Returns an array of label strings (may be empty
 * for free-text fields), or an ['error' => ...] array on failure.
 *
 * Pure-separator options (e.g. "-----") are filtered out.
 */
function keap_get_custom_field_options($fieldId) {
    $token = get_keap_token();

    $url = "https://api.infusionsoft.com/crm/rest/v1/contacts/model";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    $resp = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log('Curl error (contact model): ' . $error);
        return ['error' => 'Curl error: ' . $error];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);

    if ($httpCode !== 200 || empty($data['custom_fields'])) {
        error_log('Keap API Error (contact model): ' . $resp);
        return ['error' => 'API Error', 'http_code' => $httpCode, 'response' => $data];
    }

    // Locate the requested field within the model's custom_fields list.
    $field = null;
    foreach ($data['custom_fields'] as $cf) {
        if (isset($cf['id']) && (string)$cf['id'] === (string)$fieldId) {
            $field = $cf;
            break;
        }
    }

    if ($field === null) {
        return ['error' => 'Custom field not found', 'field_id' => $fieldId];
    }

    // Each option carries a 'label'. Skip pure-separator entries (only dashes).
    $labels = [];
    if (!empty($field['options']) && is_array($field['options'])) {
        foreach ($field['options'] as $opt) {
            if (!isset($opt['label']) || $opt['label'] === '') {
                continue;
            }
            if (preg_match('/^-+$/', $opt['label'])) {
                continue; // dropdown separator, not a real team
            }
            $labels[] = $opt['label'];
        }
    }

    return $labels;
}

function keap_get_contacts_by_tag($tagId, $limit = 100, $offset = 0) {
    $token = get_keap_token();

    $tagId = (int)$tagId;

    // Use the List Tagged Contacts endpoint to get contact IDs
    $url = "https://api.infusionsoft.com/crm/rest/v1/tags/{$tagId}/contacts?limit={$limit}&offset={$offset}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ]
    ]);

    $resp = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log('Curl error: ' . $error);
        return ['error' => 'Curl error: ' . $error];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);

    if ($httpCode !== 200) {
        error_log('Keap API Error: ' . $resp);
        return ['error' => 'API Error', 'http_code' => $httpCode, 'response' => $data];
    }

    // The tags endpoint returns contact objects nested in a 'contact' key
    // Extract the contact IDs from the nested structure
    $taggedContacts = isset($data['contacts']) ? $data['contacts'] : [];

    if (empty($taggedContacts)) {
        return ['contacts' => [], 'count' => 0];
    }

    // Fetch full contact details in parallel batches using curl_multi
    // The tag endpoint only returns basic fields; we need custom_fields
    $contactIds = [];
    foreach ($taggedContacts as $item) {
        if (isset($item['contact']['id'])) {
            $contactIds[] = (int)$item['contact']['id'];
        }
    }

    $fullContacts = [];
    $batchSize = 10;
    $batches = array_chunk($contactIds, $batchSize);

    foreach ($batches as $batch) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($batch as $contactId) {
            $contactUrl = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}?optional_properties=custom_fields";
            $ch = curl_init($contactUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer $token",
                    "Content-Type: application/json"
                ],
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        // Execute all requests in parallel
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }
        } while ($active && $status === CURLM_OK);

        // Collect results
        foreach ($handles as $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode === 200) {
                $contactData = json_decode(curl_multi_getcontent($ch), true);
                if ($contactData) {
                    $fullContacts[] = $contactData;
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
    }

    return ['contacts' => $fullContacts, 'count' => count($fullContacts)];
}
