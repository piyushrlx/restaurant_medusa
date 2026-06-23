<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if (file_exists('location.json')) {
    $data = file_get_contents('location.json');
    echo $data;
} else {
    echo json_encode(['lat' => null, 'lng' => null]);
}
?>
