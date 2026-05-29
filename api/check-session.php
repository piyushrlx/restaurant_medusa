<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

if (!empty($_SESSION['user_id'])) {
    echo json_encode([
        'success' => true,
        'logged_in' => true,
        'user_name' => $_SESSION['user_name'] ?? '',
        'user_role' => $_SESSION['user_role'] ?? ''
    ]);
} else {
    echo json_encode([
        'success' => true,
        'logged_in' => false
    ]);
}
?>
