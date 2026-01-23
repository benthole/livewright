<?php
// update_cohort.php - Update cohort custom field in Keap for selected contacts

require_once('includes/auth.php');
require_once('keap_api.php');
require_once('config.php');

header('Content-Type: application/json');

// Require editor permissions
require_editor();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$contactIds = isset($input['contact_ids']) ? $input['contact_ids'] : [];
$newCohort = isset($input['cohort']) ? trim($input['cohort']) : '';

if (empty($contactIds)) {
    echo json_encode(['success' => false, 'error' => 'No contacts selected']);
    exit;
}

if ($newCohort === '') {
    echo json_encode(['success' => false, 'error' => 'No cohort selected']);
    exit;
}

// Validate cohort is in our allowed list
$allCohorts = array_merge($cohorts['active'], $cohorts['functional'], $cohorts['inactive']);
if (!in_array($newCohort, $allCohorts)) {
    echo json_encode(['success' => false, 'error' => 'Invalid cohort value']);
    exit;
}

// Get Keap token
$token = get_keap_token();

if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Could not get Keap API token']);
    exit;
}

$results = [
    'success' => true,
    'updated' => [],
    'failed' => []
];

// Update each contact in Keap
foreach ($contactIds as $contactId) {
    $contactId = (int)$contactId;

    if ($contactId <= 0) {
        $results['failed'][] = ['id' => $contactId, 'error' => 'Invalid contact ID'];
        continue;
    }

    // Keap API endpoint to update contact
    $url = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}";

    // Prepare the custom field update payload
    // Custom field ID 45 is the cohort field
    $payload = [
        'custom_fields' => [
            [
                'id' => $cohort_field_id,
                'content' => $newCohort
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ]
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $results['updated'][] = $contactId;
    } else {
        $errorData = json_decode($resp, true);
        $errorMsg = isset($errorData['message']) ? $errorData['message'] : 'Unknown error';
        $results['failed'][] = ['id' => $contactId, 'error' => $errorMsg, 'http_code' => $httpCode];
    }
}

// Also update local database
try {
    $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get all roster entries to find matching contact IDs
    $stmt = $conn->query("SELECT id, data FROM roster");
    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build a map of Keap contact ID to database row ID and contact details
    $contactToRowId = [];
    foreach ($allRows as $row) {
        $data = json_decode($row['data'], true);
        if (isset($data['id'])) {
            // Get contact name and email
            $firstName = isset($data['given_name']) ? $data['given_name'] : '';
            $lastName = isset($data['family_name']) ? $data['family_name'] : '';
            $contactName = trim($firstName . ' ' . $lastName);

            $email = '';
            if (isset($data['email_addresses']) && is_array($data['email_addresses']) && count($data['email_addresses']) > 0) {
                $email = $data['email_addresses'][0]['email'];
            }

            // Get current cohort value
            $currentCohort = '';
            if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
                foreach ($data['custom_fields'] as $field) {
                    if (isset($field['id']) && (int)$field['id'] === $cohort_field_id) {
                        $currentCohort = isset($field['content']) ? $field['content'] : '';
                        break;
                    }
                }
            }

            $contactToRowId[(int)$data['id']] = [
                'row_id' => $row['id'],
                'data' => $data,
                'name' => $contactName,
                'email' => $email,
                'current_cohort' => $currentCohort
            ];
        }
    }

    $dbUpdated = 0;
    foreach ($results['updated'] as $contactId) {
        $contactId = (int)$contactId;

        if (!isset($contactToRowId[$contactId])) {
            continue;
        }

        $rowId = $contactToRowId[$contactId]['row_id'];
        $data = $contactToRowId[$contactId]['data'];

        // Update the custom field in the JSON
        if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
            $found = false;
            foreach ($data['custom_fields'] as &$field) {
                if (isset($field['id']) && (int)$field['id'] === $cohort_field_id) {
                    $field['content'] = $newCohort;
                    $found = true;
                    break;
                }
            }
            unset($field);

            // If field doesn't exist, add it
            if (!$found) {
                $data['custom_fields'][] = [
                    'id' => $cohort_field_id,
                    'content' => $newCohort
                ];
            }
        } else {
            $data['custom_fields'] = [
                [
                    'id' => $cohort_field_id,
                    'content' => $newCohort
                ]
            ];
        }

        // Update the database row by its primary key
        $updateStmt = $conn->prepare("UPDATE roster SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $updateStmt->execute([json_encode($data), $rowId]);
        $dbUpdated++;

        // Log the change
        $logStmt = $conn->prepare("INSERT INTO change_log (keap_contact_id, contact_name, contact_email, field_changed, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)");
        $logStmt->execute([
            $contactId,
            $contactToRowId[$contactId]['name'],
            $contactToRowId[$contactId]['email'],
            'team',
            $contactToRowId[$contactId]['current_cohort'],
            $newCohort
        ]);
    }

    $results['db_updated'] = $dbUpdated;
} catch (PDOException $e) {
    $results['db_error'] = 'Local database update failed: ' . $e->getMessage();
}

$results['total_requested'] = count($contactIds);
$results['total_updated'] = count($results['updated']);
$results['total_failed'] = count($results['failed']);

echo json_encode($results, JSON_PRETTY_PRINT);
