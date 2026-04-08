<?php
/**
 * Temporary debug script to find the Keap payment gateway ID.
 * DELETE AFTER USE.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/keap-pdp-helpers.php';

$endpoints = [
    '/crm/rest/v1/merchants',
    '/crm/rest/v2/merchants',
    '/crm/rest/v1/setting/application/configuration',
    '/crm/rest/v2/settings/applications/configuration',
];

$results = [];
foreach ($endpoints as $ep) {
    $results[$ep] = pdp_keap_request('GET', $ep);
}

echo json_encode($results, JSON_PRETTY_PRINT);
