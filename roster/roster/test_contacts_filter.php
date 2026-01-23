<?php
// test_contacts_filter.php - Test if v1 contacts endpoint filters by ID

require_once('keap_api.php');

$token = get_keap_token();

// Test with a few specific IDs from the tag results
$testIds = "90,262,312";

$url = "https://api.infusionsoft.com/crm/rest/v1/contacts?id={$testIds}&limit=10";

echo "<h2>Testing v1 Contacts Endpoint with ID Filter</h2>";
echo "<p><strong>URL:</strong> {$url}</p>";

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

echo "<h3>Response:</h3>";
echo "<pre>";
print_r($data);
echo "</pre>";

echo "<h3>Analysis:</h3>";
if (isset($data['contacts'])) {
    echo "<p>Total contacts returned: " . count($data['contacts']) . "</p>";
    echo "<p>Expected: 3 contacts (IDs: 90, 262, 312)</p>";

    echo "<h4>Contact IDs returned:</h4>";
    foreach ($data['contacts'] as $contact) {
        echo "<p>ID: " . ($contact['id'] ?? 'N/A') . " - " . ($contact['email'] ?? 'N/A') . "</p>";
    }
}
