<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

// Ensure the user is logged in
requireLogin();

$user_id = $_SESSION['user_id'];

try {
    // Retrieve orders belonging to the logged-in customer
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY order_date DESC");
    $stmt->execute([$user_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For each order, fetch its ordered items
    foreach ($orders as &$order) {
        $item_stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $item_stmt->execute([$order['id']]);
        $order['items'] = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        'success' => true,
        'orders' => $orders
    ]);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
