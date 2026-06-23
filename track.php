<?php
/**
 * track.php â€” Public live order tracking page
 * Token-based auth: ?token=<64-char hex>
 */
require_once __DIR__ . '/api/config.php';

$token = trim($_GET['token'] ?? $_SESSION['active_order_token'] ?? '');

// Validate token format
if (strlen($token) !== 64 || !ctype_xdigit($token)) {
    header('Location: menutest.html');
    exit;
}

// Initial server-side fetch for SEO + no-JS fallback
$order = null;
try {
    $stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.customer_name, o.delivery_address, o.delivery_city,
               o.total_amount, o.order_status, o.tracking_status,
               o.estimated_delivery, o.order_date
        FROM orders o
        WHERE o.tracking_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        $items_stmt = $pdo->prepare("SELECT item_name, quantity, price FROM order_items WHERE order_id = ?");
        $items_stmt->execute([$order['id']]);
        $order['items'] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { /* silent */ }

if (!$order) {
    header('Location: menutest.html');
    exit;
}

// Clean up session if order is terminal
$terminal = in_array($order['tracking_status'], ['delivered', 'cancelled']);
if ($terminal) {
    unset($_SESSION['active_order_token'], $_SESSION['active_order_id']);
}

$tracking_status = $order['tracking_status'] ?? 'placed';
$steps = [
    ['key' => 'placed',           'label' => 'Order Placed',   'icon' => 'fa-receipt'],
    ['key' => 'confirmed',        'label' => 'Confirmed',       'icon' => 'fa-circle-check'],
    ['key' => 'preparing',        'label' => 'Preparing',       'icon' => 'fa-fire-burner'],
    ['key' => 'out_for_delivery', 'label' => 'On the Way',      'icon' => 'fa-motorcycle'],
    ['key' => 'delivered',        'label' => 'Delivered',       'icon' => 'fa-house'],
];
$step_order = ['placed'=>1,'confirmed'=>2,'preparing'=>3,'out_for_delivery'=>4,'delivered'=>5,'cancelled'=>0];
$current_step = $step_order[$tracking_status] ?? 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Track your Medusa order live â€” real-time status updates.">
    <title>Track Order <?php echo htmlspecialchars($order['order_number']); ?> â€” LA-MEDUSAA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
        :root {
            --obsidian:  #0a0a0a;
            --dark:      #111111;
            --card:      #161616;
            --gold:      #c9a84c;
            --gold-glow: rgba(201,168,76,0.18);
            --gold-dim:  rgba(201,168,76,0.55);
            --parchment: #f5f0e8;
            --muted:     rgba(245,240,232,0.45);
            --border:    rgba(201,168,76,0.14);
            --serif:     'Cormorant Garamond', Georgia, serif;
            --sans:      'Jost', sans-serif;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--sans);
            background: var(--obsidian);
            color: var(--parchment);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* â”€â”€ NAV â”€â”€ */
        .top-nav {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 48px;
            height: 68px;
            background: rgba(10,10,10,0.92);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
        }
        .nav-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .nav-logo img { width: 36px; height: 36px; object-fit: contain; border-radius: 50%; border: 1px solid var(--gold-dim); }
        .nav-brand {
            font-family: var(--serif);
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gold);
            letter-spacing: 3px;
            text-transform: uppercase;
        }
        .nav-actions { display: flex; gap: 20px; align-items: center; }
        .nav-link {
            font-size: 0.75rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--muted);
            text-decoration: none;
            transition: color 0.2s;
        }
        .nav-link:hover { color: var(--gold); }

        /* â”€â”€ MAIN â”€â”€ */
        main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 60px 20px 80px;
            gap: 32px;
            max-width: 780px;
            margin: 0 auto;
            width: 100%;
        }

        /* â”€â”€ ORDER HEADER â”€â”€ */
        .order-header { text-align: center; }
        .order-number-label {
            font-size: 0.72rem;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 8px;
        }
        .order-number-value {
            font-family: var(--serif);
            font-size: 2.6rem;
            font-weight: 600;
            color: #fff;
            letter-spacing: 2px;
        }
        .order-date-label { font-size: 0.82rem; color: var(--muted); margin-top: 6px; }

        /* â”€â”€ STATUS CARD â”€â”€ */
        .status-card {
            width: 100%;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 36px 40px;
        }

        .status-headline {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 32px;
        }
        .status-pulse {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--gold);
            box-shadow: 0 0 0 0 var(--gold-glow);
            animation: pulse 2s infinite;
            flex-shrink: 0;
        }
        .status-pulse.terminal { background: #555; animation: none; box-shadow: none; }
        @keyframes pulse {
            0%   { box-shadow: 0 0 0 0 var(--gold-glow); }
            70%  { box-shadow: 0 0 0 10px rgba(0,0,0,0); }
            100% { box-shadow: 0 0 0 0 rgba(0,0,0,0); }
        }
        .status-text-group { flex: 1; }
        .status-main-label {
            font-family: var(--serif);
            font-size: 1.55rem;
            font-weight: 600;
            color: #fff;
            line-height: 1.2;
        }
        .status-sub-label { font-size: 0.85rem; color: var(--muted); margin-top: 4px; }
        .eta-badge {
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: var(--gold);
            background: var(--gold-glow);
            border: 1px solid rgba(201,168,76,0.25);
            border-radius: 50px;
            padding: 6px 16px;
            white-space: nowrap;
        }

        /* â”€â”€ STEPPER â”€â”€ */
        .stepper {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            position: relative;
        }
        .stepper::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 20px;
            right: 20px;
            height: 2px;
            background: rgba(255,255,255,0.07);
            z-index: 0;
        }
        .step-fill {
            position: absolute;
            top: 20px;
            left: 20px;
            height: 2px;
            background: linear-gradient(90deg, var(--gold), rgba(201,168,76,0.5));
            z-index: 1;
            transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 2;
            flex: 1;
        }
        .step-circle {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.1);
            background: var(--dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            color: rgba(255,255,255,0.25);
            transition: all 0.5s ease;
        }
        .step-circle.done {
            border-color: var(--gold);
            background: var(--gold-glow);
            color: var(--gold);
        }
        .step-circle.active {
            border-color: var(--gold);
            background: var(--gold);
            color: #000;
            box-shadow: 0 0 18px var(--gold-glow);
        }
        .step-label {
            font-size: 0.7rem;
            font-weight: 500;
            letter-spacing: 0.5px;
            color: var(--muted);
            text-align: center;
            text-transform: uppercase;
            transition: color 0.5s;
            max-width: 70px;
        }
        .step-label.active { color: var(--gold); }
        .step-label.done { color: rgba(245,240,232,0.75); }

        /* â”€â”€ DETAILS CARD â”€â”€ */
        .details-card {
            width: 100%;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            overflow: hidden;
        }
        .details-section {
            padding: 24px 32px;
            border-bottom: 1px solid var(--border);
        }
        .details-section:last-child { border-bottom: none; }
        .details-title {
            font-size: 0.68rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 16px;
        }
        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            font-size: 0.88rem;
        }
        .item-row:last-child { border-bottom: none; }
        .item-name { color: var(--parchment); }
        .item-qty { color: var(--muted); margin-left: 6px; font-size: 0.8rem; }
        .item-price { color: var(--gold); font-weight: 600; }
        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 1.05rem;
            font-weight: 600;
            padding-top: 12px;
            margin-top: 4px;
            border-top: 1px solid var(--border);
        }
        .total-label { color: var(--muted); font-size: 0.8rem; letter-spacing: 1px; text-transform: uppercase; }
        .total-value { color: var(--gold); font-size: 1.2rem; font-weight: 700; }
        .address-text { font-size: 0.88rem; color: var(--muted); line-height: 1.7; }

        /* â”€â”€ CTA â”€â”€ */
        .cta-row {
            display: flex;
            gap: 14px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn-gold {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--gold);
            color: #000;
            font-family: var(--sans);
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            text-decoration: none;
            padding: 13px 28px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-gold:hover { background: #d4b05a; transform: translateY(-1px); }
        .btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: transparent;
            color: var(--parchment);
            font-family: var(--sans);
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            text-decoration: none;
            padding: 13px 28px;
            border-radius: 8px;
            border: 1px solid rgba(245,240,232,0.18);
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-ghost:hover { border-color: var(--gold-dim); color: var(--gold); transform: translateY(-1px); }

        /* ———— FOOTER ———— */
        footer {
            text-align: center;
            padding: 24px;
            font-size: 0.75rem;
            color: var(--muted);
            border-top: 1px solid var(--border);
        }

        .custom-scooter-icon {
            background: transparent !important;
            border: none !important;
        }

        @media (max-width: 640px) {
            .top-nav { padding: 0 20px; }
            main { padding: 32px 16px 60px; }
            .status-card { padding: 24px 20px; }
            .status-headline { flex-direction: column; align-items: flex-start; gap: 10px; }
            .step-label { font-size: 0.6rem; max-width: 52px; }
            .details-section { padding: 20px; }
        }
    </style>
</head>
<body>

<!-- TOP NAV -->
<nav class="top-nav">
    <a href="index.html" class="nav-logo">
        <img src="assets/images/medusaa 2 ( only logo).png" alt="Medusa" onerror="this.src='assets/images/versace_logo.png'">
        <span class="nav-brand">La-Medusaa</span>
    </a>
    <div class="nav-actions">
        <a href="menutest.html" class="nav-link">Menu</a>
        <?php if (!empty($_SESSION['user_id'])): ?>
            <a href="my-orders.php" class="nav-link">My Orders</a>
        <?php endif; ?>
    </div>
</nav>

<main>
    <!-- Order Header -->
    <div class="order-header">
        <p class="order-number-label">Tracking Order</p>
        <h1 class="order-number-value"><?php echo htmlspecialchars($order['order_number']); ?></h1>
        <p class="order-date-label">
            Placed on <?php echo date('j F Y, g:i A', strtotime($order['order_date'])); ?>
            &nbsp;Â·&nbsp;
            For <?php echo htmlspecialchars($order['customer_name']); ?>
        </p>
    </div>

    <!-- Status Card -->
    <div class="status-card" id="statusCard">
        <div class="status-headline">
            <div class="status-pulse <?php echo $terminal ? 'terminal' : ''; ?>" id="statusPulse"></div>
            <div class="status-text-group">
                <div class="status-main-label" id="statusLabel">
                    <?php
                    $labels = ['placed'=>'Order Placed','confirmed'=>'Order Confirmed','preparing'=>'Being Prepared','out_for_delivery'=>'Out for Delivery','delivered'=>'Delivered','cancelled'=>'Cancelled'];
                    echo $labels[$tracking_status] ?? 'Processing';
                    ?>
                </div>
                <div class="status-sub-label" id="statusMsg">
                    <?php
                    $msgs = ['placed'=>'We have received your order and are confirming it.','confirmed'=>'Your order has been confirmed by our team!','preparing'=>'Our chefs are preparing your order right now.','out_for_delivery'=>'Your order is on its way to you!','delivered'=>'Your order has been delivered. Enjoy your meal!','cancelled'=>'This order has been cancelled.'];
                    echo $msgs[$tracking_status] ?? '';
                    ?>
                </div>
            </div>
            <?php if (!empty($order['estimated_delivery']) && !$terminal): ?>
            <div class="eta-badge" id="etaBadge">
                <i class="fas fa-clock"></i>
                ETA <?php echo date('g:i A', strtotime($order['estimated_delivery'])); ?>
            </div>
            <?php else: ?>
            <div class="eta-badge" id="etaBadge" style="display:none;"></div>
            <?php endif; ?>
        </div>

        <!-- 5-Step Progress Bar -->
        <div class="stepper" id="stepper">
            <div class="step-fill" id="stepFill" style="width:0%;"></div>
            <?php foreach ($steps as $i => $s):
                $sn = $i + 1;
                $cls = '';
                if ($sn < $current_step) $cls = 'done';
                elseif ($sn === $current_step) $cls = 'active';
            ?>
            <div class="step-item">
                <div class="step-circle <?php echo $cls; ?>" id="stepCircle<?php echo $sn; ?>">
                    <i class="fas <?php echo $s['icon']; ?>"></i>
                </div>
                <div class="step-label <?php echo $cls; ?>" id="stepLabel<?php echo $sn; ?>"><?php echo $s['label']; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Live Map Card -->
    <div class="details-card" id="liveMapCard" style="display: <?php echo $tracking_status === 'out_for_delivery' ? 'block' : 'none'; ?>; overflow: hidden;">
        <div class="details-section pb-0">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <p class="details-title m-0">Live Tracking</p>
                <div class="badge bg-gold text-dark" style="background: var(--gold); padding: 4px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 600;"><i class="fa-solid fa-location-crosshairs me-1"></i> Live</div>
            </div>
            <div id="partnerMap" style="height: 280px; width: 100%; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 24px;"></div>
        </div>
    </div>

    <!-- Order Details -->
    <div class="details-card">
        <!-- Items -->
        <div class="details-section">
            <p class="details-title">Items Ordered</p>
            <div id="itemsList">
                <?php foreach ($order['items'] as $item): ?>
                <div class="item-row">
                    <span class="item-name">
                        <?php echo htmlspecialchars($item['item_name']); ?>
                        <span class="item-qty">Ã— <?php echo intval($item['quantity']); ?></span>
                    </span>
                    <span class="item-price">â‚¹<?php echo number_format($item['price'] * $item['quantity'], 2); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="total-row">
                <span class="total-label">Grand Total</span>
                <span class="total-value">â‚¹<?php echo number_format($order['total_amount'], 2); ?></span>
            </div>
        </div>

        <!-- Address -->
        <div class="details-section">
            <p class="details-title">Delivery Address</p>
            <p class="address-text"><?php echo nl2br(htmlspecialchars(preg_replace('/\[[0-9.-]+,\s*[0-9.-]+\]/', '', $order['delivery_address']))); ?></p>
        </div>
    </div>

    <!-- CTA -->
    <div class="cta-row">
        <a href="menutest.html" class="btn-ghost"><i class="fas fa-utensils"></i> Browse Menu</a>
        <?php if (!empty($_SESSION['user_id'])): ?>
        <a href="my-orders.php" class="btn-gold"><i class="fas fa-receipt"></i> All My Orders</a>
        <?php endif; ?>
    </div>
</main>

<footer>
    LA-MEDUSAA Bar &amp; Lounge, Sector 68, Mohali &nbsp;Â·&nbsp; Live tracking updates every 15 seconds
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const TOKEN   = <?php echo json_encode($token); ?>;
const POLL_MS = 15000;
const DELIVERY_ADDRESS = <?php echo json_encode($order['delivery_address']); ?>;
const DELIVERY_CITY = <?php echo json_encode($order['delivery_city'] ?? ''); ?>;

const STEP_ORDER = { placed:1, confirmed:2, preparing:3, out_for_delivery:4, delivered:5, cancelled:0 };
const LABELS = { placed:'Order Placed', confirmed:'Order Confirmed', preparing:'Being Prepared', out_for_delivery:'Out for Delivery', delivered:'Delivered', cancelled:'Cancelled' };
const MSGS   = { placed:'We have received your order and are confirming it.', confirmed:'Your order has been confirmed by our team!', preparing:'Our chefs are preparing your order right now.', out_for_delivery:'Your order is on its way to you!', delivered:'Your order has been delivered. Enjoy your meal!', cancelled:'This order has been cancelled.' };

// Set initial fill width
let currentStep = <?php echo $current_step; ?>;
setFill(currentStep);

function setFill(step) {
    const pct = step <= 1 ? 0 : ((step - 1) / 4) * 100;
    document.getElementById('stepFill').style.width = pct + '%';
}

let map = null;
let riderMarker = null;
let customerMarker = null;
let routeLine = null;

async function getCustomerCoords() {
    const match = DELIVERY_ADDRESS.match(/\[([0-9.-]+),\s*([0-9.-]+)\]/);
    if (match) return [parseFloat(match[1]), parseFloat(match[2])];
    
    let cleanAddr = DELIVERY_ADDRESS.replace(/Table\s+[A-Za-z0-9]+/gi, '').trim();
    if(cleanAddr) {
        try {
            const query = encodeURIComponent(cleanAddr);
            const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${query}&limit=1`);
            const data = await res.json();
            if (data && data.length > 0) {
                return [parseFloat(data[0].lat), parseFloat(data[0].lon)];
            }
        } catch(e) { console.error("Geocoding address failed", e); }
    }

    if(DELIVERY_CITY && DELIVERY_CITY.trim() !== '') {
        try {
            const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&city=${encodeURIComponent(DELIVERY_CITY.trim())}&limit=1`);
            const data = await res.json();
            if (data && data.length > 0) {
                return [parseFloat(data[0].lat), parseFloat(data[0].lon)];
            }
        } catch(e) { console.error("Geocoding city failed", e); }
    }
    
    // Fallback if geocoding fails so map always shows a line
    console.warn("Using fallback coordinates for customer destination.");
    return [30.690, 76.735];
}

function initMap() {
    if (map) return;
    
    map = L.map('partnerMap').setView([30.681219808145546, 76.72328631342646], 16);
    L.tileLayer('http://mt0.google.com/vt/lyrs=m&hl=en&x={x}&y={y}&z={z}', {
        attribution: 'Google Maps'
    }).addTo(map);

    const truckIconHtml = `
        <div style="background-color: #38bdf8; border: 2px solid white; border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 6px rgba(0,0,0,0.3);">
            🚚
        </div>
    `;
    const truckIcon = L.divIcon({
        html: truckIconHtml,
        className: 'dummy',
        iconSize: [36, 36],
        iconAnchor: [18, 18]
    });

    riderMarker = L.marker([30.681219808145546, 76.72328631342646], {icon: truckIcon}).addTo(map);

    getCustomerCoords().then(coords => {
        if (coords) {
            const customerIconHtml = `
                <div style="background-color: #ef4444; border: 2px solid white; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 6px rgba(0,0,0,0.3);">
                    🏠
                </div>
            `;
            const customerIcon = L.divIcon({
                html: customerIconHtml,
                className: 'dummy',
                iconSize: [28, 28],
                iconAnchor: [14, 14]
            });
            customerMarker = L.marker(coords, {icon: customerIcon}).addTo(map);
            
            drawRoute(riderMarker.getLatLng(), customerMarker.getLatLng());
        }
    });
}

async function drawRoute(startLatLng, endLatLng) {
    const startLng = startLatLng.lng !== undefined ? startLatLng.lng : startLatLng[1];
    const startLat = startLatLng.lat !== undefined ? startLatLng.lat : startLatLng[0];
    const endLng = endLatLng.lng !== undefined ? endLatLng.lng : endLatLng[1];
    const endLat = endLatLng.lat !== undefined ? endLatLng.lat : endLatLng[0];
    
    const url = `https://router.project-osrm.org/route/v1/driving/${startLng},${startLat};${endLng},${endLat}?overview=full&geometries=geojson`;
    try {
        const res = await fetch(url);
        const data = await res.json();
        if (data.routes && data.routes.length > 0) {
            const coords = data.routes[0].geometry.coordinates.map(c => [c[1], c[0]]);
            if (routeLine) {
                routeLine.setLatLngs(coords);
            } else {
                routeLine = L.polyline(coords, {color: '#000000', weight: 5, opacity: 0.8}).addTo(map);
            }
            map.fitBounds(routeLine.getBounds(), {padding: [40, 40]});
        } else {
            drawStraightLine([startLat, startLng], [endLat, endLng]);
        }
    } catch(e) {
        drawStraightLine([startLat, startLng], [endLat, endLng]);
    }
}

function drawStraightLine(startCoords, endCoords) {
    if (routeLine) {
        routeLine.setLatLngs([startCoords, endCoords]);
    } else {
        routeLine = L.polyline([startCoords, endCoords], {color: '#000000', weight: 4, opacity: 0.8, dashArray: '10, 10'}).addTo(map);
    }
    map.fitBounds(routeLine.getBounds(), {padding: [40, 40]});
}

function updateMap(lat, lng) {
    if (!map) {
        initMap();
    }
    
    if (lat && lng) {
        const latlng = [lat, lng];
        riderMarker.setLatLng(latlng);
        
        if (customerMarker) {
            drawRoute(riderMarker.getLatLng(), customerMarker.getLatLng());
        } else {
            map.setView(latlng, 15);
        }
    }
}

function updateUI(data) {
    const step = data.step;
    const status = data.tracking_status;
    const isTerminal = !data.is_active;

    // Update headline
    document.getElementById('statusLabel').textContent = data.status_label;
    document.getElementById('statusMsg').textContent   = data.status_message;

    // Pulse
    const pulse = document.getElementById('statusPulse');
    pulse.classList.toggle('terminal', isTerminal);

    // ETA
    const eta = document.getElementById('etaBadge');
    if (data.estimated_delivery && !isTerminal) {
        const d = new Date(data.estimated_delivery.replace(' ', 'T'));
        eta.innerHTML = `<i class="fas fa-clock"></i> ETA ${d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}`;
        eta.style.display = '';
    } else {
        eta.style.display = 'none';
    }

    // Steps
    for (let i = 1; i <= 5; i++) {
        const circle = document.getElementById('stepCircle' + i);
        const label  = document.getElementById('stepLabel' + i);
        circle.className = 'step-circle' + (i < step ? ' done' : i === step ? ' active' : '');
        label.className  = 'step-label'  + (i < step ? ' done' : i === step ? ' active' : '');
    }

    // Fill bar
    setFill(step);
    currentStep = step;
    
    // Live Map Logic
    if (status === 'out_for_delivery') {
        const mapCard = document.getElementById('liveMapCard');
        if (mapCard.style.display !== 'block') {
            mapCard.style.display = 'block';
            setTimeout(() => { if (map) map.invalidateSize(); }, 300);
        }
        
        // Fetch location from the new API
        fetch('api/get_location.php')
            .then(res => res.json())
            .then(loc => {
                if (loc.lat && loc.lng) {
                    updateMap(loc.lat, loc.lng);
                }
            })
            .catch(err => console.error("Error fetching location:", err));
    } else {
        document.getElementById('liveMapCard').style.display = 'none';
    }

    // Stop polling if terminal
    if (isTerminal) clearInterval(pollTimer);
}

// Initial map load if already on the way
<?php if ($tracking_status === 'out_for_delivery'): ?>
    setTimeout(() => {
        initMap();
        fetch('api/get_location.php')
            .then(res => res.json())
            .then(loc => {
                if (loc.lat && loc.lng) {
                    updateMap(loc.lat, loc.lng);
                }
            });
    }, 500);
<?php endif; ?>

let pollTimer = null;

async function poll() {
    try {
        const res  = await fetch(`api/track-status.php?token=${TOKEN}`);
        const data = await res.json();
        if (data.success) updateUI(data);
    } catch(e) { /* silent â€” user still sees last known state */ }
}

// Only poll if order is not already in a terminal state
<?php if (!$terminal): ?>
pollTimer = setInterval(poll, POLL_MS);
<?php endif; ?>

// Fallback to fetch location if map is visible but order status not polled yet
setInterval(() => {
    const mapCard = document.getElementById('liveMapCard');
    if (mapCard.style.display === 'block') {
        fetch('api/get_location.php')
            .then(res => res.json())
            .then(loc => {
                if (loc.lat && loc.lng) {
                    updateMap(loc.lat, loc.lng);
                }
            })
            .catch(err => console.error("Error fetching location:", err));
    }
}, 2000);
</script>

</body>
</html>
