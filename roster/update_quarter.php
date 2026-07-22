<?php
// update_quarter.php - Update the Quarter custom field in Keap for selected contacts.
// Mirrors update_cohort.php but targets the Quarter field ($quarter_field_id).

require_once('includes/auth.php');
require_once('keap_api.php');
require_once('config.php');

header('Content-Type: application/json');

require_editor();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Quarter field id lives in config.php (server-only). Dormant if unset.
$quarter_field_id = isset($quarter_field_id) ? (int)$quarter_field_id : 0;
if ($quarter_field_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Quarter field is not configured']);
    exit;
}

$quarter_values = ['Q1', 'Q2', 'Q3', 'Q4'];

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$contactIds = isset($input['contact_ids']) ? $input['contact_ids'] : [];
$newQuarter = isset($input['quarter']) ? trim($input['quarter']) : '';

if (empty($contactIds)) {
    echo json_encode(['success' => false, 'error' => 'No contacts selected']);
    exit;
}
if ($newQuarter === '') {
    echo json_encode(['success' => false, 'error' => 'No quarter selected']);
    exit;
}

// Validate against the fixed list, then the live Keap options as a fallback.
if (!in_array($newQuarter, $quarter_values, true)) {
    $isValid = false;
    $fieldOptions = keap_get_custom_field_options($quarter_field_id);
    if (is_array($fieldOptions) && !isset($fieldOptions['error']) && in_array($newQuarter, $fieldOptions, true)) {
        $isValid = true;
    }
    if (!$isValid) {
        echo json_encode(['success' => false, 'error' => 'Invalid quarter value']);
        exit;
    }
}

$token = get_keap_token();
if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Could not get Keap API token']);
    exit;
}

$results = ['success' => true, 'updated' => [], 'failed' => []];

foreach ($contactIds as $contactId) {
    $contactId = (int)$contactId;
    if ($contactId <= 0) {
        $results['failed'][] = ['id' => $contactId, 'error' => 'Invalid contact ID'];
        continue;
    }

    $url = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$contactId}";
    $payload = ['custom_fields' => [['id' => $quarter_field_id, 'content' => $newQuarter]]];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
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

// Update local roster cache + change_log for the contacts that succeeded.
try {
    $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $conn->query("SELECT id, data FROM roster");
    $contactToRowId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $data = json_decode($row['data'], true);
        if (!isset($data['id'])) continue;

        $firstName = $data['given_name'] ?? '';
        $lastName = $data['family_name'] ?? '';
        $email = '';
        if (!empty($data['email_addresses'][0]['email'])) {
            $email = $data['email_addresses'][0]['email'];
        }
        $currentQuarter = '';
        if (!empty($data['custom_fields']) && is_array($data['custom_fields'])) {
            foreach ($data['custom_fields'] as $field) {
                if (isset($field['id']) && (int)$field['id'] === $quarter_field_id) {
                    $currentQuarter = $field['content'] ?? '';
                    break;
                }
            }
        }
        $contactToRowId[(int)$data['id']] = [
            'row_id' => $row['id'], 'data' => $data,
            'name' => trim($firstName . ' ' . $lastName),
            'email' => $email, 'current_quarter' => $currentQuarter,
        ];
    }

    $dbUpdated = 0;
    foreach ($results['updated'] as $contactId) {
        $contactId = (int)$contactId;
        if (!isset($contactToRowId[$contactId])) continue;

        $rowId = $contactToRowId[$contactId]['row_id'];
        $data = $contactToRowId[$contactId]['data'];

        if (!empty($data['custom_fields']) && is_array($data['custom_fields'])) {
            $found = false;
            foreach ($data['custom_fields'] as &$field) {
                if (isset($field['id']) && (int)$field['id'] === $quarter_field_id) {
                    $field['content'] = $newQuarter; $found = true; break;
                }
            }
            unset($field);
            if (!$found) $data['custom_fields'][] = ['id' => $quarter_field_id, 'content' => $newQuarter];
        } else {
            $data['custom_fields'] = [['id' => $quarter_field_id, 'content' => $newQuarter]];
        }

        $updateStmt = $conn->prepare("UPDATE roster SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $updateStmt->execute([json_encode($data), $rowId]);
        $dbUpdated++;

        $logStmt = $conn->prepare("INSERT INTO change_log (keap_contact_id, contact_name, contact_email, field_changed, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)");
        $logStmt->execute([
            $contactId,
            $contactToRowId[$contactId]['name'],
            $contactToRowId[$contactId]['email'],
            'quarter',
            $contactToRowId[$contactId]['current_quarter'],
            $newQuarter,
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
