<?php
// Shared admin header - include at top of every admin page
// Set $page_title before including. Set $page_styles for page-specific CSS.
// Set $page_head for extra <head> content (e.g. Quill CSS).
if (!isset($page_title)) $page_title = 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - PDP Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="includes/admin.css">
    <?php if (!empty($page_head)): ?>
        <?= $page_head ?>
    <?php endif; ?>
    <?php if (!empty($page_styles)): ?>
        <style><?= $page_styles ?></style>
    <?php endif; ?>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #007cba;">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">PDP Admin</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="adminNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'index.php' ? 'active' : '' ?>" href="index.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page === 'edit.php' && empty($_GET['id'])) ? 'active' : '' ?>" href="edit.php">New Plan</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= in_array($current_page, ['presets.php', 'preset-edit.php']) ? 'active' : '' ?>" href="presets.php">Presets</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'coaching-rates.php' ? 'active' : '' ?>" href="coaching-rates.php">Coaching Rates</a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
