<?php
require_once '../config.php';
requireLogin();

$contract_id = $_GET['id'] ?? null;
$preset_id = $_GET['preset'] ?? null;
$contract = null;
$options = [];
$preset_data = null;

// Load preset data if specified
if ($preset_id && !$contract_id) {
    $stmt = $pdo->prepare("SELECT * FROM pdp_presets WHERE id = ?");
    $stmt->execute([$preset_id]);
    $preset_data = $stmt->fetch();
}

// Load existing contract data if editing
if ($contract_id) {
    $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch();
    
    if (!$contract) {
        header('Location: index.php');
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM pricing_options WHERE contract_id = ? AND deleted_at IS NULL ORDER BY option_number, sub_option_name, type");
    $stmt->execute([$contract_id]);
    $existing_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by option number and sub-option name
    foreach ($existing_options as $option) {
        $options[$option['option_number']][$option['sub_option_name']][$option['type']] = $option;
    }
}

$errors = [];
$success = '';

if ($_POST) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contract_description = trim($_POST['contract_description'] ?? '');
    $pdp_from = trim($_POST['pdp_from'] ?? '');
    $pdp_toward = trim($_POST['pdp_toward'] ?? '');
    
    // Get minimum months for each option
    $option_1_minimum = (int)($_POST['option_1_minimum_months'] ?? 1);
    $option_2_minimum = (int)($_POST['option_2_minimum_months'] ?? 1);
    $option_3_minimum = (int)($_POST['option_3_minimum_months'] ?? 1);
    
    // Validation
    if (empty($first_name)) $errors[] = 'First name is required';
    if (empty($last_name)) $errors[] = 'Last name is required';
    if (empty($email)) $errors[] = 'Email is required';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';
    
    // Check email uniqueness
    if (!empty($email)) {
        $stmt = $pdo->prepare("SELECT id FROM contracts WHERE email = ? AND deleted_at IS NULL" . ($contract_id ? " AND id != ?" : ""));
        $params = [$email];
        if ($contract_id) $params[] = $contract_id;
        $stmt->execute($params);
        if ($stmt->fetch()) $errors[] = 'Email already exists';
    }
    
    // Validate options
    for ($i = 1; $i <= 3; $i++) {
        $main_desc = trim($_POST["option_{$i}_desc"] ?? '');
        
        // If option has a main description, require at least one price
        if (!empty($main_desc)) {
            $has_pricing = false;
            
            // Check for simple pricing first
            $types = ['Monthly', 'Quarterly', 'Yearly'];
            foreach ($types as $type) {
                $price = trim($_POST["option_{$i}_price_" . strtolower($type)] ?? '');
                if (!empty($price)) {
                    $has_pricing = true;
                    if (!is_numeric($price) || $price < 0) {
                        $errors[] = "Option {$i} {$type} price must be a valid positive number";
                    }
                }
            }
            
            // Check for sub-option pricing
            for ($s = 1; $s <= 10; $s++) {
                $sub_name = trim($_POST["option_{$i}_sub_{$s}_name"] ?? '');
                if (!empty($sub_name)) {
                    foreach ($types as $type) {
                        $price = trim($_POST["option_{$i}_sub_{$s}_price_" . strtolower($type)] ?? '');
                        if (!empty($price)) {
                            $has_pricing = true;
                            if (!is_numeric($price) || $price < 0) {
                                $errors[] = "Option {$i} sub-option '{$sub_name}' {$type} price must be a valid positive number";
                            }
                        }
                    }
                }
            }
            
            if (!$has_pricing) {
                $errors[] = "Option {$i} has a description but no prices. At least one price is required.";
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            if ($contract_id) {
                // Update contract
                $stmt = $pdo->prepare("UPDATE contracts SET first_name = ?, last_name = ?, email = ?, contract_description = ?, pdp_from = ?, pdp_toward = ?, option_1_minimum_months = ?, option_2_minimum_months = ?, option_3_minimum_months = ? WHERE id = ?");
                $stmt->execute([$first_name, $last_name, $email, $contract_description, $pdp_from, $pdp_toward, $option_1_minimum, $option_2_minimum, $option_3_minimum, $contract_id]);
                
                // Soft delete existing options
                $stmt = $pdo->prepare("UPDATE pricing_options SET deleted_at = NOW() WHERE contract_id = ?");
                $stmt->execute([$contract_id]);
            } else {
                // Insert new contract with unique ID
                $unique_id = uniqid('', true);
                $stmt = $pdo->prepare("INSERT INTO contracts (unique_id, first_name, last_name, email, contract_description, pdp_from, pdp_toward, option_1_minimum_months, option_2_minimum_months, option_3_minimum_months) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$unique_id, $first_name, $last_name, $email, $contract_description, $pdp_from, $pdp_toward, $option_1_minimum, $option_2_minimum, $option_3_minimum]);
                $contract_id = $pdo->lastInsertId();
            }
            
            // Insert new options with sub-options
            for ($i = 1; $i <= 3; $i++) {
                $main_desc = trim($_POST["option_{$i}_desc"] ?? '');
                
                if (!empty($main_desc)) {
                    // Check if sub-options are defined
                    $has_sub_options = false;
                    for ($s = 1; $s <= 10; $s++) {
                        if (!empty($_POST["option_{$i}_sub_{$s}_name"])) {
                            $has_sub_options = true;
                            break;
                        }
                    }
                    
                    if ($has_sub_options) {
                        // Create entries for each sub-option
                        for ($s = 1; $s <= 10; $s++) {
                            $sub_name = trim($_POST["option_{$i}_sub_{$s}_name"] ?? '');
                            if (!empty($sub_name)) {
                                $types = ['Monthly', 'Quarterly', 'Yearly'];
                                foreach ($types as $type) {
                                    $price = trim($_POST["option_{$i}_sub_{$s}_price_" . strtolower($type)] ?? '');
                                    if (!empty($price) && is_numeric($price)) {
                                        $stmt = $pdo->prepare("INSERT INTO pricing_options (contract_id, option_number, sub_option_name, description, price, type) VALUES (?, ?, ?, ?, ?, ?)");
                                        $stmt->execute([$contract_id, $i, $sub_name, $main_desc, $price, $type]);
                                    }
                                }
                            }
                        }
                    } else {
                        // Use simple pricing (no sub-options)
                        $types = ['Monthly', 'Quarterly', 'Yearly'];
                        foreach ($types as $type) {
                            $price = trim($_POST["option_{$i}_price_" . strtolower($type)] ?? '');
                            if (!empty($price) && is_numeric($price)) {
                                $stmt = $pdo->prepare("INSERT INTO pricing_options (contract_id, option_number, sub_option_name, description, price, type) VALUES (?, ?, ?, ?, ?, ?)");
                                $stmt->execute([$contract_id, $i, 'Default', $main_desc, $price, $type]);
                            }
                        }
                    }
                }
            }

            // Process support packages
            $support_packages = [];
            for ($p = 1; $p <= 20; $p++) {
                $pkg_name = trim($_POST["support_package_{$p}_name"] ?? '');
                if (!empty($pkg_name)) {
                    $package = [
                        'name' => $pkg_name,
                        'description' => trim($_POST["support_package_{$p}_description"] ?? ''),
                        'price_monthly' => floatval($_POST["support_package_{$p}_price"] ?? 0)
                    ];

                    // Check if this is a "built" package with extended data
                    if (!empty($_POST["support_package_{$p}_coach"])) {
                        $package['coach'] = trim($_POST["support_package_{$p}_coach"]);
                        $package['duration_minutes'] = (int)($_POST["support_package_{$p}_duration"] ?? 0);
                        $package['sessions'] = (int)($_POST["support_package_{$p}_sessions"] ?? 0);
                        $package['regular_price'] = floatval($_POST["support_package_{$p}_regular_price"] ?? 0);
                        $package['package_price'] = floatval($_POST["support_package_{$p}_package_price"] ?? 0);
                        $package['savings'] = floatval($_POST["support_package_{$p}_savings"] ?? 0);
                    }

                    $support_packages[] = $package;
                }
            }

            // Update contract with support packages JSON
            $support_packages_json = !empty($support_packages) ? json_encode($support_packages) : null;
            $stmt = $pdo->prepare("UPDATE contracts SET support_packages = ? WHERE id = ?");
            $stmt->execute([$support_packages_json, $contract_id]);

            $pdo->commit();
            $success = 'Personal Development Plan saved successfully';
            
            // Redirect after short delay
            header("refresh:2;url=index.php");
            
        } catch (Exception $e) {
            $pdo->rollback();
            $errors[] = 'Error saving Personal Development Plan: ' . $e->getMessage();
        }
    }
}

// Prepare form data
$form_data = [
    'first_name' => $_POST['first_name'] ?? ($contract['first_name'] ?? ($preset_data['first_name'] ?? '')),
    'last_name' => $_POST['last_name'] ?? ($contract['last_name'] ?? ($preset_data['last_name'] ?? '')),
    'email' => $_POST['email'] ?? ($contract['email'] ?? ($preset_data['email'] ?? '')),
    'contract_description' => $_POST['contract_description'] ?? ($contract['contract_description'] ?? ($preset_data['contract_description'] ?? '')),
    'pdp_from' => $_POST['pdp_from'] ?? ($contract['pdp_from'] ?? ($preset_data['pdp_from'] ?? '')),
    'pdp_toward' => $_POST['pdp_toward'] ?? ($contract['pdp_toward'] ?? ($preset_data['pdp_toward'] ?? '')),
    'option_1_minimum_months' => $_POST['option_1_minimum_months'] ?? ($contract['option_1_minimum_months'] ?? ($preset_data['option_1_minimum_months'] ?? 1)),
    'option_2_minimum_months' => $_POST['option_2_minimum_months'] ?? ($contract['option_2_minimum_months'] ?? ($preset_data['option_2_minimum_months'] ?? 1)),
    'option_3_minimum_months' => $_POST['option_3_minimum_months'] ?? ($contract['option_3_minimum_months'] ?? ($preset_data['option_3_minimum_months'] ?? 1))
];

// Prepare options data for form
$form_options = [];
for ($i = 1; $i <= 3; $i++) {
    $form_options[$i] = [
        'desc' => $_POST["option_{$i}_desc"] ?? '',
        'monthly' => $_POST["option_{$i}_price_monthly"] ?? '',
        'quarterly' => $_POST["option_{$i}_price_quarterly"] ?? '',
        'yearly' => $_POST["option_{$i}_price_yearly"] ?? ''
    ];
    
    // Load from existing contract if editing
    if (empty($form_options[$i]['desc']) && !empty($options[$i])) {
        $first_sub_option = reset($options[$i]);
        $first_option = reset($first_sub_option);
        $form_options[$i]['desc'] = $first_option['description'] ?? '';
        
        // If only one sub-option named 'Default', load simple pricing
        if (count($options[$i]) === 1 && isset($options[$i]['Default'])) {
            $form_options[$i]['monthly'] = $options[$i]['Default']['Monthly']['price'] ?? '';
            $form_options[$i]['quarterly'] = $options[$i]['Default']['Quarterly']['price'] ?? '';
            $form_options[$i]['yearly'] = $options[$i]['Default']['Yearly']['price'] ?? '';
        }
    }
    
    // Load from preset if specified
    if ($preset_data && empty($_POST)) {
        $form_options[$i]['desc'] = $preset_data["option_{$i}_desc"] ?? '';
        $form_options[$i]['monthly'] = $preset_data["option_{$i}_price_monthly"] ?? '';
        $form_options[$i]['quarterly'] = $preset_data["option_{$i}_price_quarterly"] ?? '';
        $form_options[$i]['yearly'] = $preset_data["option_{$i}_price_yearly"] ?? '';
        
        // Load sub-options from preset if they exist
        if (!empty($preset_data["option_{$i}_sub_options"])) {
            $preset_sub_options = json_decode($preset_data["option_{$i}_sub_options"], true);
            if (is_array($preset_sub_options)) {
                // Convert preset sub-options to the same format used by existing contracts
                // We'll load them into the $options array so the existing rendering logic works
                $options[$i] = [];
                foreach ($preset_sub_options as $sub_option) {
                    $sub_name = $sub_option['name'];
                    $options[$i][$sub_name] = [];
                    
                    if (!empty($sub_option['monthly'])) {
                        $options[$i][$sub_name]['Monthly'] = ['price' => $sub_option['monthly']];
                    }
                    if (!empty($sub_option['quarterly'])) {
                        $options[$i][$sub_name]['Quarterly'] = ['price' => $sub_option['quarterly']];
                    }
                    if (!empty($sub_option['yearly'])) {
                        $options[$i][$sub_name]['Yearly'] = ['price' => $sub_option['yearly']];
                    }
                }
                
                // Clear simple pricing since we have sub-options
                $form_options[$i]['monthly'] = '';
                $form_options[$i]['quarterly'] = '';
                $form_options[$i]['yearly'] = '';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= $contract_id ? 'Edit' : 'Add New' ?> Personal Development Plan</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css" rel="stylesheet">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; max-width: 900px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="email"], input[type="number"] { 
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box;
        }
        .btn { padding: 10px 20px; background: #007cba; color: white; border: none; cursor: pointer; text-decoration: none; border-radius: 4px; margin-right: 10px; display: inline-block; }
        .btn-secondary { background: #6c757d; }
        .btn-preset { background: #28a745; margin-bottom: 20px; }
        .error { color: red; margin-bottom: 15px; }
        .success { color: green; margin-bottom: 15px; font-weight: bold; }
        .option-section { border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .sub-option-section { border: 1px solid #eee; margin: 15px 0; padding: 15px; background: #fafafa; border-radius: 4px; }
        .sub-option-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .sub-option-name { width: 200px; padding: 5px; border: 1px solid #ddd; border-radius: 4px; }
        .remove-sub-option { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
        .add-sub-option { background: #28a745; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; margin-top: 10px; }
        .pricing-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-top: 15px; }
        .pricing-column { text-align: center; }
        .pricing-column h4 { margin: 0 0 10px 0; padding: 10px; background: #f5f5f5; border-radius: 4px; transition: all 0.3s ease; }
        .pricing-column h4.has-price { background: #28a745; color: white; }
        .pricing-column input { text-align: center; }
        .pricing-column input:read-only { background: #f8f9fa; color: #666; }
        .base-price-badge { background: #007cba; color: white; font-size: 0.7em; padding: 2px 6px; border-radius: 8px; margin-left: 5px; vertical-align: middle; }
        .pricing-discount { font-size: 0.85em; color: #28a745; margin-top: 5px; font-weight: bold; }
        .pricing-details { font-size: 0.8em; color: #666; margin-top: 3px; }
        .option-minimum { margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 4px; }
        .option-minimum label { font-weight: normal; margin-bottom: 0; }
        .option-minimum input { width: 80px; text-align: center; margin-left: 10px; }

        /* Support Packages Styles */
        .support-packages-section { background: #f0f7ff; border-color: #007cba; }
        .support-packages-section h3 { color: #007cba; }
        .section-description { color: #666; font-size: 0.9em; margin-bottom: 15px; }
        .support-preset-selector { margin-bottom: 20px; padding: 15px; background: white; border-radius: 4px; display: flex; align-items: center; gap: 10px; }
        .support-preset-selector select { padding: 8px; border: 1px solid #ddd; border-radius: 4px; min-width: 200px; }
        .preset-note { color: #666; font-size: 0.85em; font-style: italic; }
        .support-package-item { background: white; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; padding: 15px; }
        .support-package-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .package-name-input { width: 300px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-weight: bold; }
        .remove-support-package { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
        .support-package-body { display: grid; grid-template-columns: 2fr 1fr; gap: 15px; }
        .package-description input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .package-pricing input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; text-align: center; }
        .no-packages-message { color: #666; font-style: italic; padding: 20px; text-align: center; background: white; border-radius: 4px; }

        /* Package Builder Styles */
        .package-builder { background: white; border: 2px solid #17a2b8; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .package-builder h4 { margin: 0 0 15px 0; color: #17a2b8; display: flex; align-items: center; gap: 10px; }
        .builder-row { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 15px; align-items: end; margin-bottom: 15px; }
        .builder-row .form-group { margin-bottom: 0; }
        .builder-row select, .builder-row input { padding: 10px; border: 1px solid #ddd; border-radius: 4px; width: 100%; }
        .builder-preview { background: #f8f9fa; border-radius: 4px; padding: 15px; margin-top: 15px; display: none; }
        .builder-preview.visible { display: block; }
        .preview-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; text-align: center; }
        .preview-item { padding: 10px; }
        .preview-item .label { font-size: 0.85em; color: #666; margin-bottom: 5px; }
        .preview-item .value { font-size: 1.2em; font-weight: bold; }
        .preview-item .value.regular { color: #6c757d; text-decoration: line-through; }
        .preview-item .value.package { color: #28a745; }
        .preview-item .value.savings { color: #17a2b8; }
        .add-package-btn { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .add-package-btn:hover { background: #218838; }
        .add-package-btn:disabled { background: #6c757d; cursor: not-allowed; }
        .manage-rates-link { font-size: 0.85em; color: #17a2b8; text-decoration: none; }
        .manage-rates-link:hover { text-decoration: underline; }

        /* Package display with savings */
        .package-with-savings { background: white; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 15px; overflow: hidden; }
        .package-savings-header { display: flex; justify-content: space-between; align-items: center; padding: 15px; background: #f8f9fa; border-bottom: 1px solid #ddd; }
        .package-savings-header .name { font-weight: bold; font-size: 1.1em; }
        .package-savings-header .remove-btn { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
        .package-savings-body { padding: 15px; }
        .package-savings-body .description { color: #666; margin-bottom: 15px; }
        .package-pricing-display { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; text-align: center; }
        .pricing-box { padding: 10px; border-radius: 4px; }
        .pricing-box.regular { background: #f8f9fa; }
        .pricing-box.package { background: #d4edda; }
        .pricing-box.savings { background: #d1ecf1; }
        .pricing-box .label { font-size: 0.8em; color: #666; margin-bottom: 3px; }
        .pricing-box .amount { font-size: 1.1em; font-weight: bold; }
        .pricing-box.regular .amount { text-decoration: line-through; color: #999; }
        .pricing-box.package .amount { color: #28a745; }
        .pricing-box.savings .amount { color: #17a2b8; }
        .quill-editor { height: 120px; }
        .ql-toolbar { border-top: 1px solid #ddd; border-left: 1px solid #ddd; border-right: 1px solid #ddd; }
        .ql-container { border-bottom: 1px solid #ddd; border-left: 1px solid #ddd; border-right: 1px solid #ddd; }
        .description-group { margin-bottom: 15px; }
        .hidden { display: none; }
        .preset-section { background: #f8f9fa; padding: 20px; margin-bottom: 30px; border-radius: 4px; border-left: 4px solid #007cba; }
        .preset-buttons { display: flex; gap: 10px; margin-top: 15px; }
    </style>
</head>
<body>
    <h1><?= $contract_id ? 'Edit' : 'Add New' ?> Personal Development Plan</h1>
    
    <?php if (!$contract_id): ?>
        <div class="preset-section">
            <h3>Quick Start with Presets</h3>
            <p>Use a preset to prefill all form fields with common configurations:</p>
            <div class="preset-buttons">
                <?php
                $presets_stmt = $pdo->query("SELECT id, name FROM pdp_presets ORDER BY id");
                $presets_list = $presets_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($presets_list as $p):
                ?>
                    <a href="?preset=<?= $p['id'] ?>" class="btn btn-preset"><?= htmlspecialchars($p['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    
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
            <label>First Name:</label>
            <input type="text" name="first_name" value="<?= htmlspecialchars($form_data['first_name']) ?>" required>
        </div>
        
        <div class="form-group">
            <label>Last Name:</label>
            <input type="text" name="last_name" value="<?= htmlspecialchars($form_data['last_name']) ?>" required>
        </div>
        
        <div class="form-group">
            <label>Email:</label>
            <input type="email" name="email" value="<?= htmlspecialchars($form_data['email']) ?>" required>
        </div>
        
        <div class="description-group">
            <label>FROM — Present State Challenges and Opportunities:</label>
            <div id="pdp_from_editor" class="quill-editor"></div>
            <textarea name="pdp_from" class="hidden"><?= htmlspecialchars($form_data['pdp_from']) ?></textarea>
        </div>
        
        <div class="description-group">
            <label>TOWARD — Ideal State Outcomes:</label>
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
                
                <div class="option-minimum">
                    <label>Minimum commitment for this option: 
                        <input type="number" min="1" name="option_<?= $i ?>_minimum_months" value="<?= htmlspecialchars($form_data["option_{$i}_minimum_months"]) ?>"> months
                    </label>
                </div>
                
                <div id="sub-options-container-<?= $i ?>">
                    <?php
                    $existing_sub_options = $options[$i] ?? [];
                    if (empty($existing_sub_options) || (count($existing_sub_options) === 1 && isset($existing_sub_options['Default']))) {
                        // Show simple pricing grid for new options or default-only options
                        // ANNUAL FIRST - annual is the base price
                    ?>
                        <div class="pricing-grid">
                            <div class="pricing-column">
                                <h4 <?= !empty($form_options[$i]['yearly']) ? 'class="has-price"' : '' ?>>Pay in Full</h4>
                                <input type="number" step="0.01" name="option_<?= $i ?>_price_yearly" value="<?= htmlspecialchars($form_options[$i]['yearly']) ?>" placeholder="$0.00" onchange="updatePriceHeader(this)" class="yearly-price-<?= $i ?>">
                                <div class="pricing-details yearly-details-<?= $i ?>">Full program price</div>
                            </div>
                            <div class="pricing-column">
                                <h4 <?= !empty($form_options[$i]['monthly']) ? 'class="has-price"' : '' ?>>Monthly</h4>
                                <input type="number" step="0.01" name="option_<?= $i ?>_price_monthly" value="<?= htmlspecialchars($form_options[$i]['monthly']) ?>" placeholder="$0.00" onchange="updatePriceHeader(this)" class="monthly-price-<?= $i ?>">
                                <div class="pricing-details monthly-details-<?= $i ?>">Monthly x 12 payments</div>
                            </div>
                            <div class="pricing-column">
                                <h4 <?= !empty($form_options[$i]['quarterly']) ? 'class="has-price"' : '' ?>>Quarterly <span style="font-size:0.7em;color:#999;">(optional)</span></h4>
                                <input type="number" step="0.01" name="option_<?= $i ?>_price_quarterly" value="<?= htmlspecialchars($form_options[$i]['quarterly']) ?>" placeholder="Leave blank to hide" class="quarterly-price-<?= $i ?>">
                                <div class="pricing-details quarterly-details-<?= $i ?>">Leave empty to show only Pay in Full + Monthly</div>
                            </div>
                        </div>
                        <div style="margin-top:10px;">
                            <button type="button" class="add-sub-option" style="background:#17a2b8;font-size:0.85em;padding:5px 12px;" onclick="autoCalcPricing(<?= $i ?>)">Auto-calculate from Pay in Full</button>
                            <span style="color:#999;font-size:0.85em;margin-left:8px;">Sets Monthly = Annual/12, Quarterly = Annual/4</span>
                        </div>
                    <?php
                    } else {
                        // Show sub-options for existing data - ANNUAL FIRST
                        $sub_index = 1;
                        foreach ($existing_sub_options as $sub_name => $sub_data) {
                            if ($sub_name === 'Default') continue; // Skip default entries
                    ?>
                        <div class="sub-option-section">
                            <div class="sub-option-header">
                                <input type="text" name="option_<?= $i ?>_sub_<?= $sub_index ?>_name" value="<?= htmlspecialchars($sub_name) ?>" placeholder="Sub-option name (e.g. Elizabeth Tuazon, Dr. Judith Wright)" class="sub-option-name">
                                <button type="button" class="remove-sub-option" onclick="removeSubOption(this)">Remove</button>
                            </div>
                            <div class="pricing-grid">
                                <div class="pricing-column">
                                    <h4>Pay in Full</h4>
                                    <input type="number" step="0.01" name="option_<?= $i ?>_sub_<?= $sub_index ?>_price_yearly" value="<?= htmlspecialchars($sub_data['Yearly']['price'] ?? '') ?>" placeholder="$0.00" class="yearly-sub-<?= $i ?>-<?= $sub_index ?>">
                                </div>
                                <div class="pricing-column">
                                    <h4>Monthly</h4>
                                    <input type="number" step="0.01" name="option_<?= $i ?>_sub_<?= $sub_index ?>_price_monthly" value="<?= htmlspecialchars($sub_data['Monthly']['price'] ?? '') ?>" placeholder="$0.00" class="monthly-sub-<?= $i ?>-<?= $sub_index ?>">
                                    <div class="pricing-details">Monthly x 12 payments</div>
                                </div>
                                <div class="pricing-column">
                                    <h4>Quarterly <span style="font-size:0.7em;color:#999;">(optional)</span></h4>
                                    <input type="number" step="0.01" name="option_<?= $i ?>_sub_<?= $sub_index ?>_price_quarterly" value="<?= htmlspecialchars($sub_data['Quarterly']['price'] ?? '') ?>" placeholder="Leave blank" class="quarterly-sub-<?= $i ?>-<?= $sub_index ?>">
                                </div>
                            </div>
                        </div>
                    <?php
                            $sub_index++;
                        }
                    }
                    ?>
                </div>
                
                <button type="button" class="add-sub-option" onclick="addSubOption(<?= $i ?>)">+ Add Sub-Option</button>
            </div>
        <?php endfor; ?>

        <!-- Support Packages Section -->
        <div class="option-section support-packages-section">
            <h3>Optional Support Packages</h3>
            <p class="section-description">Build coaching packages using the rate table. Pricing is auto-calculated with volume discounts.</p>

            <!-- Package Builder -->
            <div class="package-builder">
                <h4>
                    Package Builder
                    <a href="coaching-rates.php" class="manage-rates-link" target="_blank">(Manage Rates)</a>
                </h4>
                <div class="builder-row">
                    <div class="form-group">
                        <label>Coach:</label>
                        <select id="builder-coach" onchange="updateBuilderDurations()">
                            <option value="">Select Coach...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Session Length:</label>
                        <select id="builder-duration" onchange="updateBuilderPreview()" disabled>
                            <option value="">Select Duration...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Number of Sessions:</label>
                        <select id="builder-sessions" onchange="updateBuilderPreview()" disabled>
                            <option value="">Select Sessions...</option>
                            <option value="1">1 Session</option>
                            <option value="3">3 Sessions</option>
                            <option value="5">5 Sessions</option>
                            <option value="10">10 Sessions</option>
                            <option value="20">20+ Sessions</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" class="add-package-btn" id="add-package-btn" onclick="addBuiltPackage()" disabled>
                            Add Package
                        </button>
                    </div>
                </div>
                <div class="builder-preview" id="builder-preview">
                    <div class="preview-grid">
                        <div class="preview-item">
                            <div class="label">Regular Price</div>
                            <div class="value regular" id="preview-regular">$0.00</div>
                        </div>
                        <div class="preview-item">
                            <div class="label">Package Price</div>
                            <div class="value package" id="preview-package">$0.00</div>
                        </div>
                        <div class="preview-item">
                            <div class="label">Client Saves</div>
                            <div class="value savings" id="preview-savings">$0.00</div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="support-packages-container">
                <?php
                $support_packages = [];
                if (!empty($contract['support_packages'])) {
                    $support_packages = json_decode($contract['support_packages'], true) ?: [];
                }
                if (empty($support_packages)):
                ?>
                    <div class="no-packages-message">No support packages added yet. Use the Package Builder above or add manually below.</div>
                <?php else: ?>
                    <?php foreach ($support_packages as $pkg_index => $pkg): ?>
                        <?php
                        // Check if this is a "built" package with full pricing info
                        $has_savings = isset($pkg['regular_price']) && isset($pkg['package_price']) && isset($pkg['savings']);
                        ?>
                        <?php if ($has_savings): ?>
                            <div class="package-with-savings">
                                <input type="hidden" name="support_package_<?= $pkg_index + 1 ?>_name" value="<?= htmlspecialchars($pkg['name'] ?? '') ?>">
                                <input type="hidden" name="support_package_<?= $pkg_index + 1 ?>_description" value="<?= htmlspecialchars($pkg['description'] ?? '') ?>">
                                <input type="hidden" name="support_package_<?= $pkg_index + 1 ?>_coach" value="<?= htmlspecialchars($pkg['coach'] ?? '') ?>">
                                <input type="hidden" name="support_package_<?= $pkg_index + 1 ?>_duration" value="<?= htmlspecialchars($pkg['duration_minutes'] ?? '') ?>">
                                <input type="hidden" name="support_package_<?= $pkg_index + 1 ?>_sessions" value="<?= htmlspecialchars($pkg['sessions'] ?? '') ?>">
                                <input type="hidden" name="support_package_<?= $pkg_index + 1 ?>_regular_price" value="<?= htmlspecialchars($pkg['regular_price'] ?? '') ?>">
                                <input type="hidden" name="support_package_<?= $pkg_index + 1 ?>_package_price" value="<?= htmlspecialchars($pkg['package_price'] ?? '') ?>">
                                <input type="hidden" name="support_package_<?= $pkg_index + 1 ?>_savings" value="<?= htmlspecialchars($pkg['savings'] ?? '') ?>">
                                <input type="hidden" name="support_package_<?= $pkg_index + 1 ?>_price" value="<?= htmlspecialchars($pkg['package_price'] ?? $pkg['price_monthly'] ?? '') ?>">
                                <div class="package-savings-header">
                                    <span class="name"><?= htmlspecialchars($pkg['name']) ?></span>
                                    <button type="button" class="remove-btn" onclick="removePackageWithSavings(this)">Remove</button>
                                </div>
                                <div class="package-savings-body">
                                    <div class="description"><?= htmlspecialchars($pkg['description']) ?></div>
                                    <div class="package-pricing-display">
                                        <div class="pricing-box regular">
                                            <div class="label">Regular Price</div>
                                            <div class="amount">$<?= number_format($pkg['regular_price'], 2) ?></div>
                                        </div>
                                        <div class="pricing-box package">
                                            <div class="label">Package Price</div>
                                            <div class="amount">$<?= number_format($pkg['package_price'], 2) ?></div>
                                        </div>
                                        <div class="pricing-box savings">
                                            <div class="label">Savings</div>
                                            <div class="amount">$<?= number_format($pkg['savings'], 2) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="support-package-item">
                                <div class="support-package-header">
                                    <input type="text" name="support_package_<?= $pkg_index + 1 ?>_name" value="<?= htmlspecialchars($pkg['name'] ?? '') ?>" placeholder="Package Name (e.g., Email Support)" class="package-name-input">
                                    <button type="button" class="remove-support-package" onclick="removeSupportPackage(this)">Remove</button>
                                </div>
                                <div class="support-package-body">
                                    <div class="package-description">
                                        <label>Description:</label>
                                        <input type="text" name="support_package_<?= $pkg_index + 1 ?>_description" value="<?= htmlspecialchars($pkg['description'] ?? '') ?>" placeholder="Brief description of what's included">
                                    </div>
                                    <div class="package-pricing">
                                        <div class="pricing-column">
                                            <label>Price:</label>
                                            <input type="number" step="0.01" name="support_package_<?= $pkg_index + 1 ?>_price" value="<?= htmlspecialchars($pkg['price_monthly'] ?? '') ?>" placeholder="$0.00">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <button type="button" class="add-sub-option" onclick="addSupportPackage()">+ Add Manual Package</button>
        </div>

        <button type="submit" class="btn">Save</button>
        <a href="index.php" class="btn btn-secondary">Cancel</a>
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
    
    // Contract Description editor
    const contractEditor = new Quill('#contract_editor', {
        theme: 'snow',
        placeholder: 'Describe what is included with all options...',
        modules: { toolbar: toolbarOptions }
    });
    
    // PDP From editor
    const pdpFromEditor = new Quill('#pdp_from_editor', {
        theme: 'snow',
        placeholder: 'Describe present state challenges and opportunities...',
        modules: { toolbar: toolbarOptions }
    });
    
    // PDP Toward editor
    const pdpTowardEditor = new Quill('#pdp_toward_editor', {
        theme: 'snow',
        placeholder: 'Describe ideal state outcomes...',
        modules: { toolbar: toolbarOptions }
    });
    
    // Option Description editors
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
    
    // Update price headers
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
        const yearlyInput = document.querySelector(`.yearly-price-${optionNumber}`);
        const quarterlyInput = document.querySelector(`.quarterly-price-${optionNumber}`);
        const monthlyInput = document.querySelector(`.monthly-price-${optionNumber}`);

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
    
    // Add sub-option functionality
    function addSubOption(optionNumber) {
        const container = document.getElementById(`sub-options-container-${optionNumber}`);
        const existingSubOptions = container.querySelectorAll('.sub-option-section');
        const subIndex = existingSubOptions.length + 1;

        const subOptionHtml = `
            <div class="sub-option-section">
                <div class="sub-option-header">
                    <input type="text" name="option_${optionNumber}_sub_${subIndex}_name" placeholder="Sub-option name (e.g. Elizabeth Tuazon, Dr. Judith Wright)" class="sub-option-name">
                    <button type="button" class="remove-sub-option" onclick="removeSubOption(this)">Remove</button>
                </div>
                <div class="pricing-grid">
                    <div class="pricing-column">
                        <h4>Pay in Full</h4>
                        <input type="number" step="0.01" name="option_${optionNumber}_sub_${subIndex}_price_yearly" placeholder="$0.00" class="yearly-sub-${optionNumber}-${subIndex}">
                    </div>
                    <div class="pricing-column">
                        <h4>Monthly</h4>
                        <input type="number" step="0.01" name="option_${optionNumber}_sub_${subIndex}_price_monthly" placeholder="$0.00" class="monthly-sub-${optionNumber}-${subIndex}">
                        <div class="pricing-details">Monthly x 12 payments</div>
                    </div>
                    <div class="pricing-column">
                        <h4>Quarterly <span style="font-size:0.7em;color:#999;">(optional)</span></h4>
                        <input type="number" step="0.01" name="option_${optionNumber}_sub_${subIndex}_price_quarterly" placeholder="Leave blank" class="quarterly-sub-${optionNumber}-${subIndex}">
                    </div>
                </div>
            </div>
        `;

        // If this is the first sub-option, replace the simple pricing grid
        if (subIndex === 1) {
            const simplePricingGrid = container.querySelector('.pricing-grid');
            if (simplePricingGrid && !simplePricingGrid.closest('.sub-option-section')) {
                simplePricingGrid.remove();
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
                        <input type="number" step="0.01" name="option_${optionNumber}_price_yearly" placeholder="$0.00" onchange="updatePriceHeader(this)" class="yearly-price-${optionNumber}">
                        <div class="pricing-details yearly-details-${optionNumber}">Full program price</div>
                    </div>
                    <div class="pricing-column">
                        <h4>Monthly</h4>
                        <input type="number" step="0.01" name="option_${optionNumber}_price_monthly" placeholder="$0.00" onchange="updatePriceHeader(this)" class="monthly-price-${optionNumber}">
                        <div class="pricing-details monthly-details-${optionNumber}">Monthly x 12 payments</div>
                    </div>
                    <div class="pricing-column">
                        <h4>Quarterly <span style="font-size:0.7em;color:#999;">(optional)</span></h4>
                        <input type="number" step="0.01" name="option_${optionNumber}_price_quarterly" placeholder="Leave blank to hide" class="quarterly-price-${optionNumber}">
                        <div class="pricing-details quarterly-details-${optionNumber}">Leave empty to show only Pay in Full + Monthly</div>
                    </div>
                </div>
                <div style="margin-top:10px;">
                    <button type="button" class="add-sub-option" style="background:#17a2b8;font-size:0.85em;padding:5px 12px;" onclick="autoCalcPricing(${optionNumber})">Auto-calculate from Pay in Full</button>
                    <span style="color:#999;font-size:0.85em;margin-left:8px;">Sets Monthly = Annual/12, Quarterly = Annual/4</span>
                </div>
            `;
            container.innerHTML = simplePricingHtml;
        }
    }
    
    // Before form submission, ensure all editors sync to textareas
    document.querySelector('form').addEventListener('submit', function() {
        contractTextarea.value = contractEditor.root.innerHTML;
        pdpFromTextarea.value = pdpFromEditor.root.innerHTML;
        pdpTowardTextarea.value = pdpTowardEditor.root.innerHTML;
        
        for (let i = 1; i <= 3; i++) {
            const textarea = document.querySelector(`textarea[name="option_${i}_desc"]`);
            textarea.value = optionEditors[i].root.innerHTML;
        }
    });
    
    // Update price headers on page load for existing values
    window.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.pricing-grid input[type="number"]').forEach(input => {
            if (input.value) {
                updatePriceHeader(input);
            }
        });
    });

    // Support Package Functions
    let supportPackageCount = <?= count($support_packages) ?>;
    let builderRates = null;
    let currentCalculation = null;

    // Initialize package builder on page load
    window.addEventListener('DOMContentLoaded', function() {
        loadCoaches();
    });

    // Load available coaches from API
    async function loadCoaches() {
        try {
            const response = await fetch('api/coaching-rates.php');
            const data = await response.json();
            if (data.coaches && data.coaches.length > 0) {
                const select = document.getElementById('builder-coach');
                data.coaches.forEach(coach => {
                    const option = document.createElement('option');
                    option.value = coach;
                    option.textContent = coach;
                    select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Error loading coaches:', error);
        }
    }

    // Update duration dropdown when coach is selected
    async function updateBuilderDurations() {
        const coach = document.getElementById('builder-coach').value;
        const durationSelect = document.getElementById('builder-duration');
        const sessionsSelect = document.getElementById('builder-sessions');
        const addBtn = document.getElementById('add-package-btn');

        // Reset downstream controls
        durationSelect.innerHTML = '<option value="">Select Duration...</option>';
        durationSelect.disabled = true;
        sessionsSelect.disabled = true;
        addBtn.disabled = true;
        document.getElementById('builder-preview').classList.remove('visible');
        builderRates = null;
        currentCalculation = null;

        if (!coach) return;

        try {
            const response = await fetch(`api/coaching-rates.php?coach=${encodeURIComponent(coach)}`);
            const data = await response.json();

            if (data.durations && data.rates) {
                builderRates = data;
                data.durations.forEach(duration => {
                    const option = document.createElement('option');
                    option.value = duration;
                    option.textContent = `${duration} minutes`;
                    durationSelect.appendChild(option);
                });
                durationSelect.disabled = false;
            }
        } catch (error) {
            console.error('Error loading durations:', error);
        }
    }

    // Update preview when duration or sessions change
    async function updateBuilderPreview() {
        const coach = document.getElementById('builder-coach').value;
        const duration = document.getElementById('builder-duration').value;
        const sessions = document.getElementById('builder-sessions').value;
        const sessionsSelect = document.getElementById('builder-sessions');
        const addBtn = document.getElementById('add-package-btn');
        const preview = document.getElementById('builder-preview');

        // Enable sessions dropdown when duration is selected
        if (duration) {
            sessionsSelect.disabled = false;
        }

        // Hide preview and disable button if not all selected
        if (!coach || !duration || !sessions) {
            preview.classList.remove('visible');
            addBtn.disabled = true;
            currentCalculation = null;
            return;
        }

        try {
            const response = await fetch(`api/coaching-rates.php?coach=${encodeURIComponent(coach)}&duration=${duration}&sessions=${sessions}`);
            const data = await response.json();

            if (data.calculation) {
                currentCalculation = data.calculation;

                document.getElementById('preview-regular').textContent = `$${data.calculation.regular_price.toFixed(2)}`;
                document.getElementById('preview-package').textContent = `$${data.calculation.package_price.toFixed(2)}`;
                document.getElementById('preview-savings').textContent = `$${data.calculation.savings.toFixed(2)} (${data.calculation.savings_percent}%)`;

                preview.classList.add('visible');
                addBtn.disabled = false;
            }
        } catch (error) {
            console.error('Error calculating package:', error);
        }
    }

    // Add the built package to the form
    function addBuiltPackage() {
        if (!currentCalculation) return;

        const container = document.getElementById('support-packages-container');
        supportPackageCount++;

        // Remove "no packages" message if exists
        const noPackagesMsg = container.querySelector('.no-packages-message');
        if (noPackagesMsg) noPackagesMsg.remove();

        const coach = document.getElementById('builder-coach').value;
        const duration = document.getElementById('builder-duration').value;
        const sessions = document.getElementById('builder-sessions').value;

        const packageHtml = `
            <div class="package-with-savings">
                <input type="hidden" name="support_package_${supportPackageCount}_name" value="${currentCalculation.package_name}">
                <input type="hidden" name="support_package_${supportPackageCount}_description" value="${currentCalculation.description}">
                <input type="hidden" name="support_package_${supportPackageCount}_coach" value="${coach}">
                <input type="hidden" name="support_package_${supportPackageCount}_duration" value="${duration}">
                <input type="hidden" name="support_package_${supportPackageCount}_sessions" value="${sessions}">
                <input type="hidden" name="support_package_${supportPackageCount}_regular_price" value="${currentCalculation.regular_price}">
                <input type="hidden" name="support_package_${supportPackageCount}_package_price" value="${currentCalculation.package_price}">
                <input type="hidden" name="support_package_${supportPackageCount}_savings" value="${currentCalculation.savings}">
                <input type="hidden" name="support_package_${supportPackageCount}_price" value="${currentCalculation.package_price}">
                <div class="package-savings-header">
                    <span class="name">${currentCalculation.package_name}</span>
                    <button type="button" class="remove-btn" onclick="removePackageWithSavings(this)">Remove</button>
                </div>
                <div class="package-savings-body">
                    <div class="description">${currentCalculation.description}</div>
                    <div class="package-pricing-display">
                        <div class="pricing-box regular">
                            <div class="label">Regular Price</div>
                            <div class="amount">$${currentCalculation.regular_price.toFixed(2)}</div>
                        </div>
                        <div class="pricing-box package">
                            <div class="label">Package Price</div>
                            <div class="amount">$${currentCalculation.package_price.toFixed(2)}</div>
                        </div>
                        <div class="pricing-box savings">
                            <div class="label">Savings</div>
                            <div class="amount">$${currentCalculation.savings.toFixed(2)}</div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        container.insertAdjacentHTML('beforeend', packageHtml);

        // Reset the builder
        document.getElementById('builder-coach').value = '';
        document.getElementById('builder-duration').innerHTML = '<option value="">Select Duration...</option>';
        document.getElementById('builder-duration').disabled = true;
        document.getElementById('builder-sessions').value = '';
        document.getElementById('builder-sessions').disabled = true;
        document.getElementById('add-package-btn').disabled = true;
        document.getElementById('builder-preview').classList.remove('visible');
        currentCalculation = null;
    }

    // Remove package with savings display
    function removePackageWithSavings(button) {
        const packageItem = button.closest('.package-with-savings');
        const container = document.getElementById('support-packages-container');
        packageItem.remove();
        checkEmptyPackages();
    }

    // Add manual support package (legacy)
    function addSupportPackage() {
        const container = document.getElementById('support-packages-container');
        supportPackageCount++;

        // Remove "no packages" message if exists
        const noPackagesMsg = container.querySelector('.no-packages-message');
        if (noPackagesMsg) noPackagesMsg.remove();

        const packageHtml = `
            <div class="support-package-item">
                <div class="support-package-header">
                    <input type="text" name="support_package_${supportPackageCount}_name" placeholder="Package Name (e.g., Email Support)" class="package-name-input">
                    <button type="button" class="remove-support-package" onclick="removeSupportPackage(this)">Remove</button>
                </div>
                <div class="support-package-body">
                    <div class="package-description">
                        <label>Description:</label>
                        <input type="text" name="support_package_${supportPackageCount}_description" placeholder="Brief description of what's included">
                    </div>
                    <div class="package-pricing">
                        <div class="pricing-column">
                            <label>Price:</label>
                            <input type="number" step="0.01" name="support_package_${supportPackageCount}_price" placeholder="$0.00">
                        </div>
                    </div>
                </div>
            </div>
        `;

        container.insertAdjacentHTML('beforeend', packageHtml);
    }

    function removeSupportPackage(button) {
        const packageItem = button.closest('.support-package-item');
        packageItem.remove();
        checkEmptyPackages();
    }

    function checkEmptyPackages() {
        const container = document.getElementById('support-packages-container');
        const hasPackages = container.querySelectorAll('.support-package-item, .package-with-savings').length > 0;
        if (!hasPackages) {
            container.innerHTML = '<div class="no-packages-message">No support packages added yet. Use the Package Builder above or add manually below.</div>';
        }
    }
    </script>
</body>
</html>