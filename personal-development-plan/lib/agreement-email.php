<?php
/**
 * Agreement confirmation email via Keap XML-RPC APIEmailService.sendEmail
 * (same transport pattern used by roster/drop_contact.php and
 * roster/send_bulk_email.php).
 *
 * Keap XML-RPC sendEmail does not support binary attachments, so the email
 * contains:
 *   - The selected plan summary inline
 *   - A secure link to download the signed PDF (which contains the full
 *     Terms & Conditions the client agreed to)
 *
 * TEST MODE: while PDP_AGREEMENT_EMAIL_TEST_TO is set, all confirmation
 * emails are routed to that single address (looked up as a Keap contact)
 * instead of the actual client. Set to '' or remove to go live.
 */

require_once __DIR__ . '/keap-pdp-helpers.php';

// Set to '' (empty string) when ready to send to real clients.
if (!defined('PDP_AGREEMENT_EMAIL_TEST_TO')) {
    define('PDP_AGREEMENT_EMAIL_TEST_TO', 'benthole@gmail.com');
}

if (!defined('PDP_AGREEMENT_DOWNLOAD_BASE')) {
    // Public base URL for the agreement download endpoint
    define('PDP_AGREEMENT_DOWNLOAD_BASE', 'https://checkout.livewright.com/personal-development-plan/agreement.php');
}

if (!defined('PDP_AGREEMENT_EMAIL_FROM')) {
    define('PDP_AGREEMENT_EMAIL_FROM', 'LiveWright <no-reply@livewright.com>');
}

/**
 * Look up a Keap contact_id by email. Reuses pdp_keap_request().
 *
 * @return int|null
 */
function pdp_keap_lookup_contact_id_by_email($email) {
    $result = pdp_keap_request('GET', '/crm/rest/v1/contacts?email=' . urlencode($email) . '&limit=1');
    if ($result['success'] && !empty($result['data']['contacts'][0]['id'])) {
        return (int)$result['data']['contacts'][0]['id'];
    }
    return null;
}

/**
 * Build HTML + text bodies for the confirmation email.
 */
function pdp_build_agreement_email_bodies(array $contract, array $option, string $downloadUrl) {
    $clientName = trim(($contract['first_name'] ?? '') . ' ' . ($contract['last_name'] ?? ''));
    $optionNumber = (int)($option['option_number'] ?? 0);
    $subOptionName = $option['sub_option_name'] ?? '';
    $subSuffix = ($subOptionName && $subOptionName !== 'Default')
        ? ' — ' . $subOptionName
        : '';
    $priceRaw = (float)($option['price'] ?? 0);
    $price = number_format($priceRaw, 2);
    $type = $option['type'] ?? '';
    $signedDisplay = date('F j, Y', strtotime($contract['agreement_signed_at'] ?? 'now'));

    // Option description (HTML from the Quill editor — allow through).
    $optionDescriptionHtml = trim((string)($option['description'] ?? ''));

    // "What's included" content from the contract row, if any.
    $includedHtml = trim((string)($contract['contract_description'] ?? ''));

    // Program start date if set on the contract.
    $programStart = !empty($contract['program_start_date'])
        ? date('F j, Y', strtotime($contract['program_start_date']))
        : '';

    // Initial payment vs. recurring split — same rule as the PDP page + PDF:
    // Monthly + deposit => charge deposit today, then recurring.
    $depositRaw = isset($option['deposit_amount']) && $option['deposit_amount'] !== null
        ? (float)$option['deposit_amount']
        : 0.0;
    $hasMonthlyDeposit = ($type === 'Monthly' && $depositRaw > 0);
    $initialAmountRaw = $hasMonthlyDeposit ? $depositRaw : $priceRaw;

    $paymentSummaryHtml = '<div style="margin-top:12px;padding-top:10px;border-top:1px solid #d8e1ea;">';
    if ($hasMonthlyDeposit) {
        $paymentSummaryHtml .= '<div style="font-size:14px;color:#3a3a3c;">'
            . '<strong>Initial payment due at signing:</strong> $' . number_format($initialAmountRaw, 2) . '<br>'
            . '<span style="color:#6e6e73;">Then $' . $price . '/month for the next 12 months</span>'
            . '</div>';
    } else {
        $paymentSummaryHtml .= '<div style="font-size:14px;color:#3a3a3c;">'
            . '<strong>Total at signing:</strong> $' . $price . ' <span style="color:#6e6e73;">/ ' . htmlspecialchars($type) . '</span>'
            . '</div>';
    }
    $paymentSummaryHtml .= '</div>';

    $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:-apple-system,Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.55;color:#1d1d1f;max-width:620px;">'
        . '<p>Hi ' . htmlspecialchars($contract['first_name'] ?? 'there') . ',</p>'
        . '<p>Thank you for signing your LiveWright Personal Development Plan. We\'re excited to begin this work with you.</p>'
        . '<p><strong>Your selected plan:</strong></p>'
        . '<div style="background:#f5f7fa;border:1px solid #d8e1ea;border-radius:6px;padding:16px 20px;margin:10px 0 16px;">'
            . '<div style="font-weight:700;font-size:15px;">Option ' . $optionNumber . htmlspecialchars($subSuffix) . '</div>'
            . '<div style="font-size:20px;color:#005FA3;font-weight:700;margin-top:6px;">$' . $price
            . ' <span style="font-size:13px;color:#3a3a3c;font-weight:500;">/ ' . htmlspecialchars($type) . '</span></div>'
            . ($optionDescriptionHtml !== ''
                ? '<div style="margin-top:12px;padding-top:10px;border-top:1px solid #d8e1ea;color:#1d1d1f;">' . $optionDescriptionHtml . '</div>'
                : '')
            . $paymentSummaryHtml
        . '</div>'
        . ($includedHtml !== ''
            ? '<p><strong>What\'s included in your plan:</strong></p>'
              . '<div style="background:#ffffff;border:1px solid #e5e5ea;border-radius:6px;padding:14px 18px;margin:6px 0 16px;color:#1d1d1f;">'
              . $includedHtml
              . '</div>'
            : '')
        . ($programStart !== ''
            ? '<p style="font-size:14px;"><strong>Program start date:</strong> ' . htmlspecialchars($programStart) . '</p>'
            : '')
        . '<p>A signed copy of your full agreement is available here:</p>'
        . '<p><a href="' . htmlspecialchars($downloadUrl) . '" style="display:inline-block;background:#005FA3;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;">Download Your Agreement (PDF)</a></p>'
        . '<p style="font-size:12px;color:#6e6e73;">If the button doesn\'t work, copy this link into your browser:<br>'
        . htmlspecialchars($downloadUrl) . '</p>'
        . '<p style="font-size:13px;color:#3a3a3c;">You\'ll also receive a separate email with your invoice/receipt for the payment we just processed.</p>'
        . '<p>Signed on ' . htmlspecialchars($signedDisplay) . '. If you have any questions, just reply to this email.</p>'
        . '<p>— The LiveWright Team</p>'
        . '</body></html>';

    // Plain-text version — strip HTML from rich-text fields.
    $optionDescText = trim(preg_replace('/\s+/', ' ', strip_tags($optionDescriptionHtml)));
    $includedText   = trim(preg_replace('/\s+/', ' ', strip_tags($includedHtml)));

    $textBody = "Hi " . ($contract['first_name'] ?? 'there') . ",\n\n"
        . "Thank you for signing your LiveWright Personal Development Plan.\n\n"
        . "YOUR SELECTED PLAN\n"
        . "Option {$optionNumber}{$subSuffix}\n"
        . "\${$price} / {$type}\n";
    if ($optionDescText !== '') {
        $textBody .= "\n{$optionDescText}\n";
    }
    if ($hasMonthlyDeposit) {
        $textBody .= "\nInitial payment due at signing: \$" . number_format($initialAmountRaw, 2) . "\n"
            . "Then \${$price}/month for the next 12 months.\n";
    } else {
        $textBody .= "\nTotal at signing: \${$price} / {$type}\n";
    }
    if ($includedText !== '') {
        $textBody .= "\nWHAT'S INCLUDED\n{$includedText}\n";
    }
    if ($programStart !== '') {
        $textBody .= "\nProgram start date: {$programStart}\n";
    }
    $textBody .= "\nDownload your signed agreement:\n{$downloadUrl}\n\n"
        . "You'll also receive a separate email with your invoice/receipt for the payment we just processed.\n\n"
        . "Signed on {$signedDisplay}. If you have any questions, just reply to this email.\n\n"
        . "— The LiveWright Team\n";

    return [
        'subject' => 'Your LiveWright Personal Development Plan Agreement',
        'html' => $htmlBody,
        'text' => $textBody,
    ];
}

/**
 * Send the agreement confirmation email via Keap XML-RPC.
 *
 * Honors PDP_AGREEMENT_EMAIL_TEST_TO (overrides recipient while testing).
 *
 * @return array ['success' => bool, 'recipient' => string|null, 'contact_id' => int|null, 'error' => string|null]
 */
function pdp_send_agreement_email(array $contract, array $option, string $downloadUrl) {
    $token = pdp_get_keap_token();
    if (!$token) {
        return ['success' => false, 'recipient' => null, 'contact_id' => null, 'error' => 'No Keap token available'];
    }

    $testTo = PDP_AGREEMENT_EMAIL_TEST_TO;
    $recipientEmail = $testTo !== '' ? $testTo : ($contract['email'] ?? '');

    if (empty($recipientEmail)) {
        return ['success' => false, 'recipient' => null, 'contact_id' => null, 'error' => 'No recipient email'];
    }

    // Resolve recipient to a Keap contact_id (XML-RPC sendEmail requires a contact list).
    $contactId = null;
    if ($testTo !== '') {
        $contactId = pdp_keap_lookup_contact_id_by_email($recipientEmail);
    } else {
        // Live mode: prefer the contact_id we already have on the contract
        $contactId = !empty($contract['keap_contact_id'])
            ? (int)$contract['keap_contact_id']
            : pdp_keap_lookup_contact_id_by_email($recipientEmail);
    }

    if (!$contactId) {
        return ['success' => false, 'recipient' => $recipientEmail, 'contact_id' => null, 'error' => 'Could not resolve Keap contact for ' . $recipientEmail];
    }

    $bodies = pdp_build_agreement_email_bodies($contract, $option, $downloadUrl);

    // Tag test sends in the subject so they're obvious in the inbox.
    if ($testTo !== '') {
        $bodies['subject'] = '[TEST] ' . $bodies['subject']
            . ' (intended for ' . ($contract['email'] ?? 'unknown') . ')';
    }

    $contactListXml = "<value><i4>{$contactId}</i4></value>";
    $fromAddress = PDP_AGREEMENT_EMAIL_FROM;

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
        error_log('pdp_send_agreement_email: HTTP ' . $httpCode . ' resp=' . substr((string)$resp, 0, 500));
        return ['success' => false, 'recipient' => $recipientEmail, 'contact_id' => $contactId, 'error' => 'Keap email send failed (HTTP ' . $httpCode . ')'];
    }

    return ['success' => true, 'recipient' => $recipientEmail, 'contact_id' => $contactId, 'error' => null];
}

/**
 * Build the public download URL for an agreement.
 */
function pdp_agreement_download_url(string $uniqueId, string $token) {
    return PDP_AGREEMENT_DOWNLOAD_BASE
        . '?uid=' . urlencode($uniqueId)
        . '&token=' . urlencode($token);
}
