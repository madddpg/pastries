<?php


class Database
{
    private $host = "mysql.hostinger.com";
    private $user = "u778762049_cupsandcuddles";
    private $password = "CupS@1234";
    private $db = "u778762049_ordering";

    public function opencon()
    {
        $pdo = new PDO(
            "mysql:host={$this->host};dbname={$this->db}",
            $this->user,
            $this->password
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    public function closecon()
    {
        return true;
    }
    // Fetch all picked up orders 
    public function fetch_pickedup_orders_pdo()
    {
        $con = $this->opencon();
        $orders = [];
        $sql = "SELECT t.transac_id, t.reference_number, t.user_id, t.total_amount, t.status, t.created_at, u.user_FN AS customer_name
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
    } // Update the fetch_live_orders_pdo method
    public function fetch_live_orders_pdo($status = '')
    {
        $con = $this->opencon();
        $allowed_statuses = ['pending', 'preparing', 'ready'];
        if ($status !== '' && in_array($status, $allowed_statuses)) {
            $where = "WHERE t.status = ?";
            $params = [$status];
        } else {
            $where = "WHERE t.status IN ('pending','preparing','ready')";
            $params = [];
        }

        // Added payment_method to the SELECT statement
        $sql = "SELECT t.transac_id, t.user_id, t.total_amount, t.status, t.created_at, 
            t.payment_method, p.pickup_name AS customer_name, p.pickup_time, p.special_instructions
            FROM transaction t
            LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id
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

    // Fetch all products with sales count 
    public function fetch_products_with_sales_pdo()
    {
        $con = $this->opencon();
        $sql = "SELECT p.id, p.name, p.category_id, p.price, p.status, p.created_at,
                       COALESCE(SUM(ti.quantity), 0) AS sales
                FROM products p
                LEFT JOIN transaction_items ti ON p.id = ti.product_id
                GROUP BY p.id";
        $stmt = $con->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch all locations (PDO)
    public function fetch_locations_pdo()
    {
        $con = $this->opencon();
        $stmt = $con->prepare("SELECT * FROM locations ORDER BY id DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetch_pickedup_orders()
    {
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

    // Update the fetch_live_orders method
    public function fetch_live_orders($status = '')
    {
        $con = $this->opencon();
        $allowed_statuses = ['pending', 'preparing', 'ready'];
        if ($status !== '' && in_array($status, $allowed_statuses)) {
            $where = "WHERE t.status = ?";
            $params = [$status];
        } else {
            $where = "WHERE t.status IN ('pending','preparing','ready')";
            $params = [];
        }

        // Added payment_method to the SELECT statement
        $sql = "SELECT t.transac_id, t.user_id, t.total_amount, t.payment_method, 
            p.pickup_time, p.special_instructions, t.status, t.created_at, 
            p.pickup_Name AS customer_name
            FROM transaction t
            LEFT JOIN users u ON t.user_id = u.user_id
            JOIN pickup_detail p ON t.transac_id = p.transaction_id
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

            if (!isset($order['pickup_time'])) {
                $order['pickup_time'] = null;
            }
            if (!isset($order['special_instructions'])) {
                $order['special_instructions'] = null;
            }
            // Ensure payment_method has a default value if null
            if (!isset($order['payment_method'])) {
                $order['payment_method'] = 'cash';
            }
        }

        return $orders;
    }


    public function fetch_products_with_sales()
    {
        $con = $this->opencon();
        $sql = "SELECT p.id, p.name, p.category_id, p.price, p.status, p.created_at,
                       COALESCE(SUM(ti.quantity), 0) AS sales
                FROM products p
                LEFT JOIN transaction_items ti ON p.id = ti.product_id
                GROUP BY p.id";
        $stmt = $con->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetch_locations()
    {
        $con = $this->opencon();
        $stmt = $con->prepare("SELECT * FROM locations ORDER BY id DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function loginUser($user_email, $password)
    {
        $con = $this->opencon();
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
                return ['success' => false, 'message' => 'Incorrect password.'];
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
        return ['success' => false, 'message' => 'User not found. Please try again.'];
    }

    public function loginAdmin($admin_email, $password)
    {
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

    public function registerUser($name, $lastName, $email, $password, $confirmPassword, $profile_image = null)
    {
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
    public function fetchUserOrders($user_id)
    {
        $con = $this->opencon();
        $stmt = $con->prepare("SELECT t.transac_id, t.reference_number, t.created_at, t.total_amount, t.status FROM transaction t WHERE t.user_id = ? ORDER BY t.created_at DESC");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch order details for a specific transaction and user
    public function fetchOrderDetail($user_id, $transac_id)
    {
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



public function createPickupOrder($user_id, $cart_items, $pickup_name, $pickup_location, $pickup_time, $special_instructions, $payment_method = 'cash')
{
    $con = $this->opencon();
    $con->beginTransaction();
    try {
        $total_amount = 0;

        // NEW: detect an overall (first found) sugar level for pickup_detail
        $overall_sugar = null;
        foreach ($cart_items as $ci) {
            if (isset($ci['sugar']) && $ci['sugar'] !== '') {
                $overall_sugar = $ci['sugar'];
                break;
            }
        }

        $itemInsert = $con->prepare(
            "INSERT INTO transaction_items (transaction_id, product_id, quantity, size, price) VALUES (?, ?, ?, ?, ?)"
        );
        $toppingInsert = $con->prepare(
            "INSERT INTO transaction_toppings (transaction_id, transaction_item_id, product_id, topping_id, quantity, unit_price, sugar_level) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $findTopping = $con->prepare("SELECT id FROM toppings WHERE name = ? LIMIT 1");
        $insertTopping = $con->prepare("INSERT INTO toppings (name, price, created_at) VALUES (?, ?, NOW())");

        foreach ($cart_items as $item) {
            $item_qty = intval($item['quantity'] ?? 1);
            if (isset($item['basePrice'])) {
                $base = (float)$item['basePrice'];
                $toppings_sum = 0;
                if (!empty($item['toppings'])) {
                    foreach ($item['toppings'] as $t) {
                        $t_qty = intval($t['quantity'] ?? ($t['qty'] ?? 1));
                        $t_price = (float)($t['price'] ?? 0);
                        $toppings_sum += $t_price * $t_qty;
                    }
                }
                $total_amount += ($base + $toppings_sum) * $item_qty;
            } else {
                $total_amount += ((float)($item['price'] ?? 0)) * $item_qty;
            }
        }

        $stmt = $con->prepare("INSERT INTO transaction (user_id, total_amount, status, payment_method, created_at) VALUES (?, ?, 'pending', ?, NOW())");
        $stmt->execute([$user_id, $total_amount, $payment_method]);
        $transaction_id = $con->lastInsertId();

        $reference_number = 'CNC-' . date('Ymd') . '-' . str_pad($transaction_id, 4, '0', STR_PAD_LEFT);
        $con->prepare("UPDATE transaction SET reference_number = ? WHERE transac_id = ?")
            ->execute([$reference_number, $transaction_id]);

        foreach ($cart_items as $item) {
            $product_id = $item['product_id'] ?? ($item['id'] ?? null);
            $quantity = intval($item['quantity'] ?? 1);
            $size = $item['size'] ?? ( (isset($item['name']) && preg_match('/\((.*?)\)$/', $item['name'], $m)) ? $m[1] : '' );

            $computed_toppings_sum = 0;
            if (!empty($item['toppings'])) {
                foreach ($item['toppings'] as $t) {
                    $t_qty = intval($t['quantity'] ?? ($t['qty'] ?? 1));
                    $t_price = (float)($t['price'] ?? 0);
                    $computed_toppings_sum += $t_price * $t_qty;
                }
            }
            if (isset($item['price'])) {
                $price_to_store = (float)$item['price'];
            } elseif (isset($item['basePrice'])) {
                $price_to_store = (float)$item['basePrice'] + $computed_toppings_sum;
            } else {
                $price_to_store = 0.00;
            }

            $itemInsert->execute([$transaction_id, $product_id, $quantity, $size, $price_to_store]);
            $transaction_item_id = $con->lastInsertId();

            $item_sugar = $item['sugar'] ?? null; // sugar per item

           if (!empty($item['toppings']) && is_array($item['toppings'])) {
                foreach ($item['toppings'] as $topping) {
                    $topping_id = null;
                    $t_name = trim($topping['name'] ?? '');
                    $t_price = (float)($topping['price'] ?? 0);
                    $t_qty = intval($topping['quantity'] ?? ($topping['qty'] ?? 1));

                    if (isset($topping['id']) && ctype_digit((string)$topping['id']) && intval($topping['id']) > 0) {
                        $topping_id = intval($topping['id']);
                    } elseif ($t_name !== '') {
                        $findTopping->execute([$t_name]);
                        $found = $findTopping->fetch(PDO::FETCH_ASSOC);
                        if ($found) {
                            $topping_id = (int)$found['id'];
                        } else {
                            $insertTopping->execute([$t_name, $t_price]);
                            $topping_id = (int)$con->lastInsertId();
                        }
                    } else {
                        throw new Exception("Invalid topping data for product_id {$product_id}");
                    }

                  $toppingInsert->execute([
                        $transaction_id,
                        $transaction_item_id,
                        $product_id,
                        $topping_id,
                        $t_qty,
                        $t_price,
                        $item_sugar
                    ]);
                }
            } else {
                 if ($item_sugar !== null && $item_sugar !== '') {
                    $toppingInsert->execute([
                        $transaction_id,
                        $transaction_item_id,
                        $product_id,
                        null,          // topping_id NULL
                        0,             // quantity
                        0.00,          // unit_price
                        $item_sugar
                    ]);
                }
            }
        }

        $pickup_datetime = date('Y-m-d') . ' ' . $pickup_time . ':00';

        // UPDATED: include sugar_level column
        $con->prepare("INSERT INTO pickup_detail (transaction_id, pickup_name, pickup_location, pickup_time, special_instructions, sugar_level) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$transaction_id, $pickup_name, $pickup_location, $pickup_datetime, $special_instructions, $overall_sugar]);

        $con->commit();
        return ['success' => true, 'reference_number' => $reference_number];
    } catch (Exception $e) {
        $con->rollBack();
        error_log("createPickupOrder failed: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}



 private function sendFcmTopicAdmins($title, $body, $click = '/', $icon = '/images/CC.png')
    {
        $serverKey = getenv('FCM_SERVER_KEY');
        if (!$serverKey) {
            error_log('FCM_SERVER_KEY missing');
            return;
        }

        $payload = [
            "to" => "/topics/admins",
            "notification" => [
                "title" => $title,
                "body"  => $body,
                "icon"  => $icon
            ],
            "data" => [
                "click_action" => $click
            ]
        ];

        $ch = curl_init("https://fcm.googleapis.com/fcm/send");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: key={$serverKey}"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            error_log('FCM send error: ' . curl_error($ch));
        }
        curl_close($ch);
    }

public function createTransaction($user_id, $items, $total, $method, $pickupInfo = null, $deliveryInfo = null)
{
    $con = $this->opencon();
    $con->beginTransaction();
    try {
        // Insert base transaction
        $stmt = $con->prepare("INSERT INTO transaction (user_id, total_amount, status, created_at) VALUES (?, ?, 'pending', NOW())");
        $stmt->execute([$user_id, $total]);
        $transaction_id = $con->lastInsertId();

        // prepared statements for items and toppings (match schema including sugar_level)
        $itemInsert = $con->prepare("INSERT INTO transaction_items (transaction_id, product_id, quantity, size, price) VALUES (?, ?, ?, ?, ?)");
        $toppingInsert = $con->prepare("INSERT INTO transaction_toppings (transaction_id, transaction_item_id, product_id, topping_id, quantity, unit_price, sugar_level) VALUES (?, ?, ?, ?, ?, ?, ?)");

        // helper: find topping by name (if not numeric id), and insert if missing
        $findTopping = $con->prepare("SELECT id FROM toppings WHERE name = ? LIMIT 1");
        $insertTopping = $con->prepare("INSERT INTO toppings (name, price, created_at) VALUES (?, ?, NOW())");

        // Insert items and their toppings (if any)
        foreach ($items as $item) {
            $size = '';
            if (isset($item['size']) && $item['size']) {
                $size = $item['size'];
            } elseif (isset($item['name']) && preg_match('/\((.*?)\)$/', $item['name'], $matches)) {
                $size = $matches[1];
            }

            $product_id = isset($item['id']) ? $item['id'] : (isset($item['product_id']) ? $item['product_id'] : null);
            $quantity = isset($item['quantity']) ? intval($item['quantity']) : 1;

            // compute price to store (base + toppings)
            $computed_toppings_sum = 0;
            if (!empty($item['toppings']) && is_array($item['toppings'])) {
                foreach ($item['toppings'] as $t) {
                    $t_qty = isset($t['quantity']) ? intval($t['quantity']) : (isset($t['qty']) ? intval($t['qty']) : 1);
                    $t_price = floatval(isset($t['price']) ? $t['price'] : 0);
                    $computed_toppings_sum += ($t_price * $t_qty);
                }
            }
            if (isset($item['price'])) {
                $price_to_store = floatval($item['price']);
            } elseif (isset($item['basePrice'])) {
                $price_to_store = floatval($item['basePrice']) + $computed_toppings_sum;
            } else {
                $price_to_store = 0.00;
            }

            $itemInsert->execute([$transaction_id, $product_id, $quantity, $size, $price_to_store]);
            $transaction_item_id = $con->lastInsertId();

            // Handle toppings (create/find topping and insert into transaction_toppings with sugar_level)
            if (!empty($item['toppings']) && is_array($item['toppings'])) {
                // sugar selected for this cart item (null if not provided)
                $item_sugar = isset($item['sugar']) ? $item['sugar'] : null;

                foreach ($item['toppings'] as $topping) {
                    $topping_id = null;
                    $t_name = isset($topping['name']) ? trim($topping['name']) : '';
                    $t_price = floatval(isset($topping['price']) ? $topping['price'] : 0);
                    $t_qty = isset($topping['quantity']) ? intval($topping['quantity']) : (isset($topping['qty']) ? intval($topping['qty']) : 1);

                    if (isset($topping['id']) && is_numeric($topping['id']) && intval($topping['id']) > 0) {
                        $topping_id = intval($topping['id']);
                    } elseif ($t_name !== '') {
                        $findTopping->execute([$t_name]);
                        $found = $findTopping->fetch(PDO::FETCH_ASSOC);
                        if ($found && isset($found['id'])) {
                            $topping_id = intval($found['id']);
                        } else {
                            $insertTopping->execute([$t_name, $t_price]);
                            $topping_id = $con->lastInsertId();
                        }
                    } else {
                        throw new Exception("Invalid topping data for product_id {$product_id}");
                    }

                    // insert topping record including sugar_level
                    $toppingInsert->execute([$transaction_id, $transaction_item_id, $product_id, $topping_id, $t_qty, $t_price, $item_sugar]);
                }
            }
        }

        // Insert pickup details if provided
        if ($method === 'pickup' && $pickupInfo) {
            $special = isset($pickupInfo['special']) ? $pickupInfo['special'] : '';
            $pickup_location = isset($pickupInfo['name']) ? ($pickupInfo['name'] . (isset($pickupInfo['phone']) ? " ({$pickupInfo['phone']})" : "")) : '';
            $con->prepare("INSERT INTO pickup_detail (transaction_id, pickup_location, pickup_time, special_instructions) VALUES (?, ?, ?, ?)")
                ->execute([$transaction_id, $pickup_location, $pickupInfo['time'], $special]);
        } else {
            throw new Exception("No valid pickup or delivery info provided.");
        }

        // clear cart for user if applicable
        $con->prepare("DELETE FROM cart WHERE user_id = ?")->execute([$user_id]);

        $con->commit();
        return ['success' => true, 'transaction_id' => $transaction_id];
    } catch (Exception $e) {
        $con->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

    public function fetch_toppings_pdo()
    {
        $con = $this->opencon();
        $stmt = $con->prepare("
            SELECT 
                id,
                name,
                price,
                CASE WHEN COALESCE(status,1)=1 THEN 'active' ELSE 'inactive' END AS status,
                created_at,
                updated_at
            FROM toppings
            ORDER BY id DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetch_active_toppings()
    {
        $con = $this->opencon();
        $stmt = $con->prepare("
            SELECT 
                id,
                name,
                price
            FROM toppings
            WHERE COALESCE(status,1) = 1
            ORDER BY id ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function add_topping($name, $price, $status = 'active')
    {
        $con = $this->opencon();
        $st = ($status === 'active') ? 1 : 0;
        $stmt = $con->prepare("INSERT INTO toppings (name, price, status, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([trim($name), number_format(floatval($price), 2, '.', ''), $st]);
        return $con->lastInsertId();
    }

    public function update_topping($id, $name, $price)
    {
        $con = $this->opencon();
        $stmt = $con->prepare("UPDATE toppings SET name = ?, price = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([trim($name), number_format(floatval($price), 2, '.', ''), intval($id)]);
    }

    public function update_topping_status($id, $status)
    {
        $con = $this->opencon();
        $st = ($status === 'active') ? 1 : 0;
        $stmt = $con->prepare("UPDATE toppings SET status = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$st, intval($id)]);
    }

    // Update user information (firstname, lastname, email, password, profile image)
    public function updateUserInfo($user_id, $new_FN, $new_LN, $new_email, $new_password = null)
    {
        $con = $this->opencon();
        $errors = [];
        error_log('updateUserInfo: user_id=' . $user_id . ', new_email=' . $new_email . ', new_LN=' . $new_LN);
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

    // Fetch top 3 products
    public function fetch_top_products_by_category($category, $limit = 3)
    {
        $con = $this->opencon();
        $limit = intval($limit) > 0 ? intval($limit) : 3; // Sanitize limit
        $sql = "SELECT ti.product_id, p.name, p.image, p.description, COUNT(ti.product_id) AS sales_count
                FROM transaction_items ti
                JOIN products p ON ti.product_id = p.id
                JOIN transaction t ON ti.transaction_id = t.transac_id
                WHERE p.status = 'active' AND t.status != 'cancelled'
                  AND p.name != '__placeholder__'
                  AND (LOWER(p.category_id) LIKE ? OR LOWER(p.name) LIKE ? OR LOWER(p.id) LIKE ?)
                GROUP BY ti.product_id
                ORDER BY sales_count DESC
                LIMIT $limit";
        $like = '%' . strtolower($category) . '%';
        $stmt = $con->prepare($sql);
        $stmt->execute([$like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public function fetch_recent_products_by_category($category, $limit = 3)
    {
        $con = $this->opencon();
        $limit = intval($limit) > 0 ? intval($limit) : 3;
        $sql = "SELECT id as product_id, name, image, description, 0 as sales_count
                FROM products
                WHERE status = 'active' AND name != '__placeholder__'
                  AND (LOWER(category_id) LIKE ? OR LOWER(name) LIKE ? OR LOWER(id) LIKE ?)
                ORDER BY id DESC
                LIMIT $limit";
        $like = '%' . strtolower($category) . '%';
        $stmt = $con->prepare($sql);
        $stmt->execute([$like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch top N products by sales for a specific data type
    public function fetch_top_products_by_data_type($data_type, $limit = 3)
    {
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


    public function fetch_recent_products_by_data_type($data_type, $limit = 3)
    {
        $con = $this->opencon();
        $limit = intval($limit) > 0 ? intval($limit) : 3;
        $sql = "SELECT id as product_id, name, image, description, data_type, 0 as sales_count
                FROM products
                WHERE status = 'active' AND LOWER(data_type) = ? AND name != '__placeholder__'
                ORDER BY id DESC
                LIMIT $limit";
        $stmt = $con->prepare($sql);
        $stmt->execute([strtolower($data_type)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCategories()
    {
        $con = $this->opencon();
        $stmt = $con->prepare("SELECT DISTINCT category_id FROM products WHERE name != '__placeholder__' ORDER BY category_id ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function isSuperAdmin()
    {
        return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin';
    }
    public static function isAdmin()
    {
        return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin';
    }
}
