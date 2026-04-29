<?php
/**
 * Invoice finalization: generate invoice PDF + send email.
 *
 * Called from the payment-confirmation endpoints right after a successful
 * payment is recorded. Failures log but never throw.
 */

require_once __DIR__ . '/invoice-pdf.php';
require_once __DIR__ . '/invoice-email.php';

/**
 * @return array ['pdf' => array, 'email' => array]
 */
function pdp_finalize_invoice(PDO $pdo, int $paymentId) {
    $result = ['pdf' => null, 'email' => null];

    try {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            $result['pdf'] = ['success' => false, 'error' => 'Payment not found'];
            return $result;
        }

        $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
        $stmt->execute([$payment['contract_id']]);
        $contract = $stmt->fetch();
        if (!$contract) {
            $result['pdf'] = ['success' => false, 'error' => 'Contract not found'];
            return $result;
        }

        $stmt = $pdo->prepare("SELECT * FROM pricing_options WHERE id = ?");
        $stmt->execute([$payment['pricing_option_id']]);
        $option = $stmt->fetch();
        if (!$option) {
            $result['pdf'] = ['success' => false, 'error' => 'Pricing option not found'];
            return $result;
        }

        // 1) Generate invoice PDF
        $pdfResult = pdp_generate_invoice_pdf($pdo, $contract, $option, $payment);
        $result['pdf'] = $pdfResult;
        if (!$pdfResult['success']) {
            error_log('pdp_finalize_invoice: PDF failed: ' . ($pdfResult['error'] ?? 'unknown'));
            return $result;
        }

        // Refresh payment row to capture invoice columns
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();

        // 2) Send invoice email
        $downloadUrl = pdp_invoice_download_url(
            $payment['invoice_number'],
            $payment['invoice_download_token']
        );
        $emailResult = pdp_send_invoice_email($contract, $option, $payment, $payment['invoice_number'], $downloadUrl);
        $result['email'] = $emailResult;

        if ($emailResult['success']) {
            $stmt = $pdo->prepare("UPDATE payments SET invoice_email_sent_at = NOW(), invoice_email_recipient = ? WHERE id = ?");
            $stmt->execute([$emailResult['recipient'], $paymentId]);
        } else {
            error_log('pdp_finalize_invoice: email failed: ' . ($emailResult['error'] ?? 'unknown'));
        }
    } catch (Throwable $e) {
        error_log('pdp_finalize_invoice exception: ' . $e->getMessage());
        $result['pdf'] = $result['pdf'] ?? ['success' => false, 'error' => $e->getMessage()];
    }

    return $result;
}
