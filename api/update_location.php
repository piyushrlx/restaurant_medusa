<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['lat']) && isset($data['lng'])) {
    $location = [
        'lat' => $data['lat'],
        'lng' => $data['lng'],
        'timestamp' => time()
    ];
    
    file_put_contents('location.json', json_encode($location));
    
    echo json_encode(['status' => 'success', 'message' => 'Location updated']);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
}
?>
