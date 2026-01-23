<?php
// test_custom_fields.php - Test fetching contact with custom_fields

require_once('keap_api.php');

$token = get_keap_token();
$contactId = 90; // Dave Stamm

// Test with custom_fields
echo "<h2>Test: With custom_fields optional property</h2>";
$url = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}?optional_properties=custom_fields";
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
echo "<h3>Response:</h3>";
$data = json_decode($resp, true);
echo "<pre>";
print_r($data);
echo "</pre>";

if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
    echo "<hr>";
    echo "<h3>Custom Fields Details:</h3>";
    foreach ($data['custom_fields'] as $field) {
        if (isset($field['content']) &&
            (stripos($field['content'], 'Cohort') !== false ||
             stripos($field['content'], 'Coach') !== false ||
             stripos($field['content'], 'Care') !== false)) {
            echo "<p><strong>Field ID:</strong> " . ($field['id'] ?? 'N/A') . "</p>";
            echo "<p><strong>Content:</strong> " . ($field['content'] ?? 'N/A') . "</p>";
            echo "<hr>";
        }
    }
}
