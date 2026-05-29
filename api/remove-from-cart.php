<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
requireLogin();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$food_item_id = intval($data['food_item_id'] ?? 0);

if (!$food_item_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid food item']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND food_item_id = ?");
    $stmt->execute([$user_id, $food_item_id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
