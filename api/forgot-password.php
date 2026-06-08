<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

// Decode JSON input
$data = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
}

$action = $_GET['action'] ?? '';

try {
    // ── 1. SEND OTP ──
    if ($action === 'send_otp') {
        $email = trim($data['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
            exit;
        }

        // Check if user exists
        $stmt = $pdo->prepare("SELECT id, full_name, is_active FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Return success anyway to prevent email enumeration attacks, but don't actually send
            // Actually, for better UX, we can just say if it exists or not. Let's return error.
            echo json_encode(['success' => false, 'message' => 'No account found with this email address.']);
            exit;
        }
        
        if (!(int)$user['is_active']) {
            echo json_encode(['success' => false, 'message' => 'This account is currently inactive.']);
            exit;
        }

        // Throttle OTP generation
        if (isset($_SESSION['last_pwd_reset_otp_time']) && (time() - $_SESSION['last_pwd_reset_otp_time']) < 30) {
            $wait = 30 - (time() - $_SESSION['last_pwd_reset_otp_time']);
            echo json_encode(['success' => false, 'message' => "Please wait {$wait} seconds before requesting another OTP."]);
            exit;
        }
        $_SESSION['last_pwd_reset_otp_time'] = time();

        require_once dirname(__DIR__) . '/includes/otp_helper.php';
        $otp = generateOTP();
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // Save OTP to DB
        $upd = $pdo->prepare("UPDATE users SET email_otp = ?, otp_expires_at = ? WHERE id = ?");
        $upd->execute([$otp, $expires, $user['id']]);

        if (sendOTPEmail($email, $user['full_name'], $otp)) {
            echo json_encode(['success' => true, 'message' => 'A 6-digit OTP has been sent to your email.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please try again later.']);
        }
        exit;
    }

    // ── 2. VERIFY OTP ──
    if ($action === 'verify_otp') {
        $email = trim($data['email'] ?? '');
        $otp = trim($data['otp'] ?? '');

        if (empty($email) || empty($otp)) {
            echo json_encode(['success' => false, 'message' => 'Email and OTP are required.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, email_otp, otp_expires_at FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['email_otp'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid request. Please request a new OTP.']);
            exit;
        }

        if ($user['email_otp'] !== $otp) {
            echo json_encode(['success' => false, 'message' => 'Incorrect OTP code. Please try again.']);
            exit;
        }

        if (strtotime($user['otp_expires_at']) < time()) {
            echo json_encode(['success' => false, 'message' => 'Your OTP has expired. Please request a new one.']);
            exit;
        }

        // OTP is valid. Set session token to allow password reset.
        $_SESSION['password_reset_authorized_email'] = $email;

        echo json_encode(['success' => true, 'message' => 'OTP verified successfully. You may now reset your password.']);
        exit;
    }

    // ── 3. RESET PASSWORD ──
    if ($action === 'reset_password') {
        $password = $data['password'] ?? '';
        $confirm = $data['confirm_password'] ?? '';
        $email = $_SESSION['password_reset_authorized_email'] ?? '';

        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized request. Please verify your OTP first.']);
            exit;
        }

        if (empty($password) || empty($confirm)) {
            echo json_encode(['success' => false, 'message' => 'Both password fields are required.']);
            exit;
        }

        if (strlen($password) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long.']);
            exit;
        }

        if ($password !== $confirm) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
            exit;
        }

        // Hash new password and update
        $hashed_pw = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, email_otp = NULL, otp_expires_at = NULL WHERE email = ?");
        $stmt->execute([$hashed_pw, $email]);

        // Invalidate all other sessions (optional but good practice)
        $new_token = bin2hex(random_bytes(32));
        $tok_stmt = $pdo->prepare("UPDATE users SET session_token = ? WHERE email = ?");
        $tok_stmt->execute([$new_token, $email]);

        // Clear auth token
        unset($_SESSION['password_reset_authorized_email']);

        echo json_encode(['success' => true, 'message' => 'Your password has been reset successfully! You can now log in.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
