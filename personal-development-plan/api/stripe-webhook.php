<?php
/**
 * Stripe Webhook Handler
 *
 * Handles asynchronous payment events from Stripe:
 *   - payment_intent.succeeded: Record successful payments
 *   - invoice.paid: Track subscription renewals
 *   - customer.subscription.deleted: Handle cancellations
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/stripe-helpers.php';

// Read the raw body for signature verification
$payload = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (empty(STRIPE_WEBHOOK_SECRET)) {
    http_response_code(500);
    exit('Webhook secret not configured');
}

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sig_header,
        STRIPE_WEBHOOK_SECRET
    );
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit('Invalid payload');
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit('Invalid signature');
}

// Handle the event
switch ($event->type) {
    case 'payment_intent.succeeded':
        handlePaymentIntentSucceeded($event->data->object, $pdo);
        break;

    case 'invoice.paid':
        handleInvoicePaid($event->data->object, $pdo);
        break;

    case 'customer.subscription.deleted':
        handleSubscriptionDeleted($event->data->object, $pdo);
        break;

    default:
        // Unhandled event type - just acknowledge
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);

// --- Event Handlers ---

function handlePaymentIntentSucceeded($paymentIntent, $pdo) {
    $pi_id = $paymentIntent->id;

    // Check if we already recorded this payment (from confirm-payment.php)
    $stmt = $pdo->prepare("SELECT id FROM payments WHERE stripe_payment_intent_id = ?");
    $stmt->execute([$pi_id]);
    if ($stmt->fetch()) {
        return; // Already recorded
    }

    // Extract metadata
    $metadata = $paymentIntent->metadata;
    $contract_id = $metadata->contract_id ?? null;
    $pricing_option_id = $metadata->pricing_option_id ?? null;

    if (!$contract_id || !$pricing_option_id) {
        return; // Not our payment
    }

    recordPayment($pdo, [
        'contract_id' => (int)$contract_id,
        'pricing_option_id' => (int)$pricing_option_id,
        'stripe_customer_id' => $paymentIntent->customer,
        'stripe_payment_intent_id' => $pi_id,
        'amount' => $paymentIntent->amount / 100,
        'status' => 'succeeded',
        'payment_type' => $metadata->payment_type ?? 'one_time',
        'metadata' => (array)$metadata,
    ]);
}

function handleInvoicePaid($invoice, $pdo) {
    $subscription_id = $invoice->subscription;
    if (!$subscription_id) return;

    // Check if this is a renewal (not the first invoice)
    if ($invoice->billing_reason === 'subscription_create') {
        return; // First invoice handled by confirm-payment
    }

    // Find the original payment to get contract info
    $stmt = $pdo->prepare("SELECT contract_id, pricing_option_id FROM payments WHERE stripe_subscription_id = ? LIMIT 1");
    $stmt->execute([$subscription_id]);
    $original = $stmt->fetch();

    if (!$original) return;

    recordPayment($pdo, [
        'contract_id' => $original['contract_id'],
        'pricing_option_id' => $original['pricing_option_id'],
        'stripe_customer_id' => $invoice->customer,
        'stripe_payment_intent_id' => $invoice->payment_intent,
        'stripe_subscription_id' => $subscription_id,
        'amount' => $invoice->amount_paid / 100,
        'status' => 'succeeded',
        'payment_type' => 'subscription_recurring',
        'metadata' => [
            'invoice_id' => $invoice->id,
            'billing_reason' => $invoice->billing_reason,
        ],
    ]);
}

function handleSubscriptionDeleted($subscription, $pdo) {
    // Update all payments for this subscription to note the cancellation
    $stmt = $pdo->prepare("
        UPDATE payments
        SET metadata = JSON_SET(COALESCE(metadata, '{}'), '$.subscription_canceled', true, '$.canceled_at', ?)
        WHERE stripe_subscription_id = ?
    ");
    $stmt->execute([date('Y-m-d H:i:s'), $subscription->id]);
}
