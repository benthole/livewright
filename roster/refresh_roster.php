<?php
// refresh_roster.php - Async endpoint to refresh roster data from Keap

require_once('includes/auth.php');

header('Content-Type: application/json');

// Require authentication (any logged-in user can refresh)
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Set longer execution time for API sync (may be disabled on some hosts)
@set_time_limit(120);

// Run the search/sync process
require_once('keap_api.php');
require_once('config.php');

// Tag ID 525
$tagId = 525;

// Get contacts with the tag
$limit = 1000;
$offset = 0;

$results = keap_get_contacts_by_tag($tagId, $limit, $offset);

// Check for errors
if (isset($results['error'])) {
    echo json_encode([
        'success' => false,
        'error' => $results['error']
    ]);
    exit;
}

$contacts = isset($results['contacts']) ? $results['contacts'] : [];
$inserted = 0;
$updated = 0;
$errors = [];

// Connect to livewright database
try {
    $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit;
}

// Get existing emails
$existingEmails = [];
try {
    $stmt = $conn->query("SELECT email FROM roster");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingEmails[$row['email']] = true;
    }
} catch (PDOException $e) {
    // Table might not exist yet
}

// Clean up duplicates
$totalDuplicatesRemoved = 0;
try {
    $stmt = $conn->query("SELECT id, email, data, updated_at FROM roster ORDER BY updated_at DESC");
    $seenKeapIds = [];
    $duplicateRosterIds = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = json_decode($row['data'], true);
        if (isset($data['id'])) {
            $keapId = $data['id'];
            if (isset($seenKeapIds[$keapId])) {
                $duplicateRosterIds[] = $row['id'];
            } else {
                $seenKeapIds[$keapId] = true;
            }
        }
    }

    if (!empty($duplicateRosterIds)) {
        $placeholders = implode(',', array_fill(0, count($duplicateRosterIds), '?'));
        $deleteStmt = $conn->prepare("DELETE FROM roster WHERE id IN ($placeholders)");
        $deleteStmt->execute($duplicateRosterIds);
        $totalDuplicatesRemoved = count($duplicateRosterIds);
    }
} catch (PDOException $e) {
    // Ignore duplicate cleanup errors
}

// Process each contact
foreach ($contacts as $contact) {
    $email = '';
    if (isset($contact['email_addresses']) && is_array($contact['email_addresses']) && count($contact['email_addresses']) > 0) {
        $email = $contact['email_addresses'][0]['email'];
    }

    if (empty($email)) {
        continue;
    }

    $jsonData = json_encode($contact);

    try {
        if (isset($existingEmails[$email])) {
            $stmt = $conn->prepare("UPDATE roster SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE email = ?");
            $stmt->execute([$jsonData, $email]);
            $updated++;
        } else {
            $stmt = $conn->prepare("INSERT INTO roster (email, data) VALUES (?, ?)");
            $stmt->execute([$email, $jsonData]);
            $inserted++;
            $existingEmails[$email] = true;
        }
    } catch (PDOException $e) {
        $errors[] = "Error with email {$email}: " . $e->getMessage();
    }
}

// Save last sync time
$syncTime = date('Y-m-d H:i:s');
try {
    // Create meta table if it doesn't exist
    $conn->exec("CREATE TABLE IF NOT EXISTS roster_meta (
        meta_key VARCHAR(50) PRIMARY KEY,
        meta_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Update last sync time
    $stmt = $conn->prepare("INSERT INTO roster_meta (meta_key, meta_value) VALUES ('last_sync', ?)
        ON DUPLICATE KEY UPDATE meta_value = ?, updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$syncTime, $syncTime]);
} catch (PDOException $e) {
    // Non-critical, continue
}

echo json_encode([
    'success' => true,
    'total_contacts' => count($contacts),
    'inserted' => $inserted,
    'updated' => $updated,
    'duplicates_removed' => $totalDuplicatesRemoved,
    'sync_time' => $syncTime,
    'errors' => $errors
]);
