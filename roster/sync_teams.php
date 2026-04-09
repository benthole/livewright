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

try {
    $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Extract unique team values from roster data (custom field 45)
$stmt = $conn->query("SELECT data FROM roster");
$teams = [];

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
]);
