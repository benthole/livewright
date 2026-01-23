<?php
// search.php - Retrieve contacts from Keap by tag ID 525 and store in database

require_once('keap_api.php');
require_once('config.php');

// Tag ID 525
$tagId = 525;

// Get contacts with the tag
$limit = 1000;  // Max results per page (Keap API max is 1000)
$offset = 0;

$results = keap_get_contacts_by_tag($tagId, $limit, $offset);

// Check for errors
if (isset($results['error'])) {
    echo json_encode([
        'success' => false,
        'error' => $results['error'],
        'details' => $results
    ]);
    exit;
}

$contacts = isset($results['contacts']) ? $results['contacts'] : [];
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

// Get existing emails to track inserts vs updates
$existingEmails = [];
try {
    $stmt = $conn->query("SELECT email FROM roster");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingEmails[$row['email']] = true;
    }
} catch (PDOException $e) {
    // Table might not exist yet, that's okay
}

// Clean up any existing duplicates (same Keap ID, different emails)
// Keep only the most recently updated record for each Keap ID
$totalDuplicatesRemoved = 0;
try {
    // Find all Keap IDs that have duplicates
    $stmt = $conn->query("SELECT id, email, data, updated_at FROM roster ORDER BY updated_at DESC");
    $seenKeapIds = [];
    $duplicateRosterIds = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = json_decode($row['data'], true);
        $keapId = isset($data['id']) ? (int)$data['id'] : null;

        if ($keapId !== null) {
            if (isset($seenKeapIds[$keapId])) {
                // This is a duplicate - mark for deletion (older record)
                $duplicateRosterIds[] = $row['id'];
            } else {
                $seenKeapIds[$keapId] = $row['id'];
            }
        }
    }

    // Delete duplicates
    if (count($duplicateRosterIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($duplicateRosterIds), '?'));
        $deleteStmt = $conn->prepare("DELETE FROM roster WHERE id IN ($placeholders)");
        $deleteStmt->execute($duplicateRosterIds);
        $totalDuplicatesRemoved = count($duplicateRosterIds);
    }
} catch (PDOException $e) {
    $errors[] = "Duplicate cleanup error: " . $e->getMessage();
}

// Track Keap contact IDs from this sync to identify orphans
$syncedKeapIds = [];

// Build bulk insert query with all contacts
$values = [];
$params = [];
$totalInserted = 0;
$totalUpdated = 0;
$paramIndex = 0;

// Build a map of existing Keap IDs to emails for detecting email changes
$existingKeapIdToEmail = [];
try {
    $stmt = $conn->query("SELECT email, data FROM roster");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = json_decode($row['data'], true);
        if (isset($data['id'])) {
            $existingKeapIdToEmail[(int)$data['id']] = $row['email'];
        }
    }
} catch (PDOException $e) {
    // Continue anyway
}

foreach ($contacts as $contact) {
    // Get email address - check multiple possible fields
    $email = null;
    if (isset($contact['email_addresses']) && is_array($contact['email_addresses']) && count($contact['email_addresses']) > 0) {
        $email = $contact['email_addresses'][0]['email'];
    } elseif (isset($contact['email'])) {
        $email = $contact['email'];
    }

    // Track Keap ID for orphan detection
    $keapId = isset($contact['id']) ? (int)$contact['id'] : null;
    if ($keapId !== null) {
        $syncedKeapIds[] = $keapId;

        // If this Keap ID exists with a different email, delete the old record first
        if (isset($existingKeapIdToEmail[$keapId]) && $existingKeapIdToEmail[$keapId] !== $email) {
            try {
                $deleteStmt = $conn->prepare("DELETE FROM roster WHERE email = ?");
                $deleteStmt->execute([$existingKeapIdToEmail[$keapId]]);
                $totalDuplicatesRemoved++;
            } catch (PDOException $e) {
                $errors[] = "Error removing old email for Keap ID {$keapId}: " . $e->getMessage();
            }
        }
    }

    // Skip if no email found
    if (!$email) {
        $errors[] = "Contact ID " . ($contact['id'] ?? 'unknown') . " has no email address";
        continue;
    }

    // Track if this is an insert or update
    if (isset($existingEmails[$email])) {
        $totalUpdated++;
    } else {
        $totalInserted++;
    }

    // Convert contact data to JSON
    $jsonData = json_encode($contact);

    $values[] = "(:email{$paramIndex}, :data{$paramIndex})";
    $params["email{$paramIndex}"] = $email;
    $params["data{$paramIndex}"] = $jsonData;
    $paramIndex++;
}

// Execute bulk insert if we have contacts
if (count($values) > 0) {
    try {
        $sql = "INSERT INTO roster (email, data) VALUES " . implode(', ', $values) . "
                ON DUPLICATE KEY UPDATE
                    data = VALUES(data),
                    updated_at = CURRENT_TIMESTAMP";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

    } catch (PDOException $e) {
        $errors[] = "Database error: " . $e->getMessage();
    }
}

// Remove orphans - contacts in roster that are no longer in Keap
$totalDeleted = 0;
if (count($syncedKeapIds) > 0) {
    try {
        // Get all roster entries and check if their Keap ID is in the synced list
        $stmt = $conn->query("SELECT id, email, data FROM roster");
        $orphanIds = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data = json_decode($row['data'], true);
            $keapId = isset($data['id']) ? (int)$data['id'] : null;

            // If no Keap ID or Keap ID not in synced list, mark for deletion
            if ($keapId === null || !in_array($keapId, $syncedKeapIds)) {
                $orphanIds[] = $row['id'];
            }
        }

        // Delete orphans
        if (count($orphanIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($orphanIds), '?'));
            $deleteStmt = $conn->prepare("DELETE FROM roster WHERE id IN ($placeholders)");
            $deleteStmt->execute($orphanIds);
            $totalDeleted = count($orphanIds);
        }

    } catch (PDOException $e) {
        $errors[] = "Orphan cleanup error: " . $e->getMessage();
    }
}

// Output the results
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'tag_id' => $tagId,
    'total_contacts' => count($contacts),
    'total_inserted' => $totalInserted,
    'total_updated' => $totalUpdated,
    'total_deleted' => $totalDeleted,
    'duplicates_removed' => $totalDuplicatesRemoved,
    'errors' => $errors
], JSON_PRETTY_PRINT);
