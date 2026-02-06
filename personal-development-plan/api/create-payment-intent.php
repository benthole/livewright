<?php
/**
 * Create Payment Intent API
 *
 * Creates a Stripe PaymentIntent for deposit or one-time payment,
 * or sets up a subscription for recurring billing.
 *
 * POST JSON:
 *   - contract_uid: string
 *   - pricing_option_id: int
 *   - email: string
 *   - first_name: string
 *   - last_name: string
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/stripe-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$contract_uid = $input['contract_uid'] ?? '';
$pricing_option_id = (int)($input['pricing_option_id'] ?? 0);
$email = trim($input['email'] ?? '');
$first_name = trim($input['first_name'] ?? '');
$last_name = trim($input['last_name'] ?? '');

if (empty($contract_uid) || empty($pricing_option_id) || empty($email)) {
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

    // Update contract with client info if provided
    if (!empty($first_name) && !empty($last_name)) {
        $contract['first_name'] = $first_name;
        $contract['last_name'] = $last_name;
        $contract['email'] = $email;
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

    // Get or create Stripe customer
    $customer = getOrCreateCustomer($pdo, $contract);

    $payment_mode = $option['payment_mode'] ?? 'recurring_immediate';
    $deposit_amount = (float)($option['deposit_amount'] ?? 0);
    $price = (float)$option['price'];
    $type = $option['type']; // Monthly, Quarterly, Yearly

    $response = [
        'customer_id' => $customer->id,
        'payment_mode' => $payment_mode,
    ];

    if ($payment_mode === 'deposit_only') {
        // One-time deposit payment
        $amount = $deposit_amount > 0 ? $deposit_amount : $price;
        $intent = createDepositIntent($customer->id, $amount, [
            'contract_id' => $contract['id'],
            'pricing_option_id' => $pricing_option_id,
            'payment_type' => 'deposit',
        ]);

        $response['client_secret'] = $intent->client_secret;
        $response['payment_intent_id'] = $intent->id;
        $response['amount'] = $amount;
        $response['type'] = 'one_time';

    } elseif ($payment_mode === 'deposit_and_recurring') {
        // Deposit first, subscription created after payment confirmation
        $amount = $deposit_amount > 0 ? $deposit_amount : $price;
        $intent = createDepositIntent($customer->id, $amount, [
            'contract_id' => $contract['id'],
            'pricing_option_id' => $pricing_option_id,
            'payment_type' => 'deposit',
            'setup_subscription' => 'true',
            'recurring_amount' => (string)$price,
        ]);

        $response['client_secret'] = $intent->client_secret;
        $response['payment_intent_id'] = $intent->id;
        $response['amount'] = $amount;
        $response['recurring_amount'] = $price;
        $response['type'] = 'deposit_then_recurring';

    } else {
        // recurring_immediate - first subscription payment charged now
        // Create a SetupIntent to collect payment method, then subscription
        $intent = createDepositIntent($customer->id, $price, [
            'contract_id' => $contract['id'],
            'pricing_option_id' => $pricing_option_id,
            'payment_type' => 'subscription_first',
            'setup_subscription' => 'true',
            'recurring_amount' => (string)$price,
        ]);

        $response['client_secret'] = $intent->client_secret;
        $response['payment_intent_id'] = $intent->id;
        $response['amount'] = $price;
        $response['type'] = 'recurring';
    }

    echo json_encode($response);

} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred processing your payment']);
}
