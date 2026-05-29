-- FoodieRestro / Medusa Restaurant Database Dump
-- Compatible with phpMyAdmin and MySQL/MariaDB
-- Fully Seeded for Client Demo

SET FOREIGN_KEY_CHECKS = 0;

-- Create Database
CREATE DATABASE IF NOT EXISTS `restaurant_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `restaurant_db`;

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `role` enum('customer','admin') DEFAULT 'customer',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Dumping data for table `users`
-- Default Passwords:
-- Admin: admin@example.com / admin123
-- Customer: customer@example.com / customer123
-- Piyush: piyush@example.com / customer123
-- --------------------------------------------------------
INSERT INTO `users` (`id`, `name`, `email`, `password`, `phone`, `role`) VALUES
(1, 'System Admin', 'admin@example.com', '$2y$10$rRRN5vJIWZltYTywQAgxReBWCWazX1HeadB7ktTjAdxGceY8QJQoG', '9876543210', 'admin'),
(2, 'Test Customer', 'customer@example.com', '$2y$10$hdslkG6tN7/j4ja6N63Js.3ImHeNwDwYZ0T5eAhBiqIq7MrGVWHYa', '9876543211', 'customer'),
(3, 'Piyush Sharma', 'piyush@example.com', '$2y$10$hdslkG6tN7/j4ja6N63Js.3ImHeNwDwYZ0T5eAhBiqIq7MrGVWHYa', '9876543212', 'customer');

-- --------------------------------------------------------
-- Table structure for table `food_items`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `food_items`;
CREATE TABLE `food_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT 'default.jpg',
  `is_available` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Dumping data for table `food_items`
-- --------------------------------------------------------
INSERT INTO `food_items` (`id`, `name`, `description`, `price`, `category`, `image_url`, `is_available`) VALUES
(1, 'Butter Chicken', 'Tender chicken simmered in a rich, creamy tomato gravy with aromatic spices and finished with butter.', '450.00', 'indian', 'https://images.unsplash.com/photo-1603894584373-5ac82b2ae398?w=400&h=300&fit=crop', 1),
(2, 'Chicken Biryani', 'Fragrant basmati rice layered with spiced chicken, caramelized onions, and saffron, cooked in dum style.', '350.00', 'indian', 'https://images.unsplash.com/photo-1563379091339-03b21ab4a4f8?w=400&h=300&fit=crop', 1),
(3, 'Dal Makhani', 'Slow-cooked black lentils in a rich, buttery tomato cream sauce, simmered overnight for deep flavor.', '280.00', 'indian', 'https://images.unsplash.com/photo-1585937421612-70a008356fbe?w=400&h=300&fit=crop', 1),
(4, 'Rogan Josh', 'Kashmiri lamb curry with aromatic spices, saffron, and dried ginger - a royal delicacy.', '420.00', 'indian', 'https://images.unsplash.com/photo-1585937421612-70a008356fbe?w=400&h=300&fit=crop', 1),
(5, 'Palak Paneer', 'Cubes of cottage cheese in a smooth, spiced spinach gravy with garlic and ginger.', '320.00', 'indian', 'https://images.unsplash.com/photo-1631452180519-c014fe946bc7?w=400&h=300&fit=crop', 1),
(6, 'Samosa', 'Crispy golden pastry filled with spiced potatoes and peas, served with mint and tamarind chutney.', '180.00', 'indian', 'https://images.unsplash.com/photo-1601050690597-df0568f70950?w=400&h=300&fit=crop', 1),
(7, 'Garlic Naan', 'Soft, leavened bread baked in tandoor, topped with garlic butter and fresh coriander.', '80.00', 'indian', 'https://images.unsplash.com/photo-1565557623262-b51c2513a641?w=400&h=300&fit=crop', 1),
(8, 'Margherita Pizza', 'Classic wood-fired pizza with San Marzano tomato sauce, fresh mozzarella, basil, and olive oil.', '350.00', 'italian', 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=400&h=300&fit=crop', 1),
(9, 'Pepperoni Pizza', 'Loaded with spicy pepperoni, mozzarella cheese, and our signature tomato sauce on a crispy crust.', '420.00', 'italian', 'https://images.unsplash.com/photo-1628840042765-356cda07504e?w=400&h=300&fit=crop', 1),
(10, 'Pasta Alfredo', 'Creamy fettuccine Alfredo with parmesan, garlic, and butter. Rich, velvety, and indulgent.', '380.00', 'italian', 'https://images.unsplash.com/photo-1621996346565-e3dbc646d9a9?w=400&h=300&fit=crop', 1),
(11, 'Pasta Arrabiata', 'Spicy tomato-based pasta with garlic, chili flakes, and fresh herbs. Bold and fiery.', '350.00', 'italian', 'https://images.unsplash.com/photo-1621996346565-e3dbc646d9a9?w=400&h=300&fit=crop', 1),
(12, 'Classic Lasagna', 'Layers of pasta, bolognese sauce, bechamel, mozzarella, and parmesan, baked to perfection.', '450.00', 'italian', 'https://images.unsplash.com/photo-1574894709920-11b28e7367e3?w=400&h=300&fit=crop', 1),
(13, 'Bruschetta', 'Toasted sourdough topped with fresh tomatoes, basil, garlic, olive oil, and balsamic glaze.', '250.00', 'italian', 'https://images.unsplash.com/photo-1572695157366-5e585ab2b69f?w=400&h=300&fit=crop', 1),
(14, 'Sushi Roll (8 pcs)', 'Fresh maki rolls with seasoned rice, nori, and premium fillings. Served with soy, wasabi, and ginger.', '550.00', 'asian', 'https://images.unsplash.com/photo-1579871494447-9811cf80d66c?w=400&h=300&fit=crop', 1),
(15, 'Pad Thai', 'Stir-fried rice noodles with tamarind sauce, bean sprouts, peanuts, lime, and your choice of protein.', '380.00', 'asian', 'https://images.unsplash.com/photo-1559314809-0d155014e29e?w=400&h=300&fit=crop', 1),
(16, 'Dim Sum (6 pcs)', 'Steamed dumplings with delicate filling, served with soy-chili dipping sauce.', '320.00', 'asian', 'https://images.unsplash.com/photo-1563245372-f21724e3856d?w=400&h=300&fit=crop', 1),
(17, 'Ramen Bowl', 'Rich tonkotsu broth with ramen noodles, chashu pork, soft-boiled egg, nori, and scallions.', '420.00', 'asian', 'https://images.unsplash.com/photo-1569718212165-3a8278d5f624?w=400&h=300&fit=crop', 1),
(18, 'Spring Rolls (4 pcs)', 'Crispy rolls stuffed with vegetables or meat, served with sweet chili dipping sauce.', '220.00', 'asian', 'https://images.unsplash.com/photo-1604909052743-94e838986d24?w=400&h=300&fit=crop', 1),
(19, 'Classic Burger', 'Juicy grilled patty with fresh lettuce, tomato, onion, cheese, and our secret sauce in a toasted bun.', '350.00', 'american', 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=400&h=300&fit=crop', 1),
(20, 'BBQ Ribs', 'Slow-cooked pork ribs glazed with smoky BBQ sauce, served with coleslaw and fries.', '550.00', 'american', 'https://images.unsplash.com/photo-1544025162-d76694265947?w=400&h=300&fit=crop', 1),
(21, 'Mac & Cheese', 'Creamy elbow macaroni in a blend of cheddar, mozzarella, and parmesan with a crispy breadcrumb top.', '320.00', 'american', 'https://images.unsplash.com/photo-1543339494-b4cd4f7ba686?w=400&h=300&fit=crop', 1),
(22, 'Chicken Wings (6 pcs)', 'Crispy fried chicken wings tossed in your choice of sauce. Served with ranch or blue cheese dip.', '380.00', 'american', 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=400&h=300&fit=crop', 1),
(23, 'Club Sandwich', 'Triple-decker sandwich with roasted turkey, bacon, lettuce, tomato, and mayo on toasted bread.', '320.00', 'american', 'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?w=400&h=300&fit=crop', 1),
(24, 'Gulab Jamun (3 pcs)', 'Deep-fried milk solid dumplings soaked in rose-scented sugar syrup. Warm, soft, and irresistible.', '150.00', 'desserts', 'https://images.unsplash.com/photo-1601050690597-df0568f70950?w=400&h=300&fit=crop', 1),
(25, 'Tiramisu', 'Classic Italian dessert with layers of coffee-soaked ladyfingers, mascarpone cream, and cocoa.', '320.00', 'desserts', 'https://images.unsplash.com/photo-1571877227200-a0d98ea607e9?w=400&h=300&fit=crop', 1),
(26, 'Ice Cream Sundae', 'Three scoops of premium ice cream with hot fudge, caramel, whipped cream, and a cherry on top.', '220.00', 'desserts', 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=400&h=300&fit=crop', 1),
(27, 'Mochi Ice Cream (3 pcs)', 'Chewy Japanese rice dough wrapped around creamy ice cream. Available in assorted flavors.', '280.00', 'desserts', 'https://images.unsplash.com/photo-1551024506-0bccd828d307?w=400&h=300&fit=crop', 1),
(28, 'New York Cheesecake', 'Silky cream cheese filling on a buttery graham cracker crust, baked to perfection.', '350.00', 'desserts', 'https://images.unsplash.com/photo-1482049016688-2d3e1b311543?w=400&h=300&fit=crop', 1),
(29, 'Chocolate Lava Cake', 'Warm chocolate cake with a molten center, served with vanilla ice cream and chocolate shavings.', '380.00', 'desserts', 'https://images.unsplash.com/photo-1565958011703-44f9829ba187?w=400&h=300&fit=crop', 1);

-- --------------------------------------------------------
-- Table structure for table `orders`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(20) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `customer_phone` varchar(15) DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `order_status` varchar(20) DEFAULT 'pending',
  `order_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_orders_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Dumping data for table `orders`
-- Seeding: 7 days of completed orders for revenue metrics, and some active table orders
-- --------------------------------------------------------
INSERT INTO `orders` (`id`, `order_number`, `customer_name`, `customer_phone`, `delivery_address`, `total_amount`, `order_status`, `order_date`, `user_id`) VALUES
(1, 'ORD-A1010', 'Rahul Verma', '9876543201', '123, Golf Green, Kolkata - 700032', '1280.00', 'completed', '2026-05-21 14:30:00', 2),
(2, 'ORD-A1011', 'Neha Sen', '9876543202', 'Table T02', '670.00', 'completed', '2026-05-21 19:15:00', 3),
(3, 'ORD-A1012', 'Amit Patel', '9876543203', 'Sector 5, Salt Lake, Kolkata - 700091', '1520.00', 'completed', '2026-05-22 13:00:00', 2),
(4, 'ORD-A1013', 'Suresh Kumar', '9876543204', 'Table A02', '930.00', 'completed', '2026-05-22 20:30:00', NULL),
(5, 'ORD-A1014', 'Vikram Singh', '9876543205', 'Park Street, Kolkata - 700016', '2450.00', 'completed', '2026-05-23 12:45:00', 3),
(6, 'ORD-A1015', 'Preeti Bose', '9876543206', 'Table G03', '480.00', 'completed', '2026-05-23 21:00:00', NULL),
(7, 'ORD-A1016', 'Rohan Gupta', '9876543207', 'Lake Gardens, Kolkata - 700045', '1100.00', 'completed', '2026-05-24 13:30:00', 2),
(8, 'ORD-A1017', 'Deepak Sen', '9876543208', 'Table B01', '750.00', 'completed', '2026-05-24 18:45:00', NULL),
(9, 'ORD-A1018', 'Pooja Roy', '9876543209', 'New Town, Kolkata - 700156', '1680.00', 'completed', '2026-05-25 15:20:00', 3),
(10, 'ORD-A1019', 'Ravi Shankar', '9876543220', 'Table T05', '840.00', 'completed', '2026-05-25 21:40:00', NULL),
(11, 'ORD-A1020', 'Piyush Sharma', '9876543212', 'Bidhannagar, Kolkata - 700064', '1350.00', 'completed', '2026-05-26 12:15:00', 3),
(12, 'ORD-A1021', 'Ananya Ray', '9876543221', 'Table RD2', '900.00', 'completed', '2026-05-26 20:10:00', NULL),
(13, 'ORD-A1022', 'Karan Johar', '9876543222', 'Ballygunge, Kolkata - 700019', '2150.00', 'completed', '2026-05-27 11:30:00', 2),
(14, 'ORD-A1023', 'Test Customer', '9876543211', 'Table T01', '800.00', 'preparing', '2026-05-27 12:00:00', 2),
(15, 'ORD-A1024', 'Arjun Kapoor', '9876543223', 'Table A03', '770.00', 'pending', '2026-05-27 12:15:00', NULL),
(16, 'ORD-A1025', 'Janhvi Kapoor', '9876543224', 'Alipore, Kolkata - 700027', '950.00', 'ready', '2026-05-27 12:20:00', NULL),
(17, 'ORD-A1026', 'Piyush Sharma', '9876543212', 'Table G02', '1260.00', 'preparing', '2026-05-27 12:25:00', 3);

-- --------------------------------------------------------
-- Table structure for table `order_items`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `order_items`;
CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) DEFAULT NULL,
  `food_item_id` int(11) DEFAULT NULL,
  `item_name` varchar(100) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `food_item_id` (`food_item_id`),
  CONSTRAINT `fk_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_items_food` FOREIGN KEY (`food_item_id`) REFERENCES `food_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Dumping data for table `order_items`
-- --------------------------------------------------------
INSERT INTO `order_items` (`id`, `order_id`, `food_item_id`, `item_name`, `quantity`, `price`) VALUES
(1, 1, 1, 'Butter Chicken', 2, '450.00'),
(2, 1, 7, 'Garlic Naan', 4, '80.00'),
(3, 1, 6, 'Samosa', 1, '180.00'),
(4, 2, 8, 'Margherita Pizza', 1, '350.00'),
(5, 2, 25, 'Tiramisu', 1, '320.00'),
(6, 3, 14, 'Sushi Roll (8 pcs)', 2, '550.00'),
(7, 3, 17, 'Ramen Bowl', 1, '420.00'),
(8, 4, 19, 'Classic Burger', 2, '350.00'),
(9, 4, 22, 'Chicken Wings (6 pcs)', 1, '380.00'),
(10, 5, 20, 'BBQ Ribs', 3, '550.00'),
(11, 5, 21, 'Mac & Cheese', 2, '320.00'),
(12, 5, 29, 'Chocolate Lava Cake', 2, '380.00'),
(13, 6, 15, 'Pad Thai', 1, '380.00'),
(14, 6, 7, 'Garlic Naan', 1, '80.00'),
(15, 6, 24, 'Gulab Jamun (3 pcs)', 1, '150.00'),
(16, 7, 2, 'Chicken Biryani', 2, '350.00'),
(17, 7, 17, 'Ramen Bowl', 1, '420.00'),
(18, 8, 10, 'Pasta Alfredo', 1, '380.00'),
(19, 8, 25, 'Tiramisu', 1, '320.00'),
(20, 9, 8, 'Margherita Pizza', 2, '350.00'),
(21, 9, 9, 'Pepperoni Pizza', 1, '420.00'),
(22, 9, 12, 'Classic Lasagna', 1, '450.00'),
(23, 10, 1, 'Butter Chicken', 1, '450.00'),
(24, 10, 24, 'Gulab Jamun (3 pcs)', 1, '150.00'),
(25, 10, 7, 'Garlic Naan', 2, '80.00'),
(26, 11, 2, 'Chicken Biryani', 3, '350.00'),
(27, 11, 26, 'Ice Cream Sundae', 1, '220.00'),
(28, 12, 16, 'Dim Sum (6 pcs)', 2, '320.00'),
(29, 12, 13, 'Bruschetta', 1, '250.00'),
(30, 13, 20, 'BBQ Ribs', 2, '550.00'),
(31, 13, 22, 'Chicken Wings (6 pcs)', 2, '380.00'),
(32, 13, 28, 'New York Cheesecake', 1, '350.00'),
(33, 14, 1, 'Butter Chicken', 1, '450.00'),
(34, 14, 2, 'Chicken Biryani', 1, '350.00'),
(35, 15, 10, 'Pasta Alfredo', 1, '380.00'),
(36, 15, 11, 'Pasta Arrabiata', 1, '350.00'),
(37, 15, 7, 'Garlic Naan', 1, '80.00'),
(38, 16, 19, 'Classic Burger', 1, '350.00'),
(39, 16, 21, 'Mac & Cheese', 1, '320.00'),
(40, 16, 28, 'New York Cheesecake', 1, '350.00'),
(41, 17, 14, 'Sushi Roll (8 pcs)', 1, '550.00'),
(42, 17, 15, 'Pad Thai', 1, '380.00'),
(43, 17, 27, 'Mochi Ice Cream (3 pcs)', 1, '280.00');

-- --------------------------------------------------------
-- Table structure for table `reservations`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `reservations`;
CREATE TABLE `reservations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_name` varchar(255) NOT NULL,
  `customer_phone` varchar(20) NOT NULL,
  `guests` int(11) NOT NULL,
  `reservation_date` date NOT NULL,
  `reservation_time` time NOT NULL,
  `special_request` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Dumping data for table `reservations`
-- --------------------------------------------------------
INSERT INTO `reservations` (`id`, `customer_name`, `customer_phone`, `guests`, `reservation_date`, `reservation_time`, `special_request`, `status`) VALUES
(1, 'Vikram Malhotra', '9876543230', 4, '2026-05-27', '19:30:00', 'Window seat preferred', 'confirmed'),
(2, 'Shreya Ghoshal', '9876543231', 2, '2026-05-27', '21:00:00', 'Birthday celebration', 'pending'),
(3, 'Abhishek Bachchan', '9876543232', 8, '2026-05-28', '20:00:00', 'Quiet booth area', 'confirmed'),
(4, 'Ranbir Kapoor', '9876543233', 2, '2026-05-28', '22:30:00', 'Vegetarian food menu only', 'pending');

-- --------------------------------------------------------
-- Table structure for table `user_addresses`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `user_addresses`;
CREATE TABLE `user_addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `email` varchar(100) NOT NULL,
  `country` varchar(100) NOT NULL,
  `street` varchar(255) NOT NULL,
  `apartment` varchar(255) DEFAULT NULL,
  `city` varchar(100) NOT NULL,
  `state` varchar(100) NOT NULL,
  `zip` varchar(20) NOT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_addresses_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for table `cart`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `cart`;
CREATE TABLE `cart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `food_item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `food_item_id` (`food_item_id`),
  CONSTRAINT `fk_cart_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cart_food` FOREIGN KEY (`food_item_id`) REFERENCES `food_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Dumping data for table `cart`
-- --------------------------------------------------------
INSERT INTO `cart` (`id`, `user_id`, `food_item_id`, `quantity`) VALUES
(1, 2, 8, 1),
(2, 2, 25, 2),
(3, 3, 1, 1);

-- --------------------------------------------------------
-- Table structure for table `dish_customizations`
-- Stores customization groups and their options for each food item
-- Admin can enable/disable customizations per dish
-- --------------------------------------------------------
DROP TABLE IF EXISTS `dish_customizations`;
CREATE TABLE `dish_customizations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `food_item_id` int(11) NOT NULL,
  `group_name` varchar(100) NOT NULL COMMENT 'e.g. Crust Type, Size, Toppings',
  `group_type` enum('single','multiple') DEFAULT 'single' COMMENT 'single=radio, multiple=checkbox',
  `is_required` tinyint(1) DEFAULT 0 COMMENT '1=must pick an option, 0=optional',
  `options_json` text NOT NULL COMMENT 'JSON array: [{label, price_add}]',
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `food_item_id` (`food_item_id`),
  CONSTRAINT `fk_customization_food` FOREIGN KEY (`food_item_id`) REFERENCES `food_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Sample Customization Data
-- Pizzas: Size + Crust + Toppings
-- Burgers: Patty + Extras
-- Asian: Spice Level
-- --------------------------------------------------------
INSERT INTO `dish_customizations` (`food_item_id`, `group_name`, `group_type`, `is_required`, `options_json`, `sort_order`) VALUES
-- Margherita Pizza (id=8)
(8, 'Pizza Size', 'single', 1, '[{"label":"Regular (8\")","price_add":0},{"label":"Medium (10\")","price_add":80},{"label":"Large (12\")","price_add":150}]', 1),
(8, 'Crust Type', 'single', 1, '[{"label":"Thin Crust","price_add":0},{"label":"Thick Crust","price_add":30},{"label":"Stuffed Cheese Crust","price_add":70}]', 2),
(8, 'Extra Toppings', 'multiple', 0, '[{"label":"Extra Mozzarella","price_add":60},{"label":"Mushrooms","price_add":40},{"label":"Olives","price_add":40},{"label":"Jalapeños","price_add":30}]', 3),
-- Pepperoni Pizza (id=9)
(9, 'Pizza Size', 'single', 1, '[{"label":"Regular (8\")","price_add":0},{"label":"Medium (10\")","price_add":80},{"label":"Large (12\")","price_add":150}]', 1),
(9, 'Crust Type', 'single', 1, '[{"label":"Thin Crust","price_add":0},{"label":"Thick Crust","price_add":30},{"label":"Stuffed Cheese Crust","price_add":70}]', 2),
(9, 'Extra Toppings', 'multiple', 0, '[{"label":"Double Pepperoni","price_add":80},{"label":"Extra Mozzarella","price_add":60},{"label":"Bell Peppers","price_add":40},{"label":"Jalapeños","price_add":30}]', 3),
-- Classic Burger (id=19)
(19, 'Patty Type', 'single', 1, '[{"label":"Chicken Patty","price_add":0},{"label":"Beef Patty","price_add":50},{"label":"Veggie Patty","price_add":-30}]', 1),
(19, 'Serving Size', 'single', 1, '[{"label":"Single","price_add":0},{"label":"Double","price_add":100}]', 2),
(19, 'Add-ons', 'multiple', 0, '[{"label":"Extra Cheese","price_add":40},{"label":"Bacon Strip","price_add":60},{"label":"Fried Egg","price_add":40},{"label":"Avocado","price_add":50}]', 3),
-- Ramen Bowl (id=17)
(17, 'Broth Base', 'single', 1, '[{"label":"Tonkotsu (Pork)","price_add":0},{"label":"Shoyu (Soy)","price_add":0},{"label":"Vegan Miso","price_add":0}]', 1),
(17, 'Spice Level', 'single', 1, '[{"label":"Mild","price_add":0},{"label":"Medium","price_add":0},{"label":"Hot 🌶","price_add":0},{"label":"Extra Hot 🌶🌶","price_add":0}]', 2),
(17, 'Add-ons', 'multiple', 0, '[{"label":"Extra Chashu Pork","price_add":80},{"label":"Extra Egg","price_add":30},{"label":"Extra Noodles","price_add":40}]', 3),
-- Pad Thai (id=15)
(15, 'Protein Choice', 'single', 1, '[{"label":"Chicken","price_add":0},{"label":"Prawns","price_add":80},{"label":"Tofu (Veg)","price_add":-30}]', 1),
(15, 'Spice Level', 'single', 0, '[{"label":"Mild","price_add":0},{"label":"Medium","price_add":0},{"label":"Hot 🌶","price_add":0}]', 2),
-- Chicken Wings (id=22)
(22, 'Sauce Choice', 'single', 1, '[{"label":"Buffalo Hot","price_add":0},{"label":"BBQ Smoky","price_add":0},{"label":"Honey Garlic","price_add":0},{"label":"Lemon Pepper","price_add":0}]', 1),
(22, 'Serving Size', 'single', 1, '[{"label":"6 Pcs","price_add":0},{"label":"12 Pcs","price_add":350},{"label":"18 Pcs","price_add":680}]', 2),
-- Ice Cream Sundae (id=26)
(26, 'Flavor', 'single', 1, '[{"label":"Vanilla","price_add":0},{"label":"Chocolate","price_add":0},{"label":"Strawberry","price_add":0},{"label":"Mixed Berry","price_add":0}]', 1),
(26, 'Toppings', 'multiple', 0, '[{"label":"Hot Fudge","price_add":30},{"label":"Caramel Drizzle","price_add":30},{"label":"Crushed Oreo","price_add":40},{"label":"Rainbow Sprinkles","price_add":20}]', 2),
-- Mochi Ice Cream (id=27)
(27, 'Flavor Selection', 'single', 1, '[{"label":"Strawberry","price_add":0},{"label":"Matcha Green Tea","price_add":0},{"label":"Mango","price_add":0},{"label":"Cookies & Cream","price_add":0}]', 1);

SET FOREIGN_KEY_CHECKS = 1;

