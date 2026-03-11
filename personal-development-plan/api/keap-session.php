<?php
/**
 * Keap Payment Session API
 *
 * Creates/finds a Keap contact and returns a payment session key
 * for the Keap payment widget.
 *
 * POST JSON:
 *   - contract_uid: string
 *   - first_name: string
 *   - last_name: string
 *   - email: string
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/keap-pdp-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$contract_uid = $input['contract_uid'] ?? '';
$first_name = trim($input['first_name'] ?? '');
$last_name = trim($input['last_name'] ?? '');
$email = trim($input['email'] ?? '');

if (empty($contract_uid) || empty($first_name) || empty($last_name) || empty($email)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

try {
    // Verify contract exists
    $stmt = $pdo->prepare("SELECT * FROM contracts WHERE unique_id = ? AND deleted_at IS NULL");
    $stmt->execute([$contract_uid]);
    $contract = $stmt->fetch();

    if (!$contract) {
        http_response_code(404);
        echo json_encode(['error' => 'Contract not found']);
        exit;
    }

    // Find or create Keap contact
    $contactResult = pdp_keap_find_or_create_contact($first_name, $last_name, $email);

    if (!$contactResult['success']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create Keap contact: ' . $contactResult['error']]);
        exit;
    }

    $contactId = $contactResult['contact_id'];

    // Save Keap contact ID to contract
    $stmt = $pdo->prepare("UPDATE contracts SET keap_contact_id = ? WHERE id = ?");
    $stmt->execute([$contactId, $contract['id']]);

    // Get payment session key
    $sessionResult = pdp_keap_get_payment_session($contactId);

    if (!$sessionResult['success']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get payment session: ' . $sessionResult['error']]);
        exit;
    }

    echo json_encode([
        'session_key' => $sessionResult['session_key'],
        'contact_id' => $contactId,
    ]);

} catch (Exception $e) {
    error_log('keap-session.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred setting up payment']);
}
