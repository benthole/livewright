<?php
if (isset($_GET['RedirectTo'])) {
    $redirectUrl = urldecode($_GET['RedirectTo']);
    header('Location: ' . $redirectUrl);
    exit;
}

// If no redirect parameter, show error
http_response_code(400);
echo 'Missing RedirectTo parameter';
