<?php
/**
 * Confirm Payment API
 *
 * Called after successful Stripe payment to:
 * 1. Record payment in database
 * 2. Create subscription if needed (deposit_and_recurring or recurring_immediate)
 * 3. Mark contract as signed
 *
 * POST JSON:
 *   - contract_uid: string
 *   - pricing_option_id: int
 *   - payment_intent_id: string
 *   - payment_method_id: string (for subscription creation)
 *   - first_name, last_name, email: string
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
$payment_intent_id = $input['payment_intent_id'] ?? '';
$payment_method_id = $input['payment_method_id'] ?? '';
$first_name = trim($input['first_name'] ?? '');
$last_name = trim($input['last_name'] ?? '');
$email = trim($input['email'] ?? '');

if (empty($contract_uid) || empty($pricing_option_id) || empty($payment_intent_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

try {
    initStripe();

    // Verify payment intent succeeded
    $intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
    if ($intent->status !== 'succeeded') {
        http_response_code(400);
        echo json_encode(['error' => 'Payment has not been completed', 'status' => $intent->status]);
        exit;
    }

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

    $pdo->beginTransaction();

    // Record the payment
    $payment_mode = $option['payment_mode'] ?? 'recurring_immediate';
    $deposit_amount = (float)($option['deposit_amount'] ?? 0);
    $payment_type = ($payment_mode === 'recurring_immediate') ? 'subscription_first' : 'deposit';

    $payment_id = recordPayment($pdo, [
        'contract_id' => $contract['id'],
        'pricing_option_id' => $pricing_option_id,
        'stripe_customer_id' => $intent->customer,
        'stripe_payment_intent_id' => $payment_intent_id,
        'amount' => $intent->amount / 100, // Convert from cents
        'status' => 'succeeded',
        'payment_type' => $payment_type,
        'metadata' => [
            'payment_mode' => $payment_mode,
        ],
    ]);

    // Create subscription if this is deposit+recurring or recurring_immediate
    $subscription_id = null;
    if (in_array($payment_mode, ['deposit_and_recurring', 'recurring_immediate']) && !empty($payment_method_id)) {
        $subscription = createSubscription(
            $intent->customer,
            $payment_method_id,
            (float)$option['price'],
            [
                'contract_id' => $contract['id'],
                'pricing_option_id' => $pricing_option_id,
            ]
        );
        $subscription_id = $subscription->id;

        // Update payment record with subscription ID
        $stmt = $pdo->prepare("UPDATE payments SET stripe_subscription_id = ? WHERE id = ?");
        $stmt->execute([$subscription_id, $payment_id]);
    }

    // Mark contract as signed and update client info
    $stmt = $pdo->prepare("
        UPDATE contracts
        SET selected_option_id = ?, signed = 1, first_name = ?, last_name = ?, email = ?, stripe_customer_id = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $pricing_option_id,
        $first_name ?: $contract['first_name'],
        $last_name ?: $contract['last_name'],
        $email ?: $contract['email'],
        $intent->customer,
        $contract['id'],
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'payment_id' => $payment_id,
        'subscription_id' => $subscription_id,
        'message' => 'Payment confirmed and contract signed',
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Stripe error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred confirming your payment']);
}
