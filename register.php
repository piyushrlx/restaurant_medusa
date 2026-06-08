<?php
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/includes/otp_helper.php';

// If already active/logged in, redirect to index
if (!empty($_SESSION['user_id'])) {
    header('Location: indextest.html');
    exit;
}

$error      = '';
$goToStep2  = false;   // tells JS to open step 2 on page-reload after PHP error
$form_data  = [
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'phone'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name']  ?? '');
    $fullName  = trim($firstName . ' ' . $lastName);
    $email     = trim($_POST['email']    ?? '');
    $phone     = trim($_POST['phone']    ?? '');
    $password  = $_POST['password']         ?? '';
    $confirmPw = $_POST['confirm_password'] ?? '';

    $form_data = [
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'email'      => $email,
        'phone'      => $phone,
    ];

    if (empty($firstName) || empty($email) || empty($phone) || empty($password) || empty($confirmPw)) {
        $error = 'All required fields must be filled.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address format.';
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $error = 'Mobile number must be exactly 10 digits.';
    } elseif (strlen($password) < 6) {
        $error     = 'Password must be at least 6 characters.';
        $goToStep2 = true;
    } elseif ($password !== $confirmPw) {
        $error     = 'Passwords do not match. Please try again.';
        $goToStep2 = true;
    } else {
        $goToStep2 = true;   // keep step 2 open on DB errors
        try {
            $stmt = $pdo->prepare("SELECT id, is_active FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $existing_user = $stmt->fetch();

            if ($existing_user) {
                if ($existing_user['is_active']) {
                    $error     = 'An account with this email already exists. Please login.';
                    $goToStep2 = false;
                } else {
                    $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $del->execute([$existing_user['id']]);
                }
            }

            if (empty($error)) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND is_active = 1");
                $stmt->execute([$phone]);
                if ($stmt->fetch()) {
                    $error     = 'An account with this mobile number already exists.';
                    $goToStep2 = false;
                }
            }

            if (empty($error)) {
                $emailOtp       = generateOTP();
                $phoneOtp       = generateOTP();
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                $otpExpiresAt   = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                $ins = $pdo->prepare("
                    INSERT INTO users
                    (full_name, email, phone, password, address, city, state, pincode,
                     email_otp, phone_otp, otp_expires_at,
                     is_email_verified, is_phone_verified, is_active, role)
                    VALUES (?, ?, ?, ?, '', '', '', '', ?, ?, ?, 0, 0, 0, 'customer')
                ");
                $ins->execute([$fullName, $email, $phone, $hashedPassword,
                               $emailOtp, $phoneOtp, $otpExpiresAt]);

                $newUserId = $pdo->lastInsertId();

                // Initialize loyalty reward points row for new user
                $pdo->prepare("INSERT IGNORE INTO reward_points (user_id, points_earned, points_redeemed, points_deducted, current_balance) VALUES (?, 0, 0, 0, 0)")->execute([$newUserId]);

                $_SESSION['otp_verify_user_id'] = $newUserId;
                $_SESSION['last_otp_sent_time']  = time();

                sendOTPEmail($email, $fullName, $emailOtp);
                sendOTPSMS($phone, $phoneOtp);

                header('Location: verify_otp.php');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Medusa – Create your account">
    <title>MEDUSA – Register</title>
    <script src="assets/js/theme-toggle.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* ── Back to Home button ── */
        .back-home {
            position: fixed;
            top: 18px;
            left: 18px;
            z-index: 100;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            background: rgba(15,15,15,0.65);
            border: 1px solid rgba(212,175,55,0.22);
            border-radius: 50px;
            color: rgba(255,255,255,0.65);
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.8px;
            text-decoration: none;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            transition: all 0.25s ease;
        }
        .back-home i {
            font-size: 0.75rem;
            transition: transform 0.25s ease;
        }
        .back-home:hover {
            background: rgba(212,175,55,0.12);
            border-color: rgba(212,175,55,0.55);
            color: var(--gold);
            box-shadow: 0 4px 20px rgba(212,175,55,0.15);
        }
        .back-home:hover i {
            transform: translateX(-3px);
        }

        /* ── Design tokens ── */
        :root {
            --gold:         #d4af37;
            --gold-hover:   #e5c158;
            --white:        #ffffff;
            --gray-light:   rgba(255,255,255,0.6);
            --bg-glass:     rgba(15,15,15,0.55);
            --border-glass: rgba(212,175,55,0.12);
            --red:          #e74c3c;
            --green:        #2ecc71;
            --orange:       #f39c12;
            --ease:         cubic-bezier(0.4, 0, 0.2, 1);
        }

        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px 20px;
            background: #000;
        }

        /* ── Card ── */
        .card {
            position: relative;
            width: 100%;
            max-width: 440px;
            padding: 30px 26px 26px;
            background: var(--bg-glass);
            border: 1px solid var(--border-glass);
            border-radius: 18px;
            box-shadow: 0 28px 64px rgba(0,0,0,.92),
                        inset 0 1px 0 rgba(255,255,255,.02);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            overflow: hidden;          /* clips the sliding panels */
            transition: border-color .3s, box-shadow .3s;
        }
        .card:hover {
            border-color: rgba(212,175,55,.22);
            box-shadow: 0 34px 72px rgba(0,0,0,.96);
        }

        /* ── Logo ── */
        .brand { text-align:center; margin-bottom:22px; }
        .brand-logo {
            width:80px; height:80px;
            object-fit:contain;
            border-radius:50%;
            border:1.5px solid var(--gold);
            padding:2px;
            background:rgba(0,0,0,.5);
        }

        /* ── Step progress dots ── */
        .step-dots {
            display:flex;
            justify-content:center;
            align-items:center;
            gap:8px;
            margin-bottom:24px;
        }
        .dot {
            width:8px; height:8px;
            border-radius:50%;
            background:rgba(255,255,255,.18);
            transition:background .4s var(--ease), transform .4s var(--ease), width .4s var(--ease);
        }
        .dot.active {
            background:var(--gold);
            width:22px;
            border-radius:4px;
            transform:scaleY(1.1);
        }
        .dot-label {
            font-size:.7rem;
            font-weight:600;
            letter-spacing:1.2px;
            color:rgba(255,255,255,.35);
            text-transform:uppercase;
            transition:color .3s;
        }
        .dot-label.active { color:var(--gold); }

        /* ── Sliding viewport ── */
        .steps-viewport {
            overflow: hidden;
            position: relative;
        }
        .steps-track {
            display: flex;
            width: 200%;
            transition: transform .48s var(--ease);
            will-change: transform;
        }
        .steps-track.show-step2 { transform: translateX(-50%); }

        .step-panel {
            width: 50%;
            flex-shrink: 0;
            /* so each panel uses card's horizontal space */
        }

        /* ── Error / success banners ── */
        .alert-box {
            padding:10px 12px;
            border-radius:8px;
            font-size:.84rem;
            margin-bottom:18px;
            text-align:center;
            display:flex;
            align-items:center;
            gap:8px;
            justify-content:center;
        }
        .alert-error {
            background:rgba(231,76,60,.1);
            color:#ff6b6b;
            border:1px solid rgba(231,76,60,.22);
        }

        /* ── Section label ── */
        .section-divider {
            display:flex;
            align-items:center;
            gap:10px;
            margin:0 0 18px;
        }
        .section-divider span {
            color:var(--gold);
            font-size:.7rem;
            font-weight:700;
            letter-spacing:1.8px;
            text-transform:uppercase;
            white-space:nowrap;
        }
        .section-divider::before,
        .section-divider::after {
            content:''; flex:1;
            height:1px;
            background:rgba(212,175,55,.16);
        }

        /* ── Input row (side-by-side) ── */
        .field-row { display:flex; gap:12px; }
        .field-row .input-wrap { flex:1; min-width:0; }

        /* ── Input wrapper ── */
        .input-wrap {
            display:flex;
            align-items:center;
            border-bottom:1px solid rgba(255,255,255,.15);
            margin-bottom:16px;
            padding:7px 0;
            transition:border-color .25s;
        }
        .input-wrap.focused { border-bottom-color:var(--gold); }
        .input-wrap.err     { border-bottom-color:var(--red) !important; }

        .input-icon {
            color:rgba(255,255,255,.55);
            flex-shrink:0;
            transition:color .25s;
            font-size:.88rem;
        }
        .input-wrap.focused .input-icon { color:var(--gold); }

        .input-field {
            flex:1;
            background:transparent;
            border:none; outline:none;
            color:#fff;
            font-size:.9rem;
            padding-left:11px;
            font-family:'Plus Jakarta Sans',sans-serif;
            font-weight:500;
        }
        .input-field::placeholder { color:rgba(255,255,255,.35); font-size:.8rem; }

        /* ── Eye toggle ── */
        .eye-btn {
            background:none; border:none;
            color:rgba(255,255,255,.35);
            cursor:pointer; padding:0 4px;
            line-height:1; flex-shrink:0;
            transition:color .25s;
            font-size:.9rem;
        }
        .eye-btn:hover { color:var(--gold); }

        /* ── Password strength ── */
        .strength-bar {
            display:flex; gap:4px;
            margin:-6px 0 12px;
        }
        .seg {
            flex:1; height:3px; border-radius:2px;
            background:rgba(255,255,255,.1);
            transition:background .35s;
        }
        .seg.weak   { background:var(--red); }
        .seg.fair   { background:var(--orange); }
        .seg.good   { background:var(--green); }
        .seg.strong { background:var(--gold); }

        .strength-text {
            font-size:.7rem;
            text-align:right;
            margin:-8px 0 14px;
            color:rgba(255,255,255,.4);
            letter-spacing:.3px;
            min-height:14px;
        }

        /* ── Buttons ── */
        .btn-row {
            display:flex;
            gap:10px;
            margin-top:12px;
            margin-bottom:20px;
        }
        .btn {
            flex:1;
            padding:12px;
            border-radius:8px;
            font-size:.92rem;
            font-weight:700;
            cursor:pointer;
            letter-spacing:.5px;
            transition:transform .2s, background .25s, color .25s;
            border:none;
        }
        .btn:active { transform:translateY(1px) !important; }

        .btn-gold {
            background:var(--gold);
            color:#000;
        }
        .btn-gold:hover { background:var(--gold-hover); transform:translateY(-1px); }

        .btn-ghost {
            background:transparent;
            color:rgba(255,255,255,.7);
            border:1px solid rgba(255,255,255,.18);
        }
        .btn-ghost:hover {
            background:rgba(255,255,255,.06);
            color:#fff;
            transform:translateY(-1px);
        }

        /* single-button row (step 1) */
        .btn-solo {
            width:100%;
            margin-top:12px;
            margin-bottom:20px;
        }

        /* ── Login link ── */
        .login-link {
            text-align:center;
            font-size:.83rem;
            color:rgba(255,255,255,.6);
        }
        .login-link a {
            color:var(--gold);
            font-weight:700;
            text-decoration:none;
            margin-left:4px;
            transition:color .25s;
        }
        .login-link a:hover { color:#fff; }
    </style>
</head>
<body>

<!-- ← HOME button -->
<a href="indextest.html" class="back-home">
    <i class="fas fa-arrow-left"></i> HOME
</a>
<div class="card">

    <!-- Logo -->
    <div class="brand">
        <img class="brand-logo" src="assets/images/versace_logo.png" alt="Medusa Logo">
    </div>

    <!-- Step progress dots -->
    <div class="step-dots">
        <span class="dot-label" id="lbl1">Info</span>
        <div class="dot active" id="dot1"></div>
        <div class="dot"        id="dot2"></div>
        <span class="dot-label" id="lbl2">Password</span>
    </div>

    <!-- Error banner (shown on PHP validation fail) -->
    <?php if (!empty($error)): ?>
    <div class="alert-box alert-error" id="php-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- ══ Single form – two sliding panels ══ -->
    <form action="register.php" method="POST" id="regForm" novalidate>
        <div class="steps-viewport">
            <div class="steps-track <?php echo $goToStep2 ? 'show-step2' : ''; ?>" id="track">

                <!-- ══ STEP 1 — Personal Info ══ -->
                <div class="step-panel" id="step1">

                    <div class="section-divider"><span>Personal Info</span></div>

                    <!-- First + Last -->
                    <div class="field-row">
                        <div class="input-wrap" id="w-first">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" id="first_name" name="first_name"
                                   class="input-field"
                                   placeholder="FIRST NAME *"
                                   value="<?php echo htmlspecialchars($form_data['first_name']); ?>"
                                   autocomplete="given-name"
                                   required>
                        </div>
                        <div class="input-wrap" id="w-last">
                            <input type="text" id="last_name" name="last_name"
                                   class="input-field"
                                   placeholder="LAST NAME (Optional)"
                                   value="<?php echo htmlspecialchars($form_data['last_name']); ?>"
                                   autocomplete="family-name">
                        </div>
                    </div>

                    <!-- Email -->
                    <div class="input-wrap" id="w-email">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" id="email" name="email"
                               class="input-field"
                               placeholder="EMAIL ADDRESS"
                               value="<?php echo htmlspecialchars($form_data['email']); ?>"
                               autocomplete="email">
                    </div>

                    <!-- Phone -->
                    <div class="input-wrap" id="w-phone">
                        <i class="fas fa-phone input-icon"></i>
                        <input type="tel" id="phone" name="phone"
                               class="input-field"
                               placeholder="MOBILE NUMBER"
                               value="<?php echo htmlspecialchars($form_data['phone']); ?>"
                               autocomplete="tel" maxlength="10">
                    </div>

                    <button type="button" class="btn btn-gold btn-solo" id="nextBtn">
                        Continue &nbsp;<i class="fas fa-arrow-right"></i>
                    </button>

                    <div class="login-link">
                        Already a Member? <a href="login.html">Login Now</a>
                    </div>
                </div>
                <!-- /step1 -->

                <!-- ══ STEP 2 — Set Password ══ -->
                <div class="step-panel" id="step2">

                    <div class="section-divider"><span>Set Password</span></div>

                    <!-- New Password -->
                    <div class="input-wrap" id="w-pw">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password"
                               class="input-field"
                               placeholder="NEW PASSWORD"
                               autocomplete="new-password">
                        <button type="button" class="eye-btn" id="toggle-pw" aria-label="Toggle password">
                            <i class="fas fa-eye" id="eye-pw"></i>
                        </button>
                    </div>

                    <!-- Strength bar -->
                    <div class="strength-bar">
                        <div class="seg" id="seg1"></div>
                        <div class="seg" id="seg2"></div>
                        <div class="seg" id="seg3"></div>
                        <div class="seg" id="seg4"></div>
                    </div>
                    <div class="strength-text" id="stext"></div>

                    <!-- Confirm Password -->
                    <div class="input-wrap" id="w-cpw">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="confirm_password" name="confirm_password"
                               class="input-field"
                               placeholder="CONFIRM PASSWORD"
                               autocomplete="new-password">
                        <button type="button" class="eye-btn" id="toggle-cpw" aria-label="Toggle confirm">
                            <i class="fas fa-eye" id="eye-cpw"></i>
                        </button>
                    </div>

                    <!-- Two buttons -->
                    <div class="btn-row">
                        <button type="button" class="btn btn-ghost" id="backBtn">
                            <i class="fas fa-arrow-left"></i> &nbsp;Back
                        </button>
                        <button type="submit" class="btn btn-gold" id="submitBtn">
                            Register &nbsp;<i class="fas fa-check"></i>
                        </button>
                    </div>

                    <div class="login-link">
                        Already a Member? <a href="login.html">Login Now</a>
                    </div>
                </div>
                <!-- /step2 -->

            </div><!-- /track -->
        </div><!-- /viewport -->
    </form>
</div><!-- /card -->

<script>
/* ════════════════════════════════════════════
   MULTI-STEP ANIMATION CONTROLLER
════════════════════════════════════════════ */
const track   = document.getElementById('track');
const dot1    = document.getElementById('dot1');
const dot2    = document.getElementById('dot2');
const lbl1    = document.getElementById('lbl1');
const lbl2    = document.getElementById('lbl2');

let currentStep = <?php echo $goToStep2 ? 2 : 1; ?>;

function goToStep(n) {
    currentStep = n;
    if (n === 2) {
        track.classList.add('show-step2');
        dot1.classList.remove('active');
        dot2.classList.add('active');
        lbl1.classList.remove('active');
        lbl2.classList.add('active');
    } else {
        track.classList.remove('show-step2');
        dot2.classList.remove('active');
        dot1.classList.add('active');
        lbl2.classList.remove('active');
        lbl1.classList.add('active');
    }
}

// Init dots label colours
if (currentStep === 2) {
    lbl2.classList.add('active');
} else {
    lbl1.classList.add('active');
}

/* ── Focus highlight ── */
document.querySelectorAll('.input-field').forEach(inp => {
    const wrap = inp.closest('.input-wrap');
    inp.addEventListener('focus', () => wrap.classList.add('focused'));
    inp.addEventListener('blur',  () => wrap.classList.remove('focused'));
});

/* ── Step 1 validation → Next ── */
document.getElementById('nextBtn').addEventListener('click', () => {
    const first = document.getElementById('first_name').value.trim();
    const last  = document.getElementById('last_name').value.trim();
    const email = document.getElementById('email').value.trim();
    const phone = document.getElementById('phone').value.trim();
    let ok = true;

    const mark = (id, bad) => {
        document.getElementById(id).classList.toggle('err', bad);
        if (bad) ok = false;
    };

    mark('w-first', !first);
    // last name is optional — no validation needed
    mark('w-email', !email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email));
    mark('w-phone', !phone || !/^[0-9]{10}$/.test(phone));

    if (!ok) {
        // shake the card
        const card = document.querySelector('.card');
        card.style.animation = 'none';
        requestAnimationFrame(() => {
            card.style.animation = 'shake .38s ease';
        });
        return;
    }

    goToStep(2);
    // focus first password field after animation
    setTimeout(() => document.getElementById('password').focus(), 480);
});

/* ── Back button ── */
document.getElementById('backBtn').addEventListener('click', () => goToStep(1));

/* ── Eye toggles ── */
function makeEye(btnId, inpId, icoId) {
    document.getElementById(btnId).addEventListener('click', () => {
        const inp  = document.getElementById(inpId);
        const ico  = document.getElementById(icoId);
        const show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        ico.classList.toggle('fa-eye',       !show);
        ico.classList.toggle('fa-eye-slash',  show);
    });
}
makeEye('toggle-pw',  'password',         'eye-pw');
makeEye('toggle-cpw', 'confirm_password', 'eye-cpw');

/* ── Password strength ── */
const segs   = ['seg1','seg2','seg3','seg4'].map(id => document.getElementById(id));
const stext  = document.getElementById('stext');
const LEVELS = [
    { label:'Weak',   cls:'weak',   fill:1, color:'#e74c3c' },
    { label:'Fair',   cls:'fair',   fill:2, color:'#f39c12' },
    { label:'Good',   cls:'good',   fill:3, color:'#2ecc71' },
    { label:'Strong', cls:'strong', fill:4, color:'var(--gold)' },
];

function scorePassword(pw) {
    if (!pw) return -1;
    let s = 0;
    if (pw.length >= 6)  s++;
    if (pw.length >= 10) s++;
    if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) s++;
    if (/[0-9]/.test(pw) && /[^A-Za-z0-9]/.test(pw)) s++;
    return s;
}

document.getElementById('password').addEventListener('input', function () {
    const score = scorePassword(this.value);
    segs.forEach(s => { s.className = 'seg'; });
    if (score < 0) { stext.textContent = ''; return; }
    const lvl = LEVELS[Math.min(score, 3)];
    for (let i = 0; i < lvl.fill; i++) segs[i].classList.add(lvl.cls);
    stext.textContent  = lvl.label;
    stext.style.color  = lvl.color;
});

/* ── Confirm password live match ── */
document.getElementById('confirm_password').addEventListener('input', function () {
    const pw   = document.getElementById('password').value;
    const wrap = document.getElementById('w-cpw');
    if (this.value && this.value !== pw) {
        wrap.classList.add('err');
    } else {
        wrap.classList.remove('err');
    }
});

/* ── Final submit guard ── */
document.getElementById('regForm').addEventListener('submit', function (e) {
    const pw  = document.getElementById('password').value;
    const cpw = document.getElementById('confirm_password').value;
    if (pw.length < 6 || pw !== cpw) {
        e.preventDefault();
        document.getElementById('w-pw').classList.toggle('err',  pw.length < 6);
        document.getElementById('w-cpw').classList.toggle('err', pw !== cpw);
    }
});
</script>

<style>
/* shake keyframe (no JS lib needed) */
@keyframes shake {
    0%,100% { transform: translateX(0);   }
    18%     { transform: translateX(-6px);}
    36%     { transform: translateX( 6px);}
    54%     { transform: translateX(-4px);}
    72%     { transform: translateX( 4px);}
    90%     { transform: translateX(-2px);}
}
</style>
</body>
</html>
