<?php
/**
 * Invoice PDF generation.
 *
 * Generates a PDF invoice/receipt for a payment record. Stored at
 * storage/invoices/{invoice_number}.pdf and the path/number/token are
 * recorded on the payments row.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Build a stable invoice number from a payment row.
 */
function pdp_invoice_number_for(array $payment) {
    if (!empty($payment['invoice_number'])) {
        return $payment['invoice_number'];
    }
    $year = date('Y', strtotime($payment['created_at'] ?? 'now'));
    return sprintf('INV-%s-%05d', $year, (int)$payment['id']);
}

/**
 * Friendly description of the line item for an invoice.
 */
function pdp_invoice_line_description(array $payment, array $option) {
    $isDeposit = ($payment['payment_type'] ?? '') === 'deposit';
    $optionDesc = $option['description'] ?? '';
    $sub = $option['sub_option_name'] ?? '';
    $type = $option['type'] ?? '';
    $optNum = (int)($option['option_number'] ?? 0);

    $title = 'Personal Development Plan — Option ' . $optNum;
    if ($sub && $sub !== 'Default') {
        $title .= ' (' . $sub . ')';
    }
    if ($isDeposit) {
        $title .= ' — Deposit';
    } elseif ($type) {
        $title .= ' — ' . $type;
    }

    return [
        'title' => $title,
        'detail' => strip_tags($optionDesc),
    ];
}

/**
 * Build the HTML body for the invoice PDF.
 */
function pdp_invoice_html(array $contract, array $option, array $payment) {
    $invoiceNumber = pdp_invoice_number_for($payment);
    $invoiceDate = date('F j, Y', strtotime($payment['created_at'] ?? 'now'));
    $clientName = trim(($contract['first_name'] ?? '') . ' ' . ($contract['last_name'] ?? ''));
    $clientEmail = $contract['email'] ?? '';
    $amount = number_format((float)($payment['amount'] ?? 0), 2);
    $currency = strtoupper($payment['currency'] ?? 'USD');

    $line = pdp_invoice_line_description($payment, $option);

    // Determine payment processor / reference for the receipt
    $processor = 'Credit Card';
    $reference = '';
    if (!empty($payment['stripe_payment_intent_id'])) {
        $processor = 'Stripe';
        $reference = $payment['stripe_payment_intent_id'];
    } elseif (!empty($payment['keap_order_id'])) {
        $processor = 'Keap';
        $reference = 'Order #' . $payment['keap_order_id'];
    }

    $isDeposit = ($payment['payment_type'] ?? '') === 'deposit';
    $meta = [];
    if (!empty($payment['metadata'])) {
        $decoded = json_decode($payment['metadata'], true);
        if (is_array($decoded)) $meta = $decoded;
    }
    $fullPlanPrice = isset($meta['full_plan_price']) ? (float)$meta['full_plan_price'] : null;
    $planType = $meta['plan_type'] ?? ($option['type'] ?? '');

    ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice <?= htmlspecialchars($invoiceNumber) ?></title>
<style>
    @page { margin: 60px 50px 60px 50px; }
    body {
        font-family: DejaVu Sans, Arial, sans-serif;
        font-size: 11pt;
        line-height: 1.45;
        color: #1d1d1f;
    }
    h1 { font-size: 26pt; color: #005FA3; margin: 0; letter-spacing: -0.02em; }
    h2 { font-size: 13pt; color: #005FA3; margin: 22px 0 8px; }
    .header-row {
        display: table;
        width: 100%;
        border-bottom: 2px solid #005FA3;
        padding-bottom: 14px;
        margin-bottom: 18px;
    }
    .header-row > div { display: table-cell; vertical-align: top; }
    .header-row .right { text-align: right; }
    .label-tag {
        display: inline-block;
        background: #e6f3ff;
        color: #005FA3;
        font-size: 10pt;
        font-weight: 600;
        padding: 3px 10px;
        border-radius: 6px;
        margin-top: 6px;
    }
    .paid-tag {
        display: inline-block;
        background: #e6f7ec;
        color: #248a3d;
        font-size: 10pt;
        font-weight: 700;
        padding: 4px 12px;
        border-radius: 6px;
        letter-spacing: 0.04em;
    }
    .meta-grid { display: table; width: 100%; margin: 8px 0 18px; }
    .meta-grid > div { display: table-cell; width: 50%; vertical-align: top; padding-right: 16px; }
    .meta-grid .label { color: #6e6e73; font-size: 10pt; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px; }
    .meta-grid .value { font-size: 11pt; }
    .items {
        width: 100%;
        border-collapse: collapse;
        margin: 8px 0 14px;
    }
    .items th {
        text-align: left;
        background: #f5f7fa;
        color: #3a3a3c;
        font-size: 10pt;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 600;
        padding: 9px 12px;
        border-bottom: 1px solid #d8e1ea;
    }
    .items th.amount, .items td.amount { text-align: right; }
    .items td {
        padding: 14px 12px;
        border-bottom: 1px solid #e5e5ea;
        vertical-align: top;
    }
    .items td .item-detail {
        color: #6e6e73;
        font-size: 10pt;
        margin-top: 4px;
    }
    .totals { width: 100%; margin-top: 6px; }
    .totals td { padding: 6px 12px; }
    .totals .label-cell { text-align: right; color: #6e6e73; }
    .totals .total-row td {
        font-size: 14pt;
        font-weight: 700;
        color: #005FA3;
        border-top: 2px solid #005FA3;
        padding-top: 12px;
    }
    .footer-note {
        margin-top: 28px;
        padding-top: 14px;
        border-top: 1px solid #e5e5ea;
        font-size: 9.5pt;
        color: #6e6e73;
    }
    .deposit-callout {
        background: #fff8ec;
        border: 1px solid #ffd591;
        border-radius: 6px;
        padding: 12px 16px;
        margin: 12px 0 4px;
        font-size: 10.5pt;
    }
</style>
</head>
<body>

<div class="header-row">
    <div>
        <div style="font-size: 18pt; font-weight: 700; color: #005FA3;">LiveWright, LLC</div>
        <div style="color:#6e6e73; font-size: 10pt; margin-top: 2px;">Personal Development Plans &amp; Coaching</div>
        <div class="label-tag">Invoice / Receipt</div>
    </div>
    <div class="right">
        <h1>Invoice</h1>
        <div style="margin-top: 6px; font-size: 11pt;"><strong>#<?= htmlspecialchars($invoiceNumber) ?></strong></div>
        <div style="color:#6e6e73; font-size: 10pt; margin-top: 4px;"><?= htmlspecialchars($invoiceDate) ?></div>
        <div style="margin-top: 8px;"><span class="paid-tag">PAID</span></div>
    </div>
</div>

<div class="meta-grid">
    <div>
        <div class="label">Bill To</div>
        <div class="value"><strong><?= htmlspecialchars($clientName) ?></strong></div>
        <div class="value" style="color:#3a3a3c;"><?= htmlspecialchars($clientEmail) ?></div>
    </div>
    <div>
        <div class="label">Payment</div>
        <div class="value"><?= htmlspecialchars($processor) ?></div>
        <?php if ($reference): ?>
            <div class="value" style="color:#6e6e73; font-size: 10pt;"><?= htmlspecialchars($reference) ?></div>
        <?php endif; ?>
    </div>
</div>

<table class="items">
    <thead>
        <tr>
            <th style="width: 70%;">Description</th>
            <th class="amount" style="width: 30%;">Amount</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>
                <strong><?= htmlspecialchars($line['title']) ?></strong>
                <?php if (!empty($line['detail'])): ?>
                    <div class="item-detail"><?= htmlspecialchars(mb_strimwidth($line['detail'], 0, 240, '…')) ?></div>
                <?php endif; ?>
            </td>
            <td class="amount">$<?= $amount ?></td>
        </tr>
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="label-cell" style="width:75%;">Subtotal</td>
        <td class="amount" style="width:25%; text-align:right;">$<?= $amount ?></td>
    </tr>
    <tr class="total-row">
        <td class="label-cell">Total Paid (<?= htmlspecialchars($currency) ?>)</td>
        <td class="amount" style="text-align:right;">$<?= $amount ?></td>
    </tr>
</table>

<?php if ($isDeposit && $fullPlanPrice): ?>
<div class="deposit-callout">
    <strong>Note:</strong> This is a deposit toward your selected plan
    ($<?= number_format($fullPlanPrice, 2) ?> / <?= htmlspecialchars($planType) ?>).
    Your remaining balance will be charged on the agreed schedule.
</div>
<?php endif; ?>

<div class="footer-note">
    <p>Thank you for your business. If you have any questions about this invoice, please reply to the email this was sent with or contact LiveWright support.</p>
    <p>LiveWright, LLC · Walworth County, Wisconsin</p>
</div>

</body>
</html>
    <?php
    return ob_get_clean();
}

/**
 * Generate and persist an invoice PDF for a payment.
 *
 * @return array ['success' => bool, 'path' => string|null, 'token' => string|null, 'invoice_number' => string|null, 'error' => string|null]
 */
function pdp_generate_invoice_pdf(PDO $pdo, array $contract, array $option, array $payment) {
    try {
        $storageDir = __DIR__ . '/../storage/invoices';
        if (!is_dir($storageDir)) {
            if (!mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
                return ['success' => false, 'path' => null, 'token' => null, 'invoice_number' => null, 'error' => 'Could not create storage directory'];
            }
        }

        $invoiceNumber = pdp_invoice_number_for($payment);
        $payment['invoice_number'] = $invoiceNumber;

        $html = pdp_invoice_html($contract, $option, $payment);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        $pdfBytes = $dompdf->output();

        $filename = $invoiceNumber . '.pdf';
        $path = $storageDir . '/' . $filename;
        if (file_put_contents($path, $pdfBytes) === false) {
            return ['success' => false, 'path' => null, 'token' => null, 'invoice_number' => $invoiceNumber, 'error' => 'Failed to write PDF'];
        }

        $token = $payment['invoice_download_token'] ?? '';
        if (!$token) {
            $token = bin2hex(random_bytes(16));
        }

        $relativePath = 'storage/invoices/' . $filename;
        $stmt = $pdo->prepare("UPDATE payments SET invoice_number = ?, invoice_pdf_path = ?, invoice_download_token = ? WHERE id = ?");
        $stmt->execute([$invoiceNumber, $relativePath, $token, $payment['id']]);

        return [
            'success' => true,
            'path' => $relativePath,
            'token' => $token,
            'invoice_number' => $invoiceNumber,
            'error' => null,
        ];
    } catch (Throwable $e) {
        error_log('pdp_generate_invoice_pdf error: ' . $e->getMessage());
        return ['success' => false, 'path' => null, 'token' => null, 'invoice_number' => null, 'error' => $e->getMessage()];
    }
}
