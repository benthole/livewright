<?php
/**
 * Field Organizer (Admin Only)
 *
 * Organize the Keap custom-field values shown in the roster UI:
 * assign each value to a group (Active / Functional / Inactive), reorder
 * within/between groups by drag-and-drop, and hide values from the dropdowns.
 * Stored in roster_field_options; see lib/field_config.php.
 */

require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/ui.php');
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../keap_api.php');
require_once(__DIR__ . '/../lib/field_config.php');

require_auth();
if (!is_admin()) {
    header('Location: ../');
    exit;
}
$current_user = get_logged_in_user();

$conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$defs = fc_field_defs();
$groups = fc_groups();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $message = 'Invalid form submission. Please try again.';
        $messageType = 'error';
    } else {
        $fieldKey = $_POST['field_key'] ?? '';
        $itemsJson = $_POST['items_json'] ?? '[]';
        $items = json_decode($itemsJson, true);
        if (!isset($defs[$fieldKey])) {
            $message = 'Unknown field.';
            $messageType = 'error';
        } elseif (!is_array($items)) {
            $message = 'Could not read the submitted layout.';
            $messageType = 'error';
        } elseif (fc_save($conn, $fieldKey, $items)) {
            $message = $defs[$fieldKey]['label'] . ' layout saved.';
            $messageType = 'success';
        } else {
            $message = 'Failed to save. Please try again.';
            $messageType = 'error';
        }
    }
}

// Load organized data for every managed field.
$organized = [];
try {
    foreach ($defs as $key => $_) {
        $organized[$key] = fc_organized($conn, $key);
    }
} catch (Exception $e) {
    error_log('Field organizer load error: ' . $e->getMessage());
    foreach ($defs as $key => $_) {
        if (!isset($organized[$key])) $organized[$key] = [];
    }
    if (!$message) {
        $message = 'Could not load current values (database error). ' . htmlspecialchars($e->getMessage());
        $messageType = 'error';
    }
}

$activeTab = $_POST['field_key'] ?? array_key_first($defs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Field Organizer — Roster Admin</title>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <?php roster_ui_styles(); ?>
    <style>
        .wrap { max-width: 1100px; margin: 24px auto; padding: 0 24px; }
        .tool-intro { color: var(--ink-soft); font-size: 14px; margin: 20px 0 18px; max-width: 65ch; }
        .tool-intro em { color: var(--ink); font-style: normal; font-weight: 600; }
        .tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 18px; }
        .tab { padding: 9px 16px; border: 1px solid var(--line-strong); background: var(--surface); color: var(--ink); border-radius: var(--r-sm); cursor: pointer; font-size: 14px; font-weight: 500; transition: background var(--dur) var(--ease), border-color var(--dur) var(--ease), color var(--dur) var(--ease); }
        .tab:hover { background: var(--surface-sunk); }
        .tab.active { background: var(--accent); color: oklch(0.99 0.003 85); border-color: var(--accent); }
        .panel { display: none; }
        .panel.active { display: block; }
        .groups { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .group { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-md); padding: 12px; min-height: 120px; box-shadow: var(--shadow-sm); }
        .group-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .group h3 { margin: 0; font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); }
        .add-divider { border: 1px dashed var(--line-strong); background: var(--surface); color: var(--ink-faint); border-radius: var(--r-sm); font-size: 12px; padding: 3px 8px; cursor: pointer; transition: border-color var(--dur) var(--ease), color var(--dur) var(--ease); }
        .add-divider:hover { border-color: var(--accent); color: var(--accent-ink); }
        .divider-item { background: var(--surface-sunk); color: var(--ink-faint); font-size: 12px; letter-spacing: .1em; justify-content: space-between; }
        .divider-item .val { text-align: center; color: var(--ink-faint); }
        .remove-divider { border: none; background: transparent; color: var(--ink-faint); font-size: 16px; line-height: 1; cursor: pointer; padding: 0 4px; transition: color var(--dur) var(--ease); }
        .remove-divider:hover { color: var(--danger); }
        .list { min-height: 60px; display: flex; flex-direction: column; gap: 6px; }
        .item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: var(--surface-sunk); border: 1px solid var(--line); border-radius: var(--r-sm); font-size: 14px; cursor: grab; }
        .item.hidden-val { opacity: .5; }
        .item .handle { color: var(--ink-faint); cursor: grab; }
        .item .val { flex: 1; word-break: break-word; }
        .item label { font-size: 12px; color: var(--ink-soft); display: flex; align-items: center; gap: 4px; cursor: pointer; white-space: nowrap; }
        .sortable-ghost { opacity: .4; }
        .actions { margin-top: 18px; display: flex; gap: 12px; align-items: center; }
        .msg { padding: 12px 16px; border-radius: var(--r-sm); margin-bottom: 18px; font-size: 14px; }
        .msg.success { background: var(--ok-bg); color: var(--ok); border: 1px solid var(--ok); }
        .msg.error { background: var(--danger-bg); color: var(--danger-ink); border: 1px solid var(--danger); }
        .empty-hint { color: var(--ink-faint); font-size: 13px; font-style: italic; padding: 8px; }
    </style>
</head>
<body class="rui">
    <?php roster_ui_topbar(['base' => '../', 'active' => 'organize_fields', 'page_title' => 'Organize Fields', 'user' => $current_user, 'is_admin' => true]); ?>
    <div class="wrap">
        <p class="tool-intro">
            Organize how Keap values appear in the roster dropdowns. Drag values between
            groups to regroup them, drag within a group to reorder, and use <em>Hide</em>
            to keep a value out of the dropdown. Values are pulled live from Keap, so new
            options appear here automatically. Changes save per field.
        </p>

        <?php if ($message): ?>
            <div class="msg <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="tabs">
            <?php foreach ($defs as $key => $def): ?>
                <div class="tab <?php echo $key === $activeTab ? 'active' : ''; ?>" data-tab="<?php echo htmlspecialchars($key); ?>">
                    <?php echo htmlspecialchars($def['label']); ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php foreach ($defs as $key => $def): ?>
            <div class="panel <?php echo $key === $activeTab ? 'active' : ''; ?>" data-panel="<?php echo htmlspecialchars($key); ?>">
                <form method="POST" class="organizer-form" data-field="<?php echo htmlspecialchars($key); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="field_key" value="<?php echo htmlspecialchars($key); ?>">
                    <input type="hidden" name="items_json" value="">

                    <div class="groups">
                        <?php foreach ($groups as $gKey => $gLabel): ?>
                            <div class="group">
                                <div class="group-head">
                                    <h3><?php echo htmlspecialchars($gLabel); ?></h3>
                                    <button type="button" class="add-divider" data-group="<?php echo htmlspecialchars($gKey); ?>" title="Add a divider line to this group">+ divider</button>
                                </div>
                                <div class="list" data-group="<?php echo htmlspecialchars($gKey); ?>" data-field="<?php echo htmlspecialchars($key); ?>">
                                    <?php
                                    foreach ($organized[$key] as $item):
                                        if ($item['group'] !== $gKey) continue;
                                        if (!empty($item['divider'])):
                                    ?>
                                        <div class="item divider-item" data-divider="1">
                                            <span class="handle">⠿</span>
                                            <span class="val">────────── divider ──────────</span>
                                            <button type="button" class="remove-divider" title="Remove divider">×</button>
                                        </div>
                                    <?php else: ?>
                                        <div class="item <?php echo !empty($item['hidden']) ? 'hidden-val' : ''; ?>" data-divider="0" data-value="<?php echo htmlspecialchars($item['value']); ?>">
                                            <span class="handle">⠿</span>
                                            <span class="val"><?php echo htmlspecialchars($item['value']); ?></span>
                                            <label><input type="checkbox" class="hide-toggle" <?php echo !empty($item['hidden']) ? 'checked' : ''; ?>> Hide</label>
                                        </div>
                                    <?php endif; endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="actions">
                        <button type="submit" class="rui-btn rui-btn--primary">Save <?php echo htmlspecialchars($def['label']); ?></button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const key = tab.dataset.tab;
                document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t === tab));
                document.querySelectorAll('.panel').forEach(p => p.classList.toggle('active', p.dataset.panel === key));
            });
        });

        // Drag-and-drop: shared group per field so items move between the 3 columns.
        document.querySelectorAll('.organizer-form').forEach(form => {
            const field = form.dataset.field;
            form.querySelectorAll('.list').forEach(list => {
                Sortable.create(list, {
                    group: 'field-' + field,
                    handle: '.handle',
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                });
            });

            // Toggle hidden styling live
            form.addEventListener('change', e => {
                if (e.target.classList.contains('hide-toggle')) {
                    e.target.closest('.item').classList.toggle('hidden-val', e.target.checked);
                }
            });

            // Add a divider to a group; remove a divider.
            form.addEventListener('click', e => {
                if (e.target.classList.contains('add-divider')) {
                    const group = e.target.dataset.group;
                    const list = form.querySelector('.list[data-group="' + group + '"]');
                    const div = document.createElement('div');
                    div.className = 'item divider-item';
                    div.dataset.divider = '1';
                    div.innerHTML = '<span class="handle">⠿</span>' +
                        '<span class="val">────────── divider ──────────</span>' +
                        '<button type="button" class="remove-divider" title="Remove divider">×</button>';
                    list.appendChild(div);
                } else if (e.target.classList.contains('remove-divider')) {
                    e.target.closest('.item').remove();
                }
            });

            // Serialize DOM order + group + hidden + divider into items_json on submit
            form.addEventListener('submit', () => {
                const items = [];
                form.querySelectorAll('.list').forEach(list => {
                    const group = list.dataset.group;
                    list.querySelectorAll('.item').forEach(item => {
                        const isDivider = item.dataset.divider === '1';
                        items.push({
                            value: isDivider ? '' : item.dataset.value,
                            group: group,
                            hidden: isDivider ? false : item.querySelector('.hide-toggle').checked,
                            divider: isDivider,
                        });
                    });
                });
                form.querySelector('input[name="items_json"]').value = JSON.stringify(items);
            });
        });
    </script>
    <?php roster_ui_menu_js(); ?>
</body>
</html>
