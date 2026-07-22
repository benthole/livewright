<?php
/**
 * Login Page
 *
 * Handles user authentication for the LiveWright Roster application.
 */

require_once('includes/auth.php');
require_once(__DIR__ . '/includes/ui.php');

$error = '';
$success = '';

// Check for error/success messages from URL
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'session_expired':
            $error = 'Your session has expired. Please log in again.';
            break;
        case 'logged_out':
            $success = 'You have been logged out successfully.';
            break;
    }
}

// If already logged in, redirect to roster
if (is_logged_in()) {
    header('Location: ./');
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } else {
            $result = login($email, $password);

            if ($result['success']) {
                // Redirect to original destination or roster
                $redirect = isset($_SESSION['redirect_after_login']) ? $_SESSION['redirect_after_login'] : './';
                unset($_SESSION['redirect_after_login']);
                header('Location: ' . $redirect);
                exit;
            } else {
                $error = $result['error'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - LiveWright Roster</title>
    <?php roster_ui_styles(); ?>
    <style>
        /* ---- Login page: full-viewport centered card on the workbench canvas ---- */
        body.login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            box-shadow: var(--shadow-md);
            width: 100%;
            max-width: 400px;
            overflow: hidden;
        }

        .login-header {
            padding: 32px 32px 24px;
            background: var(--surface-sunk);
            border-bottom: 1px solid var(--line);
        }

        .login-header .login-mark {
            display: block;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.02em;
            color: var(--accent-ink);
            margin-bottom: 6px;
        }

        .login-header h1 {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.01em;
            color: var(--ink);
            margin: 0 0 6px;
        }

        .login-header p {
            font-size: 14px;
            color: var(--ink-soft);
            margin: 0;
        }

        .login-body {
            padding: 32px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
        }

        .form-group input {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            background: var(--surface);
            color: var(--ink);
            font-family: inherit;
            font-size: 15px;
            transition: border-color var(--dur) var(--ease), box-shadow var(--dur) var(--ease);
        }

        .form-group input::placeholder {
            color: var(--ink-faint);
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgb(from var(--focus) r g b / 0.20);
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 60px;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 8px;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--accent-ink);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: var(--r-sm);
            transition: background var(--dur) var(--ease);
        }

        .password-toggle:hover {
            background: var(--surface-sunk);
        }

        .password-toggle:focus-visible {
            outline: 2px solid var(--focus);
            outline-offset: 1px;
        }

        .btn-login {
            width: 100%;
            padding: 12px 16px;
            background: var(--accent);
            color: oklch(0.99 0.003 85);
            border: 1px solid transparent;
            border-radius: var(--r-sm);
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background var(--dur) var(--ease);
        }

        .btn-login:hover {
            background: var(--accent-hover);
        }

        .alert {
            padding: 11px 14px;
            border-radius: var(--r-sm);
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background: var(--danger-bg);
            color: var(--danger-ink);
            border: 1px solid var(--line);
        }

        .alert-success {
            background: var(--ok-bg);
            color: var(--ok);
            border: 1px solid var(--line);
        }

        .login-footer {
            text-align: center;
            padding: 20px 32px 28px;
            color: var(--ink-faint);
            font-size: 13px;
            border-top: 1px solid var(--line);
        }
    </style>
</head>
<body class="rui login-page">
    <div class="login-card">
        <div class="login-header">
            <span class="login-mark">LiveWright</span>
            <h1>Roster</h1>
            <p>Sign in to access the roster</p>
        </div>

        <div class="login-body">
            <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <?php echo csrf_field(); ?>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required autofocus
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                           placeholder="you@example.com">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" required
                               placeholder="Enter your password">
                        <button type="button" class="password-toggle" id="password-toggle"
                                aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                </div>

                <button type="submit" class="btn-login">Sign In</button>
            </form>
        </div>

        <div class="login-footer">
            Contact your administrator if you need access.
        </div>
    </div>
    <script>
        (function () {
            var toggle = document.getElementById('password-toggle');
            var input = document.getElementById('password');
            if (!toggle || !input) return;
            toggle.addEventListener('click', function () {
                var shown = input.type === 'text';
                input.type = shown ? 'password' : 'text';
                toggle.textContent = shown ? 'Show' : 'Hide';
                toggle.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
                toggle.setAttribute('aria-pressed', shown ? 'false' : 'true');
            });
        })();
    </script>
</body>
</html>
