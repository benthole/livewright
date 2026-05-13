<?php
/**
 * Archive / unarchive PDPs in bulk.
 *
 * POST:
 *   action  = "archive" | "unarchive"
 *   ids[]   = contract ids
 *   status  = currently-viewed tab (for the redirect)
 *   archive_note = optional text (archive only)
 *
 * Archive sets archived_at = NOW() and stores the optional note.
 * Unarchive clears both columns.
 *
 * Soft-deleted (trashed) rows are not modified — trash is separate.
 */

require_once '../config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$action = $_POST['action'] ?? '';
$ids    = $_POST['ids'] ?? [];
$status = $_POST['status'] ?? 'all';
$note   = trim((string)($_POST['archive_note'] ?? ''));

$ids = array_values(array_filter(array_map('intval', (array)$ids), fn($n) => $n > 0));
if (empty($ids)) {
    header('Location: index.php?status=' . urlencode($status));
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$count = 0;

if ($action === 'archive') {
    $sql = "UPDATE contracts
            SET archived_at = NOW(),
                archive_note = ?
            WHERE id IN ($placeholders)
              AND deleted_at IS NULL
              AND archived_at IS NULL";
    $params = array_merge([$note !== '' ? $note : null], $ids);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->rowCount();
    header('Location: index.php?status=' . urlencode($status) . '&archived=' . $count);
    exit;
}

if ($action === 'unarchive') {
    $sql = "UPDATE contracts
            SET archived_at = NULL,
                archive_note = NULL
            WHERE id IN ($placeholders)
              AND deleted_at IS NULL
              AND archived_at IS NOT NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $count = $stmt->rowCount();
    header('Location: index.php?status=' . urlencode($status) . '&unarchived=' . $count);
    exit;
}

header('Location: index.php?status=' . urlencode($status));
exit;
