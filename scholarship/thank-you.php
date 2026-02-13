<?php
/**
 * Scholarship Application - Thank You Page
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$submitted = isset($_SESSION['scholarship_submitted']) && $_SESSION['scholarship_submitted'] === true;
$applicant_name = isset($_SESSION['scholarship_name']) ? $_SESSION['scholarship_name'] : '';

// Clear the session flags
unset($_SESSION['scholarship_submitted']);
unset($_SESSION['scholarship_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Submitted - LiveMORE Scholarship</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f8f9fa;
            color: #333;
        }
        .thank-you-container {
            max-width: 600px;
            margin: 80px auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 50px 40px;
            text-align: center;
        }
        .checkmark {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #28a745;
            color: white;
            font-size: 40px;
            line-height: 80px;
            margin: 0 auto 25px;
        }
        h1 { color: #007cba; margin-bottom: 15px; }
        p { color: #555; font-size: 1.1em; line-height: 1.6; }
    </style>
</head>
<body>

<div class="thank-you-container">
    <?php if ($submitted): ?>
        <div class="checkmark">&#10003;</div>
        <h1>Thank You<?= $applicant_name ? ', ' . htmlspecialchars($applicant_name) : '' ?>!</h1>
        <p>Your LiveMORE scholarship application has been received. Our team will review your application and contact you at the email address you provided.</p>
        <p class="text-muted mt-4" style="font-size: 0.9em;">If you have questions, please contact us at <a href="mailto:LiveMORE@livewright.com">LiveMORE@livewright.com</a>.</p>
    <?php else: ?>
        <h1>LiveMORE Scholarship</h1>
        <p>Looking to apply? <a href="index.php">Start your application here</a>.</p>
    <?php endif; ?>
</div>

</body>
</html>
