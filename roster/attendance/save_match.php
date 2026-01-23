<?php
require_once('config.php');

header('Content-Type: application/json');
$pdo = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);

$data = json_decode(file_get_contents('php://input'), true);

$contactId = $data['contact_id'] ?? null;
$firstName = $data['first_name'] ?? '';
$lastName = $data['last_name'] ?? '';
$email = $data['email'] ?? null;

if ($contactId && $firstName && $lastName) {
    $stmt = $pdo->prepare("INSERT INTO known_attendee (first_name, last_name, email, contact_id) VALUES (?, ?, ?, ?)");
    $success = $stmt->execute([$firstName, $lastName, $email, $contactId]);
    echo json_encode(['success' => $success]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}