<?php
/**
 * User Management Panel (Admin Only)
 *
 * Allows administrators to create, edit, and manage user accounts.
 */

require_once('../includes/auth.php');

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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #2c3e50;
            font-size: 28px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-secondary {
            background: #ecf0f1;
            color: #2c3e50;
        }

        .btn-secondary:hover {
            background: #d5dbdb;
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }

        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid #ecf0f1;
        }

        .card-header h2 {
            color: #2c3e50;
            font-size: 18px;
        }

        .card-body {
            padding: 20px;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background: #fdeaea;
            color: #c0392b;
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
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
            color: #2c3e50;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #ecf0f1;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3498db;
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
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            font-size: 14px;
            color: #2c3e50;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-viewer {
            background: #ecf0f1;
            color: #7f8c8d;
        }

        .badge-editor {
            background: #d4edda;
            color: #155724;
        }

        .badge-admin {
            background: #cce5ff;
            color: #004085;
        }

        .badge-active {
            background: #d4edda;
            color: #155724;
        }

        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
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
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal {
            background: white;
            border-radius: 8px;
            padding: 25px;
            max-width: 450px;
            width: 90%;
        }

        .modal h3 {
            margin-bottom: 15px;
            color: #2c3e50;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .user-info {
            font-size: 12px;
            color: #7f8c8d;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>User Management</h1>
            <div class="header-actions">
                <a href="../" class="btn btn-secondary">Back to Roster</a>
                <a href="../logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>

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
</body>
</html>
