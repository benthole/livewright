<?php
// update_coaching_files.php - Update coaching files link in Keap

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

$contactId = isset($input['contact_id']) ? (int)$input['contact_id'] : 0;
$coachingFilesLink = isset($input['coaching_files_link']) ? trim($input['coaching_files_link']) : '';

if ($contactId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid contact ID']);
    exit;
}

// Get Keap token
$token = get_keap_token();

if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Could not get Keap API token']);
    exit;
}

// Keap API endpoint to update contact
$url = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}";

// Prepare the update payload with custom field
// Custom field ID 135 = CoachingFilesLink
$payload = [
    'custom_fields' => [
        [
            'id' => 135,
            'content' => $coachingFilesLink ?: null
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

if ($httpCode !== 200) {
    $errorData = json_decode($resp, true);
    $errorMsg = isset($errorData['message']) ? $errorData['message'] : 'Unknown error';
    echo json_encode(['success' => false, 'error' => $errorMsg, 'http_code' => $httpCode]);
    exit;
}

// Also update local database
try {
    $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Find the roster entry for this contact
    $stmt = $conn->query("SELECT id, data FROM roster");
    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rowId = null;
    $data = null;
    $oldValue = '';
    $contactName = '';
    $contactEmail = '';

    foreach ($allRows as $row) {
        $rowData = json_decode($row['data'], true);
        if (isset($rowData['id']) && (int)$rowData['id'] === $contactId) {
            $rowId = $row['id'];
            $data = $rowData;

            $contactName = trim(($data['given_name'] ?? '') . ' ' . ($data['family_name'] ?? ''));

            if (isset($data['email_addresses']) && is_array($data['email_addresses']) && count($data['email_addresses']) > 0) {
                $contactEmail = $data['email_addresses'][0]['email'];
            }

            // Find old value
            if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
                foreach ($data['custom_fields'] as $field) {
                    if (isset($field['id']) && (int)$field['id'] === 135) {
                        $oldValue = $field['content'] ?? '';
                        break;
                    }
                }
            }
            break;
        }
    }

    if ($rowId && $data) {
        // Update the custom field in the JSON data
        $fieldFound = false;
        if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
            foreach ($data['custom_fields'] as $key => $field) {
                if (isset($field['id']) && (int)$field['id'] === 135) {
                    $data['custom_fields'][$key]['content'] = $coachingFilesLink ?: null;
                    $fieldFound = true;
                    break;
                }
            }
        }

        // If field doesn't exist, add it
        if (!$fieldFound) {
            if (!isset($data['custom_fields'])) {
                $data['custom_fields'] = [];
            }
            $data['custom_fields'][] = [
                'id' => 135,
                'content' => $coachingFilesLink ?: null
            ];
        }

        // Update the database row
        $updateStmt = $conn->prepare("UPDATE roster SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $updateStmt->execute([json_encode($data), $rowId]);

        // Log the change
        $logStmt = $conn->prepare("INSERT INTO change_log (keap_contact_id, contact_name, contact_email, field_changed, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)");
        $logStmt->execute([
            $contactId,
            $contactName,
            $contactEmail,
            'coaching_files_link',
            $oldValue,
            $coachingFilesLink
        ]);
    }

    echo json_encode([
        'success' => true,
        'contact_id' => $contactId,
        'coaching_files_link' => $coachingFilesLink
    ]);

} catch (PDOException $e) {
    // Keap update succeeded but local DB failed - still report success
    echo json_encode([
        'success' => true,
        'contact_id' => $contactId,
        'coaching_files_link' => $coachingFilesLink,
        'db_warning' => 'Local database update failed: ' . $e->getMessage()
    ]);
}
