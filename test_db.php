<?php
require_once __DIR__ . '/api/config.php';
$stmt = $pdo->query("DESCRIBE food_items");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} - {$row['Type']} - {$row['Null']} - {$row['Key']} - {$row['Default']}\n";
}
?>
