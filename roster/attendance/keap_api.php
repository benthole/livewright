<?php
// keap_api.php

require_once('../settings.php');

function get_keap_token() {
    // These variables must be defined in your config.php or elsewhere
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
			file_put_contents(__DIR__ . '/keap_token_debug.log', "[" . date('Y-m-d H:i:s') . "] Token pulled: " . $token . "\n", FILE_APPEND);

		}

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Token DB connection failed: ' . $e->getMessage()]);
        exit;
    }

    return $token;
}

function keap_find_tag_by_name($tagName) {
    $token = get_keap_token();
    $url = "https://api.infusionsoft.com/crm/rest/v1/tags?limit=100"; // first 100 tags

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ]
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);

    if (!empty($data['tags'])) {
        foreach ($data['tags'] as $tag) {
            if (strtolower($tag['name']) === strtolower($tagName)) {
                return $tag; // ✅ Found it
            }
        }
    }

    return null; // No match found
}

function keap_create_tag($tagName) {
    $token = get_keap_token();
    $url = "https://api.infusionsoft.com/crm/rest/v1/tags";

    $payload = json_encode([
        "name" => $tagName
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => $payload
    ]);
    $resp = curl_exec($ch);

file_put_contents(__DIR__ . '/keap_contact_debug.log',
    "[keap_create_tag] RAW Response: " . var_export($resp, true) . "\n",
    FILE_APPEND
);


    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log('Curl error: ' . $error);
        return ['error' => 'Curl error: ' . $error];
    }

    curl_close($ch);
    $data = json_decode($resp, true);

    if (!isset($data['id'])) {
        error_log('Keap Tag Creation Error: ' . $resp); // Should log full response
        return $data; // <== returning full response!
    }

    return ['id' => $data['id']];
}

function keap_apply_tag_to_contact($contactId, $tagId) {
    $token = get_keap_token();
    $url = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}/tags"; // ✅ Correct endpoint

    $payload = json_encode(["tagIds" => [$tagId]]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => $payload
    ]);
    $resp = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log("Curl error tagging contact {$contactId}: " . $error);
        return false;
    }

    curl_close($ch);
    $data = json_decode($resp, true);

    if (isset($data['fault'])) {
        error_log("Keap API fault tagging contact {$contactId}: " . json_encode($data));
        return false;
    }

    // ✅ Optionally log success
    error_log("Tagged contact {$contactId} with tag {$tagId} successfully.");

    return true;
}