<?php
require_once '../config.php';
requireLogin();

$errors = [];
$success = '';

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $coach_name = trim($_POST['coach_name'] ?? '');
        $duration_minutes = (int)($_POST['duration_minutes'] ?? 0);
        $tier = (int)($_POST['tier'] ?? 0);
        $rate_per_session = (float)($_POST['rate_per_session'] ?? 0);

        if (empty($coach_name)) $errors[] = 'Coach name is required';
        if (!in_array($duration_minutes, [30, 45, 60])) $errors[] = 'Duration must be 30, 45, or 60 minutes';
        if (!in_array($tier, [1, 3, 5, 10, 20])) $errors[] = 'Tier must be 1, 3, 5, 10, or 20 sessions';
        if ($rate_per_session <= 0) $errors[] = 'Rate must be greater than 0';

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO coaching_rates (coach_name, duration_minutes, tier, rate_per_session) VALUES (?, ?, ?, ?)");
                $stmt->execute([$coach_name, $duration_minutes, $tier, $rate_per_session]);
                $success = 'Rate added successfully';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $errors[] = 'This rate already exists for this coach/duration/tier combination';
                } else {
                    $errors[] = 'Error adding rate: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $rate_per_session = (float)($_POST['rate_per_session'] ?? 0);

        if ($id <= 0) $errors[] = 'Invalid rate ID';
        if ($rate_per_session <= 0) $errors[] = 'Rate must be greater than 0';

        if (empty($errors)) {
            $stmt = $pdo->prepare("UPDATE coaching_rates SET rate_per_session = ? WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$rate_per_session, $id]);
            $success = 'Rate updated successfully';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE coaching_rates SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            $success = 'Rate deleted successfully';
        }
    } elseif ($action === 'add_coach') {
        $coach_name = trim($_POST['new_coach_name'] ?? '');

        if (empty($coach_name)) {
            $errors[] = 'Coach name is required';
        } else {
            // Add all default rates for new coach (using Judith's rates as template)
            $default_rates = [
                // 60 min
                [60, 1, 750.00],
                [60, 3, 700.00],
                [60, 5, 650.00],
                [60, 10, 600.00],
                [60, 20, 550.00],
                // 45 min
                [45, 1, 560.00],
                [45, 3, 525.00],
                [45, 5, 487.50],
                [45, 10, 450.00],
                [45, 20, 412.50],
                // 30 min
                [30, 1, 375.00],
                [30, 3, 350.00],
                [30, 5, 325.00],
                [30, 10, 300.00],
                [30, 20, 275.00],
            ];

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO coaching_rates (coach_name, duration_minutes, tier, rate_per_session) VALUES (?, ?, ?, ?)");
                foreach ($default_rates as $rate) {
                    $stmt->execute([$coach_name, $rate[0], $rate[1], $rate[2]]);
                }
                $pdo->commit();
                $success = "Coach '$coach_name' added with default rates. Please update the rates as needed.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() == 23000) {
                    $errors[] = 'This coach already exists';
                } else {
                    $errors[] = 'Error adding coach: ' . $e->getMessage();
                }
            }
        }
    }
}

// Get all rates grouped by coach
$stmt = $pdo->query("
    SELECT * FROM coaching_rates
    WHERE deleted_at IS NULL
    ORDER BY coach_name, duration_minutes DESC, tier
");
$all_rates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by coach
$coaches = [];
foreach ($all_rates as $rate) {
    $coaches[$rate['coach_name']][$rate['duration_minutes']][$rate['tier']] = $rate;
}

// Get list of unique coaches for dropdown
$coach_names = array_keys($coaches);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Coaching Rates Management</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; max-width: 1200px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .btn { padding: 8px 16px; background: #007cba; color: white; text-decoration: none; border-radius: 4px; display: inline-block; margin: 2px; border: none; cursor: pointer; }
        .btn-secondary { background: #6c757d; }
        .btn-success { background: #28a745; }
        .btn-danger { background: #dc3545; }
        .btn-small { padding: 4px 8px; font-size: 0.85em; }
        .error { color: red; margin-bottom: 15px; background: #f8d7da; padding: 10px; border-radius: 4px; }
        .success { color: green; margin-bottom: 15px; font-weight: bold; background: #d4edda; padding: 10px; border-radius: 4px; }

        .coach-section { border: 1px solid #ddd; margin-bottom: 30px; border-radius: 8px; overflow: hidden; }
        .coach-header { background: #007cba; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .coach-header h2 { margin: 0; }
        .coach-body { padding: 20px; }

        .rates-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .rates-table th, .rates-table td { border: 1px solid #ddd; padding: 10px; text-align: center; }
        .rates-table th { background: #f5f5f5; }
        .rates-table .duration-header { background: #e9ecef; font-weight: bold; text-align: left; }
        .rate-input { width: 80px; padding: 5px; text-align: center; border: 1px solid #ddd; border-radius: 4px; }
        .rate-input:focus { border-color: #007cba; outline: none; }

        .add-coach-form { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
        .add-coach-form h3 { margin-top: 0; }
        .form-row { display: flex; gap: 15px; align-items: end; flex-wrap: wrap; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }

        .tier-labels th { font-size: 0.9em; color: #666; }
        .savings-row td { background: #f0fff4; color: #28a745; font-size: 0.85em; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Coaching Rates Management</h1>
        <div>
            <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <ul style="margin: 0; padding-left: 20px;">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Add New Coach Form -->
    <div class="add-coach-form">
        <h3>Add New Coach</h3>
        <form method="POST" class="form-row">
            <input type="hidden" name="action" value="add_coach">
            <div class="form-group">
                <label>Coach Name:</label>
                <input type="text" name="new_coach_name" placeholder="e.g., Sarah" required>
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-success">Add Coach with Default Rates</button>
            </div>
        </form>
    </div>

    <!-- Existing Coaches -->
    <?php foreach ($coaches as $coach_name => $durations): ?>
        <div class="coach-section">
            <div class="coach-header">
                <h2><?= htmlspecialchars($coach_name) ?></h2>
            </div>
            <div class="coach-body">
                <table class="rates-table">
                    <thead>
                        <tr class="tier-labels">
                            <th>Duration</th>
                            <th>1 Session</th>
                            <th>3 Sessions</th>
                            <th>5 Sessions</th>
                            <th>10 Sessions</th>
                            <th>20+ Sessions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ([60, 45, 30] as $duration): ?>
                            <?php if (isset($durations[$duration])): ?>
                                <tr>
                                    <td class="duration-header"><?= $duration ?> min</td>
                                    <?php foreach ([1, 3, 5, 10, 20] as $tier): ?>
                                        <td>
                                            <?php if (isset($durations[$duration][$tier])): ?>
                                                <?php $rate = $durations[$duration][$tier]; ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="update">
                                                    <input type="hidden" name="id" value="<?= $rate['id'] ?>">
                                                    <input type="number" step="0.01" name="rate_per_session"
                                                           value="<?= number_format($rate['rate_per_session'], 2, '.', '') ?>"
                                                           class="rate-input"
                                                           onchange="this.form.submit()">
                                                </form>
                                                <?php
                                                // Calculate savings vs single session
                                                if ($tier > 1 && isset($durations[$duration][1])) {
                                                    $single_rate = $durations[$duration][1]['rate_per_session'];
                                                    $savings_per_session = $single_rate - $rate['rate_per_session'];
                                                    if ($savings_per_session > 0) {
                                                        $savings_percent = round(($savings_per_session / $single_rate) * 100);
                                                        echo "<br><small style='color: #28a745;'>-{$savings_percent}%</small>";
                                                    }
                                                }
                                                ?>
                                            <?php else: ?>
                                                <span style="color: #999;">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($coaches)): ?>
        <div style="text-align: center; padding: 40px; color: #666; background: #f8f9fa; border-radius: 8px;">
            <p>No coaching rates configured yet.</p>
            <p>Add a coach above to get started.</p>
        </div>
    <?php endif; ?>
</body>
</html>
