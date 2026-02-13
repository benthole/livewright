<?php
/**
 * Keap Custom Field Setup / Lookup
 *
 * One-time utility page to find or create the Scholarship Application Link custom field.
 * Access: /scholarship/admin/setup-keap-field.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/keap-helpers.php';
require_auth('login.php');
require_admin();

$message = '';
$field_id = null;

// Fetch contact 22 to find the field
$result = keap_request('GET', '/crm/rest/v1/contacts/22?optional_properties=custom_fields');
$contact = null;
$custom_fields = [];

if ($result['success'] && !empty($result['data'])) {
    $contact = $result['data'];
    $custom_fields = $contact['custom_fields'] ?? [];
}

// Look for the field with "abcde" value or "Scholarship" in the label
$scholarship_field = null;
foreach ($custom_fields as $cf) {
    if (isset($cf['content']) && $cf['content'] === 'abcde') {
        $scholarship_field = $cf;
        break;
    }
}

$page_title = 'Keap Field Setup';
include 'includes/header.php';
?>

<div class="admin-content narrow">
    <div class="page-header">
        <h1>Keap Custom Field Setup</h1>
        <a href="index.php" class="btn btn-secondary btn-small">&larr; Back</a>
    </div>

    <?php if (!$contact): ?>
        <div class="alert-danger">
            <strong>Could not fetch contact #22 from Keap.</strong><br>
            Error: <?= htmlspecialchars($result['error'] ?? 'Unknown') ?> (HTTP <?= $result['http_code'] ?>)
            <?php if ($result['data']): ?>
                <pre style="margin-top:10px;"><?= htmlspecialchars(json_encode($result['data'], JSON_PRETTY_PRINT)) ?></pre>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="detail-section">
            <div class="detail-section-header">Contact #22: <?= htmlspecialchars(($contact['given_name'] ?? '') . ' ' . ($contact['family_name'] ?? '')) ?></div>
            <div class="detail-section-body">
                <?php if ($scholarship_field): ?>
                    <div class="alert-success">
                        <strong>Found it!</strong> The custom field with value "abcde" has <strong>ID: <?= (int)$scholarship_field['id'] ?></strong>
                    </div>
                    <p>Add this to your <code>settings.php</code>:</p>
                    <pre style="background:#f5f5f5; padding:15px; border-radius:4px;">define('KEAP_SCHOLARSHIP_FIELD_ID', <?= (int)$scholarship_field['id'] ?>);</pre>
                <?php else: ?>
                    <div class="alert-danger">
                        No custom field with value "abcde" found on contact #22.
                    </div>
                <?php endif; ?>

                <h5 style="margin-top:25px;">All Custom Fields on Contact #22</h5>
                <table>
                    <thead>
                        <tr>
                            <th>Field ID</th>
                            <th>Content</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($custom_fields)): ?>
                            <tr><td colspan="2" style="text-align:center; color:#999;">No custom fields found</td></tr>
                        <?php else: ?>
                            <?php foreach ($custom_fields as $cf): ?>
                                <tr<?= (isset($cf['content']) && $cf['content'] === 'abcde') ? ' style="background:#d4edda; font-weight:bold;"' : '' ?>>
                                    <td><?= (int)$cf['id'] ?></td>
                                    <td><?= htmlspecialchars(is_array($cf['content']) ? json_encode($cf['content']) : ($cf['content'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
