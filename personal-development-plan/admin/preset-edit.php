<?php
require_once '../config.php';
requireLogin();

$preset_id = $_GET['id'] ?? null;
$preset = null;

// Load existing preset if editing
if ($preset_id) {
    $stmt = $pdo->prepare("SELECT * FROM pdp_presets WHERE id = ?");
    $stmt->execute([$preset_id]);
    $preset = $stmt->fetch();
    
    if (!$preset) {
        header('Location: presets.php');
        exit;
    }
}

$errors = [];
$success = '';

if ($_POST) {
    $name = trim($_POST['name'] ?? '');
    $contract_description = trim($_POST['contract_description'] ?? '');
    $pdp_from = trim($_POST['pdp_from'] ?? '');
    $pdp_toward = trim($_POST['pdp_toward'] ?? '');
    
    // Validation
    if (empty($name)) $errors[] = 'Preset name is required';
    
    // Check name uniqueness
    if (!empty($name)) {
        $stmt = $pdo->prepare("SELECT id FROM pdp_presets WHERE name = ?" . ($preset_id ? " AND id != ?" : ""));
        $params = [$name];
        if ($preset_id) $params[] = $preset_id;
        $stmt->execute($params);
        if ($stmt->fetch()) $errors[] = 'Preset name already exists';
    }
    
    // Process sub-options for each option
    $sub_options_data = [1 => null, 2 => null, 3 => null];
    
    for ($i = 1; $i <= 3; $i++) {
        $sub_options = [];
        for ($s = 1; $s <= 10; $s++) {
            $sub_name = trim($_POST["option_{$i}_sub_{$s}_name"] ?? '');
            if (!empty($sub_name)) {
                $sub_options[] = [
                    'name' => $sub_name,
                    'monthly' => !empty($_POST["option_{$i}_sub_{$s}_price_monthly"]) ? floatval($_POST["option_{$i}_sub_{$s}_price_monthly"]) : null,
                    'quarterly' => !empty($_POST["option_{$i}_sub_{$s}_price_quarterly"]) ? floatval($_POST["option_{$i}_sub_{$s}_price_quarterly"]) : null,
                    'yearly' => !empty($_POST["option_{$i}_sub_{$s}_price_yearly"]) ? floatval($_POST["option_{$i}_sub_{$s}_price_yearly"]) : null
                ];
            }
        }
        if (!empty($sub_options)) {
            $sub_options_data[$i] = json_encode($sub_options);
        }
    }
    
    if (empty($errors)) {
        try {
            if ($preset_id) {
                // Update preset
                $stmt = $pdo->prepare("
                    UPDATE pdp_presets SET 
                    name = ?, contract_description = ?, pdp_from = ?, pdp_toward = ?,
                    option_1_desc = ?, option_1_price_monthly = ?, option_1_price_quarterly = ?, option_1_price_yearly = ?, option_1_sub_options = ?,
                    option_2_desc = ?, option_2_price_monthly = ?, option_2_price_quarterly = ?, option_2_price_yearly = ?, option_2_sub_options = ?,
                    option_3_desc = ?, option_3_price_monthly = ?, option_3_price_quarterly = ?, option_3_price_yearly = ?, option_3_sub_options = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $name, $contract_description, $pdp_from, $pdp_toward,
                    $_POST['option_1_desc'] ?? '', $_POST['option_1_price_monthly'] ?? null, $_POST['option_1_price_quarterly'] ?? null, $_POST['option_1_price_yearly'] ?? null, $sub_options_data[1],
                    $_POST['option_2_desc'] ?? '', $_POST['option_2_price_monthly'] ?? null, $_POST['option_2_price_quarterly'] ?? null, $_POST['option_2_price_yearly'] ?? null, $sub_options_data[2],
                    $_POST['option_3_desc'] ?? '', $_POST['option_3_price_monthly'] ?? null, $_POST['option_3_price_quarterly'] ?? null, $_POST['option_3_price_yearly'] ?? null, $sub_options_data[3],
                    $preset_id
                ]);
            } else {
                // Insert new preset
                $stmt = $pdo->prepare("
                    INSERT INTO pdp_presets 
                    (name, contract_description, pdp_from, pdp_toward, 
                     option_1_desc, option_1_price_monthly, option_1_price_quarterly, option_1_price_yearly, option_1_sub_options,
                     option_2_desc, option_2_price_monthly, option_2_price_quarterly, option_2_price_yearly, option_2_sub_options,
                     option_3_desc, option_3_price_monthly, option_3_price_quarterly, option_3_price_yearly, option_3_sub_options)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $name, $contract_description, $pdp_from, $pdp_toward,
                    $_POST['option_1_desc'] ?? '', $_POST['option_1_price_monthly'] ?? null, $_POST['option_1_price_quarterly'] ?? null, $_POST['option_1_price_yearly'] ?? null, $sub_options_data[1],
                    $_POST['option_2_desc'] ?? '', $_POST['option_2_price_monthly'] ?? null, $_POST['option_2_price_quarterly'] ?? null, $_POST['option_2_price_yearly'] ?? null, $sub_options_data[2],
                    $_POST['option_3_desc'] ?? '', $_POST['option_3_price_monthly'] ?? null, $_POST['option_3_price_quarterly'] ?? null, $_POST['option_3_price_yearly'] ?? null, $sub_options_data[3]
                ]);
            }
            
            $success = 'Preset saved successfully';
            header("refresh:2;url=presets.php");
            
        } catch (Exception $e) {
            $errors[] = 'Error saving preset: ' . $e->getMessage();
        }
    }
}

// Prepare form data
$form_data = [
    'name' => $_POST['name'] ?? ($preset['name'] ?? ''),
    'contract_description' => $_POST['contract_description'] ?? ($preset['contract_description'] ?? ''),
    'pdp_from' => $_POST['pdp_from'] ?? ($preset['pdp_from'] ?? ''),
    'pdp_toward' => $_POST['pdp_toward'] ?? ($preset['pdp_toward'] ?? '')
];

// Prepare options data
$form_options = [];
for ($i = 1; $i <= 3; $i++) {
    $form_options[$i] = [
        'desc' => $_POST["option_{$i}_desc"] ?? ($preset["option_{$i}_desc"] ?? ''),
        'monthly' => $_POST["option_{$i}_price_monthly"] ?? ($preset["option_{$i}_price_monthly"] ?? ''),
        'quarterly' => $_POST["option_{$i}_price_quarterly"] ?? ($preset["option_{$i}_price_quarterly"] ?? ''),
        'yearly' => $_POST["option_{$i}_price_yearly"] ?? ($preset["option_{$i}_price_yearly"] ?? ''),
        'sub_options' => []
    ];
    
    // Load sub-options from preset
    if ($preset && !empty($preset["option_{$i}_sub_options"])) {
        $sub_options = json_decode($preset["option_{$i}_sub_options"], true);
        if (is_array($sub_options)) {
            $form_options[$i]['sub_options'] = $sub_options;
        }
    }
    
    // Override with POST data if submitted
    if ($_POST) {
        $form_options[$i]['sub_options'] = [];
        for ($s = 1; $s <= 10; $s++) {
            $sub_name = trim($_POST["option_{$i}_sub_{$s}_name"] ?? '');
            if (!empty($sub_name)) {
                $form_options[$i]['sub_options'][] = [
                    'name' => $sub_name,
                    'monthly' => $_POST["option_{$i}_sub_{$s}_price_monthly"] ?? '',
                    'quarterly' => $_POST["option_{$i}_sub_{$s}_price_quarterly"] ?? '',
                    'yearly' => $_POST["option_{$i}_sub_{$s}_price_yearly"] ?? ''
                ];
            }
        }
    }
}

$page_title = ($preset_id ? 'Edit' : 'Add New') . ' Preset';
$page_head = '<link href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css" rel="stylesheet">';
require_once 'includes/header.php';
?>

    <div class="admin-content narrow">
    <h1><?= $preset_id ? 'Edit' : 'Add New' ?> Preset</h1>
    
    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?> - Redirecting...</div>
    <?php endif; ?>
    
    <?php if (!empty($errors)): ?>
        <div class="error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label>Preset Name:</label>
            <input type="text" name="name" value="<?= htmlspecialchars($form_data['name']) ?>" required>
        </div>
        
        <div class="description-group">
            <label>FROM – Present State Challenges and Opportunities:</label>
            <div id="pdp_from_editor" class="quill-editor"></div>
            <textarea name="pdp_from" class="hidden"><?= htmlspecialchars($form_data['pdp_from']) ?></textarea>
        </div>
        
        <div class="description-group">
            <label>TOWARD – Ideal State Outcomes:</label>
            <div id="pdp_toward_editor" class="quill-editor"></div>
            <textarea name="pdp_toward" class="hidden"><?= htmlspecialchars($form_data['pdp_toward']) ?></textarea>
        </div>
        
        <div class="description-group">
            <label>Included in Each Option:</label>
            <div id="contract_editor" class="quill-editor"></div>
            <textarea name="contract_description" class="hidden"><?= htmlspecialchars($form_data['contract_description']) ?></textarea>
            <small style="color: #666; font-style: italic;">Items included with all options</small>
        </div>
        
        <?php for ($i = 1; $i <= 3; $i++): ?>
            <div class="option-section">
                <h3>Option <?= $i ?></h3>
                
                <div class="description-group">
                    <label>Description:</label>
                    <div id="editor_<?= $i ?>" class="quill-editor"></div>
                    <textarea name="option_<?= $i ?>_desc" class="hidden"><?= htmlspecialchars($form_options[$i]['desc']) ?></textarea>
                </div>
                
                <div id="sub-options-container-<?= $i ?>">
                    <?php if (empty($form_options[$i]['sub_options'])): ?>
                        <div class="pricing-grid">
                            <div class="pricing-column">
                                <h4 <?= !empty($form_options[$i]['yearly']) ? 'class="has-price"' : '' ?>>Pay in Full</h4>
                                <input type="number" step="0.01" name="option_<?= $i ?>_price_yearly" value="<?= htmlspecialchars($form_options[$i]['yearly']) ?>" placeholder="$0.00" onchange="updatePriceHeader(this)">
                                <small>Full program price</small>
                            </div>

                            <div class="pricing-column">
                                <h4 <?= !empty($form_options[$i]['monthly']) ? 'class="has-price"' : '' ?>>Monthly</h4>
                                <input type="number" step="0.01" name="option_<?= $i ?>_price_monthly" value="<?= htmlspecialchars($form_options[$i]['monthly']) ?>" placeholder="$0.00" onchange="updatePriceHeader(this)">
                                <small>Monthly x 12 payments</small>
                            </div>

                            <div class="pricing-column">
                                <h4 <?= !empty($form_options[$i]['quarterly']) ? 'class="has-price"' : '' ?>>Quarterly <span style="font-size:0.7em;color:#999;">(optional)</span></h4>
                                <input type="number" step="0.01" name="option_<?= $i ?>_price_quarterly" value="<?= htmlspecialchars($form_options[$i]['quarterly']) ?>" placeholder="Leave blank to hide">
                                <small>Leave empty to show only Pay in Full + Monthly</small>
                            </div>
                        </div>
                        <div style="margin-top:10px;">
                            <button type="button" class="add-sub-option" style="background:#17a2b8;font-size:0.85em;padding:5px 12px;" onclick="autoCalcPricing(<?= $i ?>)">Auto-calculate from Pay in Full</button>
                            <span style="color:#999;font-size:0.85em;margin-left:8px;">Sets Monthly = Annual/12, Quarterly = Annual/4</span>
                        </div>
                        <div class="pricing-note">Use simple pricing above OR click "+ Add Sub-Option" below for variations (e.g. different coaches)</div>
                    <?php else: ?>
                        <?php foreach ($form_options[$i]['sub_options'] as $sub_index => $sub_option): ?>
                            <div class="sub-option-section">
                                <div class="sub-option-header">
                                    <input type="text" name="option_<?= $i ?>_sub_<?= $sub_index + 1 ?>_name" value="<?= htmlspecialchars($sub_option['name']) ?>" placeholder="Sub-option name (e.g. Elizabeth Tuazon, Dr. Judith Wright)" class="sub-option-name" required>
                                    <button type="button" class="remove-sub-option" onclick="removeSubOption(this)">Remove</button>
                                </div>
                                <div class="pricing-grid">
                                    <div class="pricing-column">
                                        <h4>Pay in Full</h4>
                                        <input type="number" step="0.01" name="option_<?= $i ?>_sub_<?= $sub_index + 1 ?>_price_yearly" value="<?= htmlspecialchars($sub_option['yearly'] ?? '') ?>" placeholder="$0.00">
                                    </div>
                                    <div class="pricing-column">
                                        <h4>Monthly</h4>
                                        <input type="number" step="0.01" name="option_<?= $i ?>_sub_<?= $sub_index + 1 ?>_price_monthly" value="<?= htmlspecialchars($sub_option['monthly'] ?? '') ?>" placeholder="$0.00">
                                        <small>Monthly x 12 payments</small>
                                    </div>
                                    <div class="pricing-column">
                                        <h4>Quarterly <span style="font-size:0.7em;color:#999;">(optional)</span></h4>
                                        <input type="number" step="0.01" name="option_<?= $i ?>_sub_<?= $sub_index + 1 ?>_price_quarterly" value="<?= htmlspecialchars($sub_option['quarterly'] ?? '') ?>" placeholder="Leave blank">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <button type="button" class="add-sub-option" onclick="addSubOption(<?= $i ?>)">+ Add Sub-Option</button>
            </div>
        <?php endfor; ?>
        
        <button type="submit" class="btn">Save Preset</button>
        <a href="presets.php" class="btn btn-secondary">Cancel</a>
    </form>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
    <script>
    // Initialize rich text editors
    const toolbarOptions = [
        ['bold', 'italic', 'underline'],
        [{ 'align': [] }],  // Text alignment: left, center, right, justify
        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
        ['link'],
        ['clean']
    ];
    
    const contractEditor = new Quill('#contract_editor', {
        theme: 'snow',
        placeholder: 'Describe what is included with all options...',
        modules: { toolbar: toolbarOptions }
    });
    
    const pdpFromEditor = new Quill('#pdp_from_editor', {
        theme: 'snow',
        placeholder: 'Describe present state challenges and opportunities...',
        modules: { toolbar: toolbarOptions }
    });
    
    const pdpTowardEditor = new Quill('#pdp_toward_editor', {
        theme: 'snow',
        placeholder: 'Describe ideal state outcomes...',
        modules: { toolbar: toolbarOptions }
    });
    
    const optionEditors = {};
    for (let i = 1; i <= 3; i++) {
        optionEditors[i] = new Quill(`#editor_${i}`, {
            theme: 'snow',
            placeholder: 'Leave blank to skip this option',
            modules: { toolbar: toolbarOptions }
        });
    }
    
    // Set initial content
    const contractTextarea = document.querySelector('textarea[name="contract_description"]');
    if (contractTextarea.value.trim()) {
        contractEditor.root.innerHTML = contractTextarea.value;
    }
    
    const pdpFromTextarea = document.querySelector('textarea[name="pdp_from"]');
    if (pdpFromTextarea.value.trim()) {
        pdpFromEditor.root.innerHTML = pdpFromTextarea.value;
    }
    
    const pdpTowardTextarea = document.querySelector('textarea[name="pdp_toward"]');
    if (pdpTowardTextarea.value.trim()) {
        pdpTowardEditor.root.innerHTML = pdpTowardTextarea.value;
    }
    
    for (let i = 1; i <= 3; i++) {
        const textarea = document.querySelector(`textarea[name="option_${i}_desc"]`);
        if (textarea.value.trim()) {
            optionEditors[i].root.innerHTML = textarea.value;
        }
    }
    
    // Update textareas when content changes
    contractEditor.on('text-change', () => {
        contractTextarea.value = contractEditor.root.innerHTML;
    });
    
    pdpFromEditor.on('text-change', () => {
        pdpFromTextarea.value = pdpFromEditor.root.innerHTML;
    });
    
    pdpTowardEditor.on('text-change', () => {
        pdpTowardTextarea.value = pdpTowardEditor.root.innerHTML;
    });
    
    for (let i = 1; i <= 3; i++) {
        optionEditors[i].on('text-change', () => {
            const textarea = document.querySelector(`textarea[name="option_${i}_desc"]`);
            textarea.value = optionEditors[i].root.innerHTML;
        });
    }
    
    function updatePriceHeader(input) {
        const header = input.parentElement.querySelector('h4');
        if (input.value && parseFloat(input.value) > 0) {
            header.classList.add('has-price');
        } else {
            header.classList.remove('has-price');
        }
    }
    
    // Auto-calculate pricing from Pay in Full (no markup - simple division)
    function autoCalcPricing(optionNumber) {
        const yearlyInput = document.querySelector(`input[name="option_${optionNumber}_price_yearly"]`);
        const quarterlyInput = document.querySelector(`input[name="option_${optionNumber}_price_quarterly"]`);
        const monthlyInput = document.querySelector(`input[name="option_${optionNumber}_price_monthly"]`);

        if (!monthlyInput || !quarterlyInput || !yearlyInput) return;

        const yearly = parseFloat(yearlyInput.value) || 0;

        if (yearly > 0) {
            const monthly = Math.round(yearly / 12 * 100) / 100;
            const quarterly = Math.round(yearly / 4 * 100) / 100;

            monthlyInput.value = monthly.toFixed(2);
            quarterlyInput.value = quarterly.toFixed(2);

            updatePriceHeader(quarterlyInput);
            updatePriceHeader(monthlyInput);
        }
    }

    function addSubOption(optionNumber) {
        const container = document.getElementById(`sub-options-container-${optionNumber}`);
        const existingSubOptions = container.querySelectorAll('.sub-option-section');
        const subIndex = existingSubOptions.length + 1;

        const subOptionHtml = `
            <div class="sub-option-section">
                <div class="sub-option-header">
                    <input type="text" name="option_${optionNumber}_sub_${subIndex}_name" placeholder="Sub-option name (e.g. Elizabeth Tuazon, Dr. Judith Wright)" class="sub-option-name" required>
                    <button type="button" class="remove-sub-option" onclick="removeSubOption(this)">Remove</button>
                </div>
                <div class="pricing-grid">
                    <div class="pricing-column">
                        <h4>Pay in Full</h4>
                        <input type="number" step="0.01" name="option_${optionNumber}_sub_${subIndex}_price_yearly" placeholder="$0.00">
                    </div>
                    <div class="pricing-column">
                        <h4>Monthly</h4>
                        <input type="number" step="0.01" name="option_${optionNumber}_sub_${subIndex}_price_monthly" placeholder="$0.00">
                        <small>Monthly x 12 payments</small>
                    </div>
                    <div class="pricing-column">
                        <h4>Quarterly <span style="font-size:0.7em;color:#999;">(optional)</span></h4>
                        <input type="number" step="0.01" name="option_${optionNumber}_sub_${subIndex}_price_quarterly" placeholder="Leave blank">
                    </div>
                </div>
            </div>
        `;

        // If this is the first sub-option, replace the simple pricing grid
        if (subIndex === 1) {
            const simplePricingGrid = container.querySelector('.pricing-grid');
            if (simplePricingGrid && !simplePricingGrid.closest('.sub-option-section')) {
                simplePricingGrid.remove();
                const pricingNote = container.querySelector('.pricing-note');
                if (pricingNote) pricingNote.remove();
            }
        }
        
        container.insertAdjacentHTML('beforeend', subOptionHtml);
    }
    
    function removeSubOption(button) {
        const subOption = button.closest('.sub-option-section');
        const container = subOption.closest('[id^="sub-options-container-"]');
        subOption.remove();

        // If no sub-options left, restore simple pricing grid
        const remainingSubOptions = container.querySelectorAll('.sub-option-section');
        if (remainingSubOptions.length === 0) {
            const optionNumber = container.id.split('-').pop();
            const simplePricingHtml = `
                <div class="pricing-grid">
                    <div class="pricing-column">
                        <h4>Pay in Full</h4>
                        <input type="number" step="0.01" name="option_${optionNumber}_price_yearly" placeholder="$0.00" onchange="updatePriceHeader(this)">
                        <small>Full program price</small>
                    </div>
                    <div class="pricing-column">
                        <h4>Monthly</h4>
                        <input type="number" step="0.01" name="option_${optionNumber}_price_monthly" placeholder="$0.00" onchange="updatePriceHeader(this)">
                        <small>Monthly x 12 payments</small>
                    </div>
                    <div class="pricing-column">
                        <h4>Quarterly <span style="font-size:0.7em;color:#999;">(optional)</span></h4>
                        <input type="number" step="0.01" name="option_${optionNumber}_price_quarterly" placeholder="Leave blank to hide">
                        <small>Leave empty to show only Pay in Full + Monthly</small>
                    </div>
                </div>
                <div style="margin-top:10px;">
                    <button type="button" class="add-sub-option" style="background:#17a2b8;font-size:0.85em;padding:5px 12px;" onclick="autoCalcPricing(${optionNumber})">Auto-calculate from Pay in Full</button>
                    <span style="color:#999;font-size:0.85em;margin-left:8px;">Sets Monthly = Annual/12, Quarterly = Annual/4</span>
                </div>
                <div class="pricing-note">Use simple pricing above OR click "+ Add Sub-Option" below for variations (e.g. different coaches)</div>
            `;
            container.innerHTML = simplePricingHtml;
        }
    }

    // Update price headers on page load for existing values
    window.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.pricing-grid input[type="number"]').forEach(input => {
            if (input.value) {
                updatePriceHeader(input);
            }
        });
    });
    
    // Sync all editors before form submission
    document.querySelector('form').addEventListener('submit', function() {
        contractTextarea.value = contractEditor.root.innerHTML;
        pdpFromTextarea.value = pdpFromEditor.root.innerHTML;
        pdpTowardTextarea.value = pdpTowardEditor.root.innerHTML;
        
        for (let i = 1; i <= 3; i++) {
            const textarea = document.querySelector(`textarea[name="option_${i}_desc"]`);
            textarea.value = optionEditors[i].root.innerHTML;
        }
    });
    </script>
    </div>

<?php require_once 'includes/footer.php'; ?>
