<?php
// trash.php — list soft-deleted plans with Restore / Delete Permanently actions
require_once '../config.php';
requireLogin();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $contract_id = (int)($_POST['id'] ?? 0);

        if (!$contract_id) {
            $error = 'Missing plan id.';
        } elseif ($action === 'restore') {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE contracts SET deleted_at = NULL WHERE id = ?");
                $stmt->execute([$contract_id]);
                $stmt = $pdo->prepare("UPDATE pricing_options SET deleted_at = NULL WHERE contract_id = ?");
                $stmt->execute([$contract_id]);
                $pdo->commit();
                $message = 'Plan restored.';
            } catch (Exception $e) {
                $pdo->rollback();
                $error = 'Failed to restore: ' . $e->getMessage();
            }
        } elseif ($action === 'purge') {
            // Hard delete — only allowed from trash
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("DELETE FROM pricing_options WHERE contract_id = ?");
                $stmt->execute([$contract_id]);
                $stmt = $pdo->prepare("DELETE FROM contracts WHERE id = ? AND deleted_at IS NOT NULL");
                $stmt->execute([$contract_id]);
                $pdo->commit();
                $message = 'Plan permanently deleted.';
            } catch (Exception $e) {
                $pdo->rollback();
                $error = 'Failed to permanently delete: ' . $e->getMessage();
            }
        }
    }
}

$stmt = $pdo->prepare("
    SELECT id, unique_id, first_name, last_name, email, created_at, deleted_at
    FROM contracts
    WHERE deleted_at IS NOT NULL
    ORDER BY deleted_at DESC
");
$stmt->execute();
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Trash';
require_once 'includes/header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1>Trash</h1>
        <div>
            <a href="index.php" class="btn">Back to Dashboard</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <p class="text-muted" style="margin-bottom: 15px;">
        Plans in the Trash are hidden from the dashboard. You can restore them or delete them permanently.
    </p>

    <table>
        <thead>
            <tr>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Email</th>
                <th>Created</th>
                <th>Deleted</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($contracts)): ?>
                <tr>
                    <td colspan="6" style="text-align: center;">Trash is empty.</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($contracts as $contract): ?>
                <tr>
                    <td><?= htmlspecialchars($contract['first_name']) ?></td>
                    <td><?= htmlspecialchars($contract['last_name']) ?></td>
                    <td><?= htmlspecialchars($contract['email']) ?></td>
                    <td class="date-cell"><?= date('M j, Y', strtotime($contract['created_at'])) ?></td>
                    <td class="date-cell"><?= date('M j, Y g:ia', strtotime($contract['deleted_at'])) ?></td>
                    <td class="actions">
                        <form method="POST" action="trash.php" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$contract['id'] ?>">
                            <input type="hidden" name="action" value="restore">
                            <button type="submit" class="btn">Restore</button>
                        </form>
                        <form method="POST" action="trash.php" style="display:inline;"
                              onsubmit="return confirm('Permanently delete this plan? This cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$contract['id'] ?>">
                            <input type="hidden" name="action" value="purge">
                            <button type="submit" class="btn btn-danger">Delete Permanently</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once 'includes/footer.php'; ?>
