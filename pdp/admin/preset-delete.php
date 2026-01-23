<?php
require_once '../config.php';
requireLogin();

$preset_id = $_GET['id'] ?? null;

if ($preset_id && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Delete preset (hard delete since it's just template data)
        $stmt = $pdo->prepare("DELETE FROM pdp_presets WHERE id = ?");
        $stmt->execute([$preset_id]);
    } catch (Exception $e) {
        // Silently handle error and redirect
    }
}

header('Location: presets.php');
exit;
