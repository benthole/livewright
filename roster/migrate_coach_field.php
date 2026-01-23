<?php
// migrate_coach_field.php - Migrate individual coach values from old field (47) to new field (137)

require_once('includes/auth.php');
require_once('keap_api.php');
require_once('config.php');

// Require admin permissions
require_admin();

$message = '';
$error = '';
$preview = [];
$migrated = [];

// Connect to database
try {
    $conn = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $error = 'Database connection failed: ' . $e->getMessage();
}

// Get all roster entries
$contacts = [];
if (!$error) {
    try {
        $stmt = $conn->query("SELECT id, email, data FROM roster");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data = json_decode($row['data'], true);
            if ($data) {
                $oldValue = '';
                $newValue = '';

                // Find old and new field values
                if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
                    foreach ($data['custom_fields'] as $field) {
                        if (isset($field['id'])) {
                            if ((int)$field['id'] === $individual_coach_field_id_old) {
                                $oldValue = isset($field['content']) ? trim($field['content']) : '';
                            }
                            if ((int)$field['id'] === $individual_coach_field_id) {
                                $newValue = isset($field['content']) ? trim($field['content']) : '';
                            }
                        }
                    }
                }

                $contacts[] = [
                    'row_id' => $row['id'],
                    'keap_id' => isset($data['id']) ? $data['id'] : null,
                    'email' => $row['email'],
                    'name' => trim((isset($data['given_name']) ? $data['given_name'] : '') . ' ' . (isset($data['family_name']) ? $data['family_name'] : '')),
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'data' => $data
                ];
            }
        }
    } catch (PDOException $e) {
        $error = 'Error loading contacts: ' . $e->getMessage();
    }
}

// Build preview - contacts that need migration (have old value but no new value)
foreach ($contacts as $contact) {
    if (!empty($contact['old_value']) && empty($contact['new_value'])) {
        $preview[] = $contact;
    }
}

// Handle migration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'migrate') {
    $token = get_keap_token();

    if (!$token) {
        $error = 'Could not get Keap API token';
    } else {
        $successCount = 0;
        $failCount = 0;

        foreach ($preview as $contact) {
            $keapId = $contact['keap_id'];
            $newCoachValue = $contact['old_value'];

            if (!$keapId) {
                $failCount++;
                continue;
            }

            // Update Keap - set new field and clear old field
            $url = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$keapId}";
            $payload = [
                'custom_fields' => [
                    [
                        'id' => $individual_coach_field_id,
                        'content' => $newCoachValue
                    ],
                    [
                        'id' => $individual_coach_field_id_old,
                        'content' => ''  // Clear old field to avoid confusion
                    ]
                ]
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer $token",
                    "Content-Type: application/json"
                ]
            ]);

            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                // Update local database
                $data = $contact['data'];

                // Update or add the new field
                $found = false;
                if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
                    foreach ($data['custom_fields'] as &$field) {
                        if (isset($field['id']) && (int)$field['id'] === $individual_coach_field_id) {
                            $field['content'] = $newCoachValue;
                            $found = true;
                            break;
                        }
                    }
                    unset($field);
                }

                if (!$found) {
                    if (!isset($data['custom_fields'])) {
                        $data['custom_fields'] = [];
                    }
                    $data['custom_fields'][] = [
                        'id' => $individual_coach_field_id,
                        'content' => $newCoachValue
                    ];
                }

                // Save to database
                try {
                    $updateStmt = $conn->prepare("UPDATE roster SET data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $updateStmt->execute([json_encode($data), $contact['row_id']]);

                    $migrated[] = $contact;
                    $successCount++;
                } catch (PDOException $e) {
                    $failCount++;
                }
            } else {
                $failCount++;
            }
        }

        $message = "Migration complete: {$successCount} contacts updated, {$failCount} failed.";

        // Refresh preview
        $preview = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migrate Coach Field - LiveWright</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f6fa; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { margin-bottom: 20px; color: #2c3e50; }
        .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; }
        .btn { display: inline-block; padding: 10px 20px; border-radius: 4px; text-decoration: none; cursor: pointer; border: none; font-size: 14px; }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-secondary:hover { background: #7f8c8d; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .empty { text-align: center; padding: 40px; color: #7f8c8d; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
        .badge-old { background: #ffeaa7; color: #6c5ce7; }
        .badge-new { background: #81ecec; color: #00b894; }
        .stats { display: flex; gap: 20px; margin-bottom: 20px; }
        .stat { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); flex: 1; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #3498db; }
        .stat-label { color: #7f8c8d; margin-top: 5px; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #3498db; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <a href="./" class="back-link">&larr; Back to Roster</a>

        <h1>Migrate Individual Coach Field</h1>

        <div class="card">
            <p>This tool migrates individual coach values from the old single-value field (ID <?php echo $individual_coach_field_id_old; ?>) to the new multi-value field (ID <?php echo $individual_coach_field_id; ?>).</p>
            <p style="margin-top: 10px; color: #7f8c8d;">Only contacts that have a value in the old field but NOT in the new field will be migrated.</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat">
                <div class="stat-number"><?php echo count($contacts); ?></div>
                <div class="stat-label">Total Contacts</div>
            </div>
            <div class="stat">
                <div class="stat-number"><?php echo count($preview); ?></div>
                <div class="stat-label">Need Migration</div>
            </div>
            <div class="stat">
                <div class="stat-number"><?php echo count($migrated); ?></div>
                <div class="stat-label">Just Migrated</div>
            </div>
        </div>

        <?php if (count($preview) > 0): ?>
        <div class="card">
            <h2 style="margin-bottom: 15px;">Contacts to Migrate</h2>

            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Old Field (<?php echo $individual_coach_field_id_old; ?>)</th>
                        <th>New Field (<?php echo $individual_coach_field_id; ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview as $contact): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($contact['name']); ?></td>
                        <td><?php echo htmlspecialchars($contact['email']); ?></td>
                        <td><span class="badge badge-old"><?php echo htmlspecialchars($contact['old_value']); ?></span></td>
                        <td><span style="color: #95a5a6;">(empty)</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="POST" style="margin-top: 20px;">
                <input type="hidden" name="action" value="migrate">
                <button type="submit" class="btn btn-primary" onclick="return confirm('This will update <?php echo count($preview); ?> contacts in Keap. Continue?');">
                    Migrate <?php echo count($preview); ?> Contact(s)
                </button>
            </form>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="empty">
                <p>No contacts need migration.</p>
                <p style="margin-top: 10px;">All contacts either have a value in the new field or have no value in either field.</p>
            </div>
        </div>
        <?php endif; ?>

        <?php if (count($migrated) > 0): ?>
        <div class="card">
            <h2 style="margin-bottom: 15px;">Successfully Migrated</h2>

            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Value Copied</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($migrated as $contact): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($contact['name']); ?></td>
                        <td><?php echo htmlspecialchars($contact['email']); ?></td>
                        <td><span class="badge badge-new"><?php echo htmlspecialchars($contact['old_value']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
