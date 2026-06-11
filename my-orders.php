<?php
require_once __DIR__ . '/api/config.php';
requireLogin();

// Fetch orders to render on server side (initial load)
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

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

// Fetch user coupons
require_once __DIR__ . '/api/CouponService.php';
$userCoupons = [];
try {
    $couponService = new CouponService($pdo);
    $couponService->expireCoupons();
    $userCoupons = $couponService->getUserCoupons($user_id);
} catch (Exception $e) {
    // Ignore error
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - Medusa Luxury</title>
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
            --bg-dark: #0D2016;
            --bg-secondary: #132F20;
            --bg-card: rgba(19, 47, 32, 0.65);
            --gold: #dfba86;
            --gold-light: #f3dfc1;
            --white: #FAF7F0;
            --gray: #A8A196;
            --rosewood: #5A1827;
            --rosewood-light: #7E2638;
            --border-glass: rgba(223, 186, 134, 0.12);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        body {
            background-color: var(--bg-dark);
            color: var(--white);
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navbar Styling */
        .luxury-navbar {
            background-color: var(--bg-secondary);
            border-bottom: 1px solid var(--border-glass);
            padding: 1.2rem 2rem;
            backdrop-filter: blur(10px);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar-brand {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            color: var(--gold) !important;
            font-weight: 700;
            letter-spacing: 1px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-link-custom {
            color: var(--gray);
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

        /* Container & Header */
        .dashboard-container {
            padding: 3rem 1.5rem;
            flex: 1;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }

        .welcome-section {
            margin-bottom: 3rem;
            animation: fadeInDown 0.6s ease-out;
        }

        .welcome-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.5rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.5rem;
        }

        .welcome-subtitle {
            color: var(--gray);
            font-size: 1rem;
        }

        /* Order Summary Status Cards */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
            animation: fadeIn 0.8s ease-out;
        }

        .status-card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-glass);
            border-radius: 12px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: var(--transition);
        }

        .status-card:hover {
            transform: translateY(-3px);
            border-color: rgba(223, 186, 134, 0.3);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.4);
        }

        .status-info h3 {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            color: var(--gold);
        }

        .status-info p {
            color: var(--gray);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
            margin-top: 0.25rem;
        }

        .status-icon {
            font-size: 1.8rem;
            color: var(--gray);
            opacity: 0.5;
        }

        /* Orders Section */
        .orders-section-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding-bottom: 0.5rem;
        }

        /* Order List Item Row */
        .order-card {
            background: var(--bg-card);
            border: 1px solid var(--border-glass);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            backdrop-filter: blur(15px);
            transition: var(--transition);
            animation: fadeInUp 0.5s ease-out;
        }

        .order-card:hover {
            border-color: rgba(223, 186, 134, 0.25);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5);
            transform: translateY(-2px);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding-bottom: 1rem;
        }

        .order-meta h4 {
            font-size: 1.15rem;
            font-weight: 600;
            color: #ffffff;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .order-number {
            color: var(--gold);
        }

        .order-date {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.4rem 1rem;
            border-radius: 50px;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-pending {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.25);
        }

        .status-preparing {
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
            border: 1px solid rgba(13, 110, 253, 0.25);
        }

        .status-ready {
            background-color: rgba(25, 135, 84, 0.1);
            color: #198754;
            border: 1px solid rgba(25, 135, 84, 0.25);
        }

        .status-completed {
            background-color: rgba(223, 186, 134, 0.1);
            color: var(--gold);
            border: 1px solid rgba(223, 186, 134, 0.25);
        }

        .status-cancelled {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.25);
        }

        /* Order Details */
        .order-body {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .order-items-summary {
            flex: 1;
            min-width: 250px;
        }

        .item-row {
            font-size: 0.92rem;
            color: var(--white);
            margin-bottom: 0.4rem;
            display: flex;
            justify-content: space-between;
        }

        .item-qty {
            color: var(--gold);
            font-weight: 600;
            margin-right: 8px;
        }

        .order-financials {
            text-align: right;
            min-width: 150px;
        }

        .total-label {
            font-size: 0.8rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .total-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gold);
            margin-top: 0.15rem;
        }

        .btn-view-invoice {
            background-color: var(--rosewood);
            border: 1px solid var(--rosewood);
            color: var(--gold);
            border-radius: 8px;
            padding: 0.6rem 1.2rem;
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 0.8rem;
        }

        .btn-view-invoice:hover {
            background-color: var(--rosewood-light);
            border-color: var(--rosewood-light);
            color: var(--gold-light);
            transform: translateY(-1px);
        }

        /* Empty State */
        .empty-orders {
            text-align: center;
            padding: 5rem 2rem;
            background: var(--bg-card);
            border: 1px dashed var(--border-glass);
            border-radius: 16px;
            animation: fadeIn 1s;
        }

        .empty-icon {
            font-size: 4rem;
            color: var(--gold);
            opacity: 0.4;
            margin-bottom: 1.5rem;
        }

        .empty-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            margin-bottom: 0.8rem;
        }

        .empty-text {
            color: var(--gray);
            max-width: 400px;
            margin: 0 auto 2rem auto;
            font-size: 0.95rem;
        }

        .btn-order-now {
            background-color: var(--rosewood);
            color: var(--gold);
            border: 1px solid var(--rosewood);
            border-radius: 8px;
            padding: 0.8rem 2rem;
            font-weight: 700;
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-order-now:hover {
            background-color: var(--rosewood-light);
            border-color: var(--rosewood-light);
            color: var(--gold-light);
            transform: translateY(-2px);
        }

        /* Animations */
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* ── Mini Tracker ── */
        .mini-track {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            position: relative;
            margin: 16px 0 4px;
        }
        .mini-track::before {
            content: '';
            position: absolute;
            top: 14px; left: 14px; right: 14px;
            height: 2px;
            background: rgba(255,255,255,0.06);
            z-index: 0;
        }
        .mini-track-fill {
            position: absolute;
            top: 14px; left: 14px;
            height: 2px;
            background: linear-gradient(90deg, var(--gold), rgba(223,186,134,0.4));
            z-index: 1;
            transition: width 0.6s ease;
        }
        .mts {
            display: flex; flex-direction: column;
            align-items: center; gap: 6px;
            z-index: 2; flex: 1;
        }
        .mts-dot {
            width: 28px; height: 28px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.08);
            background: var(--bg-dark);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.7rem;
            color: rgba(255,255,255,0.18);
            transition: all 0.4s ease;
        }
        .mts-dot.done { border-color: var(--gold); background: rgba(223,186,134,0.12); color: var(--gold); }
        .mts-dot.active { border-color: var(--gold); background: var(--gold); color: #000; box-shadow: 0 0 12px rgba(223,186,134,0.35); }
        .mts-lbl { font-size: 0.58rem; font-weight: 500; color: rgba(168,161,150,0.7); text-align: center; text-transform: uppercase; max-width: 48px; }
        .mts-lbl.active { color: var(--gold); }
        .mts-lbl.done { color: rgba(250,247,240,0.6); }

        /* Track live button */
        .btn-track-live {
            background: transparent;
            border: 1px solid rgba(223,186,134,0.35);
            color: var(--gold);
            border-radius: 8px;
            padding: 0.5rem 1.1rem;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: var(--transition);
            margin-top: 0.8rem;
        }
        .btn-track-live:hover {
            background: rgba(223,186,134,0.08);
            border-color: var(--gold);
            color: var(--gold-light);
            transform: translateY(-1px);
        }
        .live-dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--gold);
            animation: livePulse 1.8s ease-in-out infinite;
        }
        @keyframes livePulse { 0%,100%{opacity:1} 50%{opacity:0.35} }

        @media (max-width: 768px) {
            .order-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .order-body {
                flex-direction: column;
                align-items: stretch;
            }
            .order-financials {
                text-align: left;
                border-top: 1px solid rgba(255, 255, 255, 0.05);
                padding-top: 1rem;
            }
        }
    </style>
</head>
<body>

    <!-- Luxury Top Header Bar -->
    <header class="luxury-navbar">
        <div class="d-flex justify-content-between align-items-center w-100 max-width-1200 mx-auto">
            <a href="menutest.html" class="navbar-brand" style="display: flex; align-items: center; gap: 8px;">
                <img src="assets/images/versace_logo.png" alt="Medusa Logo" style="height: 32px; border-radius: 50%; border: 1px solid var(--gold); padding: 1px;">
                <span>Medusa</span>
            </a>
            <div class="d-flex align-items-center gap-4">
                <a href="menutest.html" class="nav-link-custom">
                    <i class="fa-solid fa-utensils"></i>
                    <span>Browse Menu</span>
                </a>
                <a href="#my-rewards-section" class="nav-link-custom">
                    <i class="fa-solid fa-gift"></i>
                    <span>My Rewards</span>
                </a>
                <a href="api/logout.php" class="nav-link-custom text-danger">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <main class="dashboard-container">
        <!-- Welcome Banner -->
        <section class="welcome-section">
            <h1 class="welcome-title">Welcome back, <?php echo htmlspecialchars($user_name); ?></h1>
            <p class="welcome-subtitle">Track your active orders and review your fine dining history below.</p>
        </section>

        <?php if (!empty($orders)): ?>
            <?php
            // Calculate helper metrics
            $total_spent = 0;
            $active_orders = 0;
            $completed_orders = 0;
            foreach ($orders as $o) {
                if ($o['order_status'] === 'completed') {
                    $total_spent += floatval($o['total_amount']);
                    $completed_orders++;
                } else if ($o['order_status'] !== 'cancelled') {
                    $active_orders++;
                }
            }
            ?>
            <!-- Stats Dashboard Row -->
            <section class="status-grid">
                <div class="status-card">
                    <div class="status-info">
                        <h3><?php echo count($orders); ?></h3>
                        <p>Total Orders</p>
                    </div>
                    <div class="status-icon">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                </div>
                <div class="status-card">
                    <div class="status-info">
                        <h3><?php echo $active_orders; ?></h3>
                        <p>Active Orders</p>
                    </div>
                    <div class="status-icon">
                        <i class="fa-solid fa-spinner fa-spin-slow"></i>
                    </div>
                </div>
                <div class="status-card">
                    <div class="status-info">
                        <h3>₹<?php echo number_format($total_spent, 2); ?></h3>
                        <p>Total Spent</p>
                    </div>
                    <div class="status-icon">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                </div>
            </section>

            <!-- My Coupons & Rewards Section -->
            <section id="my-rewards-section" class="mb-5" style="animation: fadeIn 0.8s ease-out;">
                <h2 class="orders-section-title"><i class="fa-solid fa-gift me-2 text-gold"></i>My Rewards & Coupons</h2>
                <?php if (empty($userCoupons)): ?>
                    <div class="empty-orders" style="padding: 3rem 2rem;">
                        <div class="empty-icon" style="font-size: 3rem; margin-bottom: 1rem;">
                            <i class="fa-solid fa-tag"></i>
                        </div>
                        <h4 style="color: var(--gray);">No coupons available yet</h4>
                        <p class="empty-text" style="font-size: 0.88rem; max-width: 320px; margin-bottom: 0;">Submit a 5-star review after ordering to unlock a special discount coupon!</p>
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
                            <div class="col-md-6 col-lg-4" style="opacity: <?php echo $cardOpacity; ?>;">
                                <div class="status-card" style="flex-direction: column; align-items: stretch; gap: 1rem; border-style: dashed;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-gold" style="font-weight: 700; font-size: 1.2rem;"><?php echo intval($coupon->discount_value); ?>% OFF</span>
                                        <?php echo $statusBadge; ?>
                                    </div>
                                    <p class="text-white-50 m-0" style="font-size: 0.85rem;">Campaign: <?php echo htmlspecialchars($coupon->campaign_code); ?></p>
                                    
                                    <div class="d-flex align-items-center justify-content-between bg-dark p-2 rounded border border-secondary" style="margin-top: 0.25rem;">
                                        <code style="font-family: monospace; font-size: 0.95rem; color: var(--gold); font-weight: bold;"><?php echo htmlspecialchars($coupon->coupon_code); ?></code>
                                        <?php if ($coupon->status === 'active'): ?>
                                            <button type="button" class="btn btn-sm btn-outline-light copy-btn-orders" data-code="<?php echo htmlspecialchars($coupon->coupon_code); ?>" style="font-size: 0.72rem; padding: 0.2rem 0.4rem;">Copy</button>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center text-muted" style="font-size: 0.75rem;">
                                        <span>Expires: <?php echo date('d M Y', strtotime($coupon->expires_at)); ?></span>
                                        <?php if ($coupon->redeemed_at): ?>
                                            <span class="text-white-50">Used: <?php echo date('d M Y', strtotime($coupon->redeemed_at)); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Order Cards List -->
            <section class="orders-list-wrapper">
                <h2 class="orders-section-title">Order History</h2>
                
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
                    
                    <div class="order-card" id="order-<?php echo $order['id']; ?>">
                        <div class="order-header">
                            <div class="order-meta">
                                <h4>Order <span class="order-number"><?php echo htmlspecialchars($order['order_number']); ?></span></h4>
                                <div class="order-date">
                                    <i class="fa-regular fa-calendar-days me-1"></i>
                                    <?php echo date('d M Y, h:i A', strtotime($order['order_date'])); ?>
                                    <?php if (strpos(strtolower($order['delivery_address']), 'table') !== false): ?>
                                        <span class="badge bg-secondary ms-2"><i class="fa-solid fa-utensils me-1"></i> Dine-In</span>
                                    <?php else: ?>
                                        <span class="badge bg-dark ms-2"><i class="fa-solid fa-motorcycle me-1"></i> Online Delivery</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <i class="fa-solid <?php echo $status_icon; ?>"></i>
                                <?php echo $status_label; ?>
                            </span>
                        </div>
                        
                        <div class="order-body">
                            <div class="order-items-summary">
                                <?php foreach ($order['items'] as $item): ?>
                                    <div class="item-row">
                                        <span>
                                            <span class="item-qty"><?php echo $item['quantity']; ?>x</span>
                                            <?php echo htmlspecialchars($item['item_name']); ?>
                                        </span>
                                        <span class="text-white-50">₹<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                                    </div>
                                <?php endforeach; ?>

                                <?php
                                // ── Mini Tracker (active orders with tracking token) ──
                                $ts = $order['tracking_status'] ?? 'placed';
                                $tk = $order['tracking_token'] ?? null;
                                $isActive = !in_array($order['order_status'], ['completed','cancelled']);
                                $tsStepMap = ['placed'=>1,'confirmed'=>2,'preparing'=>3,'out_for_delivery'=>4,'delivered'=>5,'cancelled'=>0];
                                $tsCurStep = $tsStepMap[$ts] ?? 1;
                                $tsSteps = [
                                    ['label'=>'Placed',    'icon'=>'fa-receipt'],
                                    ['label'=>'Confirmed', 'icon'=>'fa-circle-check'],
                                    ['label'=>'Preparing', 'icon'=>'fa-fire-burner'],
                                    ['label'=>'On Way',    'icon'=>'fa-motorcycle'],
                                    ['label'=>'Done',      'icon'=>'fa-house'],
                                ];
                                if ($isActive && $tk):
                                    $fillPct = $tsCurStep <= 1 ? 0 : (($tsCurStep-1)/4)*100;
                                ?>
                                <div class="mini-track" data-token="<?php echo htmlspecialchars($tk); ?>" data-step="<?php echo $tsCurStep; ?>">
                                    <div class="mini-track-fill" style="width:<?php echo $fillPct; ?>%;"></div>
                                    <?php foreach ($tsSteps as $si => $ss):
                                        $sn = $si+1;
                                        $sc = $sn < $tsCurStep ? 'done' : ($sn === $tsCurStep ? 'active' : '');
                                    ?>
                                    <div class="mts">
                                        <div class="mts-dot <?php echo $sc; ?>"><i class="fas <?php echo $ss['icon']; ?>"></i></div>
                                        <div class="mts-lbl <?php echo $sc; ?>"><?php echo $ss['label']; ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <a href="track.php?token=<?php echo htmlspecialchars($tk); ?>" class="btn-track-live">
                                    <span class="live-dot"></span> Track Live
                                    <i class="fa-solid fa-arrow-right"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <div class="order-financials">
                                <span class="total-label">Grand Total</span>
                                <div class="total-value">₹<?php echo number_format($order['total_amount'], 2); ?></div>
                                <a href="order-details.php?order_id=<?php echo urlencode($order['order_number']); ?>" class="btn-view-invoice">
                                    <i class="fa-solid fa-file-invoice-dollar"></i>
                                    <span>View Invoice</span>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
            
        <?php else: ?>
            <!-- Empty State -->
            <section class="empty-orders">
                <div class="empty-icon">
                    <i class="fa-solid fa-utensils"></i>
                </div>
                <h2 class="empty-title">No Orders Yet</h2>
                <p class="empty-text">You haven't placed any culinary orders with us yet. Visit our interactive menu to select your favorite dishes.</p>
                <a href="menutest.html" class="btn-order-now">
                    <span>Order Something Delicious</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </section>
        <?php endif; ?>
    </main>

    <!-- Optional Polling Script for Active Orders Status -->
    <script>
        // Poll status of active (non-completed) orders every 8 seconds to show changes without full-page reloads
        const activeOrders = <?php 
            $active_ids = [];
            foreach($orders as $o) {
                if ($o['order_status'] !== 'completed' && $o['order_status'] !== 'cancelled') {
                    $active_ids[] = $o['order_number'];
                }
            }
            echo json_encode($active_ids);
        ?>;

        if (activeOrders.length > 0) {
            setInterval(async () => {
                try {
                    const res = await fetch('api/get-my-orders.php');
                    const data = await res.json();
                    if (data.success) {
                        // Scan for changes in active orders and reload if a status update is detected
                        let statusChanged = false;
                        data.orders.forEach(updatedOrder => {
                            const matchCard = document.getElementById(`order-${updatedOrder.id}`);
                            if (matchCard) {
                                const currentBadge = matchCard.querySelector('.status-badge');
                                let newClass = 'status-pending';
                                switch(updatedOrder.order_status.toLowerCase()) {
                                    case 'pending': newClass = 'status-pending'; break;
                                    case 'preparing': newClass = 'status-preparing'; break;
                                    case 'ready': newClass = 'status-ready'; break;
                                    case 'completed': newClass = 'status-completed'; break;
                                    case 'cancelled': newClass = 'status-cancelled'; break;
                                }
                                if (!currentBadge.classList.contains(newClass)) {
                                    statusChanged = true;
                                }
                            }
                        });
                        if (statusChanged) {
                            window.location.reload();
                        }
                    }
                } catch(e) {
                    console.warn('Poller failed to reach status API', e);
                }
            }, 8000);
        }

        // Copy coupon code to clipboard from My Rewards section
        document.querySelectorAll('.copy-btn-orders').forEach(btn => {
            btn.addEventListener('click', function() {
                const code = this.getAttribute('data-code');
                navigator.clipboard.writeText(code).then(() => {
                    const originalText = this.textContent;
                    this.textContent = 'Copied!';
                    setTimeout(() => {
                        this.textContent = originalText;
                    }, 2000);
                }).catch(err => {
                    alert('Coupon Code: ' + code);
                });
            });
        });
    </script>

<?php require_once __DIR__ . '/includes/active_order_bar.php'; ?>
<?php require_once __DIR__ . '/includes/order_toast.php'; ?>
</body>
</html>
