<?php
/**
 * Scholarship Admin - View Application
 *
 * This is the page that Keap custom field links point to.
 * Displays all submitted data with admin controls.
 */
require_once __DIR__ . '/../config.php';
require_auth('login.php');

$unique_id = $_GET['id'] ?? '';
if (empty($unique_id)) {
    header('Location: index.php');
    exit;
}

// Fetch application
$stmt = $pdo->prepare("SELECT * FROM scholarship_applications WHERE unique_id = ? AND deleted_at IS NULL");
$stmt->execute([$unique_id]);
$app = $stmt->fetch();

if (!$app) {
    header('Location: index.php');
    exit;
}

// Handle status/notes update
$success_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $user = get_logged_in_user();

    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update_status') {
            $new_status = $_POST['status'];
            $valid_statuses = ['pending', 'under_review', 'approved', 'denied'];
            if (in_array($new_status, $valid_statuses)) {
                $stmt = $pdo->prepare("UPDATE scholarship_applications SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                $stmt->execute([$new_status, $user['name'], $app['id']]);
                $app['status'] = $new_status;
                $app['reviewed_by'] = $user['name'];
                $success_msg = 'Status updated.';
            }
        } elseif ($_POST['action'] === 'update_notes') {
            $notes = trim($_POST['admin_notes'] ?? '');
            $stmt = $pdo->prepare("UPDATE scholarship_applications SET admin_notes = ? WHERE id = ?");
            $stmt->execute([$notes, $app['id']]);
            $app['admin_notes'] = $notes;
            $success_msg = 'Notes saved.';
        }
    }
}

$is_need_based = $app['application_type'] === 'need_scholarship';
$documentation = $app['documentation_files'] ? json_decode($app['documentation_files'], true) : [];

$page_title = $app['first_name'] . ' ' . $app['last_name'] . ' - Application';

$page_styles = '';
include 'includes/header.php';
?>

<div class="admin-content narrow">
    <div class="page-header no-print">
        <div>
            <a href="index.php" class="btn btn-secondary btn-small">&larr; Back to List</a>
            <button onclick="window.print()" class="btn btn-small" style="margin-left:5px;">Print</button>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert-success"><?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>

    <!-- Admin Controls -->
    <div class="admin-panel no-print">
        <h3>Admin Controls</h3>
        <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: end;">
            <form method="POST" style="flex:1; min-width:200px;">
                <input type="hidden" name="action" value="update_status">
                <?= csrf_field() ?>
                <label class="form-label" style="font-weight:600;">Status</label>
                <div style="display:flex; gap:8px;">
                    <select name="status" class="form-select">
                        <option value="pending" <?= $app['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="under_review" <?= $app['status'] === 'under_review' ? 'selected' : '' ?>>Under Review</option>
                        <option value="approved" <?= $app['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="denied" <?= $app['status'] === 'denied' ? 'selected' : '' ?>>Denied</option>
                    </select>
                    <button type="submit" class="btn">Update</button>
                </div>
                <?php if ($app['reviewed_by']): ?>
                    <small class="text-muted">Last reviewed by <?= htmlspecialchars($app['reviewed_by']) ?><?= $app['reviewed_at'] ? ' on ' . date('M j, Y g:ia', strtotime($app['reviewed_at'])) : '' ?></small>
                <?php endif; ?>
            </form>

            <form method="POST" style="flex:2; min-width:300px;">
                <input type="hidden" name="action" value="update_notes">
                <?= csrf_field() ?>
                <label class="form-label" style="font-weight:600;">Admin Notes</label>
                <div style="display:flex; gap:8px;">
                    <textarea name="admin_notes" class="form-control" rows="2" placeholder="Internal notes..."><?= htmlspecialchars($app['admin_notes'] ?? '') ?></textarea>
                    <button type="submit" class="btn" style="align-self:end;">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Application Header -->
    <div style="text-align:center; margin-bottom:25px;">
        <h2 style="margin:0;"><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></h2>
        <div style="margin-top:8px;">
            <?php if ($is_need_based): ?>
                <span class="badge-type badge-need" style="font-size:1em; padding:5px 12px;">Need-Based Scholarship</span>
            <?php else: ?>
                <span class="badge-type badge-mission" style="font-size:1em; padding:5px 12px;">Mission-Based Discount</span>
            <?php endif; ?>
            <span class="badge-status badge-<?= $app['status'] ?>" style="margin-left:8px;"><?= ucwords(str_replace('_', ' ', $app['status'])) ?></span>
        </div>
        <small class="text-muted">Submitted <?= date('F j, Y \a\t g:i A', strtotime($app['created_at'])) ?></small>
    </div>

    <!-- Contact Information -->
    <div class="detail-section">
        <div class="detail-section-header">Contact Information</div>
        <div class="detail-section-body">
            <?php
            $contact_fields = [
                'Email' => '<a href="mailto:' . htmlspecialchars($app['email']) . '">' . htmlspecialchars($app['email']) . '</a>',
                'Address' => implode(', ', array_filter([
                    $app['street_address'],
                    $app['city'],
                    $app['state_zip'],
                    $app['country'] !== 'United States' ? $app['country'] : ''
                ])),
                'Cell Phone' => $app['cell_phone'],
                'Work Phone' => $app['work_phone'],
                'Other Phone' => $app['other_phone'],
            ];

            if ($app['keap_contact_id']) {
                $contact_fields['Keap Contact'] = '<a href="https://dja794.infusionsoft.com/Contact/manageContact.jsp?view=edit&ID=' . (int)$app['keap_contact_id'] . '" target="_blank">View in Keap (#' . (int)$app['keap_contact_id'] . ')</a>';
            }

            foreach ($contact_fields as $label => $value):
            ?>
            <div class="detail-row">
                <div class="detail-label"><?= $label ?></div>
                <div class="detail-value <?= empty(strip_tags($value)) ? 'empty' : '' ?>">
                    <?= empty(strip_tags($value)) ? '&mdash;' : $value ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Financial Information (need-based only) -->
    <?php if ($is_need_based): ?>
    <div class="detail-section">
        <div class="detail-section-header">Financial Information</div>
        <div class="detail-section-body">
            <h5 style="margin-top:0; margin-bottom:15px; color:#555;">Income</h5>
            <?php
            $income_fields = [
                'Gross Annual Income' => $app['gross_income'] ? '$' . htmlspecialchars($app['gross_income']) : '',
                "Spouse's Gross Income" => $app['gross_income_spouse'] ? '$' . htmlspecialchars($app['gross_income_spouse']) : '',
                'Other Income Sources' => $app['other_income_sources'],
                'Other Assets/Income' => $app['other_assets_income'],
            ];
            foreach ($income_fields as $label => $value):
            ?>
            <div class="detail-row">
                <div class="detail-label"><?= $label ?></div>
                <div class="detail-value <?= empty($value) ? 'empty' : '' ?>"><?= empty($value) ? '&mdash;' : htmlspecialchars($value) ?></div>
            </div>
            <?php endforeach; ?>

            <h5 style="margin-top:20px; margin-bottom:15px; color:#555;">Expenses</h5>
            <?php
            $expenses = [
                ['Alimony / Child Support', $app['has_alimony'], $app['alimony_percent'] ? $app['alimony_percent'] . '% of income' : ''],
                ['Student Loans', $app['has_student_loans'], $app['student_loan_monthly'] ? '$' . $app['student_loan_monthly'] . '/mo' : ''],
                ['Medical Expenses', $app['has_medical_expenses'], $app['medical_expenses_monthly'] ? '$' . $app['medical_expenses_monthly'] . '/mo' : ''],
                ['Familial Support', $app['has_familial_support'], $app['familial_support_monthly'] ? '$' . $app['familial_support_monthly'] . '/mo' : ''],
                ['Dependents in College', $app['has_dependent_college'], $app['dependent_college_count'] ? $app['dependent_college_count'] . ' dependent(s)' : ''],
                ['Children Under 18', $app['has_children_under_18'], $app['children_names_ages']],
            ];
            foreach ($expenses as [$label, $has, $detail]):
            ?>
            <div class="detail-row">
                <div class="detail-label"><?= $label ?></div>
                <div class="detail-value">
                    <?php if ($has): ?>
                        <span style="color:#dc3545; font-weight:600;">Yes</span>
                        <?php if ($detail): ?>
                            &mdash; <?= htmlspecialchars($detail) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#999;">No</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (!empty($app['additional_info'])): ?>
            <div class="detail-row">
                <div class="detail-label">Additional Info</div>
                <div class="detail-value"><?= nl2br(htmlspecialchars($app['additional_info'])) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Mission Information -->
    <div class="detail-section">
        <div class="detail-section-header">Mission Information</div>
        <div class="detail-section-body">
            <div class="detail-row">
                <div class="detail-label">Profession</div>
                <div class="detail-value">
                    <?php
                    $professions = [];
                    if ($app['is_educator']) $professions[] = 'Educator';
                    if ($app['is_nonprofit']) $professions[] = 'Nonprofit Professional';
                    if ($app['is_coach']) $professions[] = 'Coach';
                    if ($app['is_entrepreneur']) $professions[] = 'Entrepreneur';
                    echo !empty($professions) ? implode(', ', $professions) : '<span class="empty">&mdash;</span>';
                    ?>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Employer / Organization</div>
                <div class="detail-value <?= empty($app['employer_name']) ? 'empty' : '' ?>">
                    <?= !empty($app['employer_name']) ? htmlspecialchars($app['employer_name']) : '&mdash;' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Essay -->
    <div class="detail-section">
        <div class="detail-section-header">Mission Essay</div>
        <div class="detail-section-body">
            <div class="essay-text"><?= htmlspecialchars($app['essay']) ?></div>
        </div>
    </div>

    <!-- Documentation -->
    <?php if (!empty($documentation)): ?>
    <div class="detail-section">
        <div class="detail-section-header">Supporting Documentation</div>
        <div class="detail-section-body">
            <?php foreach ($documentation as $file): ?>
                <div style="display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f0f0f0;">
                    <span><?= htmlspecialchars($file['name']) ?></span>
                    <span class="text-muted" style="font-size:0.85em;">(<?= number_format($file['size'] / 1024, 0) ?> KB)</span>
                    <a href="../uploads/<?= urlencode($app['unique_id']) ?>/<?= urlencode($file['stored_name']) ?>"
                       target="_blank" class="btn btn-small btn-info" style="margin-left:auto;">Download</a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
