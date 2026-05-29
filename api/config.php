<?php
$host = "localhost";
$dbname = "restaurant_db";
$username = "root";
$password = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname",
        $username,
        $password
    );
    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Start Session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure the user is logged in
function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        // Return 401 Unauthorized for AJAX/API requests
        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false || 
            (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) ||
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized login required']);
            exit;
        } else {
            // Redirect HTML pages to login
            header('Location: login.html');
            exit;
        }
    }
}

// Ensure the user has the Admin role
function requireAdmin() {
    requireLogin();
    if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: Admins only']);
            exit;
        } else {
            // Customers or unauthenticated users redirected out of admin folder
            header('Location: ../login.html');
            exit;
        }
    }
}
?>
