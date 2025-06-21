<?php


class Database {
    private $host = "localhost";
    private $user = "root";
    private $password = "";
    private $db = "ordering";

    public function opencon() {
        $pdo = new PDO(
            "mysql:host={$this->host};dbname={$this->db}",
            $this->user,
            $this->password
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    public function fetch_pickedup_orders() {
        $con = $this->opencon();
        $orders = [];
        $sql = "SELECT t.transac_id, t.user_id, t.total_amount, t.status, t.created_at, u.user_FN AS customer_name
                FROM transaction t
                LEFT JOIN users u ON t.user_id = u.user_id
                WHERE t.status = 'picked up'
                ORDER BY t.created_at DESC";
        $stmt = $con->prepare($sql);
        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as &$order) {
            $itemStmt = $con->prepare("SELECT ti.quantity, ti.size, ti.price, p.name 
                FROM transaction_items ti 
                JOIN products p ON ti.product_id = p.id 
                WHERE ti.transaction_id = ?");
            $itemStmt->execute([$order['transac_id']]);
            $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $orders;
    }

    public function fetch_live_orders($status = '') {
        $con = $this->opencon();
        $allowed_statuses = ['pending', 'preparing', 'ready'];
        if ($status !== '' && in_array($status, $allowed_statuses)) {
            $where = "WHERE t.status = ?";
            $params = [$status];
        } else {
            $where = "WHERE t.status IN ('pending','preparing','ready')";
            $params = [];
        }
        $sql = "SELECT t.transac_id, t.user_id, t.total_amount, t.status, t.created_at, u.user_FN AS customer_name
                FROM transaction t
                LEFT JOIN users u ON t.user_id = u.user_id
                $where
                ORDER BY t.created_at DESC";
        $stmt = $con->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as &$order) {
            $itemStmt = $con->prepare("SELECT ti.quantity, ti.size, ti.price, p.name 
                FROM transaction_items ti 
                JOIN products p ON ti.product_id = p.id 
                WHERE ti.transaction_id = ?");
            $itemStmt->execute([$order['transac_id']]);
            $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $orders;
    }

    public function fetch_products_with_sales() {
        $con = $this->opencon();
        $sql = "SELECT p.id, p.name, p.category, p.price, p.status, p.created_at,
                       COALESCE(SUM(ti.quantity), 0) AS sales
                FROM products p
                LEFT JOIN transaction_items ti ON p.id = ti.product_id
                GROUP BY p.id";
        $stmt = $con->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetch_locations() {
        $con = $this->opencon();
        $stmt = $con->prepare("SELECT * FROM locations ORDER BY id DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function loginUser($user_email, $password) {
        $con = $this->opencon();
        // Check admin_users table by admin_email
        $stmt = $con->prepare("SELECT admin_id, username, password FROM admin_users WHERE admin_email = ?");
        $stmt->execute([$user_email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($admin) {
            if (password_verify($password, $admin['password'])) {
                return [
                    'success' => true,
                    'user' => [
                        'user_id' => $admin['admin_id'],
                        'user_FN' => $admin['username'],
                        'user_LN' => '', 
                        'user_email' => $user_email,
                        'is_admin' => true
                    ]
                ];
            } else {
                return ['success' => false, 'message' => 'Incorrect password for admin user.'];
            }
        }
        // Check users table by user_email
        $stmt = $con->prepare("SELECT user_id, user_FN, user_LN, user_email, user_password FROM users WHERE user_email = ?");
        $stmt->execute([$user_email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            if (password_verify($password, $user['user_password'])) {
                return [
                    'success' => true,
                    'user' => [
                        'user_id' => $user['user_id'],
                        'user_FN' => $user['user_FN'],
                        'user_LN' => $user['user_LN'],
                        'user_email' => $user['user_email'],
                        'is_admin' => false
                    ]
                ];
            } else {
                return ['success' => false, 'message' => 'Incorrect password for regular user.'];
            }
        }
        return ['success' => false, 'message' => 'User not found in both admin and regular user tables.'];
    }

    public function loginAdmin($admin_email, $password) {
        $pdo = $this->opencon();
        $stmt = $pdo->prepare("SELECT admin_id, username, admin_email, password, role FROM admin_users WHERE admin_email = ? LIMIT 1");
        $stmt->execute([$admin_email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($admin && password_verify($password, $admin['password'])) {
            // Set all necessary session variables for admin
            $_SESSION['admin_id'] = $admin['admin_id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_role'] = $admin['role'];
            return [
                'success' => true,
                'admin' => $admin
            ];
        }
        return [
            'success' => false,
            'message' => 'Invalid admin credentials.'
        ];
    }

    public function registerUser($name, $lastName, $email, $password, $confirmPassword, $profile_image = null) {
        $errors = [];
        if (empty($name) || empty($lastName) || empty($email) || empty($password) || empty($confirmPassword)) {
            $errors[] = 'All fields are required.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }
        $con = $this->opencon();
        $stmt = $con->prepare("SELECT * FROM users WHERE user_FN = ? OR user_LN = ? OR user_email = ?");
        $stmt->execute([$name, $lastName, $email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['user_FN'] === $name) {
                $errors[] = 'First name already registered.';
            }
            if ($existing['user_LN'] === $lastName) {
                $errors[] = 'Last name already registered.';
            }
            if ($existing['user_email'] === $email) {
                $errors[] = 'Email already registered.';
            }
        }
        if (!empty($errors)) {
            return ['success' => false, 'message' => implode(' ', $errors)];
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($profile_image) {
            $stmt = $con->prepare("INSERT INTO users (user_FN, user_LN, user_email, user_password, profile_image) VALUES (?, ?, ?, ?, ?)");
            $success = $stmt->execute([$name, $lastName, $email, $passwordHash, $profile_image]);
        } else {
            $stmt = $con->prepare("INSERT INTO users (user_FN, user_LN, user_email, user_password) VALUES (?, ?, ?, ?)");
            $success = $stmt->execute([$name, $lastName, $email, $passwordHash]);
        }
        if ($success) {
            return ['success' => true, 'message' => 'Account created successfully!'];
        } else {
            return ['success' => false, 'message' => 'Registration failed.'];
        }
    }

    // Fetch all orders for a user (order history)
    public function fetchUserOrders($user_id) {
        $con = $this->opencon();
        $stmt = $con->prepare("SELECT t.transac_id, t.reference_number, t.created_at, t.total_amount, t.status FROM transaction t WHERE t.user_id = ? ORDER BY t.created_at DESC");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch order details for a specific transaction and user
    public function fetchOrderDetail($user_id, $transac_id) {
        $con = $this->opencon();
        $stmt = $con->prepare("SELECT t.transac_id, t.created_at, t.total_amount, t.status FROM transaction t WHERE t.transac_id = ? AND t.user_id = ?");
        $stmt->execute([$transac_id, $user_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) return null;
        // Fetch items for this order
        $itemStmt = $con->prepare("SELECT ti.quantity, ti.size, ti.price, p.name FROM transaction_items ti JOIN products p ON ti.product_id = p.id WHERE ti.transaction_id = ?");
        $itemStmt->execute([$transac_id]);
        $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        return $order;
    }

    // Create a pickup order (returns reference number or error)
    public function createPickupOrder($user_id, $cart_items, $pickup_name, $pickup_location, $pickup_time, $special_instructions) {
        $con = $this->opencon();
        $con->beginTransaction();
        try {
            $total_amount = 0;
            foreach ($cart_items as $item) {
                $total_amount += floatval($item['price']) * intval($item['quantity']);
            }
            $stmt = $con->prepare("INSERT INTO transaction (user_id, total_amount, status, created_at) VALUES (?, ?, 'pending', NOW())");
            $stmt->execute([$user_id, $total_amount]);
            $transaction_id = $con->lastInsertId();
            // Reference number
            $reference_number = 'CNC-' . date('Ymd') . '-' . str_pad($transaction_id, 4, '0', STR_PAD_LEFT);
            $con->prepare("UPDATE transaction SET reference_number = ? WHERE transac_id = ?")
                ->execute([$reference_number, $transaction_id]);
            // Insert items
            foreach ($cart_items as $item) {
                $product_id = $item['product_id'];
                $quantity = intval($item['quantity']);
                $size = isset($item['size']) ? $item['size'] : (preg_match('/\((.*?)\)$/', $item['name'], $m) ? $m[1] : '');
                $price = floatval($item['price']);
                $con->prepare("INSERT INTO transaction_items (transaction_id, product_id, quantity, size, price) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$transaction_id, $product_id, $quantity, $size, $price]);
            }
            // Insert pickup details
            $pickup_datetime = date('Y-m-d') . ' ' . $pickup_time . ':00';
            $con->prepare("INSERT INTO pickup_detail (transaction_id, pickup_name, pickup_location, pickup_time, special_instructions) VALUES (?, ?, ?, ?, ?)")
                ->execute([$transaction_id, $pickup_name, $pickup_location, $pickup_datetime, $special_instructions]);
            $con->commit();
            return ['success' => true, 'reference_number' => $reference_number];
        } catch (Exception $e) {
            $con->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // Create a transaction (pickup or delivery)
    public function createTransaction($user_id, $items, $total, $method, $pickupInfo = null, $deliveryInfo = null) {
        $con = $this->opencon();
        $con->beginTransaction();
        try {
            $stmt = $con->prepare("INSERT INTO transaction (user_id, total_amount, status, created_at) VALUES (?, ?, 'pending', NOW())");
            $stmt->execute([$user_id, $total]);
            $transaction_id = $con->lastInsertId();
            // Insert items
            foreach ($items as $item) {
                $size = '';
                if (isset($item['size']) && $item['size']) {
                    $size = $item['size'];
                } elseif (isset($item['name'])) {
                    if (preg_match('/\((.*?)\)$/', $item['name'], $matches)) {
                        $size = $matches[1];
                    }
                }
                $con->prepare("INSERT INTO transaction_items (transaction_id, product_id, quantity, size, price) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$transaction_id, $item['id'], $item['quantity'], $size, $item['price']]);
            }
            // Insert pickup or delivery details
            if ($method === 'pickup' && $pickupInfo) {
                $special = isset($pickupInfo['special']) ? $pickupInfo['special'] : '';
                $pickup_location = $pickupInfo['name'] . " (" . $pickupInfo['phone'] . ")";
                $con->prepare("INSERT INTO pickup_detail (transaction_id, pickup_location, pickup_time, special_instructions) VALUES (?, ?, ?, ?)")
                    ->execute([$transaction_id, $pickup_location, $pickupInfo['time'], $special]);
            } elseif ($method === 'delivery' && $deliveryInfo) {
                $con->prepare("INSERT INTO delivery_detail (transaction_id, recipient_name, total, phone, street, city, state, zip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $transaction_id,
                        $deliveryInfo['name'],
                        $total,
                        $deliveryInfo['phone'],
                        $deliveryInfo['street'],
                        $deliveryInfo['city'],
                        $deliveryInfo['state'],
                        $deliveryInfo['zip']
                    ]);
            } else {
                throw new Exception("No valid pickup or delivery info provided.");
            }
            // Remove items from cart for this user (if you have a cart table)
            $con->prepare("DELETE FROM cart WHERE user_id = ?")->execute([$user_id]);
            $con->commit();
            return ['success' => true, 'transaction_id' => $transaction_id];
        } catch (Exception $e) {
            $con->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // Update user information (firstname, lastname, email, password, profile image)
    public function updateUserInfo($user_id, $new_FN, $new_LN, $new_email, $new_password = null) {
        $con = $this->opencon();
        $errors = [];
        // Debug log
        error_log('updateUserInfo: user_id=' . $user_id . ', new_email=' . $new_email . ', new_LN=' . $new_LN);
        // Check for duplicate email (exclude current user)
        $stmt = $con->prepare("SELECT user_id, user_email FROM users WHERE LOWER(TRIM(user_email)) = LOWER(TRIM(?)) AND user_id != ?");
        $stmt->execute([$new_email, $user_id]);
        $row = $stmt->fetch();
        error_log('Duplicate email check result: ' . print_r($row, true));
        if ($row) {
            error_log('Duplicate email found for user_id != ' . $user_id);
            $errors[] = 'Email already in use by another account.';
        }
        if (!empty($errors)) {
            return ['success' => false, 'message' => implode(' ', $errors)];
        }
        // Build update query
        $fields = "user_FN = ?, user_LN = ?, user_email = ?";
        $params = [$new_FN, $new_LN, $new_email];
        if ($new_password && strlen($new_password) >= 8) {
            $fields .= ", user_password = ?";
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
        }
        $params[] = $user_id;
        $sql = "UPDATE users SET $fields WHERE user_id = ?";
        $stmt = $con->prepare($sql);
        if ($stmt->execute($params)) {
            return ['success' => true, 'message' => 'Account information updated successfully.'];
        } else {
            return ['success' => false, 'message' => 'Failed to update account information.'];
        }
    }

    // Fetch top N products by sales for a given category (e.g., 'hot' or 'cold')
    public function fetch_top_products_by_category($category, $limit = 3) {
        $con = $this->opencon();
        $limit = intval($limit) > 0 ? intval($limit) : 3; // Sanitize limit
        $sql = "SELECT ti.product_id, p.name, p.image, p.description, COUNT(ti.product_id) AS sales_count
                FROM transaction_items ti
                JOIN products p ON ti.product_id = p.id
                JOIN transaction t ON ti.transaction_id = t.transac_id
                WHERE p.status = 'active' AND t.status != 'cancelled'
                  AND p.name != '__placeholder__'
                  AND (LOWER(p.category) LIKE ? OR LOWER(p.name) LIKE ? OR LOWER(p.id) LIKE ?)
                GROUP BY ti.product_id
                ORDER BY sales_count DESC
                LIMIT $limit";
        $like = '%' . strtolower($category) . '%';
        $stmt = $con->prepare($sql);
        $stmt->execute([$like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fallback: Fetch most recent N active products for a given category
    public function fetch_recent_products_by_category($category, $limit = 3) {
        $con = $this->opencon();
        $limit = intval($limit) > 0 ? intval($limit) : 3; // Sanitize limit
        $sql = "SELECT id as product_id, name, image, description, 0 as sales_count
                FROM products
                WHERE status = 'active' AND name != '__placeholder__'
                  AND (LOWER(category) LIKE ? OR LOWER(name) LIKE ? OR LOWER(id) LIKE ?)
                ORDER BY id DESC
                LIMIT $limit";
        $like = '%' . strtolower($category) . '%';
        $stmt = $con->prepare($sql);
        $stmt->execute([$like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch top N products by sales for a given data_type (e.g., 'hot' or 'cold')
    public function fetch_top_products_by_data_type($data_type, $limit = 3) {
        $con = $this->opencon();
        $limit = intval($limit) > 0 ? intval($limit) : 3; // Sanitize limit
        $sql = "SELECT ti.product_id, p.name, p.image, p.description, p.data_type, COUNT(ti.product_id) AS sales_count
                FROM transaction_items ti
                JOIN products p ON ti.product_id = p.id
                JOIN transaction t ON ti.transaction_id = t.transac_id
                WHERE p.status = 'active' AND t.status != 'cancelled' AND LOWER(p.data_type) = ?
                  AND p.name != '__placeholder__'
                GROUP BY ti.product_id
                ORDER BY sales_count DESC
                LIMIT $limit";
        $stmt = $con->prepare($sql);
        $stmt->execute([strtolower($data_type)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fallback: Fetch most recent N active products for a given data_type
    public function fetch_recent_products_by_data_type($data_type, $limit = 3) {
        $con = $this->opencon();
        $limit = intval($limit) > 0 ? intval($limit) : 3; // Sanitize limit
        $sql = "SELECT id as product_id, name, image, description, data_type, 0 as sales_count
                FROM products
                WHERE status = 'active' AND LOWER(data_type) = ? AND name != '__placeholder__'
                ORDER BY id DESC
                LIMIT $limit";
        $stmt = $con->prepare($sql);
        $stmt->execute([strtolower($data_type)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCategories() {
        $con = $this->opencon();
        $stmt = $con->prepare("SELECT DISTINCT category FROM products WHERE name != '__placeholder__' ORDER BY category ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function isSuperAdmin() {
        return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin';
    }
    public static function isAdmin() {
        return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin';
    }
}
?>
