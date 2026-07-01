<?php
/**
 * Field Organizer (Admin Only)
 *
 * Organize the Keap custom-field values shown in the roster UI:
 * assign each value to a group (Active / Functional / Inactive), reorder
 * within/between groups by drag-and-drop, and hide values from the dropdowns.
 * Stored in roster_field_options; see lib/field_config.php.
 */

require_once('../includes/auth.php');
require_once('../config.php');
require_once('../keap_api.php');
require_once('../lib/field_config.php');

require_auth();
if (!is_admin()) {
    header('Location: ../');
    exit;
}

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
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f4f6f8; color: #1a2733; }
        .topbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 14px 24px; display: flex; align-items: center; gap: 16px; }
        .topbar h1 { font-size: 18px; margin: 0; }
        .topbar a { color: #17a2b8; text-decoration: none; font-size: 14px; }
        .wrap { max-width: 1100px; margin: 24px auto; padding: 0 24px; }
        .intro { color: #5a6b7b; font-size: 14px; margin-bottom: 18px; }
        .tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 18px; }
        .tab { padding: 9px 16px; border: 1px solid #cbd5e0; background: #fff; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .tab.active { background: #17a2b8; color: #fff; border-color: #17a2b8; }
        .panel { display: none; }
        .panel.active { display: block; }
        .groups { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .group { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; min-height: 120px; }
        .group h3 { margin: 0 0 10px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #6b7a89; }
        .list { min-height: 60px; display: flex; flex-direction: column; gap: 6px; }
        .item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; cursor: grab; }
        .item.hidden-val { opacity: .5; }
        .item .handle { color: #a0aec0; cursor: grab; }
        .item .val { flex: 1; word-break: break-word; }
        .item label { font-size: 12px; color: #718096; display: flex; align-items: center; gap: 4px; cursor: pointer; white-space: nowrap; }
        .sortable-ghost { opacity: .4; }
        .actions { margin-top: 18px; display: flex; gap: 12px; align-items: center; }
        .btn { padding: 10px 20px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; }
        .btn-primary { background: #17a2b8; color: #fff; }
        .msg { padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 14px; }
        .msg.success { background: #e6f7ed; color: #1a7f4b; border: 1px solid #b7e4c7; }
        .msg.error { background: #fdecea; color: #a12a1c; border: 1px solid #f5b7ae; }
        .empty-hint { color: #a0aec0; font-size: 13px; font-style: italic; padding: 8px; }
    </style>
</head>
<body>
    <div class="topbar">
        <h1>Field Organizer</h1>
        <a href="../">&larr; Back to roster</a>
        <a href="users.php">User management</a>
    </div>
    <div class="wrap">
        <p class="intro">
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
                                <h3><?php echo htmlspecialchars($gLabel); ?></h3>
                                <div class="list" data-group="<?php echo htmlspecialchars($gKey); ?>" data-field="<?php echo htmlspecialchars($key); ?>">
                                    <?php
                                    $any = false;
                                    foreach ($organized[$key] as $item):
                                        if ($item['group'] !== $gKey) continue;
                                        $any = true;
                                    ?>
                                        <div class="item <?php echo !empty($item['hidden']) ? 'hidden-val' : ''; ?>" data-value="<?php echo htmlspecialchars($item['value']); ?>">
                                            <span class="handle">⠿</span>
                                            <span class="val"><?php echo htmlspecialchars($item['value']); ?></span>
                                            <label><input type="checkbox" class="hide-toggle" <?php echo !empty($item['hidden']) ? 'checked' : ''; ?>> Hide</label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (!$any): ?><div class="empty-hint">Drag values here</div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Save <?php echo htmlspecialchars($def['label']); ?></button>
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

            // Serialize DOM order + group + hidden into items_json on submit
            form.addEventListener('submit', () => {
                const items = [];
                form.querySelectorAll('.list').forEach(list => {
                    const group = list.dataset.group;
                    list.querySelectorAll('.item').forEach(item => {
                        items.push({
                            value: item.dataset.value,
                            group: group,
                            hidden: item.querySelector('.hide-toggle').checked,
                        });
                    });
                });
                form.querySelector('input[name="items_json"]').value = JSON.stringify(items);
            });
        });
    </script>
</body>
</html>
