<?php
// index.php - Display roster data (main entry point)

require_once('includes/auth.php');
require_once('includes/ui.php');
require_once('config.php');
require_once('keap_api.php');
require_once('lib/field_config.php');

// Require authentication
require_auth();

// Get current user info for UI
$current_user = get_logged_in_user();
$user_can_edit = can_edit();
$user_is_admin = is_admin();

// Check if viewing dropped contacts
$viewDropped = isset($_GET['dropped']) && $_GET['dropped'] === '1';

// Connect to livewright database
try {
    $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Dropdown values from the admin Field Organizer (grouping/order/hide),
// which merges live Keap options + config + local data. Falls back to config
// automatically when nothing is organized yet. See lib/field_config.php and
// admin/organize_fields.php. Guarded so any organizer/DB failure degrades to
// config-based rendering instead of breaking the roster page.
// Quarter column is optional: active only once its Keap field id is configured
// in config.php (server-only). Fully dormant otherwise.
$quarterEnabled = isset($quarter_field_id) && (int)$quarter_field_id > 0;
$quarterGroups = [];

try {
    $cohortGroups = fc_dropdown_groups($conn, 'cohort');       // [groupLabel => [team, ...]]
    $individualCoachValues = [];
    foreach (fc_dropdown_groups($conn, 'individual_coach') as $vals) {
        $individualCoachValues = array_merge($individualCoachValues, $vals);
    }
    $groupCoachValues = [];
    foreach (fc_dropdown_groups($conn, 'group_coach') as $vals) {
        $groupCoachValues = array_merge($groupCoachValues, $vals);
    }
    if ($quarterEnabled) {
        $quarterGroups = fc_dropdown_groups($conn, 'quarter'); // [groupLabel => [Q1, ...]]
    }
} catch (Exception $e) {
    error_log('Field organizer fallback (index): ' . $e->getMessage());
    $cohortGroups = [
        'Active'     => $cohorts['active'] ?? [],
        'Functional' => $cohorts['functional'] ?? [],
        'Inactive'   => $cohorts['inactive'] ?? [],
    ];
    $individualCoachValues = $individual_coaches ?? [];
    $groupCoachValues = $group_coaches ?? [];
    if ($quarterEnabled) {
        $quarterGroups = ['Active' => ['Q1', 'Q2', 'Q3', 'Q4']];
    }
}

// Fetch all roster entries
$stmt = $conn->query("SELECT id, email, data FROM roster ORDER BY id");
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get last sync time
$lastSyncTime = null;
try {
    $metaStmt = $conn->query("SELECT meta_value FROM roster_meta WHERE meta_key = 'last_sync'");
    $metaRow = $metaStmt->fetch(PDO::FETCH_ASSOC);
    if ($metaRow && $metaRow['meta_value']) {
        $lastSyncTime = $metaRow['meta_value'];
    }
} catch (PDOException $e) {
    // Table might not exist yet, that's okay
}

// Function to calculate CARE profile
function getCareProfile($customFields) {
    $careScores = [
        'C' => null,
        'A' => null,
        'R' => null,
        'E' => null
    ];

    $careLabels = [
        'C' => 'Cooperator',
        'A' => 'Analyzer',
        'R' => 'Regulator',
        'E' => 'Energizer'
    ];

    // Extract CARE scores from custom fields by ID
    // Field ID 23 = C, ID 25 = A, ID 27 = R, ID 29 = E
    if (is_array($customFields)) {
        foreach ($customFields as $field) {
            if (isset($field['id']) && isset($field['content']) && $field['content'] !== '') {
                $fieldId = (int)$field['id'];
                $value = (int)$field['content'];

                switch ($fieldId) {
                    case 23: // C score
                        $careScores['C'] = $value;
                        break;
                    case 25: // A score
                        $careScores['A'] = $value;
                        break;
                    case 27: // R score
                        $careScores['R'] = $value;
                        break;
                    case 29: // E score
                        $careScores['E'] = $value;
                        break;
                }
            }
        }
    }

    // Check if all four scores are present
    if ($careScores['C'] === null || $careScores['A'] === null ||
        $careScores['R'] === null || $careScores['E'] === null) {
        return '';
    }

    // Group scores by value for proper sorting
    $grouped = [];
    foreach ($careScores as $letter => $score) {
        if (!isset($grouped[$score])) {
            $grouped[$score] = [];
        }
        $grouped[$score][] = $letter;
    }

    // Sort groups by score (descending)
    krsort($grouped);

    // Within each group, sort letters alphabetically
    $finalOrder = [];
    foreach ($grouped as $score => $letters) {
        sort($letters);
        $finalOrder = array_merge($finalOrder, $letters);
    }

    // Get top 2 for the label
    $top1 = isset($finalOrder[0]) ? $careLabels[$finalOrder[0]] : '';
    $top2 = isset($finalOrder[1]) ? $careLabels[$finalOrder[1]] : '';

    $label = '';
    if ($top1 && $top2) {
        $label = $top1 . ' - ' . $top2;
    } elseif ($top1) {
        $label = $top1;
    }

    // Create badges HTML
    $badges = '';
    foreach (['C', 'A', 'R', 'E'] as $letter) {
        $badges .= '<span class="care-badge">' . $letter . ': ' . $careScores[$letter] . '</span> ';
    }

    // Return both label and badges
    return [
        'label' => $label,
        'badges' => trim($badges)
    ];
}

// Function to extract custom field value by ID
function getCustomFieldById($customFields, $fieldId) {
    if (is_array($customFields)) {
        foreach ($customFields as $field) {
            if (isset($field['id']) && (int)$field['id'] === (int)$fieldId) {
                return isset($field['content']) ? $field['content'] : '';
            }
        }
    }
    return '';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roster View</title>
    <!-- Quill Editor CSS -->
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
    <?php roster_ui_styles(); ?>
    <style>
    /* ============================================================
       Roster page-specific styles (tokens/base/topbar live in includes/ui.php)
       ============================================================ */

    /* Header slots ------------------------------------------------ */
    .record-count {
        font-size: 12.5px;
        font-weight: 500;
        color: var(--ink-soft);
        background: var(--surface-sunk);
        padding: 5px 11px;
        border-radius: 999px;
        border: 1px solid var(--line);
    }

    .refresh-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        background: var(--surface);
        color: var(--ink-soft);
        border: 1px solid var(--line-strong);
        border-radius: var(--r-sm);
        font-family: inherit;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: background var(--dur) var(--ease), color var(--dur) var(--ease);
    }
    .refresh-btn:hover { background: var(--surface-sunk); color: var(--ink); }
    .refresh-btn:active { transform: translateY(0.5px); }
    .refresh-btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .refresh-btn.refreshing .refresh-icon,
    .refresh-btn.refreshing .refresh-text { display: none; }
    .refresh-btn.refreshing .refresh-spinner { display: inline !important; animation: spin 1s linear infinite; }
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

    .last-sync-time { font-size: 11.5px; color: var(--ink-faint); font-style: italic; }

    .search-box { position: relative; }
    .search-box input {
        padding: 8px 34px 8px 12px;
        width: 260px;
        max-width: 44vw;
        border: 1px solid var(--line-strong);
        border-radius: var(--r-sm);
        background: var(--surface);
        color: var(--ink);
        font-family: inherit;
        font-size: 14px;
        transition: border-color var(--dur) var(--ease), box-shadow var(--dur) var(--ease);
    }
    .search-box input::placeholder { color: var(--ink-faint); }
    .search-box input:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgb(from var(--focus) r g b / 0.20);
    }
    .search-icon { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); color: var(--ink-faint); font-size: 13px; pointer-events: none; }

    .reset-filters { margin-top: 6px; }
    .reset-filters a { font-size: 12px; color: var(--ink-faint); cursor: pointer; text-decoration: none; transition: color var(--dur) var(--ease); }
    .reset-filters a:hover { color: var(--accent-ink); text-decoration: underline; }

    /* Table ------------------------------------------------------- */
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }

    thead { background: var(--surface-sunk); }
    th {
        padding: 11px 16px;
        text-align: left;
        font-weight: 600;
        font-size: 11.5px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--ink-soft);
        border-bottom: 1px solid var(--line-strong);
        position: relative;
        white-space: nowrap;
    }
    th.sortable, th.filterable { cursor: pointer; user-select: none; transition: color var(--dur) var(--ease); }
    th.sortable:hover, th.filterable:hover { color: var(--accent-ink); }
    .sort-indicator { display: inline-block; margin-left: 5px; font-size: 9px; color: var(--ink-faint); }
    th.filter-active { color: var(--accent-ink); }
    th.filter-active::after {
        content: "";
        position: absolute; top: 12px; right: 8px;
        width: 6px; height: 6px; border-radius: 50%;
        background: var(--accent);
    }

    .filter-dropdown {
        position: absolute; top: 100%; left: 0;
        background: var(--surface-raise);
        border: 1px solid var(--line);
        border-radius: var(--r-md);
        box-shadow: var(--shadow-md);
        z-index: 1000;
        min-width: 210px; max-height: 320px; overflow-y: auto;
        display: none; padding: 4px;
    }
    .filter-dropdown.show { display: block; }
    .filter-actions { padding: 8px 10px; border-bottom: 1px solid var(--line); margin-bottom: 4px; }
    .filter-actions a { font-size: 12px; color: var(--accent-ink); cursor: pointer; text-decoration: none; margin-right: 12px; }
    .filter-actions a:hover { text-decoration: underline; }
    .filter-option {
        padding: 7px 10px; display: flex; align-items: center; gap: 9px; cursor: pointer;
        color: var(--ink); font-size: 13px; text-transform: none; font-weight: 400; letter-spacing: normal;
        border-radius: var(--r-sm);
    }
    .filter-option:hover { background: var(--surface-sunk); }
    .filter-option input[type="checkbox"] { cursor: pointer; accent-color: var(--accent); width: 15px; height: 15px; }
    .filter-option label { cursor: pointer; }

    td { padding: 11px 16px; border-bottom: 1px solid var(--line); font-size: 14px; color: var(--ink); vertical-align: top; }
    tbody tr { transition: background var(--dur) var(--ease); }
    tbody tr:nth-child(even) { background: var(--surface-sunk); }
    tbody tr:hover { background: var(--accent-weak); }
    tbody tr.row-selected { background: var(--accent-weak); box-shadow: inset 3px 0 0 var(--accent); }

    a { color: var(--accent-ink); text-decoration: none; transition: color var(--dur) var(--ease); }
    a:hover { color: var(--accent-hover); text-decoration: underline; }

    /* Contact / copy affordances --------------------------------- */
    .icon-wrapper { position: relative; display: inline-block; cursor: pointer; }
    .icon { font-size: 17px; color: var(--accent); transition: color var(--dur) var(--ease); }
    .icon:hover { color: var(--accent-hover); }

    .tooltip {
        visibility: hidden; position: absolute; bottom: 128%; left: 50%; transform: translateX(-50%);
        background: var(--ink); color: var(--surface); padding: 9px 12px; border-radius: var(--r-sm);
        font-size: 12px; white-space: nowrap; z-index: 1000; opacity: 0; transition: opacity var(--dur) var(--ease);
        box-shadow: var(--shadow-md);
    }
    .tooltip::after { content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 5px solid transparent; border-top-color: var(--ink); }
    .icon-wrapper:hover .tooltip { visibility: visible; opacity: 1; }
    .tooltip small { color: rgb(from var(--surface) r g b / 0.7); }

    .copied-message {
        position: fixed; top: 20px; right: 20px; background: var(--ok); color: oklch(0.99 0.003 85);
        padding: 12px 18px; border-radius: var(--r-sm); box-shadow: var(--shadow-md); z-index: 10000;
        opacity: 0; transition: opacity 0.3s var(--ease); font-weight: 500; font-size: 14px;
    }
    .copied-message.show { opacity: 1; }

    .no-data { padding: 56px 40px; text-align: center; color: var(--ink-faint); font-size: 15px; }

    /* CARE + coach badges ---------------------------------------- */
    .care-label { margin-bottom: 6px; font-weight: 500; color: var(--ink); }
    .care-badges { display: flex; gap: 5px; flex-wrap: wrap; }
    .care-badge {
        display: inline-block; padding: 3px 8px; background: var(--tag-neutral-bg); color: var(--tag-neutral-ink);
        border-radius: var(--r-sm); font-size: 12px; font-weight: 600; white-space: nowrap;
    }

    .coach-item { margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
    .coach-item:last-child { margin-bottom: 0; }
    .coach-badge {
        display: inline-block; padding: 2px 8px; border-radius: var(--r-sm);
        font-size: 11px; font-weight: 600; white-space: nowrap;
    }
    .coach-badge-individual { background: var(--tag-individual-bg); color: var(--tag-individual-ink); }
    .coach-badge-group { background: var(--tag-group-bg); color: var(--tag-group-ink); }

    .eteam-badge {
        display: inline-block; padding: 2px 9px; border-radius: 999px;
        background: var(--tag-eteam-bg); color: var(--tag-eteam-ink);
        font-size: 11.5px; font-weight: 600; white-space: nowrap;
    }

    .contact-name-link { color: var(--accent-ink); cursor: pointer; transition: color var(--dur) var(--ease); }
    .contact-name-link:hover { color: var(--accent-hover); text-decoration: underline; }

    .editable-name { cursor: pointer; padding: 3px 7px; margin: -3px -7px; border-radius: var(--r-sm); transition: background var(--dur) var(--ease); }
    .editable-name:hover { background: var(--surface-sunk); }
    .editable-name::after { content: ' ✏️'; font-size: 13px; opacity: 0; transition: opacity var(--dur) var(--ease); }
    .editable-name:hover::after { opacity: 1; }
    .name-saving { opacity: 0.6; pointer-events: none; }

    .checkbox-cell { width: 42px; text-align: center; }
    .payment-cell, .files-cell { width: 42px; text-align: center; }
    .payment-icon { cursor: pointer; }
    .payment-icon .icon { font-size: 16px; color: var(--ok); }
    .payment-icon:hover .icon { color: oklch(0.46 0.09 150); }
    .files-link { text-decoration: none; display: inline-block; }
    .files-link .icon { font-size: 16px; color: var(--accent); transition: transform var(--dur) var(--ease); }
    .files-link:hover .icon { color: var(--accent-hover); transform: scale(1.1); }
    .checkbox-cell input[type="checkbox"] { cursor: pointer; width: 16px; height: 16px; accent-color: var(--accent); }

    /* Bulk actions bar ------------------------------------------- */
    .bulk-actions-container {
        position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%);
        background: var(--surface-raise); padding: 12px 20px; border-radius: var(--r-md);
        border: 1px solid var(--line); box-shadow: var(--shadow-md);
        display: none; z-index: 2000; align-items: center; gap: 14px;
    }
    .bulk-actions-container.show { display: flex; }
    .bulk-actions-count { font-size: 14px; color: var(--ink); font-weight: 600; }
    .bulk-actions-dropdown { position: relative; }
    .bulk-actions-btn {
        padding: 8px 15px; background: var(--accent); color: oklch(0.99 0.003 85); border: none;
        border-radius: var(--r-sm); font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
        display: flex; align-items: center; gap: 8px; transition: background var(--dur) var(--ease);
    }
    .bulk-actions-btn:hover { background: var(--accent-hover); }
    .bulk-actions-menu {
        position: absolute; bottom: 100%; left: 0; margin-bottom: 8px;
        background: var(--surface-raise); border: 1px solid var(--line); border-radius: var(--r-md);
        box-shadow: var(--shadow-md); min-width: 190px; display: none; padding: 4px;
    }
    .bulk-actions-menu.show { display: block; }
    .bulk-action-item { padding: 9px 12px; cursor: pointer; font-size: 14px; color: var(--ink); border-radius: var(--r-sm); transition: background var(--dur) var(--ease); }
    .bulk-action-item:hover { background: var(--surface-sunk); }
    .bulk-actions-cancel { padding: 8px 12px; background: transparent; color: var(--ink-faint); border: none; font-family: inherit; font-size: 14px; cursor: pointer; transition: color var(--dur) var(--ease); }
    .bulk-actions-cancel:hover { color: var(--ink); }

    /* Modals ------------------------------------------------------ */
    .modal-overlay {
        position: fixed; inset: 0; background: rgb(from var(--ink) r g b / 0.45);
        display: none; align-items: center; justify-content: center; z-index: 3000;
    }
    .modal-overlay.show { display: flex; }
    .modal {
        background: var(--surface); border-radius: var(--r-md); padding: 28px;
        max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;
        box-shadow: var(--shadow-md); border: 1px solid var(--line);
    }
    .modal-header { margin-bottom: 18px; }
    .modal-header h2 { margin: 0; font-size: 20px; font-weight: 600; color: var(--ink); }
    .modal-body { margin-bottom: 20px; }

    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: var(--ink); }
    .form-group input, .form-group textarea, .form-group select {
        width: 100%; padding: 10px 12px; border: 1px solid var(--line-strong); border-radius: var(--r-sm);
        font-size: 14px; font-family: inherit; background: var(--surface); color: var(--ink);
        transition: border-color var(--dur) var(--ease), box-shadow var(--dur) var(--ease);
    }
    .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
        outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgb(from var(--focus) r g b / 0.20);
    }
    .form-group textarea { min-height: 150px; resize: vertical; }

    /* WYSIWYG editor */
    #emailEditorContainer { border: 1px solid var(--line-strong); border-radius: var(--r-sm); background: var(--surface); }
    #emailEditorContainer:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px rgb(from var(--focus) r g b / 0.20); }
    #emailEditorContainer .ql-toolbar { border: none; border-bottom: 1px solid var(--line); background: var(--surface-sunk); border-radius: var(--r-sm) var(--r-sm) 0 0; }
    #emailEditorContainer .ql-container { border: none; font-size: 14px; font-family: inherit; }
    #emailEditorContainer .ql-editor { min-height: 150px; padding: 12px; }
    #emailEditorContainer .ql-editor.ql-blank::before { font-style: normal; color: var(--ink-faint); }
    .ql-tooltip { z-index: 3001; }

    .modal-footer { display: flex; gap: 10px; justify-content: flex-end; }

    /* Local button aliases mapped onto the shared system */
    .btn-primary {
        padding: 10px 18px; background: var(--accent); color: oklch(0.99 0.003 85); border: 1px solid transparent;
        border-radius: var(--r-sm); font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
        transition: background var(--dur) var(--ease);
    }
    .btn-primary:hover { background: var(--accent-hover); }
    .btn-secondary {
        padding: 10px 18px; background: var(--surface); color: var(--ink); border: 1px solid var(--line-strong);
        border-radius: var(--r-sm); font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
        transition: background var(--dur) var(--ease);
    }
    .btn-secondary:hover { background: var(--surface-sunk); }

    /* Coach modal ------------------------------------------------- */
    .coach-action-toggle { display: flex; background: var(--surface-sunk); border-radius: var(--r-md); padding: 4px; gap: 4px; margin-bottom: 16px; }
    .coach-action-toggle label {
        flex: 1; text-align: center; padding: 9px 16px; border-radius: var(--r-sm); cursor: pointer;
        font-size: 13px; font-weight: 500; color: var(--ink-soft); transition: color var(--dur) var(--ease);
        display: flex; align-items: center; justify-content: center; gap: 6px;
    }
    .coach-action-toggle label:hover { color: var(--ink); }
    .coach-action-toggle input[type="radio"] { display: none; }
    .coach-action-toggle input[type="radio"]:checked + span {
        background: var(--surface); color: var(--ink); box-shadow: var(--shadow-sm);
    }
    .coach-action-toggle .toggle-option { display: block; padding: 9px 16px; border-radius: var(--r-sm); transition: background var(--dur) var(--ease), color var(--dur) var(--ease); }
    .coach-action-toggle .toggle-icon { font-size: 14px; }

    .coach-list-container { border: 1px solid var(--line); border-radius: var(--r-md); overflow: hidden; max-height: 200px; overflow-y: auto; }
    .coach-list-item {
        display: flex; align-items: center; padding: 11px 15px; cursor: pointer;
        transition: background var(--dur) var(--ease); border-bottom: 1px solid var(--line);
    }
    .coach-list-item:last-child { border-bottom: none; }
    .coach-list-item:nth-child(even) { background: var(--surface-sunk); }
    .coach-list-item:hover { background: var(--accent-weak); }
    .coach-list-item input[type="checkbox"] { width: 17px; height: 17px; margin: 0 12px 0 0; cursor: pointer; accent-color: var(--accent); }
    .coach-list-item .coach-name { font-size: 14px; color: var(--ink); font-weight: 500; }
    .coach-list-item.selected { background: var(--accent-weak); }
    .coach-list-item.selected .coach-name { color: var(--accent-ink); }

    /* Dropped-view toggle strip ---------------------------------- */
    .view-toggles { text-align: center; margin-top: 20px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }

    /* Responsive -------------------------------------------------- */
    @media (max-width: 720px) {
        .search-box input { width: 100%; max-width: none; }
        th, td { padding: 10px 12px; }
    }
    </style>
</head>
<body class="rui">
    <div class="rui-container">
        <?php
        ob_start(); ?>
            <span id="recordCount" class="record-count"></span>
            <button id="refreshRosterBtn" class="refresh-btn" title="Refresh roster data from Keap">
                <span class="refresh-icon">🔄</span>
                <span class="refresh-text">Refresh</span>
                <span class="refresh-spinner" style="display: none;">⏳</span>
            </button>
            <?php if ($lastSyncTime): ?>
            <span class="last-sync-time" title="Last synced from Keap">Last sync: <?php echo date('M j, g:i A', strtotime($lastSyncTime)); ?></span>
            <?php endif;
        $rosterCenterHtml = ob_get_clean();
        ob_start(); ?>
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search roster..." aria-label="Search roster">
                <span class="search-icon" aria-hidden="true">🔍</span>
            </div>
            <div class="reset-filters"><a id="resetAllFilters" tabindex="0" role="button">Clear search &amp; filters</a></div>
            <?php
        $rosterSearchHtml = ob_get_clean();
        roster_ui_topbar([
            'base'       => '',
            'active'     => 'roster',
            'page_title' => $viewDropped ? 'Dropped Contacts' : 'Roster',
            'state_chip' => $viewDropped ? 'Dropped view' : '',
            'center'     => $rosterCenterHtml,
            'search'     => $rosterSearchHtml,
            'user'       => $current_user,
            'is_admin'   => $user_is_admin,
            'can_edit'   => $user_can_edit,
        ]);
        ?>

        <?php if (!$user_can_edit): ?>
        <div class="rui-notice rui-notice--warn">
            You have view-only access. Contact an administrator if you need to make edits.
        </div>
        <?php endif; ?>

        <?php if (count($contacts) > 0): ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <?php if ($user_can_edit): ?>
                        <th class="checkbox-cell">
                            <input type="checkbox" id="selectAll">
                        </th>
                        <?php endif; ?>
                        <th class="sortable" data-column="firstName">
                            First Name
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-column="lastName">
                            Last Name
                            <span class="sort-indicator"></span>
                        </th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th class="sortable filterable" data-column="cohort">
                            Team
                            <span class="sort-indicator"></span>
                            <div class="filter-dropdown" id="cohortFilter"></div>
                        </th>
                        <?php if ($quarterEnabled): ?>
                        <th class="sortable filterable" data-column="quarter">
                            Quarter
                            <span class="sort-indicator"></span>
                            <div class="filter-dropdown" id="quarterFilter"></div>
                        </th>
                        <?php endif; ?>
                        <th class="sortable filterable" data-column="eteam">
                            E Team
                            <span class="sort-indicator"></span>
                            <div class="filter-dropdown" id="eteamFilter"></div>
                        </th>
                        <th>CARE Profile</th>
                        <th class="filterable" data-column="coaches">
                            Coach(es)
                            <div class="filter-dropdown" id="coachesFilter"></div>
                        </th>
                        <th>$</th>
                        <th>Files</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contacts as $contact):
                        $data = json_decode($contact['data'], true);
                        $firstName = $data['given_name'] ?? '';
                        $lastName = $data['family_name'] ?? '';
                        $email = $contact['email'];

                        // Get Keap contact ID
                        $keapId = $data['id'] ?? '';
                        $keapUrl = $keapId ? 'https://dja794.infusionsoft.com/Contact/manageContact.jsp?view=edit&ID=' . $keapId : '';

                        // Get phone number
                        $phone = '';
                        if (isset($data['phone_numbers']) && is_array($data['phone_numbers']) && count($data['phone_numbers']) > 0) {
                            $phone = $data['phone_numbers'][0]['number'] ?? '';
                        }

                        // Get custom fields
                        $customFields = $data['custom_fields'] ?? [];

                        // Get cohort (custom field ID 45)
                        $cohort = getCustomFieldById($customFields, 45);

                        // Get quarter (optional custom field; empty when not configured)
                        $quarter = $quarterEnabled ? getCustomFieldById($customFields, $quarter_field_id) : '';

                        // Get CARE profile (uses IDs 19, 21, 23, 25)
                        $careProfileData = getCareProfile($customFields);
                        $careLabel = is_array($careProfileData) ? $careProfileData['label'] : '';
                        $careBadges = is_array($careProfileData) ? $careProfileData['badges'] : '';

                        // Get coach(es) - use config field IDs, with fallback to old field
                        $coachIndividualRaw = getCustomFieldById($customFields, $individual_coach_field_id);
                        // Fallback to old field if new field is empty
                        if (empty($coachIndividualRaw) && isset($individual_coach_field_id_old)) {
                            $coachIndividualRaw = getCustomFieldById($customFields, $individual_coach_field_id_old);
                        }
                        $coachGroup = getCustomFieldById($customFields, $group_coach_field_id);

                        // Parse comma-separated individual coaches into array
                        $individualCoaches = [];
                        if ($coachIndividualRaw) {
                            $individualCoaches = array_map('trim', explode(',', $coachIndividualRaw));
                            $individualCoaches = array_filter($individualCoaches); // Remove empty values
                        }

                        // Get payment info (custom field ID 107 = PaymentOption, ID 109 = PaymentOptionFrequency)
                        $paymentOption = getCustomFieldById($customFields, 107);
                        $paymentFrequency = getCustomFieldById($customFields, 109);
                        $hasPaymentInfo = !empty($paymentOption) || !empty($paymentFrequency);

                        // Get coaching files link (custom field ID 135)
                        $coachingFilesLink = getCustomFieldById($customFields, 135);

                        // Get E Team role (custom field ID 73)
                        $eteamRole = getCustomFieldById($customFields, $eteam_role_field_id);
                        $isEteam = !empty($eteamRole) && $eteamRole !== '---';
                    ?>
                    <?php
                        $isDropped = ($cohort === '-dropped');
                        // Skip row if in normal view and contact is dropped, or if in dropped view and contact is not dropped
                        if ((!$viewDropped && $isDropped) || ($viewDropped && !$isDropped)) {
                            continue;
                        }
                    ?>
                    <tr data-firstname="<?php echo htmlspecialchars($firstName); ?>"
                        data-lastname="<?php echo htmlspecialchars($lastName); ?>"
                        data-cohort="<?php echo htmlspecialchars($cohort); ?>"
                        data-quarter="<?php echo htmlspecialchars($quarter); ?>"
                        data-coach-individual="<?php echo htmlspecialchars($coachIndividualRaw); ?>"
                        data-coach-group="<?php echo htmlspecialchars($coachGroup); ?>"
                        data-contact-id="<?php echo htmlspecialchars($data['id'] ?? ''); ?>"
                        data-email="<?php echo htmlspecialchars($email); ?>"
                        data-phone="<?php echo htmlspecialchars($phone); ?>"
                        data-keap-url="<?php echo htmlspecialchars($keapUrl); ?>"
                        data-payment-option="<?php echo htmlspecialchars($paymentOption); ?>"
                        data-payment-frequency="<?php echo htmlspecialchars($paymentFrequency); ?>"
                        data-coaching-files="<?php echo htmlspecialchars($coachingFilesLink); ?>"
                        data-eteam="<?php echo $isEteam ? htmlspecialchars($eteamRole) : ''; ?>"
                        data-search-text="<?php echo htmlspecialchars(strtolower($firstName . ' ' . $lastName . ' ' . $email . ' ' . $phone . ' ' . $cohort . ' ' . $careLabel . ' ' . $coachIndividualRaw . ' ' . $coachGroup . ($isEteam ? ' ' . $eteamRole . ' e team' : ''))); ?>">
                        <?php if ($user_can_edit): ?>
                        <td class="checkbox-cell">
                            <input type="checkbox" class="row-checkbox" value="<?php echo htmlspecialchars($data['id'] ?? ''); ?>">
                        </td>
                        <?php endif; ?>
                        <td>
                            <span class="contact-name-link"><?php echo htmlspecialchars($firstName); ?></span>
                        </td>
                        <td>
                            <span class="contact-name-link"><?php echo htmlspecialchars($lastName); ?></span>
                        </td>
                        <td>
                            <?php if ($email): ?>
                            <div class="icon-wrapper" onclick="copyToClipboard('<?php echo htmlspecialchars($email, ENT_QUOTES); ?>')">
                                <span class="icon">📧</span>
                                <div class="tooltip">
                                    <?php echo htmlspecialchars($email); ?><br>
                                    <small>Click to copy</small>
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($phone): ?>
                            <div class="icon-wrapper" onclick="copyToClipboard('<?php echo htmlspecialchars($phone, ENT_QUOTES); ?>')">
                                <span class="icon">📱</span>
                                <div class="tooltip">
                                    <?php echo htmlspecialchars($phone); ?><br>
                                    <small>Click to copy</small>
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($cohort); ?></td>
                        <?php if ($quarterEnabled): ?>
                        <td><?php echo htmlspecialchars($quarter); ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if ($isEteam): ?>
                                <span class="eteam-badge"><?php echo htmlspecialchars($eteamRole); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($careLabel): ?>
                                <div class="care-label"><?php echo htmlspecialchars($careLabel); ?></div>
                                <div class="care-badges"><?php echo $careBadges; ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php foreach ($individualCoaches as $coach): ?>
                                <div class="coach-item">
                                    <?php echo htmlspecialchars($coach); ?>
                                    <span class="coach-badge coach-badge-individual">Individual</span>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($coachGroup): ?>
                                <div class="coach-item">
                                    <?php echo htmlspecialchars($coachGroup); ?>
                                    <span class="coach-badge coach-badge-group">Group</span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="payment-cell">
                            <?php if ($hasPaymentInfo): ?>
                            <div class="icon-wrapper payment-icon" data-contact-name="<?php echo htmlspecialchars($firstName . ' ' . $lastName); ?>">
                                <span class="icon">💲</span>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="files-cell">
                            <?php if ($coachingFilesLink): ?>
                            <a href="<?php echo htmlspecialchars($coachingFilesLink); ?>" target="_blank" rel="noopener noreferrer" class="files-link" title="Open Coaching Files">
                                <span class="icon">📁</span>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="no-data">No contacts found in roster.</div>
        <?php endif; ?>
    </div>

    <!-- Dropped contacts toggle -->
    <div class="view-toggles">
        <?php if ($viewDropped): ?>
        <a href="./" class="rui-btn rui-btn--secondary">&larr; Back to Roster</a>
        <?php else: ?>
        <a href="./?dropped=1" class="rui-btn rui-btn--ghost">View Dropped</a>
        <a href="scheduled_drops.php" class="rui-btn rui-btn--ghost">Scheduled Drops</a>
        <?php endif; ?>
    </div>

    <div id="copiedMessage" class="copied-message">Copied to clipboard!</div>

    <?php if ($user_can_edit): ?>
    <!-- Bulk Actions Bar -->
    <div class="bulk-actions-container" id="bulkActionsContainer">
        <span class="bulk-actions-count" id="bulkActionsCount">0 selected</span>
        <div class="bulk-actions-dropdown">
            <button class="bulk-actions-btn" id="bulkActionsBtn">
                Bulk Actions ▼
            </button>
            <div class="bulk-actions-menu" id="bulkActionsMenu">
                <div class="bulk-action-item" id="bulkChangeCohortAction">Change Team</div>
                <div class="bulk-action-item" id="bulkChangeCoachAction">Change Coach</div>
                <div class="bulk-action-item" id="bulkEmailAction">Bulk Email Users</div>
            </div>
        </div>
        <button class="bulk-actions-cancel" id="bulkActionsCancel">Cancel</button>
    </div>

    <!-- Change Team Modal -->
    <div class="modal-overlay" id="changeCohortModal">
        <div class="modal">
            <div class="modal-header">
                <h2>Change Team</h2>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 15px; color: var(--ink-faint);">Select a new team for the <strong id="cohortChangeCount">0</strong> selected contact(s):</p>
                <div class="form-group">
                    <label for="cohortSelect">Team</label>
                    <div style="display: flex; gap: 8px; align-items: flex-start;">
                        <select id="cohortSelect" style="flex: 1; padding: 10px; border: 1px solid var(--line-strong); border-radius: 4px; font-size: 14px;">
                            <option value="">-- Select Team --</option>
                            <?php foreach ($cohortGroups as $groupLabel => $groupTeams): ?>
                            <optgroup label="<?php echo htmlspecialchars($groupLabel); ?>">
                                <?php foreach ($groupTeams as $cohort): ?>
                                    <?php if (fc_is_divider($cohort)): ?>
                                    <option disabled><?php echo htmlspecialchars(fc_divider_label()); ?></option>
                                    <?php else: ?>
                                    <option value="<?php echo htmlspecialchars($cohort); ?>"><?php echo htmlspecialchars($cohort); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="syncTeamsBtn" title="Sync team values from Keap" style="padding: 10px 14px; border: 1px solid var(--accent); background: white; color: var(--accent); border-radius: 4px; cursor: pointer; font-size: 14px; white-space: nowrap;" onmouseover="this.style.background='var(--accent)';this.style.color='white'" onmouseout="this.style.background='white';this.style.color='var(--accent)'">🔄 Sync</button>
                    </div>
                    <div id="syncTeamsResult" style="margin-top: 8px; font-size: 13px; display: none;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" id="cancelCohortModal">Cancel</button>
                <button class="btn-primary" id="saveCohortChange">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Drop Confirmation Modal -->
    <div class="modal-overlay" id="dropConfirmModal">
        <div class="modal" style="max-width: 520px;">
            <div class="modal-header">
                <h2 style="color: var(--danger);">Confirm Drop</h2>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 12px;">You are about to drop <strong id="dropConfirmCount">0</strong> contact(s).</p>

                <div style="background: var(--surface-sunk); border: 1px solid var(--line); border-radius: 6px; padding: 12px 14px; margin-bottom: 14px;">
                    <label style="font-weight: 600; font-size: 13px; color: var(--ink); display: block; margin-bottom: 8px;">When should this drop take effect?</label>
                    <label style="display: block; margin-bottom: 6px; font-size: 14px;">
                        <input type="radio" name="dropTiming" value="now" checked> Drop immediately
                    </label>
                    <label style="display: block; font-size: 14px;">
                        <input type="radio" name="dropTiming" value="scheduled"> Schedule for a future date
                    </label>
                    <input type="date" id="dropScheduledFor" min="" style="margin-top: 8px; padding: 6px 10px; border: 1px solid var(--line-strong); border-radius: 4px; display: none;">
                    <p id="dropScheduleHelp" style="font-size: 12px; color: var(--ink-faint); margin: 8px 0 0; display: none;">A daily processor will run the drop on the chosen date. The contact stays active until then.</p>
                </div>

                <p style="margin-bottom: 12px;">When the drop runs, it will:</p>
                <ul style="margin: 0 0 16px 20px; color: var(--ink-soft); font-size: 14px; line-height: 1.6;">
                    <li>Change their team to <code>-dropped</code></li>
                    <li>Apply the Dropped tag in Keap</li>
                    <li>Unassign their individual coach and group coach</li>
                    <li>Archive their previous team, coaches, payment info, and coaching files as a Keap Note</li>
                    <li>Email Sebastian, Maja, and Elizabeth (to stop auto payments and future manual triggers)</li>
                </ul>
                <p style="color: var(--danger); font-weight: 600; font-size: 14px;">Are you sure they are dropping?</p>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" id="cancelDropConfirm">Cancel</button>
                <button class="btn-primary" id="confirmDropBtn" style="background: var(--danger); border-color: var(--danger);">Yes, Drop Contact(s)</button>
            </div>
        </div>
    </div>

    <!-- Change Coach Modal -->
    <div class="modal-overlay" id="changeCoachModal">
        <div class="modal" style="max-width: 520px;">
            <div class="modal-header">
                <h2>Change Coach Assignments</h2>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 20px; color: var(--ink-soft); font-size: 14px;">Updating <strong style="color: var(--ink);" id="coachChangeCount">0</strong> selected contact(s)</p>

                <div class="form-group">
                    <label style="font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--ink-soft); margin-bottom: 10px; display: block;">Individual Coach(es)</label>

                    <div class="coach-action-toggle">
                        <label>
                            <input type="radio" name="individualCoachAction" value="no_change" checked>
                            <span class="toggle-option">Keep Current</span>
                        </label>
                        <label>
                            <input type="radio" name="individualCoachAction" value="replace">
                            <span class="toggle-option">Replace</span>
                        </label>
                        <label>
                            <input type="radio" name="individualCoachAction" value="clear">
                            <span class="toggle-option">Clear All</span>
                        </label>
                    </div>

                    <div id="individualCoachCheckboxes" class="coach-list-container">
                        <?php foreach ($individualCoachValues as $coach): ?>
                            <?php if (fc_is_divider($coach)): ?>
                            <div class="coach-list-divider" style="text-align:center; color:var(--line-strong); font-size:12px; letter-spacing:.1em; padding:4px 0;"><?php echo htmlspecialchars(fc_divider_label()); ?></div>
                            <?php else: ?>
                            <label class="coach-list-item">
                                <input type="checkbox" name="individualCoaches[]" value="<?php echo htmlspecialchars($coach); ?>">
                                <span class="coach-name"><?php echo htmlspecialchars($coach); ?></span>
                            </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <p style="font-size: 12px; color: var(--ink-faint); margin-top: 8px;">Select "Replace" then choose coaches to assign</p>
                </div>

                <div class="form-group" style="margin-top: 24px;">
                    <label style="font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--ink-soft); margin-bottom: 10px; display: block;">Group Coach</label>
                    <select id="groupCoachSelect" style="width: 100%; padding: 12px 14px; border: 1px solid var(--line); border-radius: 8px; font-size: 14px; color: var(--ink); background: white; cursor: pointer;">
                        <option value="">Keep current assignment</option>
                        <option value="__CLEAR__">Clear assignment</option>
                        <?php foreach ($groupCoachValues as $coach): ?>
                            <?php if (fc_is_divider($coach)): ?>
                            <option disabled><?php echo htmlspecialchars(fc_divider_label()); ?></option>
                            <?php else: ?>
                            <option value="<?php echo htmlspecialchars($coach); ?>"><?php echo htmlspecialchars($coach); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid var(--line); padding-top: 20px;">
                <button class="btn-secondary" id="cancelCoachModal">Cancel</button>
                <button class="btn-primary" id="saveCoachChange">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Bulk Email Modal -->
    <div class="modal-overlay" id="bulkEmailModal">
        <div class="modal">
            <div class="modal-header">
                <h2>Send Email via Keap</h2>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 15px; color: var(--ink-faint);">Send an email to <strong id="emailContactCount">0</strong> selected contact(s) through Keap.</p>
                <div style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label for="fromName">From Name</label>
                        <input type="text" id="fromName" placeholder="Your name" value="<?php echo htmlspecialchars($current_user['name'] ?? ''); ?>">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="fromEmailUser">From Email <span style="color: var(--danger);">*</span></label>
                        <div style="display: flex; align-items: center;">
                            <?php
                            $defaultEmailUser = '';
                            if (!empty($current_user['email']) && preg_match('/^([^@]+)@livewright\.com$/i', $current_user['email'], $matches)) {
                                $defaultEmailUser = $matches[1];
                            }
                            ?>
                            <input type="text" id="fromEmailUser" placeholder="username" required style="border-radius: 4px 0 0 4px; border-right: none; flex: 1;" value="<?php echo htmlspecialchars($defaultEmailUser); ?>">
                            <span style="background: var(--surface-sunk); border: 1px solid var(--line-strong); padding: 10px 12px; border-radius: 0 4px 4px 0; color: var(--ink-faint); font-size: 14px; white-space: nowrap;">@livewright.com</span>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="emailSubject">Subject <span style="color: var(--danger);">*</span></label>
                    <input type="text" id="emailSubject" placeholder="Email subject" required>
                </div>
                <div class="form-group">
                    <label for="emailMessage">Message <span style="color: var(--danger);">*</span></label>
                    <div id="emailEditorContainer">
                        <div id="emailEditor"></div>
                    </div>
                    <input type="hidden" id="emailMessage" required>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" id="cancelEmailModal">Cancel</button>
                <button class="btn-primary" id="sendBulkEmail">Send Email</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Payment Info Modal -->
    <div class="modal-overlay" id="paymentInfoModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h2>Payment Information</h2>
                <p style="margin-top: 5px; color: var(--ink-faint); font-size: 14px;" id="paymentContactName"></p>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Payment Option</label>
                    <div id="paymentOptionDisplay" style="padding: 10px; background: var(--surface-sunk); border-radius: 4px; min-height: 40px;"></div>
                </div>
                <div class="form-group">
                    <label>Payment Frequency</label>
                    <div id="paymentFrequencyDisplay" style="padding: 10px; background: var(--surface-sunk); border-radius: 4px; min-height: 40px;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" id="closePaymentModal">Close</button>
            </div>
        </div>
    </div>

    <!-- Contact Details Modal -->
    <div class="modal-overlay" id="contactDetailsModal">
        <div class="modal" style="max-width: 450px;">
            <div class="modal-header">
                <h2 id="contactDetailsName" class="editable-name" title="Click to edit"></h2>
                <div id="contactDetailsNameEdit" style="display: none;">
                    <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                        <input type="text" id="editFirstName" placeholder="First Name" style="flex: 1; padding: 8px; border: 1px solid var(--accent); border-radius: 4px; font-size: 16px;">
                        <input type="text" id="editLastName" placeholder="Last Name" style="flex: 1; padding: 8px; border: 1px solid var(--accent); border-radius: 4px; font-size: 16px;">
                    </div>
                    <small style="color: var(--ink-faint);">Press Enter to save, Escape to cancel</small>
                </div>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Email</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div id="contactDetailsEmail" style="flex: 1; padding: 10px; background: var(--surface-sunk); border-radius: 4px; min-height: 20px;"></div>
                        <button class="btn-secondary" id="copyContactEmail" style="padding: 8px 12px; white-space: nowrap;">Copy</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div id="contactDetailsPhone" style="flex: 1; padding: 10px; background: var(--surface-sunk); border-radius: 4px; min-height: 20px;"></div>
                        <button class="btn-secondary" id="copyContactPhone" style="padding: 8px 12px; white-space: nowrap;">Copy</button>
                    </div>
                </div>
                <div class="form-group" id="coachingFilesGroup">
                    <label>Coaching Files</label>
                    <div id="coachingFilesDisplay" style="display: flex; align-items: center; gap: 10px;">
                        <div id="contactCoachingFiles" style="flex: 1; padding: 10px; background: var(--surface-sunk); border-radius: 4px; min-height: 20px; word-break: break-all;"></div>
                        <a id="openCoachingFiles" href="#" target="_blank" rel="noopener noreferrer" class="btn-secondary" style="padding: 8px 12px; white-space: nowrap; text-decoration: none; display: none;">Open</a>
                        <?php if ($user_can_edit): ?>
                        <button class="btn-secondary" id="editCoachingFilesBtn" style="padding: 8px 12px; white-space: nowrap;">Edit</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($user_can_edit): ?>
                    <div id="coachingFilesEdit" style="display: none; margin-top: 10px;">
                        <input type="text" id="coachingFilesInput" placeholder="https://drive.google.com/..." style="width: 100%; padding: 10px; border: 1px solid var(--accent); border-radius: 4px; font-size: 14px;">
                        <div style="margin-top: 8px; display: flex; gap: 8px;">
                            <button class="btn-primary" id="saveCoachingFiles" style="padding: 6px 12px; font-size: 13px;">Save</button>
                            <button class="btn-secondary" id="cancelCoachingFiles" style="padding: 6px 12px; font-size: 13px;">Cancel</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: space-between;">
                <a id="contactDetailsKeapLink" href="#" target="_blank" rel="noopener noreferrer" style="padding: 10px 20px; background: var(--accent); color: white; border-radius: 4px; font-size: 14px; font-weight: 600; text-decoration: none;">View in Keap</a>
                <button class="btn-secondary" id="closeContactDetailsModal">Close</button>
            </div>
        </div>
    </div>

    <script>
        // User permission flag from PHP
        const userCanEdit = <?php echo $user_can_edit ? 'true' : 'false'; ?>;

        // Clipboard functionality
        function copyToClipboard(text) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    showCopiedMessage();
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    fallbackCopy(text);
                });
            } else {
                fallbackCopy(text);
            }
        }

        function fallbackCopy(text) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                document.execCommand('copy');
                showCopiedMessage();
            } catch (err) {
                console.error('Failed to copy:', err);
            }

            document.body.removeChild(textArea);
        }

        function showCopiedMessage() {
            const message = document.getElementById('copiedMessage');
            message.classList.add('show');

            setTimeout(() => {
                message.classList.remove('show');
            }, 2000);
        }

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const tableRows = document.querySelectorAll('tbody tr');
        const recordCountEl = document.getElementById('recordCount');
        const totalRecords = tableRows.length;

        // Update record count display
        function updateRecordCount() {
            const visibleRows = Array.from(tableRows).filter(row => row.style.display !== 'none').length;
            if (visibleRows === totalRecords) {
                recordCountEl.textContent = `Showing all ${totalRecords} records`;
            } else {
                recordCountEl.textContent = `Showing ${visibleRows} of ${totalRecords} records`;
            }
        }

        // Initialize record count
        updateRecordCount();

        searchInput.addEventListener('input', function() {
            applyCoachesFilter(); // Use the combined filter function
            updateRecordCount();
        });

        // Sorting functionality
        let sortDirection = {};
        const sortableHeaders = document.querySelectorAll('th.sortable');

        sortableHeaders.forEach(header => {
            const column = header.getAttribute('data-column');
            sortDirection[column] = 'asc';

            header.addEventListener('click', function() {
                const column = this.getAttribute('data-column');
                const indicator = this.querySelector('.sort-indicator');

                // Toggle direction
                sortDirection[column] = sortDirection[column] === 'asc' ? 'desc' : 'asc';

                // Update indicators
                document.querySelectorAll('.sort-indicator').forEach(ind => ind.textContent = '');
                indicator.textContent = sortDirection[column] === 'asc' ? '▲' : '▼';

                // Sort rows
                const tbody = document.querySelector('tbody');
                const rows = Array.from(tableRows);

                rows.sort((a, b) => {
                    const aValue = a.getAttribute('data-' + column).toLowerCase();
                    const bValue = b.getAttribute('data-' + column).toLowerCase();

                    if (sortDirection[column] === 'asc') {
                        return aValue.localeCompare(bValue);
                    } else {
                        return bValue.localeCompare(aValue);
                    }
                });

                // Re-append rows in new order
                rows.forEach(row => tbody.appendChild(row));
            });
        });

        // Cohort filter functionality
        const cohortHeader = document.querySelector('th.filterable[data-column="cohort"]');
        const cohortFilterDropdown = document.getElementById('cohortFilter');
        let selectedCohorts = new Set();
        // Quarter filter state (stays empty -> no effect when the column is disabled).
        let selectedQuarters = new Set();

        // Build filter options
        function buildCohortFilter() {
            const cohorts = new Set();
            tableRows.forEach(row => {
                const cohort = row.getAttribute('data-cohort');
                if (cohort) {
                    cohorts.add(cohort);
                }
            });

            cohortFilterDropdown.innerHTML = '';

            // Add uncheck all action
            const actions = document.createElement('div');
            actions.className = 'filter-actions';
            actions.innerHTML = '<a id="cohortUncheckAll">Uncheck all</a>';
            cohortFilterDropdown.appendChild(actions);

            const uncheckAllLink = actions.querySelector('#cohortUncheckAll');
            uncheckAllLink.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                // Uncheck all checkboxes
                cohortFilterDropdown.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    cb.checked = false;
                });
                selectedCohorts.clear();
                applyCohortFilter();
            });

            const sortedCohorts = Array.from(cohorts).sort();
            sortedCohorts.forEach(cohort => {
                const option = document.createElement('div');
                option.className = 'filter-option';
                option.innerHTML = `
                    <input type="checkbox" id="cohort-${cohort}" value="${cohort}" checked>
                    <label for="cohort-${cohort}">${cohort}</label>
                `;

                const checkbox = option.querySelector('input');
                checkbox.addEventListener('change', function() {
                    if (this.checked) {
                        selectedCohorts.add(cohort);
                    } else {
                        selectedCohorts.delete(cohort);
                    }
                    applyCohortFilter();
                });

                cohortFilterDropdown.appendChild(option);
                selectedCohorts.add(cohort);
            });
        }

        function applyCohortFilter() {
            tableRows.forEach(row => {
                const cohort = row.getAttribute('data-cohort');
                const searchTerm = searchInput.value.toLowerCase();
                const searchText = row.getAttribute('data-search-text');
                const matchesSearch = searchText.includes(searchTerm);

                // If no cohorts are selected, show all (revert to showing everything)
                if (selectedCohorts.size === 0) {
                    if (matchesSearch) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                } else if (cohort === '' || selectedCohorts.has(cohort)) {
                    if (matchesSearch) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                } else {
                    row.style.display = 'none';
                }
            });
        }

        cohortHeader.addEventListener('click', function(e) {
            e.stopPropagation();
            cohortFilterDropdown.classList.toggle('show');
        });

        // Close filter dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!cohortHeader.contains(e.target)) {
                // Check if all are unchecked before closing
                if (selectedCohorts.size === 0) {
                    // Revert to all checked
                    cohortFilterDropdown.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                        cb.checked = true;
                        const cohort = cb.value;
                        selectedCohorts.add(cohort);
                    });
                    applyCohortFilter();
                }
                cohortFilterDropdown.classList.remove('show');
            }
        });

        // Initialize cohort filter
        buildCohortFilter();

        // Quarter filter functionality (only when the Quarter column is present)
        const quarterHeader = document.querySelector('th.filterable[data-column="quarter"]');
        const quarterFilterDropdown = document.getElementById('quarterFilter');
        if (quarterHeader && quarterFilterDropdown) {
            function buildQuarterFilter() {
                const quarters = new Set();
                tableRows.forEach(row => {
                    const q = row.getAttribute('data-quarter');
                    if (q) quarters.add(q);
                });

                quarterFilterDropdown.innerHTML = '';
                const actions = document.createElement('div');
                actions.className = 'filter-actions';
                actions.innerHTML = '<a id="quarterUncheckAll">Uncheck all</a>';
                quarterFilterDropdown.appendChild(actions);
                actions.querySelector('#quarterUncheckAll').addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    quarterFilterDropdown.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
                    selectedQuarters.clear();
                    applyCoachesFilter();
                });

                Array.from(quarters).sort().forEach(q => {
                    const option = document.createElement('div');
                    option.className = 'filter-option';
                    option.innerHTML = `
                        <input type="checkbox" id="quarter-${q}" value="${q}" checked>
                        <label for="quarter-${q}">${q}</label>
                    `;
                    option.querySelector('input').addEventListener('change', function() {
                        if (this.checked) { selectedQuarters.add(q); } else { selectedQuarters.delete(q); }
                        applyCoachesFilter();
                    });
                    quarterFilterDropdown.appendChild(option);
                    selectedQuarters.add(q);
                });
            }

            quarterHeader.addEventListener('click', function(e) {
                e.stopPropagation();
                quarterFilterDropdown.classList.toggle('show');
            });
            document.addEventListener('click', function(e) {
                if (!quarterHeader.contains(e.target)) {
                    if (selectedQuarters.size === 0) {
                        quarterFilterDropdown.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                            cb.checked = true;
                            selectedQuarters.add(cb.value);
                        });
                        applyCoachesFilter();
                    }
                    quarterFilterDropdown.classList.remove('show');
                }
            });

            buildQuarterFilter();
        }

        // E Team filter functionality
        const eteamHeader = document.querySelector('th.filterable[data-column="eteam"]');
        const eteamFilterDropdown = document.getElementById('eteamFilter');
        let selectedEteam = new Set();

        function buildEteamFilter() {
            const roles = new Set();
            let hasNonEteam = false;
            tableRows.forEach(row => {
                const role = row.getAttribute('data-eteam');
                if (role) {
                    roles.add(role);
                } else {
                    hasNonEteam = true;
                }
            });

            eteamFilterDropdown.innerHTML = '';

            const actions = document.createElement('div');
            actions.className = 'filter-actions';
            actions.innerHTML = '<a id="eteamUncheckAll">Uncheck all</a>';
            eteamFilterDropdown.appendChild(actions);

            actions.querySelector('#eteamUncheckAll').addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                eteamFilterDropdown.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; });
                selectedEteam.clear();
                applyEteamFilter();
            });

            // Add "(Not on E Team)" option
            if (hasNonEteam) {
                const option = document.createElement('div');
                option.className = 'filter-option';
                option.innerHTML = '<input type="checkbox" id="eteam-none" value="" checked><label for="eteam-none" style="color:var(--ink-faint);">(Not on E Team)</label>';
                option.querySelector('input').addEventListener('change', function() {
                    if (this.checked) { selectedEteam.add(''); } else { selectedEteam.delete(''); }
                    applyEteamFilter();
                });
                eteamFilterDropdown.appendChild(option);
                selectedEteam.add('');
            }

            Array.from(roles).sort().forEach(role => {
                const option = document.createElement('div');
                option.className = 'filter-option';
                option.innerHTML = `<input type="checkbox" id="eteam-${role}" value="${role}" checked><label for="eteam-${role}">${role}</label>`;
                option.querySelector('input').addEventListener('change', function() {
                    if (this.checked) { selectedEteam.add(role); } else { selectedEteam.delete(role); }
                    applyEteamFilter();
                });
                eteamFilterDropdown.appendChild(option);
                selectedEteam.add(role);
            });
        }

        function applyEteamFilter() {
            tableRows.forEach(row => {
                const role = row.getAttribute('data-eteam') || '';
                if (selectedEteam.size === 0 || selectedEteam.has(role)) {
                    if (row.style.display === 'none' && !selectedCohorts.has(row.getAttribute('data-cohort'))) return;
                    // Don't override other filters hiding this row
                } else {
                    row.style.display = 'none';
                }
            });
        }

        if (eteamHeader) {
            eteamHeader.addEventListener('click', function(e) {
                e.stopPropagation();
                eteamFilterDropdown.classList.toggle('show');
            });
        }

        document.addEventListener('click', function(e) {
            if (eteamHeader && !eteamHeader.contains(e.target)) {
                if (selectedEteam.size === 0) {
                    eteamFilterDropdown.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                        cb.checked = true;
                        selectedEteam.add(cb.value);
                    });
                    applyEteamFilter();
                }
                eteamFilterDropdown.classList.remove('show');
            }
        });

        buildEteamFilter();

        // Coaches filter functionality
        const coachesHeader = document.querySelector('th.filterable[data-column="coaches"]');
        const coachesFilterDropdown = document.getElementById('coachesFilter');
        let selectedCoaches = new Set();

        // Build coaches filter options
        function buildCoachesFilter() {
            const coaches = new Map(); // Map to store coach name -> type(s)

            tableRows.forEach(row => {
                const coachIndividualRaw = row.getAttribute('data-coach-individual');
                const coachGroup = row.getAttribute('data-coach-group');

                // Handle comma-separated individual coaches
                if (coachIndividualRaw) {
                    const individualCoaches = coachIndividualRaw.split(',').map(c => c.trim()).filter(c => c);
                    individualCoaches.forEach(coachName => {
                        const key = coachName + '|Individual';
                        if (!coaches.has(key)) {
                            coaches.set(key, { name: coachName, type: 'Individual' });
                        }
                    });
                }

                if (coachGroup) {
                    const key = coachGroup + '|Group';
                    if (!coaches.has(key)) {
                        coaches.set(key, { name: coachGroup, type: 'Group' });
                    }
                }
            });

            coachesFilterDropdown.innerHTML = '';

            // Add uncheck all action
            const actions = document.createElement('div');
            actions.className = 'filter-actions';
            actions.innerHTML = '<a id="coachesUncheckAll">Uncheck all</a>';
            coachesFilterDropdown.appendChild(actions);

            const uncheckAllLink = actions.querySelector('#coachesUncheckAll');
            uncheckAllLink.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                // Uncheck all checkboxes
                coachesFilterDropdown.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    cb.checked = false;
                });
                selectedCoaches.clear();
                applyCoachesFilter();
            });

            // Sort coaches alphabetically by name, then by type
            const sortedCoaches = Array.from(coaches.entries()).sort((a, b) => {
                const nameCompare = a[1].name.localeCompare(b[1].name);
                if (nameCompare !== 0) return nameCompare;
                return a[1].type.localeCompare(b[1].type);
            });

            sortedCoaches.forEach(([key, coach]) => {
                const option = document.createElement('div');
                option.className = 'filter-option';
                const badgeClass = coach.type === 'Individual' ? 'coach-badge-individual' : 'coach-badge-group';
                option.innerHTML = `
                    <input type="checkbox" id="coach-${key}" value="${key}" checked>
                    <label for="coach-${key}">
                        ${coach.name} <span class="coach-badge ${badgeClass}" style="font-size: 10px; padding: 2px 6px;">${coach.type}</span>
                    </label>
                `;

                const checkbox = option.querySelector('input');
                checkbox.addEventListener('change', function() {
                    if (this.checked) {
                        selectedCoaches.add(key);
                    } else {
                        selectedCoaches.delete(key);
                    }
                    applyCoachesFilter();
                });

                coachesFilterDropdown.appendChild(option);
                selectedCoaches.add(key);
            });
        }

        function applyCoachesFilter() {
            tableRows.forEach(row => {
                const coachIndividualRaw = row.getAttribute('data-coach-individual');
                const coachGroup = row.getAttribute('data-coach-group');
                const searchTerm = searchInput.value.toLowerCase();
                const searchText = row.getAttribute('data-search-text');
                const matchesSearch = searchText.includes(searchTerm);

                // Check if row matches cohort filter
                const cohort = row.getAttribute('data-cohort');
                const matchesCohort = selectedCohorts.size === 0 || selectedCohorts.has(cohort);

                // Check if row matches quarter filter (no-op when nothing selected)
                const quarter = row.getAttribute('data-quarter') || '';
                const matchesQuarter = selectedQuarters.size === 0 || selectedQuarters.has(quarter);

                // Parse comma-separated individual coaches
                const individualCoaches = coachIndividualRaw ? coachIndividualRaw.split(',').map(c => c.trim()).filter(c => c) : [];

                // If no coaches are selected, show all (revert to showing everything)
                let matchesCoach = false;
                if (selectedCoaches.size === 0) {
                    matchesCoach = true;
                } else {
                    // Check if any of the row's individual coaches are in the selected set
                    for (const coach of individualCoaches) {
                        if (selectedCoaches.has(coach + '|Individual')) {
                            matchesCoach = true;
                            break;
                        }
                    }
                    // Check group coach
                    if (coachGroup && selectedCoaches.has(coachGroup + '|Group')) {
                        matchesCoach = true;
                    }
                    // Show rows with no coaches if selected coaches is not empty
                    if (individualCoaches.length === 0 && !coachGroup) {
                        matchesCoach = true;
                    }
                }

                // Show row only if it matches all filters
                if (matchesSearch && matchesCohort && matchesCoach && matchesQuarter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
            updateRecordCount();
        }

        // Update applyCohortFilter to also check coaches filter
        function applyCohortFilter() {
            applyCoachesFilter(); // This now handles all filters together
        }

        coachesHeader.addEventListener('click', function(e) {
            e.stopPropagation();
            coachesFilterDropdown.classList.toggle('show');
        });

        // Close coaches filter dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!coachesHeader.contains(e.target)) {
                // Check if all are unchecked before closing
                if (selectedCoaches.size === 0) {
                    // Revert to all checked
                    coachesFilterDropdown.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                        cb.checked = true;
                        const key = cb.value;
                        selectedCoaches.add(key);
                    });
                    applyCoachesFilter();
                }
                coachesFilterDropdown.classList.remove('show');
            }
        });

        // Initialize coaches filter
        buildCoachesFilter();

        // Reset all filters and search
        const resetAllFiltersBtn = document.getElementById('resetAllFilters');
        resetAllFiltersBtn.addEventListener('click', function(e) {
            e.preventDefault();

            // Clear search input
            searchInput.value = '';

            // Reset cohort filter - check all
            cohortFilterDropdown.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                cb.checked = true;
                const cohort = cb.value;
                selectedCohorts.add(cohort);
            });

            // Reset coaches filter - check all
            coachesFilterDropdown.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                cb.checked = true;
                const key = cb.value;
                selectedCoaches.add(key);
            });

            // Reset quarter filter - check all (if present)
            if (quarterFilterDropdown) {
                quarterFilterDropdown.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    cb.checked = true;
                    selectedQuarters.add(cb.value);
                });
            }

            // Clear sort indicators
            document.querySelectorAll('.sort-indicator').forEach(ind => ind.textContent = '');

            // Reset sort directions
            for (let key in sortDirection) {
                sortDirection[key] = 'asc';
            }

            // Show all rows
            tableRows.forEach(row => {
                row.style.display = '';
            });

            updateRecordCount();
        });

        // Bulk actions functionality (only for editors)
        if (userCanEdit) {
        const selectAllCheckbox = document.getElementById('selectAll');
        const rowCheckboxes = document.querySelectorAll('.row-checkbox');
        const bulkActionsContainer = document.getElementById('bulkActionsContainer');
        const bulkActionsCount = document.getElementById('bulkActionsCount');
        const bulkActionsBtn = document.getElementById('bulkActionsBtn');
        const bulkActionsMenu = document.getElementById('bulkActionsMenu');
        const bulkActionsCancel = document.getElementById('bulkActionsCancel');
        const bulkEmailAction = document.getElementById('bulkEmailAction');
        const bulkEmailModal = document.getElementById('bulkEmailModal');
        const cancelEmailModal = document.getElementById('cancelEmailModal');
        const sendBulkEmail = document.getElementById('sendBulkEmail');

        let selectedContacts = new Set();

        // Presentational only: mirror a checkbox's state onto its row so the
        // selection tint (see .row-selected) never relies on color alone.
        function syncRowTint(cb) {
            const r = cb.closest('tr');
            if (r) r.classList.toggle('row-selected', cb.checked);
        }

        // Update bulk actions bar
        function updateBulkActions() {
            const count = selectedContacts.size;
            if (count > 0) {
                bulkActionsCount.textContent = `${count} selected`;
                bulkActionsContainer.classList.add('show');
            } else {
                bulkActionsContainer.classList.remove('show');
                bulkActionsMenu.classList.remove('show');
            }
        }

        // Select all checkbox
        selectAllCheckbox.addEventListener('change', function() {
            const visibleCheckboxes = Array.from(rowCheckboxes).filter(cb => {
                const row = cb.closest('tr');
                return row.style.display !== 'none';
            });

            visibleCheckboxes.forEach(cb => {
                cb.checked = this.checked;
                syncRowTint(cb);
                const contactId = cb.value;
                if (this.checked) {
                    selectedContacts.add(contactId);
                } else {
                    selectedContacts.delete(contactId);
                }
            });

            updateBulkActions();
        });

        // Individual row checkboxes
        rowCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const contactId = this.value;
                syncRowTint(this);
                if (this.checked) {
                    selectedContacts.add(contactId);
                } else {
                    selectedContacts.delete(contactId);
                }

                // Update select all checkbox state
                const visibleCheckboxes = Array.from(rowCheckboxes).filter(cb => {
                    const row = cb.closest('tr');
                    return row.style.display !== 'none';
                });
                const allChecked = visibleCheckboxes.every(cb => cb.checked);
                selectAllCheckbox.checked = allChecked && visibleCheckboxes.length > 0;

                updateBulkActions();
            });
        });

        // Bulk actions dropdown toggle
        bulkActionsBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            bulkActionsMenu.classList.toggle('show');
        });

        // Close bulk actions menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!bulkActionsBtn.contains(e.target) && !bulkActionsMenu.contains(e.target)) {
                bulkActionsMenu.classList.remove('show');
            }
        });

        // Cancel bulk actions
        bulkActionsCancel.addEventListener('click', function() {
            // Uncheck all checkboxes
            rowCheckboxes.forEach(cb => { cb.checked = false; syncRowTint(cb); });
            selectAllCheckbox.checked = false;
            selectedContacts.clear();
            updateBulkActions();
        });

        // Bulk email action
        const emailContactCount = document.getElementById('emailContactCount');
        const fromNameInput = document.getElementById('fromName');
        const fromEmailUserInput = document.getElementById('fromEmailUser');

        bulkEmailAction.addEventListener('click', function() {
            bulkActionsMenu.classList.remove('show');
            emailContactCount.textContent = selectedContacts.size;
            // Don't clear from fields - user likely wants to reuse them
            document.getElementById('emailSubject').value = '';
            document.getElementById('emailMessage').value = '';
            // Clear Quill editor if initialized
            if (typeof emailQuill !== 'undefined' && emailQuill) {
                emailQuill.setContents([]);
            }
            bulkEmailModal.classList.add('show');
        });

        // Cancel email modal
        cancelEmailModal.addEventListener('click', function() {
            bulkEmailModal.classList.remove('show');
        });

        // Close modal when clicking overlay
        bulkEmailModal.addEventListener('click', function(e) {
            if (e.target === bulkEmailModal) {
                bulkEmailModal.classList.remove('show');
            }
        });

        // Send bulk email via Keap XML-RPC API
        sendBulkEmail.addEventListener('click', async function() {
            const fromName = fromNameInput.value.trim();
            const fromEmailUser = fromEmailUserInput.value.trim().toLowerCase();
            const subject = document.getElementById('emailSubject').value.trim();

            // Get content from Quill editor
            let messageHtml = '';
            let messageText = '';
            if (typeof emailQuill !== 'undefined' && emailQuill) {
                messageHtml = emailQuill.root.innerHTML;
                messageText = emailQuill.getText().trim();
                // Check for empty editor
                if (messageHtml === '<p><br></p>') {
                    messageHtml = '';
                }
            } else {
                // Fallback if Quill not loaded
                messageHtml = document.getElementById('emailMessage').value.trim();
                messageText = messageHtml;
            }
            const message = messageText; // For validation

            // Validate inputs
            if (!fromEmailUser) {
                alert('Please enter a from email username.');
                fromEmailUserInput.focus();
                return;
            }

            // Validate username (alphanumeric, dots, hyphens, underscores only)
            if (!/^[a-z0-9._-]+$/.test(fromEmailUser)) {
                alert('Email username can only contain letters, numbers, dots, hyphens, and underscores.');
                fromEmailUserInput.focus();
                return;
            }

            // Construct full email
            const fromEmail = fromEmailUser + '@livewright.com';

            if (!subject) {
                alert('Please enter an email subject.');
                return;
            }

            if (!message) {
                alert('Please enter an email message.');
                return;
            }

            // Get selected contact IDs
            const contactIds = Array.from(selectedContacts);

            if (contactIds.length === 0) {
                alert('No contacts selected.');
                return;
            }

            if (contactIds.length > 1000) {
                alert('Maximum 1000 contacts per email send. Please select fewer contacts.');
                return;
            }

            // Confirm before sending
            const fromDisplay = fromName ? `${fromName} <${fromEmail}>` : fromEmail;
            if (!confirm(`Are you sure you want to send this email?\n\nFrom: ${fromDisplay}\nTo: ${contactIds.length} contact(s)\nSubject: ${subject}`)) {
                return;
            }

            // Disable button and show loading state
            sendBulkEmail.disabled = true;
            sendBulkEmail.textContent = 'Sending...';

            try {
                const response = await fetch('send_bulk_email.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        contact_ids: contactIds,
                        from_name: fromName,
                        from_email: fromEmail,
                        subject: subject,
                        message: messageHtml,
                        message_text: messageText
                    })
                });

                const result = await response.json();

                if (result.success) {
                    alert(`Email sent successfully!\n\n${result.contact_count} contact(s) will receive the email.`);

                    // Close modal and clear selection
                    bulkEmailModal.classList.remove('show');
                    document.getElementById('emailSubject').value = '';
                    document.getElementById('emailMessage').value = '';
                    // Clear Quill editor
                    if (typeof emailQuill !== 'undefined' && emailQuill) {
                        emailQuill.setContents([]);
                    }

                    // Clear checkboxes
                    rowCheckboxes.forEach(cb => { cb.checked = false; syncRowTint(cb); });
                    selectAllCheckbox.checked = false;
                    selectedContacts.clear();
                    updateBulkActions();
                } else {
                    alert('Error sending email: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error sending bulk email:', error);
                alert('An error occurred while sending the email. Please try again.');
            } finally {
                // Re-enable button
                sendBulkEmail.disabled = false;
                sendBulkEmail.textContent = 'Send Email';
            }
        });

        // Change Cohort functionality
        const bulkChangeCohortAction = document.getElementById('bulkChangeCohortAction');
        const changeCohortModal = document.getElementById('changeCohortModal');
        const cancelCohortModal = document.getElementById('cancelCohortModal');
        const saveCohortChange = document.getElementById('saveCohortChange');
        const cohortSelect = document.getElementById('cohortSelect');
        const cohortChangeCount = document.getElementById('cohortChangeCount');

        // Open Change Cohort modal
        bulkChangeCohortAction.addEventListener('click', function() {
            bulkActionsMenu.classList.remove('show');
            cohortChangeCount.textContent = selectedContacts.size;
            cohortSelect.value = '';
            changeCohortModal.classList.add('show');
        });

        // Cancel cohort modal
        cancelCohortModal.addEventListener('click', function() {
            changeCohortModal.classList.remove('show');
        });

        // Close modal when clicking overlay
        changeCohortModal.addEventListener('click', function(e) {
            if (e.target === changeCohortModal) {
                changeCohortModal.classList.remove('show');
            }
        });

        // Sync Teams from Keap
        document.getElementById('syncTeamsBtn').addEventListener('click', async function() {
            const btn = this;
            const resultDiv = document.getElementById('syncTeamsResult');
            btn.disabled = true;
            btn.textContent = '⏳ Syncing...';
            resultDiv.style.display = 'none';

            try {
                const response = await fetch('sync_teams.php');
                const data = await response.json();

                if (!data.success) {
                    resultDiv.innerHTML = '<span style="color:var(--danger);">Error: ' + (data.error || 'Unknown error') + '</span>';
                    resultDiv.style.display = 'block';
                    return;
                }

                // Add any new teams from Keap to the dropdown
                const select = document.getElementById('cohortSelect');
                const existingValues = new Set();
                select.querySelectorAll('option').forEach(opt => existingValues.add(opt.value));

                let added = 0;
                if (data.new_teams.length > 0) {
                    // Find or create "From Keap" optgroup
                    let keapGroup = select.querySelector('optgroup[label="From Keap"]');
                    if (!keapGroup) {
                        keapGroup = document.createElement('optgroup');
                        keapGroup.label = 'From Keap';
                        select.appendChild(keapGroup);
                    }

                    data.new_teams.forEach(team => {
                        if (!existingValues.has(team)) {
                            const opt = document.createElement('option');
                            opt.value = team;
                            opt.textContent = team;
                            keapGroup.appendChild(opt);
                            added++;
                        }
                    });
                }

                if (added > 0) {
                    resultDiv.innerHTML = '<span style="color:var(--ok);">Added ' + added + ' new team(s): ' + data.new_teams.join(', ') + '</span>';
                } else {
                    resultDiv.innerHTML = '<span style="color:var(--ink-soft);">All teams already in sync (' + data.keap_teams.length + ' found in Keap)</span>';
                }
                resultDiv.style.display = 'block';

            } catch (error) {
                resultDiv.innerHTML = '<span style="color:var(--danger);">Failed to sync teams</span>';
                resultDiv.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.textContent = '🔄 Sync';
            }
        });

        // Drop confirmation modal elements
        const dropConfirmModal = document.getElementById('dropConfirmModal');
        const dropConfirmCount = document.getElementById('dropConfirmCount');
        const cancelDropConfirm = document.getElementById('cancelDropConfirm');
        const confirmDropBtn = document.getElementById('confirmDropBtn');

        cancelDropConfirm.addEventListener('click', function() {
            dropConfirmModal.classList.remove('show');
        });
        dropConfirmModal.addEventListener('click', function(e) {
            if (e.target === dropConfirmModal) dropConfirmModal.classList.remove('show');
        });

        // Wire up the schedule-vs-immediate toggle in the drop modal.
        const dropScheduledFor = document.getElementById('dropScheduledFor');
        const dropScheduleHelp = document.getElementById('dropScheduleHelp');
        const dropTimingRadios = document.querySelectorAll('input[name="dropTiming"]');
        const todayIso = new Date().toISOString().slice(0, 10);
        if (dropScheduledFor) dropScheduledFor.min = todayIso;
        dropTimingRadios.forEach(r => {
            r.addEventListener('change', () => {
                const scheduled = document.querySelector('input[name="dropTiming"]:checked').value === 'scheduled';
                dropScheduledFor.style.display = scheduled ? '' : 'none';
                dropScheduleHelp.style.display = scheduled ? '' : 'none';
                confirmDropBtn.textContent = scheduled ? 'Schedule Drop' : 'Yes, Drop Contact(s)';
            });
        });

        // Run the drop workflow for the currently selected contacts.
        async function runDropWorkflow() {
            const contactIds = Array.from(selectedContacts);
            const isScheduled = document.querySelector('input[name="dropTiming"]:checked').value === 'scheduled';
            const scheduledFor = isScheduled ? (dropScheduledFor.value || '') : '';

            if (isScheduled && !scheduledFor) {
                alert('Please pick a date for the scheduled drop.');
                return;
            }

            confirmDropBtn.disabled = true;
            confirmDropBtn.textContent = isScheduled ? 'Scheduling...' : 'Dropping...';
            saveCohortChange.disabled = true;
            saveCohortChange.textContent = isScheduled ? 'Scheduling...' : 'Dropping...';

            try {
                const payload = { contact_ids: contactIds };
                if (scheduledFor) payload.scheduled_for = scheduledFor;
                const response = await fetch('drop_contact.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const result = await response.json();

                if (result.success && result.mode === 'scheduled') {
                    let msg = `Scheduled ${result.total_scheduled} drop(s) for ${result.scheduled_for}.`;
                    if (result.skipped && result.skipped.length) {
                        msg += `\n${result.skipped.length} skipped (not in local roster).`;
                    }
                    msg += '\nView/manage at scheduled_drops.php';
                    alert(msg);
                    dropConfirmModal.classList.remove('show');
                    changeCohortModal.classList.remove('show');
                    rowCheckboxes.forEach(cb => { cb.checked = false; syncRowTint(cb); });
                    selectAllCheckbox.checked = false;
                    selectedContacts.clear();
                    updateBulkActions();
                    return;
                }

                if (result.success) {
                    // Update UI rows for dropped contacts
                    (result.dropped || []).forEach(d => {
                        const row = document.querySelector(`tr[data-contact-id="${d.id}"]`);
                        if (row) {
                            row.setAttribute('data-cohort', '-dropped');
                            const cells = row.querySelectorAll('td');
                            if (cells[5]) cells[5].textContent = '-dropped';
                        }
                    });

                    let msg = `Dropped ${result.total_dropped} contact(s).`;
                    if (result.total_failed > 0) msg += `\n${result.total_failed} failed.`;
                    if (result.notification) {
                        if (result.notification.sent) {
                            msg += `\nNotification email sent to: ${(result.notification.recipients || []).join(', ')}.`;
                        } else if (result.notification.error) {
                            msg += `\nNotification email NOT sent: ${result.notification.error}`;
                        }
                        if (result.notification.unresolved_emails && result.notification.unresolved_emails.length) {
                            msg += `\nCould not find Keap contacts for: ${result.notification.unresolved_emails.join(', ')}`;
                        }
                    }
                    alert(msg);

                    dropConfirmModal.classList.remove('show');
                    changeCohortModal.classList.remove('show');
                    rowCheckboxes.forEach(cb => { cb.checked = false; syncRowTint(cb); });
                    selectAllCheckbox.checked = false;
                    selectedContacts.clear();
                    updateBulkActions();
                    buildCohortFilter();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error occurred'));
                }
            } catch (error) {
                console.error('Error dropping contact(s):', error);
                alert('An error occurred while dropping contact(s). Please try again.');
            } finally {
                confirmDropBtn.disabled = false;
                confirmDropBtn.textContent = 'Yes, Drop Contact(s)';
                saveCohortChange.disabled = false;
                saveCohortChange.textContent = 'Save Changes';
            }
        }

        confirmDropBtn.addEventListener('click', runDropWorkflow);

        // Save cohort change
        saveCohortChange.addEventListener('click', async function() {
            const newCohort = cohortSelect.value;

            if (!newCohort) {
                alert('Please select a cohort.');
                return;
            }

            const contactIds = Array.from(selectedContacts);

            // Dropping has its own workflow: confirm first, then run drop_contact.php
            if (newCohort === '-dropped') {
                dropConfirmCount.textContent = contactIds.length;
                dropConfirmModal.classList.add('show');
                return;
            }

            // Disable button and show loading state
            saveCohortChange.disabled = true;
            saveCohortChange.textContent = 'Saving...';

            try {
                const response = await fetch('update_cohort.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        contact_ids: contactIds,
                        cohort: newCohort
                    })
                });

                const result = await response.json();

                if (result.success) {
                    // Update the UI for successfully updated contacts
                    result.updated.forEach(contactId => {
                        const row = document.querySelector(`tr[data-contact-id="${contactId}"]`);
                        if (row) {
                            // Update data attribute
                            row.setAttribute('data-cohort', newCohort);
                            // Update search text
                            const currentSearchText = row.getAttribute('data-search-text');
                            row.setAttribute('data-search-text', currentSearchText.replace(/^(.*)$/, function(match) {
                                return match; // Keep existing, cohort is already part of it
                            }));
                            // Update visible cohort cell (6th td, index 5)
                            const cells = row.querySelectorAll('td');
                            if (cells[5]) {
                                cells[5].textContent = newCohort;
                            }
                        }
                    });

                    let message = `Successfully updated ${result.total_updated} contact(s).`;
                    if (result.total_failed > 0) {
                        message += `\n${result.total_failed} contact(s) failed to update.`;
                    }
                    alert(message);

                    // Close modal and clear selection
                    changeCohortModal.classList.remove('show');
                    rowCheckboxes.forEach(cb => { cb.checked = false; syncRowTint(cb); });
                    selectAllCheckbox.checked = false;
                    selectedContacts.clear();
                    updateBulkActions();

                    // Rebuild cohort filter to include any new cohorts
                    buildCohortFilter();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error occurred'));
                }
            } catch (error) {
                console.error('Error updating cohort:', error);
                alert('An error occurred while updating cohorts. Please try again.');
            } finally {
                // Re-enable button
                saveCohortChange.disabled = false;
                saveCohortChange.textContent = 'Save Changes';
            }
        });

        // Change Coach functionality
        const bulkChangeCoachAction = document.getElementById('bulkChangeCoachAction');
        const changeCoachModal = document.getElementById('changeCoachModal');
        const cancelCoachModal = document.getElementById('cancelCoachModal');
        const saveCoachChange = document.getElementById('saveCoachChange');
        const groupCoachSelect = document.getElementById('groupCoachSelect');
        const coachChangeCount = document.getElementById('coachChangeCount');
        const individualCoachCheckboxes = document.querySelectorAll('#individualCoachCheckboxes input[type="checkbox"]');
        const individualCoachActionRadios = document.querySelectorAll('input[name="individualCoachAction"]');

        // Open Change Coach modal
        bulkChangeCoachAction.addEventListener('click', function() {
            bulkActionsMenu.classList.remove('show');
            coachChangeCount.textContent = selectedContacts.size;
            // Reset individual coach checkboxes and radio buttons
            individualCoachCheckboxes.forEach(cb => cb.checked = false);
            document.querySelector('input[name="individualCoachAction"][value="no_change"]').checked = true;
            groupCoachSelect.value = '';
            changeCoachModal.classList.add('show');
        });

        // Cancel coach modal
        cancelCoachModal.addEventListener('click', function() {
            changeCoachModal.classList.remove('show');
        });

        // Close modal when clicking overlay
        changeCoachModal.addEventListener('click', function(e) {
            if (e.target === changeCoachModal) {
                changeCoachModal.classList.remove('show');
            }
        });

        // Save coach change
        saveCoachChange.addEventListener('click', async function() {
            // Get the selected action for individual coaches
            const individualAction = document.querySelector('input[name="individualCoachAction"]:checked').value;

            // Collect selected individual coaches
            const selectedIndividualCoaches = [];
            individualCoachCheckboxes.forEach(cb => {
                if (cb.checked) {
                    selectedIndividualCoaches.push(cb.value);
                }
            });

            // Determine individual coach value based on action
            let newIndividualCoach = '';
            if (individualAction === 'no_change') {
                newIndividualCoach = ''; // Empty means no change
            } else if (individualAction === 'clear') {
                newIndividualCoach = '__CLEAR__';
            } else if (individualAction === 'replace') {
                if (selectedIndividualCoaches.length === 0) {
                    alert('Please select at least one individual coach to replace with.');
                    return;
                }
                newIndividualCoach = selectedIndividualCoaches.join(', ');
            }

            const newGroupCoach = groupCoachSelect.value;

            if (individualAction === 'no_change' && !newGroupCoach) {
                alert('Please select at least one coach change to make.');
                return;
            }

            const contactIds = Array.from(selectedContacts);

            // Disable button and show loading state
            saveCoachChange.disabled = true;
            saveCoachChange.textContent = 'Saving...';

            try {
                const response = await fetch('update_coach.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        contact_ids: contactIds,
                        individual_coach: newIndividualCoach,
                        individual_action: individualAction,
                        group_coach: newGroupCoach
                    })
                });

                const result = await response.json();

                if (result.success) {
                    // Update the UI for successfully updated contacts
                    result.updated.forEach(contactId => {
                        const row = document.querySelector(`tr[data-contact-id="${contactId}"]`);
                        if (row) {
                            const coachCell = row.querySelectorAll('td')[7]; // Coach column is 8th (index 7)

                            // Update data attributes
                            if (individualAction !== 'no_change') {
                                const individualValue = newIndividualCoach === '__CLEAR__' ? '' : newIndividualCoach;
                                row.setAttribute('data-coach-individual', individualValue);
                            }
                            if (newGroupCoach) {
                                const groupValue = newGroupCoach === '__CLEAR__' ? '' : newGroupCoach;
                                row.setAttribute('data-coach-group', groupValue);
                            }

                            // Rebuild coach cell HTML
                            const currentIndividual = row.getAttribute('data-coach-individual');
                            const currentGroup = row.getAttribute('data-coach-group');
                            let coachHtml = '';

                            // Handle multiple individual coaches (comma-separated)
                            if (currentIndividual) {
                                const coaches = currentIndividual.split(',').map(c => c.trim()).filter(c => c);
                                coaches.forEach(coach => {
                                    coachHtml += `<div class="coach-item">${coach}<span class="coach-badge coach-badge-individual">Individual</span></div>`;
                                });
                            }
                            if (currentGroup) {
                                coachHtml += `<div class="coach-item">${currentGroup}<span class="coach-badge coach-badge-group">Group</span></div>`;
                            }

                            if (coachCell) {
                                coachCell.innerHTML = coachHtml;
                            }
                        }
                    });

                    let message = `Successfully updated ${result.total_updated} contact(s).`;
                    if (result.total_failed > 0) {
                        message += `\n${result.total_failed} contact(s) failed to update.`;
                    }
                    alert(message);

                    // Close modal and clear selection
                    changeCoachModal.classList.remove('show');
                    rowCheckboxes.forEach(cb => { cb.checked = false; syncRowTint(cb); });
                    selectAllCheckbox.checked = false;
                    selectedContacts.clear();
                    updateBulkActions();

                    // Rebuild coaches filter to include any new coaches
                    buildCoachesFilter();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error occurred'));
                }
            } catch (error) {
                console.error('Error updating coach:', error);
                alert('An error occurred while updating coaches. Please try again.');
            } finally {
                // Re-enable button
                saveCoachChange.disabled = false;
                saveCoachChange.textContent = 'Save Changes';
            }
        });

        } // End of userCanEdit block for bulk actions

        // Payment Info Modal functionality
        const paymentInfoModal = document.getElementById('paymentInfoModal');
        const paymentContactName = document.getElementById('paymentContactName');
        const paymentOptionDisplay = document.getElementById('paymentOptionDisplay');
        const paymentFrequencyDisplay = document.getElementById('paymentFrequencyDisplay');
        const closePaymentModal = document.getElementById('closePaymentModal');

        // Add click handlers to all payment icons
        document.querySelectorAll('.payment-icon').forEach(icon => {
            icon.addEventListener('click', function(e) {
                e.stopPropagation();
                const row = this.closest('tr');
                const contactName = this.getAttribute('data-contact-name');
                const paymentOption = row.getAttribute('data-payment-option') || 'Not specified';
                const paymentFrequency = row.getAttribute('data-payment-frequency') || 'Not specified';

                paymentContactName.textContent = contactName;
                paymentOptionDisplay.textContent = paymentOption;
                paymentFrequencyDisplay.textContent = paymentFrequency;

                paymentInfoModal.classList.add('show');
            });
        });

        // Close payment modal
        closePaymentModal.addEventListener('click', function() {
            paymentInfoModal.classList.remove('show');
        });

        // Close modal when clicking overlay
        paymentInfoModal.addEventListener('click', function(e) {
            if (e.target === paymentInfoModal) {
                paymentInfoModal.classList.remove('show');
            }
        });

        // Contact Details Modal functionality
        const contactDetailsModal = document.getElementById('contactDetailsModal');
        const contactDetailsName = document.getElementById('contactDetailsName');
        const contactDetailsNameEdit = document.getElementById('contactDetailsNameEdit');
        const editFirstName = document.getElementById('editFirstName');
        const editLastName = document.getElementById('editLastName');
        const contactDetailsEmail = document.getElementById('contactDetailsEmail');
        const contactDetailsPhone = document.getElementById('contactDetailsPhone');
        const contactDetailsKeapLink = document.getElementById('contactDetailsKeapLink');
        const closeContactDetailsModal = document.getElementById('closeContactDetailsModal');
        const copyContactEmail = document.getElementById('copyContactEmail');
        const copyContactPhone = document.getElementById('copyContactPhone');

        let currentContactEmail = '';
        let currentContactPhone = '';
        let currentContactId = '';
        let currentFirstName = '';
        let currentLastName = '';
        let currentRow = null;
        let isEditingName = false;

        // Add click handlers to all contact name links
        document.querySelectorAll('.contact-name-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.stopPropagation();
                const row = this.closest('tr');
                const firstName = row.getAttribute('data-firstname') || '';
                const lastName = row.getAttribute('data-lastname') || '';
                const email = row.getAttribute('data-email') || '';
                const phone = row.getAttribute('data-phone') || '';
                const keapUrl = row.getAttribute('data-keap-url') || '';
                const contactId = row.getAttribute('data-contact-id') || '';

                contactDetailsName.textContent = (firstName + ' ' + lastName).trim();
                contactDetailsEmail.textContent = email || 'Not available';
                contactDetailsPhone.textContent = phone || 'Not available';

                currentContactEmail = email;
                currentContactPhone = phone;
                currentContactId = contactId;
                currentFirstName = firstName;
                currentLastName = lastName;
                currentRow = row;

                // Reset edit mode
                contactDetailsName.style.display = 'block';
                contactDetailsNameEdit.style.display = 'none';
                isEditingName = false;

                if (keapUrl) {
                    contactDetailsKeapLink.href = keapUrl;
                    contactDetailsKeapLink.style.display = 'inline-block';
                } else {
                    contactDetailsKeapLink.style.display = 'none';
                }

                // Handle coaching files
                const coachingFiles = row.getAttribute('data-coaching-files') || '';
                const contactCoachingFiles = document.getElementById('contactCoachingFiles');
                const openCoachingFiles = document.getElementById('openCoachingFiles');
                const coachingFilesEdit = document.getElementById('coachingFilesEdit');
                const coachingFilesDisplay = document.getElementById('coachingFilesDisplay');

                if (coachingFiles) {
                    contactCoachingFiles.textContent = coachingFiles;
                    openCoachingFiles.href = coachingFiles;
                    openCoachingFiles.style.display = 'inline-block';
                } else {
                    contactCoachingFiles.textContent = 'Not set';
                    openCoachingFiles.style.display = 'none';
                }

                // Reset edit mode
                if (coachingFilesEdit) {
                    coachingFilesEdit.style.display = 'none';
                    coachingFilesDisplay.style.display = 'flex';
                }

                contactDetailsModal.classList.add('show');
            });
        });

        // Click on name to edit (only for editors)
        if (userCanEdit) {
        contactDetailsName.addEventListener('click', function() {
            if (isEditingName) return;

            isEditingName = true;
            editFirstName.value = currentFirstName;
            editLastName.value = currentLastName;

            contactDetailsName.style.display = 'none';
            contactDetailsNameEdit.style.display = 'block';

            editFirstName.focus();
            editFirstName.select();
        });
        } else {
            // Remove editable styling for viewers
            contactDetailsName.classList.remove('editable-name');
            contactDetailsName.style.cursor = 'default';
        }

        // Save name function
        async function saveName() {
            const newFirstName = editFirstName.value.trim();
            const newLastName = editLastName.value.trim();

            // Check if anything changed
            if (newFirstName === currentFirstName && newLastName === currentLastName) {
                cancelNameEdit();
                return;
            }

            // Validate
            if (!newFirstName && !newLastName) {
                alert('Name cannot be empty.');
                return;
            }

            // Show saving state
            contactDetailsNameEdit.classList.add('name-saving');

            try {
                const response = await fetch('update_name.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        contact_id: currentContactId,
                        first_name: newFirstName,
                        last_name: newLastName
                    })
                });

                const result = await response.json();

                if (result.success) {
                    // Update stored values
                    currentFirstName = newFirstName;
                    currentLastName = newLastName;

                    // Update modal display
                    contactDetailsName.textContent = (newFirstName + ' ' + newLastName).trim();

                    // Update the table row
                    if (currentRow) {
                        currentRow.setAttribute('data-firstname', newFirstName);
                        currentRow.setAttribute('data-lastname', newLastName);

                        // Update the visible name cells
                        const nameCells = currentRow.querySelectorAll('.contact-name-link');
                        if (nameCells[0]) nameCells[0].textContent = newFirstName;
                        if (nameCells[1]) nameCells[1].textContent = newLastName;

                        // Update search text
                        const oldSearchText = currentRow.getAttribute('data-search-text');
                        const newSearchText = (newFirstName + ' ' + newLastName + ' ' + currentContactEmail + ' ' + currentContactPhone).toLowerCase();
                        currentRow.setAttribute('data-search-text', newSearchText);
                    }

                    showCopiedMessage();
                    document.getElementById('copiedMessage').textContent = 'Name updated!';
                    setTimeout(() => {
                        document.getElementById('copiedMessage').textContent = 'Copied to clipboard!';
                    }, 2000);

                    cancelNameEdit();
                } else {
                    alert('Error: ' + (result.error || 'Failed to update name'));
                }
            } catch (error) {
                console.error('Error updating name:', error);
                alert('An error occurred while updating the name.');
            } finally {
                contactDetailsNameEdit.classList.remove('name-saving');
            }
        }

        // Cancel name edit
        function cancelNameEdit() {
            isEditingName = false;
            contactDetailsName.style.display = 'block';
            contactDetailsNameEdit.style.display = 'none';
        }

        // Handle Enter and Escape keys in name inputs
        [editFirstName, editLastName].forEach(input => {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveName();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelNameEdit();
                }
            });

            // Save on blur (unfocus) - but only if not clicking the other input
            input.addEventListener('blur', function(e) {
                // Small delay to check if focus moved to the other name input
                setTimeout(() => {
                    if (document.activeElement !== editFirstName && document.activeElement !== editLastName) {
                        if (isEditingName) {
                            saveName();
                        }
                    }
                }, 100);
            });
        });

        // Copy email button
        copyContactEmail.addEventListener('click', function() {
            if (currentContactEmail) {
                copyToClipboard(currentContactEmail);
            }
        });

        // Copy phone button
        copyContactPhone.addEventListener('click', function() {
            if (currentContactPhone) {
                copyToClipboard(currentContactPhone);
            }
        });

        // Close contact details modal
        closeContactDetailsModal.addEventListener('click', function() {
            contactDetailsModal.classList.remove('show');
        });

        // Close modal when clicking overlay
        contactDetailsModal.addEventListener('click', function(e) {
            if (e.target === contactDetailsModal) {
                contactDetailsModal.classList.remove('show');
            }
        });

        // Refresh roster button
        const refreshRosterBtn = document.getElementById('refreshRosterBtn');
        if (refreshRosterBtn) {
            refreshRosterBtn.addEventListener('click', async function() {
                if (this.classList.contains('refreshing')) return;

                this.classList.add('refreshing');
                this.disabled = true;

                try {
                    const response = await fetch('refresh_roster.php');
                    const result = await response.json();

                    if (result.success) {
                        // Show success message and reload
                        alert(`Roster refreshed successfully!\n\nTotal contacts: ${result.total_contacts}\nNew: ${result.inserted}\nUpdated: ${result.updated}`);
                        window.location.reload();
                    } else {
                        alert('Error refreshing roster: ' + (result.error || 'Unknown error'));
                        this.classList.remove('refreshing');
                        this.disabled = false;
                    }
                } catch (error) {
                    console.error('Error refreshing roster:', error);
                    alert('Failed to refresh roster. Please try again.');
                    this.classList.remove('refreshing');
                    this.disabled = false;
                }
            });
        }

        // Coaching Files editing (only for editors)
        if (userCanEdit) {
            const editCoachingFilesBtn = document.getElementById('editCoachingFilesBtn');
            const saveCoachingFilesBtn = document.getElementById('saveCoachingFiles');
            const cancelCoachingFilesBtn = document.getElementById('cancelCoachingFiles');
            const coachingFilesInput = document.getElementById('coachingFilesInput');
            const coachingFilesEdit = document.getElementById('coachingFilesEdit');
            const coachingFilesDisplay = document.getElementById('coachingFilesDisplay');
            const contactCoachingFiles = document.getElementById('contactCoachingFiles');
            const openCoachingFiles = document.getElementById('openCoachingFiles');

            if (editCoachingFilesBtn) {
                editCoachingFilesBtn.addEventListener('click', function() {
                    const currentValue = contactCoachingFiles.textContent === 'Not set' ? '' : contactCoachingFiles.textContent;
                    coachingFilesInput.value = currentValue;
                    coachingFilesDisplay.style.display = 'none';
                    coachingFilesEdit.style.display = 'block';
                    coachingFilesInput.focus();
                });

                cancelCoachingFilesBtn.addEventListener('click', function() {
                    coachingFilesEdit.style.display = 'none';
                    coachingFilesDisplay.style.display = 'flex';
                });

                saveCoachingFilesBtn.addEventListener('click', async function() {
                    const newValue = coachingFilesInput.value.trim();

                    saveCoachingFilesBtn.disabled = true;
                    saveCoachingFilesBtn.textContent = 'Saving...';

                    try {
                        const response = await fetch('update_coaching_files.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                contact_id: currentContactId,
                                coaching_files_link: newValue
                            })
                        });

                        const result = await response.json();

                        if (result.success) {
                            // Update display
                            if (newValue) {
                                contactCoachingFiles.textContent = newValue;
                                openCoachingFiles.href = newValue;
                                openCoachingFiles.style.display = 'inline-block';
                            } else {
                                contactCoachingFiles.textContent = 'Not set';
                                openCoachingFiles.style.display = 'none';
                            }

                            // Update row data attribute
                            if (currentRow) {
                                currentRow.setAttribute('data-coaching-files', newValue);
                                // Update or create the files link in the row
                                const filesCell = currentRow.querySelector('.files-cell');
                                if (filesCell) {
                                    if (newValue) {
                                        filesCell.innerHTML = `<a href="${newValue}" target="_blank" rel="noopener noreferrer" class="files-link" title="Open Coaching Files"><span class="icon">📁</span></a>`;
                                    } else {
                                        filesCell.innerHTML = '';
                                    }
                                }
                            }

                            coachingFilesEdit.style.display = 'none';
                            coachingFilesDisplay.style.display = 'flex';
                        } else {
                            alert('Error: ' + (result.error || 'Failed to save'));
                        }
                    } catch (error) {
                        console.error('Error saving coaching files:', error);
                        alert('Failed to save coaching files. Please try again.');
                    } finally {
                        saveCoachingFilesBtn.disabled = false;
                        saveCoachingFilesBtn.textContent = 'Save';
                    }
                });

                // Save on Enter key
                coachingFilesInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        saveCoachingFilesBtn.click();
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        cancelCoachingFilesBtn.click();
                    }
                });
            }
        }
    </script>
    <!-- Quill Editor JS -->
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
    <script>
        // Initialize Quill editor for email composition
        let emailQuill = null;

        function initEmailEditor() {
            if (emailQuill) return; // Already initialized

            emailQuill = new Quill('#emailEditor', {
                theme: 'snow',
                placeholder: 'Enter your message here...',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        ['link'],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        ['clean']
                    ]
                }
            });

            // Sync content to hidden input for form validation
            emailQuill.on('text-change', function() {
                const html = emailQuill.root.innerHTML;
                document.getElementById('emailMessage').value =
                    html === '<p><br></p>' ? '' : html;
            });
        }

        // Initialize editor when bulk email modal opens
        const bulkEmailModalEl = document.getElementById('bulkEmailModal');
        if (bulkEmailModalEl) {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'class' && bulkEmailModalEl.classList.contains('show')) {
                        setTimeout(initEmailEditor, 50);
                    }
                });
            });
            observer.observe(bulkEmailModalEl, { attributes: true });
        }
    </script>
    <?php roster_ui_menu_js(); ?>
</body>
</html>
