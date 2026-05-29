<?php
require_once __DIR__ . '/api/config.php';

$order_id = $_GET['order_id'] ?? '';
$order = null;
$error_msg = null;

if (!empty($order_id)) {
    // 1. Database access control check
    try {
        $stmt = $pdo->prepare("SELECT user_id FROM orders WHERE order_number = ?");
        $stmt->execute([$order_id]);
        $db_order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($db_order) {
            $db_user_id = $db_order['user_id'];
            $session_user_id = $_SESSION['user_id'] ?? null;
            
            // If the order has an associated user, only that user or an admin can view it
            if ($db_user_id !== null) {
                $is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
                if (!$is_admin && ($session_user_id === null || intval($db_user_id) !== intval($session_user_id))) {
                    $error_msg = "Access Denied: You do not have permission to view this order details.";
                }
            }
        }
    } catch (PDOException $e) {
        // ignore or handle database query failure
    }
    
    // 2. Fetch order details from orders.json if access not denied
    if (!$error_msg) {
        $orders_file = __DIR__ . '/orders.json';
        if (file_exists($orders_file)) {
            $orders = json_decode(file_get_contents($orders_file), true);
            if (isset($orders[$order_id])) {
                $order = $orders[$order_id];
            }
        }
    }
}

// Redirect or show error if access is denied
if ($error_msg) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error - Medusa Luxury</title>
        <!-- Global Theme Controller -->
        <script src="assets/js/theme-toggle.js"></script>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600&display=swap" rel="stylesheet">
        <style>
            body { background: #000000; color: #ffffff; font-family: 'Plus Jakarta Sans', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
            .error-card { background: #0a0a0a; border: 1px solid rgba(223, 186, 134, 0.15); border-radius: 16px; padding: 3rem; text-align: center; max-width: 500px; width: 90%; }
            .btn-back { background: #dfba86; color: #000; font-weight: 600; text-decoration: none; padding: 0.8rem 1.5rem; border-radius: 8px; display: inline-block; margin-top: 1.5rem; }
        </style>
    </head>
    <body>
        <div class="error-card">
            <h2 class="text-danger mb-3">Error</h2>
            <p><?php echo htmlspecialchars($error_msg); ?></p>
            <a href="menutest.html" class="btn-back">Go to Menu</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Fallback to mock order if not found (useful for testing/demoing)
if (!$order) {
    $order = [
        'order_id' => 'ORD-DEMO',
        'customer_name' => 'John Doe',
        'customer_phone' => '9876543210',
        'customer_email' => 'johndoe@example.com',
        'delivery_address' => '123, Main Street, Apt 4B, New York, Delhi - 10001, India',
        'message' => 'Please deliver hot and fresh.',
        'payment_id' => 'pay_test_payment_id',
        'cart_items' => [
            ['name' => 'Premium Margherita Pizza', 'price' => 299.00, 'quantity' => 1],
            ['name' => 'Paneer Tikka', 'price' => 199.00, 'quantity' => 2]
        ],
        'subtotal' => 697.00,
        'gst' => 125.46,
        'delivery' => 40.00,
        'total' => 862.46,
        'status' => 'Paid',
        'created_at' => date('Y-m-d H:i:s')
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Placed Successfully - Medusa</title>
    <!-- Global Theme Controller -->
    <script src="assets/js/theme-toggle.js"></script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        :root {
            --bg-dark: #0a0a0a;
            --bg-secondary: #121111;
            --gold: #dfba86;
            --gold-light: #e6c89f;
            --white: #f3f3f3;
            --gray: #a09f9f;
            --success-color: #2ec4b6;
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        body {
            background-color: var(--bg-dark) !important;
            color: var(--white) !important;
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 3rem 0;
        }

        /* Success Card & Glassmorphic Container */
        .success-card {
            background-color: var(--bg-secondary);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            padding: 3rem;
            max-width: 680px;
            width: 95%;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            text-align: center;
            position: relative;
            overflow: hidden;
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        /* Checkmark Animation */
        .success-icon-container {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(46, 196, 182, 0.1);
            border: 2px solid var(--success-color);
            display: inline-flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 1.5rem;
            color: var(--success-color);
            font-size: 2.2rem;
            animation: scaleIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        .success-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.5rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.5rem;
        }

        .success-subtitle {
            color: var(--gray);
            font-size: 1rem;
            margin-bottom: 2rem;
        }

        /* SMS Confirmation Badge */
        .sms-badge {
            background: rgba(223, 186, 134, 0.08);
            border: 1px solid rgba(223, 186, 134, 0.2);
            color: var(--gold-light);
            border-radius: 12px;
            padding: 0.8rem 1.2rem;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            margin-bottom: 2.5rem;
            animation: fadeIn 0.8s ease forwards;
            font-weight: 500;
        }

        .sms-badge i {
            color: var(--gold);
            font-size: 1.1rem;
        }

        /* Invoice Container */
        .invoice-box {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 2rem;
            text-align: left;
            margin-bottom: 2.5rem;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .invoice-header h4 {
            font-family: 'Playfair Display', serif;
            color: #ffffff;
            font-weight: 700;
            margin: 0;
        }

        .invoice-meta {
            color: var(--gray);
        }

        .invoice-meta span {
            display: block;
            margin-bottom: 0.2rem;
        }

        .invoice-meta strong {
            color: #ffffff;
        }

        /* Billing/Shipping Address Grid */
        .address-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 1.5rem;
            font-size: 0.88rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding-bottom: 1.5rem;
        }

        .address-col-title {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.78rem;
            letter-spacing: 1px;
            color: var(--gold);
            margin-bottom: 0.5rem;
        }

        .address-text {
            color: var(--gray);
            line-height: 1.5;
        }

        /* Invoice Table */
        .invoice-table {
            width: 100%;
            margin-bottom: 1.5rem;
        }

        .invoice-table th {
            color: var(--gray);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 0.6rem 0;
            font-weight: 600;
        }

        .invoice-table td {
            padding: 0.8rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            font-size: 0.92rem;
        }

        .item-total {
            text-align: right;
            font-weight: 600;
            color: #ffffff;
        }

        /* Summary Total Rows */
        .totals-section {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 1rem;
            font-size: 0.92rem;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.6rem;
        }

        .total-row.grand-total {
            font-size: 1.2rem;
            font-weight: 700;
            border-top: 1.5px dashed rgba(255, 255, 255, 0.1);
            padding-top: 0.8rem;
            margin-top: 0.8rem;
            color: var(--gold-light);
        }

        .total-row.grand-total span:last-child {
            font-size: 1.35rem;
            color: var(--gold);
            font-weight: 800;
        }

        /* Action Buttons */
        .btn-group-success {
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .btn-success-action {
            background-color: var(--gold);
            color: #0c0a0a;
            border: none;
            border-radius: 8px;
            padding: 0.9rem 1.8rem;
            font-weight: 700;
            font-size: 0.95rem;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-success-action:hover {
            background-color: var(--gold-light);
            transform: translateY(-2px);
            color: #0c0a0a;
        }

        .btn-secondary-action {
            background-color: transparent;
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 0.9rem 1.8rem;
            font-weight: 600;
            font-size: 0.95rem;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary-action:hover {
            border-color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
        }

        /* Animations */
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.6);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Printable styles */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                padding: 0;
            }
            .success-card {
                border: none;
                box-shadow: none;
                background: #ffffff;
                color: #000000 !important;
                padding: 0;
                max-width: 100%;
                width: 100%;
            }
            .success-icon-container, 
            .sms-badge, 
            .btn-group-success,
            .success-subtitle {
                display: none !important;
            }
            .success-title {
                color: #000000 !important;
                font-size: 2rem;
                margin-bottom: 2rem;
            }
            .invoice-box {
                background: #ffffff !important;
                border: 1px solid #cccccc !important;
                color: #000000 !important;
                padding: 1.5rem;
            }
            .invoice-header h4,
            .invoice-meta strong,
            .address-col-title,
            .item-total,
            .total-row.grand-total,
            .total-row.grand-total span:last-child {
                color: #000000 !important;
            }
            .address-text, .invoice-table td, .total-row {
                color: #333333 !important;
            }
            .invoice-table th {
                color: #555555 !important;
                border-bottom: 1px solid #999999 !important;
            }
            .invoice-table td {
                border-bottom: 1px solid #eeeeee !important;
            }
        }
    </style>
</head>
<body>

    <div class="success-card">
        <div class="success-icon-container">
            <i class="fas fa-check"></i>
        </div>
        
        <h1 class="success-title">Payment Successful</h1>
        <p class="success-subtitle">Thank you! Your order has been placed and is being prepared.</p>

        <?php
        $sms_status = $order['sms_status'] ?? 'not_checked';
        $sms_response = $order['sms_response'] ?? '';
        
        if ($sms_status !== 'not_sent'):
            if ($sms_status === 'success'): ?>
                <div class="sms-badge">
                    <i class="fas fa-check-circle" style="color: var(--success-color);"></i>
                    <span>Bill details sent via SMS to <strong>+91 <?php echo htmlspecialchars(substr($order['customer_phone'], 0, 5) . ' ' . substr($order['customer_phone'], 5)); ?></strong></span>
                </div>
            <?php elseif ($sms_status === 'api_error' || $sms_status === 'error'): ?>
                <div class="sms-badge" style="background: rgba(230, 57, 70, 0.08); border-color: rgba(230, 57, 70, 0.2); color: #ffb3b8;">
                    <i class="fas fa-exclamation-triangle" style="color: var(--accent);"></i>
                    <span>SMS failed: 
                        <strong>
                            <?php 
                            $res_decoded = json_decode($sms_response, true);
                            echo htmlspecialchars($res_decoded['message'] ?? $sms_response ?? 'API Error');
                            ?>
                        </strong>
                    </span>
                </div>
            <?php else: ?>
                <div class="sms-badge">
                    <i class="fas fa-sms"></i>
                    <span>Bill details sent via SMS to <strong>+91 <?php echo htmlspecialchars(substr($order['customer_phone'], 0, 5) . ' ' . substr($order['customer_phone'], 5)); ?></strong></span>
                </div>
            <?php endif;
        endif; ?>

        <div class="invoice-box" id="invoice">
            <div class="invoice-header">
                <div>
                    <h4>Medusa Invoice</h4>
                    <div class="invoice-meta mt-1">
                        <span>Invoice No: <strong><?php echo htmlspecialchars($order['order_id']); ?></strong></span>
                        <span>Date: <strong><?php echo htmlspecialchars(date('F j, Y, g:i a', strtotime($order['created_at']))); ?></strong></span>
                    </div>
                </div>
                <div class="text-end">
                    <div class="invoice-meta">
                        <span>Status: <strong class="text-success">Paid</strong></span>
                        <span>Payment ID: <strong><?php echo htmlspecialchars($order['payment_id']); ?></strong></span>
                    </div>
                </div>
            </div>

            <div class="address-grid">
                <div>
                    <div class="address-col-title">Customer Details</div>
                    <div class="address-text">
                        <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong><br>
                        Phone: <?php echo htmlspecialchars($order['customer_phone']); ?><br>
                        Email: <?php echo htmlspecialchars($order['customer_email']); ?>
                    </div>
                </div>
                <div>
                    <div class="address-col-title">Delivery Address</div>
                    <div class="address-text">
                        <?php echo nl2br(htmlspecialchars($order['delivery_address'])); ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($order['message'])): ?>
                <div class="mb-4" style="font-size: 0.88rem;">
                    <div class="address-col-title">Order Notes</div>
                    <div class="address-text" style="font-style: italic;">
                        "<?php echo htmlspecialchars($order['message']); ?>"
                    </div>
                </div>
            <?php endif; ?>

            <table class="invoice-table">
                <thead>
                    <tr>
                        <th style="width: 70%;">Item Description</th>
                        <th style="width: 10%; text-align: center;">Qty</th>
                        <th style="width: 20%; text-align: right;">Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order['cart_items'] as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                            <td style="text-align: center;"><?php echo intval($item['quantity']); ?></td>
                            <td class="item-total">₹<?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals-section">
                <div class="total-row">
                    <span>Subtotal</span>
                    <span>₹<?php echo number_format($order['subtotal'], 2); ?></span>
                </div>
                <div class="total-row">
                    <span>GST (18%)</span>
                    <span>₹<?php echo number_format($order['gst'], 2); ?></span>
                </div>
                <div class="total-row">
                    <span>Delivery Fee</span>
                    <span>₹<?php echo number_format($order['delivery'], 2); ?></span>
                </div>
                <div class="total-row grand-total">
                    <span>Grand Total</span>
                    <span>₹<?php echo number_format($order['total'], 2); ?></span>
                </div>
            </div>
        </div>

        <div class="btn-group-success">
            <a href="menutest.html" class="btn-success-action">
                <i class="fas fa-arrow-left"></i> Order More Food
            </a>
            <button onclick="window.print()" class="btn-secondary-action">
                <i class="fas fa-print"></i> Print Invoice
            </button>
        </div>
    <script>
        // Auto-trigger print/PDF download dialog if the print parameter is set to 1
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('print') === '1') {
            window.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    window.print();
                }, 1000); // 1-second delay to ensure graphics and animations load completely
            });
        }
    </script>
</body>
</html>
