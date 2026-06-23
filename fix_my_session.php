<?php
require_once __DIR__ . '/api/config.php';

$_SESSION['user_id'] = 2;
$_SESSION['user_name'] = 'Test Customer';

echo "<h3>Session Fixed!</h3>";
echo "<p>Your session user_id has been set to <strong>2</strong> (Test Customer) and user_name to <strong>Test Customer</strong>.</p>";
echo "<p><a href='my-orders.php?tab=active'>Go back to My Orders page</a> to see your active orders.</p>";
?>
