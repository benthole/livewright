<?php
// send_bulk_email.php - Send bulk email to contacts via Keap XML-RPC API
// Uses XML-RPC endpoint to allow custom from address

require_once('includes/auth.php');
require_once('keap_api.php');
require_once('config.php');

header('Content-Type: application/json');

// Require editor permissions
require_editor();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$contactIds = isset($input['contact_ids']) ? $input['contact_ids'] : [];
$subject = isset($input['subject']) ? trim($input['subject']) : '';
$messageHtml = isset($input['message']) ? trim($input['message']) : '';
$messageText = isset($input['message_text']) ? trim($input['message_text']) : '';
$fromEmail = isset($input['from_email']) ? trim($input['from_email']) : '';
$fromName = isset($input['from_name']) ? trim($input['from_name']) : '';

// For backwards compatibility, check both fields
$message = !empty($messageText) ? $messageText : strip_tags($messageHtml);

// Validate inputs
if (empty($contactIds)) {
    echo json_encode(['success' => false, 'error' => 'No contacts selected']);
    exit;
}

if (empty($subject)) {
    echo json_encode(['success' => false, 'error' => 'Email subject is required']);
    exit;
}

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Email message is required']);
    exit;
}

if (empty($fromEmail)) {
    echo json_encode(['success' => false, 'error' => 'From email is required']);
    exit;
}

// Basic email validation
if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid from email address']);
    exit;
}

// Enforce @livewright.com domain
if (!preg_match('/@livewright\.com$/i', $fromEmail)) {
    echo json_encode(['success' => false, 'error' => 'From email must be a @livewright.com address']);
    exit;
}

// Convert contact IDs to integers
$contactIds = array_map('intval', $contactIds);
$contactIds = array_filter($contactIds, function($id) { return $id > 0; });

if (empty($contactIds)) {
    echo json_encode(['success' => false, 'error' => 'No valid contact IDs']);
    exit;
}

// Limit to 1000 contacts per request (Keap API limit)
if (count($contactIds) > 1000) {
    echo json_encode(['success' => false, 'error' => 'Maximum 1000 contacts per email send']);
    exit;
}

// Get Keap token
$token = get_keap_token();

if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Could not get Keap API token']);
    exit;
}

// HTML sanitization for email content
function sanitize_html_email($html) {
    // Allow only safe HTML tags for email
    $allowed_tags = '<p><br><strong><b><em><i><u><a><ul><ol><li><span>';

    // Strip all tags except allowed ones
    $clean = strip_tags($html, $allowed_tags);

    // Sanitize href attributes in links (allow only http, https, mailto)
    $clean = preg_replace_callback(
        '/<a\s+([^>]*href=["\'])([^"\']+)(["\'][^>]*)>/i',
        function($matches) {
            $href = $matches[2];
            // Only allow safe URL schemes
            if (preg_match('/^(https?:|mailto:)/i', $href)) {
                return $matches[0]; // Keep as-is
            }
            // Remove dangerous links by stripping the href
            return '<a>';
        },
        $clean
    );

    // Remove any event handlers that might have slipped through
    $clean = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $clean);

    // Remove javascript: and data: URLs
    $clean = preg_replace('/(javascript|data|vbscript):/i', '', $clean);

    return $clean;
}

// Prepare email content
$textBody = $message;

// If we have HTML content, sanitize and use it; otherwise convert plain text to HTML
if (!empty($messageHtml) && $messageHtml !== $messageText) {
    $sanitizedHtml = sanitize_html_email($messageHtml);
    $htmlBody = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #333;">
' . $sanitizedHtml . '
</body>
</html>';
} else {
    // Plain text fallback (backwards compatibility)
    $htmlBody = '<html><body>' . nl2br(htmlspecialchars($message)) . '</body></html>';
}

// Format from address with name if provided
$fromAddress = $fromName ? "{$fromName} <{$fromEmail}>" : $fromEmail;

// Build XML-RPC request for APIEmailService.sendEmail
$contactListXml = '';
foreach ($contactIds as $id) {
    $contactListXml .= "<value><i4>{$id}</i4></value>";
}

$xmlRequest = '<?xml version="1.0" encoding="UTF-8"?>
<methodCall>
    <methodName>APIEmailService.sendEmail</methodName>
    <params>
        <param><value><string>' . htmlspecialchars($token) . '</string></value></param>
        <param><value><array><data>' . $contactListXml . '</data></array></value></param>
        <param><value><string>' . htmlspecialchars($fromAddress) . '</string></value></param>
        <param><value><string>~Contact.Email~</string></value></param>
        <param><value><string></string></value></param>
        <param><value><string></string></value></param>
        <param><value><string>Multipart</string></value></param>
        <param><value><string>' . htmlspecialchars($subject) . '</string></value></param>
        <param><value><string>' . htmlspecialchars($htmlBody) . '</string></value></param>
        <param><value><string>' . htmlspecialchars($textBody) . '</string></value></param>
    </params>
</methodCall>';

// Keap XML-RPC endpoint
$url = "https://api.infusionsoft.com/crm/xmlrpc/v1";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $xmlRequest,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        "Content-Type: application/xml"
    ]
]);

$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Check for curl errors
if ($curlError) {
    echo json_encode([
        'success' => false,
        'error' => 'Connection error: ' . $curlError
    ]);
    exit;
}

// Parse XML-RPC response
$success = false;
$errorMsg = 'Unknown error';

if ($httpCode === 200) {
    // Check for successful response (returns boolean true)
    if (strpos($resp, '<boolean>1</boolean>') !== false || strpos($resp, '<boolean>true</boolean>') !== false) {
        $success = true;
    } elseif (strpos($resp, '<fault>') !== false) {
        // Extract fault message
        if (preg_match('/<string>([^<]+)<\/string>/', $resp, $matches)) {
            $errorMsg = $matches[1];
        }
    } else {
        // Try to extract any error message
        if (preg_match('/<string>([^<]+)<\/string>/', $resp, $matches)) {
            $errorMsg = $matches[1];
        }
    }
}

if ($success) {
    // Log the email send
    try {
        $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create email log table if it doesn't exist
        $conn->exec("CREATE TABLE IF NOT EXISTS email_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subject VARCHAR(255),
            from_email VARCHAR(255),
            contact_count INT,
            contact_ids TEXT,
            sent_by VARCHAR(100),
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Log the email
        $currentUser = get_logged_in_user();
        $sentBy = $currentUser ? $currentUser['email'] : 'unknown';

        $stmt = $conn->prepare("INSERT INTO email_log (subject, from_email, contact_count, contact_ids, sent_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $subject,
            $fromEmail,
            count($contactIds),
            json_encode($contactIds),
            $sentBy
        ]);
    } catch (PDOException $e) {
        // Non-critical, continue
    }

    echo json_encode([
        'success' => true,
        'message' => 'Email sent successfully',
        'contact_count' => count($contactIds)
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => $errorMsg,
        'http_code' => $httpCode
    ]);
}
