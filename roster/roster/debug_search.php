<?php
// debug_search.php - Debug the Keap API search call

require_once('keap_api.php');

$searchId = 133;
$limit = 10; // Just get 10 for debugging

// Test 1: Try with savedSearchId parameter
echo "<h2>Test 1: Using savedSearchId parameter</h2>\n";
$results1 = keap_get_saved_search_results($searchId, $limit, 0);
echo "<pre>";
echo "URL used: https://api.infusionsoft.com/crm/rest/v1/contacts?savedSearchId={$searchId}&limit={$limit}&offset=0\n\n";
echo "Results:\n";
print_r($results1);
echo "</pre>";
echo "<hr>";

// Test 2: Try with saved_search_id parameter (underscore version)
echo "<h2>Test 2: Using saved_search_id parameter</h2>\n";
$token = get_keap_token();
$url2 = "https://api.infusionsoft.com/crm/rest/v1/contacts?saved_search_id={$searchId}&limit={$limit}&offset=0";

$ch = curl_init($url2);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ]
]);
$resp = curl_exec($ch);
curl_close($ch);
$results2 = json_decode($resp, true);

echo "<pre>";
echo "URL used: {$url2}\n\n";
echo "Results:\n";
print_r($results2);
echo "</pre>";
echo "<hr>";

// Test 3: Get the actual count from the response
echo "<h2>Test 3: Check count field</h2>\n";
echo "<pre>";
echo "Count from Test 1: " . ($results1['count'] ?? 'not found') . "\n";
echo "Total contacts from Test 1: " . (isset($results1['contacts']) ? count($results1['contacts']) : 'not found') . "\n\n";
echo "Count from Test 2: " . ($results2['count'] ?? 'not found') . "\n";
echo "Total contacts from Test 2: " . (isset($results2['contacts']) ? count($results2['contacts']) : 'not found') . "\n";
echo "</pre>";
