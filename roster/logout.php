<?php
/**
 * Logout Handler
 *
 * Logs out the current user and redirects to login page.
 */

require_once('includes/auth.php');

logout();

header('Location: login.php?error=logged_out');
exit;
