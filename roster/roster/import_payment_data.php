<?php
// import_payment_data.php - Import payment options and frequency from CSV to Keap

require_once('keap_api.php');
require_once('config.php');

// Parse the billing info into payment option and frequency
function parseBillingInfo($billingInfo) {
    $billingInfo = trim($billingInfo);

    // Default values
    $paymentOption = $billingInfo;
    $frequency = '';

    // Handle special cases
    $lowerBilling = strtolower($billingInfo);

    if ($lowerBilling === 'comp' || $lowerBilling === '0' || $lowerBilling === '') {
        return [
            'option' => $billingInfo ?: 'N/A',
            'frequency' => $billingInfo === 'COMP' ? 'Complimentary' : ($billingInfo === '0' ? 'No charge' : '')
        ];
    }

    if ($lowerBilling === 'exchange') {
        return [
            'option' => 'Exchange arrangement',
            'frequency' => 'Exchange'
        ];
    }

    if (strpos($lowerBilling, "couldn't find") !== false || $lowerBilling === '??' || strpos($lowerBilling, 'ask ') === 0) {
        return [
            'option' => $billingInfo,
            'frequency' => 'Unknown'
        ];
    }

    // Detect frequency from the billing string
    if (preg_match('/\/month|monthly/i', $billingInfo)) {
        $frequency = 'Monthly';
    } elseif (preg_match('/\/quarter|quarterly/i', $billingInfo)) {
        $frequency = 'Quarterly';
    } elseif (preg_match('/\/year|yearly|annually/i', $billingInfo)) {
        $frequency = 'Yearly';
    }

    // Check for minimum duration
    if (preg_match('/minimum\s+(\d+)\s+months?/i', $billingInfo, $matches)) {
        $frequency .= $frequency ? ", minimum {$matches[1]} months" : "Minimum {$matches[1]} months";
    }

    // Clean up the payment option - capitalize first letter
    $paymentOption = ucfirst($billingInfo);

    return [
        'option' => $paymentOption,
        'frequency' => $frequency ?: 'See notes'
    ];
}

// Get Keap access token
$token = get_keap_token();

if (!$token) {
    die(json_encode(['success' => false, 'error' => 'Could not get Keap token']));
}

// Read the CSV file
$csvFile = __DIR__ . '/../to-import/payment options and frequency.csv';
if (!file_exists($csvFile)) {
    die(json_encode(['success' => false, 'error' => 'CSV file not found: ' . $csvFile]));
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    die(json_encode(['success' => false, 'error' => 'Could not open CSV file']));
}

// Skip header row
$header = fgetcsv($handle);

$results = [
    'success' => true,
    'processed' => [],
    'errors' => [],
    'total' => 0,
    'updated' => 0,
    'failed' => 0
];

// Process each row
while (($row = fgetcsv($handle)) !== false) {
    $results['total']++;

    $firstName = trim($row[0] ?? '');
    $lastName = trim($row[1] ?? '');
    $billingInfo = trim($row[2] ?? '');

    if (!$firstName || !$lastName) {
        $results['errors'][] = "Row {$results['total']}: Missing name";
        $results['failed']++;
        continue;
    }

    // Parse billing info
    $parsed = parseBillingInfo($billingInfo);

    // Search for contact in Keap by name
    $searchUrl = "https://api.infusionsoft.com/crm/rest/v1/contacts?given_name=" . urlencode($firstName) . "&family_name=" . urlencode($lastName) . "&limit=10";

    $ch = curl_init($searchUrl);
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

    if ($httpCode !== 200) {
        $results['errors'][] = "$firstName $lastName: Search failed (HTTP $httpCode)";
        $results['failed']++;
        continue;
    }

    $searchData = json_decode($resp, true);
    $contacts = $searchData['contacts'] ?? [];

    if (empty($contacts)) {
        $results['errors'][] = "$firstName $lastName: Contact not found in Keap";
        $results['failed']++;
        continue;
    }

    // Use first matching contact
    $contactId = $contacts[0]['id'];

    // Update custom fields 107 (PaymentOption) and 109 (PaymentOptionFrequency)
    $updateUrl = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}";

    $updateData = [
        'custom_fields' => [
            ['id' => 107, 'content' => $parsed['option']],
            ['id' => 109, 'content' => $parsed['frequency']]
        ]
    ];

    $ch = curl_init($updateUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($updateData),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ]
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $results['updated']++;
        $results['processed'][] = [
            'name' => "$firstName $lastName",
            'contact_id' => $contactId,
            'payment_option' => $parsed['option'],
            'payment_frequency' => $parsed['frequency']
        ];
    } else {
        $results['errors'][] = "$firstName $lastName: Update failed (HTTP $httpCode)";
        $results['failed']++;
    }
}

fclose($handle);

// Output results
header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);
