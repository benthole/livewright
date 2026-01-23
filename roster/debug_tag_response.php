<?php
// debug_tag_response.php - Debug what the tags endpoint actually returns

require_once('keap_api.php');

$tagId = 525;
$token = get_keap_token();

// Call the tags endpoint directly
$url = "https://api.infusionsoft.com/crm/rest/v1/tags/{$tagId}/contacts?limit=50&offset=0";

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

echo "<h2>Tags Endpoint Raw Response</h2>";
echo "<p><strong>URL:</strong> {$url}</p>";
echo "<p><strong>HTTP Code:</strong> {$httpCode}</p>";

echo "<h3>Raw JSON:</h3>";
echo "<pre>" . htmlspecialchars($resp) . "</pre>";

echo "<h3>Parsed Data:</h3>";
$data = json_decode($resp, true);
echo "<pre>";
print_r($data);
echo "</pre>";

echo "<h3>Structure Analysis:</h3>";
if (isset($data['contacts'])) {
    echo "<p>data['contacts'] exists</p>";
    echo "<p>Type: " . gettype($data['contacts']) . "</p>";
    echo "<p>Count: " . count($data['contacts']) . "</p>";

    if (is_array($data['contacts']) && count($data['contacts']) > 0) {
        echo "<h4>First 3 items in contacts array:</h4>";
        foreach (array_slice($data['contacts'], 0, 3) as $idx => $item) {
            echo "<p><strong>Item {$idx}:</strong></p>";
            echo "<p>Type: " . gettype($item) . "</p>";
            echo "<p>Value: " . var_export($item, true) . "</p>";

            if (is_array($item)) {
                echo "<p>Keys: " . implode(', ', array_keys($item)) . "</p>";
            }
            echo "<hr>";
        }
    }
}
