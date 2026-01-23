<?php
// test_single_contact.php - Test fetching a single contact with custom fields

require_once('keap_api.php');

$token = get_keap_token();
$contactId = 90; // Dave Stamm from the tag results

// Test without optional_properties
echo "<h2>Test 1: Without optional_properties</h2>";
$url1 = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}";
echo "<p><strong>URL:</strong> {$url1}</p>";

$ch = curl_init($url1);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ]
]);

$resp1 = curl_exec($ch);
$httpCode1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p><strong>HTTP Code:</strong> {$httpCode1}</p>";
echo "<h3>Response:</h3>";
echo "<pre>";
print_r(json_decode($resp1, true));
echo "</pre>";

echo "<hr>";

// Test with optional_properties
echo "<h2>Test 2: With optional_properties</h2>";
$customFields = '_Cohort,_CoachIndividual,_CoachGroup,_CAREC,_CAREA,_CARER,_CAREE';
$url2 = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}?optional_properties={$customFields}";
echo "<p><strong>URL:</strong> {$url2}</p>";

$ch = curl_init($url2);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ]
]);

$resp2 = curl_exec($ch);
$httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "<p><strong>HTTP Code:</strong> {$httpCode2}</p>";
if ($curlError) {
    echo "<p><strong>Curl Error:</strong> {$curlError}</p>";
}
echo "<h3>Response:</h3>";
echo "<pre>";
print_r(json_decode($resp2, true));
echo "</pre>";
