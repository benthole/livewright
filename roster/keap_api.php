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

    // Fetch full contact details for each contact individually
    // This ensures we only get the contacts we want with all their custom fields
    $fullContacts = [];

    foreach ($taggedContacts as $item) {
        if (!isset($item['contact']['id'])) {
            continue;
        }

        $contactId = (int)$item['contact']['id'];

        // Fetch full contact details including custom fields
        // Request custom_fields as an optional property to get all custom fields
        $contactUrl = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}?optional_properties=custom_fields";

        $ch = curl_init($contactUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token",
                "Content-Type: application/json"
            ]
        ]);

        $contactResp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $contactData = json_decode($contactResp, true);
            if ($contactData) {
                $fullContacts[] = $contactData;
            }
        }
    }

    return ['contacts' => $fullContacts, 'count' => count($fullContacts)];
}
