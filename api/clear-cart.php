<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
requireLogin();

$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
    $stmt->execute([$user_id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
