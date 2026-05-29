<?php
require_once __DIR__ . '/config.php';

function get_env_var($key, $default = null) {
    static $env = null;
    if ($env === null) {
        $env = [];
        $path = dirname(__DIR__) . '/.env';
        if (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, '#') === 0 || empty($line)) continue;
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $env[trim($parts[0])] = trim($parts[1]);
                }
            }
        }
    }
    return $env[$key] ?? $default;
}

header('Content-Type: application/json');

// Read JSON input
$input = (php_sapi_name() === 'cli') ? file_get_contents('php://stdin') : file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request data.'
    ]);
    exit;
}

$customer_name = $data['customer_name'] ?? 'Customer';
$customer_phone = $data['customer_phone'] ?? '';
$customer_email = $data['customer_email'] ?? '';
$delivery_address = $data['delivery_address'] ?? '';
$message = $data['message'] ?? '';
$payment_id = $data['razorpay_payment_id'] ?? 'MOCK_PAYMENT';
$cart_items = $data['cart_items'] ?? [];

$save_address = !empty($data['save_address']);
$saved_address_id = intval($data['saved_address_id'] ?? 0);
$first_name = trim($data['first_name'] ?? '');
$last_name = trim($data['last_name'] ?? '');
$country = trim($data['country'] ?? '');
$street = trim($data['street'] ?? '');
$apartment = trim($data['apartment'] ?? '');
$city = trim($data['city'] ?? '');
$state = trim($data['state'] ?? '');
$zip = trim($data['zip'] ?? '');

// Calculate totals and build structured item lines
$subtotal = 0;
$items_lines = [];
foreach ($cart_items as $item) {
    $item_price = floatval($item['price']);
    $item_qty = intval($item['quantity']);
    $line_total = $item_price * $item_qty;
    $subtotal += $line_total;
    $items_lines[] = "- " . $item['name'] . " (x" . $item_qty . "): Rs. " . number_format($line_total, 2);
}

// Fallback if cart was empty
if ($subtotal == 0) {
    $subtotal = 15.85;
    $items_lines[] = "- Basic Box (x1): Rs. 15.85";
    $cart_items = [
        ['name' => 'Basic Box', 'price' => 15.85, 'quantity' => 1]
    ];
}

$gst = $subtotal * 0.18;
$delivery = 40.00;
$total = $subtotal + $gst + $delivery;

$order_id = 'ORD-' . strtoupper(substr(uniqid(), 7, 5));

// Resolve host dynamically (use local machine IP if localhost so it's clickable on mobile over Wi-Fi)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if ($host === 'localhost' || $host === '127.0.0.1') {
    $local_ip = gethostbyname(gethostname());
    if ($local_ip && $local_ip !== '127.0.0.1') {
        $host = $local_ip;
    }
}
$invoice_url = $protocol . $host . "/test/order-success.php?order_id=" . $order_id;

// Construct a detailed, professional business bill SMS layout
$items_block = implode("\n", $items_lines);
$sms_message = "=============================\n"
             . "      Medusa BILL\n"
             . "=============================\n"
             . "Order ID: {$order_id}\n"
             . "Date: " . date('d-M-Y H:i') . "\n"
             . "Customer: {$customer_name}\n"
             . "-----------------------------\n"
             . "ITEMS:\n"
             . "{$items_block}\n"
             . "-----------------------------\n"
             . "Subtotal: Rs. " . number_format($subtotal, 2) . "\n"
             . "GST (18%): Rs. " . number_format($gst, 2) . "\n"
             . "Delivery: Rs. " . number_format($delivery, 2) . "\n"
             . "-----------------------------\n"
             . "GRAND TOTAL: Rs. " . number_format($total, 2) . "\n"
             . "-----------------------------\n"
             . "Payment ID: {$payment_id}\n\n"
             . "Download PDF Bill:\n"
             . "{$invoice_url}&print=1\n"
             . "=============================\n"
             . "Thank you for choosing us!";

// SMS Routing logic based on SMS_PROVIDER setting
$sms_provider = get_env_var('SMS_PROVIDER', 'none');
$sms_status = 'not_sent';
$sms_response = '';

if ($sms_provider === 'twilio') {
    $twilio_sid = get_env_var('TWILIO_ACCOUNT_SID');
    $twilio_token = get_env_var('TWILIO_AUTH_TOKEN');
    $twilio_from = get_env_var('TWILIO_FROM_NUMBER');

    if ($twilio_sid && $twilio_token && $twilio_from && $customer_phone) {
        if (strpos($twilio_sid, 'ACxxxxxxxx') === 0 || empty($twilio_sid)) {
            $sms_status = 'api_error';
            $sms_response = json_encode([
                'message' => 'Twilio credentials not configured. Please edit your .env file with your Twilio Account SID, Auth Token, and From Number.'
            ]);
        } else {
            // Format to E.164 format
            $to_phone = trim($customer_phone);
            if (strlen($to_phone) === 10 && is_numeric($to_phone)) {
                $to_phone = '+91' . $to_phone;
            } elseif (strpos($to_phone, '+') !== 0 && is_numeric($to_phone)) {
                $to_phone = '+' . $to_phone;
            }

            $url = "https://api.twilio.com/2010-04-01/Accounts/" . $twilio_sid . "/Messages.json";
            $payload = [
                'From' => $twilio_from,
                'To'   => $to_phone,
                'Body' => $sms_message
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
            curl_setopt($ch, CURLOPT_USERPWD, $twilio_sid . ':' . $twilio_token);

            $response = curl_exec($ch);
            $err = curl_error($ch);
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err) {
                $sms_status = 'error';
                $sms_response = json_encode(['message' => 'cURL Error: ' . $err]);
            } else {
                $res_arr = json_decode($response, true);
                if ($status_code >= 200 && $status_code < 300) {
                    $sms_status = 'success';
                } else {
                    $sms_status = 'api_error';
                    $err_msg = $res_arr['message'] ?? 'Twilio API Error';
                    $sms_response = json_encode(['message' => $err_msg]);
                }
            }
        }
    } else {
        $sms_status = 'api_error';
        $sms_response = json_encode([
            'message' => 'Twilio configuration parameters missing in .env file.'
        ]);
    }
} elseif ($sms_provider === 'android') {
    $gateway_url = get_env_var('ANDROID_GATEWAY_URL');

    if ($gateway_url && $customer_phone) {
        // Format phone to clean numeric string for Simple SMS Gateway
        $to_phone = trim($customer_phone);
        // Strip out country code or special chars if present to leave clean 10-digit number
        if (strpos($to_phone, '+91') === 0) {
            $to_phone = substr($to_phone, 3);
        } elseif (strpos($to_phone, '91') === 0 && strlen($to_phone) === 12) {
            $to_phone = substr($to_phone, 2);
        }
        $to_phone = preg_replace('/[^0-9]/', '', $to_phone);

        // Setup payload based on "Simple SMS Gateway" format: {"phone": "1234567890", "message": "Hello"}
        $payload = [
            'phone' => $to_phone,
            'message' => $sms_message
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $gateway_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            $sms_status = 'error';
            $sms_response = json_encode(['message' => 'cURL Error: ' . $err]);
        } else {
            if ($status_code >= 200 && $status_code < 300) {
                $sms_status = 'success';
            } else {
                $sms_status = 'api_error';
                $sms_response = $response ?: 'HTTP ' . $status_code;
            }
        }
    } else {
        $sms_status = 'api_error';
        $sms_response = json_encode(['message' => 'Android SMS Gateway configuration parameters missing in .env file.']);
    }
}

// Save order to a local JSON file (orders.json)
$orders_file = dirname(__DIR__) . '/orders.json';
$orders = [];
if (file_exists($orders_file)) {
    $orders = json_decode(file_get_contents($orders_file), true) ?: [];
}

$new_order = [
    'order_id' => $order_id,
    'customer_name' => $customer_name,
    'customer_phone' => $customer_phone,
    'customer_email' => $customer_email,
    'delivery_address' => $delivery_address,
    'message' => $message,
    'payment_id' => $payment_id,
    'cart_items' => $cart_items,
    'subtotal' => $subtotal,
    'gst' => $gst,
    'delivery' => $delivery,
    'total' => $total,
    'status' => 'Paid',
    'sms_status' => $sms_status,
    'sms_response' => $sms_response,
    'created_at' => date('Y-m-d H:i:s')
];

$orders[$order_id] = $new_order;
file_put_contents($orders_file, json_encode($orders, JSON_PRETTY_PRINT));

// Save order to MySQL database directly
if (isset($pdo)) {
    try {
        $db_user_id = $_SESSION['user_id'] ?? null;
        $db_status = 'pending'; // Default status for new orders
        
        $ins_order = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_phone, delivery_address, total_amount, order_status, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ins_order->execute([
            $order_id,
            $customer_name,
            $customer_phone,
            $delivery_address,
            $total,
            $db_status,
            $db_user_id
        ]);
        
        $db_order_id = $pdo->lastInsertId();
        
        foreach ($cart_items as $item) {
            $f_stmt = $pdo->prepare("SELECT id FROM food_items WHERE name = ?");
            $f_stmt->execute([$item['name']]);
            $f_item = $f_stmt->fetch();
            $food_item_id = $f_item ? $f_item['id'] : null;
            
            $ins_item = $pdo->prepare("INSERT INTO order_items (order_id, food_item_id, item_name, quantity, price) VALUES (?, ?, ?, ?, ?)");
            $ins_item->execute([
                $db_order_id,
                $food_item_id,
                $item['name'],
                $item['quantity'] ?? 1,
                $item['price'] ?? 0.00
            ]);
        }
        
        // 3. Save or update customer address if requested
        if ($db_user_id !== null && $save_address && !empty($street) && !empty($city) && !empty($state)) {
            $existing_id = null;
            
            if ($saved_address_id > 0) {
                // Verify the selected address belongs to the logged-in user
                $check_owner = $pdo->prepare("SELECT id FROM user_addresses WHERE id = ? AND user_id = ?");
                $check_owner->execute([$saved_address_id, $db_user_id]);
                if ($check_owner->fetch()) {
                    $existing_id = $saved_address_id;
                }
            }
            
            if (!$existing_id) {
                // If not updating by ID, check if there is an address matching the street, city, state, zip for this user
                $check_match = $pdo->prepare("SELECT id FROM user_addresses WHERE user_id = ? AND street = ? AND city = ? AND state = ? AND zip = ?");
                $check_match->execute([$db_user_id, $street, $city, $state, $zip]);
                $matched_addr = $check_match->fetch();
                if ($matched_addr) {
                    $existing_id = $matched_addr['id'];
                }
            }
            
            if ($existing_id) {
                // Update the existing address
                $upd_stmt = $pdo->prepare("
                    UPDATE user_addresses 
                    SET first_name = ?, last_name = ?, phone = ?, email = ?, country = ?, street = ?, apartment = ?, city = ?, state = ?, zip = ?, is_default = 1
                    WHERE id = ?
                ");
                $upd_stmt->execute([
                    $first_name, $last_name, $customer_phone, $customer_email, $country, $street, $apartment, $city, $state, $zip, $existing_id
                ]);
                $address_id = $existing_id;
            } else {
                // Insert as a new address
                $ins_stmt = $pdo->prepare("
                    INSERT INTO user_addresses (user_id, first_name, last_name, phone, email, country, street, apartment, city, state, zip, is_default)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $ins_stmt->execute([
                    $db_user_id, $first_name, $last_name, $customer_phone, $customer_email, $country, $street, $apartment, $city, $state, $zip
                ]);
                $address_id = $pdo->lastInsertId();
            }
            
            // Clear default status of other addresses for this user
            if ($address_id) {
                $clear_defaults = $pdo->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ? AND id != ?");
                $clear_defaults->execute([$db_user_id, $address_id]);
            }
        }
    } catch(PDOException $e) {
        // Log or handle error gracefully so checkout doesn't crash
    }
}

echo json_encode([
    'success' => true,
    'order_id' => $order_id,
    'sms_status' => $sms_status,
    'sms_response' => $sms_response
]);
exit;
