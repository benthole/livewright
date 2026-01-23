<?php
require_once('config.php');

header('Content-Type: application/json');
$pdo = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);

$data = json_decode(file_get_contents('php://input'), true);

$firstName = strtolower(trim($data['first_name'] ?? ''));
$lastName  = strtolower(trim($data['last_name'] ?? ''));
$email     = isset($data['email']) ? strtolower(trim($data['email'])) : null;

if ($firstName && $lastName) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO skipped_attendee (first_name, last_name, email) VALUES (?, ?, ?)");
    $success = $stmt->execute([$firstName, $lastName, $email]);
    echo json_encode(['success' => $success]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}