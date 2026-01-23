<?php
// delete.php
require_once '../config.php';
requireLogin();

$contract_id = $_GET['id'] ?? null;

if ($contract_id && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $pdo->beginTransaction();
        
        // Soft delete contract
        $stmt = $pdo->prepare("UPDATE contracts SET deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$contract_id]);
        
        // Soft delete associated pricing options
        $stmt = $pdo->prepare("UPDATE pricing_options SET deleted_at = NOW() WHERE contract_id = ?");
        $stmt->execute([$contract_id]);
        
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollback();
    }
}

header('Location: admin.php');
exit;
