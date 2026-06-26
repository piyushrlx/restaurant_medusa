<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
requireLogin();

// Allow 'admin' or 'superadmin' to access
if (empty($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden: Admin access required']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT order_number, customer_name, delivery_address, status, 
               driver_lat, driver_lng, driver_last_updated
        FROM orders 
        WHERE status IN ('Picked Up', 'Out for Delivery') 
          AND driver_lat IS NOT NULL 
          AND driver_lng IS NOT NULL
    ");
    $stmt->execute();
    $active_drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'drivers' => $active_drivers]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
