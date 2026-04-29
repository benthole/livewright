<?php
/**
 * Public invoice PDF download endpoint.
 *
 * Requires invoice_number + matching invoice_download_token.
 * Admin sessions can bypass the token.
 */

require_once __DIR__ . '/config.php';

$invoiceNumber = trim($_GET['inv'] ?? '');
$token = trim($_GET['token'] ?? '');

if ($invoiceNumber === '') {
    http_response_code(400);
    echo 'Missing invoice number.';
    exit;
}

$stmt = $pdo->prepare("
    SELECT p.id, p.invoice_number, p.invoice_pdf_path, p.invoice_download_token,
           c.first_name, c.last_name
    FROM payments p
    JOIN contracts c ON c.id = p.contract_id
    WHERE p.invoice_number = ?
    LIMIT 1
");
$stmt->execute([$invoiceNumber]);
$row = $stmt->fetch();

if (!$row || empty($row['invoice_pdf_path'])) {
    http_response_code(404);
    echo 'Invoice not found.';
    exit;
}

$isAdmin = function_exists('is_logged_in') ? is_logged_in() : false;
if (!$isAdmin) {
    if (empty($token) || empty($row['invoice_download_token'])
        || !hash_equals((string)$row['invoice_download_token'], (string)$token)) {
        http_response_code(403);
        echo 'Invalid or missing download token.';
        exit;
    }
}

$path = __DIR__ . '/' . $row['invoice_pdf_path'];
if (!is_file($path)) {
    http_response_code(404);
    echo 'Invoice file is missing on the server.';
    exit;
}

$filename = 'LiveWright-Invoice-' . preg_replace('/[^A-Za-z0-9_-]/', '', $row['invoice_number']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, no-cache');
readfile($path);
exit;
