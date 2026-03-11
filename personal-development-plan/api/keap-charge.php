<?php
/**
 * Keap Charge API
 *
 * Creates an order in Keap and processes payment using the payment method
 * collected by the Keap payment widget.
 *
 * POST JSON:
 *   - contract_uid: string
 *   - pricing_option_id: int
 *   - contact_id: int (Keap contact ID)
 *   - payment_method_id: string (credit card ID from Keap widget)
 *   - amount: float
 *   - is_deposit: bool
 *   - plan_description: string
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
$pricing_option_id = (int)($input['pricing_option_id'] ?? 0);
$contact_id = (int)($input['contact_id'] ?? 0);
$payment_method_id = $input['payment_method_id'] ?? '';
$amount = (float)($input['amount'] ?? 0);
$is_deposit = (bool)($input['is_deposit'] ?? false);
$plan_description = trim($input['plan_description'] ?? '');

if (empty($contract_uid) || empty($pricing_option_id) || empty($contact_id) || empty($payment_method_id) || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

try {
    // Get contract
    $stmt = $pdo->prepare("SELECT * FROM contracts WHERE unique_id = ? AND deleted_at IS NULL");
    $stmt->execute([$contract_uid]);
    $contract = $stmt->fetch();

    if (!$contract) {
        http_response_code(404);
        echo json_encode(['error' => 'Contract not found']);
        exit;
    }

    // Get pricing option
    $stmt = $pdo->prepare("SELECT * FROM pricing_options WHERE id = ? AND contract_id = ? AND deleted_at IS NULL");
    $stmt->execute([$pricing_option_id, $contract['id']]);
    $option = $stmt->fetch();

    if (!$option) {
        http_response_code(404);
        echo json_encode(['error' => 'Pricing option not found']);
        exit;
    }

    // Duplicate charge prevention: check for existing successful payment
    $stmt = $pdo->prepare("
        SELECT id, keap_order_id FROM payments
        WHERE contract_id = ? AND pricing_option_id = ? AND status = 'succeeded'
        LIMIT 1
    ");
    $stmt->execute([$contract['id'], $pricing_option_id]);
    $existingPayment = $stmt->fetch();

    if ($existingPayment) {
        // Already charged — return success (idempotent)
        echo json_encode([
            'success' => true,
            'order_id' => $existingPayment['keap_order_id'],
            'message' => 'Payment already processed',
        ]);
        exit;
    }

    $pdo->beginTransaction();

    // Row lock to prevent race conditions
    $stmt = $pdo->prepare("SELECT id FROM contracts WHERE id = ? FOR UPDATE");
    $stmt->execute([$contract['id']]);

    // Double-check after acquiring lock
    $stmt = $pdo->prepare("
        SELECT id FROM payments
        WHERE contract_id = ? AND pricing_option_id = ? AND status = 'succeeded'
        LIMIT 1
    ");
    $stmt->execute([$contract['id'], $pricing_option_id]);
    if ($stmt->fetch()) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Payment already processed',
        ]);
        exit;
    }

    // Build order title and items
    $orderTitle = 'PDP: ' . ($plan_description ?: $option['description']);
    if ($is_deposit) {
        $orderTitle .= ' ($100 Deposit)';
    }

    $orderItems = [
        [
            'description' => $is_deposit
                ? "Deposit for: {$option['description']} ({$option['sub_option_name']} - {$option['type']})"
                : "{$option['description']} ({$option['sub_option_name']} - {$option['type']})",
            'price' => $amount,
            'quantity' => 1,
        ]
    ];

    // Create order in Keap
    $orderResult = pdp_keap_create_order($contact_id, $orderTitle, $orderItems);

    if (!$orderResult['success']) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create order: ' . $orderResult['error']]);
        exit;
    }

    $orderId = $orderResult['order_id'];

    // Process payment via Keap
    $paymentNotes = $is_deposit
        ? "PDP $100 deposit. Selected plan: {$option['description']} ({$option['sub_option_name']} - {$option['type']}) at \${$option['price']}/{$option['type']}"
        : "PDP payment in full: {$option['description']} ({$option['sub_option_name']} - {$option['type']})";

    $chargeResult = pdp_keap_process_payment($orderId, $payment_method_id, $amount, $paymentNotes);

    if (!$chargeResult['success']) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Payment failed: ' . $chargeResult['error']]);
        exit;
    }

    // Record payment locally
    $paymentType = $is_deposit ? 'deposit' : 'one_time';
    pdp_record_payment($pdo, [
        'contract_id' => $contract['id'],
        'pricing_option_id' => $pricing_option_id,
        'keap_order_id' => $orderId,
        'amount' => $amount,
        'status' => 'succeeded',
        'payment_type' => $paymentType,
        'metadata' => [
            'keap_contact_id' => $contact_id,
            'is_deposit' => $is_deposit,
            'plan_description' => $plan_description,
            'full_plan_price' => $option['price'],
            'plan_type' => $option['type'],
        ],
    ]);

    // Mark contract as signed
    $stmt = $pdo->prepare("
        UPDATE contracts
        SET selected_option_id = ?, signed = 1, keap_contact_id = ?
        WHERE id = ?
    ");
    $stmt->execute([$pricing_option_id, $contact_id, $contract['id']]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'message' => 'Payment processed and contract signed',
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('keap-charge.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred processing your payment']);
}
