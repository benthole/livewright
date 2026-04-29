<?php
/**
 * Agreement finalization: generate PDF, store, send confirmation email.
 *
 * Designed to be called from the payment-confirmation endpoints
 * (api/confirm-payment.php and api/keap-charge.php) right after a contract
 * has been marked signed. Failures are logged but never thrown — the
 * payment success response should not be blocked by email/PDF issues.
 */

require_once __DIR__ . '/agreement-pdf.php';
require_once __DIR__ . '/agreement-email.php';

/**
 * Finalize a signed agreement: generate PDF + send confirmation email.
 *
 * @return array ['pdf' => array, 'email' => array]
 */
function pdp_finalize_agreement(PDO $pdo, int $contractId) {
    $result = ['pdf' => null, 'email' => null];

    try {
        $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$contractId]);
        $contract = $stmt->fetch();
        if (!$contract) {
            $result['pdf'] = ['success' => false, 'error' => 'Contract not found'];
            return $result;
        }

        if (empty($contract['selected_option_id'])) {
            $result['pdf'] = ['success' => false, 'error' => 'No selected option on contract'];
            return $result;
        }

        $stmt = $pdo->prepare("SELECT * FROM pricing_options WHERE id = ?");
        $stmt->execute([$contract['selected_option_id']]);
        $option = $stmt->fetch();
        if (!$option) {
            $result['pdf'] = ['success' => false, 'error' => 'Selected pricing option not found'];
            return $result;
        }

        // 1) Generate the PDF
        $pdfResult = pdp_generate_agreement_pdf($pdo, $contract, $option);
        $result['pdf'] = $pdfResult;
        if (!$pdfResult['success']) {
            error_log('pdp_finalize_agreement: PDF failed: ' . ($pdfResult['error'] ?? 'unknown'));
            return $result;
        }

        // Refresh contract with the PDF columns just written
        $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
        $stmt->execute([$contractId]);
        $contract = $stmt->fetch();

        // 2) Send the email
        $downloadUrl = pdp_agreement_download_url(
            $contract['unique_id'],
            $contract['agreement_download_token']
        );
        $emailResult = pdp_send_agreement_email($contract, $option, $downloadUrl);
        $result['email'] = $emailResult;

        if ($emailResult['success']) {
            $stmt = $pdo->prepare("UPDATE contracts SET agreement_email_sent_at = NOW(), agreement_email_recipient = ? WHERE id = ?");
            $stmt->execute([$emailResult['recipient'], $contractId]);
        } else {
            error_log('pdp_finalize_agreement: email failed: ' . ($emailResult['error'] ?? 'unknown'));
        }
    } catch (Throwable $e) {
        error_log('pdp_finalize_agreement exception: ' . $e->getMessage());
        $result['pdf'] = $result['pdf'] ?? ['success' => false, 'error' => $e->getMessage()];
    }

    return $result;
}
