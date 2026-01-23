<?php
// index.php - Display roster data (main entry point)

require_once('includes/auth.php');
require_once('config.php');

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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 30px;
            background: <?php echo $viewDropped ? '#7f8c8d' : '#2c3e50'; ?>;
            color: white;
        }

        h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }

        .header-center {
            flex: 1;
            text-align: center;
        }

        .record-count {
            font-size: 14px;
            color: #ecf0f1;
            background: rgba(255,255,255,0.1);
            padding: 6px 12px;
            border-radius: 4px;
        }

        .refresh-btn {
            margin-left: 10px;
            padding: 6px 12px;
            background: rgba(255,255,255,0.15);
            color: #ecf0f1;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .refresh-btn:hover {
            background: rgba(255,255,255,0.25);
        }

        .refresh-btn:active {
            transform: scale(0.98);
        }

        .refresh-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .refresh-btn.refreshing .refresh-icon,
        .refresh-btn.refreshing .refresh-text {
            display: none;
        }

        .refresh-btn.refreshing .refresh-spinner {
            display: inline !important;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .last-sync-time {
            margin-left: 15px;
            font-size: 12px;
            color: #bdc3c7;
            font-style: italic;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 8px 35px 8px 12px;
            border: 1px solid #34495e;
            border-radius: 4px;
            font-size: 14px;
            width: 250px;
            background: white;
        }

        .search-box input:focus {
            outline: none;
            border-color: #3498db;
        }

        .search-icon {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
        }

        .reset-filters {
            margin-top: 8px;
            text-align: right;
        }

        .reset-filters a {
            font-size: 12px;
            color: #95a5a6;
            cursor: pointer;
            text-decoration: none;
            transition: color 0.2s;
        }

        .reset-filters a:hover {
            color: #3498db;
            text-decoration: underline;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #34495e;
            color: white;
        }

        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
        }

        th.sortable {
            cursor: pointer;
            user-select: none;
        }

        th.sortable:hover {
            background: #2c3e50;
        }

        th.filterable {
            cursor: pointer;
            user-select: none;
        }

        th.filterable:hover {
            background: #2c3e50;
        }

        .sort-indicator {
            display: inline-block;
            margin-left: 5px;
            font-size: 10px;
            color: #95a5a6;
        }

        .filter-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            min-width: 200px;
            max-height: 300px;
            overflow-y: auto;
            display: none;
        }

        .filter-dropdown.show {
            display: block;
        }

        .filter-actions {
            padding: 8px 12px;
            border-bottom: 1px solid #ecf0f1;
            background: #f8f9fa;
        }

        .filter-actions a {
            font-size: 12px;
            color: #3498db;
            cursor: pointer;
            text-decoration: underline;
        }

        .filter-actions a:hover {
            color: #2980b9;
        }

        .filter-option {
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: #2c3e50;
            font-size: 13px;
            text-transform: none;
            font-weight: normal;
            letter-spacing: normal;
        }

        .filter-option:hover {
            background: #f8f9fa;
        }

        .filter-option input[type="checkbox"] {
            cursor: pointer;
        }

        .filter-option label {
            cursor: pointer;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #ecf0f1;
        }

        tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        tbody tr:hover {
            background: #f0f3f5;
        }

        a {
            color: #3498db;
            text-decoration: none;
            transition: color 0.2s;
        }

        a:hover {
            color: #2980b9;
            text-decoration: underline;
        }

        .icon-wrapper {
            position: relative;
            display: inline-block;
            cursor: pointer;
        }

        .icon {
            font-size: 18px;
            color: #3498db;
            transition: color 0.2s;
        }

        .icon:hover {
            color: #2980b9;
        }

        .tooltip {
            visibility: hidden;
            position: absolute;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            background: #2c3e50;
            color: white;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: #2c3e50;
        }

        .icon-wrapper:hover .tooltip {
            visibility: visible;
            opacity: 1;
        }

        .copied-message {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10000;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .copied-message.show {
            opacity: 1;
        }

        .no-data {
            padding: 40px;
            text-align: center;
            color: #7f8c8d;
            font-size: 16px;
        }

        .care-label {
            margin-bottom: 8px;
            font-weight: 500;
        }

        .care-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .care-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #ecf0f1;
            color: #2c3e50;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .coach-item {
            margin-bottom: 6px;
        }

        .coach-item:last-child {
            margin-bottom: 0;
        }

        .coach-badge {
            display: inline-block;
            margin-left: 8px;
            padding: 4px 8px;
            color: white;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .coach-badge-individual {
            background: #3498db;
        }

        .coach-badge-group {
            background: #27ae60;
        }

        .contact-name-link {
            color: #3498db;
            cursor: pointer;
            transition: color 0.2s;
        }

        .contact-name-link:hover {
            color: #2980b9;
            text-decoration: underline;
        }

        .editable-name {
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: background 0.2s;
        }

        .editable-name:hover {
            background: #f0f3f5;
        }

        .editable-name::after {
            content: ' ✏️';
            font-size: 14px;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .editable-name:hover::after {
            opacity: 1;
        }

        .name-saving {
            opacity: 0.6;
            pointer-events: none;
        }

        .checkbox-cell {
            width: 40px;
            text-align: center;
        }

        .payment-cell {
            width: 40px;
            text-align: center;
        }

        .payment-icon {
            cursor: pointer;
        }

        .payment-icon .icon {
            font-size: 16px;
            color: #27ae60;
        }

        .payment-icon:hover .icon {
            color: #1e8449;
        }

        .files-cell {
            width: 40px;
            text-align: center;
        }

        .files-link {
            text-decoration: none;
            display: inline-block;
        }

        .files-link .icon {
            font-size: 16px;
            color: #3498db;
            transition: transform 0.2s;
        }

        .files-link:hover .icon {
            color: #2980b9;
            transform: scale(1.1);
        }

        .checkbox-cell input[type="checkbox"] {
            cursor: pointer;
            width: 16px;
            height: 16px;
        }

        .bulk-actions-container {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            display: none;
            z-index: 2000;
            align-items: center;
            gap: 15px;
        }

        .bulk-actions-container.show {
            display: flex;
        }

        .bulk-actions-count {
            font-size: 14px;
            color: #2c3e50;
            font-weight: 600;
        }

        .bulk-actions-dropdown {
            position: relative;
        }

        .bulk-actions-btn {
            padding: 8px 16px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }

        .bulk-actions-btn:hover {
            background: #2980b9;
        }

        .bulk-actions-menu {
            position: absolute;
            bottom: 100%;
            left: 0;
            margin-bottom: 8px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            min-width: 180px;
            display: none;
        }

        .bulk-actions-menu.show {
            display: block;
        }

        .bulk-action-item {
            padding: 10px 15px;
            cursor: pointer;
            font-size: 14px;
            color: #2c3e50;
            transition: background 0.2s;
        }

        .bulk-action-item:hover {
            background: #f8f9fa;
        }

        .bulk-actions-cancel {
            padding: 8px 16px;
            background: transparent;
            color: #7f8c8d;
            border: none;
            font-size: 14px;
            cursor: pointer;
            transition: color 0.2s;
        }

        .bulk-actions-cancel:hover {
            color: #2c3e50;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 3000;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal {
            background: white;
            border-radius: 8px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            margin-bottom: 20px;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 24px;
            color: #2c3e50;
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3498db;
        }

        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }

        /* WYSIWYG Editor Styling */
        #emailEditorContainer {
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
        }

        #emailEditorContainer:focus-within {
            border-color: #3498db;
        }

        #emailEditorContainer .ql-toolbar {
            border: none;
            border-bottom: 1px solid #ddd;
            background: #f8f9fa;
            border-radius: 4px 4px 0 0;
        }

        #emailEditorContainer .ql-container {
            border: none;
            font-size: 14px;
            font-family: inherit;
        }

        #emailEditorContainer .ql-editor {
            min-height: 150px;
            padding: 10px;
        }

        #emailEditorContainer .ql-editor.ql-blank::before {
            font-style: normal;
            color: #7f8c8d;
        }

        .ql-tooltip {
            z-index: 3001;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-primary {
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-secondary {
            padding: 10px 20px;
            background: #ecf0f1;
            color: #2c3e50;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-secondary:hover {
            background: #d5dbdb;
        }

        /* Coach Modal Styles */
        .coach-action-toggle {
            display: flex;
            background: #f0f2f5;
            border-radius: 8px;
            padding: 4px;
            gap: 4px;
            margin-bottom: 16px;
        }

        .coach-action-toggle label {
            flex: 1;
            text-align: center;
            padding: 10px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .coach-action-toggle label:hover {
            color: #334155;
            background: rgba(255,255,255,0.5);
        }

        .coach-action-toggle input[type="radio"] {
            display: none;
        }

        .coach-action-toggle input[type="radio"]:checked + span {
            background: white;
            color: #1e293b;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .coach-action-toggle .toggle-option {
            display: block;
            padding: 10px 16px;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .coach-action-toggle .toggle-icon {
            font-size: 14px;
        }

        .coach-list-container {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            max-height: 200px;
            overflow-y: auto;
        }

        .coach-list-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            cursor: pointer;
            transition: background 0.15s ease;
            border-bottom: 1px solid #f1f5f9;
        }

        .coach-list-item:last-child {
            border-bottom: none;
        }

        .coach-list-item:nth-child(odd) {
            background: #fafbfc;
        }

        .coach-list-item:nth-child(even) {
            background: #ffffff;
        }

        .coach-list-item:hover {
            background: #f0f7ff;
        }

        .coach-list-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 0;
            margin-right: 12px;
            cursor: pointer;
            accent-color: #3498db;
        }

        .coach-list-item .coach-name {
            font-size: 14px;
            color: #334155;
            font-weight: 500;
        }

        .coach-list-item.selected {
            background: #eff6ff;
        }

        .coach-list-item.selected .coach-name {
            color: #1d4ed8;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-left: 20px;
        }

        .user-info-display {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-name {
            font-size: 14px;
            color: #ecf0f1;
        }

        .user-role-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-viewer {
            background: rgba(255,255,255,0.1);
            color: #bdc3c7;
        }

        .role-editor {
            background: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
        }

        .role-admin {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
        }

        .header-link {
            color: #ecf0f1;
            text-decoration: none;
            font-size: 13px;
            padding: 6px 12px;
            border-radius: 4px;
            transition: background 0.2s;
        }

        .header-link:hover {
            background: rgba(255,255,255,0.1);
            text-decoration: none;
        }

        .viewer-notice {
            background: #ffeaa7;
            color: #856404;
            padding: 10px 20px;
            text-align: center;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-row">
            <h1><?php echo $viewDropped ? 'Dropped Contacts' : 'LiveWright Roster'; ?></h1>
            <div class="header-center">
                <span id="recordCount" class="record-count"></span>
                <button id="refreshRosterBtn" class="refresh-btn" title="Refresh roster data from Keap">
                    <span class="refresh-icon">🔄</span>
                    <span class="refresh-text">Refresh</span>
                    <span class="refresh-spinner" style="display: none;">⏳</span>
                </button>
                <?php if ($lastSyncTime): ?>
                <span class="last-sync-time" title="Last synced from Keap">
                    Last sync: <?php echo date('M j, g:i A', strtotime($lastSyncTime)); ?>
                </span>
                <?php endif; ?>
            </div>
            <div>
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="Search roster...">
                    <span class="search-icon">🔍</span>
                </div>
                <div class="reset-filters">
                    <a id="resetAllFilters">Clear search & filters</a>
                </div>
            </div>
            <div class="user-menu">
                <div class="user-info-display">
                    <span class="user-name"><?php echo htmlspecialchars($current_user['name']); ?></span>
                    <span class="user-role-badge role-<?php echo $current_user['role']; ?>"><?php echo ucfirst($current_user['role']); ?></span>
                </div>
                <?php if ($user_is_admin): ?>
                <a href="admin/users.php" class="header-link">Manage Users</a>
                <?php endif; ?>
                <a href="logout.php" class="header-link">Logout</a>
            </div>
        </div>

        <?php if (!$user_can_edit): ?>
        <div class="viewer-notice">
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
                        data-coach-individual="<?php echo htmlspecialchars($coachIndividualRaw); ?>"
                        data-coach-group="<?php echo htmlspecialchars($coachGroup); ?>"
                        data-contact-id="<?php echo htmlspecialchars($data['id'] ?? ''); ?>"
                        data-email="<?php echo htmlspecialchars($email); ?>"
                        data-phone="<?php echo htmlspecialchars($phone); ?>"
                        data-keap-url="<?php echo htmlspecialchars($keapUrl); ?>"
                        data-payment-option="<?php echo htmlspecialchars($paymentOption); ?>"
                        data-payment-frequency="<?php echo htmlspecialchars($paymentFrequency); ?>"
                        data-coaching-files="<?php echo htmlspecialchars($coachingFilesLink); ?>"
                        data-search-text="<?php echo htmlspecialchars(strtolower($firstName . ' ' . $lastName . ' ' . $email . ' ' . $phone . ' ' . $cohort . ' ' . $careLabel . ' ' . $coachIndividualRaw . ' ' . $coachGroup)); ?>">
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
    <div style="text-align: center; margin-top: 20px;">
        <?php if ($viewDropped): ?>
        <a href="./" style="display: inline-block; padding: 10px 20px; background: #34495e; color: white; border-radius: 4px; font-size: 14px; font-weight: 600; text-decoration: none;">&larr; Back to Roster</a>
        <?php else: ?>
        <a href="./?dropped=1" style="display: inline-block; padding: 10px 20px; background: #7f8c8d; color: white; border-radius: 4px; font-size: 14px; font-weight: 600; text-decoration: none;">View Dropped</a>
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
                <p style="margin-bottom: 15px; color: #7f8c8d;">Select a new team for the <strong id="cohortChangeCount">0</strong> selected contact(s):</p>
                <div class="form-group">
                    <label for="cohortSelect">Team</label>
                    <select id="cohortSelect" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        <option value="">-- Select Team --</option>
                        <optgroup label="Active">
                            <?php foreach ($cohorts['active'] as $cohort): ?>
                            <option value="<?php echo htmlspecialchars($cohort); ?>"><?php echo htmlspecialchars($cohort); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Functional">
                            <?php foreach ($cohorts['functional'] as $cohort): ?>
                            <option value="<?php echo htmlspecialchars($cohort); ?>"><?php echo htmlspecialchars($cohort); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Inactive">
                            <?php foreach ($cohorts['inactive'] as $cohort): ?>
                            <option value="<?php echo htmlspecialchars($cohort); ?>"><?php echo htmlspecialchars($cohort); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" id="cancelCohortModal">Cancel</button>
                <button class="btn-primary" id="saveCohortChange">Save Changes</button>
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
                <p style="margin-bottom: 20px; color: #64748b; font-size: 14px;">Updating <strong style="color: #334155;" id="coachChangeCount">0</strong> selected contact(s)</p>

                <div class="form-group">
                    <label style="font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 10px; display: block;">Individual Coach(es)</label>

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
                        <?php foreach ($individual_coaches as $coach): ?>
                        <label class="coach-list-item">
                            <input type="checkbox" name="individualCoaches[]" value="<?php echo htmlspecialchars($coach); ?>">
                            <span class="coach-name"><?php echo htmlspecialchars($coach); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p style="font-size: 12px; color: #94a3b8; margin-top: 8px;">Select "Replace" then choose coaches to assign</p>
                </div>

                <div class="form-group" style="margin-top: 24px;">
                    <label style="font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 10px; display: block;">Group Coach</label>
                    <select id="groupCoachSelect" style="width: 100%; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: #334155; background: white; cursor: pointer;">
                        <option value="">Keep current assignment</option>
                        <option value="__CLEAR__">Clear assignment</option>
                        <?php foreach ($group_coaches as $coach): ?>
                        <option value="<?php echo htmlspecialchars($coach); ?>"><?php echo htmlspecialchars($coach); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #f1f5f9; padding-top: 20px;">
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
                <p style="margin-bottom: 15px; color: #7f8c8d;">Send an email to <strong id="emailContactCount">0</strong> selected contact(s) through Keap.</p>
                <div style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label for="fromName">From Name</label>
                        <input type="text" id="fromName" placeholder="Your name" value="<?php echo htmlspecialchars($current_user['name'] ?? ''); ?>">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="fromEmailUser">From Email <span style="color: #e74c3c;">*</span></label>
                        <div style="display: flex; align-items: center;">
                            <?php
                            $defaultEmailUser = '';
                            if (!empty($current_user['email']) && preg_match('/^([^@]+)@livewright\.com$/i', $current_user['email'], $matches)) {
                                $defaultEmailUser = $matches[1];
                            }
                            ?>
                            <input type="text" id="fromEmailUser" placeholder="username" required style="border-radius: 4px 0 0 4px; border-right: none; flex: 1;" value="<?php echo htmlspecialchars($defaultEmailUser); ?>">
                            <span style="background: #ecf0f1; border: 1px solid #ddd; padding: 10px 12px; border-radius: 0 4px 4px 0; color: #7f8c8d; font-size: 14px; white-space: nowrap;">@livewright.com</span>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="emailSubject">Subject <span style="color: #e74c3c;">*</span></label>
                    <input type="text" id="emailSubject" placeholder="Email subject" required>
                </div>
                <div class="form-group">
                    <label for="emailMessage">Message <span style="color: #e74c3c;">*</span></label>
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
                <p style="margin-top: 5px; color: #7f8c8d; font-size: 14px;" id="paymentContactName"></p>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Payment Option</label>
                    <div id="paymentOptionDisplay" style="padding: 10px; background: #f8f9fa; border-radius: 4px; min-height: 40px;"></div>
                </div>
                <div class="form-group">
                    <label>Payment Frequency</label>
                    <div id="paymentFrequencyDisplay" style="padding: 10px; background: #f8f9fa; border-radius: 4px; min-height: 40px;"></div>
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
                        <input type="text" id="editFirstName" placeholder="First Name" style="flex: 1; padding: 8px; border: 1px solid #3498db; border-radius: 4px; font-size: 16px;">
                        <input type="text" id="editLastName" placeholder="Last Name" style="flex: 1; padding: 8px; border: 1px solid #3498db; border-radius: 4px; font-size: 16px;">
                    </div>
                    <small style="color: #7f8c8d;">Press Enter to save, Escape to cancel</small>
                </div>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Email</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div id="contactDetailsEmail" style="flex: 1; padding: 10px; background: #f8f9fa; border-radius: 4px; min-height: 20px;"></div>
                        <button class="btn-secondary" id="copyContactEmail" style="padding: 8px 12px; white-space: nowrap;">Copy</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div id="contactDetailsPhone" style="flex: 1; padding: 10px; background: #f8f9fa; border-radius: 4px; min-height: 20px;"></div>
                        <button class="btn-secondary" id="copyContactPhone" style="padding: 8px 12px; white-space: nowrap;">Copy</button>
                    </div>
                </div>
                <div class="form-group" id="coachingFilesGroup">
                    <label>Coaching Files</label>
                    <div id="coachingFilesDisplay" style="display: flex; align-items: center; gap: 10px;">
                        <div id="contactCoachingFiles" style="flex: 1; padding: 10px; background: #f8f9fa; border-radius: 4px; min-height: 20px; word-break: break-all;"></div>
                        <a id="openCoachingFiles" href="#" target="_blank" rel="noopener noreferrer" class="btn-secondary" style="padding: 8px 12px; white-space: nowrap; text-decoration: none; display: none;">Open</a>
                        <?php if ($user_can_edit): ?>
                        <button class="btn-secondary" id="editCoachingFilesBtn" style="padding: 8px 12px; white-space: nowrap;">Edit</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($user_can_edit): ?>
                    <div id="coachingFilesEdit" style="display: none; margin-top: 10px;">
                        <input type="text" id="coachingFilesInput" placeholder="https://drive.google.com/..." style="width: 100%; padding: 10px; border: 1px solid #3498db; border-radius: 4px; font-size: 14px;">
                        <div style="margin-top: 8px; display: flex; gap: 8px;">
                            <button class="btn-primary" id="saveCoachingFiles" style="padding: 6px 12px; font-size: 13px;">Save</button>
                            <button class="btn-secondary" id="cancelCoachingFiles" style="padding: 6px 12px; font-size: 13px;">Cancel</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: space-between;">
                <a id="contactDetailsKeapLink" href="#" target="_blank" rel="noopener noreferrer" style="padding: 10px 20px; background: #3498db; color: white; border-radius: 4px; font-size: 14px; font-weight: 600; text-decoration: none;">View in Keap</a>
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
                if (matchesSearch && matchesCohort && matchesCoach) {
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
            rowCheckboxes.forEach(cb => cb.checked = false);
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
                    rowCheckboxes.forEach(cb => cb.checked = false);
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

        // Save cohort change
        saveCohortChange.addEventListener('click', async function() {
            const newCohort = cohortSelect.value;

            if (!newCohort) {
                alert('Please select a cohort.');
                return;
            }

            const contactIds = Array.from(selectedContacts);

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
                    rowCheckboxes.forEach(cb => cb.checked = false);
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
                    rowCheckboxes.forEach(cb => cb.checked = false);
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
</body>
</html>
