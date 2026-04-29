<?php
/**
 * Agreement PDF generation.
 *
 * Generates a PDF of the signed Personal Development Plan agreement that
 * includes:
 *   - Client name + email
 *   - Selected plan summary (option, sub-option, price, billing type)
 *   - Pathways content
 *   - Full Terms of Service & Operating Agreements
 *   - Signed-on timestamp
 *
 * The PDF is stored at storage/agreements/{unique_id}.pdf and the path is
 * recorded on the contracts row.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/terms-content.php';
require_once __DIR__ . '/pathways_default.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Build the HTML body for the agreement PDF.
 */
function pdp_agreement_html(array $contract, array $option, ?string $pathwaysHtml = null) {
    $signedAt = $contract['agreement_signed_at']
        ?? $contract['updated_at']
        ?? date('Y-m-d H:i:s');
    $signedDisplay = date('F j, Y', strtotime($signedAt));

    $clientName = trim(($contract['first_name'] ?? '') . ' ' . ($contract['last_name'] ?? ''));
    $clientEmail = $contract['email'] ?? '';

    $optionNumber = (int)($option['option_number'] ?? 0);
    $subOptionName = $option['sub_option_name'] ?? '';
    $optionDescription = $option['description'] ?? '';
    $price = number_format((float)($option['price'] ?? 0), 2);
    $type = $option['type'] ?? '';

    $pathways = $pathwaysHtml ?: pdp_resolve_pathways_html($contract);
    $terms = pdp_terms_html();

    ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>LiveWright Personal Development Plan Agreement</title>
<style>
    @page { margin: 60px 50px 60px 50px; }
    body {
        font-family: DejaVu Sans, Arial, sans-serif;
        font-size: 11pt;
        line-height: 1.45;
        color: #1d1d1f;
    }
    h1 { font-size: 20pt; color: #005FA3; margin: 0 0 4px; }
    h2 { font-size: 14pt; color: #005FA3; border-bottom: 1px solid #e5e5ea; padding-bottom: 4px; margin-top: 22px; }
    h3 { font-size: 12pt; color: #005FA3; margin-top: 18px; }
    h4 { font-size: 11pt; margin-top: 14px; margin-bottom: 4px; }
    .header { border-bottom: 2px solid #005FA3; padding-bottom: 12px; margin-bottom: 18px; }
    .meta-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    .meta-table td { padding: 6px 8px; border-bottom: 1px solid #e5e5ea; vertical-align: top; }
    .meta-table td.label { width: 140px; color: #6e6e73; }
    .plan-card {
        background: #f5f7fa;
        border: 1px solid #d8e1ea;
        border-radius: 6px;
        padding: 14px 18px;
        margin: 10px 0 14px;
    }
    .plan-card .price {
        font-size: 16pt;
        color: #005FA3;
        font-weight: 700;
    }
    .footer-sig { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e5e5ea; font-size: 10pt; color: #3a3a3c; }
    p { margin: 6px 0; }
    .terms { font-size: 9.5pt; }
    .terms h3 { page-break-before: auto; }
</style>
</head>
<body>

<div class="header">
    <h1>LiveWright Personal Development Plan</h1>
    <div style="color:#6e6e73; font-size: 10pt;">Signed Agreement</div>
</div>

<table class="meta-table">
    <tr>
        <td class="label">Client</td>
        <td><?= htmlspecialchars($clientName) ?></td>
    </tr>
    <tr>
        <td class="label">Email</td>
        <td><?= htmlspecialchars($clientEmail) ?></td>
    </tr>
    <tr>
        <td class="label">Date Signed</td>
        <td><?= htmlspecialchars($signedDisplay) ?></td>
    </tr>
    <tr>
        <td class="label">Agreement ID</td>
        <td><?= htmlspecialchars($contract['unique_id'] ?? '') ?></td>
    </tr>
</table>

<h2>Selected Plan</h2>
<div class="plan-card">
    <div style="font-weight:600; font-size: 12pt;">
        Option <?= $optionNumber ?>
        <?php if ($subOptionName && $subOptionName !== 'Default'): ?>
            — <?= htmlspecialchars($subOptionName) ?>
        <?php endif; ?>
    </div>
    <div style="margin-top:6px;"><?= $optionDescription /* HTML allowed by admin */ ?></div>
    <div class="price" style="margin-top:10px;">$<?= $price ?> <span style="font-size:11pt; color:#3a3a3c; font-weight:500;">/ <?= htmlspecialchars($type) ?></span></div>
</div>

<?php if (!empty($contract['pdp_from']) || !empty($contract['pdp_toward'])): ?>
<h2>Personal Development Path</h2>
<table class="meta-table">
    <?php if (!empty($contract['pdp_from'])): ?>
    <tr>
        <td class="label">From</td>
        <td><?= $contract['pdp_from'] ?></td>
    </tr>
    <?php endif; ?>
    <?php if (!empty($contract['pdp_toward'])): ?>
    <tr>
        <td class="label">Toward</td>
        <td><?= $contract['pdp_toward'] ?></td>
    </tr>
    <?php endif; ?>
</table>
<?php endif; ?>

<?php if (!empty($pathways)): ?>
<h2>Pathways</h2>
<div><?= $pathways ?></div>
<?php endif; ?>

<h2>Terms of Service &amp; Operating Agreements</h2>
<div class="terms"><?= $terms ?></div>

<div class="footer-sig">
    <p><strong><?= htmlspecialchars($clientName) ?></strong> agreed to the Terms of Service and Operating Agreements above and signed this Personal Development Plan on <?= htmlspecialchars($signedDisplay) ?>.</p>
    <p>This agreement was signed electronically. A copy is retained by LiveWright, LLC.</p>
</div>

</body>
</html>
    <?php
    return ob_get_clean();
}

/**
 * Generate and persist the agreement PDF for a signed contract.
 *
 * @return array ['success' => bool, 'path' => string|null, 'token' => string|null, 'error' => string|null]
 */
function pdp_generate_agreement_pdf(PDO $pdo, array $contract, array $option) {
    try {
        $storageDir = __DIR__ . '/../storage/agreements';
        if (!is_dir($storageDir)) {
            if (!mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
                return ['success' => false, 'path' => null, 'token' => null, 'error' => 'Could not create storage directory'];
            }
        }

        // Stamp signed-at if not already set
        if (empty($contract['agreement_signed_at'])) {
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("UPDATE contracts SET agreement_signed_at = ? WHERE id = ? AND agreement_signed_at IS NULL");
            $stmt->execute([$now, $contract['id']]);
            $contract['agreement_signed_at'] = $now;
        }

        $html = pdp_agreement_html($contract, $option);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $pdfBytes = $dompdf->output();

        $filename = $contract['unique_id'] . '.pdf';
        $path = $storageDir . '/' . $filename;
        if (file_put_contents($path, $pdfBytes) === false) {
            return ['success' => false, 'path' => null, 'token' => null, 'error' => 'Failed to write PDF'];
        }

        // Generate / persist a download token
        $token = $contract['agreement_download_token'] ?? '';
        if (!$token) {
            $token = bin2hex(random_bytes(16));
        }

        $relativePath = 'storage/agreements/' . $filename;
        $stmt = $pdo->prepare("UPDATE contracts SET agreement_pdf_path = ?, agreement_download_token = ? WHERE id = ?");
        $stmt->execute([$relativePath, $token, $contract['id']]);

        return ['success' => true, 'path' => $relativePath, 'token' => $token, 'error' => null];
    } catch (Throwable $e) {
        error_log('pdp_generate_agreement_pdf error: ' . $e->getMessage());
        return ['success' => false, 'path' => null, 'token' => null, 'error' => $e->getMessage()];
    }
}
