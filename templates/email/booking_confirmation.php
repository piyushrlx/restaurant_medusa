<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed — #BK-<?php echo htmlspecialchars($booking['id']); ?></title>
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
        .summary-card {
            background-color: rgba(212, 175, 55, 0.03);
            border: 1px solid rgba(212, 175, 55, 0.1);
            border-radius: 12px;
            padding: 20px;
            margin: 25px 0;
        }
        .summary-table {
            width: 100%;
        }
        .summary-table td {
            padding: 8px 0;
            font-size: 14px;
        }
        .summary-label {
            color: #a5a5a5;
            font-weight: 500;
            width: 150px;
        }
        .summary-val {
            color: #e5e5e5;
            font-weight: bold;
        }
        .important-note {
            background-color: rgba(212, 175, 55, 0.08);
            border-left: 3px solid #d4af37;
            padding: 12px 18px;
            border-radius: 4px;
            margin: 25px 0;
            font-size: 14px;
            color: #e5e5e5;
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
                    <div class="greeting">Hi <?php echo htmlspecialchars($booking['customer_name']); ?>,</div>
                    <p>We are delighted to confirm that your table reservation at <?php echo htmlspecialchars($website_name); ?> is secured! Your booking details are summarized below:</p>
                    
                    <!-- Booking Summary Card -->
                    <div class="summary-card">
                        <table class="summary-table">
                            <tr>
                                <td class="summary-label">Booking Reference:</td>
                                <td class="summary-val" style="color: #d4af37;">#BK-<?php echo htmlspecialchars($booking['id']); ?></td>
                            </tr>
                            <tr>
                                <td class="summary-label">Guest Name:</td>
                                <td class="summary-val"><?php echo htmlspecialchars($booking['customer_name']); ?></td>
                            </tr>
                            <tr>
                                <td class="summary-label">Phone & Email:</td>
                                <td class="summary-val"><?php echo htmlspecialchars($booking['customer_phone']); ?> | <?php echo htmlspecialchars($booking['customer_email']); ?></td>
                            </tr>
                            <tr>
                                <td class="summary-label">Date & Time:</td>
                                <td class="summary-val"><?php echo htmlspecialchars($booking['booking_date']); ?> at <?php echo htmlspecialchars($booking['booking_time']); ?></td>
                            </tr>
                            <tr>
                                <td class="summary-label">No. of Guests:</td>
                                <td class="summary-val"><?php echo htmlspecialchars($booking['guests']); ?> Guests</td>
                            </tr>
                            <?php if (!empty($booking['table_number'])): ?>
                            <tr>
                                <td class="summary-label">Assigned Table/Zone:</td>
                                <td class="summary-val"><?php echo htmlspecialchars($booking['table_number']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($booking['special_requests'])): ?>
                            <tr>
                                <td class="summary-label">Special Requests:</td>
                                <td class="summary-val" style="font-weight: normal; font-style: italic; color: #b5b5b5;"><?php echo htmlspecialchars($booking['special_requests']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <!-- Note -->
                    <div class="important-note">
                        <strong>Important:</strong> Please arrive 10 minutes before your booking time. We will hold your table for a maximum of 15 minutes past your scheduled reservation.
                    </div>

                    <p>We look forward to welcoming you for an extraordinary gastronomic experience.</p>
                </td>
            </tr>

            <!-- Support Details -->
            <tr>
                <td class="support-info">
                    <div class="support-title">Cancellation & Modifications</div>
                    To modify or cancel your booking, please contact our concierge desk directly at:
                    <div style="margin-top: 10px;">
                        Venue: <strong><?php echo htmlspecialchars($booking['venue_name']); ?></strong>, <?php echo htmlspecialchars($booking['venue_address']); ?><br>
                        Concierge: <span style="color: #e5e5e5;"><?php echo htmlspecialchars($booking['venue_phone']); ?></span> &nbsp;|&nbsp; 
                        Email: <a href="mailto:<?php echo htmlspecialchars($support_email); ?>" style="color: #d4af37; text-decoration: none;"><?php echo htmlspecialchars($support_email); ?></a>
                    </div>
                </td>
            </tr>

            <!-- Footer Section -->
            <tr>
                <td class="footer">
                    <p>This is a transactional reservation confirmation sent on behalf of <?php echo htmlspecialchars($website_name); ?>.</p>
                    <p>&copy; 2026 <?php echo htmlspecialchars($website_name); ?> Restaurant. SCO 44,45, Sector 68, SAS Nagar, Punjab 140308. All Rights Reserved.</p>
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
