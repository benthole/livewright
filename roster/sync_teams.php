<?php
// sync_teams.php - Fetch unique team values from Keap custom field 45
// Returns all unique values found in the roster data

require_once('includes/auth.php');

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

require_once('config.php');
require_once('keap_api.php');

try {
    $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$teams = [];

// 1. Fetch the actual dropdown options defined on the Keap custom field.
//    This is the source of truth and includes options no contact uses yet.
$optionError = null;
$fieldOptions = keap_get_custom_field_options($cohort_field_id);
if (isset($fieldOptions['error'])) {
    // Don't hard-fail: fall back to values found in local data below.
    $optionError = $fieldOptions['error'];
} else {
    foreach ($fieldOptions as $label) {
        $teams[$label] = true;
    }
    // Refresh the page-load cache (same key as keap_get_custom_field_options_cached)
    // so the index.php dropdown reflects this sync immediately on next load.
    if (is_file(__DIR__ . '/lib/keap_cache.php')) {
        require_once(__DIR__ . '/lib/keap_cache.php');
        if (function_exists('kcache_put')) {
            kcache_put("cf_options_{$cohort_field_id}", $fieldOptions, 900);
        }
    }
}

// 2. Also include any team values present in local roster data (custom field 45),
//    in case a contact carries a value not (or no longer) in the option list.
$stmt = $conn->query("SELECT data FROM roster");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $data = json_decode($row['data'], true);
    if (!empty($data['custom_fields'])) {
        foreach ($data['custom_fields'] as $field) {
            if ($field['id'] == $cohort_field_id && !empty($field['content'])) {
                $teams[$field['content']] = true;
            }
        }
    }
}

$teamNames = array_keys($teams);
sort($teamNames);

// Compare with current config
$allConfigured = array_merge(
    $cohorts['active'] ?? [],
    $cohorts['functional'] ?? [],
    $cohorts['inactive'] ?? []
);

$newTeams = array_diff($teamNames, $allConfigured);
$removedTeams = array_diff($allConfigured, $teamNames);

echo json_encode([
    'success' => true,
    'keap_teams' => $teamNames,
    'configured_teams' => $allConfigured,
    'new_teams' => array_values($newTeams),
    'removed_teams' => array_values($removedTeams),
    'option_fetch_error' => $optionError, // null unless the Keap field lookup failed
]);
