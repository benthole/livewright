<?php
/**
 * Stripe Helper Functions for PDP
 *
 * Wraps Stripe API calls for customer management, payment intents, and subscriptions.
 */

/**
 * Initialize Stripe with the secret key.
 */
function initStripe() {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
}

/**
 * Get or create a Stripe customer for a contract.
 *
 * @param PDO $pdo Database connection
 * @param array $contract Contract row from DB
 * @return \Stripe\Customer
 */
function getOrCreateCustomer($pdo, $contract) {
    initStripe();

    // If customer already exists, retrieve it
    if (!empty($contract['stripe_customer_id'])) {
        try {
            return \Stripe\Customer::retrieve($contract['stripe_customer_id']);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Customer was deleted in Stripe, create a new one
        }
    }

    // Create new customer
    $customer = \Stripe\Customer::create([
        'email' => $contract['email'],
        'name' => $contract['first_name'] . ' ' . $contract['last_name'],
        'metadata' => [
            'contract_id' => $contract['id'],
            'contract_uid' => $contract['unique_id'],
        ],
    ]);

    // Save customer ID to contract
    $stmt = $pdo->prepare("UPDATE contracts SET stripe_customer_id = ? WHERE id = ?");
    $stmt->execute([$customer->id, $contract['id']]);

    return $customer;
}

/**
 * Create a PaymentIntent for a deposit or one-time payment.
 *
 * @param string $customerId Stripe customer ID
 * @param float $amount Amount in dollars
 * @param array $metadata Additional metadata
 * @return \Stripe\PaymentIntent
 */
function createDepositIntent($customerId, $amount, $metadata = []) {
    initStripe();

    return \Stripe\PaymentIntent::create([
        'amount' => (int)round($amount * 100), // Convert to cents
        'currency' => 'usd',
        'customer' => $customerId,
        'metadata' => $metadata,
        'automatic_payment_methods' => ['enabled' => true],
    ]);
}

/**
 * Create a Stripe Product + Price + Subscription for recurring billing.
 *
 * @param string $customerId Stripe customer ID
 * @param string $paymentMethodId Payment method from Stripe Elements
 * @param float $amount Monthly amount in dollars
 * @param array $metadata Additional metadata
 * @return \Stripe\Subscription
 */
function createSubscription($customerId, $paymentMethodId, $amount, $metadata = []) {
    initStripe();

    // Attach payment method to customer
    \Stripe\PaymentMethod::retrieve($paymentMethodId)->attach([
        'customer' => $customerId,
    ]);

    // Set as default payment method
    \Stripe\Customer::update($customerId, [
        'invoice_settings' => ['default_payment_method' => $paymentMethodId],
    ]);

    // Create product
    $contractId = $metadata['contract_id'] ?? 'unknown';
    $product = \Stripe\Product::create([
        'name' => 'LiveWright PDP - Contract #' . $contractId,
        'metadata' => $metadata,
    ]);

    // Create price (monthly recurring)
    $price = \Stripe\Price::create([
        'product' => $product->id,
        'unit_amount' => (int)round($amount * 100),
        'currency' => 'usd',
        'recurring' => ['interval' => 'month'],
    ]);

    // Create subscription
    return \Stripe\Subscription::create([
        'customer' => $customerId,
        'items' => [['price' => $price->id]],
        'metadata' => $metadata,
        'payment_behavior' => 'default_incomplete',
        'expand' => ['latest_invoice.payment_intent'],
    ]);
}

/**
 * Record a payment in the database.
 *
 * @param PDO $pdo Database connection
 * @param array $data Payment data
 * @return int Payment ID
 */
function recordPayment($pdo, $data) {
    $stmt = $pdo->prepare("
        INSERT INTO payments (contract_id, pricing_option_id, stripe_customer_id, stripe_payment_intent_id, stripe_subscription_id, amount, currency, status, payment_type, metadata)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $data['contract_id'],
        $data['pricing_option_id'],
        $data['stripe_customer_id'] ?? null,
        $data['stripe_payment_intent_id'] ?? null,
        $data['stripe_subscription_id'] ?? null,
        $data['amount'],
        $data['currency'] ?? 'usd',
        $data['status'] ?? 'pending',
        $data['payment_type'],
        isset($data['metadata']) ? json_encode($data['metadata']) : null,
    ]);
    return $pdo->lastInsertId();
}
