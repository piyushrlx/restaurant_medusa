<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver View - Tracker</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #0f172a; color: white; }
        .nav { padding: 15px; background: #1e293b; display: flex; justify-content: space-between; align-items: center; }
        .nav a { color: #38bdf8; text-decoration: none; margin-left: 15px; }
        .container { padding: 20px; max-width: 600px; margin: 0 auto; text-align: center; }
        .btn { padding: 10px 20px; font-size: 16px; cursor: pointer; border-radius: 5px; border: none; background: #3b82f6; color: white; }
        .btn-danger { background: #ef4444; }
        .status { margin: 20px 0; padding: 10px; border-radius: 5px; background: #1e293b; }
        .active { color: #22c55e; }
        .inactive { color: #ef4444; }
    </style>
</head>
<body>
    <div class="nav">
        <div><strong>📍 DeliveryTracker - Driver</strong></div>
        <div>
            <a href="../track.php">Customer View</a>
            <a href="driver.php" style="color: white">Driver View</a>
        </div>
    </div>

    <div class="container">
        <h2>Driver Dashboard</h2>
        
        <div class="status" id="statusBadge">
            <span class="inactive">🔴 Tracking Paused</span>
        </div>

        <button id="startBtn" class="btn">Start Transmitting Location</button>
        <button id="stopBtn" class="btn btn-danger" style="display: none;">Stop Transmitting</button>

        <p id="errorMsg" style="color: #ef4444;"></p>
        
        <div id="locationData" style="display: none; margin-top: 20px; text-align: left; background: #1e293b; padding: 15px; border-radius: 5px;">
            <p style="color: #94a3b8; font-size: 14px; margin: 0;">Current Coordinates</p>
            <p style="font-family: monospace; margin-top: 5px;" id="coordsText"></p>
        </div>
    </div>

    <script>
        let watchId = null;
        const startBtn = document.getElementById('startBtn');
        const stopBtn = document.getElementById('stopBtn');
        const statusBadge = document.getElementById('statusBadge');
        const errorMsg = document.getElementById('errorMsg');
        const locationData = document.getElementById('locationData');
        const coordsText = document.getElementById('coordsText');

        startBtn.addEventListener('click', () => {
            if (!navigator.geolocation) {
                errorMsg.innerText = 'Geolocation is not supported by your browser';
                return;
            }

            errorMsg.innerText = '';
            startBtn.style.display = 'none';
            stopBtn.style.display = 'inline-block';
            statusBadge.innerHTML = '<span class="active">🟢 Tracking Active</span>';

            // Send initial starting point (Restaurant location)
            fetch('../api/update_location.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ lat: 30.681219808145546, lng: 76.72328631342646 })
            }).catch(err => console.error("Error sending starting location:", err));

            watchId = navigator.geolocation.watchPosition(
                (position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    coordsText.innerHTML = `Lat: ${lat.toFixed(6)}<br>Lng: ${lng.toFixed(6)}`;
                    locationData.style.display = 'block';

                    // Send to PHP backend
                    fetch('../api/update_location.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ lat, lng })
                    }).catch(err => console.error("Error sending location:", err));
                },
                (err) => {
                    errorMsg.innerText = err.message;
                    stopTracking();
                },
                { enableHighAccuracy: true, maximumAge: 10000, timeout: 5000 }
            );
        });

        function stopTracking() {
            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
            startBtn.style.display = 'inline-block';
            stopBtn.style.display = 'none';
            statusBadge.innerHTML = '<span class="inactive">🔴 Tracking Paused</span>';
            locationData.style.display = 'none';
        }

        stopBtn.addEventListener('click', stopTracking);
    </script>
</body>
</html>
