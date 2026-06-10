<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to <?php echo htmlspecialchars($website_name); ?></title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #050505;
            color: #e5e5e5;
            font-family: 'Plus Jakarta Sans', Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        table {
            border-spacing: 0;
            width: 100%;
        }
        td {
            padding: 0;
        }
        img {
            border: 0;
        }
        .wrapper {
            width: 100%;
            table-layout: fixed;
            background-color: #050505;
            padding-bottom: 40px;
            padding-top: 40px;
        }
        .main-table {
            background-color: #0c0b0a;
            margin: 0 auto;
            width: 100%;
            max-width: 600px;
            border-spacing: 0;
            font-family: sans-serif;
            color: #e5e5e5;
            border: 1px solid rgba(212, 175, 55, 0.15);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6);
        }
        .header {
            background-color: #12100e;
            padding: 30px;
            text-align: center;
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
        }
        .logo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 2px solid #d4af37;
            padding: 2px;
        }
        .brand-name {
            font-family: 'Playfair Display', Georgia, serif;
            color: #d4af37;
            font-size: 24px;
            letter-spacing: 2px;
            margin-top: 12px;
            font-weight: bold;
        }
        .content {
            padding: 40px 30px;
            line-height: 1.6;
            font-size: 15px;
            color: #d8d8d8;
        }
        .greeting {
            font-size: 20px;
            color: #d4af37;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .status-box {
            background-color: rgba(46, 196, 182, 0.08);
            border: 1px solid rgba(46, 196, 182, 0.25);
            border-radius: 8px;
            padding: 15px 20px;
            color: #2ec4b6;
            font-weight: 600;
            margin-bottom: 25px;
            display: inline-block;
        }
        .btn-container {
            text-align: center;
            margin: 35px 0;
        }
        .btn-login {
            background: linear-gradient(135deg, #d4af37 0%, #b89225 100%);
            color: #000000 !important;
            text-decoration: none;
            padding: 14px 35px;
            font-weight: bold;
            font-size: 14px;
            border-radius: 8px;
            display: inline-block;
            letter-spacing: 1px;
            text-transform: uppercase;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.25);
        }
        .support-info {
            background-color: #12100e;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            padding: 25px 30px;
            font-size: 13px;
            color: #a5a5a5;
        }
        .support-title {
            color: #d4af37;
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .footer {
            background-color: #090807;
            padding: 25px 30px;
            text-align: center;
            font-size: 12px;
            color: #7c7c7c;
            border-top: 1px solid rgba(255, 255, 255, 0.03);
        }
        .footer a {
            color: #d4af37;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <center class="wrapper">
        <table class="main-table">
            <!-- Header Logo Section -->
            <tr>
                <td class="header">
                    <img class="logo" src="cid:<?php echo htmlspecialchars($logo_cid); ?>" alt="<?php echo htmlspecialchars($website_name); ?> Logo">
                    <div class="brand-name"><?php echo htmlspecialchars(strtoupper($website_name)); ?></div>
                </td>
            </tr>

            <!-- Content Area -->
            <tr>
                <td class="content">
                    <div class="greeting">Hi <?php echo htmlspecialchars($user['full_name']); ?>, Welcome to <?php echo htmlspecialchars($website_name); ?>!</div>
                    
                    <div class="status-box">
                        <span style="font-size: 16px; margin-right: 8px;">✓</span> Account Successfully Activated
                    </div>

                    <p>We are delighted to confirm that your profile verification is complete and your digital command center is fully active. You now have privileged access to the region's most refined culinary destination.</p>
                    
                    <p>From this moment, you can reserve select tables, customize artisanal dishes to your specific taste, and track orders directly from your personal dashboard.</p>

                    <!-- CTA Button -->
                    <div class="btn-container">
                        <a href="<?php echo htmlspecialchars($login_url); ?>" class="btn-login" target="_blank">Log In to Your Account</a>
                    </div>

                    <p>For your records, your registered email address is: <strong><?php echo htmlspecialchars($user['email']); ?></strong></p>
                </td>
            </tr>

            <!-- Support Details -->
            <tr>
                <td class="support-info">
                    <div class="support-title">Need Assistance?</div>
                    If you have any questions or require custom menu arrangements, please do not hesitate to contact our guest relations team:
                    <div style="margin-top: 10px;">
                        Email: <a href="mailto:<?php echo htmlspecialchars($support_email); ?>" style="color: #d4af37; text-decoration: none;"><?php echo htmlspecialchars($support_email); ?></a> &nbsp;|&nbsp; 
                        Phone: <span style="color: #e5e5e5;"><?php echo htmlspecialchars($support_phone); ?></span>
                    </div>
                </td>
            </tr>

            <!-- Footer Section -->
            <tr>
                <td class="footer">
                    <p>This is an automated operational message regarding your <?php echo htmlspecialchars($website_name); ?> account.</p>
                    <p>&copy; 2026 <?php echo htmlspecialchars($website_name); ?> Restaurant. SCO 44,45, Sector 68, SAS Nagar, Punjab 140308. All Rights Reserved.</p>
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
