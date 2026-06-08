<?php
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/otp_helper.php';

// Verify session exists
if (empty($_SESSION['otp_verify_user_id'])) {
    header('Location: register.php');
    exit;
}

$userId = $_SESSION['otp_verify_user_id'];
$error = '';
$success = '';
$redirect = false;

if (isset($_SESSION['otp_error'])) {
    $error = $_SESSION['otp_error'];
    unset($_SESSION['otp_error']);
}
if (isset($_SESSION['otp_success'])) {
    $success = $_SESSION['otp_success'];
    unset($_SESSION['otp_success']);
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        unset($_SESSION['otp_verify_user_id']);
        header('Location: register.php');
        exit;
    }

    if ($user['is_active']) {
        unset($_SESSION['otp_verify_user_id']);
        header('Location: login.html');
        exit;
    }

    $isEmailVerified = (int)$user['is_email_verified'] === 1;
    $isPhoneVerified = (int)$user['is_phone_verified'] === 1;

} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $emailOtpInput = trim($_POST['email_otp'] ?? '');
    $phoneOtpInput = trim($_POST['phone_otp'] ?? '');

    // Check expiry
    $now = new DateTime();
    $expiresAt = new DateTime($user['otp_expires_at']);

    if ($now > $expiresAt) {
        $error = 'Verification codes have expired. Please request a new OTP.';
    } else {
        $newEmailVerified = $isEmailVerified;
        $newPhoneVerified = $isPhoneVerified;

        // Verify Email OTP if not already verified
        if (!$isEmailVerified) {
            if ($emailOtpInput === $user['email_otp']) {
                $newEmailVerified = true;
            } else {
                $error = 'Invalid Email verification code.';
            }
        }

        // Verify Phone OTP if not already verified
        if (!$isPhoneVerified) {
            if ($phoneOtpInput === $user['phone_otp']) {
                $newPhoneVerified = true;
            } else {
                // Keep pre-existing error or set new one
                if (empty($error)) {
                    $error = 'Invalid Phone verification code.';
                } else {
                    $error = 'Invalid Email and Phone verification codes.';
                }
            }
        }

        // If changes, update database
        if (empty($error) || ($newEmailVerified !== $isEmailVerified || $newPhoneVerified !== $isPhoneVerified)) {
            try {
                $update = $pdo->prepare("UPDATE users SET is_email_verified = ?, is_phone_verified = ? WHERE id = ?");
                $update->execute([$newEmailVerified ? 1 : 0, $newPhoneVerified ? 1 : 0, $userId]);

                $isEmailVerified = $newEmailVerified;
                $isPhoneVerified = $newPhoneVerified;

                // Check if fully verified
                if ($isEmailVerified && $isPhoneVerified) {
                    $activate = $pdo->prepare("UPDATE users SET is_active = 1, email_otp = NULL, phone_otp = NULL, otp_expires_at = NULL WHERE id = ?");
                    $activate->execute([$userId]);

                    // Send welcome and account confirmation emails
                    sendWelcomeEmail($user);
                    sendConfirmationEmail($user);

                    unset($_SESSION['otp_verify_user_id']);
                    $success = 'Verification successful! Your account is now active. Redirecting to login...';
                    $redirect = true;
                }
            } catch (PDOException $e) {
                $error = 'Failed to update database: ' . $e->getMessage();
            }
        }
    }
}

// Calculate remaining resend time
$lastSent = $_SESSION['last_otp_sent_time'] ?? 0;
$timeSinceLast = time() - $lastSent;
$resendDelay = 30;
$secondsLeft = max(0, $resendDelay - $timeSinceLast);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEDUSA - Verify Account</title>
    <script src="assets/js/theme-toggle.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --gold: #d4af37;
            --white: #ffffff;
            --gray-light: rgba(255, 255, 255, 0.6);
            --bg-glass: rgba(15, 15, 15, 0.55);
            --border-glass: rgba(212, 175, 55, 0.12);
            --transition: all 0.25s ease;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            background-color: #000000;
        }
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 30px 24px;
            background: var(--bg-glass);
            border: 1px solid var(--border-glass);
            border-radius: 16px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.9), inset 0 1px 0 rgba(255, 255, 255, 0.02);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            transition: var(--transition);
        }
        .brand-container { text-align: center; margin-bottom: 25px; }
        .brand-logo-img {
            width: 80px;
            height: 80px;
            object-fit: contain;
            border-radius: 50%;
            border: 1.5px solid var(--gold);
            padding: 2px;
            background: rgba(0, 0, 0, 0.5);
        }
        .section-header {
            text-align: center;
            margin-bottom: 25px;
        }
        .section-header h2 {
            font-family: 'Playfair Display', Georgia, serif;
            color: var(--gold);
            font-size: 1.5rem;
            margin-bottom: 8px;
        }
        .section-header p {
            color: var(--gray-light);
            font-size: 0.85rem;
            line-height: 1.4;
        }
        .error-msg {
            background: rgba(255, 107, 107, 0.12);
            color: #ff6b6b;
            border: 1px solid rgba(255, 107, 107, 0.2);
            padding: 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            text-align: center;
        }
        .success-msg {
            background: rgba(46, 196, 182, 0.12);
            color: #2ec4b6;
            border: 1px solid rgba(46, 196, 182, 0.2);
            padding: 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            text-align: center;
        }
        .channel-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--gray-light);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-badge {
            font-size: 0.65rem;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 700;
        }
        .badge-verified {
            background: rgba(46, 196, 182, 0.15);
            color: #2ec4b6;
            border: 1px solid rgba(46, 196, 182, 0.25);
        }
        .badge-pending {
            background: rgba(223, 186, 134, 0.15);
            color: var(--gold);
            border: 1px solid rgba(223, 186, 134, 0.25);
        }
        .input-wrapper {
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            margin-bottom: 20px;
            padding: 6px 0;
            transition: var(--transition);
        }
        .input-wrapper.focused { border-bottom-color: var(--gold); }
        .input-wrapper.disabled { border-bottom-color: rgba(255, 255, 255, 0.05); opacity: 0.5; }
        .input-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            opacity: 0.75;
        }
        .input-wrapper.focused .input-icon { color: var(--gold); }
        .input-field {
            width: 100%;
            background: transparent;
            border: none;
            outline: none;
            color: var(--white);
            font-size: 0.92rem;
            padding-left: 12px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 500;
            letter-spacing: 2px;
        }
        .input-field:disabled { cursor: not-allowed; }
        .btn-submit {
            width: 100%;
            background-color: var(--gold);
            color: #000000;
            border: none;
            padding: 12px;
            border-radius: 6px;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            margin-bottom: 20px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .btn-submit:hover { background-color: #e5c158; transform: translateY(-1px); }
        .btn-submit:active { transform: translateY(0); }
        .resend-container {
            text-align: center;
            color: var(--white);
            font-size: 0.85rem;
            opacity: 0.75;
        }
        .resend-link {
            color: var(--gold);
            text-decoration: none;
            font-weight: 700;
            transition: var(--transition);
        }
        .resend-link.disabled {
            color: var(--gray-light);
            cursor: not-allowed;
            text-decoration: none;
        }
        .resend-link:not(.disabled):hover {
            color: var(--white);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="brand-container">
            <img class="brand-logo-img" src="assets/images/versace_logo.png" alt="Medusa Logo">
        </div>

        <div class="section-header">
            <h2>Dual-Channel OTP Verification</h2>
            <p>We've sent verification codes to your registered email and phone number. Enter them below to activate your account.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="error-msg">
                <i class="fas fa-exclamation-circle me-1"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="success-msg">
                <i class="fas fa-check-circle me-1"></i> <?php echo htmlspecialchars($success); ?>
            </div>
            <?php if ($redirect): ?>
                <script>
                    setTimeout(() => {
                        window.location.href = 'login.html';
                    }, 3000);
                </script>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$redirect): ?>
            <form action="verify_otp.php" method="POST">
                <!-- Email OTP -->
                <div>
                    <div class="channel-label">
                        <span>Email Code (<?php echo htmlspecialchars(substr($user['email'], 0, 4) . '***' . strstr($user['email'], '@')); ?>)</span>
                        <span class="status-badge <?php echo $isEmailVerified ? 'badge-verified' : 'badge-pending'; ?>">
                            <?php echo $isEmailVerified ? 'Verified' : 'Verification Pending'; ?>
                        </span>
                    </div>
                    <div class="input-wrapper <?php echo $isEmailVerified ? 'disabled' : ''; ?>">
                        <span class="input-icon"><i class="fas fa-envelope-open-text"></i></span>
                        <input type="text" name="email_otp" class="input-field" placeholder="------" maxlength="6" pattern="[0-9]{6}" required <?php echo $isEmailVerified ? 'disabled value="******"' : ''; ?> autocomplete="off">
                    </div>
                </div>

                <!-- Phone OTP -->
                <div>
                    <div class="channel-label">
                        <span>Phone Code (******<?php echo htmlspecialchars(substr($user['phone'], -4)); ?>)</span>
                        <span class="status-badge <?php echo $isPhoneVerified ? 'badge-verified' : 'badge-pending'; ?>">
                            <?php echo $isPhoneVerified ? 'Verified' : 'Verification Pending'; ?>
                        </span>
                    </div>
                    <div class="input-wrapper <?php echo $isPhoneVerified ? 'disabled' : ''; ?>">
                        <span class="input-icon"><i class="fas fa-sms"></i></span>
                        <input type="text" name="phone_otp" class="input-field" placeholder="------" maxlength="6" pattern="[0-9]{6}" required <?php echo $isPhoneVerified ? 'disabled value="******"' : ''; ?> autocomplete="off">
                    </div>
                </div>

                <button type="submit" class="btn-submit">Verify Codes</button>
            </form>
        <?php endif; ?>

        <div class="resend-container">
            Didn't receive codes? 
            <span id="countdown-wrapper">
                <a href="resend_otp.php" id="resend-btn" class="resend-link <?php echo $secondsLeft > 0 ? 'disabled' : ''; ?>">Resend OTP</a>
                <span id="timer-text" style="display: <?php echo $secondsLeft > 0 ? 'inline' : 'none'; ?>;">(in <span id="timer-seconds"><?php echo $secondsLeft; ?></span>s)</span>
            </span>
        </div>
    </div>

    <script>
        // Visual focus bindings
        const inputs = document.querySelectorAll('.input-field:not(:disabled)');
        inputs.forEach(input => {
            input.addEventListener('focus', () => {
                input.closest('.input-wrapper').classList.add('focused');
            });
            input.addEventListener('blur', () => {
                input.closest('.input-wrapper').classList.remove('focused');
            });
        });

        // Resend countdown logic
        let timeLeft = parseInt(document.getElementById('timer-seconds').textContent) || 0;
        if (timeLeft > 0) {
            const resendBtn = document.getElementById('resend-btn');
            const timerText = document.getElementById('timer-text');
            const timerSecs = document.getElementById('timer-seconds');

            const interval = setInterval(() => {
                timeLeft--;
                timerSecs.textContent = timeLeft;
                if (timeLeft <= 0) {
                    clearInterval(interval);
                    resendBtn.classList.remove('disabled');
                    timerText.style.display = 'none';
                }
            }, 1000);
        }
    </script>
</body>
</html>
