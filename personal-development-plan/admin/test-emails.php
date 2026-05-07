<?php
/**
 * Admin-only utility to fire test sends of the agreement + invoice emails
 * using real PDP / payment data.
 *
 * The existing test override (PDP_AGREEMENT_EMAIL_TEST_TO = 'benthole@gmail.com')
 * routes both emails to that inbox regardless of the actual contract email,
 * so this is safe to run against real signed contracts.
 *
 * URL:  /personal-development-plan/admin/test-emails.php
 */

require_once '../config.php';
require_once __DIR__ . '/../lib/agreement-pdf.php';
require_once __DIR__ . '/../lib/agreement-email.php';
require_once __DIR__ . '/../lib/invoice-pdf.php';
require_once __DIR__ . '/../lib/invoice-email.php';
requireLogin();

$flash = '';
$flashType = '';
$logLines = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contractId = (int)($_POST['contract_id'] ?? 0);
    $paymentId  = (int)($_POST['payment_id'] ?? 0);

    if ($contractId <= 0) {
        $flash = 'Pick a contract first.';
        $flashType = 'error';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
        $stmt->execute([$contractId]);
        $contract = $stmt->fetch();

        if (!$contract) {
            $flash = 'Contract not found.';
            $flashType = 'error';
        } else {
            // Use the contract's selected option (or its first option if missing).
            $option = null;
            if (!empty($contract['selected_option_id'])) {
                $oStmt = $pdo->prepare("SELECT * FROM pricing_options WHERE id = ?");
                $oStmt->execute([$contract['selected_option_id']]);
                $option = $oStmt->fetch();
            }
            if (!$option) {
                $oStmt = $pdo->prepare("SELECT * FROM pricing_options WHERE contract_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1");
                $oStmt->execute([$contractId]);
                $option = $oStmt->fetch();
            }

            $payment = null;
            if ($paymentId > 0) {
                $pStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND contract_id = ?");
                $pStmt->execute([$paymentId, $contractId]);
                $payment = $pStmt->fetch();
            }
            if (!$payment) {
                $pStmt = $pdo->prepare("SELECT * FROM payments WHERE contract_id = ? ORDER BY created_at DESC LIMIT 1");
                $pStmt->execute([$contractId]);
                $payment = $pStmt->fetch();
            }

            // ---- Agreement email ----
            if ($option) {
                // Regenerate the PDF (so the latest formatting + logo lands).
                $agRes = pdp_generate_agreement_pdf($pdo, $contract, $option);
                if ($agRes['success']) {
                    $logLines[] = 'Agreement PDF regenerated: ' . $agRes['path'];
                    // Reload contract to pick up the new download token + path.
                    $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
                    $stmt->execute([$contractId]);
                    $contract = $stmt->fetch();
                    $url = pdp_agreement_download_url($contract['unique_id'], $contract['agreement_download_token']);
                    $sendRes = pdp_send_agreement_email($contract, $option, $url);
                    if ($sendRes['success']) {
                        $logLines[] = 'Agreement email sent → ' . $sendRes['recipient'];
                    } else {
                        $logLines[] = 'Agreement email FAILED: ' . ($sendRes['error'] ?? 'unknown');
                    }
                } else {
                    $logLines[] = 'Agreement PDF generation failed: ' . ($agRes['error'] ?? 'unknown');
                }
            } else {
                $logLines[] = 'No pricing_option found for this contract — skipping agreement email.';
            }

            // ---- Invoice email ----
            if ($option && $payment) {
                $invRes = pdp_generate_invoice_pdf($pdo, $contract, $option, $payment);
                if ($invRes['success']) {
                    $logLines[] = 'Invoice PDF regenerated: ' . $invRes['path'] . ' (#' . $invRes['invoice_number'] . ')';
                    // Reload payment for the fresh token/number.
                    $pStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
                    $pStmt->execute([$payment['id']]);
                    $payment = $pStmt->fetch();
                    $invUrl = pdp_invoice_download_url($payment['invoice_number'], $payment['invoice_download_token']);
                    $sendRes = pdp_send_invoice_email($contract, $option, $payment, $payment['invoice_number'], $invUrl);
                    if ($sendRes['success']) {
                        $logLines[] = 'Invoice email sent → ' . $sendRes['recipient'];
                    } else {
                        $logLines[] = 'Invoice email FAILED: ' . ($sendRes['error'] ?? 'unknown');
                    }
                } else {
                    $logLines[] = 'Invoice PDF generation failed: ' . ($invRes['error'] ?? 'unknown');
                }
            } else {
                $logLines[] = 'No payment record — skipping invoice email.';
            }

            $flash = 'Done. Both emails (when applicable) routed to PDP_AGREEMENT_EMAIL_TEST_TO ('
                . PDP_AGREEMENT_EMAIL_TEST_TO . ').';
            $flashType = 'success';
        }
    }
}

// Recent contracts that have something interesting to test with.
$recent = $pdo->query("
    SELECT c.id, c.unique_id, c.first_name, c.last_name, c.email, c.signed, c.updated_at,
           (SELECT COUNT(*) FROM payments p WHERE p.contract_id = c.id) AS payment_count,
           (SELECT MAX(p.id) FROM payments p WHERE p.contract_id = c.id) AS last_payment_id
    FROM contracts c
    WHERE c.deleted_at IS NULL AND c.signed = 1
    ORDER BY c.updated_at DESC
    LIMIT 25
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Test Emails';
require_once 'includes/header.php';
?>
<div class="admin-content">
    <div class="page-header">
        <h1>Send Test Emails</h1>
        <a href="index.php" class="btn btn-secondary">Back to plans</a>
    </div>

    <p style="color: #6e6e73;">Picks a signed PDP, regenerates both PDFs (agreement + invoice), and fires the two
        Keap emails. The <code>PDP_AGREEMENT_EMAIL_TEST_TO</code> override routes them to
        <strong><?= htmlspecialchars(PDP_AGREEMENT_EMAIL_TEST_TO) ?></strong>.</p>

    <?php if ($flash): ?>
        <div class="<?= $flashType === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php if (!empty($logLines)): ?>
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; margin-bottom: 18px;">
            <strong>Run log:</strong>
            <ul style="margin: 8px 0 0 18px;">
                <?php foreach ($logLines as $line): ?>
                    <li style="font-family: monospace; font-size: 13px;"><?= htmlspecialchars($line) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
        <div class="form-group">
            <label>Contract:</label>
            <select name="contract_id" required>
                <option value="">— Choose a signed plan —</option>
                <?php foreach ($recent as $r): ?>
                    <option value="<?= (int)$r['id'] ?>" data-payment="<?= (int)($r['last_payment_id'] ?? 0) ?>">
                        #<?= (int)$r['id'] ?>
                        — <?= htmlspecialchars(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?>
                        &lt;<?= htmlspecialchars($r['email'] ?? '') ?>&gt;
                        — payments: <?= (int)$r['payment_count'] ?>
                        — updated <?= date('M j, Y', strtotime($r['updated_at'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Payment ID (optional — defaults to most recent):</label>
            <input type="number" name="payment_id" placeholder="leave blank for latest">
        </div>
        <button type="submit" class="btn">Send test emails</button>
    </form>
</div>

<?php require_once 'includes/footer.php'; ?>
