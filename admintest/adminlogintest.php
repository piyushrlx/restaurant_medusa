<?php
session_start();

if(isset($_POST['login'])){

    $username = $_POST['username'];
    $password = $_POST['password'];

    if($username === "admin" && $password === "admin123"){

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['user_id'] = 1; // Default admin user ID matching seed data
        $_SESSION['user_role'] = 'admin';
        $_SESSION['user_name'] = 'System Admin';
        $_SESSION['user_email'] = 'admin@example.com';

        // Trigger notification
        require_once dirname(__DIR__) . '/includes/notifications_helper.php';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        addNotification('system', 'Admin Login Detected', "System Admin logged in via legacy admin login panel from IP {$ip}.");

        header("Location: dashboardtest.php");
        exit;
    }

    $error = "Invalid username or password";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Admin Login</title>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
rel="stylesheet">
<!-- FontAwesome for Toggle Icon -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<script>
    (function() {
        const theme = localStorage.getItem('medusa_admin_theme');
        if (theme === 'light') {
            document.documentElement.classList.add('light-mode');
        }
    })();
</script>

<style>

body{
background:#0f0f0f;
height:100vh;
display:flex;
justify-content:center;
align-items:center;
font-family:Arial;
}

.login-card{
width:400px;
background:#1a1a1a;
padding:40px;
border-radius:20px;
box-shadow:0 0 30px rgba(255,215,0,0.15);
}

h2{
color:#d4af37;
text-align:center;
margin-bottom:30px;
}

.form-control{
background:#111;
border:1px solid #333;
color:white;
padding:12px;
}

.form-control:focus{
background:#111;
color:white;
border-color:#d4af37;
box-shadow:none;
}

.btn-login{
background:#d4af37;
border:none;
padding:12px;
font-weight:bold;
width:100%;
}

.btn-login:hover{
background:#f1c75b;
}

.error{
color:red;
margin-bottom:15px;
text-align:center;
}

/* Light Mode overrides */
html.light-mode body {
    background: #f8f9fc;
}
html.light-mode .login-card {
    background: #ffffff;
    box-shadow: 0 10px 40px rgba(0,0,0,0.08);
}
html.light-mode .form-control {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #1e293b;
}
html.light-mode .form-control:focus {
    background: #ffffff;
    color: #1e293b;
    border-color: #d4af37;
}
/* Smooth transition */
body, .login-card, .form-control {
    transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}

</style>

</head>
<body>

<div style="position: fixed; top: 2rem; right: 2rem; z-index: 1000;">
    <button id="themeToggleBtn" class="btn btn-outline-light rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; background: #1a1a1a; border: 1px solid #333; box-shadow: 0 4px 15px rgba(0,0,0,0.2); transition: all 0.3s;" onclick="toggleTheme()" title="Toggle Theme">
        <i class="fas fa-moon" id="themeIcon" style="color: #d4af37; font-size: 1.2rem;"></i>
    </button>
</div>

<div class="login-card">

<h2>Admin Login</h2>

<?php if(isset($error)): ?>
<div class="error">
<?= $error ?>
</div>
<?php endif; ?>

<form method="POST">

<input
type="text"
name="username"
placeholder="Username"
class="form-control mb-3"
required>

<input
type="password"
name="password"
placeholder="Password"
class="form-control mb-4"
required>

<button
type="submit"
name="login"
class="btn btn-login">

Login

</button>

</form>

</div>

<script>
    function updateThemeUI() {
        const isLight = document.documentElement.classList.contains('light-mode');
        const icon = document.getElementById('themeIcon');
        const btn = document.getElementById('themeToggleBtn');
        
        if (isLight) {
            if (icon) {
                icon.className = 'fas fa-sun';
                icon.style.color = '#d4af37';
            }
            if (btn) {
                btn.style.background = '#ffffff';
                btn.style.borderColor = '#cbd5e1';
            }
        } else {
            if (icon) {
                icon.className = 'fas fa-moon';
                icon.style.color = '#d4af37';
            }
            if (btn) {
                btn.style.background = '#1a1a1a';
                btn.style.borderColor = '#333';
            }
        }
    }

    function toggleTheme() {
        if (document.documentElement.classList.contains('light-mode')) {
            document.documentElement.classList.remove('light-mode');
            localStorage.setItem('medusa_admin_theme', 'dark');
        } else {
            document.documentElement.classList.add('light-mode');
            localStorage.setItem('medusa_admin_theme', 'light');
        }
        updateThemeUI();
    }

    // Run on load
    document.addEventListener('DOMContentLoaded', function() {
        updateThemeUI();
    });
</script>
</body>
</html>