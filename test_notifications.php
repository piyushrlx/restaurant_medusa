<?php
/**
 * ══════════════════════════════════════════════════════════════
 *  MEDUSA RESTAURANT — NOTIFICATIONS SEEDER / TESTER SCRIPT
 *  Run this file via browser or CLI to populate mock notifications.
 * ══════════════════════════════════════════════════════════════
 */
require_once __DIR__ . '/includes/notifications_helper.php';

header('Content-Type: text/plain');

echo "==========================================\n";
echo "MEDUSA NOTIFICATIONS SEEDER\n";
echo "==========================================\n\n";

$mocks = [
    ['order', 'New Order Received', 'Order #1042 containing 2x Medusa Special Burger, 1x Long Island Ice Tea has been placed.'],
    ['payment', 'Payment Successful', 'Online payment of ₹1,450 for Order #1042 was settled successfully via Razorpay.'],
    ['kitchen', 'Kitchen Warning: Out of Stock', 'Ingredient "Avocado" is marked as out-of-stock. 3 menu items affected.'],
    ['reservation', 'New Table Booking', 'Table 4 reserved for Mr. Rohit Sharma on 2026-06-09 at 20:00 (4 guests).'],
    ['staff', 'Staff Shift Started', 'Chef Vance checked in for the evening shift.'],
    ['system', 'Admin Login Detected', 'Administrator logged in from IP 192.168.1.45.'],
    ['order', 'Table 5 Order Call', 'Dine-in Order #1043: 1x Pepperoni Pizza, 2x Garlic Bread.'],
    ['kitchen', 'Special Chef Request', 'Table 5 requested "Extra cheese, no onions" on Pepperoni Pizza.'],
    ['payment', 'Cash Settlement Done', 'Bill for Table 2 (₹2,100) settled by Cash.'],
    ['order', 'Order Cancelled', 'Order #1039 was cancelled by customer.'],
    ['system', 'Backup Completed', 'Automated nightly database backup was successful.'],
    ['reservation', 'Reservation Request', 'Vip Cabin reserved for 6 guests at 21:30 under name of Ms. Ananya.'],
    ['staff', 'Delivery Dispatched', 'Order #1040 handed over to delivery executive.'],
];

foreach ($mocks as $m) {
    $success = addNotification($m[0], $m[1], $m[2]);
    if ($success) {
        echo "✓ Seeded notification [type={$m[0]}]: {$m[1]}\n";
    } else {
        echo "✗ Failed seeding [type={$m[0]}]: {$m[1]}\n";
    }
}

echo "\nSeeding complete! You can open your admin dashboard to verify the UI.\n";
?>
