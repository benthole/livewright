<?php
/**
 * Scholarship Admin - Application List
 */
require_once __DIR__ . '/../config.php';
require_auth('login.php');

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $app_id = (int)$_POST['app_id'];
        $new_status = $_POST['status'];
        $valid_statuses = ['pending', 'under_review', 'approved', 'denied'];

        if ($app_id > 0 && in_array($new_status, $valid_statuses)) {
            $user = get_logged_in_user();
            $stmt = $pdo->prepare("
                UPDATE scholarship_applications
                SET status = ?, reviewed_by = ?, reviewed_at = NOW()
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$new_status, $user['name'], $app_id]);
            $success_msg = 'Status updated successfully.';
        }
    }
}

// Filters
$filter_status = $_GET['status'] ?? '';
$filter_type = $_GET['type'] ?? '';

$where = ['deleted_at IS NULL'];
$params = [];

if ($filter_status && in_array($filter_status, ['pending', 'under_review', 'approved', 'denied'])) {
    $where[] = 'status = ?';
    $params[] = $filter_status;
}

if ($filter_type && in_array($filter_type, ['mission_discount', 'need_scholarship'])) {
    $where[] = 'application_type = ?';
    $params[] = $filter_type;
}

$sql = "SELECT * FROM scholarship_applications WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll();

// Counts for filter badges
$countStmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM scholarship_applications WHERE deleted_at IS NULL GROUP BY status");
$counts = [];
while ($row = $countStmt->fetch()) {
    $counts[$row['status']] = $row['cnt'];
}
$total = array_sum($counts);

$page_title = 'Scholarship Applications';
include 'includes/header.php';
?>

<div class="admin-content">
    <div class="page-header">
        <h1>Scholarship Applications</h1>
        <span class="text-muted"><?= $total ?> total application<?= $total !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (!empty($success_msg)): ?>
        <div class="alert-success"><?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>

    <div class="filters">
        <strong>Filter:</strong>
        <a href="index.php" class="btn btn-small <?= !$filter_status && !$filter_type ? '' : 'btn-secondary' ?>">
            All (<?= $total ?>)
        </a>
        <a href="?status=pending" class="btn btn-small <?= $filter_status === 'pending' ? '' : 'btn-secondary' ?>">
            Pending (<?= $counts['pending'] ?? 0 ?>)
        </a>
        <a href="?status=under_review" class="btn btn-small <?= $filter_status === 'under_review' ? '' : 'btn-secondary' ?>">
            Under Review (<?= $counts['under_review'] ?? 0 ?>)
        </a>
        <a href="?status=approved" class="btn btn-small <?= $filter_status === 'approved' ? 'btn-success' : 'btn-secondary' ?>">
            Approved (<?= $counts['approved'] ?? 0 ?>)
        </a>
        <a href="?status=denied" class="btn btn-small <?= $filter_status === 'denied' ? 'btn-danger' : 'btn-secondary' ?>">
            Denied (<?= $counts['denied'] ?? 0 ?>)
        </a>

        <span style="margin-left: 15px;">Type:</span>
        <a href="?type=mission_discount" class="btn btn-small <?= $filter_type === 'mission_discount' ? '' : 'btn-secondary' ?>">Mission</a>
        <a href="?type=need_scholarship" class="btn btn-small <?= $filter_type === 'need_scholarship' ? '' : 'btn-secondary' ?>">Need-Based</a>
    </div>

    <?php if (empty($applications)): ?>
        <p class="text-muted" style="text-align:center; padding:40px;">No applications found.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $app): ?>
                <tr>
                    <td><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></td>
                    <td><a href="mailto:<?= htmlspecialchars($app['email']) ?>"><?= htmlspecialchars($app['email']) ?></a></td>
                    <td>
                        <?php if ($app['application_type'] === 'mission_discount'): ?>
                            <span class="badge-type badge-mission">Mission</span>
                        <?php else: ?>
                            <span class="badge-type badge-need">Need-Based</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="app_id" value="<?= $app['id'] ?>">
                            <?= csrf_field() ?>
                            <select name="status" onchange="this.form.submit()" class="form-select form-select-sm" style="width:auto; display:inline-block;">
                                <option value="pending" <?= $app['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="under_review" <?= $app['status'] === 'under_review' ? 'selected' : '' ?>>Under Review</option>
                                <option value="approved" <?= $app['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                                <option value="denied" <?= $app['status'] === 'denied' ? 'selected' : '' ?>>Denied</option>
                            </select>
                        </form>
                    </td>
                    <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($app['created_at'])) ?></td>
                    <td class="actions">
                        <a href="view.php?id=<?= urlencode($app['unique_id']) ?>" class="btn btn-small btn-info">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
