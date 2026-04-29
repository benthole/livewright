<?php
/**
 * Invoice/receipt email via Keap XML-RPC APIEmailService.sendEmail
 * (same transport pattern as the agreement email and roster tools).
 *
 * Honors the same PDP_AGREEMENT_EMAIL_TEST_TO test override so that
 * during testing, both emails route to the same inbox.
 */

require_once __DIR__ . '/keap-pdp-helpers.php';
require_once __DIR__ . '/agreement-email.php'; // for shared constants + helpers

if (!defined('PDP_INVOICE_DOWNLOAD_BASE')) {
    define('PDP_INVOICE_DOWNLOAD_BASE', 'https://checkout.livewright.com/personal-development-plan/invoice.php');
}
if (!defined('PDP_INVOICE_EMAIL_FROM')) {
    define('PDP_INVOICE_EMAIL_FROM', 'LiveWright <no-reply@livewright.com>');
}

/**
 * Build HTML + text bodies for the invoice email.
 */
function pdp_build_invoice_email_bodies(array $contract, array $option, array $payment, string $invoiceNumber, string $downloadUrl) {
    $clientFirst = $contract['first_name'] ?? 'there';
    $amount = number_format((float)($payment['amount'] ?? 0), 2);
    $isDeposit = ($payment['payment_type'] ?? '') === 'deposit';
    $line = pdp_invoice_line_description($payment, $option);
    $invoiceDate = date('F j, Y', strtotime($payment['created_at'] ?? 'now'));

    $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:-apple-system,Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.55;color:#1d1d1f;max-width:620px;">'
        . '<p>Hi ' . htmlspecialchars($clientFirst) . ',</p>'
        . '<p>Thank you for your payment. Here is your receipt for invoice <strong>#' . htmlspecialchars($invoiceNumber) . '</strong>.</p>'
        . '<table style="width:100%;border-collapse:collapse;background:#f5f7fa;border:1px solid #d8e1ea;border-radius:6px;margin:10px 0 16px;">'
        . '<tr><td style="padding:10px 14px;border-bottom:1px solid #e5e5ea;color:#6e6e73;width:40%;">Invoice</td>'
        . '<td style="padding:10px 14px;border-bottom:1px solid #e5e5ea;"><strong>#' . htmlspecialchars($invoiceNumber) . '</strong></td></tr>'
        . '<tr><td style="padding:10px 14px;border-bottom:1px solid #e5e5ea;color:#6e6e73;">Date</td>'
        . '<td style="padding:10px 14px;border-bottom:1px solid #e5e5ea;">' . htmlspecialchars($invoiceDate) . '</td></tr>'
        . '<tr><td style="padding:10px 14px;border-bottom:1px solid #e5e5ea;color:#6e6e73;">Description</td>'
        . '<td style="padding:10px 14px;border-bottom:1px solid #e5e5ea;">' . htmlspecialchars($line['title']) . '</td></tr>'
        . '<tr><td style="padding:12px 14px;color:#6e6e73;">Amount Paid</td>'
        . '<td style="padding:12px 14px;font-size:18px;color:#005FA3;font-weight:700;">$' . $amount . '</td></tr>'
        . '</table>';

    if ($isDeposit) {
        $meta = [];
        if (!empty($payment['metadata'])) {
            $decoded = json_decode($payment['metadata'], true);
            if (is_array($decoded)) $meta = $decoded;
        }
        if (!empty($meta['full_plan_price'])) {
            $htmlBody .= '<p style="font-size:13px;color:#6e6e73;">This payment is a deposit toward your selected plan ($'
                . number_format((float)$meta['full_plan_price'], 2)
                . ' / ' . htmlspecialchars($meta['plan_type'] ?? '') . ').</p>';
        }
    }

    $htmlBody .= '<p>A PDF copy of your invoice is available here:</p>'
        . '<p><a href="' . htmlspecialchars($downloadUrl) . '" style="display:inline-block;background:#005FA3;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;">Download Invoice (PDF)</a></p>'
        . '<p style="font-size:12px;color:#6e6e73;">If the button doesn\'t work, copy this link into your browser:<br>'
        . htmlspecialchars($downloadUrl) . '</p>'
        . '<p>If you have any questions about this invoice, just reply to this email.</p>'
        . '<p>— The LiveWright Team</p>'
        . '</body></html>';

    $textBody = "Hi " . $clientFirst . ",\n\n"
        . "Thank you for your payment. Here is your receipt for invoice #{$invoiceNumber}.\n\n"
        . "Invoice: #{$invoiceNumber}\n"
        . "Date: {$invoiceDate}\n"
        . "Description: {$line['title']}\n"
        . "Amount Paid: \${$amount}\n\n"
        . "Download a PDF copy:\n{$downloadUrl}\n\n"
        . "If you have any questions, just reply to this email.\n\n"
        . "— The LiveWright Team\n";

    return [
        'subject' => 'Your LiveWright Invoice #' . $invoiceNumber,
        'html' => $htmlBody,
        'text' => $textBody,
    ];
}

/**
 * Send the invoice email via Keap XML-RPC.
 *
 * @return array ['success' => bool, 'recipient' => string|null, 'contact_id' => int|null, 'error' => string|null]
 */
function pdp_send_invoice_email(array $contract, array $option, array $payment, string $invoiceNumber, string $downloadUrl) {
    $token = pdp_get_keap_token();
    if (!$token) {
        return ['success' => false, 'recipient' => null, 'contact_id' => null, 'error' => 'No Keap token available'];
    }

    $testTo = defined('PDP_AGREEMENT_EMAIL_TEST_TO') ? PDP_AGREEMENT_EMAIL_TEST_TO : '';
    $recipientEmail = $testTo !== '' ? $testTo : ($contract['email'] ?? '');
    if (empty($recipientEmail)) {
        return ['success' => false, 'recipient' => null, 'contact_id' => null, 'error' => 'No recipient email'];
    }

    $contactId = null;
    if ($testTo !== '') {
        $contactId = pdp_keap_lookup_contact_id_by_email($recipientEmail);
    } else {
        $contactId = !empty($contract['keap_contact_id'])
            ? (int)$contract['keap_contact_id']
            : pdp_keap_lookup_contact_id_by_email($recipientEmail);
    }

    if (!$contactId) {
        return ['success' => false, 'recipient' => $recipientEmail, 'contact_id' => null, 'error' => 'Could not resolve Keap contact for ' . $recipientEmail];
    }

    $bodies = pdp_build_invoice_email_bodies($contract, $option, $payment, $invoiceNumber, $downloadUrl);

    if ($testTo !== '') {
        $bodies['subject'] = '[TEST] ' . $bodies['subject']
            . ' (intended for ' . ($contract['email'] ?? 'unknown') . ')';
    }

    $contactListXml = "<value><i4>{$contactId}</i4></value>";
    $fromAddress = PDP_INVOICE_EMAIL_FROM;

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
        <param><value><string>' . htmlspecialchars($bodies['subject']) . '</string></value></param>
        <param><value><string>' . htmlspecialchars($bodies['html']) . '</string></value></param>
        <param><value><string>' . htmlspecialchars($bodies['text']) . '</string></value></param>
    </params>
</methodCall>';

    $ch = curl_init('https://api.infusionsoft.com/crm/xmlrpc/v1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $xmlRequest,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/xml",
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $emailOk = ($httpCode === 200 && (
        strpos($resp, '<boolean>1</boolean>') !== false ||
        strpos($resp, '<boolean>true</boolean>') !== false
    ));

    if (!$emailOk) {
        error_log('pdp_send_invoice_email: HTTP ' . $httpCode . ' resp=' . substr((string)$resp, 0, 500));
        return ['success' => false, 'recipient' => $recipientEmail, 'contact_id' => $contactId, 'error' => 'Keap email send failed (HTTP ' . $httpCode . ')'];
    }

    return ['success' => true, 'recipient' => $recipientEmail, 'contact_id' => $contactId, 'error' => null];
}

/**
 * Build the public invoice download URL.
 */
function pdp_invoice_download_url(string $invoiceNumber, string $token) {
    return PDP_INVOICE_DOWNLOAD_BASE
        . '?inv=' . urlencode($invoiceNumber)
        . '&token=' . urlencode($token);
}
