<?php
$geocodeApiKey = get_env_var('GOOGLE_MAPS_GEOCODING_API_KEY', '');
$mapsApiKey = get_env_var('GOOGLE_MAPS_API_KEY', '');
if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'driver') {
    exit('Unauthorized access');
}
$driverName = htmlspecialchars($_SESSION['user_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Driver Partner Portal</title>
    <!-- Fonts and Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- OpenLayers -->
    <script src="https://cdn.jsdelivr.net/npm/ol@v10.3.1/dist/ol.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@v10.3.1/ol.css">

    <style>
        :root {
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --primary: #4CAF50;
            --primary-dark: #388E3C;
            --accent: #dfba86; /* Medusa Gold */
            --danger: #f44336;
            --text-main: #ffffff;
            --text-muted: #aaaaaa;
            --border: #333333;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden; /* Prevent body scroll, handle inside containers */
        }

        /* Top Header */
        header {
            background-color: var(--card-bg);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            z-index: 1000;
        }
        
        .header-logo {
            font-weight: 700;
            color: var(--accent);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logout-btn {
            background: none;
            border: none;
            color: var(--danger);
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
        }

        /* Screens */
        .screen {
            display: none;
            flex: 1;
            flex-direction: column;
            height: calc(100vh - 60px); /* Minus header */
        }
        .screen.active {
            display: flex;
        }

        /* Screen 1: Order Entry */
        .order-entry-container {
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1;
        }

        .welcome-text {
            font-size: 1.5rem;
            margin-bottom: 10px;
            text-align: center;
        }
        
        .sub-text {
            color: var(--text-muted);
            margin-bottom: 40px;
            text-align: center;
        }

        .input-group {
            width: 100%;
            max-width: 400px;
            margin-bottom: 20px;
        }

        .order-input {
            width: 100%;
            padding: 18px 20px;
            font-size: 1.2rem;
            background-color: var(--card-bg);
            border: 2px solid var(--border);
            border-radius: 12px;
            color: white;
            text-align: center;
            outline: none;
            transition: border-color 0.3s;
        }

        .order-input:focus {
            border-color: var(--accent);
        }

        .btn-large {
            width: 100%;
            max-width: 400px;
            padding: 18px;
            background-color: var(--accent);
            color: #000;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.1s, background-color 0.3s;
        }
        
        .btn-large:active {
            transform: scale(0.98);
        }

        /* Screen 2: Active Delivery */
        #map-container {
            height: 45vh;
            width: 100%;
            background-color: #222;
            position: absolute;
            top: 60px; /* Header height */
            left: 0;
            z-index: 1;
        }

        .delivery-details {
            background-color: var(--bg-color);
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
            margin-top: calc(45vh - 20px); /* Position below map */
            z-index: 1000;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            overflow-y: auto;
            flex: 1;
            padding-bottom: 160px; /* Space for sticky buttons */
            position: relative;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            background-color: rgba(76, 175, 80, 0.2);
            color: var(--primary);
        }
        
        .tracking-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: var(--primary);
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.7); }
            70% { box-shadow: 0 0 0 6px rgba(76, 175, 80, 0); }
            100% { box-shadow: 0 0 0 0 rgba(76, 175, 80, 0); }
        }

        .info-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 15px;
            border: 1px solid var(--border);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .card-title {
            font-size: 0.9rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .card-value {
            font-size: 1.1rem;
            font-weight: 600;
            line-height: 1.4;
        }

        .btn-call {
            background-color: rgba(76, 175, 80, 0.15);
            color: var(--primary);
            border: none;
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.95rem;
        }

        /* Sticky Bottom Actions */
        .action-panel {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 15px 20px;
            background-color: var(--card-bg);
            border-top: 1px solid var(--border);
            display: flex;
            gap: 10px;
            z-index: 2000;
        }

        .btn-action {
            flex: 1;
            padding: 15px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1.05rem;
            border: none;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            color: white;
        }

        .btn-pickup { background-color: #2196F3; }
        .btn-deliver { background-color: var(--primary); }
        .btn-cancel { background-color: var(--danger); }
        .btn-sos { 
            background-color: transparent; 
            border: 2px solid var(--danger); 
            color: var(--danger); 
            flex: 0 0 auto; 
            width: 60px;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 3000;
            padding: 20px;
        }

        .modal-content {
            background-color: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 25px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
            gap: 15px;
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .modal-subtitle {
            font-size: 0.95rem;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .modal-select {
            width: 100%;
            padding: 12px 16px;
            font-size: 1rem;
            background-color: var(--bg-color);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: white;
            outline: none;
            cursor: pointer;
            transition: border-color 0.3s;
        }

        .modal-select:focus {
            border-color: var(--accent);
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .btn-modal {
            flex: 1;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            transition: opacity 0.2s;
        }

        .btn-modal:active {
            opacity: 0.8;
        }

        .btn-modal-close {
            background-color: transparent;
            border: 1px solid var(--border);
            color: var(--text-main);
        }

        .btn-modal-confirm {
            background-color: var(--danger);
            color: white;
        }
    </style>
</head>
<body>

    <header>
        <div class="header-logo">
            <i class="fa-solid fa-motorcycle"></i> Medusa Driver
        </div>
        <button class="logout-btn" onclick="logout()"><i class="fa-solid fa-right-from-bracket"></i></button>
    </header>

    <!-- Screen 1: Order Entry -->
    <div id="screen-entry" class="screen active">
        <div class="order-entry-container">
            <h1 class="welcome-text">Ready to Deliver, <?php echo $driverName; ?>?</h1>
            <p class="sub-text">Enter an Order ID to begin tracking.</p>

                        <div class="input-group">
                <input type="text" id="orderInput" class="order-input" placeholder="e.g. ORD-123456" autocomplete="off">
            </div>
            
            <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                <input type="text" id="testPickupLat" class="order-input" placeholder="Test Pickup Lat" style="padding: 10px; font-size: 14px;">
                <input type="text" id="testPickupLng" class="order-input" placeholder="Test Pickup Lng" style="padding: 10px; font-size: 14px;">
            </div>
            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                <input type="text" id="testDropoffLat" class="order-input" placeholder="Test Dropoff Lat" style="padding: 10px; font-size: 14px;">
                <input type="text" id="testDropoffLng" class="order-input" placeholder="Test Dropoff Lng" style="padding: 10px; font-size: 14px;">
            </div>

            <button class="btn-large" onclick="fetchOrder()">Start Delivery</button>
        </div>
    </div>

    <!-- Screen 2: Active Delivery -->
    <div id="screen-delivery" class="screen">
        <div id="map-container"></div>
        
        <div class="delivery-details">
            <div class="card-header">
                <div class="status-badge" id="uiStatus">Connecting...</div>
                <div class="tracking-indicator">
                    <div class="dot"></div> Live GPS
                </div>
            </div>

            <!-- Order Details -->
            <div class="info-card">
                <div class="card-header">
                    <span class="card-title">Order Details</span>
                    <span style="font-weight: 600; color: var(--accent);" id="uiOrderNumber">---</span>
                </div>
                <div style="display:flex; justify-content: space-between; margin-top: 10px;">
                    <div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">Amount</div>
                        <div class="card-value" id="uiAmount">₹0.00</div>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">Payment</div>
                        <div class="card-value" id="uiPayment">---</div>
                    </div>
                </div>
            </div>

            <!-- Restaurant Card -->
            <div class="info-card">
                <div class="card-header">
                    <span class="card-title">Pickup: Restaurant Medusa</span>
                    <a href="tel:+919427272798" class="btn-call"><i class="fa-solid fa-phone"></i> Call</a>
                </div>
                <div class="card-value" style="font-size: 0.95rem; font-weight: 400; color: var(--text-muted);">
                    SCO 44, 45, District One Market, Sector 67, Mohali
                </div>
            </div>

            <!-- Customer Card -->
            <div class="info-card">
                <div class="card-header">
                    <span class="card-title">Dropoff: <span id="uiCustomerName">---</span></span>
                    <a href="#" id="uiCustomerPhone" class="btn-call"><i class="fa-solid fa-phone"></i> Call</a>
                </div>
                <div class="card-value" id="uiCustomerAddress" style="font-size: 0.95rem; font-weight: 400; color: var(--text-muted);">
                    ---
                </div>
            </div>
        </div>

        <div class="action-panel">
            <button class="btn-action btn-sos" onclick="triggerSOS()"><i class="fa-solid fa-triangle-exclamation"></i></button>
            <div style="display: flex; flex-direction: column; gap: 10px; flex: 1;">
                <button class="btn-action btn-pickup" id="btnPickup" onclick="markPickedUp()"><i class="fa-solid fa-box"></i> Picked Up</button>
                <button class="btn-action btn-deliver" id="btnDeliver" onclick="markDelivered()" style="display:none;"><i class="fa-solid fa-check-circle"></i> Delivered</button>
                <button class="btn-action btn-cancel" id="btnCancel" onclick="cancelDelivery()"><i class="fa-solid fa-xmark"></i> Cancel Delivery</button>
            </div>
        </div>
    </div>

    <!-- Cancellation Reason Modal -->
    <div id="cancelModal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <h3 class="modal-title">Reason for Cancellation</h3>
            <p class="modal-subtitle">Please select a reason for canceling this delivery:</p>
            
            <div class="reason-options">
                <select id="cancellationReason" class="modal-select">
                    <option value="" disabled selected>Select a reason...</option>
                    <option value="reason1">Customer Not Available</option>
                    <option value="reason2">Customer Requested Cancellation/Customer Refused Delivery</option>
                    <option value="reason3">Delivery Address Not Found</option>
                    <option value="reason4">Vehicle Breakdown / Emergency</option>
                    <option value="reason5">Unable to Contact Customer</option>
                </select>
            </div>
            
            <div class="modal-actions">
                <button class="btn-modal btn-modal-close" onclick="closeCancelModal()">Go Back</button>
                <button class="btn-modal btn-modal-confirm" id="btnConfirmCancel">Confirm Cancel</button>
            </div>
        </div>
    </div>

    <script>
        // Constants
        const DEFAULT_RESTAURANT_LAT = 30.680322;
        const DEFAULT_RESTAURANT_LNG = 76.719541;
        let RESTAURANT_LAT = 30.680322;
        let RESTAURANT_LNG = 76.719541;
        const TOMTOM_API_KEY = "qI82bUxco20qcXu2avJFVppor79rrqzM";

        // State
        let currentOrder = null;
        let map = null;
        let vectorSource = null;
        let routeSource = null;
        let driverFeature = null;
        let restaurantFeature = null;
        let customerFeature = null;
        let watchId = null;
        let currentLat = null;
        let currentLng = null;

        function createMarkerStyle(color, size) {
            return new ol.style.Style({
                image: new ol.style.Circle({
                    radius: size,
                    fill: new ol.style.Fill({ color: color }),
                    stroke: new ol.style.Stroke({ color: '#fff', width: 2 })
                })
            });
        }

        // Initialize Map
        function initMap() {
            if (map) return;
            
            routeSource = new ol.source.Vector();
            vectorSource = new ol.source.Vector();
            
            map = new ol.Map({
                target: 'map-container',
                layers: [
                    new ol.layer.Tile({
                        source: new ol.source.XYZ({
                            url: `https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?key=${TOMTOM_API_KEY}`,
                            attributions: '&copy; TomTom'
                        })
                    }),
                    new ol.layer.Vector({
                        source: routeSource,
                        style: new ol.style.Style({
                            stroke: new ol.style.Stroke({
                                color: 'rgba(76, 175, 80, 0.8)',
                                width: 5
                            })
                        })
                    }),
                    new ol.layer.Vector({
                        source: vectorSource
                    })
                ],
                view: new ol.View({
                    center: ol.proj.fromLonLat([RESTAURANT_LNG, RESTAURANT_LAT]),
                    zoom: 13
                }),
                controls: []
            });

            restaurantFeature = new ol.Feature({
                geometry: new ol.geom.Point(ol.proj.fromLonLat([RESTAURANT_LNG, RESTAURANT_LAT]))
            });
            restaurantFeature.setStyle(createMarkerStyle('#dfba86', 10));
            vectorSource.addFeature(restaurantFeature);
        }

        // Fetch Order API
        async function fetchOrder() {
            const orderId = document.getElementById('orderInput').value.trim();
            if (!orderId) { alert("Please enter an Order ID"); return; }

            try {
                const response = await fetch(`../api/driver_api.php?action=fetch_order&order_number=${orderId}`);
                const result = await response.json();

                if (result.success) {
                    currentOrder = result.order;
                    startDeliveryUI();
                } else {
                    alert(result.message);
                }
            } catch (err) {
                alert("Network error fetching order.");
            }
        }

        // Setup Delivery UI
        async function startDeliveryUI() {
            document.getElementById('screen-entry').classList.remove('active');
            document.getElementById('screen-delivery').classList.add('active');

            RESTAURANT_LAT = parseFloat(document.getElementById('testPickupLat').value) || DEFAULT_RESTAURANT_LAT;
            RESTAURANT_LNG = parseFloat(document.getElementById('testPickupLng').value) || DEFAULT_RESTAURANT_LNG;

            // Populate UI
            document.getElementById('uiOrderNumber').textContent = currentOrder.order_number;
            document.getElementById('uiAmount').textContent = '₹' + currentOrder.total_amount;
            document.getElementById('uiPayment').textContent = currentOrder.payment_method;
            document.getElementById('uiCustomerName').textContent = currentOrder.customer_name;
            document.getElementById('uiCustomerAddress').textContent = currentOrder.delivery_address;
            document.getElementById('uiCustomerPhone').href = 'tel:' + currentOrder.customer_phone;
            updateStatusBadge(currentOrder.status);

            // Init Map and Tracking
            initMap();
            if (map && restaurantFeature) {
                map.getView().setCenter(ol.proj.fromLonLat([RESTAURANT_LNG, RESTAURANT_LAT]));
                restaurantFeature.getGeometry().setCoordinates(ol.proj.fromLonLat([RESTAURANT_LNG, RESTAURANT_LAT]));
            }
            setTimeout(() => { map.updateSize(); }, 500); 
            startGPSTracking();

            // Geocode Customer Address
            try {
                let cLat = RESTAURANT_LAT + 0.02;
                let cLng = RESTAURANT_LNG + 0.02;
                
                let forceDropLat = parseFloat(document.getElementById('testDropoffLat').value);
                let forceDropLng = parseFloat(document.getElementById('testDropoffLng').value);

                if (forceDropLat && forceDropLng) {
                    cLat = forceDropLat;
                    cLng = forceDropLng;
                } else {
                    const geoRes = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(currentOrder.delivery_address)}.json?key=${TOMTOM_API_KEY}`);
                    const geoData = await geoRes.json();
                    
                    if (geoData.results && geoData.results.length > 0) {
                        cLat = geoData.results[0].position.lat;
                        cLng = geoData.results[0].position.lon;
                    } else {
                        console.warn('TomTom Geocoding failed. Using fallback.', geoData);
                    }
                }
                
                customerFeature = new ol.Feature({
                    geometry: new ol.geom.Point(ol.proj.fromLonLat([cLng, cLat]))
                });
                customerFeature.setStyle(createMarkerStyle('#4CAF50', 10));
                vectorSource.addFeature(customerFeature);

                // Setup Route
                setupRoute(cLat, cLng);
            } catch (e) {
                console.error("Geocoding failed", e);
            }
        }

        // GPS Tracking
        function startGPSTracking() {
            if ("geolocation" in navigator) {
                watchId = navigator.geolocation.watchPosition(
                    (position) => {
                        currentLat = position.coords.latitude;
                        currentLng = position.coords.longitude;

                        const coord = ol.proj.fromLonLat([currentLng, currentLat]);

                        if (!driverFeature) {
                            driverFeature = new ol.Feature({
                                geometry: new ol.geom.Point(coord)
                            });
                            driverFeature.setStyle(createMarkerStyle('#2196F3', 12));
                            vectorSource.addFeature(driverFeature);
                        } else {
                            driverFeature.getGeometry().setCoordinates(coord);
                        }

                        // Send location to backend if an order is active
                        if (currentOrder && currentOrder.order_number) {
                            fetch('../api/driver_api.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    action: 'update_location',
                                    order_number: currentOrder.order_number,
                                    lat: currentLat,
                                    lng: currentLng
                                })
                            }).catch(err => console.error("Error updating location", err));
                        }
                    },
                    (error) => { console.warn("GPS Error", error); },
                    { enableHighAccuracy: true, maximumAge: 10000, timeout: 5000 }
                );
            }
        }

        // OpenLayers Routing Setup (using OSRM API)
        async function setupRoute(custLat, custLng) {
            routeSource.clear();
            const start = `${RESTAURANT_LNG},${RESTAURANT_LAT}`;
            const end = `${custLng},${custLat}`;
            
            try {
                const res = await fetch(`https://router.project-osrm.org/route/v1/driving/${start};${end}?overview=full&geometries=geojson`);
                const data = await res.json();
                
                if(data.routes && data.routes.length > 0) {
                    const route = data.routes[0].geometry;
                    const format = new ol.format.GeoJSON();
                    const routeFeature = format.readFeature({
                        type: 'Feature',
                        geometry: route
                    }, {
                        dataProjection: 'EPSG:4326',
                        featureProjection: 'EPSG:3857'
                    });
                    routeSource.addFeature(routeFeature);
                    
                    const extent = routeSource.getExtent();
                    map.getView().fit(extent, { padding: [50, 50, 50, 50], maxZoom: 16 });
                }
            } catch(e) {
                console.error("Routing error", e);
            }
        }

        // Action: Picked Up
        async function markPickedUp() {
            try {
                const res = await fetch('../api/driver_api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'update_status', order_number: currentOrder.order_number, status: 'Picked Up' })
                });
                const data = await res.json();
                
                if (data.success) {
                    updateStatusBadge('Picked Up');
                    document.getElementById('btnPickup').style.display = 'none';
                    document.getElementById('btnDeliver').style.display = 'flex';
                } else {
                    alert(data.message);
                }
            } catch (err) { alert("Error updating status"); }
        }

        // Action: Delivered
        async function markDelivered() {
            try {
                const res = await fetch('../api/driver_api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'update_status', order_number: currentOrder.order_number, status: 'Delivered', lat: currentLat, lng: currentLng })
                });
                const data = await res.json();
                
                if (data.success) {
                    alert("Delivery completed successfully!");
                    resetDashboard();
                } else {
                    alert(data.message);
                }
            } catch (err) { alert("Error updating status"); }
        }

        // Action: SOS
        async function triggerSOS() {
            if (!confirm("CRITICAL: Send Emergency SOS Alert to Admin?")) return;
            try {
                const res = await fetch('../api/driver_api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ 
                        action: 'sos_alert', 
                        order_number: currentOrder ? currentOrder.order_number : 'N/A',
                        lat: currentLat,
                        lng: currentLng
                    })
                });
                const data = await res.json();
                if (data.success) {
                    alert("SOS Alert Sent! The restaurant has been notified of your location.");
                } else {
                    alert("Error sending SOS: " + data.message);
                }
            } catch (err) { alert("Error sending SOS."); }
        }

        function updateStatusBadge(status) {
            const badge = document.getElementById('uiStatus');
            badge.textContent = status;
            if (status === 'Picked Up' || status === 'Out for Delivery') {
                badge.style.backgroundColor = 'rgba(33,150,243,0.2)';
                badge.style.color = '#2196F3';
            } else {
                badge.style.backgroundColor = 'rgba(76, 175, 80, 0.2)';
                badge.style.color = 'var(--primary)';
            }
        }

        function resetDashboard() {
            if (watchId) navigator.geolocation.clearWatch(watchId);
            if (vectorSource) vectorSource.clear();
            if (routeSource) routeSource.clear();
            
            document.getElementById('screen-delivery').classList.remove('active');
            document.getElementById('screen-entry').classList.add('active');
            document.getElementById('orderInput').value = '';
            document.getElementById('btnPickup').style.display = 'flex';
            document.getElementById('btnDeliver').style.display = 'none';
            currentOrder = null;
        }

        function cancelDelivery() {
            document.getElementById('cancelModal').style.display = 'flex';
        }

        function closeCancelModal() {
            document.getElementById('cancelModal').style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const confirmBtn = document.getElementById('btnConfirmCancel');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', async () => {
                    const reasonSelect = document.getElementById('cancellationReason');
                    const reasonVal = reasonSelect.value;
                    const reasonText = reasonSelect.options[reasonSelect.selectedIndex]?.text || '';
                    if (!reasonVal) {
                        alert("Please select a reason first.");
                        return;
                    }
                    
                    if (!currentOrder || !currentOrder.order_number) {
                        alert("No active order to cancel.");
                        return;
                    }
                    
                    try {
                        const res = await fetch('../api/driver_api.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                action: 'update_status',
                                order_number: currentOrder.order_number,
                                status: 'cancelled',
                                reason: reasonText
                            })
                        });
                        const data = await res.json();
                        
                        if (data.success) {
                            alert("Order cancelled successfully!");
                            closeCancelModal();
                            resetDashboard();
                        } else {
                            alert("Failed to cancel order: " + data.message);
                        }
                    } catch (err) {
                        alert("Network error updating status.");
                    }
                });
            }
        });

        async function logout() {
            if (!confirm("Log out of Driver Portal?")) return;
            try {
                await fetch('../api/logout.php');
                window.location.href = '../login.html';
            } catch(e) { window.location.href = '../login.html'; }
        }
    </script>
</body>
</html>

