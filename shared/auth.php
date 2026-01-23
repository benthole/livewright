<?php
/**
 * Shared Authentication Functions
 *
 * Core authentication library for LiveWright applications.
 * Handles user authentication, session management, and permission checks.
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Secure session configuration
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

require_once(__DIR__ . '/config.php');

// Role constants
if (!defined('ROLE_VIEWER')) define('ROLE_VIEWER', 'viewer');
if (!defined('ROLE_EDITOR')) define('ROLE_EDITOR', 'editor');
if (!defined('ROLE_ADMIN')) define('ROLE_ADMIN', 'admin');

// Role hierarchy (higher index = more permissions)
$ROLE_HIERARCHY = [
    ROLE_VIEWER => 1,
    ROLE_EDITOR => 2,
    ROLE_ADMIN => 3
];

/**
 * Get database connection for auth operations
 */
function get_auth_db() {
    global $db_host_lw, $db_name_lw, $db_user_lw, $db_pass_lw;

    static $conn = null;

    if ($conn === null) {
        try {
            $conn = new PDO(
                "mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4",
                $db_user_lw,
                $db_pass_lw,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            error_log('Auth DB connection failed: ' . $e->getMessage());
            return null;
        }
    }

    return $conn;
}

/**
 * Check if user is currently logged in
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get the currently logged-in user
 */
function get_logged_in_user() {
    if (!is_logged_in()) {
        return null;
    }

    // Cache user data in session to avoid repeated DB queries
    if (!isset($_SESSION['user_data']) || $_SESSION['user_data']['id'] !== $_SESSION['user_id']) {
        $conn = get_auth_db();
        if (!$conn) return null;

        $stmt = $conn->prepare("SELECT id, email, name, role, is_active FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            // User no longer exists or is inactive - log them out
            logout();
            return null;
        }

        $_SESSION['user_data'] = $user;
    }

    return $_SESSION['user_data'];
}

/**
 * Get the current user's role
 */
function get_user_role() {
    $user = get_logged_in_user();
    return $user ? $user['role'] : null;
}

/**
 * Check if user has at least the specified role level
 */
function has_role($required_role) {
    global $ROLE_HIERARCHY;

    $user_role = get_user_role();
    if (!$user_role) return false;

    $user_level = isset($ROLE_HIERARCHY[$user_role]) ? $ROLE_HIERARCHY[$user_role] : 0;
    $required_level = isset($ROLE_HIERARCHY[$required_role]) ? $ROLE_HIERARCHY[$required_role] : 999;

    return $user_level >= $required_level;
}

/**
 * Check if user can view (any authenticated, active user)
 */
function can_view() {
    return is_logged_in() && get_logged_in_user() !== null;
}

/**
 * Check if user can edit (editor or admin)
 */
function can_edit() {
    return has_role(ROLE_EDITOR);
}

/**
 * Check if user is admin
 */
function is_admin() {
    return has_role(ROLE_ADMIN);
}

/**
 * Attempt to log in a user
 *
 * @param string $email User's email
 * @param string $password User's password
 * @return array ['success' => bool, 'error' => string|null, 'user' => array|null]
 */
function login($email, $password) {
    $conn = get_auth_db();
    if (!$conn) {
        return ['success' => false, 'error' => 'Database connection failed'];
    }

    $email = trim(strtolower($email));

    // Check for rate limiting
    if (is_rate_limited($email)) {
        return ['success' => false, 'error' => 'Too many login attempts. Please wait 15 minutes.'];
    }

    // Find user by email
    $stmt = $conn->prepare("SELECT id, email, password_hash, name, role, is_active FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Log the attempt
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (!$user) {
        log_login_attempt($email, $ip, false);
        return ['success' => false, 'error' => 'Invalid email or password'];
    }

    // Check if user is active
    if (!$user['is_active']) {
        log_login_attempt($email, $ip, false);
        return ['success' => false, 'error' => 'This account has been deactivated. Please contact an administrator.'];
    }

    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        log_login_attempt($email, $ip, false);
        return ['success' => false, 'error' => 'Invalid email or password'];
    }

    // Success - create session
    log_login_attempt($email, $ip, true);

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_data'] = [
        'id' => $user['id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'role' => $user['role'],
        'is_active' => $user['is_active']
    ];
    $_SESSION['login_time'] = time();

    // Update last login timestamp
    $updateStmt = $conn->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?");
    $updateStmt->execute([$user['id']]);

    return [
        'success' => true,
        'user' => $_SESSION['user_data']
    ];
}

/**
 * Log out the current user
 */
function logout() {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Check if an email/IP is rate limited due to failed login attempts
 */
function is_rate_limited($email) {
    $conn = get_auth_db();
    if (!$conn) return false;

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Check failed attempts in last 15 minutes
    $stmt = $conn->prepare("
        SELECT COUNT(*) as attempts
        FROM login_attempts
        WHERE (email = ? OR ip_address = ?)
        AND success = 0
        AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $stmt->execute([$email, $ip]);
    $result = $stmt->fetch();

    // Allow 5 failed attempts before rate limiting
    return $result && $result['attempts'] >= 5;
}

/**
 * Log a login attempt
 */
function log_login_attempt($email, $ip, $success) {
    $conn = get_auth_db();
    if (!$conn) return;

    $stmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)");
    $stmt->execute([$email, $ip, $success ? 1 : 0]);

    // Clean up old login attempts (older than 24 hours)
    $conn->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
}

/**
 * Generate a CSRF token
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a CSRF token
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get CSRF token input field HTML
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token()) . '">';
}

/**
 * Require authentication - redirect to login if not logged in
 *
 * @param string $login_url Optional custom login URL (default: login.php)
 */
function require_auth($login_url = 'login.php') {
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . $login_url);
        exit;
    }

    // Verify user is still active
    if (get_logged_in_user() === null) {
        header('Location: ' . $login_url . '?error=session_expired');
        exit;
    }
}

/**
 * Require editor role - return 403 if not authorized
 */
function require_editor() {
    require_auth();

    if (!can_edit()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions. Editor access required.']);
        exit;
    }
}

/**
 * Require admin role - return 403 if not authorized
 */
function require_admin() {
    require_auth();

    if (!is_admin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions. Admin access required.']);
        exit;
    }
}

/**
 * Create a new user (admin only)
 */
function create_user($email, $password, $name, $role = ROLE_VIEWER) {
    $conn = get_auth_db();
    if (!$conn) {
        return ['success' => false, 'error' => 'Database connection failed'];
    }

    $email = trim(strtolower($email));

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address'];
    }

    // Validate password strength
    if (strlen($password) < 8) {
        return ['success' => false, 'error' => 'Password must be at least 8 characters'];
    }

    // Validate role
    if (!in_array($role, [ROLE_VIEWER, ROLE_EDITOR, ROLE_ADMIN])) {
        return ['success' => false, 'error' => 'Invalid role'];
    }

    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'A user with this email already exists'];
    }

    // Create user
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, ?)");
    $stmt->execute([$email, $password_hash, trim($name), $role]);

    return [
        'success' => true,
        'user_id' => $conn->lastInsertId()
    ];
}

/**
 * Update a user (admin only)
 */
function update_user($user_id, $data) {
    $conn = get_auth_db();
    if (!$conn) {
        return ['success' => false, 'error' => 'Database connection failed'];
    }

    $allowed_fields = ['email', 'name', 'role', 'is_active'];
    $updates = [];
    $params = [];

    foreach ($data as $key => $value) {
        if (in_array($key, $allowed_fields)) {
            if ($key === 'email') {
                $value = trim(strtolower($value));
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return ['success' => false, 'error' => 'Invalid email address'];
                }
                // Check if email is taken by another user
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$value, $user_id]);
                if ($stmt->fetch()) {
                    return ['success' => false, 'error' => 'Email already in use'];
                }
            }
            if ($key === 'role' && !in_array($value, [ROLE_VIEWER, ROLE_EDITOR, ROLE_ADMIN])) {
                return ['success' => false, 'error' => 'Invalid role'];
            }
            if ($key === 'is_active') {
                $value = $value ? 1 : 0;
            }
            $updates[] = "$key = ?";
            $params[] = $value;
        }
    }

    if (empty($updates)) {
        return ['success' => false, 'error' => 'No valid fields to update'];
    }

    $params[] = $user_id;
    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    // Clear cached user data if updating current user
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
        unset($_SESSION['user_data']);
    }

    return ['success' => true];
}

/**
 * Update user password
 */
function update_password($user_id, $new_password) {
    $conn = get_auth_db();
    if (!$conn) {
        return ['success' => false, 'error' => 'Database connection failed'];
    }

    if (strlen($new_password) < 8) {
        return ['success' => false, 'error' => 'Password must be at least 8 characters'];
    }

    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$password_hash, $user_id]);

    return ['success' => true];
}

/**
 * Get all users (admin only)
 */
function get_all_users() {
    $conn = get_auth_db();
    if (!$conn) return [];

    $stmt = $conn->query("SELECT id, email, name, role, is_active, last_login_at, created_at FROM users ORDER BY name ASC");
    return $stmt->fetchAll();
}

/**
 * Get a single user by ID
 */
function get_user_by_id($user_id) {
    $conn = get_auth_db();
    if (!$conn) return null;

    $stmt = $conn->prepare("SELECT id, email, name, role, is_active, last_login_at, created_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

/**
 * Delete a user (admin only)
 */
function delete_user($user_id) {
    $conn = get_auth_db();
    if (!$conn) {
        return ['success' => false, 'error' => 'Database connection failed'];
    }

    // Prevent self-deletion
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
        return ['success' => false, 'error' => 'You cannot delete your own account'];
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$user_id]);

    return ['success' => true];
}
