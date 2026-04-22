<?php
/**
 * Simple skin for the PDP page.
 * Renders a simplified grid of Option cards.
 * Prices shown are annualized → displayed as monthly equivalent (yearly / 12).
 * Clicking a card submits the Yearly pricing_option id so the customer
 * actually gets the pay-in-full discount.
 *
 * Expects $contract, $options, $uid in scope.
 */

require_once __DIR__ . '/../lib/pathways_default.php';

// Flatten options → cards. Each sub-option becomes its own card;
// options without sub-options become one card.
$cards = [];
foreach ($options as $option_number => $sub_options) {
    $first_sub = reset($sub_options);
    $description_html = $first_sub[0]['description'] ?? '';

    // Pull title out of first <strong> tag if present
    $title = "Option $option_number";
    $sub_description = '';
    if (preg_match('/<strong>(.*?)<\/strong>/', $description_html, $m)) {
        $title = strip_tags($m[1]);
        $sub_description = trim(strip_tags(str_replace($m[0], '', $description_html)));
    } else {
        $sub_description = trim(strip_tags($description_html));
    }

    foreach ($sub_options as $sub_name => $tiers) {
        $yearly = null; $monthly = null; $quarterly = null;
        foreach ($tiers as $t) {
            if ($t['type'] === 'Yearly') $yearly = $t;
            elseif ($t['type'] === 'Monthly') $monthly = $t;
            elseif ($t['type'] === 'Quarterly') $quarterly = $t;
        }
        if (!$yearly) continue;

        $monthly_equiv = $yearly['price'] / 12;
        $label = "Option $option_number";
        $card_sub_description = $sub_description;
        if ($sub_name !== 'Default') {
            $card_sub_description = $sub_name;
        }

        $build_tier = function($tier) {
            if (!$tier) return null;
            return [
                'id' => (int)$tier['id'],
                'price' => (float)$tier['price'],
                'type' => $tier['type'],
            ];
        };

        $cards[] = [
            'option_number' => $option_number,
            'label' => $label,
            'title' => $title,
            'sub_description' => $card_sub_description,
            'monthly_equiv' => $monthly_equiv,
            'sub_option_name' => $sub_name,
            'tiers' => [
                'Yearly' => $build_tier($yearly),
                'Quarterly' => $build_tier($quarterly),
                'Monthly' => $build_tier($monthly),
            ],
        ];
    }
}

$cards_js = array_map(function($c) {
    return [
        'label' => $c['label'],
        'sub_option_name' => $c['sub_option_name'],
        'tiers' => $c['tiers'],
    ];
}, $cards);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal Development Plan - <?= htmlspecialchars($contract['first_name'] . ' ' . $contract['last_name']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f8f9fa;
            color: #333;
        }
        .container { max-width: 1100px; margin: 0 auto; }
        .header {
            background: white;
            padding: 30px;
            border-radius: 8px 8px 0 0;
            text-align: center;
            border-bottom: 3px solid #2BB5B0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .header img.logo { width: 180px; height: auto; margin-bottom: 10px; }
        .header h1 { color: #005FA3; margin: 0 0 5px 0; font-size: 1.6em; }
        .header p { color: #555; margin: 0; }

        .body-card {
            background: white;
            padding: 30px;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .greeting {
            margin-bottom: 30px;
            padding: 18px 22px;
            background: #f8f9fa;
            border-left: 4px solid #005FA3;
            border-radius: 4px;
            line-height: 1.6;
        }
        .greeting p:last-child { margin-bottom: 0; }

        .pathways {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 16px;
            align-items: stretch;
            margin: 25px 0 10px;
        }
        .pathway-card {
            background: white;
            border: 2px solid #e6edf3;
            border-radius: 10px;
            padding: 20px 22px;
            display: flex;
            flex-direction: column;
        }
        .pathway-card.from { border-left: 5px solid #005FA3; }
        .pathway-card.toward { border-left: 5px solid #2BB5B0; }
        .pathway-label {
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.75em;
            font-weight: 700;
            color: #888;
            margin-bottom: 4px;
        }
        .pathway-card.from .pathway-label { color: #005FA3; }
        .pathway-card.toward .pathway-label { color: #2BB5B0; }
        .pathway-heading {
            font-size: 1.1em;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
        }
        .pathway-body { color: #444; line-height: 1.5; font-size: 0.95em; }
        .pathway-body p:last-child { margin-bottom: 0; }
        .pathway-body ul, .pathway-body ol { padding-left: 22px; margin: 6px 0; }
        .pathway-body li { margin-bottom: 10px; }
        .pathway-body li:last-child { margin-bottom: 0; }
        .pathway-arrow {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2BB5B0;
            font-size: 2em;
            font-weight: 700;
            padding: 0 4px;
        }
        @media (max-width: 640px) {
            .pathways { grid-template-columns: 1fr; }
            .pathway-arrow { transform: rotate(90deg); padding: 4px 0; }
        }

        .pathways-detail {
            background: white;
            border: 2px solid #e6edf3;
            border-left: 5px solid #2BB5B0;
            border-radius: 10px;
            padding: 22px 26px;
            margin: 22px 0 8px;
            line-height: 1.6;
            color: #333;
        }
        .pathways-detail p { margin: 0 0 10px; }
        .pathways-detail p:last-child { margin-bottom: 0; }
        .pathways-detail ol, .pathways-detail ul { padding-left: 24px; margin: 8px 0; }
        .pathways-detail li { margin-bottom: 10px; }
        .pathways-detail li:last-child { margin-bottom: 0; }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 18px;
            margin: 30px 0 20px;
        }

        .option-card {
            background: white;
            border: 2px solid #e6edf3;
            border-radius: 10px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.1s;
        }
        .option-card:hover {
            border-color: #005FA3;
            box-shadow: 0 6px 18px rgba(0, 95, 163, 0.12);
        }

        .card-top {
            background: linear-gradient(135deg, #005FA3 0%, #2BB5B0 100%);
            color: white;
            padding: 24px 18px;
            text-align: center;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .card-top .card-title {
            font-size: 1.15em;
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 8px;
        }
        .card-top .card-subtitle {
            font-size: 0.92em;
            line-height: 1.4;
            opacity: 0.95;
        }

        .card-middle {
            padding: 22px 18px 8px;
            text-align: center;
            border-bottom: 1px solid #f0f0f0;
        }
        .card-middle .option-label {
            color: #005FA3;
            font-size: 1.6em;
            font-weight: 700;
            margin-bottom: 2px;
        }
        .card-middle .price {
            color: #333;
            font-size: 1.3em;
            font-weight: 700;
        }
        .card-middle .price .asterisk {
            color: #2BB5B0;
        }

        .card-bottom {
            padding: 18px;
            text-align: center;
        }
        .card-bottom button {
            background: #005FA3;
            color: white;
            border: none;
            padding: 12px 22px;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.05s;
            width: 100%;
            max-width: 180px;
        }
        .card-bottom button:hover { background: #004a82; }
        .card-bottom button:active { transform: scale(0.98); }

        .option-card.selected { border-color: #28a745; }
        .option-card.selected .card-bottom button { background: #28a745; }
        .option-card.selected .card-bottom button:hover { background: #218838; }

        .footnotes {
            margin-top: 25px;
            padding: 15px 0 0;
            color: #666;
            font-size: 0.85em;
            line-height: 1.6;
            border-top: 1px solid #eee;
        }
        .footnotes p { margin: 0 0 6px; }

        .selection-form {
            background: #f8f9fa;
            padding: 25px;
            margin-top: 25px;
            border-radius: 6px;
            border-top: 3px solid #005FA3;
        }
        .selection-form h3 { margin-top: 0; color: #333; }
        .selected-option {
            background: #d4edda;
            padding: 14px;
            border-radius: 4px;
            margin-bottom: 18px;
            border-left: 4px solid #28a745;
            display: none;
        }
        .selected-option.visible { display: block; }

        .billing-choice { margin: 20px 0 24px; display: none; }
        .billing-choice.visible { display: block; }
        .billing-choice h4 {
            margin: 0 0 12px;
            color: #333;
            font-size: 1.05em;
        }
        .billing-tiers {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        .billing-tier {
            background: white;
            border: 2px solid #e6edf3;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.05s;
        }
        .billing-tier:hover {
            border-color: #005FA3;
            box-shadow: 0 4px 12px rgba(0, 95, 163, 0.1);
        }
        .billing-tier.selected {
            border-color: #28a745;
            background: #f0fff4;
        }
        .billing-tier .tier-label {
            font-weight: 700;
            color: #005FA3;
            margin-bottom: 6px;
            font-size: 1em;
        }
        .billing-tier.selected .tier-label { color: #28a745; }
        .billing-tier .tier-price {
            font-size: 1.35em;
            font-weight: 700;
            color: #333;
            margin-bottom: 2px;
        }
        .billing-tier .tier-detail {
            color: #666;
            font-size: 0.82em;
        }
        .billing-tier .tier-savings {
            color: #28a745;
            font-size: 0.8em;
            font-weight: 600;
            margin-top: 4px;
        }

        .continue-btn {
            background: #28a745;
            color: white;
            padding: 14px 26px;
            border: none;
            border-radius: 6px;
            font-size: 17px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
        }
        .continue-btn:hover { background: #218838; }
        .continue-btn:disabled { background: #6c757d; cursor: not-allowed; }

        .error { background: #f8d7da; color: #721c24; padding: 20px; border-radius: 4px; text-align: center; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 0.9em; }
        .signed-badge { background: #28a745; color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.85em; margin-left: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="https://cdn.shortpixel.ai/spai/q_lossy+ret_img+to_webp/livewright.com/wp-content/uploads/2023/12/LiveWright-logo%E2%80%934C-padded.png" alt="LiveWright" class="logo">
            <h1>Personal Development Plan</h1>
            <p>For <?= htmlspecialchars($contract['first_name'] . ' ' . $contract['last_name']) ?>
                <?php if ($contract['signed']): ?>
                    <span class="signed-badge">✓ Signed</span>
                <?php endif; ?>
            </p>
        </div>

        <div class="body-card">
            <?php if (!empty($contract['greeting'])): ?>
                <div class="greeting"><?= $contract['greeting'] ?></div>
            <?php endif; ?>

            <?php if (!empty($contract['pdp_from']) || !empty($contract['pdp_toward'])): ?>
                <div class="pathways">
                    <div class="pathway-card from">
                        <div class="pathway-label">From</div>
                        <div class="pathway-heading">Present State Challenges &amp; Opportunities</div>
                        <div class="pathway-body"><?= $contract['pdp_from'] ?? '' ?></div>
                    </div>
                    <div class="pathway-arrow" aria-hidden="true">&rarr;</div>
                    <div class="pathway-card toward">
                        <div class="pathway-label">Toward</div>
                        <div class="pathway-heading">Ideal State Outcomes</div>
                        <div class="pathway-body"><?= $contract['pdp_toward'] ?? '' ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="pathways-detail">
                <?= pdp_resolve_pathways_html($contract) ?>
            </div>

            <?php if (empty($cards)): ?>
                <div class="error">
                    <p>No pricing options available for this plan.</p>
                </div>
            <?php else: ?>
                <div class="cards-grid">
                    <?php foreach ($cards as $idx => $card): ?>
                        <div class="option-card"
                             data-index="<?= (int)$idx ?>"
                             onclick="selectCard(this)">
                            <div class="card-top">
                                <div class="card-title"><?= htmlspecialchars($card['title']) ?></div>
                                <?php if (!empty($card['sub_description'])): ?>
                                    <div class="card-subtitle"><?= htmlspecialchars($card['sub_description']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="card-middle">
                                <div class="option-label"><?= htmlspecialchars($card['label']) ?></div>
                                <div class="price">
                                    $<?= number_format($card['monthly_equiv'], 0) ?>/month<span class="asterisk">**</span>
                                </div>
                            </div>
                            <div class="card-bottom">
                                <button type="button">Select</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="footnotes">
                    <p>* Financial aid is available for those who demonstrate need and are committed to fully engaging their growth capacity.</p>
                    <p>** Program runs for 12 months. Price shown is the monthly equivalent when paid annually in advance.</p>
                </div>

                <div class="selection-form">
                    <h3 id="form-title">Complete Your Selection</h3>
                    <div id="selected-option-display" class="selected-option">
                        <strong>Selected:</strong> <span id="selected-name"></span>
                    </div>

                    <div class="billing-choice" id="billing-choice">
                        <h4>Choose how you'd like to pay:</h4>
                        <div class="billing-tiers" id="billing-tiers"></div>
                    </div>

                    <form action="next.php" method="POST" onsubmit="return validateForm()">
                        <input type="hidden" name="payment_option" id="payment_option" value="">
                        <input type="hidden" name="contract_uid" value="<?= htmlspecialchars($uid) ?>">
                        <input type="hidden" name="first_name" value="<?= htmlspecialchars($contract['first_name']) ?>">
                        <input type="hidden" name="last_name" value="<?= htmlspecialchars($contract['last_name']) ?>">
                        <input type="hidden" name="email" value="<?= htmlspecialchars($contract['email']) ?>">
                        <button type="submit" class="continue-btn" id="continue-btn" disabled>
                            Continue to Checkout
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>LiveWright Personal Development Plans</p>
        </div>
    </div>

    <script>
        var CARDS = <?= json_encode($cards_js, JSON_UNESCAPED_SLASHES) ?>;
        var currentCardIndex = null;

        function selectCard(cardEl) {
            document.querySelectorAll('.option-card').forEach(c => c.classList.remove('selected'));
            cardEl.classList.add('selected');

            currentCardIndex = parseInt(cardEl.getAttribute('data-index'), 10);
            var card = CARDS[currentCardIndex];
            if (!card) return;

            var displayName = card.label;
            if (card.sub_option_name && card.sub_option_name !== 'Default') {
                displayName += ' — ' + card.sub_option_name;
            }

            document.getElementById('selected-name').textContent = displayName;
            document.getElementById('selected-option-display').classList.add('visible');
            document.getElementById('form-title').textContent = 'Your Selection:';

            // Clear any prior billing selection
            document.getElementById('payment_option').value = '';
            document.getElementById('continue-btn').disabled = true;

            renderBillingTiers(card);
        }

        function renderBillingTiers(card) {
            var container = document.getElementById('billing-tiers');
            container.innerHTML = '';

            var order = ['Yearly', 'Quarterly', 'Monthly'];
            var yearlyPrice = card.tiers.Yearly ? card.tiers.Yearly.price : 0;

            var details = {
                'Yearly':    { title: 'Pay in Full',      detail: 'one-time payment for 12 months', perPeriod: 'year' },
                'Quarterly': { title: 'Quarterly',        detail: '4 payments over 12 months',       perPeriod: 'quarter' },
                'Monthly':   { title: 'Monthly',          detail: '12 payments over 12 months',      perPeriod: 'month' }
            };

            order.forEach(function (type) {
                var tier = card.tiers[type];
                if (!tier) return;

                var meta = details[type];
                var el = document.createElement('div');
                el.className = 'billing-tier';
                el.setAttribute('data-id', tier.id);
                el.setAttribute('data-type', type);

                var savingsHtml = '';
                if (type !== 'Yearly' && yearlyPrice > 0) {
                    var periods = (type === 'Monthly') ? 12 : 4;
                    var totalAtThisRate = tier.price * periods;
                    var savings = totalAtThisRate - yearlyPrice;
                    if (savings > 0) {
                        savingsHtml = '<div class="tier-savings">Save $' + formatMoney(savings) + ' with Pay in Full</div>';
                    }
                }
                if (type === 'Yearly') {
                    savingsHtml = '<div class="tier-savings">Best value</div>';
                }

                el.innerHTML =
                    '<div class="tier-label">' + meta.title + '</div>' +
                    '<div class="tier-price">$' + formatMoney(tier.price) + '<span style="font-size:0.7em;color:#666;font-weight:600;">/' + meta.perPeriod + '</span></div>' +
                    '<div class="tier-detail">' + meta.detail + '</div>' +
                    savingsHtml;

                el.addEventListener('click', function () { selectBillingTier(el, tier); });
                container.appendChild(el);
            });

            document.getElementById('billing-choice').classList.add('visible');
        }

        function selectBillingTier(el, tier) {
            document.querySelectorAll('.billing-tier').forEach(t => t.classList.remove('selected'));
            el.classList.add('selected');
            document.getElementById('payment_option').value = tier.id;
            document.getElementById('continue-btn').disabled = false;
        }

        function formatMoney(n) {
            return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function validateForm() {
            return !!document.getElementById('payment_option').value;
        }
    </script>
</body>
</html>
