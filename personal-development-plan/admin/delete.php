<?php
// delete.php — soft-delete a contract with a confirmation step
require_once '../config.php';
requireLogin();

$contract_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$contract_id) {
    header('Location: index.php');
    exit;
}

// Load contract (must not already be deleted)
$stmt = $pdo->prepare("SELECT id, first_name, last_name, email, created_at FROM contracts WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$contract_id]);
$contract = $stmt->fetch();

if (!$contract) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE contracts SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$contract_id]);

            $stmt = $pdo->prepare("UPDATE pricing_options SET deleted_at = NOW() WHERE contract_id = ?");
            $stmt->execute([$contract_id]);

            $pdo->commit();
            header('Location: index.php?deleted=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollback();
            $error = 'Failed to delete: ' . $e->getMessage();
        }
    }
}

$page_title = 'Delete Plan';
require_once 'includes/header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1>Delete Plan</h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card" style="max-width: 600px; margin-top: 20px;">
        <div class="card-body">
            <h5 class="card-title">Are you sure you want to delete this plan?</h5>
            <p class="card-text text-muted">
                This plan will be moved to the Trash. You can restore it later from the Trash page.
            </p>

            <table class="table table-sm" style="margin-top: 20px;">
                <tr>
                    <th style="width: 140px;">Name:</th>
                    <td><?= htmlspecialchars($contract['first_name'] . ' ' . $contract['last_name']) ?></td>
                </tr>
                <tr>
                    <th>Email:</th>
                    <td><?= htmlspecialchars($contract['email']) ?></td>
                </tr>
                <tr>
                    <th>Created:</th>
                    <td><?= date('F j, Y', strtotime($contract['created_at'])) ?></td>
                </tr>
            </table>

            <form method="POST" action="delete.php" style="margin-top: 20px;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$contract_id ?>">
                <button type="submit" class="btn btn-danger">Yes, delete this plan</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
