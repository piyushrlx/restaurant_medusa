<?php
require_once __DIR__ . '/api/config.php';
try {
    $stmt = $pdo->prepare("UPDATE food_items SET name = ?, description = ?, price = ?, category = ?, image_url = ? WHERE id = ?");
    $stmt->execute(['Tandoori Roti Test', 'Plain / butter', 45.00, 'Bread Basket', 'https://chatgpt.com/s/m_6a240bd93cd88191bd5b31ef4', 1]);
    echo "Success! Affected rows: " . $stmt->rowCount() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
