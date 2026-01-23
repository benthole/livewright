<?php
require_once 'config.php';

$uid = $_GET['uid'] ?? '';
$contract = null;
$options = [];

// Helper function to calculate savings from annual base price
// Annual is the base, quarterly/monthly are markups from it
function calculateSavingsFromAnnual($yearlyPrice, $type) {
    if ($type === 'Quarterly') {
        // Quarterly is annual/4 + 5% markup
        $baseQuarterly = $yearlyPrice / 4;
        $markup = $baseQuarterly * 0.05;
        $quarterlyPrice = $baseQuarterly + $markup;
        // What they'd pay over a year at quarterly rate
        $yearlyAtQuarterly = $quarterlyPrice * 4;
        $extraCost = $yearlyAtQuarterly - $yearlyPrice;
        return [
            'quarterly_price' => $quarterlyPrice,
            'yearly_equivalent' => $yearlyAtQuarterly,
            'extra_vs_annual' => $extraCost,
            'markup_percent' => 5
        ];
    } elseif ($type === 'Monthly') {
        // Monthly is annual/12 + 10% markup
        $baseMonthly = $yearlyPrice / 12;
        $markup = $baseMonthly * 0.10;
        $monthlyPrice = $baseMonthly + $markup;
        // What they'd pay over a year at monthly rate
        $yearlyAtMonthly = $monthlyPrice * 12;
        $extraCost = $yearlyAtMonthly - $yearlyPrice;
        return [
            'monthly_price' => $monthlyPrice,
            'yearly_equivalent' => $yearlyAtMonthly,
            'extra_vs_annual' => $extraCost,
            'markup_percent' => 10
        ];
    }
    return null;
}

// Helper function to calculate annual savings display
function getAnnualSavings($yearlyPrice, $monthlyPrice) {
    $yearlyAtMonthly = $monthlyPrice * 12;
    $savings = $yearlyAtMonthly - $yearlyPrice;
    $percent = ($savings / $yearlyAtMonthly) * 100;
    return [
        'savings' => $savings,
        'percent' => round($percent)
    ];
}

if (empty($uid)) {
    $error = 'No contract identifier provided.';
} else {
    // Get contract by unique_id
    $stmt = $pdo->prepare("SELECT * FROM contracts WHERE unique_id = ? AND deleted_at IS NULL");
    $stmt->execute([$uid]);
    $contract = $stmt->fetch();
    
    if (!$contract) {
        $error = 'Contract not found or no longer available.';
    } else {
        // Get pricing options for this contract
        // Order by: option_number, sub_option_name, then by type in specific order (Yearly, Quarterly, Monthly - annual first!)
        $stmt = $pdo->prepare("
            SELECT * FROM pricing_options
            WHERE contract_id = ? AND deleted_at IS NULL
            ORDER BY
                option_number,
                sub_option_name,
                FIELD(type, 'Yearly', 'Quarterly', 'Monthly')
        ");
        $stmt->execute([$contract['id']]);
        $pricing_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by option number and sub-option name
        foreach ($pricing_options as $option) {
            $options[$option['option_number']][$option['sub_option_name']][] = $option;
        }
        
        // Debug: Uncomment to see structure
        // echo '<pre style="background: #f0f0f0; padding: 20px; margin: 20px; border: 2px solid #ccc;">'; 
        // echo "DEBUG - Options Structure:\n";
        // print_r($options); 
        // echo '</pre>';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Personal Development Plan - <?= $contract ? htmlspecialchars($contract['first_name'] . ' ' . $contract['last_name']) : 'Contract' ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f8f9fa; }
        .container { max-width: 1000px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #007cba; color: white; padding: 30px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { padding: 30px; }
        .error { background: #f8d7da; color: #721c24; padding: 20px; border-radius: 4px; margin: 20px; text-align: center; }
        .contract-info { margin-bottom: 30px; }
        .contract-info h2 { color: #333; margin-bottom: 10px; }
        .contract-description { background: #f8f9fa; padding: 20px; border-radius: 4px; margin-bottom: 30px; }
        .option-section { border: 1px solid #ddd; margin-bottom: 20px; border-radius: 4px; overflow: hidden; }
        .option-header { background: #f5f5f5; padding: 15px; border-bottom: 1px solid #ddd; }
        .option-content { padding: 20px; }
        
        /* Sub-option pricing grid - stacked by billing period */
        .sub-options-container { margin-top: 20px; }
        .pricing-columns { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        .pricing-column { }
        .pricing-column h4 { text-align: center; color: #007cba; margin: 0 0 15px 0; padding: 10px; background: #f0f8ff; border-radius: 4px; }
        
        .pricing-tier { border: 1px solid #ddd; border-radius: 4px; padding: 15px; text-align: center; background: white; cursor: pointer; transition: all 0.3s ease; margin-bottom: 10px; }
        .pricing-tier:hover { border-color: #007cba; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .pricing-tier.selected { border-color: #007cba; background: #f0f8ff; border-width: 2px; }
        .pricing-tier .sub-option-label { font-weight: bold; color: #333; margin-bottom: 8px; font-size: 0.95em; }
        .pricing-tier.recommended { border-color: #28a745; background: #f0fff4; border-width: 2px; }
        .price { font-size: 1.5em; font-weight: bold; color: #28a745; margin: 5px 0; }
        .type { color: #666; font-size: 0.9em; }
        .price-discount { font-size: 0.85em; color: #28a745; margin-top: 5px; font-weight: 600; }
        .price-details { font-size: 0.8em; color: #666; margin-top: 3px; }
        .best-value-badge { background: #28a745; color: white; font-size: 0.7em; padding: 2px 8px; border-radius: 10px; margin-left: 5px; vertical-align: middle; }

        .minimum-commitment-section { color: #dc3545; font-size: 0.9em; font-weight: bold; margin-top: 15px; text-align: center; background: #fff5f5; padding: 10px; border-radius: 4px; border-left: 3px solid #dc3545; }

        /* Support Packages Public Display */
        .support-packages-public { background: #f8f9fa; padding: 25px; border-radius: 4px; margin-top: 30px; border-left: 4px solid #17a2b8; }
        .support-packages-public h3 { color: #17a2b8; margin-top: 0; margin-bottom: 10px; }
        .support-intro { color: #666; margin-bottom: 20px; }
        .support-packages-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .support-package-card { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; text-align: center; transition: all 0.3s ease; }
        .support-package-card:hover { border-color: #17a2b8; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .support-package-card.has-savings { border-color: #28a745; }
        .support-package-name { font-weight: bold; color: #333; margin-bottom: 8px; font-size: 1.1em; }
        .support-package-description { color: #666; font-size: 0.9em; margin-bottom: 15px; min-height: 40px; }
        .support-package-price { margin-bottom: 15px; }
        .support-package-price .price-amount { font-size: 1.4em; font-weight: bold; color: #17a2b8; }
        .support-package-price .price-period { color: #666; font-size: 0.9em; }
        .support-package-checkbox { display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; padding: 10px; background: #f8f9fa; border-radius: 4px; transition: background 0.2s; }
        .support-package-checkbox:hover { background: #e9ecef; }
        .support-package-checkbox input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }

        /* Enhanced pricing display for packages with savings */
        .package-pricing-breakdown { margin: 15px 0; }
        .pricing-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .pricing-row:last-child { border-bottom: none; }
        .pricing-row .label { color: #666; }
        .pricing-row .value { font-weight: bold; }
        .pricing-row.regular .value { text-decoration: line-through; color: #999; }
        .pricing-row.package .value { color: #28a745; font-size: 1.2em; }
        .pricing-row.savings { background: #d4edda; margin: 0 -20px; padding: 10px 20px; border-radius: 0 0 8px 8px; }
        .pricing-row.savings .value { color: #28a745; }
        .savings-badge { background: #28a745; color: white; padding: 3px 8px; border-radius: 12px; font-size: 0.75em; margin-left: 5px; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 0.9em; }
        .signed-badge { background: #28a745; color: white; padding: 5px 15px; border-radius: 20px; font-size: 0.9em; margin-left: 10px; }
        .pathways-section { background: #f8f9fa; padding: 25px; border-radius: 4px; margin-bottom: 30px; border-left: 4px solid #007cba; }
        .pathways-section h2 { color: #007cba; margin-top: 0; margin-bottom: 20px; }
        .pathways-section p { margin-bottom: 20px; line-height: 1.6; }
        .pathway-item { margin-bottom: 25px; }
        .pathway-item h3 { color: #333; margin-bottom: 10px; }
        .pathway-item ul { margin-left: 20px; }
        .pathway-item li { margin-bottom: 8px; line-height: 1.5; }
        .sub-list { margin-top: 8px; margin-left: 20px; }
        .sub-list li { margin-bottom: 5px; font-size: 0.95em; }
        .selection-form { background: #f8f9fa; padding: 30px; margin-top: 30px; border-radius: 4px; border-top: 3px solid #007cba; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        .form-group input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; box-sizing: border-box; }
        .continue-btn { background: #28a745; color: white; padding: 15px 30px; border: none; border-radius: 4px; font-size: 18px; font-weight: bold; cursor: pointer; width: 100%; }
        .continue-btn:hover { background: #218838; }
        .continue-btn:disabled { background: #6c757d; cursor: not-allowed; }
        .selected-option { background: #d4edda; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #28a745; }
        .error-message { color: #dc3545; font-size: 14px; margin-top: 5px; }

        /* Floating Cart Button */
        .floating-cart {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 15px 25px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
            z-index: 1000;
            display: none;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        .floating-cart:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.5);
        }
        .floating-cart .cart-icon { font-size: 20px; }
        .floating-cart .cart-count {
            background: white;
            color: #28a745;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .floating-cart .cart-total { margin-left: 5px; }

        /* Cart Panel */
        .cart-panel {
            position: fixed;
            bottom: 100px;
            right: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            width: 350px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 999;
            display: none;
        }
        .cart-panel.open { display: block; }
        .cart-panel-header {
            background: #007cba;
            color: white;
            padding: 15px 20px;
            border-radius: 12px 12px 0 0;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .cart-panel-close {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
        }
        .cart-panel-content { padding: 20px; }
        .cart-item {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        .cart-item:last-child { border-bottom: none; }
        .cart-item-name { font-weight: bold; color: #333; margin-bottom: 5px; }
        .cart-item-price { color: #28a745; font-weight: bold; }
        .cart-item-type { color: #666; font-size: 0.9em; }
        .cart-item-remove {
            color: #dc3545;
            cursor: pointer;
            font-size: 0.85em;
            float: right;
        }
        .cart-item-remove:hover { text-decoration: underline; }
        .cart-total-row {
            padding: 15px 0;
            border-top: 2px solid #007cba;
            margin-top: 10px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
        }
        .cart-total-row .total-price { color: #28a745; font-size: 1.2em; }
        .cart-checkout-btn {
            width: 100%;
            background: #28a745;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 15px;
        }
        .cart-checkout-btn:hover { background: #218838; }

        /* Remove default highlighting once user selects */
        .pricing-tier.recommended.user-selected-elsewhere {
            border-color: #ddd;
            background: white;
            border-width: 1px;
        }
        .pricing-tier.recommended.user-selected-elsewhere:hover {
            border-color: #007cba;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Personal Development Plan</h1>
            <?php if ($contract): ?>
                <p>For <?= htmlspecialchars($contract['first_name'] . ' ' . $contract['last_name']) ?>
                <?php if ($contract['signed']): ?>
                    <span class="signed-badge">✓ Signed</span>
                <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="error">
                <h3>Error</h3>
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php else: ?>
            <div class="content">
                <div class="contract-info">
                    <h2>Contract Details</h2>
                    <p><strong>Client:</strong> <?= htmlspecialchars($contract['first_name'] . ' ' . $contract['last_name']) ?></p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($contract['email']) ?></p>
                    <p><strong>Created:</strong> <?= date('F j, Y', strtotime($contract['created_at'])) ?></p>
                </div>
                
                <?php if (!empty($contract['pdp_from']) || !empty($contract['pdp_toward'])): ?>
                    <div class="pathways-section">
                        <?php if (!empty($contract['pdp_from'])): ?>
                            <div class="pathway-item">
                                <h3>FROM — Present State Challenges and Opportunities</h3>
                                <div><?= $contract['pdp_from'] ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($contract['pdp_toward'])): ?>
                            <div class="pathway-item">
                                <h3>TOWARD — Ideal State Outcomes</h3>
                                <div><?= $contract['pdp_toward'] ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($contract['contract_description'])): ?>
                    <div class="contract-description">
                        <h3>What's Included</h3>
                        <div><?= $contract['contract_description'] ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($options)): ?>
                    <h3>Available Options</h3>
                    
                    <?php foreach ($options as $option_number => $sub_options): ?>
                        <?php 
                        // Get the description from the first sub-option
                        $first_sub = reset($sub_options);
                        $description = $first_sub[0]['description'];
                        
                        // Check if this option has sub-options (more than just "Default")
                        // Has sub-options if: more than 1 sub_option_name OR the single one isn't named "Default"
                        $has_sub_options = (count($sub_options) > 1) || (count($sub_options) === 1 && !isset($sub_options['Default']));
                        
                        // Get minimum commitment months
                        $minimum_field = "option_{$option_number}_minimum_months";
                        $minimum_months = $contract[$minimum_field] ?? 1;
                        ?>
                        
                        <div class="option-section">
                            <div class="option-header">
                                <h3 style="margin: 0;">Option <?= $option_number ?></h3>
                            </div>
                            <div class="option-content">
                                <div><?= $description ?></div>
                                
                                <?php if ($has_sub_options): ?>
                                    <!-- Display sub-options stacked by billing period - ANNUAL FIRST -->
                                    <div class="sub-options-container">
                                        <div class="pricing-columns">
                                            <!-- Yearly Column (FIRST - Best Value) -->
                                            <div class="pricing-column">
                                                <h4>Yearly <span class="best-value-badge">Best Value</span></h4>
                                                <?php foreach ($sub_options as $sub_name => $tiers): ?>
                                                    <?php
                                                    $yearly_tier = array_filter($tiers, fn($t) => $t['type'] === 'Yearly');
                                                    $yearly_tier = reset($yearly_tier);
                                                    $monthly_tier = array_filter($tiers, fn($t) => $t['type'] === 'Monthly');
                                                    $monthly_tier = reset($monthly_tier);
                                                    $monthly_price = $monthly_tier ? $monthly_tier['price'] : 0;

                                                    if ($yearly_tier):
                                                        $savings = $monthly_price > 0 ? getAnnualSavings($yearly_tier['price'], $monthly_price) : null;
                                                    ?>
                                                        <div class="pricing-tier recommended" onclick="selectOption(<?= $yearly_tier['id'] ?>, 'Option <?= $option_number ?>', '<?= htmlspecialchars(addslashes($yearly_tier['description'])) ?>', <?= $yearly_tier['price'] ?>, '<?= $yearly_tier['type'] ?>', '<?= htmlspecialchars(addslashes($sub_name)) ?>')">
                                                            <div class="sub-option-label"><?= htmlspecialchars($sub_name) ?></div>
                                                            <div class="price">$<?= number_format($yearly_tier['price'], 2) ?></div>
                                                            <div class="type">per year</div>
                                                            <?php if ($savings && $savings['savings'] > 0): ?>
                                                                <div class="price-discount">Save <?= $savings['percent'] ?>% ($<?= number_format($savings['savings'], 2) ?>/yr)</div>
                                                                <div class="price-details">vs monthly billing</div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>

                                            <!-- Quarterly Column -->
                                            <div class="pricing-column">
                                                <h4>Quarterly</h4>
                                                <?php foreach ($sub_options as $sub_name => $tiers): ?>
                                                    <?php
                                                    $quarterly_tier = array_filter($tiers, fn($t) => $t['type'] === 'Quarterly');
                                                    $quarterly_tier = reset($quarterly_tier);
                                                    $yearly_tier = array_filter($tiers, fn($t) => $t['type'] === 'Yearly');
                                                    $yearly_tier = reset($yearly_tier);
                                                    $yearly_price = $yearly_tier ? $yearly_tier['price'] : 0;

                                                    if ($quarterly_tier && $yearly_price):
                                                        $calc = calculateSavingsFromAnnual($yearly_price, 'Quarterly');
                                                    ?>
                                                        <div class="pricing-tier" onclick="selectOption(<?= $quarterly_tier['id'] ?>, 'Option <?= $option_number ?>', '<?= htmlspecialchars(addslashes($quarterly_tier['description'])) ?>', <?= $quarterly_tier['price'] ?>, '<?= $quarterly_tier['type'] ?>', '<?= htmlspecialchars(addslashes($sub_name)) ?>')">
                                                            <div class="sub-option-label"><?= htmlspecialchars($sub_name) ?></div>
                                                            <div class="price">$<?= number_format($quarterly_tier['price'], 2) ?></div>
                                                            <div class="type">per quarter</div>
                                                            <div class="price-details">4 payments of $<?= number_format($quarterly_tier['price'], 2) ?></div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>

                                            <!-- Monthly Column -->
                                            <div class="pricing-column">
                                                <h4>Monthly</h4>
                                                <?php foreach ($sub_options as $sub_name => $tiers): ?>
                                                    <?php
                                                    $monthly_tier = array_filter($tiers, fn($t) => $t['type'] === 'Monthly');
                                                    $monthly_tier = reset($monthly_tier);
                                                    if ($monthly_tier):
                                                    ?>
                                                        <div class="pricing-tier" onclick="selectOption(<?= $monthly_tier['id'] ?>, 'Option <?= $option_number ?>', '<?= htmlspecialchars(addslashes($monthly_tier['description'])) ?>', <?= $monthly_tier['price'] ?>, '<?= $monthly_tier['type'] ?>', '<?= htmlspecialchars(addslashes($sub_name)) ?>')">
                                                            <div class="sub-option-label"><?= htmlspecialchars($sub_name) ?></div>
                                                            <div class="price">$<?= number_format($monthly_tier['price'], 2) ?></div>
                                                            <div class="type">per month</div>
                                                            <div class="price-details">12 payments of $<?= number_format($monthly_tier['price'], 2) ?></div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <!-- Simple pricing (no sub-options) - ANNUAL FIRST -->
                                    <?php
                                    // Reorder to Yearly, Quarterly, Monthly
                                    $ordered_types = ['Yearly', 'Quarterly', 'Monthly'];
                                    $ordered_tiers = [];
                                    foreach ($ordered_types as $type) {
                                        foreach ($sub_options['Default'] as $tier) {
                                            if ($tier['type'] === $type) {
                                                $ordered_tiers[] = $tier;
                                            }
                                        }
                                    }
                                    $yearly_price = 0;
                                    $monthly_price = 0;
                                    foreach ($ordered_tiers as $tier) {
                                        if ($tier['type'] === 'Yearly') $yearly_price = $tier['price'];
                                        if ($tier['type'] === 'Monthly') $monthly_price = $tier['price'];
                                    }
                                    $savings = $monthly_price > 0 ? getAnnualSavings($yearly_price, $monthly_price) : null;
                                    ?>
                                    <div class="pricing-columns" style="margin-top: 20px;">
                                        <?php foreach ($ordered_tiers as $tier): ?>
                                            <div class="pricing-column">
                                                <div class="pricing-tier <?= $tier['type'] === 'Yearly' ? 'recommended' : '' ?>" onclick="selectOption(<?= $tier['id'] ?>, 'Option <?= $option_number ?>', '<?= htmlspecialchars(addslashes($tier['description'])) ?>', <?= $tier['price'] ?>, '<?= $tier['type'] ?>')">
                                                    <h4><?= htmlspecialchars($tier['type']) ?><?= $tier['type'] === 'Yearly' ? ' <span class="best-value-badge">Best Value</span>' : '' ?></h4>
                                                    <div class="price">$<?= number_format($tier['price'], 2) ?></div>
                                                    <div class="type"><?= $tier['type'] === 'Yearly' ? 'per year' : ($tier['type'] === 'Quarterly' ? 'per quarter' : 'per month') ?></div>
                                                    <?php if ($tier['type'] === 'Yearly' && $savings && $savings['savings'] > 0): ?>
                                                        <div class="price-discount">Save <?= $savings['percent'] ?>% ($<?= number_format($savings['savings'], 2) ?>/yr)</div>
                                                        <div class="price-details">vs monthly billing</div>
                                                    <?php elseif ($tier['type'] === 'Monthly'): ?>
                                                        <div class="price-details">12 payments of $<?= number_format($tier['price'], 2) ?></div>
                                                    <?php elseif ($tier['type'] === 'Quarterly'): ?>
                                                        <div class="price-details">4 payments of $<?= number_format($tier['price'], 2) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($minimum_months > 1): ?>
                                    <div class="minimum-commitment-section">
                                        ⚠️ Minimum <?= $minimum_months ?> month commitment required for this option
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php
                    // Display Support Packages if available
                    $support_packages = [];
                    if (!empty($contract['support_packages'])) {
                        $support_packages = json_decode($contract['support_packages'], true) ?: [];
                    }
                    if (!empty($support_packages)):
                    ?>
                        <div class="support-packages-public">
                            <h3>Optional Support Add-Ons</h3>
                            <p class="support-intro">Enhance your plan with optional coaching packages:</p>
                            <div class="support-packages-grid">
                                <?php foreach ($support_packages as $pkg):
                                    $has_savings = isset($pkg['regular_price']) && isset($pkg['package_price']) && isset($pkg['savings']) && $pkg['savings'] > 0;
                                ?>
                                    <div class="support-package-card <?= $has_savings ? 'has-savings' : '' ?>">
                                        <div class="support-package-name">
                                            <?= htmlspecialchars($pkg['name']) ?>
                                            <?php if ($has_savings): ?>
                                                <span class="savings-badge">Save $<?= number_format($pkg['savings'], 0) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="support-package-description"><?= htmlspecialchars($pkg['description']) ?></div>

                                        <?php if ($has_savings): ?>
                                            <div class="package-pricing-breakdown">
                                                <div class="pricing-row regular">
                                                    <span class="label">Regular Price:</span>
                                                    <span class="value">$<?= number_format($pkg['regular_price'], 2) ?></span>
                                                </div>
                                                <div class="pricing-row package">
                                                    <span class="label">Your Price:</span>
                                                    <span class="value">$<?= number_format($pkg['package_price'], 2) ?></span>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="support-package-price">
                                                <span class="price-amount">$<?= number_format($pkg['price_monthly'], 2) ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <label class="support-package-checkbox">
                                            <input type="checkbox" name="support_packages[]" value="<?= htmlspecialchars($pkg['name']) ?>" data-price="<?= $has_savings ? $pkg['package_price'] : $pkg['price_monthly'] ?>">
                                            Add to my plan
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- SINGLE FORM SECTION -->
                    <div class="selection-form">
                        <h3 id="form-title">Complete Your Selection</h3>
                        
                        <div id="selected-option-display" style="display: none;">
                            <div class="selected-option">
                                <strong>Selected:</strong> <span id="selected-option-name"></span><br>
                                <strong>$<span id="selected-price"></span></strong> <span id="selected-type"></span>
                            </div>
                        </div>
                        
                        <form action="next.php" method="POST" onsubmit="return validateForm()">
                            <input type="hidden" name="payment_option" id="payment_option" value="">
                            <input type="hidden" name="contract_uid" value="<?= htmlspecialchars($uid) ?>">
                            <input type="hidden" name="first_name" id="first_name" value="<?= htmlspecialchars($contract['first_name']) ?>">
                            <input type="hidden" name="last_name" id="last_name" value="<?= htmlspecialchars($contract['last_name']) ?>">
                            <input type="hidden" name="email" id="email" value="<?= htmlspecialchars($contract['email']) ?>">
                            
                            <button type="submit" class="continue-btn" id="continue-btn" disabled>
                                Continue to Checkout
                            </button>
                            
                            <div id="selection-error" class="error-message" style="display: none;">
                                Please select a payment option above to continue.
                            </div>
                        </form>
                    </div>
                    
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <p>No pricing options configured for this Personal Development Plan.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="footer">
            <p>LiveWright Personal Development Plans</p>
        </div>
    </div>

    <!-- Floating Cart Button -->
    <button class="floating-cart" id="floatingCart" onclick="toggleCartPanel()">
        <span class="cart-icon">🛒</span>
        <span class="cart-count" id="cartCount">0</span>
        <span class="cart-total" id="cartTotalDisplay">$0.00</span>
    </button>

    <!-- Cart Panel -->
    <div class="cart-panel" id="cartPanel">
        <div class="cart-panel-header">
            <span>Your Selections</span>
            <button class="cart-panel-close" onclick="toggleCartPanel()">×</button>
        </div>
        <div class="cart-panel-content" id="cartPanelContent">
            <!-- Cart items will be populated here -->
        </div>
    </div>

    <script>
    let selectedOptionId = null;
    let selectedOption = null;
    let selectedAddOns = [];
    let cartPanelOpen = false;

    function selectOption(optionId, optionName, description, price, type, subOptionName) {
        // Remove previous selection styling
        document.querySelectorAll('.pricing-tier').forEach(tier => {
            tier.classList.remove('selected');
            // Add class to dim default recommended items
            if (tier.classList.contains('recommended')) {
                tier.classList.add('user-selected-elsewhere');
            }
        });

        // Add selection to clicked tier
        event.currentTarget.classList.add('selected');
        // Remove the dimming class from the selected item
        event.currentTarget.classList.remove('user-selected-elsewhere');

        // Store selection
        selectedOptionId = optionId;
        document.getElementById('payment_option').value = optionId;

        // Build display name
        let displayName = optionName;
        if (subOptionName && subOptionName !== 'Default') {
            displayName += ` - ${subOptionName}`;
        }

        // Store the selected option details
        selectedOption = {
            id: optionId,
            name: displayName,
            price: parseFloat(price),
            type: type
        };

        // Update title to "Your Selection:"
        document.getElementById('form-title').textContent = 'Your Selection:';

        document.getElementById('selected-option-name').textContent = displayName;
        document.getElementById('selected-price').textContent = parseFloat(price).toFixed(2);
        document.getElementById('selected-type').textContent = type.toLowerCase();

        // Show selected option display
        document.getElementById('selected-option-display').style.display = 'block';

        // Enable continue button
        document.getElementById('continue-btn').disabled = false;

        // Hide error message
        document.getElementById('selection-error').style.display = 'none';

        // Update cart
        updateCart();
    }

    function updateCart() {
        let itemCount = 0;
        let total = 0;
        let cartHTML = '';

        // Add main program if selected
        if (selectedOption) {
            itemCount++;
            total += selectedOption.price;
            cartHTML += `
                <div class="cart-item">
                    <div class="cart-item-name">${selectedOption.name}</div>
                    <div class="cart-item-price">$${selectedOption.price.toFixed(2)} <span class="cart-item-type">${selectedOption.type.toLowerCase()}</span></div>
                </div>
            `;
        }

        // Add add-ons
        selectedAddOns.forEach((addon, index) => {
            itemCount++;
            total += addon.price;
            cartHTML += `
                <div class="cart-item">
                    <span class="cart-item-remove" onclick="removeAddOn(${index})">Remove</span>
                    <div class="cart-item-name">${addon.name}</div>
                    <div class="cart-item-price">$${addon.price.toFixed(2)}</div>
                </div>
            `;
        });

        // Show/hide floating cart
        const floatingCart = document.getElementById('floatingCart');
        if (itemCount > 0) {
            floatingCart.style.display = 'flex';
            document.getElementById('cartCount').textContent = itemCount;
            document.getElementById('cartTotalDisplay').textContent = '$' + total.toFixed(2);
        } else {
            floatingCart.style.display = 'none';
            closeCartPanel();
        }

        // Update cart panel content
        if (cartHTML) {
            cartHTML += `
                <div class="cart-total-row">
                    <span>Total:</span>
                    <span class="total-price">$${total.toFixed(2)}</span>
                </div>
                <button class="cart-checkout-btn" onclick="scrollToCheckout()">Continue to Checkout</button>
            `;
        } else {
            cartHTML = '<p style="text-align: center; color: #666;">No items selected yet</p>';
        }

        document.getElementById('cartPanelContent').innerHTML = cartHTML;
    }

    function toggleCartPanel() {
        const panel = document.getElementById('cartPanel');
        cartPanelOpen = !cartPanelOpen;
        panel.classList.toggle('open', cartPanelOpen);
    }

    function closeCartPanel() {
        const panel = document.getElementById('cartPanel');
        cartPanelOpen = false;
        panel.classList.remove('open');
    }

    function scrollToCheckout() {
        closeCartPanel();
        document.querySelector('.selection-form').scrollIntoView({ behavior: 'smooth' });
    }

    function removeAddOn(index) {
        // Uncheck the corresponding checkbox
        const addonName = selectedAddOns[index].name;
        const checkbox = document.querySelector(`input[name="support_packages[]"][value="${addonName}"]`);
        if (checkbox) {
            checkbox.checked = false;
        }
        selectedAddOns.splice(index, 1);
        updateCart();
    }

    function validateForm() {
        if (!selectedOptionId) {
            document.getElementById('selection-error').style.display = 'block';
            return false;
        }
        return true;
    }

    // Initialize add-on checkboxes
    document.addEventListener('DOMContentLoaded', function() {
        const checkboxes = document.querySelectorAll('input[name="support_packages[]"]');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const name = this.value;
                const price = parseFloat(this.dataset.price);

                if (this.checked) {
                    selectedAddOns.push({ name, price });
                } else {
                    selectedAddOns = selectedAddOns.filter(a => a.name !== name);
                }
                updateCart();
            });
        });
    });
    </script>
</body>
</html>
