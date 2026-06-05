<?php
require_once __DIR__ . '/api/config.php';
requireLogin();

$order_number = $_GET['order_id'] ?? '';
$order = null;
$order_items = [];

if (!empty($order_number)) {
    try {
        // Query the database for this order, joining users table to get the correct email
        $stmt = $pdo->prepare("SELECT o.*, u.email AS user_email FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE o.order_number = ?");
        $stmt->execute([$order_number]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order) {
            // Access control check: ensure this order belongs to the logged-in customer, or current user is an admin
            $is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
            if (!$is_admin && intval($order['user_id']) !== intval($_SESSION['user_id'])) {
                $error_msg = "Access Denied: You do not have permission to view this invoice.";
                $order = null;
            } else {
                // Fetch the items for this order
                $item_stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
                $item_stmt->execute([$order['id']]);
                $order_items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            $error_msg = "Order not found. Please verify the order number.";
        }
    } catch(PDOException $e) {
        $error_msg = "Database error: " . $e->getMessage();
    }
} else {
    $error_msg = "No order number specified.";
}

// Redirect or show error if order could not be loaded
if (!$order) {
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
            <p><?php echo htmlspecialchars($error_msg ?? 'An unknown error occurred.'); ?></p>
            <a href="my-orders.php" class="btn-back">Return to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Order calculations
$subtotal = 0;
foreach ($order_items as $item) {
    $subtotal += floatval($item['price']) * intval($item['quantity']);
}

// Load Settings
$settings_file = __DIR__ . '/admintest/settings.json';
$settings = [
    'restaurant_name' => 'Medusa',
    'gst_rate' => 18,
    'packing_charge' => 0.00,
    'opening_hours' => '11:00 AM - 11:00 PM'
];
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true) ?: $settings;
}
$gst_rate = isset($settings['gst_rate']) ? intval($settings['gst_rate']) : 18;
$packing_charge = isset($settings['packing_charge']) ? floatval($settings['packing_charge']) : 0.00;

$gst = $subtotal * ($gst_rate / 100);
$delivery_charge = floatval(get_env_var('DELIVERY_CHARGE', '40.00'));
$delivery = (strpos(strtolower($order['delivery_address']), 'table') !== false) ? 0.00 : $delivery_charge;

// Apply packing charge only if order is not dine-in (not at a Table)
$packing = (strpos(strtolower($order['delivery_address']), 'table') !== false) ? 0.00 : $packing_charge;

$grand_total = $subtotal + $gst + $delivery + $packing;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo htmlspecialchars($order['order_number']); ?> - Medusa</title>
    <!-- Global Theme Controller -->
    <script src="assets/js/theme-toggle.js"></script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-dark: #000000;
            --bg-secondary: #0a0a0a;
            --gold: #dfba86;
            --gold-light: #e6c89f;
            --white: #f3f3f3;
            --gray: #a09f9f;
            --success-color: #2ec4b6;
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --border-glass: rgba(223, 186, 134, 0.12);
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

        /* Invoice Glassmorphic Card */
        .invoice-container {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-glass);
            border-radius: 20px;
            padding: 3.5rem;
            max-width: 720px;
            width: 95%;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6);
            position: relative;
            animation: slideUp 0.5s ease-out forwards;
        }

        .brand-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .brand-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--gold);
            letter-spacing: 2px;
            margin: 0;
        }

        .brand-subtitle {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 4px;
            color: var(--gray);
            margin-top: 0.3rem;
        }

        /* Receipt styling */
        .receipt-card {
            background: rgba(255, 255, 255, 0.015);
            border: 1px solid rgba(255, 255, 255, 0.04);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .receipt-meta-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding-bottom: 1.2rem;
            margin-bottom: 1.5rem;
            font-size: 0.88rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .meta-col strong {
            color: #ffffff;
        }

        .meta-col span {
            display: block;
            color: var(--gray);
            margin-top: 0.2rem;
        }

        .address-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 1.5rem;
            font-size: 0.88rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding-bottom: 1.5rem;
        }

        .address-title {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            color: var(--gold);
            margin-bottom: 0.5rem;
        }

        .address-text {
            color: #ffffff;
            line-height: 1.5;
        }

        /* Order Items Table */
        .items-table {
            width: 100%;
            margin-bottom: 1.5rem;
            border-collapse: collapse;
        }

        .items-table th {
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            color: var(--gray);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 0.8rem 0;
            text-align: left;
            font-weight: 600;
        }

        .items-table td {
            padding: 1rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            font-size: 0.92rem;
            color: #ffffff;
        }

        .items-table .text-right {
            text-align: right;
        }

        /* Financial Breakdown */
        .breakdown-list {
            margin-left: auto;
            max-width: 320px;
            font-size: 0.9rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 1rem;
        }

        .breakdown-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.6rem;
            color: var(--gray);
        }

        .breakdown-row.grand-total {
            color: var(--gold);
            font-size: 1.25rem;
            font-weight: 700;
            border-top: 1px dashed rgba(255, 255, 255, 0.15);
            margin-top: 0.8rem;
            padding-top: 0.8rem;
        }

        /* Action Buttons */
        .actions-row {
            display: flex;
            justify-content: center;
            gap: 1.2rem;
            flex-wrap: wrap;
        }

        .btn-luxury {
            background-color: var(--gold);
            color: #000000;
            border: none;
            border-radius: 8px;
            padding: 0.8rem 1.8rem;
            font-size: 0.9rem;
            font-weight: 700;
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .btn-luxury:hover {
            background-color: var(--gold-light);
            color: #000000;
            transform: translateY(-1px);
        }

        .btn-luxury-outline {
            background-color: transparent;
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border-radius: 8px;
            padding: 0.8rem 1.8rem;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-luxury-outline:hover {
            border-color: #ffffff;
            background-color: rgba(255, 255, 255, 0.05);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
                padding: 0;
            }
            .invoice-container {
                box-shadow: none;
                border: none;
                background: #ffffff !important;
                color: #000000 !important;
                width: 100%;
                max-width: 100%;
                padding: 0;
            }
            .receipt-card {
                border: 1px solid #ddd;
                background: #fff !important;
            }
            .actions-row, header {
                display: none !important;
            }
            .items-table td, .items-table th, .breakdown-row {
                color: #000000 !important;
            }
            .breakdown-row.grand-total {
                color: #000000 !important;
                border-top: 1px dashed #000;
            }
            .brand-title, .address-title {
                color: #000000 !important;
            }
        }
        
        @media (max-width: 576px) {
            .address-grid {
                grid-template-columns: 1fr;
            }
            .invoice-container {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>
<body>

    <div class="invoice-container">
        <!-- Brand Title Header -->
        <div class="brand-header" style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
            <img src="assets/images/versace_logo.png" alt="Medusa Logo" style="height: 60px; border-radius: 50%; border: 1.5px solid var(--gold); padding: 3px;">
            <h1 class="brand-title">Medusa</h1>
            <div class="brand-subtitle">Fine Dining & Luxury Catering</div>
        </div>

        <div class="receipt-card">
            <!-- Receipt Metadata Block -->
            <div class="receipt-meta-row">
                <div class="meta-col">
                    <strong>Invoice Number</strong>
                    <span><?php echo htmlspecialchars($order['order_number']); ?></span>
                </div>
                <div class="meta-col">
                    <strong>Date & Time</strong>
                    <span><?php echo date('d M Y, h:i A', strtotime($order['order_date'])); ?></span>
                </div>
                <div class="meta-col">
                    <strong>Payment ID</strong>
                    <span><?php echo htmlspecialchars($order['payment_id'] ?: 'MOCK_PAYMENT'); ?></span>
                </div>
                <div class="meta-col">
                    <strong>Order Status</strong>
                    <span class="text-uppercase" style="color: var(--gold); font-weight: 700;"><?php echo htmlspecialchars($order['order_status']); ?></span>
                </div>
            </div>

            <!-- Address Information Grid -->
            <div class="address-grid">
                <div>
                    <div class="address-title">Customer Details</div>
                    <div class="address-text">
                        <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong><br>
                        Phone: <?php echo htmlspecialchars($order['customer_phone'] ?: 'N/A'); ?><br>
                        Email: <?php echo htmlspecialchars($order['user_email'] ?: 'N/A'); ?>
                    </div>
                </div>
                <div>
                    <div class="address-title">Fulfillment Mode</div>
                    <div class="address-text">
                        <?php if (strpos(strtolower($order['delivery_address']), 'table') !== false): ?>
                            <strong>Dine-In Order</strong><br>
                            Location: <?php echo htmlspecialchars($order['delivery_address']); ?>
                        <?php else: ?>
                            <strong>Home Delivery</strong><br>
                            Address: <?php echo htmlspecialchars($order['delivery_address']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Order Items Details Table -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Dish / Item Name</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Price</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order_items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td class="text-right"><?php echo $item['quantity']; ?></td>
                            <td class="text-right">₹<?php echo number_format($item['price'], 2); ?></td>
                            <td class="text-right">₹<?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Financial Breakdown Summaries -->
            <div class="breakdown-list">
                <div class="breakdown-row">
                    <span>Subtotal</span>
                    <span>₹<?php echo number_format($subtotal, 2); ?></span>
                </div>
                <div class="breakdown-row">
                    <span>GST (<?php echo $gst_rate; ?>%)</span>
                    <span>₹<?php echo number_format($gst, 2); ?></span>
                </div>
                <?php if ($packing > 0): ?>
                <div class="breakdown-row">
                    <span>Packing Charges</span>
                    <span>₹<?php echo number_format($packing, 2); ?></span>
                </div>
                <?php endif; ?>
                <div class="breakdown-row">
                    <span>Delivery / Table Service</span>
                    <span>₹<?php echo number_format($delivery, 2); ?></span>
                </div>
                <div class="breakdown-row grand-total">
                    <span>Grand Total</span>
                    <span>₹<?php echo number_format($grand_total, 2); ?></span>
                </div>
            </div>
        </div>

        <!-- Action Links -->
        <div class="actions-row">
            <button onclick="window.print();" class="btn-luxury">
                <i class="fa-solid fa-print"></i>
                <span>Print Bill</span>
            </button>
            <a href="my-orders.php" class="btn-luxury-outline">
                <i class="fa-solid fa-arrow-left"></i>
                <span>My Dashboard</span>
            </a>
            <a href="menutest.html" class="btn-luxury-outline">
                <i class="fa-solid fa-utensils"></i>
                <span>Order More</span>
            </a>
        </div>
    </div>

</body>
</html>
