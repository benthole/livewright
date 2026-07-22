<?php
/**
 * User Management Panel (Admin Only)
 *
 * Allows administrators to create, edit, and manage user accounts.
 */

require_once('../includes/auth.php');
require_once(__DIR__ . '/../includes/ui.php');

// Require admin access
require_auth();
if (!is_admin()) {
    header('Location: ../');
    exit;
}

$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $message = 'Invalid form submission. Please try again.';
        $messageType = 'error';
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';

        switch ($action) {
            case 'create':
                $result = create_user(
                    $_POST['email'] ?? '',
                    $_POST['password'] ?? '',
                    $_POST['name'] ?? '',
                    $_POST['role'] ?? ROLE_VIEWER
                );
                if ($result['success']) {
                    $message = 'User created successfully.';
                    $messageType = 'success';
                } else {
                    $message = $result['error'];
                    $messageType = 'error';
                }
                break;

            case 'update':
                $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
                $data = [
                    'email' => $_POST['email'] ?? '',
                    'name' => $_POST['name'] ?? '',
                    'role' => $_POST['role'] ?? ROLE_VIEWER,
                    'is_active' => isset($_POST['is_active']) ? 1 : 0
                ];
                $result = update_user($user_id, $data);
                if ($result['success']) {
                    $message = 'User updated successfully.';
                    $messageType = 'success';
                } else {
                    $message = $result['error'];
                    $messageType = 'error';
                }
                break;

            case 'reset_password':
                $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
                $new_password = $_POST['new_password'] ?? '';
                $result = update_password($user_id, $new_password);
                if ($result['success']) {
                    $message = 'Password reset successfully.';
                    $messageType = 'success';
                } else {
                    $message = $result['error'];
                    $messageType = 'error';
                }
                break;

            case 'delete':
                $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
                $result = delete_user($user_id);
                if ($result['success']) {
                    $message = 'User deleted successfully.';
                    $messageType = 'success';
                } else {
                    $message = $result['error'];
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Get all users
$users = get_all_users();
$current_user = get_logged_in_user();

// Check if editing a specific user
$editing_user = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editing_user = get_user_by_id((int)$_GET['edit']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - LiveWright Roster</title>
    <?php roster_ui_styles(); ?>
    <style>
        .container {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 20px 40px;
        }

        /* buttons — align local classes to the shared token palette */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 9px 16px;
            border: 1px solid transparent;
            border-radius: var(--r-sm);
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            line-height: 1;
            cursor: pointer;
            text-decoration: none;
            transition: background var(--dur) var(--ease), border-color var(--dur) var(--ease), color var(--dur) var(--ease);
        }

        .btn-primary {
            background: var(--accent);
            color: oklch(0.99 0.003 85);
        }

        .btn-primary:hover {
            background: var(--accent-hover);
        }

        .btn-secondary {
            background: var(--surface);
            color: var(--ink);
            border-color: var(--line-strong);
        }

        .btn-secondary:hover {
            background: var(--surface-sunk);
        }

        .btn-danger {
            background: var(--danger);
            color: oklch(0.99 0.003 85);
        }

        .btn-danger:hover {
            background: var(--danger-hover);
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-md);
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid var(--line);
        }

        .card-header h2 {
            color: var(--ink);
            font-size: 16px;
            font-weight: 600;
        }

        .card-body {
            padding: 20px;
        }

        .alert {
            padding: 12px 15px;
            border-radius: var(--r-sm);
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background: var(--danger-bg);
            color: var(--danger-ink);
            border: 1px solid var(--danger);
        }

        .alert-success {
            background: var(--ok-bg);
            color: var(--ok);
            border: 1px solid var(--ok);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 600;
            color: var(--ink);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            background: var(--surface);
            color: var(--ink);
            font-family: inherit;
            font-size: 14px;
            transition: border-color var(--dur) var(--ease), box-shadow var(--dur) var(--ease);
        }

        .form-group input::placeholder { color: var(--ink-faint); }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgb(from var(--focus) r g b / 0.20);
        }

        .form-group .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .form-group input[type="checkbox"] {
            width: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 11px 16px;
            text-align: left;
            border-bottom: 1px solid var(--line);
        }

        th {
            background: var(--surface-sunk);
            font-weight: 600;
            color: var(--ink-soft);
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid var(--line-strong);
        }

        td {
            font-size: 14px;
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }

        tbody tr:nth-child(even) {
            background: var(--surface-sunk);
        }

        tbody tr:hover {
            background: var(--accent-weak);
        }

        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: var(--r-sm);
            font-size: 12px;
            font-weight: 600;
        }

        .badge-viewer {
            background: var(--tag-neutral-bg);
            color: var(--ink-soft);
        }

        .badge-editor {
            background: var(--tag-group-bg);
            color: var(--tag-group-ink);
        }

        .badge-admin {
            background: var(--accent-weak);
            color: var(--accent-ink);
        }

        .badge-active {
            background: var(--ok-bg);
            color: var(--ok);
        }

        .badge-inactive {
            background: var(--tag-neutral-bg);
            color: var(--ink-faint);
        }

        .actions {
            display: flex;
            gap: 8px;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgb(from var(--ink) r g b / 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal {
            background: var(--surface);
            border-radius: var(--r-md);
            box-shadow: var(--shadow-md);
            padding: 28px;
            max-width: 450px;
            width: 90%;
        }

        .modal h3 {
            margin-bottom: 15px;
            font-size: 16px;
            font-weight: 600;
            color: var(--ink);
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .user-info {
            font-size: 12px;
            color: var(--ink-faint);
        }
    </style>
</head>
<body class="rui">
    <?php roster_ui_topbar(['base' => '../', 'active' => 'users', 'page_title' => 'Manage Users', 'user' => $current_user, 'is_admin' => true]); ?>
    <div class="container">
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- Create/Edit User Form -->
        <div class="card">
            <div class="card-header">
                <h2><?php echo $editing_user ? 'Edit User' : 'Create New User'; ?></h2>
            </div>
            <div class="card-body">
                <form method="POST" action="users.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo $editing_user ? 'update' : 'create'; ?>">
                    <?php if ($editing_user): ?>
                    <input type="hidden" name="user_id" value="<?php echo $editing_user['id']; ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Full Name</label>
                            <input type="text" id="name" name="name" required
                                   value="<?php echo $editing_user ? htmlspecialchars($editing_user['name']) : ''; ?>"
                                   placeholder="John Doe">
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" required
                                   value="<?php echo $editing_user ? htmlspecialchars($editing_user['email']) : ''; ?>"
                                   placeholder="john@example.com">
                        </div>
                    </div>

                    <div class="form-row">
                        <?php if (!$editing_user): ?>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required minlength="8"
                                   placeholder="Minimum 8 characters">
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="role">Role</label>
                            <select id="role" name="role" required>
                                <option value="viewer" <?php echo ($editing_user && $editing_user['role'] === 'viewer') ? 'selected' : ''; ?>>Viewer - Can only view roster</option>
                                <option value="editor" <?php echo ($editing_user && $editing_user['role'] === 'editor') ? 'selected' : ''; ?>>Editor - Can view and edit roster</option>
                                <option value="admin" <?php echo ($editing_user && $editing_user['role'] === 'admin') ? 'selected' : ''; ?>>Admin - Full access including user management</option>
                            </select>
                        </div>

                        <?php if ($editing_user): ?>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active" <?php echo $editing_user['is_active'] ? 'checked' : ''; ?>>
                                Active (can log in)
                            </label>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary">
                            <?php echo $editing_user ? 'Update User' : 'Create User'; ?>
                        </button>
                        <?php if ($editing_user): ?>
                        <a href="users.php" class="btn btn-secondary">Cancel</a>
                        <button type="button" class="btn btn-secondary" onclick="showResetPasswordModal(<?php echo $editing_user['id']; ?>, '<?php echo htmlspecialchars($editing_user['name'], ENT_QUOTES); ?>')">
                            Reset Password
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Users List -->
        <div class="card">
            <div class="card-header">
                <h2>All Users</h2>
            </div>
            <div class="card-body" style="padding: 0;">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($user['name']); ?>
                                <?php if ($user['id'] == $current_user['id']): ?>
                                <span class="user-info">(you)</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $user['role']; ?>">
                                    <?php echo ucfirst($user['role']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                if ($user['last_login_at']) {
                                    echo date('M j, Y g:i A', strtotime($user['last_login_at']));
                                } else {
                                    echo '<span class="user-info">Never</span>';
                                }
                                ?>
                            </td>
                            <td class="actions">
                                <a href="users.php?edit=<?php echo $user['id']; ?>" class="btn btn-secondary btn-small">Edit</a>
                                <?php if ($user['id'] != $current_user['id']): ?>
                                <button type="button" class="btn btn-danger btn-small" onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>')">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <h3>Delete User</h3>
            <p>Are you sure you want to delete <strong id="deleteUserName"></strong>? This action cannot be undone.</p>
            <form method="POST" action="users.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" id="deleteUserId">
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal-overlay" id="resetPasswordModal">
        <div class="modal">
            <h3>Reset Password</h3>
            <p>Enter a new password for <strong id="resetPasswordUserName"></strong>:</p>
            <form method="POST" action="users.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetPasswordUserId">
                <div class="form-group" style="margin-top: 15px;">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8" placeholder="Minimum 8 characters">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeResetPasswordModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Reset Password</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function confirmDelete(userId, userName) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserName').textContent = userName;
            document.getElementById('deleteModal').classList.add('show');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
        }

        function showResetPasswordModal(userId, userName) {
            document.getElementById('resetPasswordUserId').value = userId;
            document.getElementById('resetPasswordUserName').textContent = userName;
            document.getElementById('resetPasswordModal').classList.add('show');
        }

        function closeResetPasswordModal() {
            document.getElementById('resetPasswordModal').classList.remove('show');
            document.getElementById('new_password').value = '';
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                }
            });
        });
    </script>
    <?php roster_ui_menu_js(); ?>
</body>
</html>
