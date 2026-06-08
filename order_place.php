<?php
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/includes/pdf.php';
require_once __DIR__ . '/includes/token_helper.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/whatsapp.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

// Read JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid order request data.'
    ]);
    exit;
}

$customer_name = trim($data['customer_name'] ?? 'Customer');
$customer_phone = trim($data['customer_phone'] ?? '');
$customer_email = trim($data['customer_email'] ?? '');
$delivery_address = trim($data['delivery_address'] ?? '');
$delivery_city = trim($data['delivery_city'] ?? '');
$delivery_state = trim($data['delivery_state'] ?? '');
$delivery_pincode = trim($data['delivery_pincode'] ?? '');
$payment_method = trim($data['payment_method'] ?? 'Online');
$cart_items = $data['cart_items'] ?? [];
$coupon_code = trim($data['coupon_code'] ?? '');
$redeem_loyalty_points = !empty($data['redeem_loyalty_points']);

if (empty($customer_phone) || empty($cart_items)) {
    echo json_encode([
        'success' => false,
        'message' => 'Customer phone and cart items are required.'
    ]);
    exit;
}

// Subtotal calculation
$subtotal = 0;
foreach ($cart_items as $item) {
    $item_price = floatval($item['price']);
    $item_qty = intval($item['quantity'] ?? 1);
    $subtotal += ($item_price * $item_qty);
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

$tax_amount = $subtotal * ($gst_rate / 100);
$delivery_charge = floatval(get_env_var('DELIVERY_CHARGE', '40.00'));
$packing_fee = (strpos(strtolower($delivery_address), 'table') !== false) ? 0.00 : $packing_charge;

$db_user_id = $_SESSION['user_id'] ?? null;
$user_tier_discount_percent = 0;
$user_points_balance = 0;
$points_earning_percent = 2.00;
$current_tier_id = 1;

if ($db_user_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.current_tier_id, t.discount_percent, t.points_earning_percent 
            FROM users u 
            LEFT JOIN customer_tiers t ON u.current_tier_id = t.id 
            WHERE u.id = ?
        ");
        $stmt->execute([$db_user_id]);
        $user_tier = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_tier) {
            $current_tier_id = intval($user_tier['current_tier_id'] ?? 1);
            $user_tier_discount_percent = floatval($user_tier['discount_percent'] ?? 10.00);
            $points_earning_percent = floatval($user_tier['points_earning_percent'] ?? 2.00);
        }
        
        $pts_stmt = $pdo->prepare("SELECT current_balance FROM reward_points WHERE user_id = ?");
        $pts_stmt->execute([$db_user_id]);
        $user_points_balance = intval($pts_stmt->fetchColumn() ?: 0);
    } catch (Exception $e) {
        error_log("Failed to fetch user loyalty tier info: " . $e->getMessage());
    }
}

$tier_discount = 0;
if ($db_user_id && $user_tier_discount_percent > 0) {
    $tier_discount = $subtotal * ($user_tier_discount_percent / 100);
}

$total = $subtotal + $tax_amount + $delivery_charge + $packing_fee - $tier_discount;

// Coupon discount validation
$coupon_discount = 0;
$coupon_valid = false;
$coupon_entity = null;
if (!empty($coupon_code)) {
    require_once __DIR__ . '/api/CouponService.php';
    try {
        $couponService = new CouponService($pdo);
        $coupon_entity = $couponService->validateCoupon($coupon_code);
        $coupon_discount = $subtotal * ($coupon_entity->discount_value / 100);
        $coupon_valid = true;
    } catch (Exception $e) {
        // Log and continue without coupon to prevent checkout failure
        error_log("Coupon validation failed: " . $e->getMessage());
    }
}
$total = max(0, $total - $coupon_discount);

// Points redemption
$points_redeemed = 0;
$points_discount = 0;
if ($db_user_id && $redeem_loyalty_points && $user_points_balance > 0) {
    $points_redeemed = min($user_points_balance, intval($total));
    $points_discount = $points_redeemed * 1.00;
    $total = max(0, $total - $points_discount);
}

// Points earned
$points_earned = 0;
if ($db_user_id) {
    $points_earned = round($total * ($points_earning_percent / 100));
}

// Generate unique order number (ORD-XXXXX)
$order_number = 'ORD-' . strtoupper(substr(uniqid(), 7, 5));
$estimated_delivery = date('Y-m-d H:i:s', strtotime('+45 minutes'));

try {
    $pdo->beginTransaction();

    // 1. Insert order into orders table
    $ins_order = $pdo->prepare("
        INSERT INTO orders 
        (order_number, customer_name, customer_phone, delivery_address, delivery_city, delivery_state, delivery_pincode, total_amount, tax_amount, discount, tier_discount_amount, points_redeemed, points_redeemed_discount, points_earned, payment_method, estimated_delivery, user_id, order_status, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')
    ");
    $ins_order->execute([
        $order_number,
        $customer_name,
        $customer_phone,
        $delivery_address,
        $delivery_city,
        $delivery_state,
        $delivery_pincode,
        $total,
        $tax_amount,
        $coupon_discount,
        $tier_discount,
        $points_redeemed,
        $points_discount,
        $points_earned,
        $payment_method,
        $estimated_delivery,
        $db_user_id,
    ]);

    $db_order_id = $pdo->lastInsertId();

    // 2. Insert order items into order_items table
    $items_for_pdf = [];
    foreach ($cart_items as $item) {
        $f_stmt = $pdo->prepare("SELECT id FROM food_items WHERE name = ?");
        $f_stmt->execute([$item['name']]);
        $f_item = $f_stmt->fetch();
        $food_item_id = $f_item ? $f_item['id'] : null;

        $item_price = floatval($item['price']);
        $item_qty = intval($item['quantity'] ?? 1);
        $item_subtotal = $item_price * $item_qty;

        $ins_item = $pdo->prepare("
            INSERT INTO order_items 
            (order_id, food_item_id, item_name, quantity, price, unit_price, subtotal) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $ins_item->execute([
            $db_order_id,
            $food_item_id,
            $item['name'],
            $item_qty,
            $item_price, // price (legacy support)
            $item_price, // unit_price
            $item_subtotal
        ]);

        $items_for_pdf[] = [
            'item_name' => $item['name'],
            'quantity' => $item_qty,
            'unit_price' => $item_price,
            'subtotal' => $item_subtotal
        ];
    }

    // Prepare variables for PDF generation
    $order_data = [
        'id' => $db_order_id,
        'order_number' => $order_number,
        'order_date' => date('Y-m-d H:i:s'),
        'customer_name' => $customer_name,
        'customer_phone' => $customer_phone,
        'delivery_address' => $delivery_address,
        'delivery_city' => $delivery_city,
        'delivery_state' => $delivery_state,
        'delivery_pincode' => $delivery_pincode,
        'total_amount' => $total,
        'tax_amount' => $tax_amount,
        'discount' => $coupon_discount,
        'tier_discount_amount' => $tier_discount,
        'points_redeemed' => $points_redeemed,
        'points_redeemed_discount' => $points_discount,
        'points_earned' => $points_earned,
        'payment_method' => $payment_method,
        'estimated_delivery' => $estimated_delivery,
        'packing_charge' => $packing_fee,
        'delivery_charge' => $delivery_charge
    ];

    // 3. Generate PDF bill using TCPDF
    $pdf_relative_path = generateBillPdf($order_data, $items_for_pdf);

    // 4. Update order with PDF path
    $upd_pdf = $pdo->prepare("UPDATE orders SET pdf_path = ? WHERE id = ?");
    $upd_pdf->execute([$pdf_relative_path, $db_order_id]);

    // Redeem coupon inside the transaction if valid
    if ($coupon_valid && $coupon_entity) {
        $couponService->redeemCoupon($coupon_code, $db_order_id);
    }

    // Process Loyalty points & Tier progression
    if ($db_user_id) {
        // Deduct points
        if ($points_redeemed > 0) {
            $upd_pts = $pdo->prepare("
                UPDATE reward_points 
                SET points_redeemed = points_redeemed + ?, 
                    current_balance = current_balance - ? 
                WHERE user_id = ?
            ");
            $upd_pts->execute([$points_redeemed, $points_redeemed, $db_user_id]);
            
            $ins_trans = $pdo->prepare("
                INSERT INTO loyalty_transactions 
                (user_id, order_id, points_redeemed, transaction_type) 
                VALUES (?, ?, ?, 'redeem')
            ");
            $ins_trans->execute([$db_user_id, $db_order_id, $points_redeemed]);
        }

        // Earn points
        if ($points_earned > 0) {
            $upd_pts2 = $pdo->prepare("
                UPDATE reward_points 
                SET points_earned = points_earned + ?, 
                    current_balance = current_balance + ? 
                WHERE user_id = ?
            ");
            $upd_pts2->execute([$points_earned, $points_earned, $db_user_id]);
            
            $ins_trans2 = $pdo->prepare("
                INSERT INTO loyalty_transactions 
                (user_id, order_id, points_earned, transaction_type) 
                VALUES (?, ?, ?, 'earn')
            ");
            $ins_trans2->execute([$db_user_id, $db_order_id, $points_earned]);
        }

        // Calculate user's updated lifetime spend
        $spend_stmt = $pdo->prepare("
            SELECT SUM(total_amount) 
            FROM orders 
            WHERE user_id = ? AND order_status != 'cancelled'
        ");
        $spend_stmt->execute([$db_user_id]);
        $lifetime_spend = floatval($spend_stmt->fetchColumn() ?: 0.00);

        // Fetch all tiers to see if eligible for upgrade
        $tiers_stmt = $pdo->query("SELECT * FROM customer_tiers ORDER BY spending_requirement ASC");
        $all_tiers = $tiers_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $eligible_tier_id = 1;
        $eligible_tier_name = 'Silver';
        $eligible_discount_percent = 10.00;
        foreach ($all_tiers as $t) {
            if ($lifetime_spend >= floatval($t['spending_requirement'])) {
                $eligible_tier_id = intval($t['id']);
                $eligible_tier_name = $t['tier_name'];
                $eligible_discount_percent = floatval($t['discount_percent']);
            }
        }

        if ($eligible_tier_id > $current_tier_id) {
            $upd_user_tier = $pdo->prepare("UPDATE users SET current_tier_id = ? WHERE id = ?");
            $upd_user_tier->execute([$eligible_tier_id, $db_user_id]);
            
            $ins_history = $pdo->prepare("
                INSERT INTO tier_history (user_id, previous_tier_id, new_tier_id, reason) 
                VALUES (?, ?, ?, 'Spending threshold met')
            ");
            $ins_history->execute([$db_user_id, $current_tier_id, $eligible_tier_id]);
            
            $notif_title = "Loyalty Tier Upgraded!";
            $notif_msg = "Congratulations! Your lifetime spend has reached ₹" . number_format($lifetime_spend, 2) . ". You have been promoted to the " . $eligible_tier_name . " tier, giving you a " . $eligible_tier_name . " tier discount of " . $eligible_discount_percent . "% on future orders.";
            
            $ins_notif = $pdo->prepare("
                INSERT INTO user_notifications (user_id, title, message) 
                VALUES (?, ?, ?)
            ");
            $ins_notif->execute([$db_user_id, $notif_title, $notif_msg]);
        }
    }
        // Trigger notification triggers for admin panel
        require_once __DIR__ . '/includes/notifications_helper.php';
        
        // 1. Order notification
        $order_notif_body = "Order {$order_number} has been placed by {$customer_name} via {$payment_method}. Total amount: ₹" . number_format($total, 2);
        addNotification('order', 'New Order Received', $order_notif_body);

        // 2. Payment notification
        $payment_notif_body = "Payment of ₹" . number_format($total, 2) . " processed successfully for order {$order_number}.";
        addNotification('payment', 'Payment Successful', $payment_notif_body);

        // 3. Kitchen notification (special requests)
        $special_req = trim($data['message'] ?? '');
        if (!empty($special_req)) {
            $kitchen_notif_body = "Special request added for order {$order_number}: \"{$special_req}\"";
            addNotification('kitchen', 'Special Request Added', $kitchen_notif_body);
        }

        $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save order to database: ' . $e->getMessage()
    ]);
    exit;
}

// Generate secure download link
$download_token = generateToken($db_order_id);
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if ($host === 'localhost' || $host === '127.0.0.1') {
    $local_ip = gethostbyname(gethostname());
    if ($local_ip && $local_ip !== '127.0.0.1') {
        $host = $local_ip;
    }
}
$download_url = $protocol . $host . "/restaurant_medusa/download_bill.php?id=" . $db_order_id . "&token=" . $download_token;

$logFile = __DIR__ . '/otp_log.txt';
$timestamp = date('Y-m-d H:i:s');

// ==========================================
// Delivery Channels
// ==========================================

// 1. Send SMS with PDF download link (Simple SMS Gateway)
$sms_sent = sendOrderSms($customer_phone, $customer_name, $order_number, $download_url);
$sms_status = $sms_sent ? 'sent_gateway' : 'failed_gateway';


// 2. Send Email with PDF attached (PHPMailer)
if (!empty($customer_email)) {
    $user_data = [
        'email' => $customer_email,
        'full_name' => $customer_name,
        'phone' => $customer_phone
    ];
    sendBillEmail($user_data, $order_data, $pdf_relative_path);
}

// 3. Send WhatsApp with PDF attached
sendWhatsappBill($customer_phone, $order_data, $pdf_relative_path);

echo json_encode([
    'success' => true,
    'order_id' => $order_number, // return ORD-XXXXX as order_id for success page routing
    'sms_status' => $sms_status
]);
exit;
