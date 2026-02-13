<?php
/**
 * Scholarship Application Submission Handler
 *
 * Validates form input, handles file uploads, saves to database,
 * syncs with Keap, and redirects to thank-you page.
 */

require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../lib/keap-helpers.php');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

// Verify CSRF token
if (empty($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('Invalid request. Please go back and try again.');
}

// --- Validation ---
$errors = [];

$application_type = trim($_POST['application_type'] ?? '');
if (!in_array($application_type, ['mission_discount', 'need_scholarship'])) {
    $errors[] = 'Please select a valid application type.';
}

$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$essay = trim($_POST['essay'] ?? '');

if ($first_name === '') $errors[] = 'First name is required.';
if ($last_name === '') $errors[] = 'Last name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if ($essay === '') $errors[] = 'The mission essay is required.';

if (!empty($errors)) {
    // Store errors in session and redirect back
    $_SESSION['scholarship_errors'] = $errors;
    $_SESSION['scholarship_input'] = $_POST;
    header('Location: ../index.php');
    exit;
}

// --- Generate unique ID ---
$unique_id = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

// --- Handle file uploads ---
$uploaded_files = [];
if (!empty($_FILES['documentation']['name'][0])) {
    $upload_dir = __DIR__ . '/../uploads/' . $unique_id;
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $file_count = count($_FILES['documentation']['name']);
    for ($i = 0; $i < $file_count; $i++) {
        if ($_FILES['documentation']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmp_name = $_FILES['documentation']['tmp_name'][$i];
        $original_name = basename($_FILES['documentation']['name'][$i]);
        $file_size = $_FILES['documentation']['size'][$i];

        // Validate size
        if ($file_size > UPLOAD_MAX_SIZE) {
            continue;
        }

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp_name);
        finfo_close($finfo);

        if (!in_array($mime, UPLOAD_ALLOWED_TYPES)) {
            continue;
        }

        // Validate extension
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if (!in_array($ext, UPLOAD_ALLOWED_EXTENSIONS)) {
            continue;
        }

        // Generate safe filename
        $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_name);
        $dest = $upload_dir . '/' . $safe_name;

        // Avoid overwrites
        if (file_exists($dest)) {
            $safe_name = pathinfo($safe_name, PATHINFO_FILENAME) . '_' . time() . '.' . $ext;
            $dest = $upload_dir . '/' . $safe_name;
        }

        if (move_uploaded_file($tmp_name, $dest)) {
            $uploaded_files[] = [
                'name' => $original_name,
                'stored_name' => $safe_name,
                'size' => $file_size,
                'mime' => $mime
            ];
        }
    }
}

// --- Collect form data ---
$street_address = trim($_POST['street_address'] ?? '');
$city = trim($_POST['city'] ?? '');
$state_zip = trim($_POST['state_zip'] ?? '');
$country = trim($_POST['country'] ?? 'United States');
$cell_phone = trim($_POST['cell_phone'] ?? '');
$work_phone = trim($_POST['work_phone'] ?? '');
$other_phone = trim($_POST['other_phone'] ?? '');

// Income fields (need-based only)
$gross_income = trim($_POST['gross_income'] ?? '');
$gross_income_spouse = trim($_POST['gross_income_spouse'] ?? '');
$other_income_sources = trim($_POST['other_income_sources'] ?? '');
$other_assets_income = trim($_POST['other_assets_income'] ?? '');

// Expense toggles
$has_alimony = (int)($_POST['has_alimony'] ?? 0);
$alimony_percent = $has_alimony ? trim($_POST['alimony_percent'] ?? '') : '';
$has_student_loans = (int)($_POST['has_student_loans'] ?? 0);
$student_loan_monthly = $has_student_loans ? trim($_POST['student_loan_monthly'] ?? '') : '';
$has_medical_expenses = (int)($_POST['has_medical_expenses'] ?? 0);
$medical_expenses_monthly = $has_medical_expenses ? trim($_POST['medical_expenses_monthly'] ?? '') : '';
$has_familial_support = (int)($_POST['has_familial_support'] ?? 0);
$familial_support_monthly = $has_familial_support ? trim($_POST['familial_support_monthly'] ?? '') : '';
$has_dependent_college = (int)($_POST['has_dependent_college'] ?? 0);
$dependent_college_count = $has_dependent_college ? trim($_POST['dependent_college_count'] ?? '') : '';
$has_children_under_18 = (int)($_POST['has_children_under_18'] ?? 0);
$children_names_ages = $has_children_under_18 ? trim($_POST['children_names_ages'] ?? '') : '';
$additional_info = trim($_POST['additional_info'] ?? '');

// Mission fields
$is_educator = isset($_POST['is_educator']) ? 1 : 0;
$is_nonprofit = isset($_POST['is_nonprofit']) ? 1 : 0;
$is_coach = isset($_POST['is_coach']) ? 1 : 0;
$is_entrepreneur = isset($_POST['is_entrepreneur']) ? 1 : 0;
$employer_name = trim($_POST['employer_name'] ?? '');

// --- Insert into database ---
try {
    $stmt = $pdo->prepare("
        INSERT INTO scholarship_applications (
            unique_id, application_type,
            first_name, last_name, email,
            street_address, city, state_zip, country,
            cell_phone, work_phone, other_phone,
            gross_income, gross_income_spouse, other_income_sources, other_assets_income,
            has_alimony, alimony_percent,
            has_student_loans, student_loan_monthly,
            has_medical_expenses, medical_expenses_monthly,
            has_familial_support, familial_support_monthly,
            has_dependent_college, dependent_college_count,
            has_children_under_18, children_names_ages,
            additional_info,
            is_educator, is_nonprofit, is_coach, is_entrepreneur,
            employer_name, essay, documentation_files
        ) VALUES (
            :unique_id, :application_type,
            :first_name, :last_name, :email,
            :street_address, :city, :state_zip, :country,
            :cell_phone, :work_phone, :other_phone,
            :gross_income, :gross_income_spouse, :other_income_sources, :other_assets_income,
            :has_alimony, :alimony_percent,
            :has_student_loans, :student_loan_monthly,
            :has_medical_expenses, :medical_expenses_monthly,
            :has_familial_support, :familial_support_monthly,
            :has_dependent_college, :dependent_college_count,
            :has_children_under_18, :children_names_ages,
            :additional_info,
            :is_educator, :is_nonprofit, :is_coach, :is_entrepreneur,
            :employer_name, :essay, :documentation_files
        )
    ");

    $stmt->execute([
        'unique_id' => $unique_id,
        'application_type' => $application_type,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'street_address' => $street_address,
        'city' => $city,
        'state_zip' => $state_zip,
        'country' => $country,
        'cell_phone' => $cell_phone,
        'work_phone' => $work_phone,
        'other_phone' => $other_phone,
        'gross_income' => $gross_income,
        'gross_income_spouse' => $gross_income_spouse,
        'other_income_sources' => $other_income_sources,
        'other_assets_income' => $other_assets_income,
        'has_alimony' => $has_alimony,
        'alimony_percent' => $alimony_percent,
        'has_student_loans' => $has_student_loans,
        'student_loan_monthly' => $student_loan_monthly,
        'has_medical_expenses' => $has_medical_expenses,
        'medical_expenses_monthly' => $medical_expenses_monthly,
        'has_familial_support' => $has_familial_support,
        'familial_support_monthly' => $familial_support_monthly,
        'has_dependent_college' => $has_dependent_college,
        'dependent_college_count' => $dependent_college_count,
        'has_children_under_18' => $has_children_under_18,
        'children_names_ages' => $children_names_ages,
        'additional_info' => $additional_info,
        'is_educator' => $is_educator,
        'is_nonprofit' => $is_nonprofit,
        'is_coach' => $is_coach,
        'is_entrepreneur' => $is_entrepreneur,
        'employer_name' => $employer_name,
        'essay' => $essay,
        'documentation_files' => !empty($uploaded_files) ? json_encode($uploaded_files) : null
    ]);

} catch (PDOException $e) {
    error_log('Scholarship insert failed: ' . $e->getMessage());
    die('An error occurred saving your application. Please try again or contact LiveMORE@livewright.com.');
}

// --- Keap Integration (non-blocking - log errors but don't fail) ---
$keap_contact_id = null;
try {
    $keapResult = keap_upsert_contact([
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'street_address' => $street_address,
        'city' => $city,
        'state_zip' => $state_zip,
        'country' => $country,
        'cell_phone' => $cell_phone,
        'work_phone' => $work_phone,
        'other_phone' => $other_phone
    ]);

    if ($keapResult['success'] && $keapResult['contact_id']) {
        $keap_contact_id = $keapResult['contact_id'];

        // Update the DB record with the Keap contact ID
        $updateStmt = $pdo->prepare("UPDATE scholarship_applications SET keap_contact_id = ? WHERE unique_id = ?");
        $updateStmt->execute([$keap_contact_id, $unique_id]);

        // Set the custom field with the admin view link
        keap_set_scholarship_link($keap_contact_id, $unique_id);
    }
} catch (Exception $e) {
    error_log('Keap sync failed for scholarship ' . $unique_id . ': ' . $e->getMessage());
}

// --- Redirect to thank-you page ---
$_SESSION['scholarship_submitted'] = true;
$_SESSION['scholarship_name'] = $first_name;

// Regenerate CSRF token for next submission
unset($_SESSION['csrf_token']);

header('Location: ../thank-you.php');
exit;
