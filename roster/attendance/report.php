<?php
require_once('config.php');
$pdo = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);

// Step 1: Get all cohorts with attendance
$cohorts = $pdo->query("
    SELECT DISTINCT c.id, c.name
    FROM cohorts c
    JOIN attendance_tags at ON c.id = at.cohort_id
    WHERE at.cohort_id IS NOT NULL
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

// Step 2: Get all tags (meetings) for those cohorts, grouped and sorted
$allTagsStmt = $pdo->query("
    SELECT at.id, at.tag_name, at.meeting_date, at.cohort_id, c.name AS cohort_name
    FROM attendance_tags at
    JOIN cohorts c ON at.cohort_id = c.id
    WHERE at.cohort_id IS NOT NULL
    ORDER BY c.name, at.meeting_date ASC
");
$allTags = $allTagsStmt->fetchAll(PDO::FETCH_ASSOC);

// Group tags by cohort
$tagsByCohort = [];
foreach ($allTags as $tag) {
    $tagsByCohort[$tag['cohort_id']][] = $tag;
}

// Step 3: Output modern styles
echo <<<STYLE
<style>
body {
    font-family: 'Inter', sans-serif;
    background: #f9fafb;
    color: #1f2937;
    padding: 2rem;
}
table {
    border-collapse: collapse;
    margin-bottom: 40px;
    width: auto;
    table-layout: auto;
}

th,
td {
    padding: 10px 12px;
    border: 1px solid #e5e7eb;
    text-align: center;
    font-size: 0.95rem;
    word-wrap: break-word;
}

th:first-child,
td:first-child {
    width: 200px;
    text-align: left;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

</style>
STYLE;

// Step 4: Loop through each cohort and generate a table
foreach ($cohorts as $cohort) {
    $cohortId = $cohort['id'];
    $cohortName = htmlspecialchars($cohort['name']);
    $meetings = $tagsByCohort[$cohortId] ?? [];

    if (count($meetings) === 0) continue;

    // Get contacts in this cohort
    $contactsStmt = $pdo->prepare("
    SELECT DISTINCT ct.id, ct.first_name, ct.last_name, ct.keap_contact_id
    FROM contact ct
    JOIN contact_cohorts cc ON ct.id = cc.contact_id
    WHERE cc.cohort_id = ?
    ORDER BY ct.last_name, ct.first_name
    ");
    $contactsStmt->execute([$cohortId]);
    $contacts = $contactsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($contacts) === 0) continue;

    // Get attendance records for this cohort
    $tagIds = array_column($meetings, 'id');

$attendanceStmt = $pdo->query("
    SELECT contact_id AS keap_contact_id, attendance_tag_id
    FROM attendance_records
    WHERE attendance_tag_id IN (" . implode(',', $tagIds) . ")
");

$attendanceMap = [];
foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $attendanceMap[$row['keap_contact_id']][$row['attendance_tag_id']] = true;
}

    // Output table
    echo "<h2>Cohort: {$cohortName}</h2>";
    echo "<table>";
    echo "<thead><tr><th class='name-col'>Contact Name</th>";
    foreach ($meetings as $tag) {
        $dateOnly = date('Y-m-d', strtotime($tag['meeting_date']));
        echo "<th style='width: 100px;'>$dateOnly</th>";
    }
    echo "</tr></thead><tbody>";

    foreach ($contacts as $contact) {
        $fullName = htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name']);
        echo "<tr>";
        echo "<td style='width: 200px; text-align: left;'>$fullName</td>";
        foreach ($meetings as $tag) {
            $keapId = $contact['keap_contact_id'] ?? null;
            $attended = ($keapId && isset($attendanceMap[$keapId][$tag['id']])) ? '✔️' : '';
            echo "<td style='width: 100px;'>$attended</td>";
        }
        echo "</tr>";
    }

    echo "</tbody></table>";
}
