<?php
require_once dirname(__DIR__) . '/api/config.php';
requireAdmin();

// Ensure feedback table exists before running any queries
try {
    $createTableQuery = "
        CREATE TABLE IF NOT EXISTS `feedback` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_number` VARCHAR(20) NOT NULL,
            `rating` INT NOT NULL,
            `review` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT `fk_feedback_orders` FOREIGN KEY (`order_number`) REFERENCES `orders` (`order_number`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createTableQuery);
} catch (PDOException $e) {
    // Fail silently
}

// Helper to render gold stars based on feedback rating
function renderStars($rating, $review = '') {
    $rating = intval($rating);
    if ($rating < 1 || $rating > 5) return '';
    $starsHtml = '<div class="feedback-stars mt-1" style="color: #dfba86; font-size: 0.85rem;" title="' . htmlspecialchars($rating) . '/5 Stars' . (!empty($review) ? ': ' . htmlspecialchars($review) : '') . '">';
    for ($i = 1; $i <= 5; $i++) {
        $starsHtml .= ($i <= $rating) ? '★' : '☆';
    }
    $starsHtml .= '</div>';
    return $starsHtml;
}

// Helper to determine if a dish is Vegetarian based on name/description keywords
function isVegItem($name, $description = '') {
    $non_veg_keywords = ['chicken', 'biryani', 'rogan', 'josh', 'lamb', 'mutton', 'pork', 'fish', 'prawn', 'shrimp', 'pepperoni', 'meat', 'wings', 'ribs', 'chashu', 'bolognese', 'lasagna', 'bacon', 'beef', 'duck', 'egg'];
    $text = strtolower($name . ' ' . $description);
    foreach ($non_veg_keywords as $keyword) {
        if (strpos($text, $keyword) !== false) {
            return false;
        }
    }
    return true;
}

// Helper to determine date boundaries for report filtering
function getDateBounds($range, $start_custom = null, $end_custom = null) {
    date_default_timezone_set('Asia/Kolkata');
    $start = new DateTime();
    $end = new DateTime();
    
    switch ($range) {
        case 'today':
            $start->setTime(0, 0, 0);
            $end->setTime(23, 59, 59);
            break;
        case 'yesterday':
            $start->modify('-1 day')->setTime(0, 0, 0);
            $end->modify('-1 day')->setTime(23, 59, 59);
            break;
        case 'thisweek':
            $start->modify('this week')->setTime(0, 0, 0);
            $end->setTime(23, 59, 59);
            break;
        case 'lastweek':
            $start->modify('last week')->setTime(0, 0, 0);
            $end = clone $start;
            $end->modify('+6 days')->setTime(23, 59, 59);
            break;
        case 'thismonth':
            $start->modify('first day of this month')->setTime(0, 0, 0);
            $end->setTime(23, 59, 59);
            break;
        case 'lastmonth':
            $start->modify('first day of last month')->setTime(0, 0, 0);
            $end = clone $start;
            $end->modify('last day of this month')->setTime(23, 59, 59);
            break;
        case 'thisyear':
            $start->setDate(intval(date('Y')), 1, 1)->setTime(0, 0, 0);
            $end->setTime(23, 59, 59);
            break;
        case 'custom':
            if ($start_custom) {
                $start = new DateTime($start_custom . ' 00:00:00');
            }
            if ($end_custom) {
                $end = new DateTime($end_custom . ' 23:59:59');
            }
            break;
    }
    
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

// 1. Sync orders.json to MySQL database
function syncOrdersJsonToDb($pdo) {
    $orders_file = dirname(__DIR__) . '/orders.json';
    if (!file_exists($orders_file)) return;
    
    $json_content = file_get_contents($orders_file);
    $orders = json_decode($json_content, true) ?: [];
    
    foreach ($orders as $order_id => $data) {
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_number = ?");
        $stmt->execute([$order_id]);
        $exists = $stmt->fetch();
        
        if (!$exists) {
            $status = strtolower($data['status'] ?? 'pending');
            if ($status === 'paid') {
                $status = 'pending';
            }
            
            $delivery_address = $data['delivery_address'] ?? '';
            $ins_order = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_phone, delivery_address, total_amount, order_status, order_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins_order->execute([
                $order_id,
                $data['customer_name'] ?? 'Customer',
                $data['customer_phone'] ?? '',
                $delivery_address,
                $data['total'] ?? 0.00,
                $status,
                $data['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            
            $db_order_id = $pdo->lastInsertId();
            
            $cart_items = $data['cart_items'] ?? [];
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
        }
    }
}

// Perform sync
syncOrdersJsonToDb($pdo);

// 2. Load Settings
$settings_file = __DIR__ . '/settings.json';
$settings = [
    'restaurant_name' => 'Medusa',
    'gst_rate' => 18,
    'packing_charge' => 0.00,
    'opening_hours' => '11:00 AM - 11:00 PM'
];
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true) ?: $settings;
}

// 3. API Handlers
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];
    
    // Search Orders Endpoint
    if ($action === 'search_orders') {
        $sql = "SELECT *, (SELECT rating FROM feedback WHERE order_number = orders.order_number LIMIT 1) AS rating, (SELECT review FROM feedback WHERE order_number = orders.order_number LIMIT 1) AS review FROM orders WHERE 1=1";
        $params = [];
        
        if (!empty($_POST['search'])) {
            $sql .= " AND (order_number LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ? OR delivery_address LIKE ?)";
            $wildcard = "%" . $_POST['search'] . "%";
            $params[] = $wildcard; $params[] = $wildcard; $params[] = $wildcard; $params[] = $wildcard;
        }
        
        if (!empty($_POST['status']) && $_POST['status'] !== 'all') {
            $sql .= " AND order_status = ?";
            $params[] = $_POST['status'];
        }
        
        if (!empty($_POST['payment_status']) && $_POST['payment_status'] !== 'all') {
            if ($_POST['payment_status'] === 'paid') {
                $sql .= " AND order_status = 'completed'";
            } elseif ($_POST['payment_status'] === 'unpaid') {
                $sql .= " AND order_status != 'completed'";
            }
        }
        
        if (!empty($_POST['type']) && $_POST['type'] !== 'all') {
            if ($_POST['type'] === 'online') {
                $sql .= " AND delivery_address NOT LIKE 'Table %'";
            } elseif ($_POST['type'] === 'dinein') {
                $sql .= " AND delivery_address LIKE 'Table %'";
            }
        }
        
        if (!empty($_POST['date'])) {
            if ($_POST['date'] === 'today') {
                $sql .= " AND DATE(order_date) = CURDATE()";
            } elseif ($_POST['date'] === 'yesterday') {
                $sql .= " AND DATE(order_date) = SUBDATE(CURDATE(), 1)";
            } elseif ($_POST['date'] === '7days') {
                $sql .= " AND order_date >= SUBDATE(NOW(), INTERVAL 7 DAY)";
            } elseif ($_POST['date'] === '30days') {
                $sql .= " AND order_date >= SUBDATE(NOW(), INTERVAL 30 DAY)";
            } elseif ($_POST['date'] === 'custom' && !empty($_POST['start_date']) && !empty($_POST['end_date'])) {
                $sql .= " AND DATE(order_date) BETWEEN ? AND ?";
                $params[] = $_POST['start_date'];
                $params[] = $_POST['end_date'];
            }
        }
        
        if (isset($_POST['min_amount']) && $_POST['min_amount'] !== '') {
            $sql .= " AND total_amount >= ?";
            $params[] = floatval($_POST['min_amount']);
        }
        if (isset($_POST['max_amount']) && $_POST['max_amount'] !== '') {
            $sql .= " AND total_amount <= ?";
            $params[] = floatval($_POST['max_amount']);
        }
        
        $sql .= " ORDER BY id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($orders as &$order) {
            $item_stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $item_stmt->execute([$order['id']]);
            $order['items'] = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode(['success' => true, 'orders' => $orders]);
        exit;
    }
    
    // Search Menu Endpoint
    if ($action === 'search_menu') {
        $sql = "SELECT * FROM food_items WHERE 1=1";
        $params = [];
        
        if (!empty($_POST['search'])) {
            $sql .= " AND (name LIKE ? OR category LIKE ? OR description LIKE ?)";
            $wildcard = "%" . $_POST['search'] . "%";
            $params[] = $wildcard; $params[] = $wildcard; $params[] = $wildcard;
        }
        
        if (isset($_POST['availability']) && $_POST['availability'] !== 'all') {
            $sql .= " AND is_available = ?";
            $params[] = intval($_POST['availability']);
        }
        
        if (isset($_POST['min_price']) && $_POST['min_price'] !== '') {
            $sql .= " AND price >= ?";
            $params[] = floatval($_POST['min_price']);
        }
        if (isset($_POST['max_price']) && $_POST['max_price'] !== '') {
            $sql .= " AND price <= ?";
            $params[] = floatval($_POST['max_price']);
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Bestsellers list based on quantities
        $best_stmt = $pdo->query("SELECT food_item_id, SUM(quantity) as qty FROM order_items GROUP BY food_item_id ORDER BY qty DESC LIMIT 10");
        $bestsellers = $best_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        
        $filtered = [];
        foreach ($items as $dish) {
            $is_veg = isVegItem($dish['name'], $dish['description']);
            $is_bestseller = in_array($dish['id'], $bestsellers);
            
            if (!empty($_POST['diet_type']) && $_POST['diet_type'] !== 'all') {
                if ($_POST['diet_type'] === 'veg' && !$is_veg) continue;
                if ($_POST['diet_type'] === 'nonveg' && $is_veg) continue;
            }
            
            if (isset($_POST['bestseller']) && $_POST['bestseller'] === '1' && !$is_bestseller) {
                continue;
            }
            
            $dish['is_veg'] = $is_veg ? 1 : 0;
            $dish['is_bestseller'] = $is_bestseller ? 1 : 0;
            
            $cc = $pdo->prepare("SELECT COUNT(*) FROM dish_customizations WHERE food_item_id = ?");
            $cc->execute([$dish['id']]);
            $dish['cust_count'] = (int)$cc->fetchColumn();
            
            $filtered[] = $dish;
        }
        
        echo json_encode(['success' => true, 'menu' => $filtered]);
        exit;
    }
    
    // Search Customers Endpoint
    if ($action === 'search_customers') {
        $sql = "SELECT 
                    o.customer_name, 
                    o.customer_phone, 
                    u.email,
                    u.id as customer_id,
                    GROUP_CONCAT(DISTINCT o.delivery_address SEPARATOR ' | ') as addresses,
                    COUNT(o.id) as order_count, 
                    SUM(o.total_amount) as total_spent,
                    MAX(o.order_date) as last_order_date
                FROM orders o
                LEFT JOIN users u ON o.user_id = u.id
                WHERE 1=1";
        $params = [];
        
        if (!empty($_POST['search'])) {
            $sql .= " AND (o.customer_name LIKE ? OR o.customer_phone LIKE ? OR u.email LIKE ?)";
            $wildcard = "%" . $_POST['search'] . "%";
            $params[] = $wildcard; $params[] = $wildcard; $params[] = $wildcard;
        }
        
        $sql .= " GROUP BY o.customer_phone, o.customer_name ORDER BY total_spent DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($customers as &$c) {
            $fav_stmt = $pdo->prepare("SELECT item_name, SUM(quantity) as qty 
                                       FROM order_items 
                                       WHERE order_id IN (SELECT id FROM orders WHERE customer_phone = ? AND customer_name = ?) 
                                       GROUP BY item_name 
                                       ORDER BY qty DESC LIMIT 1");
            $fav_stmt->execute([$c['customer_phone'], $c['customer_name']]);
            $fav = $fav_stmt->fetch(PDO::FETCH_ASSOC);
            $c['favorite_dish'] = $fav ? $fav['item_name'] : 'N/A';
            
            $pay_stmt = $pdo->prepare("SELECT 
                                           SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) as paid_count,
                                           SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as failed_count,
                                           SUM(CASE WHEN order_status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as pending_count
                                       FROM orders 
                                       WHERE customer_phone = ? AND customer_name = ?");
            $pay_stmt->execute([$c['customer_phone'], $c['customer_name']]);
            $c['payment_summary'] = $pay_stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        echo json_encode(['success' => true, 'customers' => $customers]);
        exit;
    }
    
    // Search Payments Endpoint
    if ($action === 'search_payments') {
        $sql = "SELECT * FROM orders WHERE 1=1";
        $params = [];
        
        if (!empty($_POST['search'])) {
            $sql .= " AND (order_number LIKE ? OR customer_name LIKE ?)";
            $wildcard = "%" . $_POST['search'] . "%";
            $params[] = $wildcard; $params[] = $wildcard;
        }
        
        if (!empty($_POST['method']) && $_POST['method'] !== 'all') {
            if ($_POST['method'] === 'cash') {
                $sql .= " AND delivery_address LIKE '%Paid via CASH%'";
            } elseif ($_POST['method'] === 'card') {
                $sql .= " AND delivery_address LIKE '%Paid via CARD%'";
            } elseif ($_POST['method'] === 'upi') {
                $sql .= " AND delivery_address LIKE '%Paid via UPI%'";
            } elseif ($_POST['method'] === 'netbanking') {
                $sql .= " AND (delivery_address LIKE '%Paid via NETBANKING%' OR delivery_address LIKE '%Paid via NET BANKING%')";
            } elseif ($_POST['method'] === 'wallet') {
                $sql .= " AND delivery_address LIKE '%Paid via WALLET%'";
            } elseif ($_POST['method'] === 'gateway') {
                $sql .= " AND delivery_address NOT LIKE '%Paid via %'";
            }
        }
        
        if (!empty($_POST['status']) && $_POST['status'] !== 'all') {
            if ($_POST['status'] === 'success') {
                $sql .= " AND order_status = 'completed'";
            } elseif ($_POST['status'] === 'failed') {
                $sql .= " AND order_status = 'cancelled'";
            } elseif ($_POST['status'] === 'pending') {
                $sql .= " AND order_status IN ('pending', 'preparing', 'ready')";
            }
        }
        
        if (isset($_POST['min_amount']) && $_POST['min_amount'] !== '') {
            $sql .= " AND total_amount >= ?";
            $params[] = floatval($_POST['min_amount']);
        }
        if (isset($_POST['max_amount']) && $_POST['max_amount'] !== '') {
            $sql .= " AND total_amount <= ?";
            $params[] = floatval($_POST['max_amount']);
        }
        
        $sql .= " ORDER BY id DESC LIMIT 100";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'payments' => $logs]);
        exit;
    }
    
    // Business Intelligence Analytics Endpoint
    if ($action === 'get_reports_data') {
        $range = $_POST['range'] ?? 'today';
        $start_custom = $_POST['start_date'] ?? null;
        $end_custom = $_POST['end_date'] ?? null;
        
        list($start_date, $end_date) = getDateBounds($range, $start_custom, $end_custom);
        
        // 1. Revenue
        $rev_stmt = $pdo->prepare("SELECT SUM(total_amount) FROM orders WHERE order_status = 'completed' AND order_date BETWEEN ? AND ?");
        $rev_stmt->execute([$start_date, $end_date]);
        $revenue = floatval($rev_stmt->fetchColumn() ?: 0);
        
        // Growth Calculation
        $start_ts = strtotime($start_date);
        $end_ts = strtotime($end_date);
        $diff = $end_ts - $start_ts;
        $prev_start = date('Y-m-d H:i:s', $start_ts - $diff - 1);
        $prev_end = date('Y-m-d H:i:s', $start_ts - 1);
        
        $prev_rev_stmt = $pdo->prepare("SELECT SUM(total_amount) FROM orders WHERE order_status = 'completed' AND order_date BETWEEN ? AND ?");
        $prev_rev_stmt->execute([$prev_start, $prev_end]);
        $prev_revenue = floatval($prev_rev_stmt->fetchColumn() ?: 0);
        $revenue_growth = $prev_revenue > 0 ? round((($revenue - $prev_revenue) / $prev_revenue) * 100, 1) : 0;
        
        // 2. Orders Analytics
        $ord_stmt = $pdo->prepare("SELECT 
                                      COUNT(*) as total,
                                      SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) as completed,
                                      SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                                      SUM(CASE WHEN order_status IN ('pending', 'preparing', 'ready') THEN 1 ELSE 0 END) as pending,
                                      SUM(CASE WHEN delivery_address NOT LIKE 'Table %' THEN 1 ELSE 0 END) as online,
                                      SUM(CASE WHEN delivery_address LIKE 'Table %' THEN 1 ELSE 0 END) as dinein
                                  FROM orders 
                                  WHERE order_date BETWEEN ? AND ?");
        $ord_stmt->execute([$start_date, $end_date]);
        $ord_metrics = $ord_stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'completed' => 0, 'cancelled' => 0, 'pending' => 0, 'online' => 0, 'dinein' => 0];
        
        $total_orders = intval($ord_metrics['total']);
        $completed_orders = intval($ord_metrics['completed']);
        $cancelled_orders = intval($ord_metrics['cancelled']);
        $pending_orders = intval($ord_metrics['pending']);
        
        $prev_ord_stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_status = 'completed' AND order_date BETWEEN ? AND ?");
        $prev_ord_stmt->execute([$prev_start, $prev_end]);
        $prev_completed_orders = intval($prev_ord_stmt->fetchColumn() ?: 0);
        $orders_growth = $prev_completed_orders > 0 ? round((($completed_orders - $prev_completed_orders) / $prev_completed_orders) * 100, 1) : 0;
        
        $aov = $completed_orders > 0 ? round($revenue / $completed_orders, 2) : 0;
        $prev_aov = $prev_completed_orders > 0 ? round($prev_revenue / $prev_completed_orders, 2) : 0;
        $aov_growth = $prev_aov > 0 ? round((($aov - $prev_aov) / $prev_aov) * 100, 1) : 0;
        
        $acceptance_rate = $total_orders > 0 ? round((($total_orders - $cancelled_orders) / $total_orders) * 100, 1) : 100;
        $completion_rate = ($total_orders - $cancelled_orders) > 0 ? round(($completed_orders / ($total_orders - $cancelled_orders)) * 100, 1) : 100;
        
        // 3. Category Performance
        $cat_stmt = $pdo->prepare("SELECT 
                                       IFNULL(f.category, 'uncategorized') as category_name, 
                                       SUM(oi.quantity) as units_sold, 
                                       SUM(oi.quantity * oi.price) as revenue
                                   FROM order_items oi
                                   JOIN food_items f ON oi.food_item_id = f.id
                                   JOIN orders o ON oi.order_id = o.id
                                   WHERE o.order_status = 'completed' AND o.order_date BETWEEN ? AND ?
                                   GROUP BY f.category
                                   ORDER BY revenue DESC");
        $cat_stmt->execute([$start_date, $end_date]);
        $categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 4. Best Selling Dishes
        $dish_stmt = $pdo->prepare("SELECT 
                                        oi.item_name, 
                                        SUM(oi.quantity) as qty_sold, 
                                        SUM(oi.quantity * oi.price) as revenue
                                    FROM order_items oi
                                    JOIN orders o ON oi.order_id = o.id
                                    WHERE o.order_status = 'completed' AND o.order_date BETWEEN ? AND ?
                                    GROUP BY oi.item_name
                                    ORDER BY qty_sold DESC");
        $dish_stmt->execute([$start_date, $end_date]);
        $dishes = $dish_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 5. Payment Breakup
        $pay_stmt = $pdo->prepare("SELECT total_amount, delivery_address FROM orders WHERE order_status = 'completed' AND order_date BETWEEN ? AND ?");
        $pay_stmt->execute([$start_date, $end_date]);
        $pay_orders = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $payments = [
            'CASH' => ['amount' => 0, 'count' => 0],
            'UPI' => ['amount' => 0, 'count' => 0],
            'CARD' => ['amount' => 0, 'count' => 0],
            'NET BANKING' => ['amount' => 0, 'count' => 0],
            'WALLET' => ['amount' => 0, 'count' => 0],
            'ONLINE GATEWAY' => ['amount' => 0, 'count' => 0]
        ];
        
        foreach ($pay_orders as $po) {
            $addr = strtoupper($po['delivery_address']);
            $amt = floatval($po['total_amount']);
            
            if (strpos($addr, 'PAID VIA CASH') !== false) {
                $payments['CASH']['amount'] += $amt; $payments['CASH']['count']++;
            } elseif (strpos($addr, 'PAID VIA CARD') !== false) {
                $payments['CARD']['amount'] += $amt; $payments['CARD']['count']++;
            } elseif (strpos($addr, 'PAID VIA UPI') !== false) {
                $payments['UPI']['amount'] += $amt; $payments['UPI']['count']++;
            } elseif (strpos($addr, 'PAID VIA NETBANKING') !== false || strpos($addr, 'PAID VIA NET BANKING') !== false) {
                $payments['NET BANKING']['amount'] += $amt; $payments['NET BANKING']['count']++;
            } elseif (strpos($addr, 'PAID VIA WALLET') !== false) {
                $payments['WALLET']['amount'] += $amt; $payments['WALLET']['count']++;
            } else {
                if (strpos($addr, 'TABLE ') === false) {
                    $payments['ONLINE GATEWAY']['amount'] += $amt; $payments['ONLINE GATEWAY']['count']++;
                } else {
                    $payments['CASH']['amount'] += $amt; $payments['CASH']['count']++;
                }
            }
        }
        
        // 6. Customers Analysis
        $cust_stmt = $pdo->prepare("SELECT COUNT(DISTINCT customer_phone) FROM orders WHERE order_date BETWEEN ? AND ?");
        $cust_stmt->execute([$start_date, $end_date]);
        $total_customers = intval($cust_stmt->fetchColumn() ?: 0);
        
        $new_cust_stmt = $pdo->prepare("SELECT COUNT(*) FROM (
                                            SELECT customer_phone, MIN(order_date) as first_date 
                                            FROM orders 
                                            GROUP BY customer_phone
                                        ) t WHERE first_date BETWEEN ? AND ?");
        $new_cust_stmt->execute([$start_date, $end_date]);
        $new_customers = intval($new_cust_stmt->fetchColumn() ?: 0);
        
        $returning_customers = max(0, $total_customers - $new_customers);
        $retention_rate = $total_customers > 0 ? round(($returning_customers / $total_customers) * 100, 1) : 0;
        
        $top_cust_stmt = $pdo->prepare("SELECT customer_name, customer_phone, COUNT(*) as order_count, SUM(total_amount) as total_spent 
                                        FROM orders 
                                        WHERE order_status = 'completed' AND order_date BETWEEN ? AND ? 
                                        GROUP BY customer_phone, customer_name 
                                        ORDER BY total_spent DESC LIMIT 5");
        $top_cust_stmt->execute([$start_date, $end_date]);
        $top_customers = $top_cust_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // 7. Trend graphs
        $trend_labels = [];
        $trend_data = [];
        
        if ($diff <= 172800) { // Group by hour if <= 2 days
            $tr_stmt = $pdo->prepare("SELECT HOUR(order_date) as hr, SUM(total_amount) as total 
                                      FROM orders 
                                      WHERE order_status = 'completed' AND order_date BETWEEN ? AND ? 
                                      GROUP BY HOUR(order_date) 
                                      ORDER BY hr ASC");
            $tr_stmt->execute([$start_date, $end_date]);
            $raw_trends = $tr_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            for ($h = 0; $h < 24; $h++) {
                $trend_labels[] = sprintf("%02d:00", $h);
                $found = 0;
                foreach ($raw_trends as $rt) {
                    if (intval($rt['hr']) === $h) {
                        $trend_data[] = floatval($rt['total']);
                        $found = 1;
                        break;
                    }
                }
                if (!$found) $trend_data[] = 0;
            }
        } else { // Group by Date
            $tr_stmt = $pdo->prepare("SELECT DATE(order_date) as dt, SUM(total_amount) as total 
                                      FROM orders 
                                      WHERE order_status = 'completed' AND order_date BETWEEN ? AND ? 
                                      GROUP BY DATE(order_date) 
                                      ORDER BY dt ASC");
            $tr_stmt->execute([$start_date, $end_date]);
            $raw_trends = $tr_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($raw_trends as $rt) {
                $trend_labels[] = date('d M Y', strtotime($rt['dt']));
                $trend_data[] = floatval($rt['total']);
            }
        }
        
        // Performance Score
        $perf_score = round(($completion_rate * 0.4) + ($acceptance_rate * 0.4) + (min(max($revenue_growth, -20), 20) + 20) * 0.5, 0);
        $perf_score = min(max($perf_score, 10), 100);
        
        echo json_encode([
            'success' => true,
            'summary' => [
                'revenue' => $revenue,
                'revenue_growth' => $revenue_growth,
                'orders_count' => $completed_orders,
                'orders_growth' => $orders_growth,
                'aov' => $aov,
                'aov_growth' => $aov_growth,
                'total_orders' => $total_orders,
                'online_orders' => intval($ord_metrics['online']),
                'dinein_orders' => intval($ord_metrics['dinein']),
                'cancelled_orders' => $cancelled_orders,
                'pending_orders' => $pending_orders,
                'acceptance_rate' => $acceptance_rate,
                'completion_rate' => $completion_rate,
                'total_customers' => $total_customers,
                'new_customers' => $new_customers,
                'returning_customers' => $returning_customers,
                'retention_rate' => $retention_rate,
                'performance_score' => $perf_score,
                'start_date' => date('d M Y', strtotime($start_date)),
                'end_date' => date('d M Y', strtotime($end_date)),
                'generated_at' => date('d M Y, h:i A')
            ],
            'trend' => [
                'labels' => $trend_labels,
                'data' => $trend_data
            ],
            'categories' => $categories,
            'dishes' => $dishes,
            'payments' => $payments,
            'top_customers' => $top_customers
        ]);
        exit;
    }
    
    // Live Kitchen Polling
    if ($action === 'get_kitchen_orders') {
        $stmt = $pdo->query("SELECT * FROM orders WHERE order_status IN ('pending', 'preparing', 'ready') ORDER BY id ASC");
        $active_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($active_orders as &$order) {
            $item_stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $item_stmt->execute([$order['id']]);
            $order['items'] = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(['success' => true, 'orders' => $active_orders]);
        exit;
    }
    
    // Update Order Status
    if ($action === 'update_order_status') {
        $order_id = $_POST['order_id'];
        $status = $_POST['status'];
        
        $stmt = $pdo->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
        $stmt->execute([$status, $order_id]);
        
        echo json_encode(['success' => true]);
        exit;
    }

    // Toggle Menu Item Availability
    if ($action === 'toggle_menu_item') {
        $item_id = $_POST['id'];
        $val = $_POST['val'];
        
        $stmt = $pdo->prepare("UPDATE food_items SET is_available = ? WHERE id = ?");
        $stmt->execute([$val, $item_id]);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Add Menu Item
    if ($action === 'add_menu_item') {
        $name = $_POST['name'];
        $desc = $_POST['description'];
        $price = $_POST['price'];
        $category = $_POST['category'];
        $image_url = $_POST['image_url'] ?: 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=400&h=300&fit=crop';
        
        $stmt = $pdo->prepare("INSERT INTO food_items (name, description, price, category, image_url, is_available) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([$name, $desc, $price, $category, $image_url]);
        
        echo json_encode(['success' => true]);
        exit;
    }

    // Edit Menu Item
    if ($action === 'edit_menu_item') {
        $id = $_POST['id'];
        $name = $_POST['name'];
        $desc = $_POST['description'];
        $price = $_POST['price'];
        $category = $_POST['category'];
        $image_url = $_POST['image_url'];
        
        $stmt = $pdo->prepare("UPDATE food_items SET name = ?, description = ?, price = ?, category = ?, image_url = ? WHERE id = ?");
        $stmt->execute([$name, $desc, $price, $category, $image_url, $id]);
        
        echo json_encode(['success' => true]);
        exit;
    }

    // Delete Menu Item
    if ($action === 'delete_menu_item') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM food_items WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Settle dine-in bill
    if ($action === 'settle_bill') {
        $order_id = $_POST['order_id'];
        $payment_method = $_POST['payment_method']; // cash, upi, card
        
        // Append payment method details in the database order status or message
        $stmt = $pdo->prepare("UPDATE orders SET order_status = 'completed', delivery_address = CONCAT(delivery_address, ' [Paid via ', ?, ']') WHERE id = ?");
        $stmt->execute([strtoupper($payment_method), $order_id]);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Add item to active Dine-In Table Order
    if ($action === 'add_table_item') {
        $order_id = $_POST['order_id'];
        $food_id = $_POST['food_item_id'];
        $qty = intval($_POST['quantity']);
        
        $f_stmt = $pdo->prepare("SELECT * FROM food_items WHERE id = ?");
        $f_stmt->execute([$food_id]);
        $food = $f_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($food) {
            $stmt = $pdo->prepare("INSERT INTO order_items (order_id, food_item_id, item_name, quantity, price) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$order_id, $food_id, $food['name'], $qty, $food['price']]);
            
            // Recalculate order total amount
            $total_stmt = $pdo->prepare("SELECT SUM(quantity * price) FROM order_items WHERE order_id = ?");
            $total_stmt->execute([$order_id]);
            $new_total = $total_stmt->fetchColumn();
            
            $up_stmt = $pdo->prepare("UPDATE orders SET total_amount = ? WHERE id = ?");
            $up_stmt->execute([$new_total, $order_id]);
            
            echo json_encode(['success' => true, 'new_total' => $new_total]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Food item not found']);
        }
        exit;
    }

    // Create new dine-in table order
    if ($action === 'create_dinein_order') {
        $table_code = $_POST['table_code'];
        $cust_name = $_POST['customer_name'] ?: 'Guest';
        
        $order_number = 'ORD-' . strtoupper(substr(uniqid(), 7, 5));
        
        $stmt = $pdo->prepare("INSERT INTO orders (order_number, customer_name, delivery_address, total_amount, order_status) VALUES (?, ?, ?, 0.00, 'pending')");
        $stmt->execute([$order_number, $cust_name, "Table " . $table_code]);
        
        echo json_encode(['success' => true, 'order_id' => $pdo->lastInsertId()]);
        exit;
    }

    // Save Settings
    if ($action === 'save_settings') {
        $settings = [
            'restaurant_name' => $_POST['restaurant_name'],
            'gst_rate' => intval($_POST['gst_rate']),
            'packing_charge' => floatval($_POST['packing_charge']),
            'opening_hours' => $_POST['opening_hours']
        ];
        file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true]);
        exit;
    }
}

// 4. Fetch Core Metrics for UI
$total_orders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn() ?: 0;
$total_revenue = $pdo->query("SELECT SUM(total_amount) FROM orders WHERE order_status = 'completed'")->fetchColumn() ?: 0;
$online_orders_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE delivery_address NOT LIKE 'Table %'")->fetchColumn() ?: 0;
$today_sales = $pdo->query("SELECT SUM(total_amount) FROM orders WHERE DATE(order_date) = CURDATE() AND order_status = 'completed'")->fetchColumn() ?: 0;

$top_selling_dish = $pdo->query("SELECT item_name, SUM(quantity) as qty FROM order_items GROUP BY item_name ORDER BY qty DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$top_dish_name = $top_selling_dish ? $top_selling_dish['item_name'] : 'N/A';

// Fetch tables with active orders
$active_order_stmt = $pdo->query("SELECT delivery_address FROM orders WHERE order_status IN ('pending', 'preparing', 'ready')");
$occupied_tables = [];
while ($row = $active_order_stmt->fetch(PDO::FETCH_ASSOC)) {
    // extract Table code (e.g. Table T01 -> T01)
    if (preg_match('/Table\s+([A-Za-z0-9]+)/i', $row['delivery_address'], $matches)) {
        $occupied_tables[] = trim($matches[1]);
    }
}
$active_tables_count = count(array_unique($occupied_tables));

// 7-day Sales Chart Query
$chart_stmt = $pdo->query("SELECT DATE(order_date) as d, SUM(total_amount) as total FROM orders WHERE order_status = 'completed' GROUP BY DATE(order_date) ORDER BY DATE(order_date) ASC LIMIT 7");
$chart_labels = [];
$chart_data = [];
while ($row = $chart_stmt->fetch(PDO::FETCH_ASSOC)) {
    $chart_labels[] = date('d M', strtotime($row['d']));
    $chart_data[] = floatval($row['total']);
}
if (empty($chart_data)) {
    $chart_labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $chart_data = [0, 0, 0, 0, 0, 0, 0];
}

// 10 Recent Orders
$recent_orders = $pdo->query("SELECT *, (SELECT rating FROM feedback WHERE order_number = orders.order_number LIMIT 1) AS rating, (SELECT review FROM feedback WHERE order_number = orders.order_number LIMIT 1) AS review FROM orders ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// Full Menu List
$menu_list = $pdo->query("SELECT * FROM food_items ORDER BY category, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Customers list
$customer_list_raw = $pdo->query("SELECT 
                                     o.customer_name, 
                                     o.customer_phone, 
                                     u.email,
                                     u.id as customer_id,
                                     COUNT(o.id) as order_count, 
                                     SUM(o.total_amount) as total_spent,
                                     MAX(o.order_date) as last_order_date
                                 FROM orders o
                                 LEFT JOIN users u ON o.user_id = u.id
                                 GROUP BY o.customer_phone, o.customer_name 
                                 ORDER BY total_spent DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$customer_list = [];
foreach ($customer_list_raw as $c) {
    // Favorite dish
    $fav_stmt = $pdo->prepare("SELECT item_name, SUM(quantity) as qty FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_phone = ? AND customer_name = ?) GROUP BY item_name ORDER BY qty DESC LIMIT 1");
    $fav_stmt->execute([$c['customer_phone'], $c['customer_name']]);
    $fav = $fav_stmt->fetch(PDO::FETCH_ASSOC);
    $c['favorite_dish'] = $fav ? $fav['item_name'] : 'N/A';
    
    // Payment summary
    $pay_stmt = $pdo->prepare("SELECT 
                                   SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) as paid_count,
                                   SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as failed_count,
                                   SUM(CASE WHEN order_status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as pending_count
                               FROM orders 
                               WHERE customer_phone = ? AND customer_name = ?");
    $pay_stmt->execute([$c['customer_phone'], $c['customer_name']]);
    $c['payment_summary'] = $pay_stmt->fetch(PDO::FETCH_ASSOC);
    
    $customer_list[] = $c;
}

// All Tables Definition matching book-table-test.html
$table_zones = [
    'VIP Area' => ['T01','T02','T03','T04','T05','T06','T07','T08','R1','R2'],
    'Indoor Dining' => ['A01','A02','A03','A04','B01','B02','B03','B04','RD1','RD2','RD3','C01','C02','C03','C04'],
    'Outdoor / Garden' => ['G01','G02','G03','G04','G05','G06','G07','G08'],
    'Booth Seating' => ['F01','F02','F03','F04','F05','F06'],
    'Banquet & Communal' => ['L01','L02','BQ1','BQ2']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['restaurant_name']); ?> - Luxury Dashboard</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        (function() {
            const theme = localStorage.getItem('medusa_admin_theme');
            if (theme === 'light') {
                document.documentElement.classList.add('light-mode');
            }
        })();
    </script>
    
    <style>
        :root {
            --bg-dark: #0a0a0a;
            --bg-secondary: #121111;
            --gold: #dfba86;
            --gold-light: #e6c89f;
            --white: #f3f3f3;
            --gray: #a09f9f;
            --gray-dark: #222222;
            --success-color: #2ec4b6;
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --radius: 16px;
        }

        body {
            background-color: var(--bg-dark);
            color: var(--white);
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Sidebar Styling */
        .sidebar {
            width: 260px;
            background-color: var(--bg-secondary);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 100;
            padding: 2rem 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 2.5rem;
        }

        .sidebar-brand {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            color: var(--gold);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--gray);
            padding: 0.8rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: var(--transition);
            cursor: pointer;
        }

        .sidebar-link:hover, .sidebar-link.active {
            color: var(--gold);
            background-color: rgba(223, 186, 134, 0.08);
        }

        /* Main Content wrapper */
        .main-content {
            margin-left: 260px;
            padding: 2.5rem;
            min-height: 100vh;
        }

        /* Header block */
        .page-header {
            margin-bottom: 2.5rem;
        }

        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.2rem;
            font-weight: 700;
            color: #ffffff;
        }

        .page-subtitle {
            color: var(--gray);
            font-size: 0.95rem;
        }

        /* Metric Cards */
        .metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .metric-card {
            background-color: var(--bg-secondary);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .metric-info h5 {
            font-size: 0.85rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .metric-info .value {
            font-size: 1.8rem;
            font-weight: 700;
            color: #ffffff;
        }

        .metric-icon {
            font-size: 2rem;
            color: var(--gold);
            background-color: rgba(223, 186, 134, 0.05);
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Content Blocks */
        .content-card {
            background-color: var(--bg-secondary);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
            margin-bottom: 2rem;
        }

        .card-header-premium {
            font-family: 'Playfair Display', serif;
            font-size: 1.4rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding-bottom: 0.8rem;
        }

        /* Custom Table Styling */
        .premium-table {
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.05);
        }

        .premium-table th {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding: 1rem 0.8rem;
            background: transparent;
        }

        .premium-table td {
            padding: 1rem 0.8rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            vertical-align: middle;
            background: transparent;
            color: #ffffff !important; /* Force high-contrast text color for cell readability */
        }

        .premium-table td strong {
            color: #ffffff !important;
        }

        .premium-table td .text-muted {
            color: #b2bec3 !important; /* Brighter gray for secondary information to keep it readable */
        }

        .text-gold {
            color: var(--gold) !important;
        }

        /* Badges */
        .status-badge {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 8px;
            border-radius: 6px;
        }

        .status-pending { background-color: rgba(223, 186, 134, 0.1); color: var(--gold-light); }
        .status-preparing { background-color: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .status-ready { background-color: rgba(46, 196, 182, 0.1); color: var(--success-color); }
        .status-completed { background-color: rgba(40, 167, 69, 0.1); color: #28a745; }
        .status-cancelled { background-color: rgba(220, 53, 69, 0.1); color: #dc3545; }

        /* Tabs Panels */
        .tab-panel {
            display: none;
        }
        .tab-panel.active {
            display: block;
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Dine-In Tables Visual Grid */
        .tables-zone-box {
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.05);
            background: rgba(255, 255, 255, 0.01);
            border-radius: 12px;
            padding: 1.5rem;
        }
        .zone-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.2rem;
            color: var(--gold-light);
            margin-bottom: 1rem;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.1);
            padding-bottom: 0.5rem;
        }
        .table-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 1rem;
        }
        .table-cell {
            background-color: var(--bg-dark);
            border: 1.5px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
            position: relative;
        }
        .table-cell:hover {
            border-color: var(--gold);
            transform: translateY(-2px);
        }
        .table-cell.occupied {
            background-color: rgba(223, 186, 134, 0.04);
            border-color: var(--gold);
        }
        .table-cell .table-name {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 3px;
        }
        .table-cell .table-status {
            font-size: 0.72rem;
            font-weight: 500;
            color: var(--gray);
        }
        .table-cell.occupied .table-status {
            color: var(--gold);
        }
        
        /* Kitchen layout */
        .kitchen-columns {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1.5rem;
        }
        .kitchen-col {
            background-color: rgba(255, 255, 255, 0.01);
            border: 1px solid rgba(255, 255, 255, 0.04);
            border-radius: 12px;
            padding: 1.2rem;
            min-height: 400px;
        }
        .kitchen-col-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.15rem;
            color: #ffffff;
            margin-bottom: 1.2rem;
            border-bottom: 1.5px solid var(--gold);
            padding-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .kitchen-card {
            background-color: var(--bg-secondary);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            transition: var(--transition);
        }
        .kitchen-card:hover {
            border-color: rgba(223, 186, 134, 0.3);
        }
        .kitchen-card-header {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.6rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding-bottom: 0.4rem;
        }
        .kitchen-card-items {
            list-style: none;
            padding: 0;
            margin: 0 0 0.8rem 0;
            font-size: 0.82rem;
            color: #e0e0e0;
        }
        .kitchen-card-items li {
            padding: 0.2rem 0;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.02);
        }
        .kitchen-card-items li:last-child {
            border-bottom: none;
        }

        /* Settings CSS */
        .form-control-dashboard {
            background-color: var(--bg-dark) !important;
            border: 1.5px solid rgba(255, 255, 255, 0.08) !important;
            color: #ffffff !important;
            border-radius: 8px !important;
            padding: 0.75rem !important;
        }
        .form-control-dashboard:focus {
            border-color: var(--gold) !important;
            box-shadow: none !important;
        }

        /* Premium Search Bar Custom Layout */
        .premium-search-group {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .premium-search-group .search-icon {
            position: absolute;
            left: 1rem;
            color: var(--gold);
            z-index: 5;
            pointer-events: none;
            font-size: 0.95rem;
            transition: var(--transition);
        }
        .premium-search-group .form-control-dashboard {
            padding-left: 2.6rem !important;
            width: 100%;
        }
        .premium-search-group:focus-within .search-icon {
            color: var(--gold-light);
            transform: scale(1.1);
        }

        .btn-gold-action {
            background-color: var(--gold);
            color: #000000;
            font-weight: 700;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            transition: var(--transition);
        }
        .btn-gold-action:hover {
            background-color: var(--gold-light);
            transform: translateY(-1px);
        }

        /* QR block */
        .qr-card-view {
            text-align: center;
            background-color: var(--bg-secondary);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            transition: var(--transition);
        }
        .qr-card-view:hover {
            border-color: var(--gold);
        }
        .qr-title-text {
            font-weight: bold;
            color: #ffffff;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }
        .qr-sub {
            color: var(--gray);
            font-size: 0.75rem;
            margin-bottom: 1rem;
        }

        /* Orders tab active border-bottom styling */
        #orderTabNav .nav-link.active {
            border-bottom: 2px solid var(--gold) !important;
        }

        /* LIGHT MODE OVERRIDES */
        html.light-mode body {
            --bg-dark: #f8f9fc;
            --bg-secondary: #ffffff;
            --white: #1e293b;
            --gray: #64748b;
            --gray-dark: #cbd5e1;
        }

        html.light-mode .sidebar {
            background-color: var(--bg-secondary);
            border-right: 1px solid rgba(0, 0, 0, 0.08);
        }

        html.light-mode .sidebar-link {
            color: var(--gray);
        }

        html.light-mode .sidebar-link:hover, html.light-mode .sidebar-link.active {
            color: var(--gold);
            background-color: rgba(223, 186, 134, 0.12);
        }

        html.light-mode .page-title,
        html.light-mode .card-header-premium,
        html.light-mode .kitchen-col-title,
        html.light-mode .qr-title-text,
        html.light-mode .metric-info .value,
        html.light-mode .premium-table,
        html.light-mode .premium-table td,
        html.light-mode .premium-table td strong {
            color: var(--white) !important;
        }

        html.light-mode .text-white {
            color: var(--white) !important;
        }

        html.light-mode .bg-dark {
            background-color: var(--bg-secondary) !important;
        }

        html.light-mode .list-group-item.bg-dark {
            background-color: var(--bg-secondary) !important;
            color: var(--white) !important;
            border-color: rgba(0, 0, 0, 0.08) !important;
        }

        html.light-mode .list-group-item.bg-dark:hover {
            background-color: rgba(223, 186, 134, 0.08) !important;
        }

        html.light-mode .content-card,
        html.light-mode .metric-card,
        html.light-mode .qr-card-view,
        html.light-mode .kitchen-col,
        html.light-mode .kitchen-card,
        html.light-mode .tables-zone-box {
            background-color: var(--bg-secondary);
            border: 1px solid rgba(0, 0, 0, 0.08);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.04);
        }

        html.light-mode .table-cell {
            background-color: #f1f5f9;
            border-color: rgba(0, 0, 0, 0.08);
        }

        html.light-mode .table-cell.occupied {
            background-color: rgba(223, 186, 134, 0.1);
            border-color: var(--gold);
        }

        html.light-mode .table-cell .table-name {
            color: var(--white);
        }

        html.light-mode .premium-table th {
            color: var(--gray);
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
        }

        html.light-mode .premium-table td {
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        html.light-mode .form-control-dashboard,
        html.light-mode .form-control,
        html.light-mode .form-select {
            background-color: #f1f5f9 !important;
            color: var(--white) !important;
            border: 1.5px solid rgba(0, 0, 0, 0.08) !important;
        }

        html.light-mode .form-control-dashboard:focus,
        html.light-mode .form-control:focus,
        html.light-mode .form-select:focus {
            border-color: var(--gold) !important;
            background-color: #ffffff !important;
        }

        html.light-mode .modal-content {
            background-color: var(--bg-secondary) !important;
            color: var(--white) !important;
            border: 1px solid rgba(0, 0, 0, 0.1) !important;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.15) !important;
        }

        html.light-mode .btn-close {
            filter: invert(1) grayscale(100%) brightness(0%);
        }

        html.light-mode .btn-outline-light {
            border-color: #cbd5e1;
            color: #475569;
        }

        html.light-mode .btn-outline-light:hover {
            background-color: #f1f5f9;
            color: #1e293b;
        }

        html.light-mode .status-pending {
            background-color: rgba(223, 186, 134, 0.15);
        }

        html.light-mode .status-preparing {
            background-color: rgba(59, 130, 246, 0.15);
        }

        html.light-mode .status-ready {
            background-color: rgba(46, 196, 182, 0.15);
        }

        html.light-mode .status-completed {
            background-color: rgba(40, 167, 69, 0.15);
        }

        html.light-mode .status-cancelled {
            background-color: rgba(220, 53, 69, 0.15);
        }

        /* Smooth Transition */
        body, 
        .sidebar, 
        .sidebar-link, 
        .main-content, 
        .content-card, 
        .metric-card, 
        .kitchen-col, 
        .kitchen-card, 
        .table-cell, 
        .form-control, 
        .form-select, 
        .form-control-dashboard,
        .premium-table td,
        .page-title,
        .card-header-premium {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
    

/* ===== CHATGPT FINAL UI FIX ===== */
.sidebar{
    transition:all .3s ease;
    width:260px;
}
.sidebar.collapsed{
    width:80px;
    padding-left:.8rem;
    padding-right:.8rem;
}
.sidebar.collapsed .sidebar-brand span,
.sidebar.collapsed .sidebar-link span{
    display:none;
}
.sidebar.collapsed .sidebar-link{
    justify-content:center;
}
.main-content{
    transition:all .3s ease;
}
.main-content.expanded{
    margin-left:80px!important;
}

/* Burger button: bottom-left, always visible */
#sidebarToggle{
    position:fixed;
    left:1.15rem;
    bottom:1.15rem;
    z-index:2000;
    width:46px;
    height:46px;
    border-radius:14px;
    border:1px solid rgba(148,163,184,.28);
    background:var(--bg-secondary);
    color:var(--gold);
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 8px 18px rgba(0,0,0,.12);
    transition:all .25s ease, background-color .25s ease, border-color .25s ease;
}
#sidebarToggle:hover{
    transform:translateY(-1px);
    border-color:rgba(223,186,134,.55);
}
html.light-mode #sidebarToggle{
    background:#ffffff;
    border-color:rgba(15,23,42,.12);
}
#sidebarToggle.closed{
    left:1.15rem;
}

/* Gold action buttons: consistent and no wrapping */
.btn-gold-action{
    width:auto!important;
    min-width:108px!important;
    height:42px!important;
    min-height:42px!important;
    padding:0 14px!important;
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    gap:8px!important;
    white-space:nowrap!important;
    line-height:1!important;
    flex-wrap:nowrap!important;
    vertical-align:middle;
}
.btn-gold-action i{
    flex:0 0 auto;
}
.btn-action-form{
    min-width:112px!important;
}
.btn-action-wide{
    min-width:148px!important;
}
.btn-action-full{
    width:100%!important;
    min-width:0!important;
}
.btn-icon-only{
    min-width:42px!important;
    width:42px!important;
    padding:0!important;
}
.content-card .btn-gold-action{
    max-height:42px!important;
}

/* Dark mode visibility fixes */
html:not(.light-mode) .form-label,
html:not(.light-mode) label,
html:not(.light-mode) th,
html:not(.light-mode) .text-muted,
html:not(.light-mode) .small,
html:not(.light-mode) .card-header-premium,
html:not(.light-mode) .page-subtitle{
    color:#cbd5e1!important;
}
html:not(.light-mode) input,
html:not(.light-mode) select,
html:not(.light-mode) textarea,
.form-control-dashboard{
    color:var(--white)!important;
}
html:not(.light-mode) input::placeholder,
html:not(.light-mode) textarea::placeholder{
    color:#9ca3af!important;
    opacity:1;
}
html:not(.light-mode) .premium-table td{
    color:#f5f5f5!important;
}
html:not(.light-mode) .premium-table th{
    color:#94a3b8!important;
}
html:not(.light-mode) .form-control-dashboard:focus,
html:not(.light-mode) .form-control:focus,
html:not(.light-mode) .form-select:focus{
    color:var(--white)!important;
}

/* Align buttons in filter rows a bit closer to fields */
.content-card form .row.g-3 > [class*="col-"]{
    align-self:flex-start;
}
.content-card form .row.g-3 > [class*="col-"]:has(.btn-gold-action){
    align-self:end;
}

/* Mobile drawer */
@media(max-width:768px){
    .sidebar{
        transform:translateX(-100%);
        width:260px;
        z-index:2500;
    }
    .sidebar.mobile-open{
        transform:translateX(0);
        width:260px;
    }
    .main-content{
        margin-left:0!important;
        padding-left:1rem;
        padding-right:1rem;
    }
    .main-content.expanded{
        margin-left:0!important;
    }
    #sidebarToggle{
        left:1rem!important;
        bottom:1rem!important;
    }
}

/* Orders Management filter box tuning */
#ordersSearchForm .row.g-3{
    align-items:end;
}

#ordersSearchForm .form-label{
    margin-bottom:.35rem;
    font-size:.72rem;
    letter-spacing:.4px;
}

#ordersSearchForm .form-control-dashboard,
#ordersSearchForm .form-select{
    min-height:48px;
    height:48px;
    padding-top:.6rem !important;
    padding-bottom:.6rem !important;
    font-size:.95rem;
}

#ordersSearchForm .premium-search-group .form-control-dashboard{
    min-height:48px;
    height:48px;
}

#ordersSearchForm .premium-search-group .search-icon{
    top:50%;
    transform:translateY(-50%);
}

#ordersSearchForm .btn-action-form{
    min-width:96px!important;
    width:auto!important;
    height:48px!important;
    min-height:48px!important;
    padding:0 12px!important;
}

/* Kitchen quick status alignment */
#kitchen-tab .content-card.mb-4 .d-flex.gap-2.flex-wrap{
    margin-top:0 !important;
}

#kitchen-tab .content-card.mb-4 .btn.btn-outline-light.btn-sm{
    height:36px;
    padding:.35rem .7rem;
    line-height:1;
}

#kitchen-tab .content-card.mb-4 .form-label.mb-1{
    margin-bottom:.35rem !important;
}

/* ===== END FIX ===== */



/* ===== V10 FINAL FILTER COLUMN GAP FIX ===== */
.content-card form .row.g-3 > .d-flex:has(.btn-gold-action),
.filter-btn-wrapper{
    width:auto!important;
    flex:0 0 auto!important;
    max-width:max-content!important;
    margin-left:0!important;
    margin-top:0!important;
    padding-left:4px!important;
    padding-right:4px!important;
    justify-content:flex-start!important;
    align-items:flex-end!important;
}

.content-card form .row.g-3 > [class*="col-"]:has(.btn-gold-action){
    width:auto!important;
    flex:0 0 auto!important;
    max-width:max-content!important;
    justify-content:flex-start!important;
}

.btn-gold-action{
    width:auto!important;
    min-width:110px!important;
    height:42px!important;
    margin:0!important;
    flex:none!important;
}
/* ===== END V10 FIX ===== */
/* ===== V13 SIZE TWEAKS ONLY ===== */

/* Keep the working layout, only tighten control sizing */
#ordersSearchForm .btn-action-form,
#paymentsSearchForm .btn-action-form,
#menuSearchForm .btn-action-form{
    min-width: 104px !important;
    width: auto !important;
    height: 44px !important;
    min-height: 44px !important;
    padding: 0 12px !important;
    font-size: 0.92rem;
}

#customersSearchForm .btn-action-wide{
    min-width: 140px !important;
    width: auto !important;
    height: 44px !important;
    min-height: 44px !important;
    padding: 0 14px !important;
    font-size: 0.92rem;
}

#reportsFilterForm .btn-action-wide{
    min-width: 178px !important;
    width: auto !important;
    height: 44px !important;
    min-height: 44px !important;
    padding: 0 14px !important;
    font-size: 0.92rem;
}

/* Align the action columns closer to their fields */
#customersSearchForm .col-md-3.d-flex.align-items-end.justify-content-end.ms-md-1,
#reportsFilterForm .col-md-3.d-flex.align-items-end.justify-content-end.ms-md-1,
#paymentsSearchForm .col-md-2.d-flex.align-items-end.justify-content-end,
#ordersSearchForm .col-auto.d-flex.align-items-end,
#paymentsSearchForm .col-auto.d-flex.align-items-end{
    margin-left: 0 !important;
    padding-left: 4px !important;
    padding-right: 4px !important;
}

/* Keep the controls at a consistent visual height */
#ordersSearchForm .form-control-dashboard,
#ordersSearchForm .form-select,
#paymentsSearchForm .form-control-dashboard,
#paymentsSearchForm .form-select,
#menuSearchForm .form-control-dashboard,
#menuSearchForm .form-select,
#customersSearchForm .form-control-dashboard,
#reportsFilterForm .form-control-dashboard,
#reportsFilterForm .form-select{
    min-height: 44px !important;
    height: 44px !important;
}

/* Match the icon size to the tighter buttons */
#ordersSearchForm .btn-gold-action i,
#paymentsSearchForm .btn-gold-action i,
#menuSearchForm .btn-gold-action i,
#customersSearchForm .btn-gold-action i,
#reportsFilterForm .btn-gold-action i{
    font-size: 14px;
}

/* Prevent text wrapping in the wider buttons */
#customersSearchForm .btn-action-wide span,
#reportsFilterForm .btn-action-wide span,
#ordersSearchForm .btn-action-form span,
#paymentsSearchForm .btn-action-form span,
#menuSearchForm .btn-action-form span{
    white-space: nowrap !important;
}

/* ===== Kitchen monitor size alignment fix ONLY ===== */

#kitchen-tab .content-card.mb-4 .row{
    align-items: end !important;
}

#kitchen-tab .content-card.mb-4 .col-md-6{
    width: 50% !important;
    flex: 0 0 50% !important;
}

#kitchen-tab .content-card.mb-4 .premium-search-group{
    width: 100% !important;
}

#kitchen-tab .content-card.mb-4 .form-control-dashboard{
    height: 48px !important;
    min-height: 48px !important;
}

#kitchen-tab .content-card.mb-4 .d-flex.gap-2.flex-wrap{
    height: 48px !important;
    align-items: center !important;
    gap: 10px !important;
}

#kitchen-tab .content-card.mb-4 .btn.btn-outline-light.btn-sm{
    height: 38px !important;
    padding: 0 14px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
}

/* ===== END V13 SIZE TWEAKS ONLY ===== */

</style>
</head>
<body>
<button id="sidebarToggle" type="button" onclick="toggleSidebar()" aria-label="Toggle sidebar" title="Toggle sidebar"><i class="fas fa-bars"></i></button>


    <!-- GLOBAL THEME TOGGLE BUTTON -->
    <div style="position: fixed; top: 2rem; right: 2.5rem; z-index: 1050;">
        <button id="themeToggleBtn" class="btn btn-outline-light rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; background: var(--bg-secondary); border: 1px solid rgba(255,255,255,0.08); box-shadow: 0 4px 15px rgba(0,0,0,0.3); transition: var(--transition);" onclick="toggleTheme()" title="Toggle Theme">
            <i class="fas fa-moon" id="themeIcon" style="color: var(--gold); font-size: 1.2rem;"></i>
        </button>
    </div>

    <!-- LEFT SIDEBAR -->
    <div class="sidebar">
        <div class="sidebar-brand" style="display: flex; align-items: center; gap: 8px;">
            <img src="../assets/images/versace_logo.png" alt="Medusa Logo" style="height: 32px; border-radius: 50%; border: 1px solid var(--gold); padding: 1px;">
            <span>Admin</span>
        </div>
        <ul class="sidebar-menu">
            <li>
                <a class="sidebar-link active" onclick="switchTab('dashboard-tab', this)">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a class="sidebar-link" onclick="switchTab('orders-tab', this)">
                    <i class="fas fa-receipt"></i>
                    <span>Orders</span>
                </a>
            </li>
            <li>
                <a class="sidebar-link" onclick="switchTab('tables-tab', this)">
                    <i class="fas fa-chair"></i>
                    <span>Tables & QR</span>
                </a>
            </li>
            <li>
                <a class="sidebar-link" onclick="switchTab('kitchen-tab', this)">
                    <i class="fas fa-fire-burner"></i>
                    <span>Kitchen Panel</span>
                </a>
            </li>
            <li>
                <a class="sidebar-link" onclick="switchTab('menu-tab', this)">
                    <i class="fas fa-book-open"></i>
                    <span>Menu Card</span>
                </a>
            </li>
            <li>
                <a class="sidebar-link" onclick="switchTab('customers-tab', this)">
                    <i class="fas fa-users"></i>
                    <span>Customers</span>
                </a>
            </li>
            <li>
                <a class="sidebar-link" onclick="switchTab('payments-tab', this)">
                    <i class="fas fa-wallet"></i>
                    <span>Payments</span>
                </a>
            </li>
            <li>
                <a class="sidebar-link" onclick="switchTab('reports-tab', this)">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Reports</span>
                </a>
            </li>
            <li>
                <a class="sidebar-link" onclick="switchTab('settings-tab', this)">
                    <i class="fas fa-sliders"></i>
                    <span>Settings</span>
                </a>
            </li>
            <li>
                <a href="../api/logout.php" class="sidebar-link text-danger">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- MAIN CONTAINER -->
    <div class="main-content">
        
        <!-- ==================== DASHBOARD TAB ==================== -->
        <div id="dashboard-tab" class="tab-panel active">
            <div class="page-header">
                <h1 class="page-title"><?php echo htmlspecialchars($settings['restaurant_name']); ?></h1>
                <p class="page-subtitle">Premium Command Center for Client Demo</p>
            </div>

            <!-- METRIC CARDS GRID -->
            <div class="metric-grid">
                <div class="metric-card">
                    <div class="metric-info">
                        <h5>Total Orders</h5>
                        <div class="value"><?php echo $total_orders; ?></div>
                    </div>
                    <div class="metric-icon"><i class="fas fa-shopping-basket"></i></div>
                </div>
                <div class="metric-card">
                    <div class="metric-info">
                        <h5>Total Revenue</h5>
                        <div class="value">₹<?php echo number_format($total_revenue, 2); ?></div>
                    </div>
                    <div class="metric-icon"><i class="fas fa-dollar-sign"></i></div>
                </div>
                <div class="metric-card">
                    <div class="metric-info">
                        <h5>Online Orders</h5>
                        <div class="value"><?php echo $online_orders_count; ?></div>
                    </div>
                    <div class="metric-icon"><i class="fas fa-globe"></i></div>
                </div>
                <div class="metric-card">
                    <div class="metric-info">
                        <h5>Active Tables</h5>
                        <div class="value"><?php echo $active_tables_count; ?></div>
                    </div>
                    <div class="metric-icon"><i class="fas fa-chair"></i></div>
                </div>
                <div class="metric-card">
                    <div class="metric-info">
                        <h5>Today's Sales</h5>
                        <div class="value">₹<?php echo number_format($today_sales, 2); ?></div>
                    </div>
                    <div class="metric-icon"><i class="fas fa-cash-register"></i></div>
                </div>
                <div class="metric-card">
                    <div class="metric-info">
                        <h5>Top Dish</h5>
                        <div class="value" style="font-size: 1.1rem; line-height: 1.6;"><?php echo htmlspecialchars($top_dish_name); ?></div>
                    </div>
                    <div class="metric-icon"><i class="fas fa-star"></i></div>
                </div>
            </div>

            <div class="row">
                <!-- CHART -->
                <div class="col-lg-12 mb-4">
                    <div class="content-card h-100">
                        <div class="card-header-premium">Revenue Analytics</div>
                        <div style="height: 300px; position: relative;">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ==================== ORDERS TAB ==================== -->
        <div id="orders-tab" class="tab-panel">
            <div class="page-header">
                <h1 class="page-title">Order Management</h1>
                <p class="page-subtitle">Process online food requests and generate tableside bills</p>
            </div>

            <!-- Orders Search Box -->
            <div class="content-card mb-4">
                <form id="ordersSearchForm" onsubmit="performOrdersSearch(event)">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label text-muted small text-uppercase">Search Text</label>
                            <div class="premium-search-group">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" id="order_search_input" class="form-control form-control-dashboard" placeholder="Order ID, Customer, Phone, Address...">
                            </div>

                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-muted small text-uppercase">Status</label>
                            <select id="order_status_select" class="form-select bg-dark text-white border-secondary form-control-dashboard">
                                <option value="all">All Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="preparing">Preparing</option>
                                <option value="ready">Ready</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-muted small text-uppercase">Payment Status</label>
                            <select id="order_payment_status_select" class="form-select bg-dark text-white border-secondary form-control-dashboard">
                                <option value="all">All</option>
                                <option value="paid">Paid</option>
                                <option value="unpaid">Unpaid</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-muted small text-uppercase">Order Type</label>
                            <select id="order_type_select" class="form-select bg-dark text-white border-secondary form-control-dashboard">
                                <option value="all">All Types</option>
                                <option value="online">Online Order</option>
                                <option value="dinein">Dine-In Order</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-muted small text-uppercase">Date Filter</label>
                            <select id="order_date_select" class="form-select bg-dark text-white border-secondary form-control-dashboard" onchange="toggleCustomDateFields('orders', this.value)">
                                <option value="all">All Time</option>
                                <option value="today">Today</option>
                                <option value="yesterday">Yesterday</option>
                                <option value="7days">Last 7 Days</option>
                                <option value="30days">Last 30 Days</option>
                                <option value="custom">Custom Range</option>
                            </select>
                        </div>
                        <div class="col-auto d-flex align-items-end">
                            <button type="submit" class="btn btn-gold-action btn-action-form"><i class="fas fa-search me-1"></i><span>Filter</span></button>
                        </div>
                    </div>
                    <div class="row g-3 mt-2" id="orders_custom_date_row" style="display:none;">
                        <div class="col-md-3">
                            <label class="form-label text-muted small text-uppercase">Start Date</label>
                            <input type="date" id="order_start_date" class="form-control bg-dark text-white border-secondary form-control-dashboard">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-muted small text-uppercase">End Date</label>
                            <input type="date" id="order_end_date" class="form-control bg-dark text-white border-secondary form-control-dashboard">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-muted small text-uppercase">Min Amount (₹)</label>
                            <input type="number" id="order_min_amount" class="form-control bg-dark text-white border-secondary form-control-dashboard" placeholder="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-muted small text-uppercase">Max Amount (₹)</label>
                            <input type="number" id="order_max_amount" class="form-control bg-dark text-white border-secondary form-control-dashboard" placeholder="10000">
                        </div>
                    </div>
                </form>
            </div>

            <!-- ORDERS SEARCH RESULTS CARD (shown by JS after search) -->
            <div id="orders-search-results-card" class="content-card mb-4" style="display:none;">
                <div class="card-header-premium">
                    <span>Search Results</span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('orders-search-results-card').style.display='none'">
                        <i class="fas fa-times me-1"></i> Clear Results
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table premium-table align-middle">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Amount</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody id="orders-search-results-body"></tbody>
                    </table>
                </div>
            </div>

            <ul class="nav nav-tabs mb-4" id="orderTabNav" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active text-white" style="background: transparent; border: none;" id="online-orders-tab" data-bs-toggle="tab" data-bs-target="#online-orders-panel" type="button">Online Orders</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link text-white" style="background: transparent; border: none; margin-left: 10px;" id="dinein-orders-tab" data-bs-toggle="tab" data-bs-target="#dinein-orders-panel" type="button">Dine-In Orders</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link text-white" style="background: transparent; border: none; margin-left: 10px;" id="recent-orders-tab" data-bs-toggle="tab" data-bs-target="#recent-orders-panel" type="button">Recent Orders</button>
                </li>
            </ul>

            <div class="tab-content" id="orderTabContent">
                <!-- ONLINE LIST -->
                <div class="tab-pane fade show active" id="online-orders-panel">
                    <div class="content-card">
                        <div class="card-header-premium">Active Online Delivery & Takeaway Orders</div>
                        <div class="table-responsive">
                            <table class="table premium-table">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Customer</th>
                                        <th>Items Ordered</th>
                                        <th>Total Bill</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $on_stmt = $pdo->query("SELECT *, (SELECT rating FROM feedback WHERE order_number = orders.order_number LIMIT 1) AS rating, (SELECT review FROM feedback WHERE order_number = orders.order_number LIMIT 1) AS review FROM orders WHERE delivery_address NOT LIKE 'Table %' ORDER BY id DESC");
                                    $online_orders = $on_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    if (empty($online_orders)): ?>
                                        <tr><td colspan="6" class="text-center text-muted">No online orders found.</td></tr>
                                    <?php else:
                                    foreach ($online_orders as $ord):
                                        // Fetch items
                                        $it_stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
                                        $it_stmt->execute([$ord['id']]);
                                        $items = $it_stmt->fetchAll(PDO::FETCH_ASSOC);
                                        $items_text = [];
                                        foreach ($items as $it) {
                                            $items_text[] = $it['item_name'] . ' x' . $it['quantity'];
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong class="text-gold">#<?php echo htmlspecialchars($ord['order_number']); ?></strong>
                                                <?php echo renderStars($ord['rating'] ?? 0, $ord['review'] ?? ''); ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($ord['customer_name']); ?></strong><br>
                                                <small class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($ord['customer_phone']); ?></small><br>
                                                <small class="text-muted"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($ord['delivery_address']); ?></small>
                                            </td>
                                            <td><?php echo implode(', ', $items_text); ?></td>
                                            <td class="text-gold"><strong>₹<?php echo number_format($ord['total_amount'], 2); ?></strong></td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower($ord['order_status']); ?>">
                                                    <?php echo htmlspecialchars($ord['order_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (strtolower($ord['order_status']) === 'pending'): ?>
                                                    <button class="btn btn-sm btn-success" onclick="updateOrderStatus(<?php echo $ord['id']; ?>, 'preparing')">Accept</button>
                                                    <button class="btn btn-sm btn-danger ms-1" onclick="updateOrderStatus(<?php echo $ord['id']; ?>, 'cancelled')">Reject</button>
                                                <?php else: ?>
                                                    <select class="form-select form-select-sm bg-dark text-white border-secondary w-auto d-inline-block" onchange="updateOrderStatus(<?php echo $ord['id']; ?>, this.value)">
                                                        <option value="">Change Status</option>
                                                        <option value="preparing" <?php echo strtolower($ord['order_status'])=='preparing'?'selected':''; ?>>Preparing</option>
                                                        <option value="ready" <?php echo strtolower($ord['order_status'])=='ready'?'selected':''; ?>>Ready</option>
                                                        <option value="completed" <?php echo strtolower($ord['order_status'])=='completed'?'selected':''; ?>>Completed</option>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- DINE IN TABLE ORDERS -->
                <div class="tab-pane fade" id="dinein-orders-panel">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="content-card">
                                <div class="card-header-premium">Active Dine-in Tables</div>
                                <div class="list-group bg-dark border-0">
                                    <?php
                                    $dine_active_stmt = $pdo->query("SELECT * FROM orders WHERE order_status IN ('pending', 'preparing', 'ready') AND delivery_address LIKE 'Table %'");
                                    $active_dinein_orders = $dine_active_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    if (empty($active_dinein_orders)): ?>
                                        <p class="text-center text-muted py-3">No active tables placing orders.</p>
                                    <?php else:
                                    foreach ($active_dinein_orders as $d_ord):
                                        // extract table
                                        $tbl = 'Unknown';
                                        if (preg_match('/Table\s+([A-Za-z0-9]+)/i', $d_ord['delivery_address'], $m)) {
                                            $tbl = $m[1];
                                        }
                                        ?>
                                        <button class="list-group-item list-group-item-action bg-dark text-white border-secondary d-flex justify-content-between align-items-center mb-2 rounded" onclick="loadTableOrderDetails(<?php echo htmlspecialchars(json_encode($d_ord)); ?>)">
                                            <span><strong>Table <?php echo $tbl; ?></strong> (<?php echo htmlspecialchars($d_ord['customer_name']); ?>)</span>
                                            <span class="badge bg-gold text-dark">₹<?php echo number_format($d_ord['total_amount'], 2); ?></span>
                                        </button>
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="content-card" id="table-detail-card" style="display:none;">
                                <div class="card-header-premium">
                                    <span id="detail-table-title">Select an Active Table</span>
                                    <span class="status-badge" id="detail-table-status"></span>
                                </div>
                                <div id="table-detail-body">
                                    <!-- Populated dynamically via JS -->
                                    <table class="table premium-table align-middle">
                                        <thead>
                                            <tr>
                                                <th>Dish Name</th>
                                                <th>Qty</th>
                                                <th>Price</th>
                                                <th>Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody id="detail-table-items"></tbody>
                                    </table>
                                    
                                    <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top border-secondary">
                                        <h4>Grand Total: <span id="detail-table-total" class="text-gold">₹0.00</span></h4>
                                        <div>
                                            <button class="btn btn-outline-light" onclick="openAddTableItemModal()"><i class="fas fa-plus"></i> Add Items</button>
                                            <button class="btn btn-gold-action ms-2 btn-action-wide" onclick="openBillSettleModal()"><i class="fas fa-file-invoice"></i><span>Generate Bill & Pay</span></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RECENT ORDERS -->
                <div class="tab-pane fade" id="recent-orders-panel">
                    <div class="content-card">
                        <div class="card-header-premium">Recent Orders</div>
                        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                            <table class="table premium-table">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Details</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_orders)): ?>
                                        <tr><td colspan="5" class="text-center text-muted">No recent orders found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_orders as $ord): ?>
                                        <tr>
                                            <td>
                                                <strong class="text-gold">#<?php echo htmlspecialchars($ord['order_number']); ?></strong>
                                                <?php echo renderStars($ord['rating'] ?? 0, $ord['review'] ?? ''); ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($ord['customer_name']); ?></strong>
                                                <?php if (!empty($ord['customer_phone'])): ?>
                                                    <br><small class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($ord['customer_phone']); ?></small>
                                                <?php endif; ?>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($ord['delivery_address']); ?></small>
                                            </td>
                                            <td class="text-gold"><strong>₹<?php echo number_format($ord['total_amount'], 2); ?></strong></td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower($ord['order_status']); ?>">
                                                    <?php echo htmlspecialchars($ord['order_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo htmlspecialchars($ord['order_date']); ?></small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ==================== TABLES & QR TAB ==================== -->
        <div id="tables-tab" class="tab-panel">
            <div class="page-header">
                <h1 class="page-title">Table Layout & QR Codes</h1>
                <p class="page-subtitle">Configure dine-in layout and download assigned QR menus</p>
            </div>

            <!-- TABLE LAYOUT -->
            <?php foreach ($table_zones as $zone_name => $tables): ?>
            <div class="tables-zone-box">
                <div class="zone-title"><?php echo htmlspecialchars($zone_name); ?></div>
                <div class="table-grid">
                    <?php foreach ($tables as $t_code): 
                        $is_occ = in_array($t_code, $occupied_tables);
                    ?>
                    <div class="table-cell <?php echo $is_occ ? 'occupied' : ''; ?>" onclick="openTableQRModal('<?php echo $t_code; ?>', <?php echo $is_occ ? 'true' : 'false'; ?>)">
                        <div class="table-name">Table <?php echo $t_code; ?></div>
                        <div class="table-status">
                            <?php echo $is_occ ? '🔴 Occupied' : '🟢 Free'; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ==================== KITCHEN PANEL TAB ==================== -->
        <div id="kitchen-tab" class="tab-panel">
            <div class="page-header">
                <h1 class="page-title">Kitchen Monitor</h1>
                <p class="page-subtitle">Live screen for chefs - updates automatically when orders are placed</p>
            </div>

            <!-- Kitchen Search Control -->
            <div class="content-card mb-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase">Search Active Orders</label>
                        <div class="premium-search-group">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="kitchen_search_input" class="form-control form-control-dashboard" placeholder="Search Order ID, Dish Name, Customer, Table..." onkeyup="filterKitchenOrders()">
                        </div>

                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase mb-1">Quick Status Filter</label>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <button class="btn btn-outline-light active btn-sm" id="btn-kitchen-filter-all" onclick="filterKitchenStatus('all')">All</button>
                            <button class="btn btn-outline-light btn-sm ms-1" id="btn-kitchen-filter-pending" onclick="filterKitchenStatus('pending')">Pending</button>
                            <button class="btn btn-outline-light btn-sm ms-1" id="btn-kitchen-filter-preparing" onclick="filterKitchenStatus('preparing')">Cooking</button>
                            <button class="btn btn-outline-light btn-sm ms-1" id="btn-kitchen-filter-ready" onclick="filterKitchenStatus('ready')">Ready</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="kitchen-columns">
                <!-- INCOMING / NEW -->
                <div class="kitchen-col">
                    <div class="kitchen-col-title">
                        <span>New Orders</span>
                        <span class="badge bg-gold text-dark" id="count-kitchen-pending">0</span>
                    </div>
                    <div id="kitchen-pending-list"></div>
                </div>

                <!-- PREPARING -->
                <div class="kitchen-col">
                    <div class="kitchen-col-title">
                        <span>Preparing / Cooking</span>
                        <span class="badge bg-primary text-white" id="count-kitchen-preparing">0</span>
                    </div>
                    <div id="kitchen-preparing-list"></div>
                </div>

                <!-- READY FOR PICKUP -->
                <div class="kitchen-col">
                    <div class="kitchen-col-title">
                        <span>Ready for Service</span>
                        <span class="badge bg-success text-dark" id="count-kitchen-ready">0</span>
                    </div>
                    <div id="kitchen-ready-list"></div>
                </div>
            </div>
        </div>

        <!-- ==================== MENU TAB ==================== -->
        <div id="menu-tab" class="tab-panel">
            <div class="page-header">
                <h1 class="page-title">Menu Management</h1>
                <p class="page-subtitle">Add, edit, toggle availability, and set pricing for dishes</p>
            </div>

            <!-- Menu Search Box -->
            <div class="content-card mb-4">
                <form id="menuSearchForm" onsubmit="performMenuSearch(event)">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label text-muted small text-uppercase">Dish Name</label>
                            <input type="text" id="menu_search_input" class="form-control bg-dark text-white border-secondary form-control-dashboard" placeholder="Search dish name, details...">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-muted small text-uppercase">Category</label>
                            <select id="menu_category_select" class="form-select bg-dark text-white border-secondary form-control-dashboard">
                                <option value="all">All Categories</option>
                                <option value="indian">Indian</option>
                                <option value="italian">Italian</option>
                                <option value="asian">Asian</option>
                                <option value="american">American</option>
                                <option value="desserts">Desserts</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-muted small text-uppercase">Diet Type</label>
                            <select id="menu_diet_select" class="form-select bg-dark text-white border-secondary form-control-dashboard">
                                <option value="all">All Types</option>
                                <option value="veg">Veg</option>
                                <option value="nonveg">Non-Veg</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-muted small text-uppercase">Price Range</label>
                            <div class="d-flex gap-2">
                                <input type="number" id="menu_price_min" class="form-control bg-dark text-white border-secondary form-control-dashboard" placeholder="Min">
                                <input type="number" id="menu_price_max" class="form-control bg-dark text-white border-secondary form-control-dashboard" placeholder="Max">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-muted small text-uppercase">Availability</label>
                            <select id="menu_availability_select" class="form-select bg-dark text-white border-secondary form-control-dashboard">
                                <option value="all">All</option>
                                <option value="1">Available</option>
                                <option value="0">Out of Stock</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end justify-content-end">
                            <button type="submit" class="btn btn-gold-action btn-action-wide"><i class="fas fa-search me-1"></i><span>Filter</span></button>
                        </div>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-md-12">
                            <div class="form-check form-switch">
                                <input type="checkbox" id="menu_bestseller_check" class="form-check-input">
                                <label class="form-check-label text-muted small text-uppercase" for="menu_bestseller_check">Show Bestsellers Only</label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- MENU SEARCH RESULTS CARD (shown by JS after search) -->
            <div id="menu-search-results-card" class="content-card mb-4" style="display:none;">
                <div class="card-header-premium">
                    <span>Menu Search Results</span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('menu-search-results-card').style.display='none'">
                        <i class="fas fa-times me-1"></i> Clear Results
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table premium-table align-middle">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Description</th>
                                <th>Customizations</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="menu-search-results-body"></tbody>
                    </table>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header-premium">
                    <span>Active Food Items</span>
                    <button class="btn btn-gold-action btn-action-wide" onclick="openAddMenuModal()"><i class="fas fa-plus"></i><span>Add New Dish</span></button>
                </div>
                <div class="table-responsive">
                    <table class="table premium-table align-middle">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Description</th>
                                <th>Customizations</th>
                                <th>Available</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menu_list as $dish): ?>
                            <tr>
                                <td>
                                    <img src="<?php echo htmlspecialchars($dish['image_url']); ?>" alt="" style="width: 50px; height: 50px; border-radius: 8px; object-fit: cover;">
                                </td>
                                <td><strong><?php echo htmlspecialchars($dish['name']); ?></strong></td>
                                <td><span class="text-uppercase"><?php echo htmlspecialchars($dish['category']); ?></span></td>
                                <td>₹<?php echo number_format($dish['price'], 2); ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($dish['description']); ?></small></td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" <?php echo $dish['is_available'] ? 'checked' : ''; ?> onchange="toggleMenuAvailability(<?php echo $dish['id']; ?>, this.checked)">
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    // Count customization groups for this dish
                                    $cust_count = 0;
                                    try {
                                        $cc = $pdo->prepare("SELECT COUNT(*) FROM dish_customizations WHERE food_item_id = ?");
                                        $cc->execute([$dish['id']]);
                                        $cust_count = (int)$cc->fetchColumn();
                                    } catch(Exception $e) { $cust_count = 0; }
                                    ?>
                                    <button class="btn btn-sm <?php echo $cust_count > 0 ? 'btn-gold-action' : 'btn-outline-secondary'; ?>" onclick="openCustomizationManager(<?php echo $dish['id']; ?>, '<?php echo htmlspecialchars(addslashes($dish['name'])); ?>')" title="Manage Customizations">
                                        <i class="fas fa-sliders-h"></i> 
                                        <span class="badge bg-dark ms-1"><?php echo $cust_count; ?></span>
                                    </button>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-light" onclick="openEditMenuModal(<?php echo htmlspecialchars(json_encode($dish)); ?>)"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteMenuItem(<?php echo $dish['id']; ?>)"><i class="fas fa-trash-alt"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==================== CUSTOMERS TAB ==================== -->
        <div id="customers-tab" class="tab-panel">
            <div class="page-header">
                <h1 class="page-title">Customer History</h1>
                <p class="page-subtitle">View phone records and lifetime values for regular guests</p>
            </div>

            <!-- Customers Search Box -->
            <div class="content-card mb-4">
                <form id="customersSearchForm" onsubmit="performCustomersSearch(event)">
                    <div class="row g-3">
                        <div class="col-md-9">
                            <label class="form-label text-muted small text-uppercase">Customer Lookup</label>
                            <div class="premium-search-group">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" id="customer_search_input" class="form-control form-control-dashboard" placeholder="Search by name, phone, email, customer ID...">
                            </div>

                        </div>
                        <div class="col-md-3 d-flex align-items-end justify-content-end ms-md-1">
                            <button type="submit" class="btn btn-gold-action btn-action-wide"><i class="fas fa-search me-1"></i><span>Search</span></button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="content-card">
                <div class="card-header-premium">Active Customers Registry</div>
                <div class="table-responsive">
                    <table class="table premium-table align-middle">
                        <thead>
                            <tr>
                                <th>ID / Name</th>
                                <th>Phone Number</th>
                                <th>Email</th>
                                <th>Total Orders</th>
                                <th>Total Spend</th>
                                <th>Last Order</th>
                                <th>Favorite Dish</th>
                                <th>Payment Summary</th>
                            </tr>
                        </thead>
                        <tbody id="customers-table-body">
                            <?php if (empty($customer_list)): ?>
                                <tr><td colspan="8" class="text-center text-muted">No customer data available yet.</td></tr>
                            <?php else:
                            foreach ($customer_list as $cust): 
                                $paid = $cust['payment_summary']['paid_count'] ?? 0;
                                $failed = $cust['payment_summary']['failed_count'] ?? 0;
                                $pending = $cust['payment_summary']['pending_count'] ?? 0;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($cust['customer_name'] ?: 'Guest'); ?></strong><br>
                                    <small class="text-muted">ID: <?php echo htmlspecialchars($cust['customer_id'] ?: 'GUEST'); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($cust['customer_phone'] ?: 'N/A'); ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($cust['email'] ?: 'N/A'); ?></small></td>
                                <td><?php echo $cust['order_count']; ?> orders</td>
                                <td class="text-gold">₹<?php echo number_format($cust['total_spent'], 2); ?></td>
                                <td><small class="text-muted"><?php echo $cust['last_order_date'] ? date('d M Y, h:i A', strtotime($cust['last_order_date'])) : 'N/A'; ?></small></td>
                                <td><span class="badge bg-dark border border-secondary text-white"><?php echo htmlspecialchars($cust['favorite_dish']); ?></span></td>
                                <td>
                                    <span class="badge bg-success text-dark" title="Completed Orders">Paid: <?php echo $paid; ?></span>
                                    <?php if ($pending > 0): ?><span class="badge bg-warning text-dark" title="Pending Orders">Pending: <?php echo $pending; ?></span><?php endif; ?>
                                    <?php if ($failed > 0): ?><span class="badge bg-danger text-white" title="Cancelled Orders">Failed: <?php echo $failed; ?></span><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==================== PAYMENTS TAB ==================== -->
        <div id="payments-tab" class="tab-panel">
            <div class="page-header">
                <h1 class="page-title">Transaction Log</h1>
                <p class="page-subtitle">Track and settle pending cash, card, and UPI bills</p>
            </div>

            <!-- Payments Search Box -->
            <div class="content-card mb-4">
                <form id="paymentsSearchForm" onsubmit="performPaymentsSearch(event)">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label text-muted small text-uppercase">Search Text</label>
                            <input type="text" id="payment_search_input" class="form-control bg-dark text-white border-secondary form-control-dashboard" placeholder="Order ID or Customer...">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-muted small text-uppercase">Method</label>
                            <select id="payment_method_select" class="form-select bg-dark text-white border-secondary form-control-dashboard">
                                <option value="all">All Methods</option>
                                <option value="cash">Cash</option>
                                <option value="upi">UPI</option>
                                <option value="card">Card</option>
                                <option value="netbanking">Net Banking</option>
                                <option value="wallet">Wallet</option>
                                <option value="gateway">Online Gateway</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-muted small text-uppercase">Status</label>
                            <select id="payment_status_select" class="form-select bg-dark text-white border-secondary form-control-dashboard">
                                <option value="all">All Statuses</option>
                                <option value="success">Success / Paid</option>
                                <option value="failed">Failed / Cancelled</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-muted small text-uppercase">Amount Range</label>
                            <div class="d-flex gap-2">
                                <input type="number" id="payment_min_amount" class="form-control bg-dark text-white border-secondary form-control-dashboard" placeholder="Min">
                                <input type="number" id="payment_max_amount" class="form-control bg-dark text-white border-secondary form-control-dashboard" placeholder="Max">
                            </div>
                        </div>
                        <div class="col-md-2 d-flex align-items-end justify-content-end">
                            <button type="submit" class="btn btn-gold-action btn-action-form"><i class="fas fa-search me-1"></i><span>Filter</span></button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="content-card">
                <div class="card-header-premium">Settled Receipts</div>
                <div class="table-responsive">
                    <table class="table premium-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody id="payments-table-body">
                            <?php
                            $pay_stmt = $pdo->query("SELECT * FROM orders ORDER BY id DESC LIMIT 50");
                            $pay_logs = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($pay_logs as $log):
                                $method = 'Online Gateway';
                                if (stripos($log['delivery_address'], 'Paid via CASH') !== false) $method = 'CASH';
                                elseif (stripos($log['delivery_address'], 'Paid via CARD') !== false) $method = 'CARD';
                                elseif (stripos($log['delivery_address'], 'Paid via UPI') !== false) $method = 'UPI';
                                elseif (stripos($log['delivery_address'], 'Paid via NETBANKING') !== false || stripos($log['delivery_address'], 'Paid via NET BANKING') !== false) $method = 'NET BANKING';
                                elseif (stripos($log['delivery_address'], 'Paid via WALLET') !== false) $method = 'WALLET';
                            ?>
                            <tr>
                                <td>#<?php echo htmlspecialchars($log['order_number']); ?></td>
                                <td><?php echo htmlspecialchars($log['customer_name']); ?></td>
                                <td>₹<?php echo number_format($log['total_amount'], 2); ?></td>
                                <td><span class="badge bg-dark border border-secondary text-white"><?php echo $method; ?></span></td>
                                <td>
                                    <span class="status-badge <?php echo strtolower($log['order_status'])==='completed'?'bg-success text-dark':'bg-warning text-dark'; ?>">
                                        <?php echo strtolower($log['order_status'])==='completed'?'Paid':'Pending Settlement'; ?>
                                    </span>
                                </td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($log['order_date']); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==================== REPORTS TAB ==================== -->
        <div id="reports-tab" class="tab-panel">
            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h1 class="page-title">Business Intelligence Dashboard</h1>
                    <p class="page-subtitle">Real-time enterprise analytics and professional reporting center</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-light btn-sm" onclick="printReport()"><i class="fas fa-print me-1"></i> Print Report</button>
                    <button class="btn btn-outline-light btn-sm" onclick="exportReportToPDF()"><i class="fas fa-file-pdf me-1"></i> Export PDF</button>
                    <button class="btn btn-outline-light btn-sm" onclick="exportReportToExcel()"><i class="fas fa-file-excel me-1"></i> Export Excel</button>
                </div>
            </div>

            <!-- Date Filters Selector -->
            <div class="content-card mb-4">
                <form id="reportsFilterForm" onsubmit="loadReportsData(event)">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label text-muted small text-uppercase">Reporting Period</label>
                            <select id="report_range_select" class="form-select bg-dark text-white border-secondary form-control-dashboard" onchange="toggleCustomDateFields('reports', this.value)">
                                <option value="today">Today</option>
                                <option value="yesterday">Yesterday</option>
                                <option value="thisweek" selected>This Week</option>
                                <option value="lastweek">Last Week</option>
                                <option value="thismonth">This Month</option>
                                <option value="lastmonth">Last Month</option>
                                <option value="thisyear">This Year</option>
                                <option value="custom">Custom Date Range</option>
                            </select>
                        </div>
                        <div class="col-md-3 reports_custom_date" style="display:none;">
                            <label class="form-label text-muted small text-uppercase">Start Date</label>
                            <input type="date" id="report_start_date" class="form-control bg-dark text-white border-secondary form-control-dashboard">
                        </div>
                        <div class="col-md-3 reports_custom_date" style="display:none;">
                            <label class="form-label text-muted small text-uppercase">End Date</label>
                            <input type="date" id="report_end_date" class="form-control bg-dark text-white border-secondary form-control-dashboard">
                        </div>
                        <div class="col-md-3 d-flex align-items-end justify-content-end ms-md-1">
                            <button type="submit" class="btn btn-gold-action btn-action-wide"><i class="fas fa-sync-alt me-1"></i><span>Update Report</span></button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- BI Overview Summary Cards -->
            <div class="metric-grid mb-4" id="reports-metrics-grid">
                <div class="metric-card">
                    <div class="metric-info">
                        <h5>Total Revenue</h5>
                        <div class="value text-gold" id="rep_revenue">₹0.00</div>
                        <small id="rep_revenue_growth" class="text-success"><i class="fas fa-caret-up"></i> 0% vs last period</small>
                    </div>
                    <div class="metric-icon"><i class="fas fa-wallet"></i></div>
                </div>
                <div class="metric-card">
                    <div class="metric-info">
                        <h5>Completed Orders</h5>
                        <div class="value" id="rep_orders">0</div>
                        <small id="rep_orders_growth" class="text-success"><i class="fas fa-caret-up"></i> 0% vs last period</small>
                    </div>
                    <div class="metric-icon"><i class="fas fa-shopping-cart"></i></div>
                </div>
                <div class="metric-card">
                    <div class="metric-info">
                        <h5>Average Order Value</h5>
                        <div class="value" id="rep_aov">₹0.00</div>
                        <small id="rep_aov_growth" class="text-success"><i class="fas fa-caret-up"></i> 0% vs last period</small>
                    </div>
                    <div class="metric-icon"><i class="fas fa-calculator"></i></div>
                </div>
                <div class="metric-card">
                    <div class="metric-info">
                        <h5>Performance Score</h5>
                        <div class="value text-gold" id="rep_perf_score" style="font-size: 2.2rem; font-weight: 800;">0/100</div>
                        <small class="text-muted">Acceptance & Completion rate</small>
                    </div>
                    <div class="metric-icon"><i class="fas fa-award"></i></div>
                </div>
            </div>

            <!-- Visual Analytics Grid (Charts) -->
            <div class="row mb-4">
                <div class="col-lg-7 mb-4">
                    <div class="content-card h-100">
                        <div class="card-header-premium">Sales Revenue Trend</div>
                        <div style="height: 320px; position: relative;">
                            <canvas id="repSalesChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 mb-4">
                    <div class="content-card h-100">
                        <div class="card-header-premium">Payment Breakup</div>
                        <div style="height: 320px; position: relative; display: flex; justify-content: center; align-items: center;">
                            <canvas id="repPaymentChart" style="max-height: 280px; max-width: 280px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-lg-6 mb-4">
                    <div class="content-card h-100">
                        <div class="card-header-premium">Category Performance Analysis</div>
                        <div style="height: 320px; position: relative; display: flex; justify-content: center; align-items: center;">
                            <canvas id="repCategoryChart" style="max-height: 280px; max-width: 280px;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="content-card h-100">
                        <div class="card-header-premium">Operations & Order Analytics</div>
                        <div class="p-3">
                            <div class="row text-center mb-4">
                                <div class="col-6 mb-3">
                                    <h6 class="text-muted small text-uppercase">Online Orders</h6>
                                    <h4 class="text-white" id="rep_op_online">0</h4>
                                </div>
                                <div class="col-6 mb-3">
                                    <h6 class="text-muted small text-uppercase">Dine-In Orders</h6>
                                    <h4 class="text-white" id="rep_op_dinein">0</h4>
                                </div>
                                <div class="col-6">
                                    <h6 class="text-muted small text-uppercase">Acceptance Rate</h6>
                                    <h4 class="text-success" id="rep_op_acceptance">0%</h4>
                                </div>
                                <div class="col-6">
                                    <h6 class="text-muted small text-uppercase">Completion Rate</h6>
                                    <h4 class="text-success" id="rep_op_completion">0%</h4>
                                </div>
                            </div>
                            <hr class="border-secondary mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Customers Reached:</span>
                                <strong id="rep_cust_total">0</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>New Guest Registrations:</span>
                                <strong id="rep_cust_new">0</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Returning Customer Base:</span>
                                <strong id="rep_cust_returning">0</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Guest Retention Rate:</span>
                                <strong class="text-gold" id="rep_cust_retention">0%</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Best Sellers & Customers Breakdown Table -->
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="content-card h-100">
                        <div class="card-header-premium">Best Selling Dishes Breakdown</div>
                        <div class="table-responsive" style="max-height: 350px;">
                            <table class="table premium-table align-middle" id="rep-dishes-table">
                                <thead>
                                    <tr>
                                        <th>Dish Name</th>
                                        <th>Qty Sold</th>
                                        <th>Revenue Generated</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="content-card h-100">
                        <div class="card-header-premium">Top Performing Customers</div>
                        <div class="table-responsive" style="max-height: 350px;">
                            <table class="table premium-table align-middle" id="rep-customers-table">
                                <thead>
                                    <tr>
                                        <th>Name / Phone</th>
                                        <th>Orders</th>
                                        <th>Total Spent</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ==================== SETTINGS TAB ==================== -->
        <div id="settings-tab" class="tab-panel">
            <div class="page-header">
                <h1 class="page-title">Restaurant Settings</h1>
                <p class="page-subtitle">Configure core branding, GST rates, and operational parameters</p>
            </div>

            <div class="content-card col-md-8">
                <div class="card-header-premium">Branding & System Configurations</div>
                <form id="settingsForm" onsubmit="saveSettings(event)">
                    <div class="mb-3">
                        <label class="form-label text-muted text-uppercase small">Restaurant Brand Name</label>
                        <input type="text" id="set_restaurant_name" class="form-control form-control-dashboard" value="<?php echo htmlspecialchars($settings['restaurant_name']); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted text-uppercase small">GST Surcharge Rate (%)</label>
                        <input type="number" id="set_gst_rate" class="form-control form-control-dashboard" value="<?php echo intval($settings['gst_rate']); ?>" required min="0" max="30">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted text-uppercase small">Packing Charges (₹)</label>
                        <input type="number" step="0.01" id="set_packing_charge" class="form-control form-control-dashboard" value="<?php echo isset($settings['packing_charge']) ? floatval($settings['packing_charge']) : 0.00; ?>" required min="0">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted text-uppercase small">Operational Business Hours</label>
                        <input type="text" id="set_opening_hours" class="form-control form-control-dashboard" value="<?php echo htmlspecialchars($settings['opening_hours']); ?>" required>
                    </div>

                    <button type="submit" class="btn btn-gold-action mt-3 btn-action-wide">Save Server Config</button>
                </form>
            </div>
        </div>

    </div>

    <!-- ==================== MODALS ==================== -->
    
    <!-- Table QR Modal -->
    <div class="modal fade" id="tableQRModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark border-secondary text-white">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title font-playfair" id="qrModalTitle">Table Config</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="qr-card-view p-4">
                        <div class="qr-title-text" id="qrTableLabel">Table T01</div>
                        <p class="qr-sub">Scan to land automatically on ordering menu</p>
                        
                        <div id="qrCodeContainer" class="d-flex justify-content-center p-3 bg-white rounded w-50 mx-auto mb-3">
                            <!-- QR code generated here -->
                        </div>
                        
                        <div class="d-flex justify-content-center gap-2">
                            <a id="qrOpenLink" href="#" target="_blank" class="btn btn-sm btn-outline-light"><i class="fas fa-external-link-alt"></i> Open Menu</a>
                            <button id="btnDineInAct" class="btn btn-sm btn-gold-action"><i class="fas fa-plus"></i> Open Dine-In Bill</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Settle Bill Modal -->
    <div class="modal fade" id="settleBillModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark border-secondary text-white">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title font-playfair">Settle Dine-In Invoice</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="settleBillForm" onsubmit="submitSettleBill(event)">
                    <div class="modal-body">
                        <input type="hidden" id="settle_order_id">
                        
                        <div class="mb-3 text-center">
                            <h6 class="text-muted">Total Amount Due</h6>
                            <h2 class="text-gold" id="settle_bill_total">₹0.00</h2>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted">Select Settlement Payment Method</label>
                            <select id="settle_payment_method" class="form-select bg-dark text-white border-secondary" required>
                                <option value="cash">CASH</option>
                                <option value="upi">UPI (GPay/Paytm)</option>
                                <option value="card">CREDIT/DEBIT CARD</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="submit" class="btn btn-gold-action btn-action-full">Settle & Mark Paid</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Table Item Modal -->
    <div class="modal fade" id="addTableItemModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark border-secondary text-white">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title font-playfair">Add Dish to Table</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="addTableItemForm" onsubmit="submitAddTableItem(event)">
                    <div class="modal-body">
                        <input type="hidden" id="add_table_order_id">
                        
                        <div class="mb-3">
                            <label class="form-label text-muted">Select Food Dish</label>
                            <select id="add_table_food_id" class="form-select bg-dark text-white border-secondary" required>
                                <?php foreach ($menu_list as $dish): ?>
                                    <option value="<?php echo $dish['id']; ?>"><?php echo htmlspecialchars($dish['name']); ?> (₹<?php echo number_format($dish['price'], 2); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted">Quantity</label>
                            <input type="number" id="add_table_qty" class="form-control bg-dark text-white border-secondary" value="1" min="1" required>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="submit" class="btn btn-gold-action btn-action-full">Add Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ==================== CUSTOMIZATION MANAGER MODAL ==================== -->
    <div class="modal fade" id="customizationManagerModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content text-white" style="background:#121111; border:1px solid rgba(223,186,134,0.25);">
                <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,0.07);">
                    <div>
                        <h5 class="modal-title font-playfair text-gold" id="custManagerTitle">Manage Customizations</h5>
                        <small class="text-muted" id="custManagerSubtitle">Loading...</small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height:70vh; overflow-y:auto;">

                    <!-- Existing customization groups -->
                    <div id="existingCustomizationsContainer"></div>

                    <!-- Divider -->
                    <hr style="border-color:rgba(255,255,255,0.07); margin:1.5rem 0;">

                    <!-- Add New Customization Group -->
                    <div style="background:rgba(223,186,134,0.05); border:1px dashed rgba(223,186,134,0.25); border-radius:12px; padding:1.5rem;">
                        <h6 class="text-gold mb-3"><i class="fas fa-plus-circle me-2"></i>Add New Customization Group</h6>
                        <form id="addCustomGroupForm" onsubmit="submitCustomGroup(event)">
                            <input type="hidden" id="cust_food_item_id">
                            <input type="hidden" id="cust_group_edit_id" value="">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label text-muted small text-uppercase">Group Name</label>
                                    <input type="text" id="cust_group_name" class="form-control bg-dark text-white border-secondary" placeholder="e.g. Crust Type, Size, Toppings" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label text-muted small text-uppercase">Selection Type</label>
                                    <select id="cust_group_type" class="form-select bg-dark text-white border-secondary">
                                        <option value="single">Single Choice (Radio)</option>
                                        <option value="multiple">Multi-Select (Checkbox)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label text-muted small text-uppercase">Required?</label>
                                    <select id="cust_group_required" class="form-select bg-dark text-white border-secondary">
                                        <option value="0">Optional</option>
                                        <option value="1">Required</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Options Builder -->
                            <div class="mt-3">
                                <label class="form-label text-muted small text-uppercase">Options <span class="text-gold">(add at least 1)</span></label>
                                <div id="optionsBuilderContainer"></div>
                                <button type="button" class="btn btn-outline-secondary btn-sm mt-2" onclick="addOptionRow()">
                                    <i class="fas fa-plus"></i> Add Option
                                </button>
                            </div>

                            <div class="mt-3 d-flex gap-2">
                                <button type="submit" class="btn btn-gold-action btn-action-wide" id="custGroupSubmitBtn">Save Group</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="resetCustomGroupForm()">Cancel / Reset</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Menu CRUD Modal -->
    <div class="modal fade" id="menuCrudModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark border-secondary text-white">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title font-playfair" id="menuModalTitle">Add Menu Item</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="menuCrudForm" onsubmit="submitMenuCrud(event)">
                    <div class="modal-body">
                        <input type="hidden" id="menu_item_id">
                        
                        <div class="mb-3">
                            <label class="form-label text-muted">Dish Name</label>
                            <input type="text" id="menu_name" class="form-control bg-dark text-white border-secondary" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted">Category</label>
                            <select id="menu_category" class="form-select bg-dark text-white border-secondary" required>
                                <option value="indian">Indian Specialties</option>
                                <option value="italian">Italian</option>
                                <option value="asian">Asian</option>
                                <option value="american">American</option>
                                <option value="desserts">Desserts</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted">Base Price (INR)</label>
                            <input type="number" step="0.01" id="menu_price" class="form-control bg-dark text-white border-secondary" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted">Description</label>
                            <textarea id="menu_description" rows="2" class="form-control bg-dark text-white border-secondary" required></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted">Image URL (Optional)</label>
                            <input type="text" id="menu_image_url" class="form-control bg-dark text-white border-secondary">
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="submit" class="btn btn-gold-action btn-action-full" id="btnMenuSubmit">Save Dish</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap & jQuery -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Global variables for active table tracking
        let activeDineInOrder = null;
        let selectedTableQR = null;

        // Dark/Light Theme Switching System
        function updateThemeUI() {
            const isLight = document.documentElement.classList.contains('light-mode');
            const icon = document.getElementById('themeIcon');
            const btn = document.getElementById('themeToggleBtn');
            
            if (isLight) {
                if (icon) {
                    icon.className = 'fas fa-sun';
                    icon.style.color = 'var(--gold)';
                }
                if (btn) {
                    btn.style.background = 'var(--bg-secondary)';
                    btn.style.borderColor = 'rgba(0, 0, 0, 0.08)';
                    btn.style.boxShadow = '0 4px 15px rgba(0,0,0,0.06)';
                }
            } else {
                if (icon) {
                    icon.className = 'fas fa-moon';
                    icon.style.color = 'var(--gold)';
                }
                if (btn) {
                    btn.style.background = 'var(--bg-secondary)';
                    btn.style.borderColor = 'rgba(255, 255, 255, 0.08)';
                    btn.style.boxShadow = '0 4px 15px rgba(0,0,0,0.3)';
                }
            }
            updateChartTheme();
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

        function updateChartTheme() {
            if (!window.salesChartInstance) return;
            const isLight = document.documentElement.classList.contains('light-mode');
            const gridColor = isLight ? 'rgba(0, 0, 0, 0.05)' : 'rgba(255, 255, 255, 0.05)';
            const tickColor = isLight ? '#64748b' : '#a09f9f';
            
            window.salesChartInstance.options.scales.x.grid.color = gridColor;
            window.salesChartInstance.options.scales.x.ticks.color = tickColor;
            window.salesChartInstance.options.scales.y.grid.color = gridColor;
            window.salesChartInstance.options.scales.y.ticks.color = tickColor;
            window.salesChartInstance.update();
        }

        // Switch Sidebar Tabs
        function switchTab(tabId, el) {
            // Remove active classes
            document.querySelectorAll('.sidebar-link').forEach(link => link.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(panel => panel.classList.remove('active'));
            
            // Add active class
            el.classList.add('active');
            document.getElementById(tabId).classList.add('active');
            
            // If kitchen panel is active, start live polling
            if (tabId === 'kitchen-tab') {
                startKitchenPolling();
            } else {
                stopKitchenPolling();
            }
        }

        // 1. Chart.js Sales Graph
        const ctx = document.getElementById('salesChart');
        if (ctx) {
            window.salesChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        label: 'Sales Revenue (₹)',
                        data: <?php echo json_encode($chart_data); ?>,
                        borderColor: '#dfba86',
                        backgroundColor: 'rgba(223, 186, 134, 0.1)',
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            grid: { color: 'rgba(255, 255, 255, 0.05)' },
                            ticks: { color: '#a09f9f' }
                        },
                        x: {
                            grid: { color: 'rgba(255, 255, 255, 0.05)' },
                            ticks: { color: '#a09f9f' }
                        }
                    },
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
            updateChartTheme(); // apply theme colors to chart immediately
        }

        // Initialize theme UI state on DOMContentLoaded
        document.addEventListener('DOMContentLoaded', function() {
            updateThemeUI();
        });

        // 2. Order Status Controls (Online list)
        function updateOrderStatus(id, newStatus) {
            if (!newStatus) return;
            fetch('dashboardtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'update_order_status',
                    order_id: id,
                    status: newStatus
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error updating order status');
                }
            });
        }

        // 3. Dine-In Table Selection Loader
        function loadTableOrderDetails(order) {
            activeDineInOrder = order;
            document.getElementById('table-detail-card').style.display = 'block';
            
            // extract table number
            let tbl = 'Unknown';
            if (preg_match = order.delivery_address.match(/Table\s+([A-Za-z0-9]+)/i)) {
                tbl = preg_match[1];
            }
            
            document.getElementById('detail-table-title').textContent = 'Table ' + tbl + ' Order Details';
            
            const badge = document.getElementById('detail-table-status');
            badge.className = 'status-badge status-' + order.order_status.toLowerCase();
            badge.textContent = order.order_status;
            
            // Fetch items
            fetch('dashboardtest.php?action=get_kitchen_orders')
            .then(res => res.json())
            .then(data => {
                const updatedOrder = data.orders.find(o => o.id == order.id);
                if (updatedOrder) {
                    activeDineInOrder = updatedOrder;
                    renderTableItems(updatedOrder.items, updatedOrder.total_amount);
                } else {
                    // Fallback to active order items
                    renderTableItems([], order.total_amount);
                }
            });
        }

        function renderTableItems(items, total) {
            const tbody = document.getElementById('detail-table-items');
            tbody.innerHTML = '';
            
            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No items in this order yet.</td></tr>';
            } else {
                items.forEach(it => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td><strong>${it.item_name}</strong></td>
                        <td>${it.quantity}</td>
                        <td>₹${parseFloat(it.price).toFixed(2)}</td>
                        <td>₹${(it.price * it.quantity).toFixed(2)}</td>
                    `;
                    tbody.appendChild(row);
                });
            }
            document.getElementById('detail-table-total').textContent = '₹' + parseFloat(total).toFixed(2);
        }

        // 4. Dine-in Order Modification
        function openAddTableItemModal() {
            if (!activeDineInOrder) return;
            document.getElementById('add_table_order_id').value = activeDineInOrder.id;
            const modal = new bootstrap.Modal(document.getElementById('addTableItemModal'));
            modal.show();
        }

        function submitAddTableItem(e) {
            e.preventDefault();
            const order_id = document.getElementById('add_table_order_id').value;
            const food_item_id = document.getElementById('add_table_food_id').value;
            const quantity = document.getElementById('add_table_qty').value;
            
            fetch('dashboardtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'add_table_item',
                    order_id: order_id,
                    food_item_id: food_item_id,
                    quantity: quantity
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('addTableItemModal')).hide();
                    alert('Item added successfully!');
                    // Reload table details
                    loadTableOrderDetails(activeDineInOrder);
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        // 5. Dine-in Settle Invoice
        function openBillSettleModal() {
            if (!activeDineInOrder) return;
            document.getElementById('settle_order_id').value = activeDineInOrder.id;
            document.getElementById('settle_bill_total').textContent = '₹' + parseFloat(activeDineInOrder.total_amount).toFixed(2);
            
            const modal = new bootstrap.Modal(document.getElementById('settleBillModal'));
            modal.show();
        }

        function submitSettleBill(e) {
            e.preventDefault();
            const order_id = document.getElementById('settle_order_id').value;
            const method = document.getElementById('settle_payment_method').value;
            
            fetch('dashboardtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'settle_bill',
                    order_id: order_id,
                    payment_method: method
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('settleBillModal')).hide();
                    alert('Invoice successfully settled & table released!', () => {
                        location.reload();
                    });
                } else {
                    alert('Error settling bill');
                }
            });
        }

        // 6. QR Code Configuration Dialog
        function openTableQRModal(tableCode, isOccupied) {
            selectedTableQR = tableCode;
            document.getElementById('qrModalTitle').textContent = 'Table ' + tableCode + ' Configuration';
            document.getElementById('qrTableLabel').textContent = 'Table ' + tableCode;
            
            // Generate QR Code targeting the menu page with this table number prefilled
            // Get local IP address (or fallback to localhost)
            const localHost = window.location.host;
            const menuUrl = `http://${localHost}/test/menutest.html?table=${tableCode}`;
            
            const qrContainer = document.getElementById('qrCodeContainer');
            qrContainer.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(menuUrl)}" alt="QR Code" style="width: 150px; height: 150px;">`;
            
            document.getElementById('qrOpenLink').href = menuUrl;
            
            const btnAct = document.getElementById('btnDineInAct');
            if (isOccupied) {
                btnAct.textContent = 'View Active Order';
                btnAct.className = 'btn btn-sm btn-gold-action';
                btnAct.onclick = () => {
                    bootstrap.Modal.getInstance(document.getElementById('tableQRModal')).hide();
                    switchTab('orders-tab', document.querySelector('.sidebar-link[onclick*="orders-tab"]'));
                    // Load table active order
                    // Look up order matching tableCode
                    fetch('dashboardtest.php?action=get_kitchen_orders')
                    .then(res => res.json())
                    .then(data => {
                        const ord = data.orders.find(o => o.delivery_address.includes('Table ' + tableCode));
                        if (ord) {
                            loadTableOrderDetails(ord);
                        } else {
                            alert('No active order data found');
                        }
                    });
                };
            } else {
                btnAct.textContent = 'Open New Dine-In Order';
                btnAct.className = 'btn btn-sm btn-outline-light';
                btnAct.onclick = () => {
                    const custName = prompt('Enter Guest Name (Optional):', 'Guest');
                    if (custName === null) return;
                    
                    fetch('dashboardtest.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'create_dinein_order',
                            table_code: tableCode,
                            customer_name: custName
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            bootstrap.Modal.getInstance(document.getElementById('tableQRModal')).hide();
                            alert('New table order successfully opened!', () => {
                                location.reload();
                            });
                        } else {
                            alert('Error opening table order');
                        }
                    });
                };
            }

            const modal = new bootstrap.Modal(document.getElementById('tableQRModal'));
            modal.show();
        }

        // 7. Kitchen Panel Live Polling Logic
        let kitchenInterval = null;
        
        function startKitchenPolling() {
            loadKitchenOrders();
            kitchenInterval = setInterval(loadKitchenOrders, 5000); // Poll every 5 seconds
        }
        
        function stopKitchenPolling() {
            if (kitchenInterval) {
                clearInterval(kitchenInterval);
                kitchenInterval = null;
            }
        }
        
        function loadKitchenOrders() {
            fetch('dashboardtest.php?action=get_kitchen_orders')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    renderKitchenColumn(data.orders.filter(o => o.order_status.toLowerCase() === 'pending'), 'kitchen-pending-list', 'pending');
                    renderKitchenColumn(data.orders.filter(o => o.order_status.toLowerCase() === 'preparing'), 'kitchen-preparing-list', 'preparing');
                    renderKitchenColumn(data.orders.filter(o => o.order_status.toLowerCase() === 'ready'), 'kitchen-ready-list', 'ready');
                }
            });
        }
        
        function renderKitchenColumn(orders, containerId, columnType) {
            const container = document.getElementById(containerId);
            container.innerHTML = '';
            
            // Update counter badge
            document.getElementById('count-kitchen-' + columnType).textContent = orders.length;
            
            if (orders.length === 0) {
                container.innerHTML = `<div class="text-center text-muted py-5">No orders in this state.</div>`;
                return;
            }
            
            orders.forEach(order => {
                const card = document.createElement('div');
                card.className = 'kitchen-card';
                
                const itemsList = order.items.map(it => `<li>${it.item_name} <strong>x${it.quantity}</strong></li>`).join('');
                
                let btn = '';
                if (columnType === 'pending') {
                    btn = `<button class="btn btn-sm btn-gold-action btn-action-full" onclick="updateOrderStatus(${order.id}, 'preparing')">Start Cooking</button>`;
                } else if (columnType === 'preparing') {
                    btn = `<button class="btn btn-sm btn-success w-100 text-dark" onclick="updateOrderStatus(${order.id}, 'ready')">Mark Ready</button>`;
                } else if (columnType === 'ready') {
                    btn = `<button class="btn btn-sm btn-primary w-100 text-white" onclick="updateOrderStatus(${order.id}, 'completed')">Complete / Serve</button>`;
                }
                
                card.innerHTML = `
                    <div class="kitchen-card-header">
                        <span>#${order.order_number}</span>
                        <span class="text-gold">${order.delivery_address}</span>
                    </div>
                    <ul class="kitchen-card-items">
                        ${itemsList}
                    </ul>
                    ${btn}
                `;
                container.appendChild(card);
            });
        }

        // ======= CUSTOMIZATION MANAGER =======
        let custFoodItemId = null;

        function openCustomizationManager(foodItemId, dishName) {
            custFoodItemId = foodItemId;
            document.getElementById('custManagerTitle').textContent = 'Customizations: ' + dishName;
            document.getElementById('custManagerSubtitle').textContent = 'Add/remove selection groups (size, crust, toppings, sauce, etc.)';
            document.getElementById('cust_food_item_id').value = foodItemId;
            resetCustomGroupForm();
            loadExistingCustomizations(foodItemId);
            const modal = new bootstrap.Modal(document.getElementById('customizationManagerModal'));
            modal.show();
        }

        function loadExistingCustomizations(foodItemId) {
            const container = document.getElementById('existingCustomizationsContainer');
            container.innerHTML = '<div class="text-muted text-center py-3"><i class="fas fa-spinner fa-spin me-2"></i>Loading...</div>';

            fetch('../api/save-customization.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'get_customizations', food_item_id: foodItemId })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    container.innerHTML = '<div class="alert alert-warning">Could not load customizations. Please import the updated restaurant_db.sql first.</div>';
                    return;
                }
                if (data.customizations.length === 0) {
                    container.innerHTML = '<div class="text-center text-muted py-3"><i class="fas fa-info-circle me-2"></i>No customizations set up for this dish yet.</div>';
                    return;
                }
                container.innerHTML = '';
                data.customizations.forEach(group => {
                    const card = document.createElement('div');
                    card.className = 'mb-3';
                    card.style.cssText = 'background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07); border-radius:10px; padding:1rem;';

                    const optionTags = group.options.map(o => {
                        const priceLabel = o.price_add > 0 ? ` <span style="color:#2ec4b6">+₹${o.price_add}</span>` : (o.price_add < 0 ? ` <span style="color:#ff6b6b">-₹${Math.abs(o.price_add)}</span>` : '');
                        return `<span style="background:rgba(223,186,134,0.1); color:#dfba86; border:1px solid rgba(223,186,134,0.2); border-radius:20px; padding:2px 10px; font-size:0.8rem; display:inline-block; margin:2px;">${o.label}${priceLabel}</span>`;
                    }).join('');

                    const typeBadge = group.group_type === 'multiple' ? '<span class="badge bg-primary ms-2">Multi-Select</span>' : '<span class="badge bg-secondary ms-2">Single Choice</span>';
                    const reqBadge = group.is_required == 1 ? '<span class="badge bg-danger ms-1">Required</span>' : '<span class="badge bg-dark ms-1">Optional</span>';

                    card.innerHTML = `
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <strong style="color:#fff;">${group.group_name}</strong>
                                ${typeBadge}${reqBadge}
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-light" onclick="editCustomGroup(${JSON.stringify(group).replace(/"/g,'&quot;')})" title="Edit Group"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteCustomGroup(${group.id})" title="Delete Group"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <div>${optionTags}</div>
                    `;
                    container.appendChild(card);
                });
            })
            .catch(() => {
                container.innerHTML = '<div class="alert alert-danger">Failed to load. Check that dish_customizations table exists in your database.</div>';
            });
        }

        function addOptionRow(label = '', priceAdd = 0) {
            const container = document.getElementById('optionsBuilderContainer');
            const idx = container.children.length;
            const row = document.createElement('div');
            row.className = 'd-flex gap-2 mb-2 option-row align-items-center';
            row.innerHTML = `
                <input type="text" class="form-control bg-dark text-white border-secondary option-label" placeholder="Option label (e.g. Thin Crust)" value="${label}" required>
                <input type="number" step="1" class="form-control bg-dark text-white border-secondary option-price" style="max-width:130px;" placeholder="Price (+/-)" value="${priceAdd}" title="Price added to base (0 = free, negative = discount)">
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.option-row').remove()" title="Remove"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(row);
        }

        function collectOptionsFromForm() {
            const rows = document.querySelectorAll('#optionsBuilderContainer .option-row');
            const options = [];
            rows.forEach(row => {
                const lbl = row.querySelector('.option-label').value.trim();
                const price = parseFloat(row.querySelector('.option-price').value) || 0;
                if (lbl) options.push({ label: lbl, price_add: price });
            });
            return options;
        }

        function submitCustomGroup(e) {
            e.preventDefault();
            const options = collectOptionsFromForm();
            if (options.length === 0) {
                alert('Please add at least one option to this group.');
                return;
            }

            const editId = document.getElementById('cust_group_edit_id').value;
            const bodyData = {
                action: 'save_customization_group',
                food_item_id: custFoodItemId,
                group_name: document.getElementById('cust_group_name').value,
                group_type: document.getElementById('cust_group_type').value,
                is_required: document.getElementById('cust_group_required').value,
                options_json: JSON.stringify(options),
                sort_order: 0,
                id: editId
            };

            fetch('../api/save-customization.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(bodyData)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    resetCustomGroupForm();
                    loadExistingCustomizations(custFoodItemId);
                } else {
                    alert('Error: ' + (data.message || 'Save failed'));
                }
            });
        }

        function editCustomGroup(group) {
            document.getElementById('cust_group_edit_id').value = group.id;
            document.getElementById('cust_group_name').value = group.group_name;
            document.getElementById('cust_group_type').value = group.group_type;
            document.getElementById('cust_group_required').value = group.is_required;
            document.getElementById('custGroupSubmitBtn').textContent = 'Update Group';

            // Clear and repopulate options
            const container = document.getElementById('optionsBuilderContainer');
            container.innerHTML = '';
            (group.options || []).forEach(o => addOptionRow(o.label, o.price_add));

            // Scroll to form
            document.getElementById('addCustomGroupForm').scrollIntoView({ behavior: 'smooth' });
        }

        function deleteCustomGroup(id) {
            if (!confirm('Delete this customization group? This cannot be undone.')) return;
            fetch('../api/save-customization.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'delete_customization_group', id: id })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    loadExistingCustomizations(custFoodItemId);
                } else {
                    alert('Delete failed');
                }
            });
        }

        function resetCustomGroupForm() {
            document.getElementById('addCustomGroupForm').reset();
            document.getElementById('cust_group_edit_id').value = '';
            document.getElementById('cust_food_item_id').value = custFoodItemId || '';
            document.getElementById('optionsBuilderContainer').innerHTML = '';
            document.getElementById('custGroupSubmitBtn').textContent = 'Save Group';
            // Add one blank option row to start
            addOptionRow();
        }

        // 8. Menu Management CRUD
        function toggleMenuAvailability(id, isChecked) {
            fetch('dashboardtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'toggle_menu_item',
                    id: id,
                    val: isChecked ? 1 : 0
                })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert('Failed to update availability status.');
                }
            });
        }

        function openAddMenuModal() {
            document.getElementById('menuCrudForm').reset();
            document.getElementById('menu_item_id').value = '';
            document.getElementById('menuModalTitle').textContent = 'Add New Dish';
            document.getElementById('btnMenuSubmit').textContent = 'Save Dish';
            
            const modal = new bootstrap.Modal(document.getElementById('menuCrudModal'));
            modal.show();
        }

        function openEditMenuModal(dish) {
            document.getElementById('menu_item_id').value = dish.id;
            document.getElementById('menu_name').value = dish.name;
            document.getElementById('menu_category').value = dish.category;
            document.getElementById('menu_price').value = dish.price;
            document.getElementById('menu_description').value = dish.description;
            document.getElementById('menu_image_url').value = dish.image_url;
            
            document.getElementById('menuModalTitle').textContent = 'Edit Dish Details';
            document.getElementById('btnMenuSubmit').textContent = 'Update Dish';
            
            const modal = new bootstrap.Modal(document.getElementById('menuCrudModal'));
            modal.show();
        }

        function submitMenuCrud(e) {
            e.preventDefault();
            const id = document.getElementById('menu_item_id').value;
            const action = id ? 'edit_menu_item' : 'add_menu_item';
            
            const bodyData = {
                action: action,
                name: document.getElementById('menu_name').value,
                category: document.getElementById('menu_category').value,
                price: document.getElementById('menu_price').value,
                description: document.getElementById('menu_description').value,
                image_url: document.getElementById('menu_image_url').value
            };
            if (id) bodyData.id = id;
            
            fetch('dashboardtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(bodyData)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('menuCrudModal')).hide();
                    alert('Dish successfully saved!', () => {
                        location.reload();
                    });
                } else {
                    alert('Error saving menu details');
                }
            });
        }

        function deleteMenuItem(id) {
            if (!confirm('Are you sure you want to delete this menu dish permanently?')) return;
            
            fetch('dashboardtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'delete_menu_item',
                    id: id
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error deleting menu item');
                }
            });
        }

        // 9. Save settings
        function saveSettings(e) {
            e.preventDefault();
            fetch('dashboardtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'save_settings',
                    restaurant_name: document.getElementById('set_restaurant_name').value,
                    gst_rate: document.getElementById('set_gst_rate').value,
                    packing_charge: document.getElementById('set_packing_charge').value,
                    opening_hours: document.getElementById('set_opening_hours').value
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Settings updated successfully!', () => {
                        location.reload();
                    });
                } else {
                    alert('Error saving configs');
                }
            });
        }

        // =====================================================================
        // ADVANCED SEARCH & REPORTING FRONTEND CONTROLLER
        // =====================================================================

        // ---- Utility: debounce helper ----
        function debounce(fn, delay) {
            let timer;
            return function(...args) {
                clearTimeout(timer);
                timer = setTimeout(() => fn.apply(this, args), delay);
            };
        }

        // ---- Utility: show loading spinner in a tbody ----
        function setTableLoading(tbodyId, colSpan) {
            const tbody = document.getElementById(tbodyId);
            if (!tbody) return;
            tbody.innerHTML = `<tr><td colspan="${colSpan}" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Searching...</td></tr>`;
        }

        // ---- Utility: format currency ----
        function fmtINR(val) {
            return '₹' + parseFloat(val || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // ---- Utility: growth badge HTML ----
        function growthBadge(val) {
            const num = parseFloat(val || 0);
            const cls = num >= 0 ? 'text-success' : 'text-danger';
            const icon = num >= 0 ? 'fa-caret-up' : 'fa-caret-down';
            return `<i class="fas ${icon}"></i> ${Math.abs(num)}% vs last period`;
        }

        // ---- Toggle Custom Date Fields ----
        function toggleCustomDateFields(context, value) {
            if (context === 'orders') {
                const row = document.getElementById('orders_custom_date_row');
                if (row) row.style.display = (value === 'custom') ? 'flex' : 'none';
            } else if (context === 'reports') {
                document.querySelectorAll('.reports_custom_date').forEach(el => {
                    el.style.display = (value === 'custom') ? 'block' : 'none';
                });
            }
        }

        // =====================================================================
        // 1. ORDERS SEARCH
        // =====================================================================
        function performOrdersSearch(event) {
            if (event) event.preventDefault();
            setTableLoading('orders-search-results-body', 7);

            const params = new URLSearchParams({
                action: 'search_orders',
                search: document.getElementById('order_search_input')?.value || '',
                status: document.getElementById('order_status_select')?.value || 'all',
                payment_status: document.getElementById('order_payment_status_select')?.value || 'all',
                type: document.getElementById('order_type_select')?.value || 'all',
                date: document.getElementById('order_date_select')?.value || 'all',
                start_date: document.getElementById('order_start_date')?.value || '',
                end_date: document.getElementById('order_end_date')?.value || '',
                min_amount: document.getElementById('order_min_amount')?.value || '',
                max_amount: document.getElementById('order_max_amount')?.value || ''
            });

            fetch('dashboardtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) { showSearchError('orders-search-results-body', 7, 'Search failed.'); return; }
                renderOrdersSearchResults(data.orders);
            })
            .catch(() => showSearchError('orders-search-results-body', 7, 'Network error.'));
        }

        function getStarRatingHtml(rating, review) {
            rating = parseInt(rating);
            if (isNaN(rating) || rating < 1 || rating > 5) return '';
            let title = rating + '/5 Stars' + (review ? ': ' + review.replace(/"/g, '&quot;') : '');
            let html = `<div class="feedback-stars mt-1" style="color: #dfba86; font-size: 0.85rem;" title="${title}">`;
            for (let i = 1; i <= 5; i++) {
                html += (i <= rating) ? '★' : '☆';
            }
            html += '</div>';
            return html;
        }

        function renderOrdersSearchResults(orders) {
            // Show/hide the results card
            const card = document.getElementById('orders-search-results-card');
            if (card) card.style.display = 'block';

            const tbody = document.getElementById('orders-search-results-body');
            if (!tbody) return;

            if (!orders || orders.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No orders found matching your criteria.</td></tr>';
                return;
            }

            tbody.innerHTML = orders.map(ord => {
                const items = (ord.items || []).map(i => `${i.item_name} ×${i.quantity}`).join(', ') || '—';
                const statusMap = {
                    pending: 'bg-warning text-dark',
                    preparing: 'bg-primary text-white',
                    ready: 'bg-info text-dark',
                    completed: 'bg-success text-dark',
                    cancelled: 'bg-danger text-white'
                };
                const badgeCls = statusMap[ord.order_status?.toLowerCase()] || 'bg-secondary text-white';
                const isOnline = !ord.delivery_address?.toLowerCase().startsWith('table ');
                const typeBadge = isOnline
                    ? '<span class="badge bg-dark border border-secondary text-white">Online</span>'
                    : '<span class="badge bg-dark border border-secondary text-white">Dine-In</span>';

                return `<tr>
                    <td>
                        <strong class="text-gold">#${ord.order_number || ord.id}</strong>
                        ${getStarRatingHtml(ord.rating, ord.review)}
                    </td>
                    <td><strong>${ord.customer_name || '—'}</strong><br><small class="text-muted">${ord.customer_phone || ''}</small></td>
                    <td><small class="text-muted">${items}</small></td>
                    <td class="text-gold">${fmtINR(ord.total_amount)}</td>
                    <td>${typeBadge}</td>
                    <td><span class="status-badge ${badgeCls}">${(ord.order_status || '').toUpperCase()}</span></td>
                    <td><small class="text-muted">${ord.order_date ? new Date(ord.order_date).toLocaleString('en-IN') : '—'}</small></td>
                </tr>`;
            }).join('');
        }

        function showSearchError(tbodyId, colSpan, msg) {
            const tbody = document.getElementById(tbodyId);
            if (tbody) tbody.innerHTML = `<tr><td colspan="${colSpan}" class="text-center text-danger py-3"><i class="fas fa-exclamation-triangle me-2"></i>${msg}</td></tr>`;
        }

        // =====================================================================
        // 2. KITCHEN SEARCH (Client-Side)
        // =====================================================================
        let _kitchenStatusFilter = 'all';

        function filterKitchenOrders() {
            const query = (document.getElementById('kitchen_search_input')?.value || '').toLowerCase();
            document.querySelectorAll('.kitchen-card').forEach(card => {
                const text = card.textContent.toLowerCase();
                const matchSearch = !query || text.includes(query);
                // Status filter is applied via CSS class toggling on columns
                card.style.display = matchSearch ? '' : 'none';
            });
        }

        function filterKitchenStatus(status) {
            _kitchenStatusFilter = status;
            // Toggle active button
            ['all', 'pending', 'preparing', 'ready'].forEach(s => {
                const btn = document.getElementById(`btn-kitchen-filter-${s}`);
                if (btn) btn.classList.toggle('active', s === status);
            });
            // Show/hide kitchen columns
            const colMap = {
                all: ['kitchen-pending-list', 'kitchen-preparing-list', 'kitchen-ready-list'],
                pending: ['kitchen-pending-list'],
                preparing: ['kitchen-preparing-list'],
                ready: ['kitchen-ready-list']
            };
            ['kitchen-pending-list', 'kitchen-preparing-list', 'kitchen-ready-list'].forEach(id => {
                const el = document.getElementById(id);
                const parent = el ? el.closest('.kitchen-col') : null;
                if (parent) parent.style.display = (status === 'all' || (colMap[status] || []).includes(id)) ? '' : 'none';
            });
        }

        // =====================================================================
        // 3. MENU SEARCH
        // =====================================================================
        function performMenuSearch(event) {
            if (event) event.preventDefault();

            const params = new URLSearchParams({
                action: 'search_menu',
                search: document.getElementById('menu_search_input')?.value || '',
                availability: document.getElementById('menu_availability_select')?.value || 'all',
                diet_type: document.getElementById('menu_diet_select')?.value || 'all',
                min_price: document.getElementById('menu_price_min')?.value || '',
                max_price: document.getElementById('menu_price_max')?.value || '',
                bestseller: document.getElementById('menu_bestseller_check')?.checked ? '1' : '0'
            });

            const card = document.getElementById('menu-search-results-card');
            const tbody = document.getElementById('menu-search-results-body');
            if (card) card.style.display = 'block';
            if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Searching menu...</td></tr>';

            fetch('dashboardtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) { showSearchError('menu-search-results-body', 7, 'Search failed.'); return; }
                renderMenuSearchResults(data.menu);
            })
            .catch(() => showSearchError('menu-search-results-body', 7, 'Network error.'));
        }

        function renderMenuSearchResults(menu) {
            const tbody = document.getElementById('menu-search-results-body');
            if (!tbody) return;

            if (!menu || menu.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No menu items found.</td></tr>';
                return;
            }

            tbody.innerHTML = menu.map(dish => {
                const vegBadge = dish.is_veg
                    ? '<span class="badge" style="background:#16a34a; color:#fff; font-size:0.7rem;">VEG</span>'
                    : '<span class="badge" style="background:#dc2626; color:#fff; font-size:0.7rem;">NON-VEG</span>';
                const bestBadge = dish.is_bestseller
                    ? '<span class="badge bg-warning text-dark ms-1" style="font-size:0.7rem;">⭐ BESTSELLER</span>'
                    : '';
                const availBadge = dish.is_available == 1
                    ? '<span class="status-badge bg-success text-dark">Available</span>'
                    : '<span class="status-badge bg-danger text-white">Unavailable</span>';

                return `<tr>
                    <td><img src="${dish.image_url || ''}" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover;"></td>
                    <td><strong>${dish.name}</strong> ${vegBadge}${bestBadge}</td>
                    <td class="text-uppercase"><small>${dish.category || '—'}</small></td>
                    <td class="text-gold">${fmtINR(dish.price)}</td>
                    <td><small class="text-muted">${(dish.description || '').substring(0, 60)}${(dish.description || '').length > 60 ? '…' : ''}</small></td>
                    <td><small class="text-muted">${dish.cust_count || 0} groups</small></td>
                    <td>${availBadge}</td>
                </tr>`;
            }).join('');
        }

        // =====================================================================
        // 4. CUSTOMERS SEARCH
        // =====================================================================
        function performCustomersSearch(event) {
            if (event) event.preventDefault();

            const params = new URLSearchParams({
                action: 'search_customers',
                search: document.getElementById('customer_search_input')?.value || ''
            });

            setTableLoading('customers-table-body', 8);

            fetch('dashboardtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) { showSearchError('customers-table-body', 8, 'Search failed.'); return; }
                renderCustomersSearchResults(data.customers);
            })
            .catch(() => showSearchError('customers-table-body', 8, 'Network error.'));
        }

        function renderCustomersSearchResults(customers) {
            const tbody = document.getElementById('customers-table-body');
            if (!tbody) return;

            if (!customers || customers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No customers found.</td></tr>';
                return;
            }

            tbody.innerHTML = customers.map(c => {
                const paid = c.payment_summary?.paid_count || 0;
                const failed = c.payment_summary?.failed_count || 0;
                const pending = c.payment_summary?.pending_count || 0;
                const lastDate = c.last_order_date ? new Date(c.last_order_date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

                return `<tr>
                    <td><strong>${c.customer_name || 'Guest'}</strong><br><small class="text-muted">ID: ${c.customer_id || 'GUEST'}</small></td>
                    <td>${c.customer_phone || '—'}</td>
                    <td><small class="text-muted">${c.email || '—'}</small></td>
                    <td>${c.order_count || 0} orders</td>
                    <td class="text-gold">${fmtINR(c.total_spent)}</td>
                    <td><small class="text-muted">${lastDate}</small></td>
                    <td><span class="badge bg-dark border border-secondary text-white">${c.favorite_dish || '—'}</span></td>
                    <td>
                        <span class="badge bg-success text-dark">Paid: ${paid}</span>
                        ${pending > 0 ? `<span class="badge bg-warning text-dark ms-1">Pending: ${pending}</span>` : ''}
                        ${failed > 0 ? `<span class="badge bg-danger text-white ms-1">Failed: ${failed}</span>` : ''}
                    </td>
                </tr>`;
            }).join('');
        }

        // =====================================================================
        // 5. PAYMENTS SEARCH
        // =====================================================================
        function performPaymentsSearch(event) {
            if (event) event.preventDefault();

            const params = new URLSearchParams({
                action: 'search_payments',
                search: document.getElementById('payment_search_input')?.value || '',
                method: document.getElementById('payment_method_select')?.value || 'all',
                status: document.getElementById('payment_status_select')?.value || 'all',
                min_amount: document.getElementById('payment_min_amount')?.value || '',
                max_amount: document.getElementById('payment_max_amount')?.value || ''
            });

            setTableLoading('payments-table-body', 6);

            fetch('dashboardtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) { showSearchError('payments-table-body', 6, 'Search failed.'); return; }
                renderPaymentsSearchResults(data.payments);
            })
            .catch(() => showSearchError('payments-table-body', 6, 'Network error.'));
        }

        function renderPaymentsSearchResults(logs) {
            const tbody = document.getElementById('payments-table-body');
            if (!tbody) return;

            if (!logs || logs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No transactions found.</td></tr>';
                return;
            }

            tbody.innerHTML = logs.map(log => {
                let method = 'ONLINE GATEWAY';
                const addr = (log.delivery_address || '').toUpperCase();
                if (addr.includes('PAID VIA CASH')) method = 'CASH';
                else if (addr.includes('PAID VIA CARD')) method = 'CARD';
                else if (addr.includes('PAID VIA UPI')) method = 'UPI';
                else if (addr.includes('PAID VIA NETBANKING') || addr.includes('PAID VIA NET BANKING')) method = 'NET BANKING';
                else if (addr.includes('PAID VIA WALLET')) method = 'WALLET';

                const isPaid = log.order_status?.toLowerCase() === 'completed';
                const statusHtml = isPaid
                    ? '<span class="status-badge bg-success text-dark">Paid</span>'
                    : '<span class="status-badge bg-warning text-dark">Pending Settlement</span>';
                const dateStr = log.order_date ? new Date(log.order_date).toLocaleString('en-IN') : '—';

                return `<tr>
                    <td>#${log.order_number || log.id}</td>
                    <td>${log.customer_name || '—'}</td>
                    <td class="text-gold">${fmtINR(log.total_amount)}</td>
                    <td><span class="badge bg-dark border border-secondary text-white">${method}</span></td>
                    <td>${statusHtml}</td>
                    <td><small class="text-muted">${dateStr}</small></td>
                </tr>`;
            }).join('');
        }

        // =====================================================================
        // 6. REPORTS / BI DASHBOARD
        // =====================================================================
        let repSalesChartInst = null;
        let repPaymentChartInst = null;
        let repCategoryChartInst = null;
        let _lastReportData = null;

        function loadReportsData(event) {
            if (event) event.preventDefault();

            const range = document.getElementById('report_range_select')?.value || 'thisweek';
            const start_date = document.getElementById('report_start_date')?.value || '';
            const end_date = document.getElementById('report_end_date')?.value || '';

            // Show loading skeleton on summary cards
            ['rep_revenue','rep_orders','rep_aov','rep_perf_score'].forEach(id => {
                const el = document.getElementById(id);
                if (el) { el.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
            });

            fetch('dashboardtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'get_reports_data', range, start_date, end_date })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) { alert('Failed to load report data. Please try again.'); return; }
                _lastReportData = data;
                renderReportSummary(data.summary);
                renderReportTrendChart(data.trend);
                renderReportPaymentChart(data.payments);
                renderReportCategoryChart(data.categories);
                renderReportDishesTable(data.dishes);
                renderReportCustomersTable(data.top_customers);
            })
            .catch(() => alert('Network error while loading reports.'));
        }

        function renderReportSummary(summary) {
            if (!summary) return;

            const rev = document.getElementById('rep_revenue');
            const revG = document.getElementById('rep_revenue_growth');
            if (rev) rev.textContent = fmtINR(summary.revenue);
            if (revG) { revG.className = parseFloat(summary.revenue_growth) >= 0 ? 'text-success' : 'text-danger'; revG.innerHTML = growthBadge(summary.revenue_growth); }

            const ord = document.getElementById('rep_orders');
            const ordG = document.getElementById('rep_orders_growth');
            if (ord) ord.textContent = summary.orders_count || 0;
            if (ordG) { ordG.className = parseFloat(summary.orders_growth) >= 0 ? 'text-success' : 'text-danger'; ordG.innerHTML = growthBadge(summary.orders_growth); }

            const aov = document.getElementById('rep_aov');
            const aovG = document.getElementById('rep_aov_growth');
            if (aov) aov.textContent = fmtINR(summary.aov);
            if (aovG) { aovG.className = parseFloat(summary.aov_growth) >= 0 ? 'text-success' : 'text-danger'; aovG.innerHTML = growthBadge(summary.aov_growth); }

            const perf = document.getElementById('rep_perf_score');
            if (perf) perf.textContent = (summary.performance_score || 0) + '/100';

            // Operations panel
            const setEl = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            setEl('rep_op_online', summary.online_orders || 0);
            setEl('rep_op_dinein', summary.dinein_orders || 0);
            setEl('rep_op_acceptance', (summary.acceptance_rate || 0) + '%');
            setEl('rep_op_completion', (summary.completion_rate || 0) + '%');
            setEl('rep_cust_total', summary.total_customers || 0);
            setEl('rep_cust_new', summary.new_customers || 0);
            setEl('rep_cust_returning', summary.returning_customers || 0);
            setEl('rep_cust_retention', (summary.retention_rate || 0) + '%');
        }

        function getChartColors() {
            const isLight = document.documentElement.classList.contains('light-mode');
            return {
                gridColor: isLight ? 'rgba(0,0,0,0.07)' : 'rgba(255,255,255,0.06)',
                tickColor: isLight ? '#475569' : '#a09f9f',
                labelColor: isLight ? '#1e293b' : '#f0ece4',
                gold: '#dfba86',
                palette: ['#dfba86','#2ec4b6','#6366f1','#f97316','#ec4899','#84cc16','#14b8a6','#f43f5e']
            };
        }

        function renderReportTrendChart(trend) {
            const canvas = document.getElementById('repSalesChart');
            if (!canvas) return;
            const colors = getChartColors();

            if (repSalesChartInst) repSalesChartInst.destroy();

            repSalesChartInst = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: trend?.labels || [],
                    datasets: [{
                        label: 'Revenue (₹)',
                        data: trend?.data || [],
                        backgroundColor: 'rgba(223,186,134,0.18)',
                        borderColor: colors.gold,
                        borderWidth: 2,
                        borderRadius: 6,
                        hoverBackgroundColor: 'rgba(223,186,134,0.38)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            grid: { color: colors.gridColor },
                            ticks: { color: colors.tickColor, callback: v => '₹' + Number(v).toLocaleString('en-IN') }
                        },
                        x: {
                            grid: { color: colors.gridColor },
                            ticks: { color: colors.tickColor, maxRotation: 45 }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: { label: ctx => ' ₹' + Number(ctx.raw).toLocaleString('en-IN', { minimumFractionDigits: 2 }) }
                        }
                    }
                }
            });
        }

        function renderReportPaymentChart(payments) {
            const canvas = document.getElementById('repPaymentChart');
            if (!canvas || !payments) return;
            const colors = getChartColors();

            const labels = Object.keys(payments).filter(k => payments[k].amount > 0);
            const data = labels.map(k => payments[k].amount);

            if (repPaymentChartInst) repPaymentChartInst.destroy();

            if (labels.length === 0) {
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                canvas.parentElement.innerHTML = `<canvas id="repPaymentChart" style="max-height:280px;max-width:280px;"></canvas><p class="text-center text-muted mt-3">No payment data for this period.</p>`;
                return;
            }

            repPaymentChartInst = new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{ data, backgroundColor: colors.palette.slice(0, labels.length), borderWidth: 2, borderColor: document.documentElement.classList.contains('light-mode') ? '#fff' : '#0a0a0a' }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: { position: 'bottom', labels: { color: colors.labelColor, padding: 12, font: { size: 11 } } },
                        tooltip: {
                            callbacks: { label: ctx => ` ${ctx.label}: ₹${Number(ctx.raw).toLocaleString('en-IN', { minimumFractionDigits: 2 })} (${((ctx.raw / data.reduce((a,b) => a+b, 0))*100).toFixed(1)}%)` }
                        }
                    }
                }
            });
        }

        function renderReportCategoryChart(categories) {
            const canvas = document.getElementById('repCategoryChart');
            if (!canvas || !categories) return;
            const colors = getChartColors();

            const labels = categories.map(c => (c.category_name || 'Other').toUpperCase());
            const data = categories.map(c => parseFloat(c.revenue || 0));

            if (repCategoryChartInst) repCategoryChartInst.destroy();

            if (labels.length === 0) {
                canvas.parentElement.innerHTML = `<canvas id="repCategoryChart" style="max-height:280px;max-width:280px;"></canvas><p class="text-center text-muted mt-3">No category data for this period.</p>`;
                return;
            }

            repCategoryChartInst = new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{ data, backgroundColor: colors.palette.slice(0, labels.length), borderWidth: 2, borderColor: document.documentElement.classList.contains('light-mode') ? '#fff' : '#0a0a0a' }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: { position: 'bottom', labels: { color: colors.labelColor, padding: 10, font: { size: 11 } } },
                        tooltip: {
                            callbacks: { label: ctx => ` ${ctx.label}: ₹${Number(ctx.raw).toLocaleString('en-IN', { minimumFractionDigits: 2 })}` }
                        }
                    }
                }
            });
        }

        function renderReportDishesTable(dishes) {
            const tbody = document.querySelector('#rep-dishes-table tbody');
            if (!tbody) return;

            if (!dishes || dishes.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">No dish data for this period.</td></tr>';
                return;
            }

            tbody.innerHTML = dishes.map((d, i) => `<tr>
                <td>
                    <span style="display:inline-block;width:20px;height:20px;border-radius:50%;background:rgba(223,186,134,0.15);color:#dfba86;font-size:0.7rem;font-weight:700;text-align:center;line-height:20px;margin-right:8px;">${i+1}</span>
                    ${d.item_name}
                </td>
                <td>${d.qty_sold || 0}</td>
                <td class="text-gold">${fmtINR(d.revenue)}</td>
            </tr>`).join('');
        }

        function renderReportCustomersTable(customers) {
            const tbody = document.querySelector('#rep-customers-table tbody');
            if (!tbody) return;

            if (!customers || customers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">No customer data for this period.</td></tr>';
                return;
            }

            tbody.innerHTML = customers.map((c, i) => `<tr>
                <td>
                    <span style="display:inline-block;width:20px;height:20px;border-radius:50%;background:rgba(223,186,134,0.15);color:#dfba86;font-size:0.7rem;font-weight:700;text-align:center;line-height:20px;margin-right:8px;">${i+1}</span>
                    <strong>${c.customer_name || 'Guest'}</strong><br><small class="text-muted">${c.customer_phone || ''}</small>
                </td>
                <td>${c.order_count || 0}</td>
                <td class="text-gold">${fmtINR(c.total_spent)}</td>
            </tr>`).join('');
        }

        // =====================================================================
        // 7. EXPORT FUNCTIONS
        // =====================================================================
        function printReport() {
            window.print();
        }

        function exportReportToPDF() {
            // Use browser's built-in print-to-PDF (print styles handle layout)
            const originalTitle = document.title;
            document.title = 'Medusa_Business_Report_' + new Date().toISOString().slice(0, 10);
            window.print();
            document.title = originalTitle;
        }

        function exportReportToExcel() {
            if (!_lastReportData) {
                alert('Please generate a report first by clicking "Update Report".');
                return;
            }

            const summary = _lastReportData.summary || {};
            const dishes = _lastReportData.dishes || [];
            const categories = _lastReportData.categories || [];
            const payments = _lastReportData.payments || {};
            const topCustomers = _lastReportData.top_customers || [];

            // Build CSV rows
            let csv = [];

            csv.push(['MEDUSA RESTAURANT - BUSINESS INTELLIGENCE REPORT']);
            csv.push(['Report Period', `${summary.start_date || ''} to ${summary.end_date || ''}`]);
            csv.push(['Generated At', summary.generated_at || new Date().toLocaleString()]);
            csv.push([]);

            // Summary section
            csv.push(['=== SUMMARY METRICS ===']);
            csv.push(['Metric', 'Value', 'Growth vs Last Period']);
            csv.push(['Total Revenue', `INR ${parseFloat(summary.revenue || 0).toFixed(2)}`, `${summary.revenue_growth || 0}%`]);
            csv.push(['Completed Orders', summary.orders_count || 0, `${summary.orders_growth || 0}%`]);
            csv.push(['Average Order Value', `INR ${parseFloat(summary.aov || 0).toFixed(2)}`, `${summary.aov_growth || 0}%`]);
            csv.push(['Total Orders', summary.total_orders || 0, '']);
            csv.push(['Online Orders', summary.online_orders || 0, '']);
            csv.push(['Dine-In Orders', summary.dinein_orders || 0, '']);
            csv.push(['Cancelled Orders', summary.cancelled_orders || 0, '']);
            csv.push(['Acceptance Rate', `${summary.acceptance_rate || 0}%`, '']);
            csv.push(['Completion Rate', `${summary.completion_rate || 0}%`, '']);
            csv.push(['Performance Score', `${summary.performance_score || 0}/100`, '']);
            csv.push([]);

            // Customer metrics
            csv.push(['=== CUSTOMER ANALYTICS ===']);
            csv.push(['Total Customers', summary.total_customers || 0]);
            csv.push(['New Customers', summary.new_customers || 0]);
            csv.push(['Returning Customers', summary.returning_customers || 0]);
            csv.push(['Retention Rate', `${summary.retention_rate || 0}%`]);
            csv.push([]);

            // Top dishes
            csv.push(['=== TOP SELLING DISHES ===']);
            csv.push(['Rank', 'Dish Name', 'Qty Sold', 'Revenue (INR)']);
            dishes.forEach((d, i) => csv.push([i + 1, d.item_name, d.qty_sold, parseFloat(d.revenue || 0).toFixed(2)]));
            csv.push([]);

            // Category performance
            csv.push(['=== CATEGORY PERFORMANCE ===']);
            csv.push(['Category', 'Units Sold', 'Revenue (INR)']);
            categories.forEach(c => csv.push([c.category_name, c.units_sold, parseFloat(c.revenue || 0).toFixed(2)]));
            csv.push([]);

            // Payment breakdown
            csv.push(['=== PAYMENT METHOD BREAKDOWN ===']);
            csv.push(['Method', 'Transactions', 'Total Amount (INR)']);
            Object.entries(payments).forEach(([method, vals]) => {
                if (vals.count > 0) csv.push([method, vals.count, parseFloat(vals.amount || 0).toFixed(2)]);
            });
            csv.push([]);

            // Top customers
            csv.push(['=== TOP PERFORMING CUSTOMERS ===']);
            csv.push(['Name', 'Phone', 'Orders', 'Total Spent (INR)']);
            topCustomers.forEach(c => csv.push([c.customer_name, c.customer_phone, c.order_count, parseFloat(c.total_spent || 0).toFixed(2)]));

            // Convert to CSV string
            const csvStr = csv.map(row => row.map(cell => {
                const s = String(cell || '').replace(/"/g, '""');
                return s.includes(',') || s.includes('\n') || s.includes('"') ? `"${s}"` : s;
            }).join(',')).join('\r\n');

            // Trigger download
            const blob = new Blob(['\uFEFF' + csvStr], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'Medusa_Report_' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        // =====================================================================
        // 8. AUTO-INITIALIZATION
        // =====================================================================
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-load reports when reports tab is activated
            const reportsLink = document.querySelector('[onclick*="reports-tab"]');
            if (reportsLink) {
                const origOnclick = reportsLink.getAttribute('onclick');
                reportsLink.setAttribute('onclick', origOnclick);
                reportsLink.addEventListener('click', function() {
                    setTimeout(() => { loadReportsData(null); }, 150);
                });
            }

            // Real-time debounced kitchen search
            const kitchenInput = document.getElementById('kitchen_search_input');
            if (kitchenInput) {
                kitchenInput.addEventListener('input', debounce(filterKitchenOrders, 200));
            }

            // Real-time debounced orders search (search-as-you-type on the text field)
            const orderInput = document.getElementById('order_search_input');
            if (orderInput) {
                orderInput.addEventListener('input', debounce(() => performOrdersSearch(null), 400));
            }

            // Real-time debounced menu search
            const menuInput = document.getElementById('menu_search_input');
            if (menuInput) {
                menuInput.addEventListener('input', debounce(() => performMenuSearch(null), 400));
            }

            // Real-time debounced customer search
            const custInput = document.getElementById('customer_search_input');
            if (custInput) {
                custInput.addEventListener('input', debounce(() => performCustomersSearch(null), 400));
            }

            // Real-time debounced payment search
            const payInput = document.getElementById('payment_search_input');
            if (payInput) {
                payInput.addEventListener('input', debounce(() => performPaymentsSearch(null), 400));
            }

            // Ensure orders-search-results-card is hidden by default
            const ordResCard = document.getElementById('orders-search-results-card');
            if (ordResCard) ordResCard.style.display = 'none';

            const menuResCard = document.getElementById('menu-search-results-card');
            if (menuResCard) menuResCard.style.display = 'none';

            // Update BI report chart colors when theme toggles
            document.addEventListener('themeChanged', () => {
                if (repSalesChartInst) { renderReportTrendChart(_lastReportData?.trend); }
                if (repPaymentChartInst && _lastReportData) { renderReportPaymentChart(_lastReportData.payments); }
                if (repCategoryChartInst && _lastReportData) { renderReportCategoryChart(_lastReportData.categories); }
            });
        });

        // Patch toggleTheme to dispatch custom event for chart theme updates
        const _origToggleTheme = toggleTheme;
        toggleTheme = function() {
            _origToggleTheme();
            document.dispatchEvent(new Event('themeChanged'));
        };

        // =====================================================================
        // END OF ADVANCED SEARCH & REPORTING CONTROLLER
        // =====================================================================

        // Global Premium Theme Alert Override
        (function() {
            window.alert = function(message, callback) {
                const existing = document.getElementById('customAlertModal');
                if (existing) existing.remove();

                const overlay = document.createElement('div');
                overlay.id = 'customAlertModal';
                overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); z-index:99999; display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity 0.22s ease-out; padding:1.5rem;';

                const isLight = document.documentElement.classList.contains('light-mode');

                const box = document.createElement('div');
                box.style.cssText = `background:${isLight ? 'rgba(255,255,255,0.96)' : 'linear-gradient(135deg, #1c1a17 0%, #0d0c0a 100%)'}; border:1px solid ${isLight ? 'rgba(223,186,134,0.35)' : 'rgba(223,186,134,0.25)'}; border-radius:20px; width:100%; max-width:400px; padding:2.2rem 2rem; box-shadow:${isLight ? '0 20px 50px rgba(0,0,0,0.08)' : '0 30px 70px rgba(0,0,0,0.8)'}; transform:scale(0.85); transition:transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); text-align:center; position:relative;`;

                let iconHtml = '';
                const msgLower = message.toLowerCase();
                if (msgLower.includes('success') || msgLower.includes('booked') || msgLower.includes('✅') || msgLower.includes('settled') || msgLower.includes('opened')) {
                    iconHtml = '<div style="width:58px; height:58px; border-radius:50%; background:rgba(46,196,182,0.1); border:2px solid #2ec4b6; display:inline-flex; align-items:center; justify-content:center; margin-bottom:1.2rem; color:#2ec4b6; font-size:1.6rem;"><i class="fas fa-check"></i></div>';
                } else if (msgLower.includes('error') || msgLower.includes('fail') || msgLower.includes('denied') || msgLower.includes('invalid') || msgLower.includes('please') || msgLower.includes('failed')) {
                    iconHtml = '<div style="width:58px; height:58px; border-radius:50%; background:rgba(239,68,68,0.08); border:2px solid #ef4444; display:inline-flex; align-items:center; justify-content:center; margin-bottom:1.2rem; color:#ef4444; font-size:1.6rem;"><i class="fas fa-exclamation-triangle"></i></div>';
                } else {
                    iconHtml = '<div style="width:58px; height:58px; border-radius:50%; background:rgba(223,186,134,0.08); border:2px solid #dfba86; display:inline-flex; align-items:center; justify-content:center; margin-bottom:1.2rem; color:#dfba86; font-size:1.6rem;"><i class="fas fa-info-circle"></i></div>';
                }

                const cleanMessage = message.replace('✅', '').replace('❌', '').trim();

                box.innerHTML = `
                    ${iconHtml}
                    <div style="font-size:0.95rem; line-height:1.6; color:${isLight ? '#1e293b' : '#f0ece4'}; margin-bottom:1.8rem; font-weight:500; font-family:'Plus Jakarta Sans', sans-serif;">
                        ${cleanMessage}
                    </div>
                    <button id="customAlertOkBtn" style="background:linear-gradient(135deg, #dfba86 0%, #c89640 100%); color:#0a0a0a; border:none; border-radius:10px; padding:0.72rem 2.8rem; font-weight:700; font-size:0.88rem; cursor:pointer; transition:all 0.2s; letter-spacing:0.4px; outline:none; font-family:'Plus Jakarta Sans', sans-serif;">OK</button>
                `;

                overlay.appendChild(box);
                document.body.appendChild(overlay);

                overlay.offsetHeight; // Reflow
                overlay.style.opacity = '1';
                box.style.transform = 'scale(1)';

                const closeAlert = () => {
                    overlay.style.opacity = '0';
                    box.style.transform = 'scale(0.85)';
                    setTimeout(() => {
                        overlay.remove();
                        if (typeof callback === 'function') {
                            callback();
                        }
                    }, 220);
                    window.removeEventListener('keydown', handleKeydown);
                };

                const handleKeydown = (e) => {
                    if (e.key === 'Enter' || e.key === 'Escape') {
                        e.preventDefault();
                        closeAlert();
                    }
                };

                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) closeAlert();
                });
                box.querySelector('#customAlertOkBtn').addEventListener('click', closeAlert);
                window.addEventListener('keydown', handleKeydown);
            };
        })();
    </script>

<script>
function toggleSidebar(){
 const sidebar=document.querySelector('.sidebar');
 const main=document.querySelector('.main-content');
 const btn=document.getElementById('sidebarToggle');
 if(window.innerWidth<=768){
  sidebar.classList.toggle('mobile-open');
  return;
 }
 sidebar.classList.toggle('collapsed');
 main.classList.toggle('expanded');
 btn.classList.toggle('closed');
 document.body.classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));
 localStorage.setItem('sidebarCollapsed',sidebar.classList.contains('collapsed'));
}
document.addEventListener('DOMContentLoaded',()=>{
 const sidebar=document.querySelector('.sidebar');
 const main=document.querySelector('.main-content');
 const btn=document.getElementById('sidebarToggle');
 if(localStorage.getItem('sidebarCollapsed')==='true'){
  sidebar?.classList.add('collapsed');
  main?.classList.add('expanded');
  btn?.classList.add('closed');
  document.body.classList.add('sidebar-collapsed');
 }
});
</script>
</body>
</html>
