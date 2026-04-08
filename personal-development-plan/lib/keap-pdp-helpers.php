<?php
/**
 * Keap API Helper Functions for PDP
 *
 * Handles OAuth token retrieval, contact upsert, payment session, order creation,
 * and payment processing via Keap's REST API.
 *
 * Reuses patterns from scholarship/lib/keap-helpers.php.
 */

/**
 * Get a valid Keap OAuth access token from the inf_settings table.
 *
 * @return string The access token, or empty string on failure.
 */
function pdp_get_keap_token() {
    global $dbHost, $dbName, $dbUser, $dbPass;

    // Fall back to livewright DB vars if settings.php vars aren't available
    if (empty($dbHost)) {
        global $db_host_lw, $db_name_lw, $db_user_lw, $db_pass_lw;
        $dbHost = $db_host_lw;
        $dbName = $db_name_lw;
        $dbUser = $db_user_lw;
        $dbPass = $db_pass_lw;
    }

    try {
        $conn = new PDO(
            "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $conn->prepare("
            SELECT access_token FROM inf_settings, apps
            WHERE apps.app_url = inf_settings.app_url
            AND apps.url_short = :app_url
            ORDER BY updated_on DESC
            LIMIT 1
        ");
        $stmt->execute(['app_url' => KEAP_APP_URL_SHORT]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['access_token'] : '';

    } catch (PDOException $e) {
        error_log('PDP Keap token retrieval failed: ' . $e->getMessage());
        return '';
    }
}

/**
 * Make a Keap REST API request.
 *
 * @param string $method HTTP method (GET, POST, PATCH, PUT, DELETE)
 * @param string $endpoint API endpoint path (e.g., /crm/rest/v1/contacts)
 * @param array|null $data Request body data
 * @return array ['success' => bool, 'data' => array|null, 'http_code' => int, 'error' => string|null]
 */
function pdp_keap_request($method, $endpoint, $data = null) {
    $token = pdp_get_keap_token();
    if (!$token) {
        return ['success' => false, 'data' => null, 'http_code' => 0, 'error' => 'No Keap token available'];
    }

    $url = 'https://api.infusionsoft.com' . $endpoint;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json",
            "Accept: application/json"
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    if ($data !== null && in_array($method, ['POST', 'PATCH', 'PUT'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log("PDP Keap API curl error: $error");
        return ['success' => false, 'data' => null, 'http_code' => 0, 'error' => $error];
    }

    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode, 'error' => null];
    }

    error_log("PDP Keap API error ($httpCode): $response");
    $errorMsg = "HTTP $httpCode";
    if ($decoded) {
        if (!empty($decoded['message'])) {
            $errorMsg = $decoded['message'];
        } elseif (!empty($decoded['error'])) {
            $errorMsg = $decoded['error'];
        }
    }
    return ['success' => false, 'data' => $decoded, 'http_code' => $httpCode, 'error' => $errorMsg];
}

/**
 * Find or create a contact in Keap by email.
 *
 * @param string $firstName
 * @param string $lastName
 * @param string $email
 * @return array ['success' => bool, 'contact_id' => int|null, 'error' => string|null]
 */
function pdp_keap_find_or_create_contact($firstName, $lastName, $email) {
    // Search for existing contact
    $result = pdp_keap_request('GET', '/crm/rest/v1/contacts?email=' . urlencode($email) . '&limit=1');

    if ($result['success'] && !empty($result['data']['contacts'])) {
        $contactId = $result['data']['contacts'][0]['id'];

        // Update name if needed
        pdp_keap_request('PATCH', "/crm/rest/v1/contacts/$contactId", [
            'given_name' => $firstName,
            'family_name' => $lastName,
        ]);

        return ['success' => true, 'contact_id' => $contactId, 'error' => null];
    }

    // Create new contact
    $payload = [
        'given_name' => $firstName,
        'family_name' => $lastName,
        'email_addresses' => [
            ['email' => $email, 'field' => 'EMAIL1']
        ]
    ];

    $result = pdp_keap_request('POST', '/crm/rest/v1/contacts', $payload);

    if ($result['success'] && isset($result['data']['id'])) {
        return ['success' => true, 'contact_id' => $result['data']['id'], 'error' => null];
    }

    return ['success' => false, 'contact_id' => null, 'error' => $result['error'] ?? 'Failed to create contact'];
}

/**
 * Get a payment session key from Keap's v2 Payments API.
 *
 * @param int $contactId Keap contact ID
 * @return array ['success' => bool, 'session_key' => string|null, 'error' => string|null]
 */
function pdp_keap_get_payment_session($contactId) {
    $result = pdp_keap_request('POST', '/crm/rest/v2/paymentMethodConfigs', [
        'contact_id' => $contactId
    ]);

    if ($result['success'] && !empty($result['data']['session_key'])) {
        return ['success' => true, 'session_key' => $result['data']['session_key'], 'error' => null];
    }

    return ['success' => false, 'session_key' => null, 'error' => $result['error'] ?? 'Failed to get payment session'];
}

// Keap product IDs for PDP orders
define('KEAP_PDP_PRODUCT_ID', 99);           // Personal Development Plan
define('KEAP_PDP_DEPOSIT_PRODUCT_ID', 97);   // Personal Development Plan (Deposit)

/**
 * Create an order in Keap.
 *
 * @param int $contactId Keap contact ID
 * @param string $orderTitle Order title/description
 * @param array $items Array of line items: [['description' => string, 'price' => float, 'quantity' => int, 'is_deposit' => bool]]
 * @return array ['success' => bool, 'order_id' => int|null, 'error' => string|null]
 */
function pdp_keap_create_order($contactId, $orderTitle, $items) {
    $orderItems = [];
    foreach ($items as $item) {
        $isDeposit = !empty($item['is_deposit']);
        $orderItems[] = [
            'name' => $item['description'],
            'description' => $item['description'],
            'price' => (float)$item['price'],
            'quantity' => (int)($item['quantity'] ?? 1),
            'product_id' => $isDeposit ? KEAP_PDP_DEPOSIT_PRODUCT_ID : KEAP_PDP_PRODUCT_ID,
        ];
    }

    $payload = [
        'contact_id' => $contactId,
        'order_title' => $orderTitle,
        'order_date' => date('c'),
        'order_items' => $orderItems
    ];

    $result = pdp_keap_request('POST', '/crm/rest/v1/orders', $payload);

    if ($result['success'] && isset($result['data']['id'])) {
        return ['success' => true, 'order_id' => $result['data']['id'], 'error' => null];
    }

    return ['success' => false, 'order_id' => null, 'error' => $result['error'] ?? 'Failed to create order'];
}

/**
 * Process a payment on a Keap order using a payment method from the widget.
 *
 * @param int $orderId Keap order ID
 * @param string $paymentMethodId Payment method ID from the Keap widget (credit_card_id)
 * @param float $amount Amount to charge
 * @param string $notes Payment notes
 * @return array ['success' => bool, 'error' => string|null]
 */
function pdp_keap_process_payment($orderId, $paymentMethodId, $amount, $notes = '') {
    $payload = [
        'charge_now' => true,
        'credit_card_id' => is_numeric($paymentMethodId) ? (int)$paymentMethodId : $paymentMethodId,
        'notes' => $notes,
        'payment_amount' => (float)$amount,
        'payment_gateway_id' => 2,
        'payment_method_type' => 'CREDIT_CARD',
    ];

    $result = pdp_keap_request('POST', "/crm/rest/v1/orders/$orderId/payments", $payload);

    if ($result['success']) {
        return ['success' => true, 'error' => null];
    }

    return ['success' => false, 'error' => $result['error'] ?? 'Payment processing failed'];
}

/**
 * Record a payment in the local database.
 *
 * @param PDO $pdo Database connection
 * @param array $data Payment data
 * @return int Payment ID
 */
function pdp_record_payment($pdo, $data) {
    $stmt = $pdo->prepare("
        INSERT INTO payments (contract_id, pricing_option_id, keap_order_id, amount, currency, status, payment_type, metadata)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $data['contract_id'],
        $data['pricing_option_id'],
        $data['keap_order_id'] ?? null,
        $data['amount'],
        $data['currency'] ?? 'usd',
        $data['status'] ?? 'pending',
        $data['payment_type'],
        isset($data['metadata']) ? json_encode($data['metadata']) : null,
    ]);
    return $pdo->lastInsertId();
}
