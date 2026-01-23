<?php
// test_tag.php - Test the exact API call for tag ID 525

require_once('keap_api.php');

$tagId = 525;
$token = get_keap_token();

// Test the API call directly
$url = "https://api.infusionsoft.com/crm/rest/v1/contacts?tagId={$tagId}&limit=50&offset=0";

echo "<h2>Testing Keap API with Tag ID 525</h2>";
echo "<p><strong>URL:</strong> {$url}</p>";
echo "<p><strong>Token (first 20 chars):</strong> " . substr($token, 0, 20) . "...</p>";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ]
]);

$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p><strong>HTTP Code:</strong> {$httpCode}</p>";

$data = json_decode($resp, true);

echo "<h3>Response Data:</h3>";
echo "<pre>";
print_r($data);
echo "</pre>";

echo "<hr>";
echo "<h3>Analysis:</h3>";
echo "<p>Total contacts returned: " . (isset($data['contacts']) ? count($data['contacts']) : 'N/A') . "</p>";
echo "<p>Count field: " . ($data['count'] ?? 'N/A') . "</p>";

if (isset($data['contacts']) && count($data['contacts']) > 0) {
    echo "<h3>First Contact Sample:</h3>";
    echo "<pre>";
    print_r($data['contacts'][0]);
    echo "</pre>";

    echo "<h3>Checking if contacts have tag 525:</h3>";
    foreach (array_slice($data['contacts'], 0, 5) as $idx => $contact) {
        echo "<p><strong>Contact " . ($idx + 1) . " (ID: " . ($contact['id'] ?? 'N/A') . "):</strong></p>";
        if (isset($contact['tag_ids']) && is_array($contact['tag_ids'])) {
            echo "<p>Tags: " . implode(', ', $contact['tag_ids']) . "</p>";
            echo "<p>Has tag 525: " . (in_array(525, $contact['tag_ids']) ? 'YES' : 'NO') . "</p>";
        } else {
            echo "<p>No tag_ids field found</p>";
        }
    }
}
