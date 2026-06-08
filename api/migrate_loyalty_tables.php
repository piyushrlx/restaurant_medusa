<?php
/**
 * ══════════════════════════════════════════════════════════════
 *  MEDUSA RESTAURANT — CUSTOMER LOYALTY DATABASE MIGRATION SCRIPT
 *  Creates and seeds all tables required for tiers & reward points.
 * ══════════════════════════════════════════════════════════════
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain');

echo "── STARTING LOYALTY SCHEMAS MIGRATION ──\n\n";

try {
    // ── 1. Create Customer Tiers Table ──
    echo "Creating `customer_tiers` table if not exists...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `customer_tiers` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tier_name` VARCHAR(50) NOT NULL UNIQUE,
            `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
            `spending_requirement` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `points_earning_percent` DECIMAL(5,2) NOT NULL DEFAULT 2.00
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ Table `customer_tiers` is set up.\n";

    // Seed default tiers if empty
    $count = $pdo->query("SELECT COUNT(*) FROM `customer_tiers`")->fetchColumn();
    if ($count == 0) {
        $pdo->exec("
            INSERT INTO `customer_tiers` (id, tier_name, discount_percent, spending_requirement, points_earning_percent) VALUES
            (1, 'Silver', 10.00, 0.00, 2.00),
            (2, 'Gold', 15.00, 25000.00, 2.00),
            (3, 'Platinum', 20.00, 75000.00, 2.00)
        ");
        echo "✓ Seeded default tiers: Silver, Gold, Platinum.\n";
    } else {
        echo "• Default tiers already seeded.\n";
    }

    // ── 2. Create Reward Points Table ──
    echo "\nCreating `reward_points` table if not exists...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `reward_points` (
            `user_id` INT PRIMARY KEY,
            `points_earned` INT DEFAULT 0,
            `points_redeemed` INT DEFAULT 0,
            `points_deducted` INT DEFAULT 0,
            `current_balance` INT DEFAULT 0,
            CONSTRAINT `fk_rewards_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ Table `reward_points` is set up.\n";

    // ── 3. Create Tier History Table ──
    echo "\nCreating `tier_history` table if not exists...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tier_history` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `previous_tier_id` INT NOT NULL,
            `new_tier_id` INT NOT NULL,
            `change_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `reason` VARCHAR(255) NOT NULL,
            CONSTRAINT `fk_history_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ Table `tier_history` is set up.\n";

    // ── 4. Create Loyalty Transactions Table ──
    echo "\nCreating `loyalty_transactions` table if not exists...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `loyalty_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `order_id` INT DEFAULT NULL,
            `points_earned` INT DEFAULT 0,
            `points_redeemed` INT DEFAULT 0,
            `points_deducted` INT DEFAULT 0,
            `transaction_type` VARCHAR(50) NOT NULL,
            `transaction_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT `fk_transactions_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_transactions_orders` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ Table `loyalty_transactions` is set up.\n";

    // ── 5. Create User Notifications Table ──
    echo "\nCreating `user_notifications` table if not exists...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_notifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `title` VARCHAR(100) NOT NULL,
            `message` TEXT NOT NULL,
            `is_read` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT `fk_notifications_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ Table `user_notifications` is set up.\n";

    // ── 6. Alter Users Table ──
    echo "\nChecking `users` table columns...\n";
    
    // Check current_tier_id
    $check_tier = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'current_tier_id'")->fetch();
    if (!$check_tier) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `current_tier_id` INT DEFAULT 1 AFTER `role`");
        $pdo->exec("ALTER TABLE `users` ADD CONSTRAINT `fk_users_tier` FOREIGN KEY (`current_tier_id`) REFERENCES `customer_tiers` (`id`) ON DELETE SET NULL");
        echo "✓ Added `current_tier_id` column and FK constraint to `users`.\n";
    } else {
        echo "• `current_tier_id` already exists in `users`.\n";
    }

    // Check last_inactivity_check
    $check_inact = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'last_inactivity_check'")->fetch();
    if (!$check_inact) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `last_inactivity_check` DATE DEFAULT NULL AFTER `current_tier_id`");
        echo "✓ Added `last_inactivity_check` column to `users`.\n";
    } else {
        echo "• `last_inactivity_check` already exists in `users`.\n";
    }

    // ── 7. Alter Orders Table ──
    echo "\nChecking `orders` table columns...\n";
    
    // Check tier_discount_amount
    $check_td = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'tier_discount_amount'")->fetch();
    if (!$check_td) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `tier_discount_amount` DECIMAL(10,2) DEFAULT 0.00 AFTER `discount`");
        echo "✓ Added `tier_discount_amount` column to `orders`.\n";
    } else {
        echo "• `tier_discount_amount` already exists in `orders`.\n";
    }

    // Check points_redeemed
    $check_pr = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'points_redeemed'")->fetch();
    if (!$check_pr) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `points_redeemed` INT DEFAULT 0 AFTER `tier_discount_amount`");
        echo "✓ Added `points_redeemed` column to `orders`.\n";
    } else {
        echo "• `points_redeemed` already exists in `orders`.\n";
    }

    // Check points_redeemed_discount
    $check_prd = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'points_redeemed_discount'")->fetch();
    if (!$check_prd) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `points_redeemed_discount` DECIMAL(10,2) DEFAULT 0.00 AFTER `points_redeemed`");
        echo "✓ Added `points_redeemed_discount` column to `orders`.\n";
    } else {
        echo "• `points_redeemed_discount` already exists in `orders`.\n";
    }

    // Check points_earned
    $check_pe = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'points_earned'")->fetch();
    if (!$check_pe) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `points_earned` INT DEFAULT 0 AFTER `points_redeemed_discount`");
        echo "✓ Added `points_earned` column to `orders`.\n";
    } else {
        echo "• `points_earned` already exists in `orders`.\n";
    }

    // ── 8. Initialize Reward Points for existing users ──
    echo "\nInitializing reward points balance records for existing users...\n";
    $users = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $stmt_ins = $pdo->prepare("INSERT IGNORE INTO reward_points (user_id, points_earned, points_redeemed, points_deducted, current_balance) VALUES (?, 0, 0, 0, 0)");
    foreach ($users as $uid) {
        $stmt_ins->execute([$uid]);
    }
    echo "✓ Reward points balances initialized.\n";

    echo "\n── LOYALTY MIGRATION COMPLETED SUCCESSFULLY ──\n";

} catch (Exception $e) {
    echo "\n✗ LOYALTY MIGRATION ERROR: " . $e->getMessage() . "\n";
}
