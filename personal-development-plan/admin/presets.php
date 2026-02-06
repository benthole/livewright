<?php
require_once '../config.php';
requireLogin();

// Get all presets
$stmt = $pdo->prepare("SELECT * FROM pdp_presets ORDER BY name");
$stmt->execute();
$presets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Manage Presets';
require_once 'includes/header.php';
?>

    <div class="admin-content">
        <div class="page-header">
            <h1>Manage Plan Presets</h1>
            <div>
                <a href="preset-edit.php" class="btn btn-success">Add New Preset</a>
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
    </div>

<?php require_once 'includes/footer.php'; ?>
