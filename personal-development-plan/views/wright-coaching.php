<?php
/**
 * Wright Coaching skin — table layout for Drs. Bob & Judith Wright coaching packages.
 *
 * Renders the screenshot-matching layout:
 *   Option 1: hours-tier rows × Prepaid / 2-pay / 4-pay columns
 *   Option 2a: 6 months × Prepaid / 2-pay / 3-pay
 *   Option 2b: 3 months × Prepaid / 2-pay / 3-pay
 *
 * Schema mapping (existing pricing_options.type slots):
 *   Yearly    => Prepaid
 *   Quarterly => 2-pay
 *   Monthly   => 4-pay (Option 1) or 3-pay (Options 2a/2b)
 *
 * Expects $contract, $options, $uid in scope.
 */

// Pluck a tier by type out of the [sub_name => [tier rows...]] structure.
$pick = function (array $tiers, string $type) {
    foreach ($tiers as $t) {
        if (($t['type'] ?? null) === $type) return $t;
    }
    return null;
};

$money = function ($n) {
    return '$' . number_format((float)$n, 0);
};

// For each option, define the right-most column label (the per-period plan label).
// Option 1 = "4-pay plan", Options 2a/2b = "3-pay plan".
$plan_labels = [
    1 => ['Yearly' => 'Prepaid Price', 'Quarterly' => '2-pay plan', 'Monthly' => '4-pay plan'],
    2 => ['Yearly' => 'Prepaid Price', 'Quarterly' => '2-pay plan', 'Monthly' => '3-pay plan'],
    3 => ['Yearly' => 'Prepaid Price', 'Quarterly' => '2-pay plan', 'Monthly' => '3-pay plan'],
];

$option_titles = [
    1 => 'Option 1',
    2 => 'Option 2a',
    3 => 'Option 2b',
];

$option_subtitles = [
    1 => 'Hours to be used and scheduled as needed with either Dr. Bob or Dr. Judith',
    2 => '45 minutes of coaching weekly with Dr. Bob<br>+ 45 minutes of coaching every other week with Dr. Judith<br><strong>For 6 months</strong>',
    3 => '45 minutes of coaching weekly with Dr. Bob<br>+ 45 minutes of coaching every other week with Dr. Judith<br><strong>For 3 months</strong>',
];

// Option 1 row label header (varies by option).
$row_header_label = [
    1 => '# of hours',
    2 => 'Term',
    3 => 'Term',
];

// Cosmetic: sort sub-options for Option 1 in 5/10/20 order. Numeric prefix sort works.
$sort_subs = function ($subs) {
    $names = array_keys($subs);
    natsort($names);
    $sorted = [];
    foreach ($names as $n) $sorted[$n] = $subs[$n];
    return $sorted;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coaching &amp; Consulting Packages — Drs. Bob &amp; Judith Wright</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            margin: 0;
            padding: 24px 16px;
            background: #f8f9fa;
            color: #222;
        }
        .container { max-width: 860px; margin: 0 auto; background: white; padding: 36px 32px; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
        .logo { text-align: center; margin-bottom: 18px; }
        .logo img { width: 180px; height: auto; }
        h1.title { text-align: center; font-size: 1.4em; margin: 0 0 4px; color: #005FA3; line-height: 1.35; }
        h1.title small { display: block; font-size: 0.85em; color: #444; font-weight: 600; margin-top: 4px; }

        .option-block { margin-top: 36px; }
        .option-block.first { margin-top: 24px; }
        .option-title { text-align: center; font-size: 1.15em; font-weight: 700; margin: 0 0 6px; color: #222; }
        .option-subtitle { text-align: center; color: #444; margin: 0 0 14px; font-size: 0.98em; line-height: 1.45; }

        table.pricing {
            border-collapse: collapse;
            margin: 0 auto;
            min-width: 520px;
        }
        table.pricing th, table.pricing td {
            border: 1px solid #888;
            padding: 8px 16px;
            text-align: left;
            font-size: 0.98em;
        }
        table.pricing th {
            background: #f3f6f9;
            font-weight: 700;
        }
        table.pricing tr.selectable { cursor: pointer; transition: background 0.15s; }
        table.pricing tr.selectable:hover { background: #f0f8ff; }
        table.pricing td.price-cell { cursor: pointer; transition: background 0.15s, outline 0.15s; }
        table.pricing td.price-cell:hover { background: #e7f4fb; }
        table.pricing td.price-cell.selected {
            background: #d4edda;
            outline: 2px solid #28a745;
            outline-offset: -2px;
            font-weight: 700;
        }

        .footnotes { margin-top: 30px; color: #555; font-size: 0.85em; line-height: 1.55; border-top: 1px solid #eee; padding-top: 14px; }
        .footnotes p { margin: 0 0 4px; }

        .selection-form { margin-top: 28px; padding: 22px; background: #f8f9fa; border-radius: 6px; border-top: 3px solid #005FA3; }
        .selection-form h3 { margin: 0 0 12px; }
        .selected-display { background: #d4edda; padding: 12px 14px; border-radius: 4px; border-left: 4px solid #28a745; margin-bottom: 14px; display: none; }
        .selected-display.visible { display: block; }
        .continue-btn {
            background: #28a745; color: white; padding: 13px 24px; border: none;
            border-radius: 6px; font-size: 16px; font-weight: 700; cursor: pointer; width: 100%;
        }
        .continue-btn:hover:not(:disabled) { background: #218838; }
        .continue-btn:disabled { background: #94a3b1; cursor: not-allowed; }

        .signed-badge { background: #28a745; color: white; padding: 3px 12px; border-radius: 20px; font-size: 0.8em; margin-left: 8px; }

        @media (max-width: 600px) {
            .container { padding: 22px 14px; }
            table.pricing { min-width: 100%; font-size: 0.92em; }
            table.pricing th, table.pricing td { padding: 7px 10px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <img src="https://cdn.shortpixel.ai/spai/q_lossy+ret_img+to_webp/livewright.com/wp-content/uploads/2023/12/LiveWright-logo%E2%80%934C-padded.png" alt="LiveWright">
        </div>

        <h1 class="title">
            Coaching &amp; consulting packages<br>
            with Drs. Bob and Judith Wright
            <?php if (!empty($contract['signed'])): ?>
                <span class="signed-badge">✓ Signed</span>
            <?php endif; ?>
        </h1>

        <?php
        $first = true;
        foreach ([1, 2, 3] as $opt_num):
            if (empty($options[$opt_num])) continue;

            $sub_options = $options[$opt_num];
            if ($opt_num === 1) $sub_options = $sort_subs($sub_options);

            $labels = $plan_labels[$opt_num];
            $row_label = $row_header_label[$opt_num];
            $is_simple = isset($sub_options['Default']) && count($sub_options) === 1;
        ?>
            <div class="option-block <?= $first ? 'first' : '' ?>">
                <div class="option-title"><?= htmlspecialchars($option_titles[$opt_num]) ?></div>
                <div class="option-subtitle"><?= $option_subtitles[$opt_num] ?></div>

                <table class="pricing">
                    <thead>
                        <tr>
                            <?php if (!$is_simple): ?>
                                <th><?= htmlspecialchars($row_label) ?></th>
                            <?php endif; ?>
                            <th><?= htmlspecialchars($labels['Yearly']) ?></th>
                            <th><?= htmlspecialchars($labels['Quarterly']) ?></th>
                            <th><?= htmlspecialchars($labels['Monthly']) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sub_options as $sub_name => $tiers):
                            $y = $pick($tiers, 'Yearly');
                            $q = $pick($tiers, 'Quarterly');
                            $m = $pick($tiers, 'Monthly');
                        ?>
                            <tr class="selectable">
                                <?php if (!$is_simple): ?>
                                    <td><?= htmlspecialchars($sub_name) ?></td>
                                <?php endif; ?>
                                <?php foreach ([$y, $q, $m] as $tier): ?>
                                    <?php if ($tier && (float)$tier['price'] > 0): ?>
                                        <td class="price-cell"
                                            data-id="<?= (int)$tier['id'] ?>"
                                            data-price="<?= (float)$tier['price'] ?>"
                                            data-type="<?= htmlspecialchars($tier['type']) ?>"
                                            data-option="<?= (int)$opt_num ?>"
                                            data-sub="<?= htmlspecialchars($sub_name) ?>"
                                            onclick="selectPrice(this)">
                                            <?= $money($tier['price']) ?>
                                        </td>
                                    <?php else: ?>
                                        <td>&mdash;</td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php $first = false; endforeach; ?>

        <div class="footnotes">
            <p>Click any price above to select that payment plan.</p>
        </div>

        <div class="selection-form">
            <h3 id="form-title">Complete Your Selection</h3>
            <div id="selected-display" class="selected-display">
                <strong>Selected:</strong> <span id="selected-name"></span><br>
                <strong id="selected-price"></strong> <span id="selected-detail"></span>
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
    </div>

    <script>
        // Map data-type/data-option pairs to user-friendly plan names.
        var PLAN_LABELS = <?= json_encode($plan_labels, JSON_UNESCAPED_SLASHES) ?>;
        var OPTION_TITLES = <?= json_encode($option_titles, JSON_UNESCAPED_SLASHES) ?>;

        function selectPrice(cell) {
            document.querySelectorAll('td.price-cell.selected').forEach(c => c.classList.remove('selected'));
            cell.classList.add('selected');

            var id    = cell.getAttribute('data-id');
            var price = parseFloat(cell.getAttribute('data-price'));
            var type  = cell.getAttribute('data-type');
            var opt   = cell.getAttribute('data-option');
            var sub   = cell.getAttribute('data-sub');

            var optTitle = OPTION_TITLES[opt] || ('Option ' + opt);
            var planLabel = (PLAN_LABELS[opt] && PLAN_LABELS[opt][type]) || type;

            var name = optTitle;
            if (sub && sub !== 'Default') name += ' — ' + sub;
            name += ' (' + planLabel + ')';

            document.getElementById('payment_option').value = id;
            document.getElementById('selected-name').textContent = name;
            document.getElementById('selected-price').textContent = '$' + price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('selected-detail').textContent = '';
            document.getElementById('selected-display').classList.add('visible');
            document.getElementById('continue-btn').disabled = false;
            document.getElementById('form-title').textContent = 'Your Selection:';
        }

        function validateForm() {
            return !!document.getElementById('payment_option').value;
        }
    </script>
</body>
</html>
