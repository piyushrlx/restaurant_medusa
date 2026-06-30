<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $table_code = $input['table_code'] ?? '';
    $phone = $input['phone'] ?? '';
    
    if (empty($table_code)) {
        json_response(['success' => false, 'message' => 'Table code is required'], 400);
    }
    
    // Check if there is an active reservation for this table right now
    // Window: 30 mins before, 90 mins after
    $stmt = $pdo->prepare("
        SELECT * FROM table_reservations 
        WHERE table_code = ? 
          AND status = 'active' 
          AND NOW() >= DATE_SUB(reservation_time, INTERVAL 30 MINUTE)
          AND NOW() <= DATE_ADD(reservation_time, INTERVAL 90 MINUTE)
        ORDER BY reservation_time ASC LIMIT 1
    ");
    $stmt->execute([$table_code]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reservation) {
        // Table is free
        json_response(['success' => true, 'is_reserved' => false]);
    }
    
    // Table is reserved
    if (!empty($phone)) {
        // Check if the provided phone matches the reservation
        // Strip any non-digit chars from both to compare
        $db_phone = preg_replace('/[^0-9]/', '', $reservation['customer_phone']);
        $input_phone = preg_replace('/[^0-9]/', '', $phone);
        
        if ($db_phone === $input_phone) {
            json_response(['success' => true, 'is_reserved' => false, 'message' => 'Unlocked']);
        } else {
            json_response(['success' => true, 'is_reserved' => true, 'time' => date('h:i A', strtotime($reservation['reservation_time'])), 'error' => 'Phone number does not match reservation']);
        }
    }
    
    json_response(['success' => true, 'is_reserved' => true, 'time' => date('h:i A', strtotime($reservation['reservation_time']))]);
} else {
    json_response(['success' => false, 'message' => 'Invalid method'], 405);
}
?>
