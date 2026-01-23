<?php
require_once '../config.php';
requireLogin();

// Get all presets
$stmt = $pdo->prepare("SELECT * FROM pdp_presets ORDER BY name");
$stmt->execute();
$presets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Presets</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; max-width: 1000px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .btn { padding: 8px 16px; background: #007cba; color: white; text-decoration: none; border-radius: 4px; display: inline-block; margin: 2px; }
        .btn-danger { background: #dc3545; }
        .btn-secondary { background: #6c757d; }
        .btn-success { background: #28a745; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #f5f5f5; }
        .actions { white-space: nowrap; }
        .description-preview { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Manage Plan Presets</h1>
        <div>
            <a href="preset-edit.php" class="btn btn-success">Add New Preset</a>
            <a href="index.php" class="btn btn-secondary">Back to Plans</a>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Included in Each Option</th>
                <th>Options Configured</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($presets)): ?>
                <tr>
                    <td colspan="4" style="text-align: center;">No presets found</td>
                </tr>
            <?php endif; ?>
            
            <?php foreach ($presets as $preset): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($preset['name']) ?></strong></td>
                    <td class="description-preview" title="<?= htmlspecialchars(strip_tags($preset['contract_description'])) ?>">
                        <?= htmlspecialchars(substr(strip_tags($preset['contract_description']), 0, 50)) ?>...
                    </td>
                    <td>
                        <?php
                        $option_count = 0;
                        for ($i = 1; $i <= 3; $i++) {
                            if (!empty($preset["option_{$i}_desc"])) {
                                $option_count++;
                            }
                        }
                        echo $option_count . " option" . ($option_count != 1 ? "s" : "");
                        ?>
                    </td>
                    <td class="actions">
                        <a href="preset-edit.php?id=<?= $preset['id'] ?>" class="btn">Edit</a>
                        <a href="preset-delete.php?id=<?= $preset['id'] ?>" class="btn btn-danger" onclick="return confirm('Delete this preset?')">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>