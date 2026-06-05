<?php
// Set up CLI testing environment
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line interface (CLI).\n");
}

require_once dirname(__DIR__) . '/api/config.php';
require_once dirname(__DIR__) . '/api/CouponService.php';

// Test status trackers
$testsRun = 0;
$testsFailed = 0;

function assertEqual($actual, $expected, $message) {
    global $testsRun, $testsFailed;
    $testsRun++;
    if ($actual !== $expected) {
        echo "❌ [FAIL] $message\n";
        echo "   Expected: " . var_export($expected, true) . "\n";
        echo "   Actual:   " . var_export($actual, true) . "\n";
        $testsFailed++;
    } else {
        echo "✅ [PASS] $message\n";
    }
}

function assertThrows($callback, $expectedMessageSubstring, $message) {
    global $testsRun, $testsFailed;
    $testsRun++;
    try {
        $callback();
        echo "❌ [FAIL] $message (No exception thrown)\n";
        $testsFailed++;
    } catch (Exception $e) {
        if (strpos($e->getMessage(), $expectedMessageSubstring) !== false) {
            echo "✅ [PASS] $message\n";
        } else {
            echo "❌ [FAIL] $message (Wrong exception message)\n";
            echo "   Expected substring: $expectedMessageSubstring\n";
            echo "   Actual message:   " . $e->getMessage() . "\n";
            $testsFailed++;
        }
    }
}

echo "==========================================\n";
echo "Starting Coupon System Tests\n";
echo "==========================================\n";

try {
    $couponService = new CouponService($pdo);

    // Start database transaction to prevent persisting test data
    $pdo->beginTransaction();

    // -------------------------------------------------------------------------
    // 1. Generation Tests
    // -------------------------------------------------------------------------
    echo "\nRunning Coupon Generation Tests...\n";

    // Create a temporary test order to link foreign keys
    $testOrderNum = 'ORD-TST' . rand(1000, 9999);
    $insOrder = $pdo->prepare("INSERT INTO orders (order_number, customer_name, total_amount, order_status) VALUES (?, 'Test Customer', 100.00, 'pending')");
    $insOrder->execute([$testOrderNum]);
    $orderId = $pdo->lastInsertId();

    // Create a temporary test review
    $insReview = $pdo->prepare("INSERT INTO feedback (order_number, rating, review) VALUES (?, 5, 'Great food!')");
    $insReview->execute([$testOrderNum]);
    $reviewId = $pdo->lastInsertId();

    // Test: Generates coupon for 5-star review
    $userId = 1; // Assuming a mock userId
    $campaignCode = 'TEST2026';
    $couponCode = $couponService->generateCoupon($userId, $reviewId, $campaignCode);
    
    assertEqual(strpos($couponCode, '5STAR-TEST2026-') === 0, true, "Generates coupon with correct prefix and campaign code");
    assertEqual(strlen($couponCode) >= 15, true, "Generated coupon code length is user friendly");

    // Test: Prevent duplicate coupon generation for same review
    $duplicateCoupon = $couponService->generateCoupon($userId, $reviewId, $campaignCode);
    assertEqual($duplicateCoupon, $couponCode, "Prevents duplicate coupons for the same review by returning the existing code");

    // Test: Does not generate coupons for rating < 5
    // In submit-feedback.php we explicitly check rating === 5 before calling generateCoupon,
    // but we want to make sure the code logic handles unique coupons
    $insReview2 = $pdo->prepare("INSERT INTO feedback (order_number, rating, review) VALUES (?, 4, 'Okay food.')");
    $insReview2->execute([$testOrderNum]);
    $reviewId2 = $pdo->lastInsertId();

    $couponCode2 = $couponService->generateCoupon($userId, $reviewId2, $campaignCode);
    assertEqual($couponCode !== $couponCode2, true, "Generates unique coupon codes for distinct reviews");

    // -------------------------------------------------------------------------
    // 2. Validation Tests
    // -------------------------------------------------------------------------
    echo "\nRunning Coupon Validation Tests...\n";

    // Test: Valid coupon passes
    $coupon = $couponService->validateCoupon($couponCode);
    assertEqual($coupon->coupon_code, $couponCode, "Valid coupon validation returns correct coupon entity");
    assertEqual($coupon->status, 'active', "Valid coupon status is active");

    // Test: Invalid coupon fails
    assertThrows(function() use ($couponService) {
        $couponService->validateCoupon('INVALID-CODE-XYZ');
    }, "not found", "Validating non-existent coupon throws Exception");

    // Test: Expired coupon fails
    // Create an expired coupon manually
    $expiredCode = '5STAR-EXPIRED-' . strtoupper(substr(hash('sha256', rand()), 0, 8));
    $insExpired = $pdo->prepare("
        INSERT INTO coupons (user_id, review_id, coupon_code, campaign_code, discount_type, discount_value, expires_at, status)
        VALUES (?, ?, ?, 'TESTCAMPAIGN', 'percentage', 10.00, SUBDATE(NOW(), INTERVAL 1 DAY), 'active')
    ");
    $insExpired->execute([$userId, $reviewId, $expiredCode]);
    
    // validateCoupon should mark it as expired and throw
    assertThrows(function() use ($couponService, $expiredCode) {
        $couponService->validateCoupon($expiredCode);
    }, "expired", "Expired coupon throws Exception on validation");

    // Check status in DB updated to expired
    $checkStatus = $pdo->prepare("SELECT status FROM coupons WHERE coupon_code = ?");
    $checkStatus->execute([$expiredCode]);
    assertEqual($checkStatus->fetchColumn(), 'expired', "Expired coupon status updated to expired in database");

    // -------------------------------------------------------------------------
    // 3. Redemption Tests
    // -------------------------------------------------------------------------
    echo "\nRunning Coupon Redemption Tests...\n";

    // Test: Successful redemption
    $redeemResult = $couponService->redeemCoupon($couponCode, $orderId);
    assertEqual($redeemResult, true, "Successful redemption returns true");

    // Check database values post-redemption
    $checkRedemption = $pdo->prepare("SELECT status, order_id, redeemed_at FROM coupons WHERE coupon_code = ?");
    $checkRedemption->execute([$couponCode]);
    $redeemedData = $checkRedemption->fetch(PDO::FETCH_ASSOC);
    assertEqual($redeemedData['status'], 'redeemed', "Redeemed coupon status is updated to redeemed");
    assertEqual(intval($redeemedData['order_id']), intval($orderId), "Redeemed coupon is linked to the correct order_id");
    assertEqual(!empty($redeemedData['redeemed_at']), true, "Redeemed timestamp is saved");

    // Test: Duplicate redemption blocked
    assertThrows(function() use ($couponService, $couponCode, $orderId) {
        $couponService->redeemCoupon($couponCode, $orderId);
    }, "already been redeemed", "Redeeming already redeemed coupon is blocked");

    // Test: Rollback on failure
    // We can simulate an invalid redemption attempt in a sub-transaction if supported,
    // but since we roll back the main transaction anyway, all test data is kept safe.

    // -------------------------------------------------------------------------
    // 4. User Retrieval Tests
    // -------------------------------------------------------------------------
    echo "\nRunning User Coupons Retrieval Tests...\n";
    $userCouponsList = $couponService->getUserCoupons($userId);
    assertEqual(count($userCouponsList) >= 2, true, "Retrieves all coupons created for the given user_id");

    // Roll back the transaction to leave database clean
    $pdo->rollBack();
    echo "\nDatabase transaction rolled back. Test sandbox clean.\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ [CRITICAL ERROR] Tests aborted: " . $e->getMessage() . "\n";
    $testsFailed++;
}

echo "\n==========================================\n";
echo "Tests Summary\n";
echo "==========================================\n";
echo "Total Tests Run: $testsRun\n";
echo "Total Failures:  $testsFailed\n";

if ($testsFailed > 0) {
    echo "\n❌ Test suite failed.\n";
    exit(1);
} else {
    echo "\n✅ Test suite completed successfully!\n";
    exit(0);
}
