<?php
/**
 * Public agreement PDF download endpoint.
 *
 * Requires both the contract unique_id and the per-contract download token
 * (random 32-hex). Admin-area users can also bypass the token via session.
 */

require_once __DIR__ . '/config.php';

$uid = trim($_GET['uid'] ?? '');
$token = trim($_GET['token'] ?? '');

if ($uid === '') {
    http_response_code(400);
    echo 'Missing agreement ID.';
    exit;
}

$stmt = $pdo->prepare("SELECT id, unique_id, first_name, last_name, agreement_pdf_path, agreement_download_token FROM contracts WHERE unique_id = ? AND deleted_at IS NULL");
$stmt->execute([$uid]);
$contract = $stmt->fetch();

if (!$contract || empty($contract['agreement_pdf_path'])) {
    http_response_code(404);
    echo 'Agreement not found.';
    exit;
}

// Authorize: either admin session, or matching token
$isAdmin = function_exists('is_logged_in') ? is_logged_in() : false;
if (!$isAdmin) {
    if (empty($token) || empty($contract['agreement_download_token'])
        || !hash_equals((string)$contract['agreement_download_token'], (string)$token)) {
        http_response_code(403);
        echo 'Invalid or missing download token.';
        exit;
    }
}

$path = __DIR__ . '/' . $contract['agreement_pdf_path'];
if (!is_file($path)) {
    http_response_code(404);
    echo 'Agreement file is missing on the server.';
    exit;
}

$filename = 'LiveWright-Agreement-'
    . preg_replace('/[^A-Za-z0-9_-]/', '', ($contract['first_name'] ?? '') . '-' . ($contract['last_name'] ?? ''))
    . '.pdf';

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, no-cache');
readfile($path);
exit;
