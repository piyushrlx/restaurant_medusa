<?php
/**
 * ══════════════════════════════════════════════════════════════
 *  MEDUSA RESTAURANT — CUSTOMER ACCOUNT DASHBOARD
 *  Unified hub for profiles, orders, settings, rewards, and support.
 * ══════════════════════════════════════════════════════════════
 */
require_once __DIR__ . '/api/config.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_email = $_SESSION['user_email'];

// Fetch user profile info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$profile_pic = !empty($user['profile_pic']) && file_exists(__DIR__ . '/' . $user['profile_pic']) 
    ? htmlspecialchars($user['profile_pic']) 
    : '';

// Ensure user_liquor_quota table exists and fetch user's quotas
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_liquor_quota` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `food_item_id` INT NOT NULL,
            `item_name` VARCHAR(255) NOT NULL,
            `total_pegs` INT DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `user_item` (`user_id`, `food_item_id`),
            CONSTRAINT `fk_quota_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_quota_items` FOREIGN KEY (`food_item_id`) REFERENCES `food_items` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $ex) {}

$has_liquor_quota = false;
$user_liquor_quotas = [];
if ($user_id) {
    try {
        $stmt_quota = $pdo->prepare("SELECT * FROM user_liquor_quota WHERE user_id = ?");
        $stmt_quota->execute([$user_id]);
        $user_liquor_quotas = $stmt_quota->fetchAll(PDO::FETCH_ASSOC);
        if (count($user_liquor_quotas) > 0) {
            $has_liquor_quota = true;
        }
    } catch (PDOException $ex) {}
}
$phone = $user['phone'] ?? '';
$is_email_verified = $user['is_email_verified'] ?? 0;
$is_phone_verified = $user['is_phone_verified'] ?? 0;

// Fetch settings
$settings_stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
$settings_stmt->execute([$user_id]);
$settings = $settings_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'email_notifications' => 1,
    'sms_notifications' => 1,
    'promotional_offers' => 1,
    'privacy_mode' => 0,
    'language' => 'en',
    'theme' => 'dark'
];

// Fetch orders for history
try {
    $stmt = $pdo->prepare("SELECT *, tracking_token, tracking_status FROM orders WHERE user_id = ? ORDER BY order_date DESC");
    $stmt->execute([$user_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($orders as &$order) {
        $item_stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $item_stmt->execute([$order['id']]);
        $order['items'] = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch(PDOException $e) {
    $orders = [];
}

// Fetch coupons
require_once __DIR__ . '/api/CouponService.php';
$userCoupons = [];
try {
    $couponService = new CouponService($pdo);
    $couponService->expireCoupons();
    $userCoupons = $couponService->getUserCoupons($user_id);
} catch (Exception $e) {
    // Ignore
}

// Calculate spent & loyalty rewards
$total_spent = 0;
$completed_count = 0;
$active_count = 0;
$tier_spend = 0;
foreach ($orders as $o) {
    if ($o['order_status'] !== 'cancelled') {
        $tier_spend += floatval($o['total_amount']);
    }
    if ($o['order_status'] === 'completed') {
        $total_spent += floatval($o['total_amount']);
        $completed_count++;
    } elseif ($o['order_status'] !== 'cancelled') {
        $active_count++;
    }
}

// Fetch user's current loyalty tier
$tier_stmt = $pdo->prepare("
    SELECT t.id as tier_id, t.tier_name, t.discount_percent 
    FROM users u 
    LEFT JOIN customer_tiers t ON u.current_tier_id = t.id 
    WHERE u.id = ?
");
$tier_stmt->execute([$user_id]);
$tier_info = $tier_stmt->fetch(PDO::FETCH_ASSOC);
if (!$tier_info) $tier_info = [];
$user_tier_id = intval($tier_info['tier_id'] ?? 1);
$user_tier_name = $tier_info['tier_name'] ?? 'Silver';
$user_tier_discount = floatval($tier_info['discount_percent'] ?? 10.00);

// Fetch points balance and statistics
$pts_stmt = $pdo->prepare("SELECT * FROM reward_points WHERE user_id = ?");
$pts_stmt->execute([$user_id]);
$reward_points_row = $pts_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'points_earned' => 0,
    'points_redeemed' => 0,
    'points_deducted' => 0,
    'current_balance' => 0
];
$points_balance = intval($reward_points_row['current_balance']);
$loyalty_points = $points_balance; // backward compatibility

// Fetch notifications log
$notif_stmt = $pdo->prepare("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC");
$notif_stmt->execute([$user_id]);
$user_notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch support ticket requests
$support_stmt = $pdo->prepare("SELECT * FROM support_requests WHERE user_id = ? ORDER BY created_at DESC");
$support_stmt->execute([$user_id]);
$support_tickets = $support_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch feedback submissions
$fb_stmt = $pdo->prepare("SELECT * FROM feedback WHERE user_id = ? ORDER BY created_at DESC");
$fb_stmt->execute([$user_id]);
$feedbacks = $fb_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch login activity logs
$login_logs_stmt = $pdo->prepare("SELECT * FROM login_activity_logs WHERE user_id = ? ORDER BY login_time DESC LIMIT 6");
$login_logs_stmt->execute([$user_id]);
$login_logs = $login_logs_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Medusa Luxury Restaurant - Customer Account Dashboard">
    <title>My Account - Medusa Luxury</title>
    <!-- Global Theme Controller -->
    <script src="assets/js/theme-toggle.js"></script>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-dark: #F9F6F0;
            --bg-secondary: #ffffff;
            --bg-sidebar: #143628;
            --bg-header: #4A151D;
            --bg-card: #ffffff;
            --gold: #C09B5B;
            --gold-light: #d6b883;
            --gold-dark: #a17c40;
            --text-dark: #332222;
            --text-muted: #887a7a;
            --white: #ffffff;
            --gray: #a09f9f;
            --border-glass: rgba(192, 155, 91, 0.2);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        /* Dashboard Toggle Buttons */
        .dashboard-toggle-buttons {
            display: flex;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-glass);
            border-radius: 8px;
            padding: 4px;
            margin: 1.5rem 1rem;
            gap: 4px;
        }

        .btn-dashboard-toggle {
            flex: 1;
            background: transparent;
            border: none;
            color: var(--gray);
            padding: 8px 12px;
            font-size: 0.85rem;
            font-weight: 500;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-dashboard-toggle:hover {
            color: var(--white);
            background: rgba(255, 255, 255, 0.05);
        }

        .btn-dashboard-toggle.active {
            color: var(--bg-dark);
            background: var(--gold);
            font-weight: 600;
        }

        /* Tier Badges styling */
        .tier-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .tier-bronze {
            background: linear-gradient(135deg, #cd7f32, #8c521f);
            color: #ffffff;
            border: 1px solid rgba(205, 127, 50, 0.4);
        }
        .tier-silver {
            background: linear-gradient(135deg, #bdc3c7, #2c3e50);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .tier-gold {
            background: linear-gradient(135deg, #f39c12, #d35400);
            color: #ffffff;
            border: 1px solid rgba(243, 156, 18, 0.3);
        }

        /* Loyalty Status Dashboard Layout */
        .loyalty-progress-container {
            background: var(--bg-secondary);
            border: 1px solid var(--border-glass);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .loyalty-progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .loyalty-progress-bar-bg {
            height: 10px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 5px;
            overflow: hidden;
            position: relative;
            margin-bottom: 1rem;
        }
        .loyalty-progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--gold-dark), var(--gold));
            border-radius: 5px;
            width: 0%;
            transition: width 1s ease-out;
        }
        .loyalty-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .loyalty-stat-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-glass);
            border-radius: 10px;
            padding: 1.25rem;
            text-align: center;
            transition: var(--transition);
        }
        .loyalty-stat-card:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(223, 186, 134, 0.25);
        }
        .loyalty-stat-val {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--gold);
            margin-top: 0.5rem;
        }
        .loyalty-stat-label {
            font-size: 0.8rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Notifications styling */
        .notif-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .notif-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border-glass);
            border-radius: 10px;
            padding: 1.25rem;
            position: relative;
            transition: var(--transition);
        }
        .notif-item.unread {
            border-left: 3px solid var(--gold);
        }
        .notif-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .notif-item-title {
            font-weight: 600;
            color: var(--gold-light);
            font-size: 1rem;
        }
        .notif-item-date {
            font-size: 0.75rem;
            color: var(--gray);
        }
        .notif-item-msg {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.8);
            line-height: 1.4;
        }

        body {
            background-color: var(--bg-dark);
            color: var(--text-dark);
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Luxury Top Header Bar */
        .luxury-navbar {
            background-color: var(--bg-header);
            border-bottom: 1px solid var(--border-glass);
            padding: 1.2rem 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar-brand {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            color: var(--bg-dark) !important;
            font-weight: 700;
            letter-spacing: 1px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-link-custom {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link-custom:hover {
            color: var(--gold);
        }

        /* Layout Grid */
        .dashboard-wrapper {
            width: 100%;
            margin: 0;
            padding: 0;
            flex: 1;
            display: flex;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 280px 1fr;
            width: 100%;
            gap: 0;
        }

        @media (max-width: 991px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Sidebar Container */
        .sidebar-container {
            background-color: var(--bg-sidebar);
            border: none;
            border-radius: 0;
            padding: 3rem 1.5rem;
            min-height: calc(100vh - 80px); /* Adjust based on header height */
            box-shadow: 2px 0 20px rgba(0,0,0,0.05);
        }

        /* Profile Summary */
        .profile-summary {
            text-align: center;
            margin-bottom: 2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding-bottom: 1.5rem;
        }

        .avatar-uploader {
            position: relative;
            width: 100px;
            height: 100px;
            margin: 0 auto 1rem auto;
            border-radius: 50%;
            border: 2px solid var(--gold);
            padding: 3px;
            background: #000;
            overflow: hidden;
            cursor: pointer;
        }

        .avatar-uploader img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .avatar-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: rgba(223, 186, 134, 0.1);
            color: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            font-family: 'Playfair Display', serif;
            font-weight: 700;
        }

        .avatar-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: var(--transition);
            border-radius: 50%;
        }

        .avatar-uploader:hover .avatar-overlay {
            opacity: 1;
        }

        .avatar-overlay i {
            color: var(--gold);
            font-size: 1.25rem;
        }

        .profile-name {
            font-family: 'Playfair Display', serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: #ffffff;
            margin: 0 0 0.25rem 0;
        }

        .profile-email {
            font-size: 0.82rem;
            color: var(--gray);
            word-break: break-all;
        }

        /* Sidebar Nav Buttons */
        .sidebar-menu .nav-link {
            width: 100%;
            text-align: left;
            padding: 1rem 1.2rem;
            color: rgba(255, 255, 255, 0.7);
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 500;
            border: 1px solid transparent;
            margin-bottom: 0.5rem;
            transition: var(--transition);
            background: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-menu .nav-link i {
            font-size: 1rem;
            width: 18px;
            text-align: center;
        }

        .sidebar-menu .nav-link:hover {
            color: var(--gold);
            background: rgba(223, 186, 134, 0.04);
            border-color: rgba(223, 186, 134, 0.08);
        }

        .sidebar-menu .nav-link.active {
            color: var(--white);
            background: var(--bg-header);
            border-color: transparent;
            font-weight: 600;
        }

        /* Main Content Container */
        .main-content {
            background-color: transparent;
            border: none;
            border-radius: 0;
            padding: 3rem 4rem;
            box-shadow: none;
            animation: fadeIn 0.5s ease-out;
        }

        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title i {
            color: var(--gold);
            font-size: 1.5rem;
        }

        /* Inputs & Forms styling */
        .form-control-medusa {
            background: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.1);
            color: var(--text-dark) !important;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            transition: var(--transition);
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.02);
        }

        .form-control-medusa:focus {
            background: #ffffff;
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(192, 155, 91, 0.15);
            outline: none;
        }

        .form-label-medusa {
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 0.4rem;
        }

        .btn-gold-medusa {
            background-color: var(--bg-header);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.8rem;
            font-weight: 600;
            font-size: 0.95rem;
            letter-spacing: 0.5px;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-gold-medusa:hover {
            background-color: #381016;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(74, 21, 29, 0.2);
        }

        .btn-outline-medusa {
            background-color: transparent;
            border: 1px solid var(--gold);
            color: var(--gold);
            border-radius: 8px;
            padding: 0.75rem 1.8rem;
            font-weight: 600;
            font-size: 0.95rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-outline-medusa:hover {
            background-color: var(--gold);
            color: #000000;
            transform: translateY(-1px);
        }

        /* Order Cards in History */
        .order-card {
            background: rgba(0,0,0,0.3);
            border: 1px solid var(--border-glass);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.2rem;
            transition: var(--transition);
        }

        .order-card:hover {
            border-color: rgba(223, 186, 134, 0.25);
            box-shadow: 0 10px 25px rgba(0,0,0,0.4);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            padding-bottom: 0.75rem;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
            gap: 8px;
        }

        .order-number {
            font-weight: 700;
            color: var(--gold);
        }

        /* Status Badges */
        .status-badge {
            padding: 0.35rem 0.85rem;
            border-radius: 50px;
            font-size: 0.74rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .status-pending { background: rgba(255,193,7,0.1); color: #ffc107; border: 1px solid rgba(255,193,7,0.2); }
        .status-preparing { background: rgba(13,110,253,0.1); color: #0d6efd; border: 1px solid rgba(13,110,253,0.2); }
        .status-ready { background: rgba(25,135,84,0.1); color: #2ecc71; border: 1px solid rgba(25,135,84,0.2); }
        .status-completed { background: rgba(223,186,134,0.1); color: var(--gold); border: 1px solid rgba(223,186,134,0.2); }
        .status-cancelled { background: rgba(220,53,69,0.1); color: #ff6b6b; border: 1px solid rgba(220,53,69,0.2); }

        /* Loyalty Badge */
        .tier-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0.4rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 0.5rem;
        }

        .tier-bronze { background: linear-gradient(135deg, #8c5230, #c68a4c); color: #fff; }
        .tier-silver { background: linear-gradient(135deg, #7f8c8d, #bdc3c7); color: #000; }
        .tier-gold { background: linear-gradient(135deg, #8B6914, #E8D5B0); color: #000; }

        /* Security Strength meter */
        .strength-bar { display: flex; gap: 4px; margin-top: 5px; }
        .seg { flex: 1; height: 4px; border-radius: 2px; background: rgba(255,255,255,0.1); transition: var(--transition); }
        .seg.weak { background: #ff6b6b; }
        .seg.fair { background: #f39c12; }
        .seg.good { background: #2ecc71; }
        .seg.strong { background: var(--gold); }

        /* Verification badges */
        .verification-tag {
            font-size: 0.72rem;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            margin-left: 8px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .tag-verified { background: rgba(46,204,113,0.15); color: #2ecc71; border: 1px solid rgba(46,204,113,0.2); }
        .tag-pending { background: rgba(230,126,34,0.15); color: #e67e22; border: 1px solid rgba(230,126,34,0.2); cursor: pointer; }

        /* Accordion Customization */
        .accordion-item-medusa {
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid var(--border-glass);
            border-radius: 8px !important;
            margin-bottom: 0.75rem;
            overflow: hidden;
        }

        .accordion-button-medusa {
            background: transparent !important;
            color: #fff !important;
            font-weight: 600;
            padding: 1.1rem;
            border: none !important;
            box-shadow: none !important;
        }

        .accordion-button-medusa:not(.collapsed) {
            color: var(--gold) !important;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .accordion-body-medusa {
            padding: 1.2rem;
            color: var(--gray);
            line-height: 1.6;
            font-size: 0.92rem;
        }

        /* Star Rating */
        .star-rating {
            display: flex;
            gap: 8px;
            font-size: 1.8rem;
            cursor: pointer;
            margin-bottom: 1rem;
        }

        .star-rating i {
            color: rgba(255,255,255,0.2);
            transition: var(--transition);
        }

        .star-rating i.active {
            color: var(--gold);
            text-shadow: 0 0 10px rgba(223,186,134,0.3);
        }

        /* Toast notifications */
        .medusa-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--bg-secondary);
            border: 1px solid var(--gold);
            border-radius: 8px;
            padding: 1rem 1.5rem;
            color: #fff;
            box-shadow: 0 10px 30px rgba(0,0,0,0.8);
            z-index: 2000;
            display: none;
            align-items: center;
            gap: 12px;
            animation: slideInUp 0.3s ease;
        }

        /* Custom Scrollbar for modern feel */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-dark); }
        ::-webkit-scrollbar-thumb { background: rgba(223, 186, 134, 0.2); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(223, 186, 134, 0.4); }

        @keyframes slideInUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Settings Sub-Navigation */
        .settings-subnav {
            display: flex;
            gap: 2.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            flex-wrap: wrap;
            padding-bottom: 2px;
        }
        .settings-subnav .nav-link {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--gray);
            padding: 0.5rem 0;
            border: none;
            background: transparent;
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        .settings-subnav .nav-link i {
            font-size: 0.9rem;
        }
        .settings-subnav .nav-link.active {
            color: var(--bg-header);
        }
        .settings-subnav .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            width: 100%;
            height: 2px;
            background-color: var(--bg-header);
        }

        /* Settings Action Cards */
        .settings-action-card {
            background: #ffffff;
            border: 1px solid rgba(0,0,0,0.05);
            border-radius: 16px;
            padding: 1.5rem;
            height: 100%;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
        }
        .settings-action-card:hover {
            box-shadow: 0 10px 20px rgba(0,0,0,0.02);
            border-color: rgba(0,0,0,0.08);
        }
        .settings-icon-container {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(74, 21, 29, 0.05); /* very light maroon */
            color: var(--bg-header);
            font-size: 1.1rem;
            margin-bottom: 1rem;
        }
        .danger-zone-card {
            background-color: #fcf6f6;
            border: 1px solid #f8e5e5;
        }
        .danger-zone-icon {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
    </style>
    <?php if ($settings['language'] !== 'en') { ?>
    <!-- Auto Google Translate Integration -->
    <script>
        document.cookie = "googtrans=/en/<?php echo htmlspecialchars($settings['language']); ?>; path=/";
        // Also set domain cookie to be safe
        document.cookie = "googtrans=/en/<?php echo htmlspecialchars($settings['language']); ?>; domain=" + window.location.hostname + "; path=/";
    </script>
    <style>
        /* Hide the Google Translate UI frame and body top-padding */
        .VIpgJd-ZVi9od-ORHb-OEVmcd { display: none !important; }
        .goog-te-banner-frame { display: none !important; }
        body { top: 0 !important; }
        #google_translate_element { display: none !important; }
        /* Prevent translation styling glitches */
        font { background: transparent !important; color: inherit !important; box-shadow: none !important; }
    </style>
    <script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
    <script type="text/javascript">
        function googleTranslateElementInit() {
          new google.translate.TranslateElement({pageLanguage: 'en', autoDisplay: false}, 'google_translate_element');
        }
    </script>
    <?php } else { ?>
    <!-- Clear translate cookie if English is selected -->
    <script>
        document.cookie = "googtrans=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
        document.cookie = "googtrans=; expires=Thu, 01 Jan 1970 00:00:00 UTC; domain=" + window.location.hostname + "; path=/;";
    </script>
    <?php } ?>
</head>
<body>
    <div id="google_translate_element"></div>

    <!-- Luxury Top Header Bar -->
    <header class="luxury-navbar">
        <div class="d-flex justify-content-between align-items-center w-100 max-width-1200 mx-auto">
            <a href="menutest.php" class="navbar-brand">
                <img src="assets/images/versace_logo.png" alt="Medusa Logo" style="height: 32px; border-radius: 50%; border: 1px solid var(--gold); padding: 1px;">
                <span>Medusa</span>
            </a>
            <div class="d-flex align-items-center gap-4">
                <a href="menutest.html" class="nav-link-custom">
                    <i class="fa-solid fa-utensils"></i>
                    <span>Browse Menu</span>
                </a>
                <a href="api/logout.php" class="nav-link-custom text-danger">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <div class="dashboard-wrapper">
        <div class="dashboard-grid">
            
            <!-- Left Sidebar Navigation -->
            <aside class="sidebar-container">
                <div class="profile-summary">
                    <div class="avatar-uploader" onclick="document.getElementById('profile_pic_input').click()">
                        <?php if ($profile_pic): ?>
                            <img id="avatar-img" src="<?php echo $profile_pic; ?>" alt="Profile Picture">
                        <?php else: ?>
                            <div id="avatar-placeholder" class="avatar-placeholder">
                                <?php 
                                    $parts = explode(' ', $user_name);
                                    $initials = '';
                                    foreach($parts as $p) {
                                        $initials .= strtoupper(substr($p, 0, 1));
                                    }
                                    echo htmlspecialchars(substr($initials, 0, 2));
                                ?>
                            </div>
                        <?php endif; ?>
                        <div class="avatar-overlay">
                            <i class="fa-solid fa-camera"></i>
                        </div>
                    </div>
                    <input type="file" id="profile_pic_input" accept="image/*" style="display: none;" onchange="handleProfilePicUpload(event)">
                    
                    <h4 class="profile-name"><?php echo htmlspecialchars($user_name); ?></h4>
                    <div class="profile-email"><?php echo htmlspecialchars($user_email); ?></div>
                    
                    <div class="mt-2" style="color: var(--gold); font-weight: 600; font-size: 0.9rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-crown me-1"></i><?php echo htmlspecialchars($user_tier_name); ?> Member
                    </div>
                </div>
                
                <!-- Dashboard Toggle Buttons -->
                <div class="dashboard-toggle-buttons" style="display: none;">
                    <button class="btn-dashboard-toggle active" id="btn-toggle-profile" onclick="switchDashboardMode('profile')">
                        <i class="fa-solid fa-id-card"></i> Profile Hub
                    </button>
                    <button class="btn-dashboard-toggle" id="btn-toggle-settings" onclick="switchDashboardMode('settings')">
                        <i class="fa-solid fa-gears"></i> Settings Hub
                    </button>
                </div>
                
                <nav class="sidebar-menu nav flex-column nav-pills" role="tablist">
                    <!-- Profile Dashboard Group -->
                    <button class="nav-link active dashboard-pill-profile" id="pill-profile-tab" data-bs-toggle="pill" data-bs-target="#pill-profile" type="button" role="tab">
                        <i class="fa-regular fa-user"></i> Profile Overview
                    </button>
                    <button class="nav-link dashboard-pill-profile" id="pill-orders-tab" data-bs-toggle="pill" data-bs-target="#pill-orders" type="button" role="tab">
                        <i class="fa-solid fa-receipt"></i> Order History
                    </button>
                    <button class="nav-link dashboard-pill-profile" id="pill-loyalty-tab" data-bs-toggle="pill" data-bs-target="#pill-loyalty" type="button" role="tab">
                        <i class="fa-solid fa-crown"></i> My Tier & Rewards
                    </button>
                    <button class="nav-link dashboard-pill-profile" id="pill-coupons-tab" data-bs-toggle="pill" data-bs-target="#pill-coupons" type="button" role="tab">
                        <i class="fa-solid fa-gift"></i> Coupons & Rewards
                    </button>
                    <?php if ($has_liquor_quota): ?>
                    <button class="nav-link dashboard-pill-profile" id="pill-quota-tab" data-bs-toggle="pill" data-bs-target="#pill-quota" type="button" role="tab">
                        <i class="fa-solid fa-wine-bottle"></i> Liquor Quota
                    </button>
                    <?php endif; ?>
                    <button class="nav-link dashboard-pill-profile" id="pill-notifications-tab" data-bs-toggle="pill" data-bs-target="#pill-notifications" type="button" role="tab">
                        <i class="fa-solid fa-bell"></i> Notifications Log
                    </button>

                    <!-- Settings Dashboard Group -->
                    <button class="nav-link dashboard-pill-settings" id="pill-settings-tab" data-bs-toggle="pill" data-bs-target="#pill-settings" type="button" role="tab" style="display: none;">
                        <i class="fa-solid fa-sliders"></i> Account Settings
                    </button>
                    <button class="nav-link dashboard-pill-settings" id="pill-security-tab" data-bs-toggle="pill" data-bs-target="#pill-security" type="button" role="tab" style="display: none;">
                        <i class="fa-solid fa-shield-halved"></i> Security & Sessions
                    </button>
                    <button class="nav-link dashboard-pill-settings" id="pill-feedback-tab" data-bs-toggle="pill" data-bs-target="#pill-feedback" type="button" role="tab" style="display: none;">
                        <i class="fa-solid fa-star"></i> Customer Feedback
                    </button>
                    <button class="nav-link dashboard-pill-settings" id="pill-support-tab" data-bs-toggle="pill" data-bs-target="#pill-support" type="button" role="tab" style="display: none;">
                        <i class="fa-solid fa-headset"></i> Support & Help
                    </button>
                </nav>
            </aside>

            <!-- Right Main Panels -->
            <main class="main-content tab-content">
                
                <!-- ══ TAB 1: PROFILE DETAILS ══ -->
                <div class="tab-pane fade show active" id="pill-profile" role="tabpanel">
                    <!-- Welcome Header & Rewards Card -->
                    <div class="row mb-5 align-items-center">
                        <div class="col-md-6 mb-4 mb-md-0">
                            <p class="text-muted mb-1" style="font-size: 0.9rem;">Welcome back,</p>
                            <h1 class="display-4 mb-3" style="font-family: 'Playfair Display', serif; color: var(--bg-header);"><?php echo htmlspecialchars(explode(' ', $user_name)[0]); ?></h1>
                            <p class="text-muted" style="font-size: 0.85rem; max-width: 250px;">Manage your account details and enjoy exclusive experiences.</p>
                        </div>
                        <div class="col-md-6">
                            <div class="p-4 rounded-4" style="background-color: var(--bg-sidebar); color: var(--white); box-shadow: 0 10px 25px rgba(20, 54, 40, 0.2);">
                                <div class="d-flex align-items-center mb-3">
                                    <i class="fa-solid fa-crown text-gold me-2"></i>
                                    <span class="text-uppercase tracking-widest text-gold" style="font-size: 0.75rem; letter-spacing: 1px;">Medusa Rewards</span>
                                </div>
                                <h4 class="mb-1" style="font-family: 'Playfair Display', serif;"><?php echo htmlspecialchars($user_tier_name); ?> Member</h4>
                                <p class="mb-4" style="color: rgba(255,255,255,0.7); font-size: 0.85rem;">You have <?php echo number_format($loyalty_points); ?> points</p>
                                <a href="#" class="text-gold text-decoration-none" style="font-size: 0.85rem; font-weight: 500;">View Rewards &rarr;</a>
                            </div>
                        </div>
                    </div>

                    <!-- Personal Information -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h3 class="m-0" style="font-family: 'Playfair Display', serif; color: var(--text-dark); font-size: 1.4rem;">
                            <i class="fa-regular fa-user me-2"></i> Personal Information
                        </h3>
                        <button type="button" class="btn btn-link text-dark text-decoration-none p-0" onclick="document.getElementById('profile-view').style.display='none'; document.getElementById('profile-edit').style.display='block';" style="font-size: 0.85rem; font-weight: 500;">
                            Edit Profile <i class="fa-solid fa-pencil ms-1" style="font-size: 0.75rem;"></i>
                        </button>
                    </div>

                    <!-- Static View -->
                    <div id="profile-view" class="bg-white p-4 rounded-4 border mb-5" style="border-color: rgba(0,0,0,0.05) !important;">
                        <div class="row">
                            <div class="col-md-4 p-3 border-bottom border-end">
                                <label class="text-muted text-uppercase d-block mb-1" style="font-size: 0.7rem; font-weight: 600; letter-spacing: 0.5px;">Full Name</label>
                                <div class="text-dark" style="font-size: 0.95rem;"><?php echo htmlspecialchars($user_name); ?></div>
                            </div>
                            <div class="col-md-4 p-3 border-bottom border-end">
                                <label class="text-muted text-uppercase d-block mb-1" style="font-size: 0.7rem; font-weight: 600; letter-spacing: 0.5px;">Mobile Number</label>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="text-dark" style="font-size: 0.95rem;"><?php echo htmlspecialchars($phone); ?></div>
                                    <?php if ($is_phone_verified): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success" style="font-weight: 500; font-size: 0.7rem;">Verified</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4 p-3 border-bottom">
                                <label class="text-muted text-uppercase d-block mb-1" style="font-size: 0.7rem; font-weight: 600; letter-spacing: 0.5px;">Membership Tier</label>
                                <div class="text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem; font-weight: 500;">
                                    <i class="fa-solid fa-crown text-gold"></i> <?php echo htmlspecialchars($user_tier_name); ?> Member
                                </div>
                                <div class="text-muted mt-1" style="font-size: 0.75rem;">Member since May 2024</div>
                            </div>
                            
                            <div class="col-md-4 p-3 border-end">
                                <label class="text-muted text-uppercase d-block mb-1" style="font-size: 0.7rem; font-weight: 600; letter-spacing: 0.5px;">Email Address</label>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="text-dark" style="font-size: 0.95rem;"><?php echo htmlspecialchars($user_email); ?></div>
                                    <?php if ($is_email_verified): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success" style="font-weight: 500; font-size: 0.7rem;">Verified</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4 p-3 border-end">
                                <label class="text-muted text-uppercase d-block mb-1" style="font-size: 0.7rem; font-weight: 600; letter-spacing: 0.5px;">Date of Birth</label>
                                <div class="text-dark d-flex justify-content-between align-items-center" style="font-size: 0.95rem;">
                                    15 Jan 1990 <i class="fa-regular fa-calendar text-muted"></i>
                                </div>
                            </div>
                            <div class="col-md-4 p-3">
                                <label class="text-muted text-uppercase d-block mb-1" style="font-size: 0.7rem; font-weight: 600; letter-spacing: 0.5px;">Preferred Ambience</label>
                                <div class="text-dark" style="font-size: 0.95rem;">Lounge, Live Music</div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Form (Hidden initially) -->
                    <div id="profile-edit" class="bg-white p-4 rounded-4 border mb-5" style="border-color: rgba(0,0,0,0.05) !important; display: none;">
                        <form id="profileForm" onsubmit="submitProfileForm(event)">
                            <div class="mb-4">
                                <label class="form-label-medusa" for="profile_name">Full Name *</label>
                                <input type="text" id="profile_name" class="form-control form-control-medusa" value="<?php echo htmlspecialchars($user_name); ?>" required>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label-medusa" for="profile_email">
                                    Email Address 
                                    <?php if (empty($user_email)): ?>
                                        <span class="verification-tag tag-pending"><i class="fa-solid fa-circle-info"></i> Not Provided</span>
                                    <?php elseif ($is_email_verified): ?>
                                        <span class="verification-tag tag-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>
                                    <?php else: ?>
                                        <span class="verification-tag tag-pending" onclick="sendOTP('email')"><i class="fa-solid fa-triangle-exclamation"></i> Verify email</span>
                                    <?php endif; ?>
                                </label>
                                <div class="input-group">
                                    <input type="email" id="profile_email" class="form-control form-control-medusa" value="<?php echo htmlspecialchars($user_email); ?>">
                                    <button type="button" id="btn-verify-email" class="btn btn-outline-medusa" style="display: none;" onclick="sendOTP('email')">Verify</button>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label-medusa" for="profile_phone">
                                    Mobile Number
                                    <?php if ($is_phone_verified): ?>
                                        <span class="verification-tag tag-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>
                                    <?php else: ?>
                                        <span class="verification-tag tag-pending" onclick="sendOTP('phone')"><i class="fa-solid fa-triangle-exclamation"></i> Verify phone</span>
                                    <?php endif; ?>
                                </label>
                                <div class="input-group">
                                    <input type="tel" id="profile_phone" class="form-control form-control-medusa" value="<?php echo htmlspecialchars($phone); ?>" maxlength="10">
                                    <button type="button" id="btn-verify-phone" class="btn btn-outline-medusa" style="display: none;" onclick="sendOTP('phone')">Verify</button>
                                </div>
                            </div>

                            <div class="d-flex gap-3 mt-4">
                                <button type="submit" class="btn-gold-medusa">Save Changes</button>
                                <button type="button" class="btn btn-outline-dark" onclick="document.getElementById('profile-edit').style.display='none'; document.getElementById('profile-view').style.display='block';">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <!-- Statistics Summary Bar -->
                    <div class="rounded-4 p-4 mb-5" style="background-color: var(--bg-header); color: var(--white);">
                        <div class="row text-center text-md-start">
                            <div class="col-6 col-md-3 mb-3 mb-md-0 d-flex align-items-center justify-content-center justify-content-md-start gap-3 border-end border-light border-opacity-25">
                                <i class="fa-regular fa-star text-gold" style="font-size: 1.5rem;"></i>
                                <div>
                                    <h4 class="mb-0 text-white" style="font-weight: 700; font-size: 1.2rem;"><?php echo number_format($loyalty_points); ?></h4>
                                    <span style="font-size: 0.75rem; opacity: 0.8;">Total Points</span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 mb-3 mb-md-0 d-flex align-items-center justify-content-center justify-content-md-start gap-3 border-end-md border-light border-opacity-25 ps-md-4">
                                <i class="fa-regular fa-calendar text-gold" style="font-size: 1.5rem;"></i>
                                <div>
                                    <h4 class="mb-0 text-white" style="font-weight: 700; font-size: 1.2rem;">0</h4>
                                    <span style="font-size: 0.75rem; opacity: 0.8;">Reservations</span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 d-flex align-items-center justify-content-center justify-content-md-start gap-3 border-end border-light border-opacity-25 ps-md-4">
                                <i class="fa-solid fa-bag-shopping text-gold" style="font-size: 1.5rem;"></i>
                                <div>
                                    <h4 class="mb-0 text-white" style="font-weight: 700; font-size: 1.2rem;"><?php echo count($orders); ?></h4>
                                    <span style="font-size: 0.75rem; opacity: 0.8;">Orders</span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 d-flex align-items-center justify-content-center justify-content-md-start gap-3 ps-md-4">
                                <i class="fa-solid fa-gift text-gold" style="font-size: 1.5rem;"></i>
                                <div>
                                    <h4 class="mb-0 text-white" style="font-weight: 700; font-size: 1.2rem;"><?php echo count($userCoupons); ?></h4>
                                    <span style="font-size: 0.75rem; opacity: 0.8;">Coupons</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Upcoming Reservation Placeholder -->
                    <div class="mb-5">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="m-0" style="font-family: 'Playfair Display', serif; color: var(--text-dark); font-size: 1.2rem;">
                                <i class="fa-regular fa-calendar-check me-2"></i> Upcoming Reservation
                            </h4>
                            <a href="#" class="text-dark text-decoration-none" style="font-size: 0.85rem; font-weight: 500;">View All Reservations &rarr;</a>
                        </div>
                        <div class="bg-white p-4 rounded-4 border d-flex align-items-center gap-4 flex-wrap" style="border-color: rgba(0,0,0,0.05) !important;">
                            <img src="assets/images/hero_steak.png" alt="Reservation" class="rounded-3 object-cover" style="width: 150px; height: 100px;">
                            <div class="flex-grow-1">
                                <div class="d-flex gap-3 text-muted mb-2" style="font-size: 0.8rem;">
                                    <span><i class="fa-regular fa-calendar me-1"></i> Sat, 24 May 2024</span>
                                    <span><i class="fa-regular fa-clock me-1"></i> 7:00 PM</span>
                                    <span><i class="fa-regular fa-user me-1"></i> 2 Guests</span>
                                </div>
                                <h5 class="text-dark mb-1" style="font-size: 1rem;">Medusa Lounge - Downtown</h5>
                                <p class="text-muted m-0" style="font-size: 0.85rem;">123 Medusa Lane, New York, NY 10001</p>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-success bg-opacity-10 text-success mb-3 d-inline-block" style="padding: 0.5rem 1rem;">CONFIRMED</span><br>
                                <button class="btn-gold-medusa" style="padding: 0.5rem 1.5rem; font-size: 0.8rem;">VIEW DETAILS</button>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions & Account Security -->
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <h4 class="mb-3" style="font-family: 'Playfair Display', serif; color: var(--text-dark); font-size: 1.2rem;">Quick Actions</h4>
                            <div class="bg-white p-4 rounded-4 border d-flex justify-content-around text-center" style="border-color: rgba(0,0,0,0.05) !important;">
                                <a href="#" class="text-dark text-decoration-none action-icon">
                                    <div class="rounded-circle border d-flex align-items-center justify-content-center mx-auto mb-2 hover-gold-border" style="width: 50px; height: 50px;">
                                        <i class="fa-regular fa-calendar"></i>
                                    </div>
                                    <span style="font-size: 0.75rem;">Reserve</span>
                                </a>
                                <a href="menutest.html" class="text-dark text-decoration-none action-icon">
                                    <div class="rounded-circle border d-flex align-items-center justify-content-center mx-auto mb-2 hover-gold-border" style="width: 50px; height: 50px;">
                                        <i class="fa-solid fa-utensils"></i>
                                    </div>
                                    <span style="font-size: 0.75rem;">View Menu</span>
                                </a>
                                <a href="carttest.html" class="text-dark text-decoration-none action-icon">
                                    <div class="rounded-circle border d-flex align-items-center justify-content-center mx-auto mb-2 hover-gold-border" style="width: 50px; height: 50px;">
                                        <i class="fa-solid fa-bag-shopping"></i>
                                    </div>
                                    <span style="font-size: 0.75rem;">Order Now</span>
                                </a>
                                <a href="#" class="text-dark text-decoration-none action-icon">
                                    <div class="rounded-circle border d-flex align-items-center justify-content-center mx-auto mb-2 hover-gold-border" style="width: 50px; height: 50px;">
                                        <i class="fa-solid fa-gift"></i>
                                    </div>
                                    <span style="font-size: 0.75rem;">Gift Cards</span>
                                </a>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <h4 class="mb-3" style="font-family: 'Playfair Display', serif; color: var(--text-dark); font-size: 1.2rem;">
                                <i class="fa-solid fa-shield-halved me-2"></i> Account Security
                            </h4>
                            <div class="bg-white p-4 rounded-4 border" style="border-color: rgba(0,0,0,0.05) !important;">
                                <p class="text-muted mb-4" style="font-size: 0.85rem;">Keep your account safe and secure.</p>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="text-muted" style="font-size: 0.85rem; width: 100px;">Password</span>
                                    <span class="text-dark fw-bold flex-grow-1">••••••••••••</span>
                                    <a href="#" onclick="switchDashboardMode('settings'); document.getElementById('pill-security-tab').click();" class="text-success text-decoration-none" style="font-size: 0.85rem; font-weight: 500;">Change</a>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted" style="font-size: 0.85rem; width: 100px;">Last Login</span>
                                    <span class="text-dark flex-grow-1" style="font-size: 0.85rem;">
                                        <?php echo !empty($login_logs) ? date('M d, Y • h:i A', strtotime($login_logs[0]['login_time'])) : 'N/A'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══ TAB 2: ORDER HISTORY ══ -->
                <div class="tab-pane fade" id="pill-orders" role="tabpanel">
                    <h2 class="section-title"><i class="fa-solid fa-clock-rotate-left"></i> Order History</h2>
                    
                    <!-- Search & Filter Controls -->
                    <div class="row g-3 mb-4 bg-black p-3 rounded border border-secondary">
                        <div class="col-md-5">
                            <label class="form-label-medusa">Search Order</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary text-white-50"><i class="fa-solid fa-magnifying-glass"></i></span>
                                <input type="text" id="order-search" class="form-control form-control-medusa" placeholder="Enter Order Number..." oninput="filterOrders()">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-medusa">Filter by Status</label>
                            <select id="order-status-filter" class="form-select form-control-medusa" onchange="filterOrders()">
                                <option value="all">All Orders</option>
                                <option value="pending">Pending</option>
                                <option value="preparing">Preparing</option>
                                <option value="ready">Ready to Serve</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button class="btn btn-outline-medusa w-100" onclick="resetOrderFilters()"><i class="fa-solid fa-rotate"></i> Reset</button>
                        </div>
                    </div>

                    <!-- Order Cards list -->
                    <div id="orders-list-container">
                        <?php if (empty($orders)): ?>
                            <div class="text-center py-5 bg-black rounded border border-secondary border-dashed">
                                <i class="fa-solid fa-utensils text-gold mb-3" style="font-size: 3rem; opacity: 0.4;"></i>
                                <h4 class="mb-2">No Orders Yet</h4>
                                <p class="text-white-50 mb-4" style="max-width: 380px; margin: 0 auto;">You haven't placed any fine dining orders yet.</p>
                                <a href="menutest.php" class="btn-gold-medusa">Browse Our Menu</a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                    $status_class = 'status-pending';
                                    $status_label = 'Pending';
                                    $status_icon = 'fa-clock';
                                    
                                    switch (strtolower($order['order_status'])) {
                                        case 'pending':
                                            $status_class = 'status-pending';
                                            $status_label = 'Pending';
                                            $status_icon = 'fa-clock';
                                            break;
                                        case 'preparing':
                                            $status_class = 'status-preparing';
                                            $status_label = 'Preparing';
                                            $status_icon = 'fa-fire-burner';
                                            break;
                                        case 'ready':
                                            $status_class = 'status-ready';
                                            $status_label = 'Ready to Serve';
                                            $status_icon = 'fa-bell';
                                            break;
                                        case 'completed':
                                            $status_class = 'status-completed';
                                            $status_label = 'Completed';
                                            $status_icon = 'fa-circle-check';
                                            break;
                                        case 'cancelled':
                                            $status_class = 'status-cancelled';
                                            $status_label = 'Cancelled';
                                            $status_icon = 'fa-circle-xmark';
                                            break;
                                    }
                                ?>
                                <div class="order-card" data-number="<?php echo htmlspecialchars($order['order_number']); ?>" data-status="<?php echo strtolower($order['order_status']); ?>">
                                    <div class="order-header">
                                        <div>
                                            <span class="order-number">Order #<?php echo htmlspecialchars($order['order_number']); ?></span>
                                            <span class="text-white-50 ms-2" style="font-size: 0.8rem;">
                                                <i class="fa-regular fa-calendar me-1"></i><?php echo date('d M Y, h:i A', strtotime($order['order_date'])); ?>
                                            </span>
                                        </div>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <i class="fa-solid <?php echo $status_icon; ?>"></i>
                                            <?php echo $status_label; ?>
                                        </span>
                                    </div>
                                    <div class="row g-3 align-items-center">
                                        <div class="col-lg-7">
                                            <div class="text-white-50" style="font-size: 0.9rem;">
                                                <?php foreach ($order['items'] as $item): ?>
                                                    <div class="d-flex justify-content-between mb-1">
                                                        <span><span class="text-gold font-weight-bold"><?php echo $item['quantity']; ?>x</span> <?php echo htmlspecialchars($item['item_name']); ?></span>
                                                        <span>₹<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="col-lg-5 text-end">
                                            <div class="text-white-50" style="font-size: 0.8rem; text-transform: uppercase;">Grand Total</div>
                                            <div class="text-gold font-weight-bold mb-3" style="font-size: 1.4rem;">₹<?php echo number_format($order['total_amount'], 2); ?></div>
                                            
                                            <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                <?php
                                                $trk_token  = $order['tracking_token']  ?? null;
                                                $trk_status = $order['tracking_status'] ?? 'placed';
                                                $trk_active = !in_array(strtolower($order['order_status']), ['completed','cancelled']) && $trk_token;
                                                ?>
                                                <?php if ($trk_active): ?>
                                                <a href="track.php?token=<?php echo htmlspecialchars($trk_token); ?>" class="btn btn-sm btn-track-live-acc" target="_blank">
                                                    <span class="live-dot-acc"></span>
                                                    <i class="fa-solid fa-location-dot"></i> Track Order
                                                </a>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-gold-medusa" onclick="reorderItems(<?php echo $order['id']; ?>)">
                                                    <i class="fa-solid fa-arrows-rotate"></i> Reorder
                                                </button>
                                                <a href="order-details.php?order_id=<?php echo urlencode($order['order_number']); ?>" class="btn btn-sm btn-outline-medusa">
                                                    <i class="fa-solid fa-file-pdf"></i> Invoice
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ══ TAB: MY TIER & REWARDS ══ -->
                <div class="tab-pane fade" id="pill-loyalty" role="tabpanel">
                    <?php
                    $next_tier_name = '';
                    $next_tier_req = 0;
                    $remaining_spend = 0;
                    $progress_percent = 100;
                    
                    if ($user_tier_id == 1) {
                        $next_tier_name = 'Silver';
                        $next_tier_req = 25000;
                        $remaining_spend = max(0, 25000 - $tier_spend);
                        $progress_percent = min(100, round(($tier_spend / 25000) * 100));
                    } elseif ($user_tier_id == 2) {
                        $next_tier_name = 'Gold';
                        $next_tier_req = 75000;
                        $remaining_spend = max(0, 75000 - $tier_spend);
                        $progress_percent = min(100, round((($tier_spend - 25000) / (75000 - 25000)) * 100));
                    }
                    ?>
                    <h2 class="section-title"><i class="fa-solid fa-crown text-gold"></i> Loyalty Status & Rewards</h2>
                    
                    <div class="loyalty-progress-container">
                        <div class="loyalty-progress-header">
                            <div>
                                <h4 class="mb-1">Active Tier: <span class="text-gold"><?php echo htmlspecialchars($user_tier_name); ?></span></h4>
                                <p class="text-white-50 mb-0" style="font-size: 0.9rem;">
                                    Tier Benefits: <?php echo floatval($user_tier_discount); ?>% Discount & Earning Points
                                </p>
                            </div>
                            <div class="text-end">
                                <?php if ($next_tier_name): ?>
                                    <span class="badge bg-gold text-dark p-2" style="font-size: 0.8rem; background-color: var(--gold) !important;">
                                        Next Tier: <?php echo $next_tier_name; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success p-2" style="font-size: 0.8rem;">
                                        Maximum Tier Reached
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($next_tier_name): ?>
                            <div class="loyalty-progress-bar-bg">
                                <div class="loyalty-progress-bar-fill" style="width: <?php echo $progress_percent; ?>%;"></div>
                            </div>
                            <div class="d-flex justify-content-between text-white-50" style="font-size: 0.85rem;">
                                <span>₹<?php echo number_format($tier_spend, 2); ?> spent</span>
                                <span>₹<?php echo number_format($remaining_spend, 2); ?> more to unlock <?php echo $next_tier_name; ?></span>
                            </div>
                        <?php else: ?>
                            <div class="loyalty-progress-bar-bg">
                                <div class="loyalty-progress-bar-fill" style="width: 100%;"></div>
                            </div>
                            <div class="text-center text-gold" style="font-size: 0.85rem; font-weight: 500;">
                                You have achieved the ultimate Gold Tier! Enjoy maximum benefits.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <h3 class="mb-4 text-gold" style="font-family: 'Playfair Display', serif;"><i class="fa-solid fa-chart-line text-gold me-2"></i> Rewards Statistics</h3>
                    <div class="loyalty-stats-grid">
                        <div class="loyalty-stat-card">
                            <div class="loyalty-stat-label">Current Balance</div>
                            <div class="loyalty-stat-val"><?php echo number_format($points_balance); ?> pts</div>
                        </div>
                        <div class="loyalty-stat-card">
                            <div class="loyalty-stat-label">Total Points Earned</div>
                            <div class="loyalty-stat-val"><?php echo number_format($reward_points_row['points_earned']); ?> pts</div>
                        </div>
                        <div class="loyalty-stat-card">
                            <div class="loyalty-stat-label">Total Points Redeemed</div>
                            <div class="loyalty-stat-val"><?php echo number_format($reward_points_row['points_redeemed']); ?> pts</div>
                        </div>
                        <div class="loyalty-stat-card">
                            <div class="loyalty-stat-label">Lifetime Spend</div>
                            <div class="loyalty-stat-val">₹<?php echo number_format($tier_spend, 2); ?></div>
                        </div>
                    </div>

                    <!-- Points transaction history -->
                    <h3 class="mb-4 text-gold mt-5" style="font-family: 'Playfair Display', serif;"><i class="fa-solid fa-clock-rotate-left text-gold me-2"></i> Point Transactions</h3>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover border-glass" style="font-size: 0.9rem;">
                            <thead>
                                <tr class="text-gold">
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Change</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $tx_stmt = $pdo->prepare("
                                    SELECT lt.*, o.order_number 
                                    FROM loyalty_transactions lt 
                                    LEFT JOIN orders o ON lt.order_id = o.id 
                                    WHERE lt.user_id = ? 
                                    ORDER BY lt.transaction_date DESC 
                                    LIMIT 10
                                ");
                                $tx_stmt->execute([$user_id]);
                                $txs = $tx_stmt->fetchAll(PDO::FETCH_ASSOC);
                                if (empty($txs)):
                                ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-white-50">No point transactions found yet.</td>
                                    </tr>
                                <?php
                                else:
                                    foreach ($txs as $t):
                                        $change_str = '';
                                        $change_class = '';
                                        if ($t['points_earned'] > 0) {
                                            $change_str = '+' . $t['points_earned'];
                                            $change_class = 'text-success';
                                        } elseif ($t['points_redeemed'] > 0) {
                                            $change_str = '-' . $t['points_redeemed'];
                                            $change_class = 'text-danger';
                                        } elseif ($t['points_deducted'] > 0) {
                                            $change_str = '-' . $t['points_deducted'];
                                            $change_class = 'text-warning';
                                        }
                                        
                                        $type_label = ucfirst(str_replace('_', ' ', $t['transaction_type']));
                                        $ref = $t['order_number'] ? 'Order #' . $t['order_number'] : 'System Adjust';
                                ?>
                                        <tr>
                                            <td><?php echo date('d M Y, h:i A', strtotime($t['transaction_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($type_label); ?></td>
                                            <td class="<?php echo $change_class; ?> font-weight-bold"><?php echo $change_str; ?></td>
                                            <td><?php echo htmlspecialchars($ref); ?></td>
                                        </tr>
                                <?php
                                    endforeach;
                                endif;
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Loyalty Rules Section -->
                    <h3 class="mb-4 mt-5 text-gold" style="font-family: 'Playfair Display', serif;"><i class="fa-solid fa-scale-balanced text-gold me-2"></i> Loyalty Rules &amp; Resets</h3>
                    <div class="card bg-transparent border-glass mb-4">
                        <div class="card-body">
                            <h5 class="text-white mb-3"><i class="fa-solid fa-clock-rotate-left me-2"></i> Points Reset (Inactivity Penalty)</h5>
                            <p class="text-white-50 mb-4" style="font-size: 0.9rem; line-height: 1.6;">
                                To encourage regular visits, a <strong>20% deduction</strong> is applied to your reward points balance if you do not place any orders for <strong>3 consecutive months (90 days)</strong>. Don't worry, we will send you a warning email 7 days before any points are deducted!
                            </p>
                            
                            <h5 class="text-white mb-3"><i class="fa-solid fa-calendar-check me-2"></i> Annual Tier Reset</h5>
                            <p class="text-white-50 mb-0" style="font-size: 0.9rem; line-height: 1.6;">
                                On <strong>January 1st</strong> of every year, all customers who are in the Silver or Gold tiers are <strong>moved down one tier</strong> (e.g. Gold to Silver, Silver to Bronze). Make sure to enjoy your premium benefits before the end of the year!
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ══ TAB: NOTIFICATIONS LOG ══ -->
                <div class="tab-pane fade" id="pill-notifications" role="tabpanel">
                    <h2 class="section-title"><i class="fa-solid fa-bell text-gold"></i> Notifications Log</h2>
                    
                    <div class="notif-list">
                        <?php if (empty($user_notifications)): ?>
                            <div class="text-center text-white-50 py-5">
                                <i class="fa-regular fa-bell-slash fa-3x mb-3 text-gold" style="opacity: 0.5;"></i>
                                <p>No notifications found.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($user_notifications as $notif): ?>
                                <div class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                                    <div class="notif-item-header">
                                        <div class="notif-item-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div class="notif-item-date"><?php echo date('d M Y, h:i A', strtotime($notif['created_at'])); ?></div>
                                    </div>
                                    <div class="notif-item-msg"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ══ TAB 3: COUPONS & REWARDS ══ -->
                <div class="tab-pane fade" id="pill-coupons" role="tabpanel">
                    <h2 class="section-title"><i class="fa-solid fa-gift"></i> Coupons & Loyalty Rewards</h2>
                    
                    <!-- Points Summary -->
                    <div class="row g-4 mb-5">
                        <div class="col-md-6">
                            <div class="bg-black p-4 rounded border border-secondary d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="text-white-50 mb-1" style="font-size: 0.9rem; text-transform: uppercase;">Loyalty Points</h5>
                                    <h2 class="text-gold font-weight-bold m-0"><?php echo $loyalty_points; ?> <span style="font-size: 1rem; color: #fff;">Points</span></h2>
                                    <p class="text-muted m-0 mt-2" style="font-size: 0.75rem;">Earn 1 point for every ₹100 spent.</p>
                                </div>
                                <div style="font-size: 3rem; color: var(--gold); opacity: 0.4;">
                                    <i class="fa-solid fa-gem"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="bg-black p-4 rounded border border-secondary d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="text-white-50 mb-1" style="font-size: 0.9rem; text-transform: uppercase;">Total Spent</h5>
                                    <h2 class="text-white font-weight-bold m-0">₹<?php echo number_format($total_spent, 2); ?></h2>
                                    <p class="text-muted m-0 mt-2" style="font-size: 0.75rem;">Calculated from <?php echo $completed_count; ?> completed orders.</p>
                                </div>
                                <div style="font-size: 3rem; color: var(--gray); opacity: 0.4;">
                                    <i class="fa-solid fa-wallet"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Coupons List -->
                    <h4 class="mb-3 text-gold">Available Promo Coupons</h4>
                    <?php if (empty($userCoupons)): ?>
                        <div class="text-center py-4 bg-black rounded border border-secondary">
                            <i class="fa-solid fa-ticket-simple mb-2 text-gold-dark" style="font-size: 2.2rem; opacity: 0.4;"></i>
                            <p class="text-white-50 m-0">No active coupons found. Leave a 5-star review to unlock a coupon!</p>
                        </div>
                    <?php else: ?>
                        <div class="row g-4">
                            <?php foreach ($userCoupons as $coupon): ?>
                                <?php
                                    $statusBadge = '';
                                    $cardOpacity = '1';
                                    switch (strtolower($coupon->status)) {
                                        case 'active':
                                            $statusBadge = '<span class="badge bg-success text-white">Active</span>';
                                            break;
                                        case 'redeemed':
                                            $statusBadge = '<span class="badge bg-secondary text-white">Redeemed</span>';
                                            $cardOpacity = '0.6';
                                            break;
                                        case 'expired':
                                            $statusBadge = '<span class="badge bg-danger text-white">Expired</span>';
                                            $cardOpacity = '0.5';
                                            break;
                                    }
                                ?>
                                <div class="col-md-6" style="opacity: <?php echo $cardOpacity; ?>;">
                                    <div class="bg-black p-3 rounded border border-secondary d-flex flex-column gap-2" style="border-style: dashed !important;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-gold font-weight-bold" style="font-size: 1.15rem;"><?php echo intval($coupon->discount_value); ?>% DISCOUNT</span>
                                            <?php echo $statusBadge; ?>
                                        </div>
                                        <p class="text-white-50 m-0" style="font-size: 0.8rem;">Campaign: <?php echo htmlspecialchars($coupon->campaign_code); ?></p>
                                        
                                        <div class="d-flex align-items-center justify-content-between bg-dark p-2 rounded border border-secondary" style="margin-top: 0.25rem;">
                                            <code style="font-family: monospace; font-size: 0.95rem; color: var(--gold); font-weight: bold;"><?php echo htmlspecialchars($coupon->coupon_code); ?></code>
                                            <?php if ($coupon->status === 'active'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-light" onclick="copyCouponCode(this, '<?php echo htmlspecialchars($coupon->coupon_code); ?>')" style="font-size: 0.72rem; padding: 0.2rem 0.5rem;">Copy</button>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex justify-content-between text-muted" style="font-size: 0.72rem;">
                                            <span>Expires: <?php echo date('d M Y', strtotime($coupon->expires_at)); ?></span>
                                            <?php if ($coupon->redeemed_at): ?>
                                                <span>Used: <?php echo date('d M Y', strtotime($coupon->redeemed_at)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ══ TAB: LIQUOR QUOTA ══ -->
                <?php if ($has_liquor_quota): ?>
                <div class="tab-pane fade" id="pill-quota" role="tabpanel">
                    <h2 class="section-title"><i class="fa-solid fa-wine-bottle text-gold"></i> Liquor Quota Balance</h2>
                    
                    <div class="row g-4 mb-5">
                        <?php foreach ($user_liquor_quotas as $quota): 
                            $total_pegs = intval($quota['total_pegs']);
                            $bottles_left = floor($total_pegs / 8);
                            $pegs_left = $total_pegs % 8;
                        ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="bg-black p-4 rounded border border-secondary text-center h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="mb-3" style="font-size: 2.5rem; color: var(--gold);">
                                        <i class="fa-solid fa-wine-bottle"></i>
                                    </div>
                                    <h4 class="text-white font-weight-bold mb-3" style="font-family: 'Playfair Display', serif; font-size: 1.2rem;"><?php echo htmlspecialchars($quota['item_name']); ?></h4>
                                    <div class="d-flex justify-content-center gap-4 my-3">
                                        <div>
                                            <h2 class="text-gold font-weight-bold mb-0" style="font-size: 2rem;"><?php echo $bottles_left; ?></h2>
                                            <span class="text-white-50" style="font-size: 0.8rem;">bottles left</span>
                                        </div>
                                        <div style="border-left: 1px solid rgba(255,255,255,0.15); height: 40px; align-self: center;"></div>
                                        <div>
                                            <h2 class="text-gold font-weight-bold mb-0" style="font-size: 2rem;"><?php echo $pegs_left; ?></h2>
                                            <span class="text-white-50" style="font-size: 0.8rem;">pegs left</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <a href="menutest.html?category=Liquor" class="btn btn-outline-medusa btn-sm w-100">
                                        <i class="fa-solid fa-cart-plus"></i> Buy Again
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="bg-black p-4 rounded border border-secondary">
                        <h4 class="text-gold mb-3" style="font-family: 'Playfair Display', serif;"><i class="fa-solid fa-circle-info text-gold me-2"></i> How the Liquor Quota Works</h4>
                        <ul class="text-white-50 ms-3" style="font-size: 0.9rem; line-height: 1.6;">
                            <li>Select your favorite premium brands from our <strong>Liquor</strong> category in the menu.</li>
                            <li>Order a bottle. Upon payment confirmation, <strong>8 pegs</strong> will be credited to your quota balance per bottle.</li>
                            <li>You can track and manage your available bottles and pegs from this panel.</li>
                            <li>To consume a peg during your visit, please request the waiter/admin to record the consumption at the counter.</li>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ══ TAB 4: ACCOUNT SETTINGS ══ -->
                <div class="tab-pane fade" id="pill-settings" role="tabpanel">
                    <h2 class="section-title mb-1" style="border:none; padding-bottom:0;">Account Settings</h2>
                    <p class="text-muted mb-4" style="font-size: 0.9rem;">Manage your account preferences and security settings.</p>
                    
                    <div class="settings-subnav nav" role="tablist">
                        <button class="nav-link active" id="subnav-account-tab" data-bs-toggle="tab" data-bs-target="#subnav-account" type="button" role="tab"><i class="fa-regular fa-user"></i> ACCOUNT</button>
                        <button class="nav-link" id="subnav-notifications-tab" data-bs-toggle="tab" data-bs-target="#subnav-notifications" type="button" role="tab"><i class="fa-regular fa-bell"></i> NOTIFICATIONS</button>
                        <button class="nav-link" id="subnav-preferences-tab" data-bs-toggle="tab" data-bs-target="#subnav-preferences" type="button" role="tab"><i class="fa-solid fa-sliders"></i> PREFERENCES</button>
                        <button class="nav-link" id="subnav-privacy-tab" data-bs-toggle="tab" data-bs-target="#subnav-privacy" type="button" role="tab"><i class="fa-solid fa-lock"></i> PRIVACY</button>
                    </div>

                    <div class="tab-content" id="settings-tabContent">
                        <!-- ACCOUNT TAB -->
                        <div class="tab-pane fade show active" id="subnav-account" role="tabpanel">
                            <!-- Account Information -->
                        <div class="bg-white p-4 rounded-4 border mb-4" style="border-color: rgba(0,0,0,0.05) !important;">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h4 class="m-0 text-dark" style="font-size: 1.1rem; display: flex; align-items: center; gap: 10px;">
                                    <div style="background: rgba(0,0,0,0.05); padding: 8px; border-radius: 50%; display: flex;">
                                        <i class="fa-regular fa-user" style="font-size: 0.9rem;"></i>
                                    </div>
                                    Account Information
                                </h4>
                                <a href="#" onclick="switchDashboardMode('profile'); document.getElementById('btn-edit-profile').click();" class="text-dark text-decoration-none" style="font-size: 0.85rem; font-weight: 500;">
                                    Edit Profile <i class="fa-solid fa-pencil ms-1" style="font-size: 0.75rem;"></i>
                                </a>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 p-3 border-bottom border-end">
                                    <label class="text-muted d-block mb-1" style="font-size: 0.8rem;">Full Name</label>
                                    <div class="text-dark" style="font-size: 0.95rem; font-weight: 500;"><?php echo htmlspecialchars($user_name); ?></div>
                                </div>
                                <div class="col-md-6 p-3 border-bottom">
                                    <label class="text-muted d-block mb-1" style="font-size: 0.8rem;">Date of Birth</label>
                                    <div class="text-dark d-flex justify-content-between align-items-center" style="font-size: 0.95rem; font-weight: 500;">
                                        15 Jan 1990 <i class="fa-regular fa-calendar text-muted"></i>
                                    </div>
                                </div>
                                <div class="col-md-6 p-3 border-bottom border-end">
                                    <label class="text-muted d-block mb-1" style="font-size: 0.8rem;">Email Address</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="text-dark" style="font-size: 0.95rem; font-weight: 500;"><?php echo htmlspecialchars($user_email); ?></div>
                                        <?php if ($is_email_verified): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success" style="font-weight: 500; font-size: 0.7rem;">Verified <i class="fa-solid fa-check"></i></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6 p-3 border-bottom">
                                    <label class="text-muted d-block mb-1" style="font-size: 0.8rem;">Membership Tier</label>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="text-dark d-flex align-items-center gap-2" style="font-size: 0.95rem; font-weight: 500;">
                                            <i class="fa-solid fa-crown text-gold"></i> <?php echo htmlspecialchars($user_tier_name); ?> Member
                                        </div>
                                        <div class="text-muted" style="font-size: 0.75rem;">Member since May 2024</div>
                                    </div>
                                </div>
                                <div class="col-md-6 p-3 border-end">
                                    <label class="text-muted d-block mb-1" style="font-size: 0.8rem;">Mobile Number</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="text-dark" style="font-size: 0.95rem; font-weight: 500;"><?php echo htmlspecialchars($phone); ?></div>
                                        <?php if ($is_phone_verified): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success" style="font-weight: 500; font-size: 0.7rem;">Verified <i class="fa-solid fa-check"></i></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6 p-3">
                                    <label class="text-muted d-block mb-1" style="font-size: 0.8rem;">Preferred Ambience</label>
                                    <div class="text-dark" style="font-size: 0.95rem; font-weight: 500;">Lounge, Live Music, Outdoor Seating</div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Cards Grid -->
                        <div class="row g-4 mb-4">
                            <!-- Change Password -->
                            <div class="col-md-6 col-lg-3">
                                <div class="settings-action-card">
                                    <div class="settings-icon-container">
                                        <i class="fa-solid fa-lock"></i>
                                    </div>
                                    <h5 class="text-dark" style="font-size: 1rem; font-weight: 600;">Change Password</h5>
                                    <p class="text-muted flex-grow-1" style="font-size: 0.8rem; line-height: 1.5; margin-bottom: 1.5rem;">Keep your account safe with a strong password.</p>
                                    <button class="btn btn-outline-dark btn-sm text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px; border-radius: 6px; padding: 0.5rem;" onclick="document.getElementById('pill-security-tab').click();">Change Password <i class="fa-solid fa-chevron-right ms-1"></i></button>
                                </div>
                            </div>
                            <!-- Two-Factor Authentication -->
                            <div class="col-md-6 col-lg-3">
                                <div class="settings-action-card">
                                    <div class="settings-icon-container">
                                        <i class="fa-solid fa-shield-halved"></i>
                                    </div>
                                    <h5 class="text-dark" style="font-size: 1rem; font-weight: 600;">Two-Factor<br>Authentication</h5>
                                    <p class="text-muted flex-grow-1" style="font-size: 0.8rem; line-height: 1.5; margin-bottom: 1.5rem;">Add an extra layer of security to your account.</p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="badge bg-success bg-opacity-10 text-success px-2 py-1" style="font-size: 0.75rem;">Enabled <i class="fa-solid fa-check ms-1"></i></span>
                                        <i class="fa-solid fa-chevron-right text-muted" style="font-size: 0.7rem;"></i>
                                    </div>
                                </div>
                            </div>
                            <!-- Linked Accounts -->
                            <div class="col-md-6 col-lg-3">
                                <div class="settings-action-card">
                                    <div class="settings-icon-container">
                                        <i class="fa-solid fa-user-group"></i>
                                    </div>
                                    <h5 class="text-dark" style="font-size: 1rem; font-weight: 600;">Linked Accounts</h5>
                                    <p class="text-muted flex-grow-1" style="font-size: 0.8rem; line-height: 1.5; margin-bottom: 1.5rem;">Manage your connected accounts and services.</p>
                                    <button class="btn btn-outline-dark btn-sm text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px; border-radius: 6px; padding: 0.5rem;">Manage Accounts <i class="fa-solid fa-chevron-right ms-1"></i></button>
                                </div>
                            </div>
                            <!-- Login Activity -->
                            <div class="col-md-6 col-lg-3">
                                <div class="settings-action-card">
                                    <div class="settings-icon-container">
                                        <i class="fa-regular fa-clock"></i>
                                    </div>
                                    <h5 class="text-dark" style="font-size: 1rem; font-weight: 600;">Login Activity</h5>
                                    <p class="text-muted flex-grow-1" style="font-size: 0.8rem; line-height: 1.5; margin-bottom: 1.5rem;">Review your recent login activity.</p>
                                    <button class="btn btn-outline-dark btn-sm text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px; border-radius: 6px; padding: 0.5rem;" onclick="document.getElementById('pill-security-tab').click();">View Activity <i class="fa-solid fa-chevron-right ms-1"></i></button>
                                </div>
                            </div>
                        </div>

                        <!-- Danger Zone -->
                        <div class="bg-white p-4 rounded-4 border danger-zone-card d-flex align-items-center flex-wrap gap-4">
                            <div class="settings-icon-container danger-zone-icon m-0" style="width: 50px; height: 50px;">
                                <i class="fa-solid fa-trash-can"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="text-danger mb-1" style="font-size: 1.05rem; font-weight: 600;">Danger Zone</h5>
                                <p class="text-muted m-0" style="font-size: 0.8rem;">Once you delete your account, there is no going back.<br>Please be certain.</p>
                            </div>
                            <button class="btn btn-outline-danger text-uppercase fw-bold bg-white" style="font-size: 0.8rem; letter-spacing: 0.5px; padding: 0.6rem 1.2rem; border-color: rgba(220,53,69,0.3);">Delete Account Permanently</button>
                        </div>

                    </div> <!-- End of subnav-account -->

                        <!-- ══ TAB: NOTIFICATIONS ══ -->
                        <div class="tab-pane fade" id="subnav-notifications" role="tabpanel">
                            <div class="bg-white p-4 rounded-4 border mb-4" style="border-color: rgba(0,0,0,0.05) !important;">
                                <h4 class="text-dark mb-1" style="font-size: 1.1rem; font-weight: 600;">Notification Preferences</h4>
                                <p class="text-muted mb-4" style="font-size: 0.85rem;">Manage how we contact you.</p>
                                
                                <form id="notifForm" onsubmit="submitSettingsForm(event)">
                                    <div class="mb-3 form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="notif_email" <?php echo $settings['email_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label text-dark" style="font-size: 0.9rem;" for="notif_email">Email Notifications <span class="text-muted d-block" style="font-size: 0.75rem;">Order receipts, booking alerts</span></label>
                                    </div>
                                    <div class="mb-3 form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="notif_sms" <?php echo $settings['sms_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label text-dark" style="font-size: 0.9rem;" for="notif_sms">SMS / WhatsApp Alerts <span class="text-muted d-block" style="font-size: 0.75rem;">Instant delivery progress updates</span></label>
                                    </div>
                                    <div class="mb-4 form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="notif_promo" <?php echo $settings['promotional_offers'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label text-dark" style="font-size: 0.9rem;" for="notif_promo">Promotional Offers <span class="text-muted d-block" style="font-size: 0.75rem;">Discounts, special menus, chef events</span></label>
                                    </div>
                                    <button type="submit" class="btn btn-dark text-uppercase fw-bold mt-2" style="font-size: 0.8rem; letter-spacing: 0.5px; border-radius: 6px; padding: 0.6rem 1.2rem;">Save Notifications</button>
                                </form>
                            </div>
                        </div>

                        <!-- ══ TAB: PREFERENCES ══ -->
                        <div class="tab-pane fade" id="subnav-preferences" role="tabpanel">
                            <div class="bg-white p-4 rounded-4 border mb-4" style="border-color: rgba(0,0,0,0.05) !important;">
                                <h4 class="text-dark mb-3" style="font-size: 1.1rem; font-weight: 600;">System Preferences</h4>
                                <form id="prefForm" onsubmit="submitSettingsForm(event)">
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-6">
                                            <label class="text-muted d-block mb-1" style="font-size: 0.8rem;" for="pref_lang">Language Preference</label>
                                            <select id="pref_lang" class="form-select" style="background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.1);">
                                                <option value="en" <?php echo $settings['language'] === 'en' ? 'selected' : ''; ?>>English</option>
                                                <option value="hi" <?php echo $settings['language'] === 'hi' ? 'selected' : ''; ?>>Hindi</option>
                                                <option value="es" <?php echo $settings['language'] === 'es' ? 'selected' : ''; ?>>Spanish</option>
                                                <option value="fr" <?php echo $settings['language'] === 'fr' ? 'selected' : ''; ?>>French</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="text-muted d-block mb-1" style="font-size: 0.8rem;" for="pref_theme">Theme Preference</label>
                                            <select id="pref_theme" class="form-select" style="background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.1);">
                                                <option value="dark" <?php echo $settings['theme'] === 'dark' ? 'selected' : ''; ?>>Medusa Dark (Gold)</option>
                                                <option value="light" <?php echo $settings['theme'] === 'light' ? 'selected' : ''; ?>>Medusa Light</option>
                                            </select>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-dark text-uppercase fw-bold" style="font-size: 0.8rem; letter-spacing: 0.5px; border-radius: 6px; padding: 0.6rem 1.2rem;">Save Preferences</button>
                                </form>
                            </div>
                        </div>

                        <!-- ══ TAB: PRIVACY ══ -->
                        <div class="tab-pane fade" id="subnav-privacy" role="tabpanel">
                            <div class="bg-white p-4 rounded-4 border mb-4" style="border-color: rgba(0,0,0,0.05) !important;">
                                <h4 class="text-dark mb-1" style="font-size: 1.1rem; font-weight: 600;">Privacy Controls</h4>
                                <p class="text-muted mb-4" style="font-size: 0.85rem;">Manage how your data is used across the platform.</p>
                                
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" role="switch" id="privacy_analytics" checked>
                                    <label class="form-check-label text-dark" style="font-size: 0.9rem;" for="privacy_analytics">Allow Analytics Tracking <span class="text-muted d-block" style="font-size: 0.75rem;">Help us improve by sharing usage data</span></label>
                                </div>
                                <div class="form-check form-switch mb-4">
                                    <input class="form-check-input" type="checkbox" role="switch" id="privacy_marketing" checked>
                                    <label class="form-check-label text-dark" style="font-size: 0.9rem;" for="privacy_marketing">Marketing Partners <span class="text-muted d-block" style="font-size: 0.75rem;">Share data with partners for tailored offers</span></label>
                                </div>
                                <button class="btn btn-dark text-uppercase fw-bold" style="font-size: 0.8rem; letter-spacing: 0.5px; border-radius: 6px; padding: 0.6rem 1.2rem;">Save Privacy Settings</button>
                            </div>
                        </div>

                    </div> <!-- End of settings-tabContent -->
                </div> <!-- End of pill-settings -->

                <!-- ══ NEW TAB 5: SECURITY & SESSIONS ══ -->
                <div class="tab-pane fade" id="pill-security" role="tabpanel">
                    <h2 class="section-title mb-1" style="border:none; padding-bottom:0;"><i class="fa-solid fa-shield-halved"></i> Security & Sessions</h2>
                    <p class="text-muted mb-4" style="font-size: 0.9rem;">Manage your password, 2FA, and trusted devices.</p>

                    <div class="row g-4">
                        <div class="col-lg-6">
                            <!-- Change Password -->
                            <div class="bg-white p-4 rounded-4 border mb-4" style="border-color: rgba(0,0,0,0.05) !important;">
                                <h4 class="text-dark mb-3" style="font-size: 1.1rem; font-weight: 600;">Change Password</h4>
                                <form id="passwordForm" onsubmit="submitPasswordForm(event)">
                                    <div class="mb-3">
                                        <label class="text-muted d-block mb-1" style="font-size: 0.8rem;" for="cur_pass">Current Password *</label>
                                        <input type="password" id="cur_pass" class="form-control" style="background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.1);" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="text-muted d-block mb-1" style="font-size: 0.8rem;" for="new_pass">New Password *</label>
                                        <input type="password" id="new_pass" class="form-control" style="background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.1);" oninput="checkPassStrength(this.value)" required>
                                        <div class="strength-bar mt-2">
                                            <div class="seg" id="seg1"></div>
                                            <div class="seg" id="seg2"></div>
                                            <div class="seg" id="seg3"></div>
                                            <div class="seg" id="seg4"></div>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="text-muted d-block mb-1" style="font-size: 0.8rem;" for="conf_pass">Confirm New Password *</label>
                                        <input type="password" id="conf_pass" class="form-control" style="background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.1);" required>
                                    </div>
                                    <button type="submit" class="btn btn-dark text-uppercase fw-bold" style="font-size: 0.8rem; letter-spacing: 0.5px; border-radius: 6px; padding: 0.6rem 1.2rem;">Update Password</button>
                                </form>
                            </div>

                            <!-- Two Factor Authentication -->
                            <div class="bg-white p-4 rounded-4 border" style="border-color: rgba(0,0,0,0.05) !important;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h4 class="text-dark mb-1" style="font-size: 1.1rem; font-weight: 600;">Two-Factor Authentication</h4>
                                        <p class="text-muted m-0" style="font-size: 0.85rem;">Secure login with dynamic OTP codes.</p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="two_factor_toggle" <?php echo $settings['privacy_mode'] ? 'checked' : ''; ?> onchange="toggle2FA(this)">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <!-- New: Login Alerts -->
                            <div class="bg-white p-4 rounded-4 border mb-4" style="border-color: rgba(0,0,0,0.05) !important;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h4 class="text-dark mb-1" style="font-size: 1.1rem; font-weight: 600;">Login Alerts</h4>
                                        <p class="text-muted m-0" style="font-size: 0.85rem;">Get notified of unrecognized logins.</p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="login_alerts_toggle" checked>
                                    </div>
                                </div>
                            </div>

                            <!-- New: Trusted Devices -->
                            <div class="bg-white p-4 rounded-4 border mb-4" style="border-color: rgba(0,0,0,0.05) !important;">
                                <h4 class="text-dark mb-3" style="font-size: 1.1rem; font-weight: 600;">Trusted Devices</h4>
                                <ul class="list-group list-group-flush mb-3">
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0 border-0">
                                        <div>
                                            <div style="font-size: 0.95rem; font-weight: 500;">iPhone 14 Pro Max</div>
                                            <small class="text-muted">Currently active</small>
                                        </div>
                                        <span class="badge bg-success bg-opacity-10 text-success">Active</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0 border-top">
                                        <div>
                                            <div style="font-size: 0.95rem; font-weight: 500;">MacBook Pro (Chrome)</div>
                                            <small class="text-muted">Last used 2 days ago</small>
                                        </div>
                                        <button class="btn btn-sm btn-outline-danger">Revoke</button>
                                    </li>
                                </ul>
                            </div>

                            <!-- New: Account Recovery -->
                            <div class="bg-white p-4 rounded-4 border" style="border-color: rgba(0,0,0,0.05) !important;">
                                <h4 class="text-dark mb-3" style="font-size: 1.1rem; font-weight: 600;">Account Recovery</h4>
                                <p class="text-muted mb-3" style="font-size: 0.85rem;">Add a fallback email in case you lose access.</p>
                                <div class="input-group">
                                    <input type="email" class="form-control" placeholder="Recovery Email" style="background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.1);">
                                    <button class="btn btn-dark" type="button">Save</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Login Sessions -->
                    <div class="bg-white p-4 rounded-4 border mt-4" style="border-color: rgba(0,0,0,0.05) !important;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="text-dark m-0" style="font-size: 1.1rem; font-weight: 600;">Recent Login Sessions</h4>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="logoutOtherDevices()">Logout Other Devices</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" style="font-size: 0.85rem; border: 1px solid rgba(0,0,0,0.05); border-radius: 8px; overflow: hidden;">
                                <thead style="background: rgba(0,0,0,0.02);">
                                    <tr>
                                        <th class="text-muted">IP Address</th>
                                        <th class="text-muted">Device / Browser</th>
                                        <th class="text-muted">Timestamp</th>
                                        <th class="text-muted">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($login_logs)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">No logs found</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($login_logs as $log): ?>
                                            <tr>
                                                <td class="text-dark" style="font-family: monospace;"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                                <td class="text-muted" title="<?php echo htmlspecialchars($log['user_agent']); ?>">
                                                    <?php 
                                                        $ua = $log['user_agent'];
                                                        if (preg_match('/(Chrome|Safari|Firefox|Edge|MSIE|Trident|Opera)/i', $ua, $matches)) {
                                                            echo $matches[0];
                                                        } else {
                                                            echo "Browser";
                                                        }
                                                        echo (strpos(strtolower($ua), 'mobile') !== false) ? " (Mobile)" : " (Desktop)";
                                                    ?>
                                                </td>
                                                <td class="text-muted"><?php echo date('d M Y, H:i:s', strtotime($log['login_time'])); ?></td>
                                                <td><span class="badge bg-success bg-opacity-10 text-success">Success</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ══ TAB 6: CUSTOMER FEEDBACK ══ -->
                <div class="tab-pane fade" id="pill-feedback" role="tabpanel">
                    <h2 class="section-title"><i class="fa-solid fa-star"></i> Customer Feedback & Reviews</h2>
                    
                    <div class="row g-4">
                        <!-- Submit feedback form -->
                        <div class="col-md-6">
                            <h4 class="text-gold mb-3" style="font-size: 1.1rem; text-transform: uppercase;">Submit Review</h4>
                            <form id="feedbackForm" onsubmit="submitFeedbackForm(event)">
                                <div class="mb-3">
                                    <label class="form-label-medusa">Overall Experience Rating *</label>
                                    <div class="star-rating" id="feedback-stars">
                                        <i class="fa-solid fa-star" data-index="1"></i>
                                        <i class="fa-solid fa-star" data-index="2"></i>
                                        <i class="fa-solid fa-star" data-index="3"></i>
                                        <i class="fa-solid fa-star" data-index="4"></i>
                                        <i class="fa-solid fa-star" data-index="5"></i>
                                    </div>
                                    <input type="hidden" id="feedback-rating-val" value="0">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label-medusa" for="feedback_type">Feedback Category</label>
                                    <select id="feedback_type" class="form-select form-control-medusa">
                                        <option value="general">General Dining Feedback</option>
                                        <option value="suggestion">Improvement Suggestion</option>
                                        <option value="issue">Issue Report</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label-medusa" for="feedback_review">Review Comments *</label>
                                    <textarea id="feedback_review" rows="4" class="form-control form-control-medusa" placeholder="Write your dining review, feedback or complaints here..." required></textarea>
                                </div>

                                <button type="submit" class="btn-gold-medusa"><i class="fa-solid fa-paper-plane"></i> Submit Feedback</button>
                            </form>
                        </div>

                        <!-- Previous feedbacks list -->
                        <div class="col-md-6">
                            <h4 class="text-gold mb-3" style="font-size: 1.1rem; text-transform: uppercase;">Your Previous Feedback</h4>
                            <div id="feedback-history" style="max-height: 400px; overflow-y: auto;">
                                <?php if (empty($feedbacks)): ?>
                                    <div class="text-center py-4 bg-black rounded border border-secondary">
                                        <p class="text-white-50 m-0" style="font-size: 0.9rem;">You haven't submitted any feedback yet.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($feedbacks as $fb): ?>
                                        <div class="bg-black p-3 rounded border border-secondary mb-3">
                                            <div class="d-flex justify-content-between mb-2">
                                                <div>
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="fa-solid fa-star" style="font-size: 0.8rem; color: <?php echo $i <= $fb['rating'] ? 'var(--gold)' : 'rgba(255,255,255,0.15)'; ?>;"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <span class="badge bg-dark border border-secondary text-capitalize" style="font-size: 0.7rem;"><?php echo htmlspecialchars($fb['type']); ?></span>
                                            </div>
                                            <p class="m-0 text-white-50" style="font-size: 0.88rem;"><?php echo nl2br(htmlspecialchars($fb['review'])); ?></p>
                                            <div class="text-end text-muted mt-2" style="font-size: 0.72rem;"><?php echo date('d M Y, h:i A', strtotime($fb['created_at'])); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══ TAB 7: SUPPORT & FAQS ══ -->
                <div class="tab-pane fade" id="pill-support" role="tabpanel">
                    <h2 class="section-title"><i class="fa-solid fa-headset"></i> Support & Help Desk</h2>
                    
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <h4 class="text-gold mb-3" style="font-size: 1.1rem; text-transform: uppercase;">Contact Help Desk</h4>
                            <p class="text-white-50 mb-4" style="font-size: 0.92rem;">Need instant help? Feel free to call us or drop a message directly on WhatsApp.</p>
                            
                            <div class="d-flex gap-3 mb-5">
                                <a href="https://wa.me/919427272798" target="_blank" class="btn-gold-medusa" style="background-color: #25d366; color: #fff; width: 50%;">
                                    <i class="fa-brands fa-whatsapp" style="font-size: 1.2rem;"></i> WhatsApp
                                </a>
                                <a href="tel:+919427272798" class="btn-outline-medusa" style="width: 50%;">
                                    <i class="fa-solid fa-phone"></i> Call Support
                                </a>
                            </div>

                            <h4 class="text-gold mb-3" style="font-size: 1.1rem; text-transform: uppercase;">Submit Support Ticket</h4>
                            <form id="supportForm" onsubmit="submitSupportForm(event)">
                                <div class="mb-3">
                                    <label class="form-label-medusa" for="support_subject">Subject *</label>
                                    <input type="text" id="support_subject" class="form-control form-control-medusa" placeholder="Enter ticket subject..." required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label-medusa" for="support_message">Details / Message *</label>
                                    <textarea id="support_message" rows="4" class="form-control form-control-medusa" placeholder="Provide details about your query or concern..." required></textarea>
                                </div>
                                <button type="submit" class="btn-gold-medusa"><i class="fa-solid fa-circle-plus"></i> Submit Ticket</button>
                            </form>

                            <!-- Ticket History -->
                            <div class="mt-4 pt-3 border-top border-secondary">
                                <h5 class="text-white-50 mb-3" style="font-size: 0.9rem; text-transform: uppercase;">Active Tickets</h5>
                                <?php if (empty($support_tickets)): ?>
                                    <p class="text-muted" style="font-size: 0.85rem;">No registered support tickets.</p>
                                <?php else: ?>
                                    <div class="table-responsive bg-black p-2 rounded border border-secondary">
                                        <table class="table table-dark table-striped table-hover m-0" style="font-size: 0.8rem;">
                                            <thead>
                                                <tr>
                                                    <th>Subject</th>
                                                    <th>Status</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($support_tickets as $ticket): ?>
                                                    <tr>
                                                        <td class="text-white"><?php echo htmlspecialchars($ticket['subject']); ?></td>
                                                        <td>
                                                            <span class="badge <?php echo $ticket['status'] === 'open' ? 'bg-warning text-dark' : 'bg-secondary'; ?> text-uppercase" style="font-size: 0.65rem;">
                                                                <?php echo htmlspecialchars($ticket['status']); ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-white-50"><?php echo date('d M Y', strtotime($ticket['created_at'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <h4 class="text-gold mb-3" style="font-size: 1.1rem; text-transform: uppercase;">Frequently Asked Questions</h4>
                            
                            <div class="accordion" id="faqAccordion">
                                <div class="accordion-item accordion-item-medusa">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button accordion-button-medusa collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                            How do I cancel or modify a table reservation?
                                        </button>
                                    </h2>
                                    <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body accordion-body-medusa">
                                            You can cancel or modify bookings directly by contacting our concierge desk via phone or WhatsApp. Changes must be requested at least 2 hours prior to reservation time.
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item accordion-item-medusa">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button accordion-button-medusa collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                            How does the loyalty rewards program work?
                                        </button>
                                    </h2>
                                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body accordion-body-medusa">
                                            For every ₹100 spent on completed orders with Medusa, you earn 1 point automatically. Accumulating points unlocks Bronze, Silver Premium, and Gold Elite tiers, qualifying you for exclusive promotions.
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item accordion-item-medusa">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button accordion-button-medusa collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                            Can I get dynamic discount coupons?
                                        </button>
                                    </h2>
                                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body accordion-body-medusa">
                                            Yes! After placing and receiving an order, navigate to the Feedback page to rate and review your experience. Submitting feedback often generates instant coupons in your account.
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item accordion-item-medusa">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button accordion-button-medusa collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                            What are the delivery ranges and timings?
                                        </button>
                                    </h2>
                                    <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body accordion-body-medusa">
                                            We deliver culinary creations from 11:00 AM to 11:30 PM daily within a 15km radius of Chandigarh. Order values above ₹2000 qualify for free luxury delivery.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <!-- ══ MODALS ══ -->

    <!-- OTP Verification Modal -->
    <div class="modal fade" id="otpModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-secondary text-white border-gold" style="border: 1px solid var(--gold);">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-gold" id="otpModalLabel">OTP Verification</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4 text-center">
                    <p class="text-white-50 mb-4" id="otpModalDesc">Please enter the 6-digit One-Time Password sent to your new destination.</p>
                    
                    <div class="d-flex justify-content-center gap-2 mb-4">
                        <input type="text" maxlength="6" id="otp-input-field" class="form-control form-control-medusa text-center font-weight-bold" style="font-size: 1.8rem; letter-spacing: 5px; width: 200px;" placeholder="000000">
                    </div>
                    
                    <div class="text-white-50" style="font-size: 0.85rem;">
                        Didn't receive code? 
                        <button type="button" class="btn btn-link text-gold p-0" id="otp-resend-btn" onclick="resendOTP()" disabled>Resend in <span id="otp-timer">30</span>s</button>
                    </div>
                </div>
                <div class="modal-footer border-secondary justify-content-center">
                    <button type="button" class="btn btn-outline-medusa" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-gold-medusa" onclick="verifyOTPCode()">Verify Code</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Account Confirmation Modal -->
    <div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-secondary text-white border-danger" style="border: 1px solid #ff4d4d;">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-danger"><i class="fa-solid fa-triangle-exclamation"></i> Delete Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <p class="text-white-50 mb-3">To confirm deletion, please enter your password. This action will delete your profile, reservations, reviews, and reward points permanently.</p>
                    <div class="mb-3">
                        <label class="form-label-medusa" for="delete_confirm_pass">Account Password</label>
                        <input type="password" id="delete_confirm_pass" class="form-control form-control-medusa" placeholder="Confirm your password..." required>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-medusa" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDeleteAccount()">Delete Permanently</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Dynamic Toast Notification -->
    <div class="medusa-toast" id="medusaToast">
        <i class="fa-solid fa-bell text-gold" id="toast-icon"></i>
        <span id="toast-message">Message text</span>
    </div>

    <!-- Bootstrap JS (bundle contains Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Client-Side Dashboard Operations -->
    <script>
        // Alert Toast Helper
        function showToast(message, type = 'info') {
            const toast = document.getElementById('medusaToast');
            const msgSpan = document.getElementById('toast-message');
            const icon = document.getElementById('toast-icon');
            
            msgSpan.textContent = message;
            
            if (type === 'success') {
                icon.className = "fa-solid fa-circle-check text-success";
            } else if (type === 'error') {
                icon.className = "fa-solid fa-circle-xmark text-danger";
            } else {
                icon.className = "fa-solid fa-bell text-gold";
            }
            
            toast.style.display = 'flex';
            
            setTimeout(() => {
                toast.style.display = 'none';
            }, 4000);
        }

        // Live Password Strength Indicator
        function checkPassStrength(pw) {
            const segs = [
                document.getElementById('seg1'),
                document.getElementById('seg2'),
                document.getElementById('seg3'),
                document.getElementById('seg4')
            ];
            
            segs.forEach(s => s.className = 'seg');
            if (!pw) return;

            let score = 0;
            if (pw.length >= 6) score++;
            if (pw.length >= 10) score++;
            if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
            if (/[0-9]/.test(pw) && /[^A-Za-z0-9]/.test(pw)) score++;

            const levels = ['weak', 'fair', 'good', 'strong'];
            const activeLevel = levels[Math.min(score - 1, 3)];

            for(let i = 0; i < score; i++) {
                segs[i].classList.add(activeLevel);
            }
        }

        // Detect profile updates and show verify button if email/phone changed
        const originalEmail = "<?php echo htmlspecialchars($user_email); ?>";
        const originalPhone = "<?php echo htmlspecialchars($phone); ?>";

        document.getElementById('profile_email').addEventListener('input', function() {
            const btn = document.getElementById('btn-verify-email');
            const hint = document.getElementById('email-change-hint');
            if (this.value.trim() !== originalEmail) {
                btn.style.display = 'block';
                hint.style.display = 'block';
            } else {
                btn.style.display = 'none';
                hint.style.display = 'none';
            }
        });

        document.getElementById('profile_phone').addEventListener('input', function() {
            const btn = document.getElementById('btn-verify-phone');
            const hint = document.getElementById('phone-change-hint');
            if (this.value.trim() !== originalPhone) {
                btn.style.display = 'block';
                hint.style.display = 'block';
            } else {
                btn.style.display = 'none';
                hint.style.display = 'none';
            }
        });

        // Feedback stars behavior
        const stars = document.querySelectorAll('#feedback-stars i');
        const ratingValInput = document.getElementById('feedback-rating-val');
        stars.forEach(star => {
            star.addEventListener('click', function() {
                const idx = parseInt(this.getAttribute('data-index'));
                ratingValInput.value = idx;
                stars.forEach((s, sIdx) => {
                    if (sIdx < idx) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
            });
            star.addEventListener('mouseover', function() {
                const idx = parseInt(this.getAttribute('data-index'));
                stars.forEach((s, sIdx) => {
                    if (sIdx < idx) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
            });
            star.addEventListener('mouseout', function() {
                const activeVal = parseInt(ratingValInput.value);
                stars.forEach((s, sIdx) => {
                    if (sIdx < activeVal) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
            });
        });

        // OTP Timer & Operations
        let otpType = 'email'; // email or phone
        let otpCountdown = 30;
        let timerInterval;

        function startOTPTimer() {
            otpCountdown = 30;
            const timerSpan = document.getElementById('otp-timer');
            const resendBtn = document.getElementById('otp-resend-btn');
            resendBtn.disabled = true;
            timerSpan.textContent = otpCountdown;
            
            clearInterval(timerInterval);
            timerInterval = setInterval(() => {
                otpCountdown--;
                timerSpan.textContent = otpCountdown;
                if (otpCountdown <= 0) {
                    clearInterval(timerInterval);
                    resendBtn.innerHTML = "Resend OTP";
                    resendBtn.disabled = false;
                } else {
                    resendBtn.innerHTML = `Resend in <span id="otp-timer">${otpCountdown}</span>s`;
                }
            }, 1000);
        }

        async function sendOTP(type) {
            otpType = type;
            let val = '';
            let action = '';
            
            if (type === 'email') {
                val = document.getElementById('profile_email').value.trim();
                action = 'send_email_otp';
                if (!val) {
                    showToast('Please enter a valid email address first.', 'error');
                    return;
                }
            } else {
                val = document.getElementById('profile_phone').value.trim();
                action = 'send_phone_otp';
                if (!val || val.length !== 10) {
                    showToast('Please enter a valid 10-digit mobile number first.', 'error');
                    return;
                }
            }

            try {
                const response = await fetch(`api/account-api.php?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ [type]: val })
                });
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    // Show OTP modal
                    document.getElementById('otpModalDesc').textContent = `Please enter the 6-digit One-Time Password sent to your new ${type}: ${val}`;
                    document.getElementById('otp-input-field').value = '';
                    
                    const otpModal = new bootstrap.Modal(document.getElementById('otpModal'));
                    otpModal.show();
                    startOTPTimer();
                } else {
                    showToast(result.message, 'error');
                }
            } catch (e) {
                showToast('Failed to trigger verification code.', 'error');
            }
        }

        async function resendOTP() {
            let val = '';
            let action = '';
            if (otpType === 'email') {
                val = document.getElementById('profile_email').value.trim();
                action = 'send_email_otp';
            } else {
                val = document.getElementById('profile_phone').value.trim();
                action = 'send_phone_otp';
            }

            try {
                const response = await fetch(`api/account-api.php?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ [otpType]: val })
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    startOTPTimer();
                } else {
                    showToast(result.message, 'error');
                }
            } catch (e) {
                showToast('Failed to resend code.', 'error');
            }
        }

        async function verifyOTPCode() {
            const code = document.getElementById('otp-input-field').value.trim();
            if (code.length !== 6 || isNaN(code)) {
                showToast('Please enter a valid 6-digit number code.', 'error');
                return;
            }

            const action = otpType === 'email' ? 'verify_email_otp' : 'verify_phone_otp';
            try {
                const response = await fetch(`api/account-api.php?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ otp: code })
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    // Dismiss modal and reload
                    const myModalEl = document.getElementById('otpModal');
                    const modal = bootstrap.Modal.getInstance(myModalEl);
                    modal.hide();
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast(result.message, 'error');
                }
            } catch (e) {
                showToast('Verification request failed.', 'error');
            }
        }

        // Profile pic AJAX upload
        async function handleProfilePicUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            const formData = new FormData();
            formData.append('profile_pic', file);

            try {
                showToast('Uploading profile picture...', 'info');
                const response = await fetch('api/account-api.php?action=upload_profile_pic', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    // update elements
                    const img = document.getElementById('avatar-img');
                    if (img) {
                        img.src = result.path;
                    } else {
                        const placeholder = document.getElementById('avatar-placeholder');
                        const parent = placeholder.parentNode;
                        parent.innerHTML = `<img id="avatar-img" src="${result.path}" alt="Profile Picture"><div class="avatar-overlay"><i class="fa-solid fa-camera"></i></div>`;
                    }
                } else {
                    showToast(result.message, 'error');
                }
            } catch (e) {
                showToast('Error uploading avatar picture', 'error');
            }
        }

        // Submit Profile Details form
        async function submitProfileForm(e) {
            e.preventDefault();
            const name = document.getElementById('profile_name').value.trim();
            try {
                const response = await fetch('api/account-api.php?action=update_profile', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name: name })
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    // update profile displays
                    document.querySelectorAll('.profile-name').forEach(el => el.textContent = name);
                } else {
                    showToast(result.message, 'error');
                }
            } catch (err) {
                showToast('Profile save error.', 'error');
            }
        }

        // Submit preferences settings
        async function submitSettingsForm(e) {
            e.preventDefault();
            const emailNotif = document.getElementById('notif_email').checked ? 1 : 0;
            const smsNotif = document.getElementById('notif_sms').checked ? 1 : 0;
            const promo = document.getElementById('notif_promo').checked ? 1 : 0;
            const lang = document.getElementById('pref_lang').value;
            const theme = document.getElementById('pref_theme').value;
            const privacy = document.getElementById('two_factor_toggle').checked ? 1 : 0; // mapping 2FA to privacy_mode

            try {
                const response = await fetch('api/account-api.php?action=update_settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email_notifications: emailNotif,
                        sms_notifications: smsNotif,
                        promotional_offers: promo,
                        language: lang,
                        theme: theme,
                        privacy_mode: privacy
                    })
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    
                    // Update localStorage for the theme so the script picks it up on reload
                    localStorage.setItem('medusa_admin_theme', theme);
                    
                    // Reload to the exact same tab
                    setTimeout(() => {
                        window.location.href = window.location.pathname + '?tab=settings&sub=preferences';
                    }, 1500);
                } else {
                    showToast(result.message, 'error');
                }
            } catch(e) {
                showToast('Failed to save settings.', 'error');
            }
        }

        // Toggle 2FA switch from Security Tab
        function toggle2FA(switcher) {
            // Automatically submit setting form values
            const emailNotif = document.getElementById('notif_email').checked ? 1 : 0;
            const smsNotif = document.getElementById('notif_sms').checked ? 1 : 0;
            const promo = document.getElementById('notif_promo').checked ? 1 : 0;
            const lang = document.getElementById('pref_lang').value;
            const theme = document.getElementById('pref_theme').value;
            const privacy = switcher.checked ? 1 : 0;

            fetch('api/account-api.php?action=update_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email_notifications: emailNotif,
                    sms_notifications: smsNotif,
                    promotional_offers: promo,
                    language: lang,
                    theme: theme,
                    privacy_mode: privacy
                })
            }).then(res => res.json()).then(result => {
                if (result.success) {
                    showToast(privacy ? 'Two-Factor Authentication Enabled' : 'Two-Factor Authentication Disabled', 'success');
                } else {
                    showToast(result.message, 'error');
                    switcher.checked = !switcher.checked; // revert
                }
            }).catch(() => {
                showToast('Failed to update 2FA state.', 'error');
                switcher.checked = !switcher.checked;
            });
        }

        // Submit password change
        async function submitPasswordForm(e) {
            e.preventDefault();
            const currentPw = document.getElementById('cur_pass').value;
            const newPw = document.getElementById('new_pass').value;
            const confPw = document.getElementById('conf_pass').value;

            if (newPw.length < 6) {
                showToast('New password must be at least 6 characters long.', 'error');
                return;
            }
            if (newPw !== confPw) {
                showToast('Passwords do not match.', 'error');
                return;
            }

            try {
                const response = await fetch('api/account-api.php?action=change_password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        current_password: currentPw,
                        new_password: newPw,
                        confirm_password: confPw
                    })
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    document.getElementById('passwordForm').reset();
                    checkPassStrength(''); // reset meter
                } else {
                    showToast(result.message, 'error');
                }
            } catch(e) {
                showToast('Failed to update password.', 'error');
            }
        }

        // Submit customer support ticket
        async function submitSupportForm(e) {
            e.preventDefault();
            const subject = document.getElementById('support_subject').value.trim();
            const message = document.getElementById('support_message').value.trim();

            try {
                const response = await fetch('api/account-api.php?action=submit_support', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ subject, message })
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    document.getElementById('supportForm').reset();
                    // refresh after delay or alert
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast(result.message, 'error');
                }
            } catch(e) {
                showToast('Failed to submit support request.', 'error');
            }
        }

        // Submit general feedback
        async function submitFeedbackForm(e) {
            e.preventDefault();
            const rating = parseInt(document.getElementById('feedback-rating-val').value);
            const review = document.getElementById('feedback_review').value.trim();
            const type = document.getElementById('feedback_type').value;

            if (rating < 1) {
                showToast('Please select a star rating first.', 'error');
                return;
            }

            try {
                const response = await fetch('api/account-api.php?action=submit_feedback', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ rating, review, type })
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    document.getElementById('feedbackForm').reset();
                    stars.forEach(s => s.classList.remove('active'));
                    document.getElementById('feedback-rating-val').value = '0';
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast(result.message, 'error');
                }
            } catch(e) {
                showToast('Failed to submit review feedback.', 'error');
            }
        }

        // Reorder Items handler
        async function reorderItems(orderId) {
            try {
                showToast('Adding order items to cart...', 'info');
                const response = await fetch('api/account-api.php?action=reorder', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order_id: orderId })
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    // Redirect to cart page after short delay so they can checkout
                    setTimeout(() => { window.location.href = 'menutest.html'; }, 1500);
                } else {
                    showToast(result.message, 'error');
                }
            } catch(e) {
                showToast('Failed to reorder items.', 'error');
            }
        }

        // Logout all other devices
        async function logoutOtherDevices() {
            if (!confirm('Are you sure you want to invalidate all other active sessions? You will remain logged in on this device.')) return;
            try {
                const response = await fetch('api/account-api.php?action=logout_all_devices', { method: 'POST' });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch(e) {
                showToast('Device logout request failed.', 'error');
            }
        }

        // Permanent Delete modal triggers
        function showDeleteAccountModal() {
            document.getElementById('delete_confirm_pass').value = '';
            const modal = new bootstrap.Modal(document.getElementById('deleteAccountModal'));
            modal.show();
        }

        async function confirmDeleteAccount() {
            const pw = document.getElementById('delete_confirm_pass').value;
            if (!pw) {
                showToast('Password is required to proceed.', 'error');
                return;
            }

            try {
                const response = await fetch('api/account-api.php?action=delete_account', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ password: pw })
                });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    const myModalEl = document.getElementById('deleteAccountModal');
                    const modal = bootstrap.Modal.getInstance(myModalEl);
                    modal.hide();
                    setTimeout(() => { window.location.href = 'indextest.html'; }, 2000);
                } else {
                    showToast(result.message, 'error');
                }
            } catch(e) {
                showToast('Failed to process account deletion.', 'error');
            }
        }

        // Copy coupon codes
        function copyCouponCode(btn, code) {
            navigator.clipboard.writeText(code).then(() => {
                const orig = btn.textContent;
                btn.textContent = 'Copied!';
                showToast(`Coupon code ${code} copied to clipboard!`, 'success');
                setTimeout(() => btn.textContent = orig, 2000);
            }).catch(() => {
                showToast(`Coupon code is: ${code}`);
            });
        }

        // Search & Filter Orders
        function filterOrders() {
            const query = document.getElementById('order-search').value.toLowerCase().trim();
            const status = document.getElementById('order-status-filter').value.toLowerCase();
            const cards = document.querySelectorAll('#orders-list-container .order-card');
            
            cards.forEach(card => {
                const num = card.getAttribute('data-number').toLowerCase();
                const cardStatus = card.getAttribute('data-status');
                
                const matchesSearch = num.includes(query);
                const matchesStatus = (status === 'all' || cardStatus === status);
                
                if (matchesSearch && matchesStatus) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function resetOrderFilters() {
            document.getElementById('order-search').value = '';
            document.getElementById('order-status-filter').value = 'all';
            filterOrders();
        }

        function switchDashboardMode(mode) {
            document.querySelectorAll('.btn-dashboard-toggle').forEach(btn => btn.classList.remove('active'));
            if (mode === 'profile') {
                const profileBtn = document.getElementById('btn-toggle-profile');
                if (profileBtn) profileBtn.classList.add('active');
                document.querySelectorAll('.dashboard-pill-profile').forEach(p => p.style.display = 'block');
                document.querySelectorAll('.dashboard-pill-settings').forEach(p => p.style.display = 'none');
                const activePill = document.querySelector('.sidebar-menu .nav-link.active');
                if (!activePill || !activePill.classList.contains('dashboard-pill-profile')) {
                    const profileTab = document.getElementById('pill-profile-tab');
                    if (profileTab) profileTab.click();
                }
            } else {
                const settingsBtn = document.getElementById('btn-toggle-settings');
                if (settingsBtn) settingsBtn.classList.add('active');
                document.querySelectorAll('.dashboard-pill-profile').forEach(p => p.style.display = 'none');
                document.querySelectorAll('.dashboard-pill-settings').forEach(p => p.style.display = 'block');
                const activePill = document.querySelector('.sidebar-menu .nav-link.active');
                if (!activePill || !activePill.classList.contains('dashboard-pill-settings')) {
                    const settingsTab = document.getElementById('pill-settings-tab');
                    if (settingsTab) settingsTab.click();
                }
            }
        }

        async function consumePeg() {
            const btn = document.getElementById('btn-consume-peg');
            if (!btn) return;
            const origText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
            try {
                const response = await fetch('api/consume-quota.php', { method: 'POST' });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    document.getElementById('quota-display-pegs').innerHTML = `${result.new_quota} <span style="font-size: 1.5rem; color: #fff;">pegs</span>`;
                } else {
                    showToast(result.message, 'error');
                }
            } catch(e) {
                showToast('Failed to consume peg quota.', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            const tab = params.get('tab');
            if (tab === 'settings') {
                switchDashboardMode('settings');
            } else {
                switchDashboardMode('profile');
            }
        });
    </script>

<style>
/* ── Track Order Button ── */
.btn-track-live-acc {
    background: transparent;
    border: 1px solid rgba(201,168,76,0.4);
    color: #c9a84c;
    font-size: 0.78rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 6px;
    padding: 0.3rem 0.8rem;
    text-decoration: none;
    transition: all 0.2s;
}
.btn-track-live-acc:hover {
    background: rgba(201,168,76,0.1);
    border-color: #c9a84c;
    color: #d4b05a;
    transform: translateY(-1px);
}
.live-dot-acc {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: #c9a84c;
    animation: liveAcc 1.6s ease-in-out infinite;
    flex-shrink: 0;
}
@keyframes liveAcc { 0%,100%{opacity:1} 50%{opacity:0.3} }
</style>

<?php require_once __DIR__ . '/includes/active_order_bar.php'; ?>
<?php require_once __DIR__ . '/includes/order_toast.php'; ?>
</body>
</html>
