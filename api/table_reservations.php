<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
requireLogin();

// Allow 'admin' or 'superadmin' to access
if (empty($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'superadmin'])) {
    json_response(['success' => false, 'message' => 'Forbidden: Admin access required'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Fetch upcoming reservations for a table
    $table_code = $_GET['table_code'] ?? '';
    if (empty($table_code)) {
        json_response(['success' => false, 'message' => 'Table code is required'], 400);
    }
    
    $stmt = $pdo->prepare("SELECT * FROM table_reservations WHERE table_code = ? AND status = 'active' AND reservation_time > DATE_SUB(NOW(), INTERVAL 2 HOUR) ORDER BY reservation_time ASC");
    $stmt->execute([$table_code]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    json_response(['success' => true, 'reservations' => $reservations]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add a new reservation
    $input = json_decode(file_get_contents('php://input'), true);
    
    $table_code = $input['table_code'] ?? '';
    $customer_name = $input['customer_name'] ?? '';
    $customer_phone = $input['customer_phone'] ?? '';
    $reservation_time = $input['reservation_time'] ?? '';
    
    if (empty($table_code) || empty($customer_name) || empty($customer_phone) || empty($reservation_time)) {
        json_response(['success' => false, 'message' => 'All fields are required'], 400);
    }
    
    $stmt = $pdo->prepare("INSERT INTO table_reservations (table_code, customer_name, customer_phone, reservation_time) VALUES (?, ?, ?, ?)");
    if ($stmt->execute([$table_code, $customer_name, $customer_phone, $reservation_time])) {
        json_response(['success' => true, 'message' => 'Reservation added successfully']);
    } else {
        json_response(['success' => false, 'message' => 'Failed to add reservation'], 500);
    }
} else {
    json_response(['success' => false, 'message' => 'Invalid method'], 405);
}
?>
