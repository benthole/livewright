<?php
/**
 * LiveMORE Scholarship Application Form
 *
 * Public-facing form for mission-based discount and need-based scholarship applications.
 */

// Start session for CSRF token
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
    session_start();
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LiveMORE Scholarship Application - Wright Foundation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --lw-blue: #007cba;
            --lw-blue-dark: #005f8a;
            --lw-green: #28a745;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f8f9fa;
            color: #333;
        }

        .app-header {
            background: linear-gradient(135deg, var(--lw-blue), var(--lw-blue-dark));
            color: white;
            padding: 40px 0;
            text-align: center;
        }

        .app-header h1 {
            font-size: 2em;
            margin-bottom: 10px;
        }

        .app-header p {
            font-size: 1.1em;
            opacity: 0.9;
            max-width: 700px;
            margin: 0 auto;
        }

        .form-container {
            max-width: 800px;
            margin: -30px auto 40px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 40px;
            position: relative;
            z-index: 1;
        }

        .section-title {
            font-size: 1.3em;
            color: var(--lw-blue);
            border-bottom: 2px solid var(--lw-blue);
            padding-bottom: 8px;
            margin: 30px 0 20px;
        }

        .section-title:first-of-type {
            margin-top: 0;
        }

        .form-label {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .required::after {
            content: ' *';
            color: #dc3545;
        }

        /* Application type cards */
        .type-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .type-card {
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }

        .type-card:hover {
            border-color: var(--lw-blue);
            background: #f0f7ff;
        }

        .type-card.selected {
            border-color: var(--lw-blue);
            background: #e8f4fd;
        }

        .type-card input[type="radio"] {
            display: none;
        }

        .type-card h4 {
            margin: 0 0 8px;
            color: var(--lw-blue);
        }

        .type-card p {
            margin: 0;
            font-size: 0.9em;
            color: #666;
        }

        /* Expense toggle rows */
        .expense-row {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .expense-row:last-child {
            border-bottom: none;
        }

        .expense-label {
            flex: 1;
            font-weight: 500;
        }

        .expense-toggle {
            display: flex;
            gap: 8px;
        }

        .expense-toggle .btn {
            padding: 4px 16px;
            font-size: 0.85em;
        }

        .expense-toggle .btn-outline-secondary.active {
            background: var(--lw-blue);
            border-color: var(--lw-blue);
            color: white;
        }

        .expense-detail {
            width: 200px;
        }

        .expense-detail input {
            font-size: 0.9em;
        }

        /* File upload zone */
        .upload-zone {
            border: 2px dashed #ccc;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #fafafa;
        }

        .upload-zone:hover, .upload-zone.dragover {
            border-color: var(--lw-blue);
            background: #f0f7ff;
        }

        .upload-zone p {
            margin: 0;
            color: #666;
        }

        .upload-zone .icon {
            font-size: 2em;
            color: #999;
            margin-bottom: 8px;
        }

        .file-list {
            margin-top: 10px;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 5px;
        }

        .file-item .name {
            font-size: 0.9em;
        }

        .file-item .size {
            font-size: 0.8em;
            color: #666;
            margin-left: 10px;
        }

        .file-item .remove {
            color: #dc3545;
            cursor: pointer;
            border: none;
            background: none;
            font-size: 1.2em;
            padding: 0 5px;
        }

        /* Profession checkboxes */
        .profession-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .profession-grid .form-check {
            padding: 10px 15px 10px 35px;
            background: #f8f9fa;
            border-radius: 4px;
        }

        /* Hidden sections */
        .conditional-section {
            display: none;
        }

        .conditional-section.visible {
            display: block;
        }

        /* Submit area */
        .submit-area {
            margin-top: 30px;
            text-align: center;
        }

        .submit-area .btn-primary {
            background: var(--lw-blue);
            border-color: var(--lw-blue);
            padding: 12px 40px;
            font-size: 1.1em;
        }

        .submit-area .btn-primary:hover {
            background: var(--lw-blue-dark);
            border-color: var(--lw-blue-dark);
        }

        .alert-validation {
            display: none;
        }

        @media (max-width: 768px) {
            .type-cards {
                grid-template-columns: 1fr;
            }

            .form-container {
                margin: -20px 15px 30px;
                padding: 25px;
            }

            .expense-row {
                flex-wrap: wrap;
            }

            .expense-detail {
                width: 100%;
            }

            .profession-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="app-header">
    <div class="container">
        <h1>LiveMORE Scholarship Application</h1>
        <p>The Wright Foundation is committed to making transformational education accessible. Apply for a mission-based discount or need-based scholarship below.</p>
    </div>
</div>

<div class="form-container">
    <div class="alert alert-danger alert-validation" id="validationAlert" role="alert">
        Please correct the errors below before submitting.
    </div>

    <form id="scholarshipForm" action="api/submit.php" method="POST" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <!-- Section 1: Application Type -->
        <h3 class="section-title">Application Type</h3>
        <div class="type-cards">
            <label class="type-card" id="card-mission">
                <input type="radio" name="application_type" value="mission_discount" required>
                <h4>Mission-Based Discount</h4>
                <p>For educators, nonprofit professionals, coaches, and entrepreneurs advancing human potential.</p>
            </label>
            <label class="type-card" id="card-need">
                <input type="radio" name="application_type" value="need_scholarship">
                <h4>Need-Based Scholarship</h4>
                <p>For applicants who require financial assistance to participate in the LiveMORE program.</p>
            </label>
        </div>

        <!-- Section 2: Contact Information -->
        <h3 class="section-title">Contact Information</h3>
        <div class="row g-3">
            <div class="col-md-6">
                <label for="first_name" class="form-label required">First Name</label>
                <input type="text" class="form-control" id="first_name" name="first_name" required>
            </div>
            <div class="col-md-6">
                <label for="last_name" class="form-label required">Last Name</label>
                <input type="text" class="form-control" id="last_name" name="last_name" required>
            </div>
            <div class="col-md-8">
                <label for="email" class="form-label required">Email Address</label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>
            <div class="col-12">
                <label for="street_address" class="form-label">Street Address</label>
                <input type="text" class="form-control" id="street_address" name="street_address">
            </div>
            <div class="col-md-5">
                <label for="city" class="form-label">City</label>
                <input type="text" class="form-control" id="city" name="city">
            </div>
            <div class="col-md-4">
                <label for="state_zip" class="form-label">State / Zip</label>
                <input type="text" class="form-control" id="state_zip" name="state_zip">
            </div>
            <div class="col-md-3">
                <label for="country" class="form-label">Country</label>
                <input type="text" class="form-control" id="country" name="country" value="United States">
            </div>
            <div class="col-md-4">
                <label for="cell_phone" class="form-label">Cell Phone</label>
                <input type="tel" class="form-control" id="cell_phone" name="cell_phone">
            </div>
            <div class="col-md-4">
                <label for="work_phone" class="form-label">Work Phone</label>
                <input type="tel" class="form-control" id="work_phone" name="work_phone">
            </div>
            <div class="col-md-4">
                <label for="other_phone" class="form-label">Other Phone</label>
                <input type="tel" class="form-control" id="other_phone" name="other_phone">
            </div>
        </div>

        <!-- Section 3: Capacity-Based Scholarship (need-based only) -->
        <div id="needBasedSection" class="conditional-section">
            <h3 class="section-title">Financial Information</h3>
            <p class="text-muted mb-3">This information helps us assess your scholarship eligibility. All information is kept strictly confidential.</p>

            <h5 class="mt-3 mb-3">Income</h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="gross_income" class="form-label">Your Gross Annual Income</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="text" class="form-control" id="gross_income" name="gross_income" placeholder="0">
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="gross_income_spouse" class="form-label">Spouse's Gross Annual Income</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="text" class="form-control" id="gross_income_spouse" name="gross_income_spouse" placeholder="0">
                    </div>
                </div>
                <div class="col-12">
                    <label for="other_income_sources" class="form-label">Other Sources of Income</label>
                    <textarea class="form-control" id="other_income_sources" name="other_income_sources" rows="2" placeholder="Rental income, investments, etc."></textarea>
                </div>
                <div class="col-12">
                    <label for="other_assets_income" class="form-label">Other Assets That Generate Income</label>
                    <textarea class="form-control" id="other_assets_income" name="other_assets_income" rows="2" placeholder="Real estate, business ownership, etc."></textarea>
                </div>
            </div>

            <h5 class="mt-4 mb-3">Expenses</h5>

            <!-- Alimony -->
            <div class="expense-row">
                <span class="expense-label">Do you pay alimony or child support?</span>
                <div class="expense-toggle" data-field="alimony">
                    <button type="button" class="btn btn-sm btn-outline-secondary toggle-yes">Yes</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary toggle-no active">No</button>
                </div>
                <div class="expense-detail" style="display:none;">
                    <input type="hidden" name="has_alimony" value="0">
                    <input type="text" class="form-control form-control-sm" name="alimony_percent" placeholder="% of income">
                </div>
            </div>

            <!-- Student Loans -->
            <div class="expense-row">
                <span class="expense-label">Do you have student loans?</span>
                <div class="expense-toggle" data-field="student_loans">
                    <button type="button" class="btn btn-sm btn-outline-secondary toggle-yes">Yes</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary toggle-no active">No</button>
                </div>
                <div class="expense-detail" style="display:none;">
                    <input type="hidden" name="has_student_loans" value="0">
                    <input type="text" class="form-control form-control-sm" name="student_loan_monthly" placeholder="Monthly payment">
                </div>
            </div>

            <!-- Medical Expenses -->
            <div class="expense-row">
                <span class="expense-label">Do you have significant medical expenses?</span>
                <div class="expense-toggle" data-field="medical_expenses">
                    <button type="button" class="btn btn-sm btn-outline-secondary toggle-yes">Yes</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary toggle-no active">No</button>
                </div>
                <div class="expense-detail" style="display:none;">
                    <input type="hidden" name="has_medical_expenses" value="0">
                    <input type="text" class="form-control form-control-sm" name="medical_expenses_monthly" placeholder="Monthly amount">
                </div>
            </div>

            <!-- Familial Support -->
            <div class="expense-row">
                <span class="expense-label">Do you support family members financially?</span>
                <div class="expense-toggle" data-field="familial_support">
                    <button type="button" class="btn btn-sm btn-outline-secondary toggle-yes">Yes</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary toggle-no active">No</button>
                </div>
                <div class="expense-detail" style="display:none;">
                    <input type="hidden" name="has_familial_support" value="0">
                    <input type="text" class="form-control form-control-sm" name="familial_support_monthly" placeholder="Monthly amount">
                </div>
            </div>

            <!-- Dependent in College -->
            <div class="expense-row">
                <span class="expense-label">Do you have dependents in college?</span>
                <div class="expense-toggle" data-field="dependent_college">
                    <button type="button" class="btn btn-sm btn-outline-secondary toggle-yes">Yes</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary toggle-no active">No</button>
                </div>
                <div class="expense-detail" style="display:none;">
                    <input type="hidden" name="has_dependent_college" value="0">
                    <input type="text" class="form-control form-control-sm" name="dependent_college_count" placeholder="How many?">
                </div>
            </div>

            <!-- Children Under 18 -->
            <div class="expense-row">
                <span class="expense-label">Do you have children under 18?</span>
                <div class="expense-toggle" data-field="children_under_18">
                    <button type="button" class="btn btn-sm btn-outline-secondary toggle-yes">Yes</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary toggle-no active">No</button>
                </div>
                <div class="expense-detail" style="display:none;">
                    <input type="hidden" name="has_children_under_18" value="0">
                    <input type="text" class="form-control form-control-sm" name="children_names_ages" placeholder="Names and ages">
                </div>
            </div>

            <div class="mt-3">
                <label for="additional_info" class="form-label">Additional Information</label>
                <textarea class="form-control" id="additional_info" name="additional_info" rows="3" placeholder="Any other financial circumstances you'd like us to consider..."></textarea>
            </div>
        </div>

        <!-- Section 4: Mission-Based Information (all applicants) -->
        <h3 class="section-title">Mission Information</h3>
        <p class="text-muted mb-3">Even if applying for a need-based scholarship, please complete this section.</p>

        <label class="form-label">Which of the following describe your profession? <span class="text-muted">(select all that apply)</span></label>
        <div class="profession-grid mb-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="is_educator" name="is_educator" value="1">
                <label class="form-check-label" for="is_educator">Educator</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="is_nonprofit" name="is_nonprofit" value="1">
                <label class="form-check-label" for="is_nonprofit">Nonprofit Professional</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="is_coach" name="is_coach" value="1">
                <label class="form-check-label" for="is_coach">Coach</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="is_entrepreneur" name="is_entrepreneur" value="1">
                <label class="form-check-label" for="is_entrepreneur">Entrepreneur</label>
            </div>
        </div>

        <div class="mb-3">
            <label for="employer_name" class="form-label">Employer / Organization Name</label>
            <input type="text" class="form-control" id="employer_name" name="employer_name">
        </div>

        <div class="mb-3">
            <label for="essay" class="form-label required">Tell us about your mission</label>
            <p class="text-muted small">How will participating in the LiveMORE program help you advance your mission and make a greater impact in the world?</p>
            <textarea class="form-control" id="essay" name="essay" rows="6" required placeholder="Share your story, your mission, and how this program would help you create greater impact..."></textarea>
        </div>

        <!-- Documentation Upload -->
        <div class="mb-3">
            <label class="form-label">Supporting Documentation <span class="text-muted">(optional)</span></label>
            <p class="text-muted small">Upload any supporting documents such as pay stubs, tax returns, or letters of recommendation. PDF, JPG, or PNG files up to 10MB each.</p>
            <div class="upload-zone" id="uploadZone">
                <div class="icon">&#128206;</div>
                <p><strong>Click to select files</strong> or drag and drop</p>
                <p class="small text-muted">PDF, JPG, PNG &mdash; Max 10MB per file</p>
            </div>
            <input type="file" id="fileInput" name="documentation[]" multiple accept=".pdf,.jpg,.jpeg,.png" style="display:none;">
            <div class="file-list" id="fileList"></div>
        </div>

        <!-- Submit -->
        <div class="submit-area">
            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">Submit Application</button>
            <p class="text-muted small mt-2">Your information is kept strictly confidential and used only for scholarship evaluation.</p>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('scholarshipForm');
    const needSection = document.getElementById('needBasedSection');
    const typeCards = document.querySelectorAll('.type-card');
    const validationAlert = document.getElementById('validationAlert');
    const submitBtn = document.getElementById('submitBtn');

    // Application type selection
    typeCards.forEach(function(card) {
        card.addEventListener('click', function() {
            typeCards.forEach(function(c) { c.classList.remove('selected'); });
            card.classList.add('selected');
            card.querySelector('input[type="radio"]').checked = true;

            if (card.querySelector('input').value === 'need_scholarship') {
                needSection.classList.add('visible');
            } else {
                needSection.classList.remove('visible');
            }
        });
    });

    // Expense yes/no toggles
    document.querySelectorAll('.expense-toggle').forEach(function(toggle) {
        var row = toggle.closest('.expense-row');
        var detail = row.querySelector('.expense-detail');
        var hidden = detail.querySelector('input[type="hidden"]');
        var yesBtn = toggle.querySelector('.toggle-yes');
        var noBtn = toggle.querySelector('.toggle-no');

        yesBtn.addEventListener('click', function() {
            yesBtn.classList.add('active');
            noBtn.classList.remove('active');
            detail.style.display = '';
            hidden.value = '1';
        });

        noBtn.addEventListener('click', function() {
            noBtn.classList.add('active');
            yesBtn.classList.remove('active');
            detail.style.display = 'none';
            hidden.value = '0';
        });
    });

    // File upload
    var uploadZone = document.getElementById('uploadZone');
    var fileInput = document.getElementById('fileInput');
    var fileList = document.getElementById('fileList');
    var selectedFiles = new DataTransfer();

    uploadZone.addEventListener('click', function() {
        fileInput.click();
    });

    uploadZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        uploadZone.classList.add('dragover');
    });

    uploadZone.addEventListener('dragleave', function() {
        uploadZone.classList.remove('dragover');
    });

    uploadZone.addEventListener('drop', function(e) {
        e.preventDefault();
        uploadZone.classList.remove('dragover');
        addFiles(e.dataTransfer.files);
    });

    fileInput.addEventListener('change', function() {
        addFiles(fileInput.files);
    });

    function addFiles(files) {
        var maxSize = 10 * 1024 * 1024;
        var allowed = ['application/pdf', 'image/jpeg', 'image/png'];

        Array.from(files).forEach(function(file) {
            if (file.size > maxSize) {
                alert(file.name + ' is too large. Maximum file size is 10MB.');
                return;
            }
            if (allowed.indexOf(file.type) === -1) {
                alert(file.name + ' is not a supported file type. Please upload PDF, JPG, or PNG files.');
                return;
            }
            selectedFiles.items.add(file);
        });

        fileInput.files = selectedFiles.files;
        renderFileList();
    }

    function renderFileList() {
        fileList.innerHTML = '';
        Array.from(selectedFiles.files).forEach(function(file, idx) {
            var div = document.createElement('div');
            div.className = 'file-item';
            var sizeKB = (file.size / 1024).toFixed(0);
            var sizeStr = sizeKB > 1024 ? (file.size / (1024*1024)).toFixed(1) + ' MB' : sizeKB + ' KB';
            div.innerHTML = '<span><span class="name">' + escapeHtml(file.name) + '</span><span class="size">' + sizeStr + '</span></span>' +
                '<button type="button" class="remove" data-idx="' + idx + '">&times;</button>';
            fileList.appendChild(div);
        });

        fileList.querySelectorAll('.remove').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var idx = parseInt(this.getAttribute('data-idx'));
                var newDt = new DataTransfer();
                Array.from(selectedFiles.files).forEach(function(f, i) {
                    if (i !== idx) newDt.items.add(f);
                });
                selectedFiles = newDt;
                fileInput.files = selectedFiles.files;
                renderFileList();
            });
        });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Client-side validation
    form.addEventListener('submit', function(e) {
        var errors = [];

        if (!form.querySelector('input[name="application_type"]:checked')) {
            errors.push('Please select an application type.');
        }

        var firstName = form.querySelector('#first_name').value.trim();
        var lastName = form.querySelector('#last_name').value.trim();
        var email = form.querySelector('#email').value.trim();
        var essay = form.querySelector('#essay').value.trim();

        if (!firstName) errors.push('First name is required.');
        if (!lastName) errors.push('Last name is required.');
        if (!email) errors.push('Email address is required.');
        else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.push('Please enter a valid email address.');
        if (!essay) errors.push('The mission essay is required.');

        if (errors.length > 0) {
            e.preventDefault();
            validationAlert.style.display = 'block';
            validationAlert.innerHTML = '<strong>Please correct the following:</strong><ul class="mb-0 mt-1">' +
                errors.map(function(err) { return '<li>' + err + '</li>'; }).join('') + '</ul>';
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }

        validationAlert.style.display = 'none';
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';
    });
});
</script>
</body>
</html>
