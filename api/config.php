<?php
if (!function_exists('get_env_var')) {
    function get_env_var($key, $default = null) {
        static $env = null;
        if ($env === null) {
            $env = [];
            $path = dirname(__DIR__) . '/.env';
            if (file_exists($path)) {
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, '#') === 0 || empty($line)) continue;
                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        $env[trim($parts[0])] = trim($parts[1]);
                    }
                }
            }
        }
        return $env[$key] ?? $default;
    }
}

$host = get_env_var('DB_HOST', 'localhost');
$dbname = get_env_var('DB_NAME', 'restaurant_db');
$username = get_env_var('DB_USER', 'root');
$password = get_env_var('DB_PASS', '');

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
    global $pdo;
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

    // Validate session token against database
    if (isset($_SESSION['session_token'])) {
        try {
            $stmt = $pdo->prepare("SELECT session_token FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $db_token = $stmt->fetchColumn();
            if ($db_token !== $_SESSION['session_token']) {
                // Token mismatch (e.g. logged out from all devices) - destroy session
                $_SESSION = array();
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $params["path"], $params["domain"],
                        $params["secure"], $params["httponly"]
                    );
                }
                session_destroy();

                if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false || 
                    (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) ||
                    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')) {
                    header('Content-Type: application/json');
                    http_response_code(401);
                    echo json_encode(['success' => false, 'message' => 'Session expired or logged out from other devices. Please login again.']);
                    exit;
                } else {
                    header('Location: login.html');
                    exit;
                }
            }
        } catch (PDOException $e) {
            // Silently ignore DB error during session check to prevent rendering issues, or just log
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

// WhatsApp Delivery Gateway Settings (Feature 6)
// Set WHATSAPP_ENABLED=true and fill API credentials in config.php when client provides them — no other code changes needed
if (!defined('WHATSAPP_ENABLED')) {
    $wa_env = get_env_var('WHATSAPP_ENABLED', 'false');
    define('WHATSAPP_ENABLED', $wa_env === 'true' || $wa_env === '1' || $wa_env === 1);
}
if (!defined('WHATSAPP_API_URL')) {
    define('WHATSAPP_API_URL', get_env_var('WHATSAPP_API_URL', ''));
}
if (!defined('WHATSAPP_API_KEY')) {
    define('WHATSAPP_API_KEY', get_env_var('WHATSAPP_API_KEY', ''));
}
if (!defined('WHATSAPP_FROM_NUMBER')) {
    define('WHATSAPP_FROM_NUMBER', get_env_var('WHATSAPP_FROM_NUMBER', ''));
}
?>
