<?php
require_once('config.php');
require_once('keap_api.php');
require_once('attendance_db.php');

$pdo = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);


header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $name        = trim($input['name'] ?? '');
    $date        = trim($input['date'] ?? '');
    $cohort      = trim($input['cohort'] ?? '');
    $contactIds  = $input['contact_ids'] ?? [];
    $useExisting = $input['use_existing'] ?? false;

    file_put_contents(__DIR__ . '/keap_contact_debug.log', "[" . date('Y-m-d H:i:s') . "] Contact IDs: " . json_encode($contactIds) . "\n", FILE_APPEND);

    if (!$name || !$date || empty($contactIds)) {
        echo json_encode(['success' => false, 'error' => 'Missing meeting name, date, or contact list']);
        exit;
    }

    $fullTagName = $name . ' ' . $date;
    if ($cohort) {
        $fullTagName .= " - " . $cohort;
    }

    $existingTag = keap_find_tag_by_name($fullTagName);

    if ($existingTag) {
        $tagId = $existingTag['id'];
    } else {
        $createTagResponse = keap_create_tag($fullTagName);
        if (empty($createTagResponse['id'])) {
            $errorMessage = $createTagResponse['message'] ?? 'Unable to create tag';
            echo json_encode(['success' => false, 'error' => $errorMessage]);
            exit;
        }
        $tagId = $createTagResponse['id'];
    }

    file_put_contents(__DIR__ . '/keap_contact_debug.log',
    "[" . date('Y-m-d H:i:s') . "] Attempting to create tag: $fullTagName | Response: " . json_encode($createTagResponse) . "\n",
    FILE_APPEND
);

    $successCount = 0;
    $successIds = [];
    $failedIds = [];

    foreach ($contactIds as $id) {
        if (keap_apply_tag_to_contact($id, $tagId)) {
            $successIds[] = $id;
            $successCount++;
        } else {
            $failedIds[] = $id;
        }
    }

    file_put_contents(__DIR__ . '/keap_contact_debug.log', "[DEBUG] successCount: $successCount\n", FILE_APPEND);
    
    if ($successCount > 0) {
        // Lookup cohort_id from name (if present)
        $cohortId = null;
        if ($cohort) {
            $stmt = $pdo->prepare("SELECT id FROM cohorts WHERE name = ? LIMIT 1");
            $stmt->execute([$cohort]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $cohortId = $row['id'];
            }
        }
    
        // Save tag to DB
        file_put_contents(__DIR__ . '/keap_contact_debug.log', "[DEBUG] About to call save_attendance_tag\n", FILE_APPEND);
        $attendanceTagId = save_attendance_tag($fullTagName, $tagId, $date, $cohortId, $pdo);
        file_put_contents(__DIR__ . '/keap_contact_debug.log', "[DEBUG] save_attendance_tag returned: " . json_encode($attendanceTagId) . "\n", FILE_APPEND);
    
        // Save attendance records
        foreach ($successIds as $contactId) {
            save_attendance_record($contactId, $attendanceTagId, $pdo);
        }
    
        file_put_contents(__DIR__ . '/keap_contact_debug.log', "[" . date('Y-m-d H:i:s') . "] Success: " . json_encode($successIds) . " | Fail: " . json_encode($failedIds) . "\n", FILE_APPEND);
    }
    
    echo json_encode([
        'success'  => true,
        'tag_name' => $fullTagName,
        'count'    => $successCount
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
