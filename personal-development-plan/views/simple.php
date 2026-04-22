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
        $yearly = null;
        foreach ($tiers as $t) {
            if ($t['type'] === 'Yearly') { $yearly = $t; break; }
        }
        if (!$yearly) continue;

        $monthly_equiv = $yearly['price'] / 12;
        $label = "Option $option_number";
        $card_sub_description = $sub_description;

        // If this option has real sub-options (not Default), append sub-option name as the coach detail
        if ($sub_name !== 'Default' && count($sub_options) > 1) {
            $card_sub_description = $sub_name;
        } elseif ($sub_name !== 'Default') {
            $card_sub_description = $sub_name;
        }

        $cards[] = [
            'option_number' => $option_number,
            'label' => $label,
            'title' => $title,
            'sub_description' => $card_sub_description,
            'monthly_equiv' => $monthly_equiv,
            'yearly_id' => $yearly['id'],
            'yearly_price' => $yearly['price'],
            'yearly_description' => $yearly['description'],
            'sub_option_name' => $sub_name,
        ];
    }
}
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

            <?php if (empty($cards)): ?>
                <div class="error">
                    <p>No pricing options available for this plan.</p>
                </div>
            <?php else: ?>
                <div class="cards-grid">
                    <?php foreach ($cards as $card): ?>
                        <div class="option-card"
                             data-id="<?= (int)$card['yearly_id'] ?>"
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
                            <input type="hidden"
                                   class="card-data"
                                   data-description="<?= htmlspecialchars($card['yearly_description']) ?>"
                                   data-price="<?= htmlspecialchars((string)$card['yearly_price']) ?>"
                                   data-label="<?= htmlspecialchars($card['label']) ?>"
                                   data-suboption="<?= htmlspecialchars($card['sub_option_name']) ?>">
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
                        <strong>Selected:</strong> <span id="selected-name"></span><br>
                        <strong>$<span id="selected-price"></span></strong> paid annually in advance
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
        function selectCard(cardEl) {
            document.querySelectorAll('.option-card').forEach(c => c.classList.remove('selected'));
            cardEl.classList.add('selected');

            var id = cardEl.getAttribute('data-id');
            var data = cardEl.querySelector('.card-data');
            var label = data.getAttribute('data-label');
            var suboption = data.getAttribute('data-suboption');
            var price = parseFloat(data.getAttribute('data-price'));

            var displayName = label;
            if (suboption && suboption !== 'Default') displayName += ' — ' + suboption;

            document.getElementById('payment_option').value = id;
            document.getElementById('selected-name').textContent = displayName;
            document.getElementById('selected-price').textContent = price.toFixed(2);
            document.getElementById('selected-option-display').classList.add('visible');
            document.getElementById('continue-btn').disabled = false;
            document.getElementById('form-title').textContent = 'Your Selection:';
        }

        function validateForm() {
            return !!document.getElementById('payment_option').value;
        }
    </script>
</body>
</html>
