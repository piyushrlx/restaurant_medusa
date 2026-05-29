<?php
// One-time setup script: creates dish_customizations table and inserts sample data
// Run once at: http://localhost/test/scratch/setup_customizations.php
// DELETE this file after running!

$host     = 'localhost';
$dbname   = 'restaurant_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage());
}

$sql = "
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `dish_customizations`;
CREATE TABLE `dish_customizations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `food_item_id` int(11) NOT NULL,
  `group_name` varchar(100) NOT NULL,
  `group_type` enum('single','multiple') DEFAULT 'single',
  `is_required` tinyint(1) DEFAULT 0,
  `options_json` text NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `food_item_id` (`food_item_id`),
  CONSTRAINT `fk_customization_food` FOREIGN KEY (`food_item_id`) REFERENCES `food_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `dish_customizations` (`food_item_id`, `group_name`, `group_type`, `is_required`, `options_json`, `sort_order`) VALUES
(8,  'Pizza Size',      'single',   1, '[{\"label\":\"Regular (8\\\")\",\"price_add\":0},{\"label\":\"Medium (10\\\")\",\"price_add\":80},{\"label\":\"Large (12\\\")\",\"price_add\":150}]', 1),
(8,  'Crust Type',      'single',   1, '[{\"label\":\"Thin Crust\",\"price_add\":0},{\"label\":\"Thick Crust\",\"price_add\":30},{\"label\":\"Stuffed Cheese Crust\",\"price_add\":70}]', 2),
(8,  'Extra Toppings',  'multiple', 0, '[{\"label\":\"Extra Mozzarella\",\"price_add\":60},{\"label\":\"Mushrooms\",\"price_add\":40},{\"label\":\"Olives\",\"price_add\":40},{\"label\":\"Jalapeños\",\"price_add\":30}]', 3),
(9,  'Pizza Size',      'single',   1, '[{\"label\":\"Regular (8\\\")\",\"price_add\":0},{\"label\":\"Medium (10\\\")\",\"price_add\":80},{\"label\":\"Large (12\\\")\",\"price_add\":150}]', 1),
(9,  'Crust Type',      'single',   1, '[{\"label\":\"Thin Crust\",\"price_add\":0},{\"label\":\"Thick Crust\",\"price_add\":30},{\"label\":\"Stuffed Cheese Crust\",\"price_add\":70}]', 2),
(9,  'Extra Toppings',  'multiple', 0, '[{\"label\":\"Double Pepperoni\",\"price_add\":80},{\"label\":\"Extra Mozzarella\",\"price_add\":60},{\"label\":\"Bell Peppers\",\"price_add\":40},{\"label\":\"Jalapeños\",\"price_add\":30}]', 3),
(19, 'Patty Type',      'single',   1, '[{\"label\":\"Chicken Patty\",\"price_add\":0},{\"label\":\"Beef Patty\",\"price_add\":50},{\"label\":\"Veggie Patty\",\"price_add\":-30}]', 1),
(19, 'Serving Size',    'single',   1, '[{\"label\":\"Single\",\"price_add\":0},{\"label\":\"Double\",\"price_add\":100}]', 2),
(19, 'Add-ons',         'multiple', 0, '[{\"label\":\"Extra Cheese\",\"price_add\":40},{\"label\":\"Bacon Strip\",\"price_add\":60},{\"label\":\"Fried Egg\",\"price_add\":40},{\"label\":\"Avocado\",\"price_add\":50}]', 3),
(17, 'Broth Base',      'single',   1, '[{\"label\":\"Tonkotsu (Pork)\",\"price_add\":0},{\"label\":\"Shoyu (Soy)\",\"price_add\":0},{\"label\":\"Vegan Miso\",\"price_add\":0}]', 1),
(17, 'Spice Level',     'single',   1, '[{\"label\":\"Mild\",\"price_add\":0},{\"label\":\"Medium\",\"price_add\":0},{\"label\":\"Hot\",\"price_add\":0},{\"label\":\"Extra Hot\",\"price_add\":0}]', 2),
(17, 'Add-ons',         'multiple', 0, '[{\"label\":\"Extra Chashu Pork\",\"price_add\":80},{\"label\":\"Extra Egg\",\"price_add\":30},{\"label\":\"Extra Noodles\",\"price_add\":40}]', 3),
(15, 'Protein Choice',  'single',   1, '[{\"label\":\"Chicken\",\"price_add\":0},{\"label\":\"Prawns\",\"price_add\":80},{\"label\":\"Tofu (Veg)\",\"price_add\":-30}]', 1),
(15, 'Spice Level',     'single',   0, '[{\"label\":\"Mild\",\"price_add\":0},{\"label\":\"Medium\",\"price_add\":0},{\"label\":\"Hot\",\"price_add\":0}]', 2),
(22, 'Sauce Choice',    'single',   1, '[{\"label\":\"Buffalo Hot\",\"price_add\":0},{\"label\":\"BBQ Smoky\",\"price_add\":0},{\"label\":\"Honey Garlic\",\"price_add\":0},{\"label\":\"Lemon Pepper\",\"price_add\":0}]', 1),
(22, 'Serving Size',    'single',   1, '[{\"label\":\"6 Pcs\",\"price_add\":0},{\"label\":\"12 Pcs\",\"price_add\":350},{\"label\":\"18 Pcs\",\"price_add\":680}]', 2),
(26, 'Flavor',          'single',   1, '[{\"label\":\"Vanilla\",\"price_add\":0},{\"label\":\"Chocolate\",\"price_add\":0},{\"label\":\"Strawberry\",\"price_add\":0},{\"label\":\"Mixed Berry\",\"price_add\":0}]', 1),
(26, 'Toppings',        'multiple', 0, '[{\"label\":\"Hot Fudge\",\"price_add\":30},{\"label\":\"Caramel Drizzle\",\"price_add\":30},{\"label\":\"Crushed Oreo\",\"price_add\":40},{\"label\":\"Rainbow Sprinkles\",\"price_add\":20}]', 2),
(27, 'Flavor Selection','single',   1, '[{\"label\":\"Strawberry\",\"price_add\":0},{\"label\":\"Matcha Green Tea\",\"price_add\":0},{\"label\":\"Mango\",\"price_add\":0},{\"label\":\"Cookies & Cream\",\"price_add\":0}]', 1);

SET FOREIGN_KEY_CHECKS = 1;
";

// Execute each statement separately
$statements = array_filter(array_map('trim', explode(';', $sql)), 'strlen');

$results = [];
$errors  = [];
foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
        $results[] = htmlspecialchars(substr($stmt, 0, 80)) . '... <span style="color:#2ec4b6">✓ OK</span>';
    } catch (PDOException $e) {
        $errors[] = htmlspecialchars(substr($stmt, 0, 80)) . '... <span style="color:#ff6b6b">✗ ' . htmlspecialchars($e->getMessage()) . '</span>';
    }
}

// Verify the table was created
$count = $pdo->query("SELECT COUNT(*) FROM dish_customizations")->fetchColumn();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Customizations Setup</title>
    <style>
        body { font-family: monospace; background:#0a0a0a; color:#f3f3f3; padding:2rem; }
        h1 { color:#dfba86; }
        .ok { color:#2ec4b6; }
        .err { color:#ff6b6b; }
        .log { background:#121212; border:1px solid #222; border-radius:8px; padding:1rem; margin:1rem 0; font-size:0.85rem; line-height:2; }
        .badge { display:inline-block; background:#dfba86; color:#000; padding:0.4rem 1rem; border-radius:20px; font-weight:700; font-size:1.1rem; margin:1rem 0; }
        a { color:#dfba86; }
    </style>
</head>
<body>
    <h1>🍽️ Medusa — Dish Customizations Setup</h1>

    <?php if (!empty($errors)): ?>
        <p class="err">⚠️ Some statements failed:</p>
        <div class="log">
            <?php foreach ($errors as $e) echo "<div>$e</div>"; ?>
        </div>
    <?php endif; ?>

    <div class="log">
        <?php foreach ($results as $r) echo "<div>$r</div>"; ?>
    </div>

    <?php if ((int)$count > 0): ?>
        <div class="badge">✅ Success! <?php echo $count; ?> customization rows inserted.</div>
        <p>The <code>dish_customizations</code> table is live in your database.</p>
        <p><a href="../menutest.html">→ Go to Menu</a> | <a href="../admintest/dashboardtest.php">→ Go to Admin Dashboard</a></p>
        <p style="color:#a09f9f; margin-top:2rem;">⚠️ <strong>Delete this file after setup!</strong> Path: <code>c:\xampp1\htdocs\test\scratch\setup_customizations.php</code></p>
    <?php else: ?>
        <p class="err">❌ Table created but no rows found. Check error logs above.</p>
    <?php endif; ?>
</body>
</html>
