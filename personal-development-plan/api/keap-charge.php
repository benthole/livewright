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
$plan_description = trim($input['plan_description'] ?? '');

// A coaching package charge is a one-time payment NOT tied to a pricing_options
// row. It's identified by a package_index into the contract's support_packages
// JSON; the amount is re-derived server-side (never trusted from the request).
$package_index = isset($input['package_index']) ? (int)$input['package_index'] : null;
$is_package = ($package_index !== null && $package_index >= 0);
// For a package/term the client may choose to pay in full or in installments.
// Only the binary choice is trusted from the request; the installment COUNT is
// always read from the stored package below (never from the request).
$installment_choice = ($input['installment_choice'] ?? 'full') === 'installments' ? 'installments' : 'full';
$is_deposit = $is_package ? false : (bool)($input['is_deposit'] ?? false);
// For non-package charges the amount still comes from the request (existing behaviour);
// for package charges it is overwritten from the DB below.
$amount = $is_package ? 0.0 : (float)($input['amount'] ?? 0);

if ($is_package) {
    if (empty($contract_uid) || empty($contact_id) || empty($payment_method_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }
} elseif (empty($contract_uid) || empty($pricing_option_id) || empty($contact_id) || empty($payment_method_id) || $amount <= 0) {
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

    $option = null;
    $package = null;

    if ($is_package) {
        // Resolve the chosen coaching package from the contract's support_packages
        // JSON and derive the amount server-side (never trust a request amount).
        $packages = !empty($contract['support_packages'])
            ? (json_decode($contract['support_packages'], true) ?: [])
            : [];
        if (!isset($packages[$package_index])) {
            http_response_code(404);
            echo json_encode(['error' => 'Coaching package not found']);
            exit;
        }
        $package = $packages[$package_index];
        // Net price is what the client pays (package price less any manual discount).
        $package_net = (float)($package['net_price'] ?? $package['package_price'] ?? $package['price_monthly'] ?? 0);
        if ($package_net <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Selected coaching package has no price']);
            exit;
        }
        // Installments: charge only the first installment now; the rest are set up
        // in Keap. The split is derived server-side (never trusted from the request).
        // Pay-in-full forces a single installment; "installments" uses the count
        // stored on the package (a client can never inflate the number of payments).
        $package_installments = (int)($package['installments'] ?? 1);
        $count = ($installment_choice === 'installments') ? $package_installments : 1;
        $package_plan = pdp_installment_plan($package_net, $count);
        $amount = $package_plan['first'];
    } else {
        // Get pricing option
        $stmt = $pdo->prepare("SELECT * FROM pricing_options WHERE id = ? AND contract_id = ? AND deleted_at IS NULL");
        $stmt->execute([$pricing_option_id, $contract['id']]);
        $option = $stmt->fetch();

        if (!$option) {
            http_response_code(404);
            echo json_encode(['error' => 'Pricing option not found']);
            exit;
        }
    }

    // Duplicate charge prevention. Package charges dedupe on the coaching_package
    // payment type (one package purchase per contract); option charges on option id.
    $dupeSql = $is_package
        ? "SELECT id, keap_order_id FROM payments WHERE contract_id = ? AND payment_type = 'coaching_package' AND status = 'succeeded' LIMIT 1"
        : "SELECT id, keap_order_id FROM payments WHERE contract_id = ? AND pricing_option_id = ? AND status = 'succeeded' LIMIT 1";
    $dupeParams = $is_package ? [$contract['id']] : [$contract['id'], $pricing_option_id];

    $stmt = $pdo->prepare($dupeSql);
    $stmt->execute($dupeParams);
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
    $stmt = $pdo->prepare($dupeSql);
    $stmt->execute($dupeParams);
    if ($stmt->fetch()) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Payment already processed',
        ]);
        exit;
    }

    // Build order title and items
    if ($is_package) {
        $packageName = $package['name'] ?? ($plan_description ?: 'Coaching Package');
        $packageDesc = $package['description'] ?? $packageName;
        $installmentSuffix = ($package_plan['count'] > 1)
            ? " — Payment 1 of {$package_plan['count']}"
            : '';
        $orderTitle = 'Coaching Package: ' . $packageName . $installmentSuffix;
        $orderItems = [
            [
                'description' => $packageDesc . $installmentSuffix,
                'price' => $amount,
                'quantity' => 1,
                'product_id' => KEAP_COACHING_PACKAGE_PRODUCT_ID,
            ]
        ];
    } else {
        $orderTitle = 'PDP: ' . ($plan_description ?: $option['description']);
        if ($is_deposit) {
            $orderTitle .= ' (Initial Payment $' . number_format($amount, 2) . ')';
        }

        $orderItems = [
            [
                'description' => $is_deposit
                    ? "Initial payment for: {$option['description']} ({$option['sub_option_name']} - {$option['type']})"
                    : "{$option['description']} ({$option['sub_option_name']} - {$option['type']})",
                'price' => $amount,
                'quantity' => 1,
                'is_deposit' => $is_deposit,
            ]
        ];
    }

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
    if ($is_package) {
        $paymentNotes = ($package_plan['count'] > 1)
            ? "Coaching package: {$packageName}. Payment 1 of {$package_plan['count']} = \${$amount}. Remaining " . ($package_plan['count'] - 1) . " payments (total \${$package_plan['remaining']}) to be set up in Keap. Package total \${$package_net}."
            : "Coaching package (one-time): {$packageName} — \${$amount}";
    } else {
        $paymentNotes = $is_deposit
            ? "PDP initial payment of \${$amount}. Plan: {$option['description']} ({$option['sub_option_name']} - {$option['type']}) at \${$option['price']}/{$option['type']}. Remaining recurring charges handled separately."
            : "PDP payment in full: {$option['description']} ({$option['sub_option_name']} - {$option['type']})";
    }

    $chargeResult = pdp_keap_process_payment($orderId, $payment_method_id, $amount, $paymentNotes);

    if (!$chargeResult['success']) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Payment failed: ' . $chargeResult['error']]);
        exit;
    }

    // Record payment locally
    if ($is_package) {
        $payment_id = pdp_record_payment($pdo, [
            'contract_id' => $contract['id'],
            'pricing_option_id' => null,
            'keap_order_id' => $orderId,
            'amount' => $amount,
            'status' => 'succeeded',
            'payment_type' => 'coaching_package',
            'metadata' => [
                'keap_contact_id' => $contact_id,
                'package_index' => $package_index,
                'package_name' => $packageName,
                'package_price' => $package['package_price'] ?? null,
                'discount_percent' => $package['discount_percent'] ?? null,
                'net_price' => $package_net,
                'installments' => $package_plan['count'],
                'installment_number' => 1,
                'installment_amount' => $amount,
                'installments_remaining_total' => $package_plan['remaining'],
            ],
        ]);

        // Mark contract signed. selected_option_id stays NULL — a coaching package
        // is not one of the recurring pricing options.
        $stmt = $pdo->prepare("UPDATE contracts SET signed = 1, keap_contact_id = ? WHERE id = ?");
        $stmt->execute([$contact_id, $contract['id']]);

        $pdo->commit();
        // Note: agreement/invoice PDF finalizers are plan-specific and are
        // intentionally skipped for one-time coaching packages (follow-up).
    } else {
        $paymentType = $is_deposit ? 'deposit' : 'one_time';
        $payment_id = pdp_record_payment($pdo, [
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

        // Generate signed-agreement PDF + invoice PDF and send confirmation emails.
        // Failures are logged but do not block the success response.
        try {
            require_once __DIR__ . '/../lib/agreement-finalize.php';
            pdp_finalize_agreement($pdo, (int)$contract['id']);
        } catch (Throwable $e) {
            error_log('keap-charge.php agreement finalize error: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/../lib/invoice-finalize.php';
            pdp_finalize_invoice($pdo, (int)$payment_id);
        } catch (Throwable $e) {
            error_log('keap-charge.php invoice finalize error: ' . $e->getMessage());
        }
    }

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
