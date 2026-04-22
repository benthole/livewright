<?php
require_once '../config.php';

$error = '';

// If already logged in, redirect to admin
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } else {
            $result = login($email, $password);

            if ($result['success']) {
                $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
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
    <title>Admin Login - LiveWright PDP</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            background: #f5f5f7;
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #1d1d1f;
            letter-spacing: -0.01em;
        }
        .login-card {
            background: white;
            width: 100%;
            max-width: 420px;
            border-radius: 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 20px 60px rgba(0,0,0,0.08);
            padding: 40px 36px;
        }
        .brand {
            text-align: center;
            margin-bottom: 28px;
        }
        .brand h1 {
            margin: 0 0 4px;
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.02em;
            color: #1d1d1f;
        }
        .brand p {
            margin: 0;
            color: #6e6e73;
            font-size: 14px;
        }
        .form-group { margin-bottom: 16px; }
        label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 500;
            color: #3a3a3c;
        }
        input[type="email"], input[type="password"], input[type="text"] {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #e5e5ea;
            border-radius: 10px;
            font-family: inherit;
            font-size: 15px;
            color: #1d1d1f;
            background: white;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        input:focus {
            outline: none;
            border-color: #005FA3;
            box-shadow: 0 0 0 4px rgba(0,95,163,0.12);
        }
        .btn {
            width: 100%;
            padding: 12px 22px;
            background: #005FA3;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            margin-top: 8px;
            transition: background 0.15s, transform 0.05s;
        }
        .btn:hover { background: #004a82; }
        .btn:active { transform: scale(0.99); }
        .error {
            color: #8a1a10;
            background: #ffe5e3;
            border: 1px solid #ffc7c2;
            margin-bottom: 16px;
            padding: 11px 14px;
            border-radius: 10px;
            font-size: 14px;
        }
        .password-wrapper { position: relative; }
        .password-wrapper input { padding-right: 62px; }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 6px;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #005FA3;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 6px;
        }
        .password-toggle:hover { background: #f5f5f7; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand">
            <h1>LiveWright PDP</h1>
            <p>Sign in to the admin dashboard</p>
        </div>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" required>
                    <button type="button" class="password-toggle" id="password-toggle"
                            aria-label="Show password" aria-pressed="false">Show</button>
                </div>
            </div>
            <button type="submit" class="btn">Sign In</button>
        </form>
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
