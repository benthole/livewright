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
$payment_mode = $selected_option['payment_mode'] ?? 'recurring_immediate';
$deposit_amount = (float)($selected_option['deposit_amount'] ?? 0);
$plan_price = (float)($selected_option['price'] ?? 0);
$plan_type = $selected_option['type'] ?? 'Monthly';

// Calculate what to charge now
if ($payment_mode === 'deposit_only') {
    $charge_now = $deposit_amount > 0 ? $deposit_amount : $plan_price;
    $charge_label = 'One-time deposit';
} elseif ($payment_mode === 'deposit_and_recurring') {
    $charge_now = $deposit_amount > 0 ? $deposit_amount : $plan_price;
    $charge_label = 'Deposit today';
} else {
    // recurring_immediate
    $charge_now = $plan_price;
    $charge_label = 'First ' . strtolower($plan_type) . ' payment';
}

$stripe_publishable_key = STRIPE_PUBLISHABLE_KEY;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Confirm Your Selection - Personal Development Plan</title>
    <script src="https://js.stripe.com/v3/"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f8f9fa; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #007cba; color: white; padding: 30px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { padding: 30px; }
        .error { background: #f8d7da; color: #721c24; padding: 20px; border-radius: 4px; margin: 20px; text-align: center; }
        .success { background: #d4edda; color: #155724; padding: 20px; border-radius: 4px; margin: 20px; text-align: center; }
        .plan-details { background: #f8f9fa; padding: 20px; border-radius: 4px; margin-bottom: 30px; }
        .plan-details h3 { margin-top: 0; color: #007cba; }
        .price-highlight { font-size: 1.5em; font-weight: bold; color: #28a745; margin: 10px 0; }
        .client-info { background: #fff; border: 1px solid #ddd; padding: 20px; border-radius: 4px; margin-bottom: 20px; }
        .back-btn { background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 4px; text-decoration: none; display: inline-block; margin-right: 10px; }
        .back-btn:hover { background: #5a6268; color: white; }
        .button-group { display: flex; gap: 10px; margin-top: 20px; }
        .success-message { text-align: center; }
        .success-message h2 { color: #28a745; }

        /* Terms of Service Modal */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); z-index: 1000; overflow-y: auto; padding: 20px; box-sizing: border-box; }
        .modal-overlay.active { display: flex; justify-content: center; align-items: flex-start; }
        .modal-content { background: white; border-radius: 8px; max-width: 800px; width: 100%; max-height: 90vh; overflow-y: auto; position: relative; margin: 20px auto; }
        .modal-header { background: #007cba; color: white; padding: 20px 30px; border-radius: 8px 8px 0 0; position: sticky; top: 0; z-index: 1; }
        .modal-header h2 { margin: 0; }
        .modal-close { position: absolute; right: 15px; top: 15px; background: none; border: none; color: white; font-size: 28px; cursor: pointer; line-height: 1; }
        .modal-close:hover { opacity: 0.8; }
        .modal-body { padding: 30px; font-size: 14px; line-height: 1.6; }
        .modal-body h3 { color: #007cba; margin-top: 30px; border-bottom: 2px solid #007cba; padding-bottom: 10px; }
        .modal-body h3:first-child { margin-top: 0; }
        .modal-body h4 { color: #333; margin-top: 20px; }
        .modal-body ul { margin: 10px 0; padding-left: 25px; }
        .modal-body li { margin-bottom: 8px; }
        .modal-body p { margin: 10px 0; }
        .modal-footer { padding: 20px 30px; border-top: 1px solid #ddd; text-align: center; position: sticky; bottom: 0; background: white; }
        .modal-footer button { background: #007cba; color: white; padding: 12px 30px; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; }
        .modal-footer button:hover { background: #006399; }

        /* Terms checkbox */
        .terms-agreement { background: #f8f9fa; padding: 15px 20px; border-radius: 4px; margin: 20px 0; border: 1px solid #ddd; }
        .terms-agreement label { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; }
        .terms-agreement input[type="checkbox"] { margin-top: 3px; width: 18px; height: 18px; }
        .terms-link { color: #007cba; text-decoration: underline; cursor: pointer; }
        .terms-link:hover { color: #005a8c; }

        /* Stripe Payment Section */
        .payment-section { background: #f0f7ff; border: 2px solid #007cba; border-radius: 8px; padding: 25px; margin: 20px 0; }
        .payment-section h3 { margin-top: 0; color: #007cba; }
        .payment-summary { background: white; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .payment-summary .line-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .payment-summary .line-item:last-child { border-bottom: none; }
        .payment-summary .line-item .label { color: #666; }
        .payment-summary .line-item .amount { font-weight: bold; }
        .payment-summary .line-item.total { border-top: 2px solid #007cba; padding-top: 12px; margin-top: 5px; }
        .payment-summary .line-item.total .amount { color: #28a745; font-size: 1.2em; }
        .payment-summary .line-item.recurring { color: #666; font-size: 0.9em; }

        #card-element { padding: 12px; border: 1px solid #ddd; border-radius: 4px; background: white; }
        #card-errors { color: #dc3545; margin-top: 10px; font-size: 0.9em; }
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
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
                        $<?= number_format($selected_option['price'], 2) ?> <?= strtolower($selected_option['type']) ?>
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

                <div class="terms-agreement">
                    <label>
                        <input type="checkbox" id="termsCheckbox">
                        <span>I have read and agree to the <a href="#" class="terms-link" onclick="openTermsModal(); return false;">Terms of Service and Operating Agreements</a></span>
                    </label>
                </div>

                <!-- Payment Section (shown after terms agreement) -->
                <div class="payment-section" id="paymentSection" style="display: none;">
                    <h3>Payment</h3>

                    <div class="payment-summary">
                        <div class="line-item">
                            <span class="label"><?= htmlspecialchars($charge_label) ?></span>
                            <span class="amount">$<?= number_format($charge_now, 2) ?></span>
                        </div>
                        <?php if ($payment_mode === 'deposit_and_recurring'): ?>
                            <div class="line-item recurring">
                                <span class="label">Then $<?= number_format($plan_price, 2) ?>/<?= strtolower($plan_type) ?> recurring</span>
                                <span class="amount"></span>
                            </div>
                        <?php elseif ($payment_mode === 'recurring_immediate'): ?>
                            <div class="line-item recurring">
                                <span class="label">Recurring <?= strtolower($plan_type) ?></span>
                                <span class="amount"></span>
                            </div>
                        <?php endif; ?>
                        <div class="line-item total">
                            <span class="label">Due Today</span>
                            <span class="amount">$<?= number_format($charge_now, 2) ?></span>
                        </div>
                    </div>

                    <label style="display: block; margin-bottom: 8px; font-weight: bold;">Card Details</label>
                    <div id="card-element"></div>
                    <div id="card-errors" role="alert"></div>

                    <button type="button" class="pay-btn" id="payBtn" disabled onclick="handlePayment()">
                        <span class="btn-text">Pay $<?= number_format($charge_now, 2) ?> & Sign Plan</span>
                        <span class="spinner"></span>
                    </button>

                    <div class="secure-badge">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        Payments are securely processed by Stripe
                    </div>
                </div>

                <div class="button-group">
                    <a href="/?uid=<?= urlencode($contract_uid) ?>" class="back-btn">← Go Back</a>
                </div>
            </div>

            <!-- Success Section (hidden by default) -->
            <div class="content" id="success-section" style="display: none;">
                <div class="success-message">
                    <h2>✓ Personal Development Plan Signed Successfully!</h2>
                    <p>Thank you for choosing your Personal Development Plan.</p>

                    <div class="plan-details">
                        <h3>Your Selected Plan</h3>
                        <div><?= $selected_option['description'] ?></div>
                        <?php if ($selected_option['sub_option_name'] !== 'Default'): ?>
                            <p><strong>Selected:</strong> <?= htmlspecialchars($selected_option['sub_option_name']) ?></p>
                        <?php endif; ?>
                        <div class="price-highlight">
                            $<?= number_format($selected_option['price'], 2) ?> <?= strtolower($selected_option['type']) ?>
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

    <!-- Terms of Service Modal -->
    <div id="termsModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Terms of Service & Operating Agreements</h2>
                <button class="modal-close" onclick="closeTermsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <h3>Operating Agreements</h3>
                <p>Welcome! These agreements are here to help you get the most out of your experience in this program and support your growth in every area of your life. By joining this program, you're agreeing to honor these guidelines. If someone is not able to follow them, we'll address it together — and ongoing issues may lead to being asked to leave the program.</p>
                <p><strong>Here's what we're asking of you:</strong></p>

                <h4>Show Up Fully</h4>
                <p>Be present, engaged, and ready to participate. Keep your camera on (unless it's briefly off to avoid distraction). Give it your best effort — the more you put in, the more you'll take away.</p>

                <h4>Be Curious and Create Your Own Value</h4>
                <p>The biggest growth happens when you take initiative. Don't wait for answers — ask questions, explore, and stay curious. You're responsible for your own experience. This is your time.</p>

                <h4>Contribute to the Group</h4>
                <p>If you engage in group services, use your voice and energy to support the program and your fellow participants. Avoid anything that takes energy away from the group's progress.</p>

                <h4>Be On Time</h4>
                <p>Please note if being on time is an issue for you and use lateness as an opportunity to examine the issues, learn, and grow. In groups, everyone's time and investment matter. Let's respect each other by starting and ending on time.</p>

                <h4>Take Ownership of Your Reactions</h4>
                <p>If something stirs up emotion, pause and check in with yourself. Notice what you're feeling and take responsibility for your experience. Reach out to a coach, leader, or assistant if you need support.</p>

                <h4>No Side Conversations</h4>
                <p>Keep your attention on the group and the moment. Private chats or discussions outside the group setting take away from the shared experience.</p>

                <h4>No Advice or Disagreeing</h4>
                <p>Instead of giving advice, ask open questions. Instead of saying "I disagree," try "Can you help me understand what you mean?" We grow more by staying curious than by trying to be right.</p>

                <h4>Keep a Beginner's Mind</h4>
                <p>No matter how much you know, come with a fresh perspective. This space isn't about teaching others — it's about exploring your own learning edge.</p>

                <h4>Speak From Your Own Experience</h4>
                <p>Share your own story, your own insights. Avoid generalizations or talking about other people.</p>

                <h4>Tell the Truth</h4>
                <p>You're always welcome to pass, but we invite you to be open and honest. The more real you are, the more powerful your experience will be.</p>

                <h4>Ask When You Don't Understand</h4>
                <p>If something doesn't make sense, ask. Curiosity opens the door to insight. Keep an open heart and mind.</p>

                <h4>Be Self-Focused</h4>
                <p>Your greatest contribution to others is not your knowledge but your example of learning and growing. The more you make this about you, the more growth you'll experience. Dive in. Apply the tools. Use this space for your own breakthroughs.</p>

                <h4>Be Coachable</h4>
                <p>Stay open to feedback and new perspectives. You don't need to agree — just be open to trying something different. Note feelings underneath tendencies to agree or disagree — neither position is conducive to learning and growing.</p>

                <h4>Keep It Confidential</h4>
                <p>What's shared in the group stays in the group. Talk about your own experience, not others'.</p>

                <h4>No Business Deals</h4>
                <p>Keep leaders appraised of any business or financial dealing so all can be aware of non-growth-oriented dynamics. Please don't initiate business or money-related conversations with fellow participants during the program. Referrals and existing relationships are fine — just save business for after the program.</p>

                <h4>Stay Substance-Free</h4>
                <p>To be fully present, avoid alcohol and mood-altering substances for 24 hours before and during the training.</p>

                <h4>No Recording</h4>
                <p>Don't record the program in any way. If you want to share a group photo, please ask for permission first.</p>

                <h4>One Person Per Screen</h4>
                <p>Except in obvious situations like couples' or family training and coaching, everyone must be registered and participating on their own device, in a private space if possible. Use headphones if others are nearby.</p>

                <p style="margin-top: 20px; font-style: italic;">These agreements are here to create a respectful, powerful, and growth-centered environment for everyone involved. Thank you for honoring them — and for showing up for yourself and the group.</p>

                <h3>Service Agreement</h3>

                <h4>Purpose</h4>
                <p>The Client is engaging LiveWright, LLC to provide coaching and consulting services, as outlined below. Both parties agree to the following terms of this services agreement and the operating agreement below for engagement in coaching and group services:</p>

                <h4>A. Scope of Services</h4>
                <p>LiveWright will provide coaching and consulting services to the Client as requested and agreed upon. All acknowledge that this service is educational and developmental and is not psychotherapy or any licensed medical service. It's not a substitute for therapy, doesn't treat mental health conditions, and isn't meant to diagnose or cure any medical issues.</p>

                <h4>B. Duration</h4>
                <p>This agreement begins on the date above and will continue until services are completed, extended, or either party gives 30 days' written notice.</p>

                <h4>C. Governing Law</h4>
                <p>This agreement is governed by the laws of the State of Wisconsin.</p>

                <h4>D. Communications & Notices</h4>
                <p>All official communications must be in writing and sent to the addresses listed above via mail, overnight courier, or electronic transmission. Notices are considered received upon delivery.</p>

                <h4>E. Ownership</h4>
                <p>All materials developed for the Client are for the Client's personal use and ownership remains with the developer unless otherwise agreed in writing.</p>

                <h4>F. Confidentiality</h4>
                <p>Both parties agree to keep each other's confidential information private and only use it as needed for the services provided. Confidential information will be protected and returned or destroyed after the project or upon request.</p>

                <h4>G. Use of Shared Ideas</h4>
                <p>Information already public, independently developed, or lawfully received from others is not restricted. If either party receives a legal request for confidential information, they will notify the other party before complying.</p>

                <h4>H. Client Reference</h4>
                <p>Client agrees that LiveWright may mention the Client's name and a general description of services in promotional materials or reference lists.</p>

                <h4>I. Use of General Knowledge</h4>
                <p>LiveWright is free to use skills, knowledge, and general methods developed during this engagement for other clients, as long as confidential information is not shared.</p>

                <h4>J. Warranties</h4>
                <p>LiveWright commits to performing services professionally and lawfully. If the services do not meet expectations within 30 days of completion, LiveWright will make reasonable efforts to resolve the issue. If that's not possible, the Client may cancel the agreement and receive a refund for any non-compliant work. The Client confirms they have the right to use all materials provided to LiveWright and that these materials do not violate any laws or third-party rights.</p>

                <h4>K. Team Members & Hiring</h4>
                <p>LiveWright's team may work with other clients. The Client agrees not to hire or recruit LiveWright team members during the project or within one year after, without prior agreement. If this happens, a fee of $75,000 applies unless otherwise negotiated.</p>

                <h4>L. Disputes</h4>
                <p>Any disagreements will be resolved through arbitration in Milwaukee, Wisconsin under the rules of the American Arbitration Association. Decisions will be final and enforceable by law.</p>

                <h4>M. Ending the Agreement</h4>
                <p>Either party can end this agreement with 30 days' written notice. If there's a serious issue (like non-payment or breach of contract), the agreement can be ended with 15 days' notice if the issue isn't resolved. If either party becomes unable to continue due to insolvency or similar reasons, the agreement may be ended immediately. Upon ending, the Client agrees to pay for all services delivered up to that point.</p>

                <h4>N. Force Majeure</h4>
                <p>Neither party is responsible for delays due to uncontrollable events (e.g., natural disasters, emergencies), except for payment obligations.</p>

                <h4>O. Legal Jurisdiction</h4>
                <p>This agreement falls under Wisconsin law, and any legal matters will be resolved in Walworth County, Wisconsin.</p>

                <h4>P. Notices</h4>
                <p>All notices must be in writing and are considered received when delivered in person, by mail, courier, or fax.</p>

                <h4>Q. Independent Contractor</h4>
                <p>LiveWright is an independent contractor, not an employee. Nothing in this agreement creates a joint venture, partnership, or employment relationship.</p>

                <h4>R. Insurance</h4>
                <p>Any legal claim related to this agreement must be filed within one year of the services being completed. The Client is also responsible for any collection costs if fees remain unpaid and legal action is required.</p>

                <h4>T. Ongoing Obligations</h4>
                <p>Any part of this agreement that logically continues after termination (like confidentiality, ownership, and warranties) will remain in effect.</p>

                <h4>U. Authority to Sign</h4>
                <p>This agreement becomes valid once signed by an authorized representative of LiveWright, LLC.</p>

                <h4>V. Payment</h4>
                <p>Payment terms are as agreed. Travel and additional services not covered here will be billed separately. This agreement covers the period of current program participation and subsequent services from the date it's signed.</p>

                <h4>W. Cancellation Policy</h4>
                <p>Client agrees that it is the Client's responsibility to notify the Coach of any requests to cancel or reschedule any appointment at least 24 hours in advance of the scheduled calls/meetings. Coach reserves the right to bill Client for a missed or late-canceled meeting, or to deduct the session from the Client's package. Coach will attempt in good faith to reschedule the missed meeting.</p>

                <h4>X. No Guarantees and Limited Liability</h4>
                <p>Except as expressly provided in this Agreement, the Coach makes no guarantees, representations or warranties of any kind or nature, express or implied with respect to the coaching services negotiated, agreed upon and rendered. In no event shall the Coach be liable to the Client for any indirect, consequential or special damages. Notwithstanding any damages that the Client may incur, the Coach's entire liability under this Agreement, and the Client's exclusive remedy, shall be limited to the amount actually paid by the Client to the Coach under this Agreement for all coaching services.</p>

                <h4>Y. Binding Effect</h4>
                <p>This Agreement shall be binding upon the parties hereto and their respective successors and permissible assigns.</p>

                <p style="margin-top: 20px;"><strong>By signing, Client agrees to the terms above.</strong></p>
            </div>
            <div class="modal-footer">
                <button onclick="closeTermsModal()">I Have Read the Terms</button>
            </div>
        </div>
    </div>

    <script>
    // --- Terms Modal ---
    function openTermsModal() {
        document.getElementById('termsModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeTermsModal() {
        document.getElementById('termsModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeTermsModal();
    });

    document.getElementById('termsModal').addEventListener('click', function(e) {
        if (e.target === this) closeTermsModal();
    });

    // --- Terms Checkbox → Show Payment Section ---
    const termsCheckbox = document.getElementById('termsCheckbox');
    const paymentSection = document.getElementById('paymentSection');

    if (termsCheckbox && paymentSection) {
        termsCheckbox.addEventListener('change', function() {
            if (this.checked) {
                paymentSection.style.display = 'block';
                paymentSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                paymentSection.style.display = 'none';
            }
        });
    }

    // --- Stripe Elements ---
    <?php if (!$error): ?>
    const stripe = Stripe('<?= htmlspecialchars($stripe_publishable_key) ?>');
    const elements = stripe.elements();
    const cardElement = elements.create('card', {
        style: {
            base: {
                fontSize: '16px',
                color: '#333',
                '::placeholder': { color: '#aab7c4' },
            },
            invalid: { color: '#dc3545' },
        }
    });
    cardElement.mount('#card-element');

    // Enable/disable pay button based on card completeness
    let cardComplete = false;
    cardElement.on('change', function(event) {
        const displayError = document.getElementById('card-errors');
        if (event.error) {
            displayError.textContent = event.error.message;
        } else {
            displayError.textContent = '';
        }
        cardComplete = event.complete;
        document.getElementById('payBtn').disabled = !cardComplete;
    });

    // --- Payment Flow ---
    let processing = false;

    async function handlePayment() {
        if (processing || !cardComplete) return;
        processing = true;

        const payBtn = document.getElementById('payBtn');
        payBtn.disabled = true;
        payBtn.classList.add('processing');
        document.getElementById('card-errors').textContent = '';

        try {
            // Step 1: Create PaymentIntent on server
            const createResponse = await fetch('api/create-payment-intent.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    contract_uid: '<?= addslashes($contract_uid) ?>',
                    pricing_option_id: <?= (int)$payment_option_id ?>,
                    email: '<?= addslashes($email) ?>',
                    first_name: '<?= addslashes($first_name) ?>',
                    last_name: '<?= addslashes($last_name) ?>'
                })
            });

            const createData = await createResponse.json();

            if (createData.error) {
                throw new Error(createData.error);
            }

            // Step 2: Confirm payment with Stripe
            const { error, paymentIntent } = await stripe.confirmCardPayment(
                createData.client_secret,
                {
                    payment_method: {
                        card: cardElement,
                        billing_details: {
                            name: '<?= addslashes($first_name . ' ' . $last_name) ?>',
                            email: '<?= addslashes($email) ?>'
                        }
                    }
                }
            );

            if (error) {
                throw new Error(error.message);
            }

            if (paymentIntent.status === 'succeeded') {
                // Step 3: Confirm on server (record payment, create subscription, mark signed)
                const confirmResponse = await fetch('api/confirm-payment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        contract_uid: '<?= addslashes($contract_uid) ?>',
                        pricing_option_id: <?= (int)$payment_option_id ?>,
                        payment_intent_id: paymentIntent.id,
                        payment_method_id: paymentIntent.payment_method,
                        first_name: '<?= addslashes($first_name) ?>',
                        last_name: '<?= addslashes($last_name) ?>',
                        email: '<?= addslashes($email) ?>'
                    })
                });

                const confirmData = await confirmResponse.json();

                if (confirmData.error) {
                    // Payment succeeded but server confirmation failed
                    // Still show success since money was charged
                    console.error('Server confirmation error:', confirmData.error);
                }

                // Show success
                document.getElementById('review-section').style.display = 'none';
                document.getElementById('success-section').style.display = 'block';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

        } catch (err) {
            document.getElementById('card-errors').textContent = err.message;
            payBtn.disabled = false;
            payBtn.classList.remove('processing');
            processing = false;
        }
    }
    <?php endif; ?>
    </script>
</body>
</html>
