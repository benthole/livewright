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

// Handle form submission for final confirmation
if ($_POST && isset($_POST['confirm_purchase']) && !$error) {
    try {
        $pdo->beginTransaction();
        
        // Update contract with selected option and mark as signed
        $stmt = $pdo->prepare("UPDATE contracts SET selected_option_id = ?, signed = 1, first_name = ?, last_name = ?, email = ? WHERE id = ?");
        $stmt->execute([$payment_option_id, $first_name, $last_name, $email, $contract['id']]);
        
        $pdo->commit();
        
        // Redirect to success page or show success message
        $success = true;
        
    } catch (Exception $e) {
        $pdo->rollback();
        $error = 'Error processing your selection. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Confirm Your Selection - Personal Development Plan</title>
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
        .confirm-btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 4px; font-size: 18px; font-weight: bold; cursor: pointer; width: 100%; margin-top: 20px; }
        .confirm-btn:hover { background: #218838; }
        .back-btn { background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 4px; text-decoration: none; display: inline-block; margin-right: 10px; }
        .back-btn:hover { background: #5a6268; color: white; }
        .button-group { display: flex; gap: 10px; margin-top: 20px; }
        .success-message { text-align: center; }
        .success-message h2 { color: #28a745; }

        /* Terms of Service Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            overflow-y: auto;
            padding: 20px;
            box-sizing: border-box;
        }
        .modal-overlay.active { display: flex; justify-content: center; align-items: flex-start; }
        .modal-content {
            background: white;
            border-radius: 8px;
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            margin: 20px auto;
        }
        .modal-header {
            background: #007cba;
            color: white;
            padding: 20px 30px;
            border-radius: 8px 8px 0 0;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .modal-header h2 { margin: 0; }
        .modal-close {
            position: absolute;
            right: 15px;
            top: 15px;
            background: none;
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            line-height: 1;
        }
        .modal-close:hover { opacity: 0.8; }
        .modal-body {
            padding: 30px;
            font-size: 14px;
            line-height: 1.6;
        }
        .modal-body h3 { color: #007cba; margin-top: 30px; border-bottom: 2px solid #007cba; padding-bottom: 10px; }
        .modal-body h3:first-child { margin-top: 0; }
        .modal-body h4 { color: #333; margin-top: 20px; }
        .modal-body ul { margin: 10px 0; padding-left: 25px; }
        .modal-body li { margin-bottom: 8px; }
        .modal-body p { margin: 10px 0; }
        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #ddd;
            text-align: center;
            position: sticky;
            bottom: 0;
            background: white;
        }
        .modal-footer button {
            background: #007cba;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
        }
        .modal-footer button:hover { background: #006399; }

        /* Terms checkbox styling */
        .terms-agreement {
            background: #f8f9fa;
            padding: 15px 20px;
            border-radius: 4px;
            margin: 20px 0;
            border: 1px solid #ddd;
        }
        .terms-agreement label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
        }
        .terms-agreement input[type="checkbox"] {
            margin-top: 3px;
            width: 18px;
            height: 18px;
        }
        .terms-link {
            color: #007cba;
            text-decoration: underline;
            cursor: pointer;
        }
        .terms-link:hover { color: #005a8c; }
        .confirm-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
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
        <?php elseif (isset($success) && $success): ?>
            <div class="content">
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
        <?php else: ?>
            <div class="content">
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
                
                <form method="POST" id="confirmForm">
                    <input type="hidden" name="payment_option" value="<?= htmlspecialchars($payment_option_id) ?>">
                    <input type="hidden" name="contract_uid" value="<?= htmlspecialchars($contract_uid) ?>">
                    <input type="hidden" name="first_name" value="<?= htmlspecialchars($first_name) ?>">
                    <input type="hidden" name="last_name" value="<?= htmlspecialchars($last_name) ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                    <input type="hidden" name="confirm_purchase" value="1">

                    <div class="terms-agreement">
                        <label>
                            <input type="checkbox" id="termsCheckbox" name="agree_terms" required>
                            <span>I have read and agree to the <a href="#" class="terms-link" onclick="openTermsModal(); return false;">Terms of Service and Operating Agreements</a></span>
                        </label>
                    </div>

                    <div class="button-group">
                        <a href="/?uid=<?= urlencode($contract_uid) ?>" class="back-btn">← Go Back</a>
                        <button type="submit" class="confirm-btn" id="confirmBtn" disabled>
                            Confirm & Sign Plan
                        </button>
                    </div>
                </form>
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
                <p>The following operating agreements will help you get the most out of your participation in the program and enhance the quality of your life in all areas. Choosing to participate in the training means choosing to follow these agreements; those who choose not to will be informed of the problem, and continued failure to follow the agreements will lead to dismissal from the training.</p>

                <h4>The agreements are as follows:</h4>
                <ul>
                    <li><strong>Have fun, participate and engage fully, and stay curious.</strong></li>
                    <li><strong>Create value for yourself.</strong> Those who benefit the most in workshop learning don't wait for information to come to them. They participate and generate meaning and value for themselves. The more you can accept this responsibility to create value for yourself, the more you will find creative ways to contribute, participate, and benefit.</li>
                    <li><strong>Create value for the training and do not detract.</strong> Use your participation to move the group or training forward and not detract or delay it in any way.</li>
                    <li><strong>Participate fully.</strong> The more you invest and participate in the training, the more you will benefit. Research has shown that those who participate the most get the most out of their experience. Full participation also means that you will be fully engaged with your camera on through the duration of the training.</li>
                    <li><strong>Do your best in every way possible.</strong></li>
                    <li><strong>Be in the here and now.</strong> You are here to explore your experience in the here and now and ways to live more presently in the moment, and you agree to accept coaching.</li>
                    <li><strong>Be on time.</strong> You and others have invested significant resources to participate in this training. To make your work together the most productive, it is critical that you show up on time and ready to work.</li>
                    <li><strong>Take responsibility for your own experience, especially charges and reactions.</strong> Any time an issue arises or you have a charge or reaction, you agree you will immediately identify the feeling underneath and take responsibility for yourself, your pain, or your issue.</li>
                    <li><strong>No side conversations.</strong> Your team's success depends on everyone paying attention and supporting the group unity.</li>
                    <li><strong>Do not give advice or disagree.</strong> Giving advice prevents fellow participants from accessing their own innate wisdom, deep yearnings, and feelings on a given topic or assignment.</li>
                    <li><strong>Have a beginner's mind.</strong> The best coaches and teachers are those who are always learning and are deepening their insights and learning in new ways.</li>
                    <li><strong>Speak about your own experience. No generalizations.</strong> While it is often easier to talk about how the experience or training relates to someone you know, you'll get the most benefit when you talk about how your insight or learning applies to you.</li>
                    <li><strong>Tell the truth.</strong> You can always decline to share information about yourself. However, a training experience is a great opportunity to take risks, be more open and honest, and disclose more about yourself.</li>
                    <li><strong>Be curious and honest about what you do and do not understand.</strong></li>
                    <li><strong>Be as selfish as possible.</strong> The more you want, the more you will invest, and the more you invest, the more you will get out of this experience.</li>
                    <li><strong>Be coachable and open-minded.</strong> Be coachable and be open to having your opinion shifted and changed.</li>
                    <li><strong>Keep confidentiality.</strong> We maintain an environment of integrity, safety, and freedom to participate by upholding confidentiality. Students do not disclose the content of other students' work.</li>
                    <li><strong>No business transactions.</strong> We ask that participants refrain from conducting business transactions with each other during the training. Referrals and networking are okay.</li>
                    <li><strong>Be fully conscious, fully available, and fully engaged by refraining from mood-altering substances.</strong> Participants agree to refrain from mood-altering drugs or substances 24 hours ahead and during the training.</li>
                    <li><strong>No Recording.</strong> By participating, you agree not to record the screen/video or sound/audio from the training.</li>
                    <li><strong>No broadcasting to non-registered participants.</strong> Everyone who is participating must be registered, have signed these agreements, and must participate from their own device.</li>
                </ul>

                <h4>The Seven Rules of Engagement</h4>
                <ul>
                    <li><strong>Rule #1: Accentuate the positive.</strong> Focus on what is good, exciting, novel, and what works.</li>
                    <li><strong>Rule #2: Minimize the negative.</strong> Eliminate passive-destructive behaviors like avoiding, stonewalling, withholding, keeping secrets, or zoning out.</li>
                    <li><strong>Rule #3: No one gets more than 50 percent of the blame.</strong> When you find yourself assigning blame, remember that no matter who instigates a conflict, you are part of a system.</li>
                    <li><strong>Rule #4: Take 100 percent responsibility for your happiness and satisfaction.</strong> It is not the responsibility of the program, your group, your leaders, your coach, or anyone else in your life to make you happy.</li>
                    <li><strong>Rule #5: Express and agree with the truth, always.</strong> In any disagreement, verbally acknowledge when someone says something that is true or when you are wrong.</li>
                    <li><strong>Rule #6: Fight for, not against.</strong> When someone says or does something that is irritating or challenging, fight for something rather than just asserting your perspective.</li>
                    <li><strong>Rule #7: Assume goodwill.</strong> Look for the positive intent in any relationship or interaction, rather than scanning for what others are doing wrong.</li>
                </ul>

                <h3>Service Agreement</h3>
                <p>This agreement is made between LiveWright, LLC, N7698 County Road H, Elkhorn, Wisconsin 53121 ("LiveWright" or "Consultant") and You ("Client").</p>

                <h4>A. Services</h4>
                <p>The Coaching and Consulting services shall be performed for the Client as indicated in the selected plan.</p>

                <h4>B. Term</h4>
                <p>This Agreement shall commence on the date of signing and shall continue until the Consultant has delivered services and services are deemed complete by client and/or Consultant with 30 days' notice.</p>

                <h4>C. Governing Law</h4>
                <p>This Agreement shall be governed by and construed according to the internal laws of the State of Wisconsin. Any action or proceeding shall be brought in the courts in the State of Wisconsin.</p>

                <h4>D. Confidentiality</h4>
                <p>Each party agrees that any confidential information disclosed shall not be disclosed to any other party or used by the receiving party for its own benefit except as contemplated by this Agreement.</p>

                <h4>E. Warranties</h4>
                <p>LiveWright warrants that services shall: (a) be performed in a professional and workmanlike manner; (b) comply with applicable law; and (c) not violate or infringe upon intellectual property rights of a third party.</p>

                <h4>F. Termination</h4>
                <p>Either party may terminate this Agreement without cause upon giving the other party thirty (30) days prior written notice. Either party may terminate for a material breach upon giving fifteen (15) days prior written notice, provided that the breaching party does not cure such breach within the notice period.</p>

                <h4>G. Arbitration</h4>
                <p>Any controversy arising under this Agreement shall be determined and settled by arbitration in the metropolitan area of Milwaukee, Wisconsin, in accordance with the rules of the American Arbitration Association.</p>

                <h4>H. Independent Contractor</h4>
                <p>LiveWright is an independent contractor, and no party shall have the authority to bind, represent or commit to the other. Nothing in this Agreement shall be deemed to create a joint venture, partnership, or agency relationship.</p>

                <h4>I. Payment</h4>
                <p>Total cost as agreed upon in the selected plan. Travel expenses are not included and will be billed separately. This Contract covers services rendered during the 365-day period commencing when LiveWright receives your signed contract.</p>
            </div>
            <div class="modal-footer">
                <button onclick="closeTermsModal()">I Have Read the Terms</button>
            </div>
        </div>
    </div>

    <script>
        // Terms modal functions
        function openTermsModal() {
            document.getElementById('termsModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeTermsModal() {
            document.getElementById('termsModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeTermsModal();
            }
        });

        // Close modal when clicking outside
        document.getElementById('termsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTermsModal();
            }
        });

        // Enable/disable confirm button based on checkbox
        const checkbox = document.getElementById('termsCheckbox');
        const confirmBtn = document.getElementById('confirmBtn');

        if (checkbox && confirmBtn) {
            checkbox.addEventListener('change', function() {
                confirmBtn.disabled = !this.checked;
            });
        }
    </script>
</body>
</html>