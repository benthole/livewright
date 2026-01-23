<?php
// attendance_db.php

function save_attendance_tag($tagName, $keapTagId, $meetingDate, $cohortId, $pdo) {
    file_put_contents(__DIR__ . '/keap_contact_debug.log',
        "[TAG INSERT ATTEMPT] name: $tagName | keapTagId: $keapTagId | date: $meetingDate | cohortId: " . json_encode($cohortId) . "\n",
        FILE_APPEND
    );

    try {
        // Check if tag already exists in the DB
        $stmt = $pdo->prepare("SELECT id FROM attendance_tags WHERE keap_tag_id = ?");
        $stmt->execute([$keapTagId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            file_put_contents(__DIR__ . '/keap_contact_debug.log',
                "[TAG DEBUG] Tag already exists in DB with id: {$existing['id']}\n",
                FILE_APPEND
            );
            return $existing['id'];
        }

        // Log the actual params being inserted
        file_put_contents(__DIR__ . '/keap_contact_debug.log',
            "[TAG DEBUG] SQL Params: " . json_encode([$tagName, $keapTagId, $meetingDate, $cohortId]) . "\n",
            FILE_APPEND
        );

        // Insert new attendance tag
        $stmt = $pdo->prepare("INSERT INTO attendance_tags (tag_name, keap_tag_id, meeting_date, cohort_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$tagName, $keapTagId, $meetingDate, $cohortId]);

        $lastId = $pdo->lastInsertId();
        file_put_contents(__DIR__ . '/keap_contact_debug.log',
            "[TAG INSERT] Inserted tag, lastInsertId: $lastId\n",
            FILE_APPEND
        );
        return $lastId;
    } catch (Throwable $e) {
        file_put_contents(__DIR__ . '/keap_contact_debug.log',
            "[TAG ERROR] " . $e->getMessage() . "\n",
            FILE_APPEND
        );
        return null;
    }
}

function save_attendance_record($contactId, $attendanceTagId, $pdo) {
    try {
        $stmt = $pdo->prepare("INSERT INTO attendance_records (contact_id, attendance_tag_id) VALUES (?, ?)");
        $stmt->execute([$contactId, $attendanceTagId]);
    } catch (Throwable $e) {
        file_put_contents(__DIR__ . '/keap_contact_debug.log', "[ATTENDANCE ERROR] Contact $contactId – " . $e->getMessage() . "\n", FILE_APPEND);
    }
}