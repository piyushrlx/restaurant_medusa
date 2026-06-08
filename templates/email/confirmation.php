<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Confirmation - <?php echo htmlspecialchars($website_name); ?></title>
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
        .heading {
            font-size: 20px;
            color: #d4af37;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .details-table {
            width: 100%;
            margin: 25px 0;
            background-color: #12100e;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            overflow: hidden;
        }
        .details-table td {
            padding: 12px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            font-size: 14px;
        }
        .details-table tr:last-child td {
            border-bottom: none;
        }
        .details-label {
            color: #d4af37;
            font-weight: bold;
            width: 30%;
        }
        .details-value {
            color: #e5e5e5;
        }
        .security-notice {
            background-color: rgba(239, 68, 68, 0.06);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 8px;
            padding: 18px;
            margin-top: 30px;
            color: #fca5a5;
            font-size: 14px;
        }
        .security-title {
            color: #ef4444;
            font-weight: bold;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 12px;
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
                    <div class="heading">Registration Complete</div>
                    
                    <p>Dear <?php echo htmlspecialchars($user['full_name']); ?>,</p>
                    <p>Your registration at <?php echo htmlspecialchars($website_name); ?> Restaurant is officially complete. Below is a summary of the details registered to your account:</p>

                    <!-- Summary of Registered Details -->
                    <table class="details-table">
                        <tr>
                            <td class="details-label">Full Name</td>
                            <td class="details-value"><?php echo htmlspecialchars($user['full_name']); ?></td>
                        </tr>
                        <tr>
                            <td class="details-label">Email Address</td>
                            <td class="details-value"><?php echo htmlspecialchars($user['email']); ?></td>
                        </tr>
                        <tr>
                            <td class="details-label">Phone Number</td>
                            <td class="details-value"><?php echo htmlspecialchars($user['phone']); ?></td>
                        </tr>
                        <tr>
                            <td class="details-label">Date & Time</td>
                            <td class="details-value"><?php echo htmlspecialchars($registration_date); ?></td>
                        </tr>
                    </table>

                    <!-- Security Alert Box -->
                    <div class="security-notice">
                        <div class="security-title">⚠️ Security Alert</div>
                        If you did not perform this registration or did not authorize this account setup, please contact our Guest Relations desk immediately at <a href="mailto:<?php echo htmlspecialchars($support_email); ?>" style="color: #ef4444; font-weight: bold; text-decoration: none;"><?php echo htmlspecialchars($support_email); ?></a> or <span style="font-weight: bold;"><?php echo htmlspecialchars($support_phone); ?></span> to suspend this profile.
                    </div>
                </td>
            </tr>

            <!-- Footer Section -->
            <tr>
                <td class="footer">
                    <p>This is a system transaction confirmation. Please retain this email for your personal security records.</p>
                    <p>&copy; 2026 <?php echo htmlspecialchars($website_name); ?> Restaurant. SCO 44,45, Sector 68, SAS Nagar, Punjab 140308. All Rights Reserved.</p>
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
