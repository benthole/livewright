<?php
/**
 * Keap API Helper Functions for Scholarship Application
 *
 * Handles OAuth token retrieval, contact upsert, and custom field management.
 * Reuses the token retrieval pattern from roster/keap_api.php.
 */

/**
 * Get a valid Keap OAuth access token from the inf_settings table.
 *
 * @return string The access token, or empty string on failure.
 */
function get_keap_token() {
    // Token lives in the inf_settings table which is in the Keap helper DB
    // (defined by $dbHost/$dbName/$dbUser/$dbPass in settings.php, NOT livewright DB)
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
        error_log('Keap token retrieval failed: ' . $e->getMessage());
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
function keap_request($method, $endpoint, $data = null) {
    $token = get_keap_token();
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
        error_log("Keap API curl error: $error");
        return ['success' => false, 'data' => null, 'http_code' => 0, 'error' => $error];
    }

    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode, 'error' => null];
    }

    error_log("Keap API error ($httpCode): $response");
    return ['success' => false, 'data' => $decoded, 'http_code' => $httpCode, 'error' => "HTTP $httpCode"];
}

/**
 * Search for a contact by email address.
 *
 * @param string $email
 * @return int|null Contact ID if found, null otherwise.
 */
function keap_find_contact_by_email($email) {
    $result = keap_request('GET', '/crm/rest/v1/contacts?email=' . urlencode($email) . '&limit=1');

    if ($result['success'] && !empty($result['data']['contacts'])) {
        return $result['data']['contacts'][0]['id'];
    }

    return null;
}

/**
 * Create or update a contact in Keap.
 *
 * @param array $contactData Associative array with contact fields:
 *   - first_name, last_name, email
 *   - street_address, city, state_zip, country
 *   - cell_phone, work_phone, other_phone
 * @return array ['success' => bool, 'contact_id' => int|null, 'error' => string|null]
 */
function keap_upsert_contact($contactData) {
    $email = $contactData['email'];
    $existingId = keap_find_contact_by_email($email);

    // Build Keap contact payload
    $payload = [
        'given_name' => $contactData['first_name'],
        'family_name' => $contactData['last_name'],
        'email_addresses' => [
            ['email' => $email, 'field' => 'EMAIL1']
        ]
    ];

    // Phone numbers
    $phones = [];
    if (!empty($contactData['cell_phone'])) {
        $phones[] = ['number' => $contactData['cell_phone'], 'field' => 'PHONE1'];
    }
    if (!empty($contactData['work_phone'])) {
        $phones[] = ['number' => $contactData['work_phone'], 'field' => 'PHONE2'];
    }
    if (!empty($contactData['other_phone'])) {
        $phones[] = ['number' => $contactData['other_phone'], 'field' => 'PHONE3'];
    }
    if (!empty($phones)) {
        $payload['phone_numbers'] = $phones;
    }

    // Address
    if (!empty($contactData['street_address']) || !empty($contactData['city'])) {
        $address = ['field' => 'BILLING'];
        if (!empty($contactData['street_address'])) $address['line1'] = $contactData['street_address'];
        if (!empty($contactData['city'])) $address['locality'] = $contactData['city'];
        if (!empty($contactData['state_zip'])) {
            // Try to split state and zip
            $parts = preg_split('/\s+/', trim($contactData['state_zip']), 2);
            if (count($parts) === 2 && is_numeric(str_replace('-', '', $parts[1]))) {
                $address['region'] = $parts[0];
                $address['postal_code'] = $parts[1];
            } else {
                $address['region'] = $contactData['state_zip'];
            }
        }
        if (!empty($contactData['country'])) $address['country_code'] = $contactData['country'];
        $payload['addresses'] = [$address];
    }

    if ($existingId) {
        // Update existing contact
        $result = keap_request('PATCH', "/crm/rest/v1/contacts/$existingId", $payload);
        if ($result['success']) {
            return ['success' => true, 'contact_id' => $existingId, 'error' => null];
        }
        return ['success' => false, 'contact_id' => null, 'error' => $result['error']];
    } else {
        // Create new contact
        $result = keap_request('POST', '/crm/rest/v1/contacts', $payload);
        if ($result['success'] && isset($result['data']['id'])) {
            return ['success' => true, 'contact_id' => $result['data']['id'], 'error' => null];
        }
        return ['success' => false, 'contact_id' => null, 'error' => $result['error']];
    }
}

/**
 * Set a custom field value on a Keap contact.
 *
 * @param int $contactId
 * @param int $fieldId Custom field ID
 * @param string $value
 * @return bool
 */
function keap_set_custom_field($contactId, $fieldId, $value) {
    $payload = [
        'custom_fields' => [
            [
                'id' => $fieldId,
                'content' => $value
            ]
        ]
    ];

    $result = keap_request('PATCH', "/crm/rest/v1/contacts/$contactId", $payload);
    return $result['success'];
}

/**
 * Set the scholarship application link on a Keap contact.
 *
 * @param int $contactId
 * @param string $uniqueId Application UUID
 * @return bool
 */
function keap_set_scholarship_link($contactId, $uniqueId) {
    if (KEAP_SCHOLARSHIP_FIELD_ID <= 0) {
        error_log('Keap scholarship custom field ID not configured');
        return false;
    }

    $link = SCHOLARSHIP_ADMIN_BASE_URL . '?id=' . urlencode($uniqueId);
    return keap_set_custom_field($contactId, KEAP_SCHOLARSHIP_FIELD_ID, $link);
}

/**
 * Create a custom field in Keap (run once during setup).
 *
 * @param string $label Field label
 * @return int|null The field ID if created, null on failure.
 */
function keap_create_custom_field($label) {
    $payload = [
        'field_type' => 'Text',
        'group_id' => 0,
        'label' => $label
    ];

    $result = keap_request('POST', '/crm/rest/v1/contactCustomFields', $payload);

    if ($result['success'] && isset($result['data']['id'])) {
        return $result['data']['id'];
    }

    error_log('Failed to create Keap custom field: ' . ($result['error'] ?? 'unknown'));
    return null;
}
