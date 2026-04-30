<?php
require_once 'config.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

$payment_option_id = $_POST['payment_option'] ?? '';
$contract_uid = $_POST['contract_uid'] ?? '';
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');

$error = '';
$contract = null;
$selected_option = null;

// Validate required fields
if (empty($payment_option_id) || empty($contract_uid) || empty($first_name) || empty($last_name) || empty($email)) {
    $error = 'Missing required information.';
} else {
    // Get contract
    $stmt = $pdo->prepare("SELECT * FROM contracts WHERE unique_id = ? AND deleted_at IS NULL");
    $stmt->execute([$contract_uid]);
    $contract = $stmt->fetch();

    if (!$contract) {
        $error = 'Personal Development Plan not found.';
    } else {
        // Get selected pricing option
        $stmt = $pdo->prepare("SELECT * FROM pricing_options WHERE id = ? AND contract_id = ? AND deleted_at IS NULL");
        $stmt->execute([$payment_option_id, $contract['id']]);
        $selected_option = $stmt->fetch();

        if (!$selected_option) {
            $error = 'Invalid payment option selected.';
        }
    }
}

// Determine payment details
$plan_price = (float)($selected_option['price'] ?? 0);
$plan_type = $selected_option['type'] ?? 'Monthly';

// Friendly type labels
$type_labels = [
    'Yearly' => 'one-time payment for 12 months of coaching',
    'Monthly' => 'monthly for 12 months',
    'Quarterly' => 'quarterly over the next 12 months',
];
$friendly_type = $type_labels[$plan_type] ?? strtolower($plan_type);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Confirm Your Selection - Personal Development Plan</title>
    <script src="https://payments.keap.page/lib/payment-method-embed.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f8f9fa; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: white; padding: 30px; border-radius: 8px 8px 0 0; text-align: center; border-bottom: 3px solid #2BB5B0; }
        .header img.logo { width: 200px; height: auto; margin-bottom: 15px; }
        .header h1 { color: #005FA3; margin: 0 0 5px 0; }
        .header p { color: #555; }
        .content { padding: 30px; }
        .error { background: #f8d7da; color: #721c24; padding: 20px; border-radius: 4px; margin: 20px; text-align: center; }
        .success { background: #d4edda; color: #155724; padding: 20px; border-radius: 4px; margin: 20px; text-align: center; }
        .plan-details { background: #f8f9fa; padding: 20px; border-radius: 4px; margin-bottom: 30px; }
        .plan-details h3 { margin-top: 0; color: #005FA3; }
        .price-highlight { font-size: 1.5em; font-weight: bold; color: #28a745; margin: 10px 0; }
        .client-info { background: #fff; border: 1px solid #ddd; padding: 20px; border-radius: 4px; margin-bottom: 20px; }
        .back-btn { background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 4px; text-decoration: none; display: inline-block; margin-right: 10px; }
        .back-btn:hover { background: #5a6268; color: white; }
        .button-group { display: flex; gap: 10px; margin-top: 20px; }
        .success-message { text-align: center; }
        .success-message h2 { color: #28a745; }

        /* Payment Option Toggle */
        .payment-toggle { background: #f8f9fa; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .payment-toggle h3 { margin-top: 0; color: #333; margin-bottom: 15px; }
        .toggle-option { display: flex; align-items: flex-start; gap: 12px; padding: 15px; border: 2px solid #ddd; border-radius: 6px; margin-bottom: 10px; cursor: pointer; transition: all 0.2s; }
        .toggle-option:hover { border-color: #005FA3; background: #f0f8ff; }
        .toggle-option.active { border-color: #28a745; background: #f0fff4; }
        .toggle-option input[type="radio"] { margin-top: 3px; width: 18px; height: 18px; cursor: pointer; }
        .toggle-option .option-details { flex: 1; }
        .toggle-option .option-label { font-weight: bold; color: #333; font-size: 1.05em; }
        .toggle-option .option-sublabel { color: #666; font-size: 0.9em; margin-top: 4px; }
        .toggle-option .option-price { font-weight: bold; color: #28a745; font-size: 1.1em; white-space: nowrap; }

        /* Terms of Service Modal */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); z-index: 1000; overflow-y: auto; padding: 20px; box-sizing: border-box; }
        .modal-overlay.active { display: flex; justify-content: center; align-items: flex-start; }
        .modal-content { background: white; border-radius: 8px; max-width: 800px; width: 100%; max-height: 90vh; overflow-y: auto; position: relative; margin: 20px auto; }
        .modal-header { background: #005FA3; color: white; padding: 20px 30px; border-radius: 8px 8px 0 0; position: sticky; top: 0; z-index: 1; }
        .modal-header h2 { margin: 0; }
        .modal-close { position: absolute; right: 15px; top: 15px; background: none; border: none; color: white; font-size: 28px; cursor: pointer; line-height: 1; }
        .modal-close:hover { opacity: 0.8; }
        .modal-body { padding: 30px; font-size: 14px; line-height: 1.6; }
        .modal-body h3 { color: #005FA3; margin-top: 30px; border-bottom: 2px solid #005FA3; padding-bottom: 10px; }
        .modal-body h3:first-child { margin-top: 0; }
        .modal-body h4 { color: #333; margin-top: 20px; }
        .modal-body ul { margin: 10px 0; padding-left: 25px; }
        .modal-body li { margin-bottom: 8px; }
        .modal-body p { margin: 10px 0; }
        .modal-footer { padding: 20px 30px; border-top: 1px solid #ddd; text-align: center; position: sticky; bottom: 0; background: white; }
        .modal-footer button { background: #005FA3; color: white; padding: 12px 30px; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; }
        .modal-footer button:hover { background: #006399; }

        /* Terms checkbox */
        .terms-agreement { background: #f8f9fa; padding: 15px 20px; border-radius: 4px; margin: 20px 0; border: 1px solid #ddd; }
        .terms-agreement label { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; }
        .terms-agreement input[type="checkbox"] { margin-top: 3px; width: 18px; height: 18px; }
        .terms-link { color: #005FA3; text-decoration: underline; cursor: pointer; }
        .terms-link:hover { color: #005a8c; }

        /* Payment Section */
        .payment-section { background: #f0f7ff; border: 2px solid #005FA3; border-radius: 8px; padding: 25px; margin: 20px 0; }
        .payment-section h3 { margin-top: 0; color: #005FA3; }
        .payment-summary { background: white; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .payment-summary .line-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .payment-summary .line-item:last-child { border-bottom: none; }
        .payment-summary .line-item .label { color: #666; }
        .payment-summary .line-item .amount { font-weight: bold; }
        .payment-summary .line-item.total { border-top: 2px solid #005FA3; padding-top: 12px; margin-top: 5px; }
        .payment-summary .line-item.total .amount { color: #28a745; font-size: 1.2em; }
        .payment-summary .line-item.recurring { color: #666; font-size: 0.9em; }

        #keap-payment-container { min-height: 80px; padding: 12px; border: 1px solid #ddd; border-radius: 4px; background: white; }
        #payment-errors { color: #dc3545; margin-top: 10px; font-size: 0.9em; }
        .pay-btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 4px; font-size: 18px; font-weight: bold; cursor: pointer; width: 100%; margin-top: 15px; }
        .pay-btn:hover { background: #218838; }
        .pay-btn:disabled { background: #ccc; cursor: not-allowed; }
        .pay-btn .spinner { display: none; }
        .pay-btn.processing .spinner { display: inline-block; }
        .pay-btn.processing .btn-text { display: none; }
        .spinner { width: 20px; height: 20px; border: 3px solid rgba(255,255,255,0.3); border-top: 3px solid white; border-radius: 50%; animation: spin 1s linear infinite; display: inline-block; vertical-align: middle; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .secure-badge { text-align: center; margin-top: 15px; color: #666; font-size: 0.85em; }
        .secure-badge svg { vertical-align: middle; margin-right: 5px; }

        .loading-indicator { text-align: center; padding: 20px; color: #666; }
        .loading-indicator .spinner { border: 3px solid #ddd; border-top: 3px solid #005FA3; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="https://cdn.shortpixel.ai/spai/q_lossy+ret_img+to_webp/livewright.com/wp-content/uploads/2023/12/LiveWright-logo%E2%80%934C-padded.png" alt="LiveWright" class="logo">
            <h1>Confirm Your Selection</h1>
            <?php if ($contract): ?>
                <p>Personal Development Plan</p>
            <?php endif; ?>
        </div>

        <?php if (isset($error) && $error): ?>
            <div class="error">
                <h3>Error</h3>
                <p><?= htmlspecialchars($error) ?></p>
                <a href="/" class="back-btn">Go Back</a>
            </div>

        <?php else: ?>
            <div class="content" id="review-section">
                <h2>Review Your Selection</h2>

                <div class="plan-details">
                    <h3>Selected Plan</h3>
                    <div><?= $selected_option['description'] ?></div>
                    <?php if ($selected_option['sub_option_name'] !== 'Default'): ?>
                        <p><strong>Selected:</strong> <?= htmlspecialchars($selected_option['sub_option_name']) ?></p>
                    <?php endif; ?>
                    <div class="price-highlight">
                        $<?= number_format($selected_option['price'], 2) ?> — <?= $friendly_type ?>
                    </div>
                </div>

                <div class="client-info">
                    <h4>Your Information</h4>
                    <p><strong>Name:</strong> <?= htmlspecialchars($first_name . ' ' . $last_name) ?></p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($email) ?></p>
                </div>

                <?php if (!empty($contract['contract_description'])): ?>
                    <div class="plan-details">
                        <h4>What's Included</h4>
                        <div><?= $contract['contract_description'] ?></div>
                    </div>
                <?php endif; ?>

                <!-- Payment Section -->
                <div class="payment-section" id="paymentSection">
                    <h3>Payment</h3>

                    <div class="payment-summary" id="paymentSummary">
                        <div class="line-item">
                            <span class="label" id="summaryLabel">Pay in Full</span>
                            <span class="amount" id="summaryAmount">$<?= number_format($plan_price, 2) ?></span>
                        </div>
                        <div class="line-item total">
                            <span class="label">Due Today</span>
                            <span class="amount" id="summaryTotal">$<?= number_format($plan_price, 2) ?></span>
                        </div>
                    </div>

                    <div class="loading-indicator" id="widgetLoading">
                        <span class="spinner"></span>
                        <p>Loading secure payment form...</p>
                    </div>

                    <label style="display: none; margin-bottom: 8px; font-weight: bold;" id="cardLabel">Card Details</label>
                    <div id="keap-payment-container" style="display: none;"></div>
                    <div id="payment-errors" role="alert"></div>

                    <button type="button" class="pay-btn" id="payBtn" disabled onclick="handlePayment()">
                        <span class="btn-text" id="payBtnText">Pay $<?= number_format($plan_price, 2) ?> & Sign Plan</span>
                        <span class="spinner"></span>
                    </button>

                    <div class="secure-badge">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        Payments are securely processed
                    </div>
                </div>

                <div class="button-group">
                    <a href="/?uid=<?= urlencode($contract_uid) ?>" class="back-btn">&larr; Go Back</a>
                </div>
            </div>

            <!-- Success Section (hidden by default) -->
            <div class="content" id="success-section" style="display: none;">
                <div class="success-message">
                    <h2>&#10003; Personal Development Plan Signed Successfully!</h2>
                    <p>Thank you for choosing your Personal Development Plan.</p>

                    <div class="plan-details">
                        <h3>Your Selected Plan</h3>
                        <div><?= $selected_option['description'] ?></div>
                        <?php if ($selected_option['sub_option_name'] !== 'Default'): ?>
                            <p><strong>Selected:</strong> <?= htmlspecialchars($selected_option['sub_option_name']) ?></p>
                        <?php endif; ?>
                        <div class="price-highlight">
                            $<?= number_format($selected_option['price'], 2) ?> — <?= $friendly_type ?>
                        </div>
                    </div>

                    <div class="client-info">
                        <h4>Plan Information</h4>
                        <p><strong>Name:</strong> <?= htmlspecialchars($first_name . ' ' . $last_name) ?></p>
                        <p><strong>Email:</strong> <?= htmlspecialchars($email) ?></p>
                        <p><strong>Plan ID:</strong> <?= htmlspecialchars($contract_uid) ?></p>
                        <p><strong>Date:</strong> <?= date('F j, Y') ?></p>
                    </div>

                    <p>You will receive a confirmation email shortly with your plan details and next steps.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>


    <script>
    // --- Config from PHP ---
    const CONFIG = {
        contractUid: '<?= addslashes($contract_uid) ?>',
        pricingOptionId: <?= (int)$payment_option_id ?>,
        firstName: '<?= addslashes($first_name) ?>',
        lastName: '<?= addslashes($last_name) ?>',
        email: '<?= addslashes($email) ?>',
        planPrice: <?= $plan_price ?>,
        planDescription: '<?= addslashes($selected_option['description'] ?? '') ?>'
    };

    let sessionKey = null;
    let keapContactId = null;
    let widgetReady = false;
    let processing = false;

    function getChargeAmount() {
        return CONFIG.planPrice;
    }

    // --- Keap Payment Integration ---
    <?php if (!$error): ?>

    // Initialize Keap session as soon as the page is ready
    document.addEventListener('DOMContentLoaded', function() {
        if (!sessionKey) {
            initKeapSession();
        }
    });

    async function initKeapSession() {
        document.getElementById('widgetLoading').style.display = 'block';
        document.getElementById('payment-errors').textContent = '';

        try {
            const response = await fetch('api/keap-session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    contract_uid: CONFIG.contractUid,
                    first_name: CONFIG.firstName,
                    last_name: CONFIG.lastName,
                    email: CONFIG.email
                })
            });

            const data = await response.json();

            if (data.error) {
                throw new Error(data.error);
            }

            sessionKey = data.session_key;
            keapContactId = data.contact_id;

            // Render Keap payment widget
            renderKeapWidget(sessionKey);

        } catch (err) {
            document.getElementById('widgetLoading').style.display = 'none';
            document.getElementById('payment-errors').textContent = 'Failed to load payment form: ' + err.message;
        }
    }

    function renderKeapWidget(key) {
        const container = document.getElementById('keap-payment-container');
        container.innerHTML = '';

        const widget = document.createElement('keap-payment-method');
        widget.setAttribute('data-key', key);
        container.appendChild(widget);

        // Listen for widget ready event (Keap sends { type: 'readyForStyles' })
        window.addEventListener('message', function handler(event) {
            if (event.data && (event.data.type === 'readyForStyles' || event.data.type === 'keap-payment-method-ready')) {
                widgetReady = true;
                document.getElementById('widgetLoading').style.display = 'none';
                document.getElementById('cardLabel').style.display = 'block';
                container.style.display = 'block';
                document.getElementById('payBtn').disabled = false;
            }
            if (event.data && event.data.error) {
                var errMsg = (typeof event.data.error === 'object') ? event.data.error.message : event.data.error;
                if (errMsg) document.getElementById('payment-errors').textContent = errMsg;
            }
        });

        // Fallback: show widget after a timeout even if no ready event
        setTimeout(function() {
            if (!widgetReady) {
                document.getElementById('widgetLoading').style.display = 'none';
                document.getElementById('cardLabel').style.display = 'block';
                container.style.display = 'block';
                document.getElementById('payBtn').disabled = false;
                widgetReady = true;
            }
        }, 5000);
    }

    async function handlePayment() {
        if (processing) return;
        processing = true;

        const payBtn = document.getElementById('payBtn');
        payBtn.disabled = true;
        payBtn.classList.add('processing');
        document.getElementById('payment-errors').textContent = '';

        try {
            // Get payment method from Keap widget
            const paymentMethodId = await getKeapPaymentMethod();

            if (!paymentMethodId) {
                throw new Error('Please enter your card details');
            }

            // Send charge request to our backend
            const chargeResponse = await fetch('api/keap-charge.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    contract_uid: CONFIG.contractUid,
                    pricing_option_id: CONFIG.pricingOptionId,
                    contact_id: keapContactId,
                    payment_method_id: paymentMethodId,
                    amount: getChargeAmount(),
                    is_deposit: false,
                    plan_description: CONFIG.planDescription
                })
            });

            const chargeData = await chargeResponse.json();

            if (chargeData.error) {
                throw new Error(chargeData.error);
            }

            // Show success
            document.getElementById('review-section').style.display = 'none';
            document.getElementById('success-section').style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });

        } catch (err) {
            document.getElementById('payment-errors').textContent = err.message;
            payBtn.disabled = false;
            payBtn.classList.remove('processing');
            processing = false;
        }
    }

    function getKeapPaymentMethod() {
        return new Promise(function(resolve, reject) {
            const widget = document.querySelector('keap-payment-method');
            if (!widget) {
                reject(new Error('Payment widget not loaded'));
                return;
            }

            var timeoutId;

            // Listen for the response
            function handler(event) {
                if (!event.data || typeof event.data !== 'object') return;

                // Keap widget returns { success, paymentMethodId, creditCardId } or { error }
                if (event.data.error) {
                    clearTimeout(timeoutId);
                    window.removeEventListener('message', handler);
                    var errMsg = (typeof event.data.error === 'object') ? event.data.error.message : event.data.error;
                    reject(new Error(errMsg || 'Failed to process card'));
                } else if (event.data.success) {
                    clearTimeout(timeoutId);
                    window.removeEventListener('message', handler);
                    var id = event.data.creditCardId || event.data.paymentMethodId;
                    if (id) {
                        resolve(id);
                    } else {
                        reject(new Error('No payment method ID returned'));
                    }
                }
            }
            window.addEventListener('message', handler);

            // Trigger submit on the widget
            if (typeof widget.submit === 'function') {
                widget.submit();
            } else {
                // Post message to widget iframe
                widget.querySelector('iframe')?.contentWindow?.postMessage({ type: 'submit' }, '*');
            }

            // Timeout after 30 seconds
            timeoutId = setTimeout(function() {
                window.removeEventListener('message', handler);
                reject(new Error('Payment processing timed out. Please try again.'));
            }, 30000);
        });
    }

    <?php endif; ?>
    </script>
</body>
</html>
