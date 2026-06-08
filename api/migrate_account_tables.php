<?php
/**
 * ══════════════════════════════════════════════════════════════
 *  MEDUSA RESTAURANT — CUSTOMER ACCOUNT DATABASE MIGRATION SCRIPT
 *  Checks and creates/alters all tables required for accounts.
 * ══════════════════════════════════════════════════════════════
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain');

echo "── STARTING ACCOUNT SCHEMAS MIGRATION ──\n\n";

try {
    // ── 1. Alter Users Table ──
    echo "Checking `users` table columns...\n";
    
    // Check profile_pic
    $check_pic = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'profile_pic'")->fetch();
    if (!$check_pic) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `profile_pic` VARCHAR(255) DEFAULT NULL AFTER `role`");
        echo "✓ Added `profile_pic` column to `users`.\n";
    } else {
        echo "• `profile_pic` already exists in `users`.\n";
    }

    // Check session_token
    $check_token = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'session_token'")->fetch();
    if (!$check_token) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `session_token` VARCHAR(255) DEFAULT NULL AFTER `profile_pic`");
        echo "✓ Added `session_token` column to `users`.\n";
    } else {
        echo "• `session_token` already exists in `users`.\n";
    }

    // ── 2. Create User Settings Table ──
    echo "\nCreating `user_settings` table if not exists...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_settings` (
            `user_id` INT PRIMARY KEY,
            `email_notifications` TINYINT(1) DEFAULT 1,
            `sms_notifications` TINYINT(1) DEFAULT 1,
            `promotional_offers` TINYINT(1) DEFAULT 1,
            `privacy_mode` TINYINT(1) DEFAULT 0,
            `language` VARCHAR(10) DEFAULT 'en',
            `theme` VARCHAR(10) DEFAULT 'dark',
            CONSTRAINT `fk_user_settings_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ Table `user_settings` is set up.\n";

    // ── 3. Create Login Activity Logs Table ──
    echo "\nCreating `login_activity_logs` table if not exists...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `login_activity_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT,
            `ip_address` VARCHAR(45) NOT NULL,
            `user_agent` TEXT NOT NULL,
            `login_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `status` VARCHAR(20) DEFAULT 'success',
            CONSTRAINT `fk_login_logs_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ Table `login_activity_logs` is set up.\n";

    // ── 4. Create Support Requests Table ──
    echo "\nCreating `support_requests` table if not exists...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `support_requests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT,
            `subject` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `status` VARCHAR(20) DEFAULT 'open',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT `fk_support_requests_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ Table `support_requests` is set up.\n";

    // ── 5. Alter Feedback Table ──
    echo "\nChecking `feedback` table columns...\n";
    
    // Check if feedback table exists, create if not
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `feedback` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_number` VARCHAR(20) NOT NULL,
            `rating` INT NOT NULL,
            `review` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT `fk_feedback_orders` FOREIGN KEY (`order_number`) REFERENCES `orders` (`order_number`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Make order_number NULLable
    $pdo->exec("ALTER TABLE `feedback` MODIFY COLUMN `order_number` VARCHAR(20) NULL");
    echo "✓ Set `order_number` column to NULLable in `feedback`.\n";

    // Add user_id referencing users
    $check_fb_user = $pdo->query("SHOW COLUMNS FROM `feedback` LIKE 'user_id'")->fetch();
    if (!$check_fb_user) {
        $pdo->exec("ALTER TABLE `feedback` ADD COLUMN `user_id` INT NULL AFTER `id`");
        $pdo->exec("ALTER TABLE `feedback` ADD CONSTRAINT `fk_feedback_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL");
        echo "✓ Added `user_id` column and FK constraint to `feedback`.\n";
    } else {
        echo "• `user_id` already exists in `feedback`.\n";
    }

    // Add type column
    $check_fb_type = $pdo->query("SHOW COLUMNS FROM `feedback` LIKE 'type'")->fetch();
    if (!$check_fb_type) {
        $pdo->exec("ALTER TABLE `feedback` ADD COLUMN `type` VARCHAR(50) DEFAULT 'order' AFTER `review`");
        echo "✓ Added `type` column to `feedback`.\n";
    } else {
        echo "• `type` already exists in `feedback`.\n";
    }

    echo "\n── MIGRATION COMPLETED SUCCESSFULLY ──\n";

} catch (Exception $e) {
    echo "\n✗ MIGRATION ERROR: " . $e->getMessage() . "\n";
}
