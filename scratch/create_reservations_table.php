<?php
$conn = new mysqli('localhost', 'root', '', 'restaurant_db');

$sql = "CREATE TABLE IF NOT EXISTS `table_reservations` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `table_code` varchar(20) NOT NULL,
    `customer_name` varchar(100) NOT NULL,
    `customer_phone` varchar(15) NOT NULL,
    `reservation_time` datetime NOT NULL,
    `status` varchar(20) DEFAULT 'active' COMMENT 'active, completed, cancelled',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

if ($conn->query($sql) === TRUE) {
    echo "Table table_reservations created successfully";
} else {
    echo "Error creating table: " . $conn->error;
}
$conn->close();
?>
