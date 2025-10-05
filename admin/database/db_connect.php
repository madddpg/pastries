<?php


class Database
{
    private $host = "mysql.hostinger.com";
    private $user = "u778762049_cupsandcuddles";
    private $password = "CupS@1234";
    private $db = "u778762049_ordering";
    private $pdo;
    // Cache resolved table name for size-based price history
    private $sizePriceTableName = null;

    public function opencon()
    {
        $pdo = new PDO(
            "mysql:host={$this->host};dbname={$this->db}",
            $this->user,
            $this->password
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Ensure supporting tables/schemas exist or are updated
    try { $this->ensureSizePriceHistorySchema($pdo); } catch (Throwable $e) { /* ignore */ }
    try { $this->ensurePastryVariantsSchema($pdo); } catch (Throwable $e) { /* ignore */ }
    try { $this->ensureUsersBlockSchema($pdo); } catch (Throwable $e) { /* ignore */ }
    try { $this->ensureToppingsScopeSchema($pdo); } catch (Throwable $e) { /* ignore */ }
        return $pdo;
    }

    public function closecon()
    {
        return true;
    }


    public function openPdo(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        $dsn = "mysql:host={$this->host};dbname={$this->db};charset=utf8mb4";
        $pdo = new PDO(
            $dsn,
            $this->user,
            $this->password,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        try { $this->ensureSizePriceHistorySchema($pdo); } catch (Throwable $e) { /* ignore */ }
        try { $this->ensurePastryVariantsSchema($pdo); } catch (Throwable $e) { /* ignore */ }
        try { $this->ensureUsersBlockSchema($pdo); } catch (Throwable $e) { /* ignore */ }
        return $pdo;
    }

    /** Resolve the size-price table name in the current DB (supports product_sizes_prices or product_size_prices). */
    public function getSizePriceTable(PDO $pdo): string
    {
        if (is_string($this->sizePriceTableName) && $this->sizePriceTableName !== '') {
            return $this->sizePriceTableName;
        }
        try {
            $stmt = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('product_sizes_prices','product_size_prices') LIMIT 1");
            $name = $stmt->fetchColumn();
            if (is_string($name) && $name !== '') {
                $this->sizePriceTableName = $name;
                return $this->sizePriceTableName;
            }
        } catch (Throwable $_) { /* ignore */ }
        // Default to singular if creating new
        $this->sizePriceTableName = 'product_size_prices';
        return $this->sizePriceTableName;
    }

    /**
     * Create or migrate the product_size_prices table to support historical pricing by size.
     * - Adds effective_from/effective_to
     * - Removes UNIQUE(products_pk,size) if present
     * - Keeps FK to products(products_pk)
     */
    private function ensureSizePriceHistorySchema(PDO $pdo): void
    {
        $tbl = $this->getSizePriceTable($pdo);
        // 1) Create table if it does not exist
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$tbl}` (
                    `product_size_id` INT NOT NULL AUTO_INCREMENT,
                    `products_pk` INT NOT NULL,
                    `size` ENUM('grande','supreme') NOT NULL,
                    `price` DECIMAL(10,2) NOT NULL,
                    `effective_from` DATE NOT NULL DEFAULT (CURDATE()),
                    `effective_to` DATE DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`product_size_id`),
                    KEY `idx_psp_products_pk` (`products_pk`),
                    CONSTRAINT `fk_psp_products` FOREIGN KEY (`products_pk`) REFERENCES `products` (`products_pk`) ON DELETE CASCADE ON UPDATE RESTRICT
                ) ENGINE=InnoDB"
            );
        } catch (Throwable $e) {
            // Some MySQL/MariaDB versions don't allow DEFAULT (CURDATE()) for DATE.
            // Retry without DEFAULT expression.
            try {
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS `{$tbl}` (
                        `product_size_id` INT NOT NULL AUTO_INCREMENT,
                        `products_pk` INT NOT NULL,
                        `size` ENUM('grande','supreme') NOT NULL,
                        `price` DECIMAL(10,2) NOT NULL,
                        `effective_from` DATE NOT NULL,
                        `effective_to` DATE DEFAULT NULL,
                        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (`product_size_id`),
                        KEY `idx_psp_products_pk` (`products_pk`),
                        CONSTRAINT `fk_psp_products` FOREIGN KEY (`products_pk`) REFERENCES `products` (`products_pk`) ON DELETE CASCADE ON UPDATE RESTRICT
                    ) ENGINE=InnoDB"
                );
            } catch (Throwable $_) { /* ignore */ }
        }

        // 2) Ensure columns effective_from/effective_to exist
        try {
            $q = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . str_replace("`","",$tbl) . "'");
            $cols = array_map(static function($r){return strtolower($r['COLUMN_NAME']);}, $q->fetchAll(PDO::FETCH_ASSOC));
            if (!in_array('effective_from', $cols, true)) {
                try { $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `effective_from` DATE NOT NULL DEFAULT (CURDATE()) AFTER price"); }
                catch (Throwable $e) { try { $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `effective_from` DATE NOT NULL AFTER price"); } catch (Throwable $_) {} }
            }
            if (!in_array('effective_to', $cols, true)) {
                try { $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `effective_to` DATE NULL AFTER effective_from"); } catch (Throwable $_) {}
            }
        } catch (Throwable $_) { /* ignore */ }

        // 3) Drop UNIQUE(products_pk,size) if present
        try {
            $rows = $pdo->query("SHOW INDEX FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
            $byKey = [];
            foreach ($rows as $r) {
                $key = $r['Key_name'];
                if (!isset($byKey[$key])) {
                    $byKey[$key] = ['non_unique' => (int)$r['Non_unique'], 'cols' => []];
                }
                $byKey[$key]['cols'][(int)$r['Seq_in_index']] = $r['Column_name'];
            }
            foreach ($byKey as $name => $meta) {
                ksort($meta['cols']);
                $cols = array_values($meta['cols']);
                if ((int)$meta['non_unique'] === 0 && $cols === ['products_pk','size']) {
                    try { $pdo->exec("ALTER TABLE `{$tbl}` DROP INDEX `{$name}`"); } catch (Throwable $_) {}
                }
            }
        } catch (Throwable $_) { /* ignore */ }

        // 4) Ensure helpful indexes exist
        try {
            // Add composite index to speed up active lookups
            $pdo->exec("ALTER TABLE `{$tbl}` ADD INDEX `idx_psp_active` (`products_pk`,`size`,`effective_to`)");
        } catch (Throwable $_) { /* ignore if exists */ }
    }

    /** Ensure table for pastry variants exists and supports history via effective dates. */
    private function ensurePastryVariantsSchema(PDO $pdo): void
    {
        // 1) Create table if it does not exist (with effective dates)
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS product_pastry_variants (
                    variant_id INT NOT NULL AUTO_INCREMENT,
                    products_pk INT NOT NULL,
                    label VARCHAR(64) NOT NULL,
                    price DECIMAL(10,2) NOT NULL,
                    effective_from DATE NOT NULL,
                    effective_to DATE DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (variant_id),
                    KEY idx_ppv_products_pk (products_pk),
                    KEY idx_ppv_active (products_pk, label, effective_to),
                    CONSTRAINT fk_ppv_products FOREIGN KEY (products_pk) REFERENCES products(products_pk)
                        ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e) { /* ignore */ }

        // 2) Migrate: add effective_from/effective_to if missing, plus helpful index
        try {
            $q = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_pastry_variants'");
            $cols = array_map(static function($r){return strtolower($r['COLUMN_NAME']);}, $q->fetchAll(PDO::FETCH_ASSOC));
            if (!in_array('effective_from', $cols, true)) {
                try { $pdo->exec("ALTER TABLE product_pastry_variants ADD COLUMN effective_from DATE NOT NULL AFTER price"); } catch (Throwable $_) {}
                // Backfill: set effective_from to CURRENT_DATE for existing rows
                try { $pdo->exec("UPDATE product_pastry_variants SET effective_from = CURDATE() WHERE effective_from IS NULL"); } catch (Throwable $_) {}
            }
            if (!in_array('effective_to', $cols, true)) {
                try { $pdo->exec("ALTER TABLE product_pastry_variants ADD COLUMN effective_to DATE NULL AFTER effective_from"); } catch (Throwable $_) {}
            }
        } catch (Throwable $_) { /* ignore */ }

        // 3) Ensure active index exists
        try { $pdo->exec("ALTER TABLE product_pastry_variants ADD INDEX idx_ppv_active (products_pk, label, effective_to)"); } catch (Throwable $_) {}
    }


    /** Ensure users table has is_blocked/blocked_at columns for blocking feature. */
    private function ensureUsersBlockSchema(PDO $pdo): void
    {
        try {
            $q = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
            $cols = array_map(static function($r){return strtolower($r['COLUMN_NAME']);}, $q->fetchAll(PDO::FETCH_ASSOC));
            if (!in_array('is_blocked', $cols, true)) {
                try { $pdo->exec("ALTER TABLE users ADD COLUMN is_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER user_password"); } catch (Throwable $_) {}
            }
            if (!in_array('blocked_at', $cols, true)) {
                try { $pdo->exec("ALTER TABLE users ADD COLUMN blocked_at DATETIME NULL AFTER is_blocked"); } catch (Throwable $_) {}
            }
        } catch (Throwable $_) { /* ignore */ }
        // Helpful index for admin listing/filtering
        try { $pdo->exec("ALTER TABLE users ADD INDEX idx_users_block (is_blocked, user_id)"); } catch (Throwable $_) {}
    }
    
        /** Ensure schema to scope toppings to data_types and/or categories. */
        private function ensureToppingsScopeSchema(PDO $pdo): void
        {
            try {
                // Main toppings table may already exist; just extend with status if missing
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS toppings (
                        topping_id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(120) NOT NULL UNIQUE,
                        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        status ENUM('active','inactive') DEFAULT 'active',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );

                // Table linking toppings to allowed data_types (e.g., 'premium','specialty','pastries', etc.)
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS topping_allowed_types (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        topping_id INT NOT NULL,
                        data_type VARCHAR(50) NOT NULL,
                        UNIQUE KEY uniq_topping_type (topping_id, data_type),
                        INDEX (data_type),
                        CONSTRAINT fk_tat_topping FOREIGN KEY (topping_id) REFERENCES toppings(topping_id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );

                // Table linking toppings to allowed categories (by numeric category_id from categories table)
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS topping_allowed_categories (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        topping_id INT NOT NULL,
                        category_id INT NOT NULL,
                        UNIQUE KEY uniq_topping_cat (topping_id, category_id),
                        INDEX (category_id),
                        CONSTRAINT fk_tac_topping FOREIGN KEY (topping_id) REFERENCES toppings(topping_id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            } catch (Throwable $e) { /* ignore */ }
        }

        /** Return active toppings filtered by optional product context. */
        public function fetch_active_toppings_filtered(?string $data_type = null, $category_id = null): array
        {
            $con = $this->opencon();
            $sql = "SELECT t.topping_id, t.name, t.price FROM toppings t WHERE COALESCE(t.status,1) = 1";
            $params = [];
            $data_type = ($data_type !== null && $data_type !== '') ? strtolower(trim($data_type)) : null;
            $category_id = ($category_id !== null && $category_id !== '') ? intval($category_id) : null;

            // If data_type provided: allow topping if it explicitly allows this type OR it has no type restrictions
            if ($data_type !== null) {
                $sql .= " AND (EXISTS (SELECT 1 FROM topping_allowed_types tat WHERE tat.topping_id = t.topping_id AND tat.data_type = ?) 
                               OR NOT EXISTS (SELECT 1 FROM topping_allowed_types tat0 WHERE tat0.topping_id = t.topping_id))";
                $params[] = $data_type;
            }
            // If category provided: allow topping if it explicitly allows this category OR it has no category restrictions
            if ($category_id !== null) {
                $sql .= " AND (EXISTS (SELECT 1 FROM topping_allowed_categories tac WHERE tac.topping_id = t.topping_id AND tac.category_id = ?) 
                               OR NOT EXISTS (SELECT 1 FROM topping_allowed_categories tac0 WHERE tac0.topping_id = t.topping_id))";
                $params[] = $category_id;
            }

            $sql .= " ORDER BY t.topping_id ASC";
            $st = $con->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }

    /** Check if a user is blocked from ordering. */
    public function isUserBlocked(int $user_id): bool
    {
        if ($user_id <= 0) return false;
        $pdo = $this->opencon();
        $st = $pdo->prepare("SELECT is_blocked FROM users WHERE user_id = ? LIMIT 1");
        $st->execute([$user_id]);
        $v = $st->fetchColumn();
        return (bool)$v;
    }

    /** Toggle a user's blocked status. Optionally record blocked_at timestamp. */
    public function setUserBlocked(int $user_id, bool $blocked): bool
    {
        $pdo = $this->opencon();
        if ($blocked) {
            $st = $pdo->prepare("UPDATE users SET is_blocked = 1, blocked_at = NOW() WHERE user_id = ?");
        } else {
            $st = $pdo->prepare("UPDATE users SET is_blocked = 0, blocked_at = NULL WHERE user_id = ?");
        }
        return $st->execute([$user_id]) === true;
    }

    /** Fetch all users for admin listing. */
    public function fetchAllUsers(): array
    {
        $pdo = $this->opencon();
        $st = $pdo->prepare("SELECT user_id, user_FN, user_LN, user_email, COALESCE(is_blocked,0) AS is_blocked, blocked_at FROM users ORDER BY user_id ASC");
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Fetch users with server-side pagination and optional search. */
    public function fetchUsersPaginated(int $page = 1, int $perPage = 10, ?string $q = null): array
    {
        $pdo = $this->opencon();
        $page = max(1, (int)$page);
        $perPage = max(1, min(100, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = '1=1';
        $params = [];
        $idExact = null;
        if (is_string($q)) {
            $q = trim($q);
            if ($q !== '') {
                $where .= ' AND (user_FN LIKE :q OR user_LN LIKE :q OR user_email LIKE :q';
                $params[':q'] = '%' . $q . '%';
                if (ctype_digit($q)) {
                    $where .= ' OR user_id = :idExact';
                    $idExact = (int)$q;
                }
                $where .= ')';
            }
        }

        // total count
        $sqlCount = "SELECT COUNT(*) FROM users WHERE $where";
        $st = $pdo->prepare($sqlCount);
        if (array_key_exists(':q', $params)) { $st->bindValue(':q', $params[':q'], PDO::PARAM_STR); }
        if (!is_null($idExact)) { $st->bindValue(':idExact', $idExact, PDO::PARAM_INT); }
        $st->execute();
        $total = (int)$st->fetchColumn();

        // clamp page if out of range
        $totalPages = (int)max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        // data page
        $sql = "SELECT user_id, user_FN, user_LN, user_email, COALESCE(is_blocked,0) AS is_blocked, blocked_at
                FROM users
                WHERE $where
                ORDER BY user_id ASC
                LIMIT $offset, $perPage"; // integers already sanitized
        $st = $pdo->prepare($sql);
        if (array_key_exists(':q', $params)) { $st->bindValue(':q', $params[':q'], PDO::PARAM_STR); }
        if (!is_null($idExact)) { $st->bindValue(':idExact', $idExact, PDO::PARAM_INT); }
        $st->execute();
        $users = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
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
            // Prefer join by products_pk (precise version); fallback to active row by product_id
            $itemStmt = $con->prepare("SELECT ti.quantity, ti.size, ti.price,
                        COALESCE(p.name, p2.name) AS name
                    FROM transaction_items ti
                    LEFT JOIN products p
                        ON ti.products_pk IS NOT NULL AND p.products_pk = ti.products_pk
                    LEFT JOIN products p2
                        ON ti.products_pk IS NULL AND p2.product_id = ti.product_id
                    WHERE ti.transaction_id = ?");
            $itemStmt->execute([$order['transac_id']]);
            $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $orders;
    }



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
            t.payment_method, p.pickup_name AS customer_name, p.pickup_location, p.pickup_time, p.special_instructions,
            a.admin_id AS approved_by_admin_id, a.username AS approved_by
            FROM transaction t
            LEFT JOIN pickup_detail p ON t.transac_id = p.transaction_id
            LEFT JOIN admin_users a ON t.admin_id = a.admin_id
            $where
            ORDER BY t.created_at DESC";

        $stmt = $con->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orders as &$order) {
            $itemStmt = $con->prepare("SELECT ti.quantity, ti.size, ti.price,
                        COALESCE(p.name, p2.name) AS name
                    FROM transaction_items ti
                    LEFT JOIN products p
                        ON ti.products_pk IS NOT NULL AND p.products_pk = ti.products_pk
                    LEFT JOIN products p2
                        ON ti.products_pk IS NULL AND p2.product_id = ti.product_id
                    WHERE ti.transaction_id = ?");
            $itemStmt->execute([$order['transac_id']]);
            $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $orders;
    }

    private function connect()
    {
        if ($this->pdo) return $this->pdo;
        $dsn = "mysql:host={$this->host};dbname={$this->db};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $this->pdo;
    }

    public function getAdminFcmToken(int $adminId): ?array
    {
        $pdo = $this->connect();
        $st = $pdo->prepare("SELECT fcm_token FROM admin_users WHERE admin_id = ? AND fcm_token <> ''"); // FIXED COLUMN
        $st->execute([$adminId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function getAllAdminFcmTokens(): array
    {
        $pdo = $this->connect();
        $rows = $pdo->query("SELECT fcm_token FROM admin_users WHERE fcm_token IS NOT NULL AND fcm_token <> ''")
            ->fetchAll(PDO::FETCH_COLUMN);
        if (!$rows) return [];

        $flat = [];
        foreach ($rows as $raw) {
            if (!is_string($raw)) continue;
            $raw = trim($raw);
            if ($raw === '') continue;
            // If the column now stores a JSON array (Option C) expand it
            if ($raw[0] === '[') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $t) {
                        if (is_string($t) && $t !== '') {
                            $flat[] = $t;
                        }
                    }
                }
            } else {
                // Legacy single token value
                $flat[] = $raw;
            }
        }
        // Dedupe & reindex
        $flat = array_values(array_unique($flat));
        // Optional: log count for debugging
        if (!$flat) {
            error_log('FCM: getAllAdminFcmTokens found no usable tokens after flatten');
        } else {
            error_log('FCM: getAllAdminFcmTokens flattened count=' . count($flat));
        }
        return $flat;
    }

    // Fetch all products with sales count (price derived from size prices; prefers 'grande' then 'supreme')
    public function fetch_products_with_sales_pdo()
    {
        $con = $this->opencon();
        $tbl = $this->getSizePriceTable($con);
        $sql = "SELECT 
                    p.product_id,
                    p.name,
                    p.category_id,
                    p.data_type,
                    COALESCE(
                        (SELECT spp.price FROM `{$tbl}` spp WHERE spp.products_pk = p.products_pk AND spp.size='grande' AND spp.effective_to IS NULL LIMIT 1),
                        (SELECT spp.price FROM `{$tbl}` spp WHERE spp.products_pk = p.products_pk AND spp.size='supreme' AND spp.effective_to IS NULL LIMIT 1),
                        0
                    ) AS price,
                    p.status,
                    p.created_at,
                    COALESCE(SUM(ti.quantity), 0) AS sales
                FROM products p
                LEFT JOIN transaction_items ti ON ti.product_id = p.product_id
                WHERE p.name != '__placeholder__'
                GROUP BY p.product_id";
        $stmt = $con->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get size-specific price for the active version of a product (falls back to products.price)
    public function get_size_price_for_active(string $product_id, string $size = ''): float
    {
        $con = $this->opencon();
        $size = strtolower(trim($size));
        // Only constrain by size if it's one of our supported values
        $constrainSize = in_array($size, ['grande', 'supreme'], true);
        $tbl = $this->getSizePriceTable($con);
        // Resolve the current products_pk for this product_id (latest row by created_at)
        $pkRow = $con->prepare("SELECT products_pk FROM products WHERE product_id = ? ORDER BY created_at DESC LIMIT 1");
        $pkRow->execute([$product_id]);
        $pPk = $pkRow->fetchColumn();
        if (!$pPk) { return 0.0; }
        if ($constrainSize) {
            $sql = "SELECT spp.price AS price FROM `{$tbl}` spp WHERE spp.products_pk = ? AND spp.size = ? AND spp.effective_to IS NULL LIMIT 1";
        } else {
            $sql = "SELECT COALESCE(
                        (SELECT price FROM `{$tbl}` s1 WHERE s1.products_pk = ? AND s1.size='grande' AND s1.effective_to IS NULL LIMIT 1),
                        (SELECT price FROM `{$tbl}` s2 WHERE s2.products_pk = ? AND s2.size='supreme' AND s2.effective_to IS NULL LIMIT 1)
                    ) AS price";
        }
        $stmt = $con->prepare($sql);
        if ($constrainSize) {
            $stmt->execute([$pPk, $size]);
        } else {
            $stmt->execute([$pPk, $pPk]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && isset($row['price']) ? (float)$row['price'] : 0.0;
    }

    // Return map of size prices for all active products: [product_id => ['grande'=>price, 'supreme'=>price]]
    public function get_all_size_prices_for_active(): array
    {
        $con = $this->opencon();
        $tbl = $this->getSizePriceTable($con);
        // Join via latest products_pk per product_id
        $sql = "SELECT p.product_id, spp.size, spp.price
                FROM products p
                JOIN (
                    SELECT product_id, MAX(created_at) AS max_created
                    FROM products
                    GROUP BY product_id
                ) latest ON latest.product_id = p.product_id AND latest.max_created = p.created_at
                JOIN `{$tbl}` spp ON spp.products_pk = p.products_pk AND spp.effective_to IS NULL
                WHERE p.status = 'active'";
        $stmt = $con->prepare($sql);
        $stmt->execute();
        $map = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = $r['product_id'];
            $sz  = strtolower($r['size']);
            $map[$pid][$sz] = (float)$r['price'];
        }
        return $map;
    }

    /** Get product data_type for latest version of a product_id. */
    public function get_product_data_type(string $product_id): ?string
    {
        $con = $this->opencon();
        $st = $con->prepare("SELECT data_type FROM products WHERE product_id = ? ORDER BY created_at DESC LIMIT 1");
        $st->execute([$product_id]);
        $dt = $st->fetchColumn();
        return $dt !== false ? strtolower((string)$dt) : null;
    }

    /** Get pastry variant price by label for the active/latest products_pk. */
    public function get_pastry_variant_price_for_active(string $product_id, string $label): float
    {
        $con = $this->opencon();
        $label = trim($label);
        if ($label === '') return 0.0;
        try {
            $pk = $con->prepare("SELECT products_pk FROM products WHERE product_id = ? ORDER BY created_at DESC LIMIT 1");
            $pk->execute([$product_id]);
            $pPk = $pk->fetchColumn();
            if (!$pPk) return 0.0;
            $st = $con->prepare("SELECT price
                                  FROM product_pastry_variants
                                 WHERE products_pk = ? AND label = ? AND effective_to IS NULL
                              ORDER BY effective_from DESC, variant_id DESC
                                 LIMIT 1");
            $st->execute([$pPk, $label]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row && isset($row['price']) ? (float)$row['price'] : 0.0;
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    // Fetch all locations (PDO)
    public function fetch_locations_pdo()
    {
        $con = $this->opencon();
        $stmt = $con->prepare("SELECT * FROM locations ORDER BY location_id DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------- Pastry Variants ----------
    /** Fetch variants for a given product_id (resolves latest products_pk). */
    public function fetch_pastry_variants(string $product_id): array
    {
        $con = $this->opencon();
        try {
            $pkStmt = $con->prepare("SELECT products_pk FROM products WHERE product_id = ? ORDER BY created_at DESC LIMIT 1");
            $pkStmt->execute([$product_id]);
            $pk = $pkStmt->fetchColumn();
            if (!$pk) return [];
            $st = $con->prepare("SELECT variant_id, label, price
                                   FROM product_pastry_variants
                                  WHERE products_pk = ? AND effective_to IS NULL
                               ORDER BY variant_id ASC");
            $st->execute([$pk]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Replace all variants for a pastry product by product_id. */
    public function save_pastry_variants(string $product_id, array $variants): bool
    {
        $con = $this->opencon();
        try {
            $con->beginTransaction();
            $pkStmt = $con->prepare("SELECT products_pk FROM products WHERE product_id = ? ORDER BY created_at DESC LIMIT 1");
            $pkStmt->execute([$product_id]);
            $pk = $pkStmt->fetchColumn();
            if (!$pk) { $con->rollBack(); return false; }
            // End-date existing active variants to preserve history
            $con->prepare("UPDATE product_pastry_variants SET effective_to = CURDATE() WHERE products_pk = ? AND effective_to IS NULL")
                ->execute([$pk]);
            // Insert new active set (effective_from = today)
            if (!empty($variants)) {
                $ins = $con->prepare("INSERT INTO product_pastry_variants (products_pk, label, price, effective_from, effective_to) VALUES (?, ?, ?, CURDATE(), NULL)");
                foreach ($variants as $v) {
                    $label = isset($v['label']) ? trim($v['label']) : '';
                    $price = isset($v['price']) ? (float)$v['price'] : 0.0;
                    if ($label === '' || $price < 0) continue;
                    $ins->execute([$pk, $label, number_format($price, 2, '.', '')]);
                }
            }
            $con->commit();
            return true;
        } catch (Throwable $e) {
            if ($con->inTransaction()) $con->rollBack();
            return false;
        }
    }

    /** Get all variants keyed by product_id for active pastries. */
    public function get_all_pastry_variants(): array
    {
        $con = $this->opencon();
        $map = [];
        try {
            $sql = "SELECT p.product_id, v.variant_id, v.label, v.price
                    FROM products p
                    JOIN (
                        SELECT product_id, MAX(created_at) AS latest
                        FROM products
                        GROUP BY product_id
                    ) latest ON latest.product_id = p.product_id AND latest.latest = p.created_at
                    LEFT JOIN product_pastry_variants v ON v.products_pk = p.products_pk AND v.effective_to IS NULL
                    WHERE p.status = 'active' AND p.data_type = 'pastries'
                    ORDER BY p.product_id, v.variant_id";
            $st = $con->query($sql);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $pid = $row['product_id'];
                if (!isset($map[$pid])) $map[$pid] = [];
                if ($row['variant_id'] !== null) {
                    $map[$pid][] = [
                        'variant_id' => (int)$row['variant_id'],
                        'label' => $row['label'],
                        'price' => (float)$row['price']
                    ];
                }
            }
        } catch (Throwable $e) { /* ignore */ }
        return $map;
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
            $itemStmt = $con->prepare("SELECT ti.quantity, ti.size, ti.price,
                        COALESCE(p.name, p2.name) AS name
                    FROM transaction_items ti
                    LEFT JOIN products p
                        ON ti.products_pk IS NOT NULL AND p.products_pk = ti.products_pk
                    LEFT JOIN products p2
                        ON ti.products_pk IS NULL AND p2.product_id = ti.product_id
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
            $itemStmt = $con->prepare("SELECT ti.quantity, ti.size, ti.price,
                        COALESCE(p.name, p2.name) AS name
                    FROM transaction_items ti
                    LEFT JOIN products p
                        ON ti.products_pk IS NOT NULL AND p.products_pk = ti.products_pk
                    LEFT JOIN products p2
                        ON ti.products_pk IS NULL AND p2.product_id = ti.product_id AND p2.effective_to IS NULL
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
        $tbl = $this->getSizePriceTable($con);
        $sql = "SELECT 
                    p.product_id,
                    p.name,
                    p.category_id,
                    p.data_type,
                    COALESCE(
                        (SELECT spp.price FROM `{$tbl}` spp WHERE spp.products_pk = p.products_pk AND spp.size='grande' AND spp.effective_to IS NULL LIMIT 1),
                        (SELECT spp.price FROM `{$tbl}` spp WHERE spp.products_pk = p.products_pk AND spp.size='supreme' AND spp.effective_to IS NULL LIMIT 1),
                        0
                    ) AS price,
                    p.status,
                    p.created_at,
                    COALESCE(SUM(ti.quantity), 0) AS sales
                FROM products p
                LEFT JOIN transaction_items ti ON ti.product_id = p.product_id
                WHERE p.name != '__placeholder__'
                GROUP BY p.product_id";
        $stmt = $con->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetch_locations()
    {
        $con = $this->opencon();
        $stmt = $con->prepare("SELECT * FROM locations ORDER BY location_id DESC");
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

    // Fetch user orders with pagination; returns array and sets $total via reference
    public function fetchUserOrdersPaginated(int $user_id, int $page, int $perPage, int &$total = 0): array
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(100, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $con = $this->opencon();
        // Total count
        $stmt = $con->prepare("SELECT COUNT(*) FROM transaction t WHERE t.user_id = ?");
        $stmt->execute([$user_id]);
        $total = (int)$stmt->fetchColumn();

        // Page data
        // Important: LIMIT/OFFSET must be integers; we inject them after clamping
        $sql = "SELECT t.transac_id, t.reference_number, t.created_at, t.total_amount, t.status
                FROM transaction t
                WHERE t.user_id = ?
                ORDER BY t.created_at DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $st2 = $con->prepare($sql);
        $st2->execute([$user_id]);
        return $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        $itemStmt = $con->prepare("SELECT ti.quantity, ti.size, ti.price,
                COALESCE(
                    p.name,
                    (SELECT p2.name FROM products p2 WHERE p2.product_id = ti.product_id ORDER BY p2.created_at DESC LIMIT 1)
                ) AS name
            FROM transaction_items ti
            LEFT JOIN products p ON p.products_pk = ti.products_pk
            WHERE ti.transaction_id = ?");
        $itemStmt->execute([$transac_id]);
        $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        return $order;
    }


public function createPickupOrder(
    $user_id,
    array $cart_items,
    string $pickup_name,
    string $pickup_location,
    string $pickup_time,
    string $special_instructions = '',
    string $payment_method = 'cash'
) {
    $con = $this->opencon();
    $con->beginTransaction();

    try {
        // Blocked user guard
        if ($user_id && $this->isUserBlocked((int)$user_id)) {
            throw new Exception("Your account is blocked from placing orders. Please contact support.");
        }
        if (!$pickup_name || !$pickup_location || !$pickup_time) {
            throw new Exception("Missing required pickup details.");
        }
        if (empty($cart_items)) {
            throw new Exception("Cart is empty.");
        }

        // Normalize pickup time (assume HH:MM from form)
        $pickup_datetime = date('Y-m-d') . ' ' . preg_replace('/[^0-9:]/', '', $pickup_time) . ':00';

        // Calculate total (pastries use variant price; drinks use size-based price)
        $total_amount = 0.0;
        $dtypeCache = [];
        // Pre-collect desired quantities for stock validation
        $productPkCache = [];
        $needed = [];
        foreach ($cart_items as $item) {
            $qty = max(1, (int)($item['quantity'] ?? 1));
            // Enforce server-side base price from DB by product + size
            $pid  = $item['product_id'] ?? '';
            $sz   = strtolower($item['size'] ?? '');
            $base = 0.0;
            if ($pid) {
                if (!isset($dtypeCache[$pid])) {
                    $dtypeCache[$pid] = $this->get_product_data_type($pid) ?: '';
                }
                if ($dtypeCache[$pid] === 'pastries') {
                    // here $sz carries the variant label from the cart
                    $base = $this->get_pastry_variant_price_for_active($pid, $sz);
                } else {
                    $base = $this->get_size_price_for_active($pid, $sz);
                }
            } else {
                $base = (float)($item['basePrice'] ?? $item['price'] ?? 0);
            }
            $tSum = 0.0;
            if (!empty($item['toppings']) && is_array($item['toppings'])) {
                foreach ($item['toppings'] as $t) {
                    $t_price = (float)($t['price'] ?? 0);
                    $t_qty   = max(1, (int)($t['quantity'] ?? 1));
                    $tSum += $t_price * $t_qty;
                }
            }
            $total_amount += ($base + $tSum) * $qty;

            if ($pid) {
                if (!isset($productPkCache[$pid])) {
                    $findActiveProductPkTmp = $con->prepare("SELECT products_pk FROM products WHERE product_id = ? ORDER BY created_at DESC LIMIT 1");
                    $findActiveProductPkTmp->execute([$pid]);
                    $rowTmp = $findActiveProductPkTmp->fetch(PDO::FETCH_ASSOC);
                    $productPkCache[$pid] = $rowTmp['products_pk'] ?? null;
                }
                $pkTmp = $productPkCache[$pid];
                if ($pkTmp) {
                    if (!isset($needed[$pkTmp])) $needed[$pkTmp] = 0;
                    $needed[$pkTmp] += $qty;
                }
            }
        }

        // Validate aggregated stock before creating transaction
        if (!empty($needed)) {
            $in = implode(',', array_fill(0, count($needed), '?'));
            $stq = $con->prepare("SELECT products_pk, quantity, product_id FROM products WHERE products_pk IN ($in)");
            $stq->execute(array_keys($needed));
            $issues = [];
            while ($r = $stq->fetch(PDO::FETCH_ASSOC)) {
                $pk = (int)$r['products_pk'];
                $want = $needed[$pk] ?? 0;
                $cur = $r['quantity'];
                if ($cur !== null) { // null means unlimited
                    $curInt = (int)$cur;
                    if ($curInt <= 0) {
                        $issues[] = $r['product_id'] . ' out of stock';
                    } elseif ($want > $curInt) {
                        $issues[] = $r['product_id'] . ' needs ' . $want . ' but only ' . $curInt . ' left';
                    }
                }
            }
            if (!empty($issues)) {
                throw new Exception('Insufficient stock: ' . implode('; ', $issues));
            }
        }

        // Insert transaction
        $stmt = $con->prepare("
            INSERT INTO transaction (user_id, total_amount, status, payment_method, created_at) 
            VALUES (?, ?, 'pending', ?, NOW())
        ");
        $stmt->execute([$user_id ?: null, $total_amount, $payment_method]);
        $transaction_id = (int)$con->lastInsertId();

        $reference_number = 'CNC-' . date('Ymd') . '-' . str_pad($transaction_id, 4, '0', STR_PAD_LEFT);
        $con->prepare("UPDATE transaction SET reference_number = ? WHERE transac_id = ?")
            ->execute([$reference_number, $transaction_id]);

        // Insert items (include products_pk for version-accurate linkage)
        $insertItem = $con->prepare("
            INSERT INTO transaction_items (transaction_id, product_id, products_pk, quantity, size, sugar_level, price)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        // Insert toppings (no transaction_id here)
        $insertTopping = $con->prepare("
            INSERT INTO transaction_toppings (transaction_item_id, topping_id, quantity, unit_price, sugar_level)
            VALUES (?, ?, ?, ?, ?)
        ");

    $findTopping = $con->prepare("SELECT topping_id FROM toppings WHERE topping_id = ? LIMIT 1");
    $findActiveProductPk = $con->prepare("SELECT products_pk FROM products WHERE product_id = ? ORDER BY created_at DESC LIMIT 1");
    // Prepared statement to decrement stock and auto-inactivate when quantity reaches 0
    $decrementStock = $con->prepare("UPDATE products
        SET quantity = CASE WHEN quantity IS NULL THEN NULL ELSE GREATEST(quantity - ?, 0) END,
            status = CASE WHEN quantity IS NOT NULL AND (quantity - ?) <= 0 THEN 0 ELSE status END,
            updated_at = NOW()
        WHERE products_pk = ?");
    // Fallback decrement by product_id (if products_pk not resolved)
    $decrementStockByPid = $con->prepare("UPDATE products
        SET quantity = CASE WHEN quantity IS NULL THEN NULL ELSE GREATEST(quantity - ?, 0) END,
            status = CASE WHEN quantity IS NOT NULL AND (quantity - ?) <= 0 THEN 0 ELSE status END,
            updated_at = NOW()
        WHERE product_id = ?");

        foreach ($cart_items as $item) {
            $product_id = $item['product_id'] ?? null;
            if (!$product_id) {
                throw new Exception("Missing product_id in cart item.");
            }

            $quantity   = max(1, (int)($item['quantity'] ?? 1));
            $size       = $item['size'] ?? '';
            $item_sugar = isset($item['sugar']) && $item['sugar'] !== '' ? trim($item['sugar']) : null;

            // Calculate per item price (base + toppings)
            // Re-fetch authoritative base price from DB using size/label
            $basePrice = 0.0;
            if (!isset($dtypeCache[$product_id])) {
                $dtypeCache[$product_id] = $this->get_product_data_type($product_id) ?: '';
            }
            if ($dtypeCache[$product_id] === 'pastries') {
                $basePrice = $this->get_pastry_variant_price_for_active($product_id, $size);
            } else {
                $basePrice = $this->get_size_price_for_active($product_id, $size);
            }
            $tSum = 0.0;
            if (!empty($item['toppings']) && is_array($item['toppings'])) {
                foreach ($item['toppings'] as $t) {
                    $t_price = (float)($t['price'] ?? 0);
                    $t_qty   = max(1, (int)($t['quantity'] ?? 1));
                    $tSum += $t_price * $t_qty;
                }
            }
            $linePrice = $basePrice + $tSum;

            // Resolve active products_pk for this product_id
            $products_pk_val = null;
            $findActiveProductPk->execute([$product_id]);
            $pkRow = $findActiveProductPk->fetch(PDO::FETCH_ASSOC);
            if ($pkRow && isset($pkRow['products_pk'])) {
                $products_pk_val = (int)$pkRow['products_pk'];
            }

            // Per-item validation (in case stock changed between aggregate check and insert)
            if ($products_pk_val) {
                $chk = $con->prepare("SELECT quantity, product_id FROM products WHERE products_pk = ? LIMIT 1");
                $chk->execute([$products_pk_val]);
                $rw = $chk->fetch(PDO::FETCH_ASSOC);
                if ($rw && $rw['quantity'] !== null) {
                    $curQty = (int)$rw['quantity'];
                    if ($curQty <= 0) {
                        throw new Exception('Product ' . $rw['product_id'] . ' is out of stock');
                    }
                    if ($quantity > $curQty) {
                        throw new Exception('Product ' . $rw['product_id'] . ' needs ' . $quantity . ' but only ' . $curQty . ' left');
                    }
                }
            } else { // Fallback validation by product_id
                $chk2 = $con->prepare("SELECT quantity, product_id FROM products WHERE product_id = ? ORDER BY created_at DESC LIMIT 1");
                $chk2->execute([$product_id]);
                $rw2 = $chk2->fetch(PDO::FETCH_ASSOC);
                if ($rw2 && $rw2['quantity'] !== null) {
                    $curQty = (int)$rw2['quantity'];
                    if ($curQty <= 0) {
                        throw new Exception('Product ' . $rw2['product_id'] . ' is out of stock');
                    }
                    if ($quantity > $curQty) {
                        throw new Exception('Product ' . $rw2['product_id'] . ' needs ' . $quantity . ' but only ' . $curQty . ' left');
                    }
                }
            }

            // Save item with products_pk
            $insertItem->execute([
                $transaction_id,
                $product_id,
                $products_pk_val,
                $quantity,
                $size,
                $item_sugar,
                $linePrice
            ]);
            $transaction_item_id = (int)$con->lastInsertId();

            // Save toppings if exist
            if (!empty($item['toppings']) && is_array($item['toppings'])) {
                foreach ($item['toppings'] as $t) {
                    $topping_id = $t['topping_id'] ?? null;
                    if (!$topping_id || !ctype_digit((string)$topping_id)) {
                        continue;
                    }
                    $findTopping->execute([$topping_id]);
                    if (!$findTopping->fetch(PDO::FETCH_ASSOC)) {
                        continue;
                    }
                    $t_qty   = max(1, (int)($t['quantity'] ?? 1));
                    $t_price = (float)($t['price'] ?? 0);
                    $insertTopping->execute([
                        $transaction_item_id,
                        $topping_id,
                        $t_qty,
                        $t_price,
                        $item_sugar
                    ]);
                }
            }

            // Decrement stock if the product has a finite quantity
            if ($products_pk_val) {
                try {
                    $decrementStock->execute([$quantity, $quantity, $products_pk_val]);
                    if ($decrementStock->rowCount() === 0) {
                        // fallback attempt
                        $decrementStockByPid->execute([$quantity, $quantity, $product_id]);
                    }
                } catch (Exception $e) {
                    // Log but do not abort the whole order if stock update fails
                    error_log('Stock decrement failed for products_pk ' . $products_pk_val . ': ' . $e->getMessage());
                }
            } else {
                try {
                    $decrementStockByPid->execute([$quantity, $quantity, $product_id]);
                } catch (Exception $e) {
                    error_log('Stock decrement (by product_id) failed for ' . $product_id . ': ' . $e->getMessage());
                }
            }
        }

        // Insert pickup_detail
        $con->prepare("
            INSERT INTO pickup_detail (transaction_id, pickup_name, pickup_location, pickup_time, special_instructions)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            $transaction_id,
            $pickup_name,
            $pickup_location,
            $pickup_datetime,
            $special_instructions
        ]);

        $con->commit();

        $this->sendDirectFcm(
            "New Order",
            "Reference {$reference_number}",
            ['reference' => $reference_number, 'click_action' => 'https://cupsandcuddles.online/admin/admin.php']
        );

        return [
            'success' => true,
            'reference_number' => $reference_number
        ];
    } catch (Exception $e) {
        if ($con->inTransaction()) {
            $con->rollBack();
        }
        error_log("createPickupOrder error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}



    public function createTransaction($user_id, $items, $total, $method, $pickupInfo = null, $deliveryInfo = null)
    {
        $con = $this->opencon();
        $con->beginTransaction();
        try {
            // Blocked user guard
            if ($user_id && $this->isUserBlocked((int)$user_id)) {
                throw new Exception("Your account is blocked from placing orders. Please contact support.");
            }
            // Insert base transaction
            $stmt = $con->prepare("INSERT INTO transaction (user_id, total_amount, status, created_at) VALUES (?, ?, 'pending', NOW())");
            $stmt->execute([$user_id, $total]);
            $transaction_id = $con->lastInsertId();

            // prepared statements for items and toppings (match schema including sugar_level)
            $itemInsert = $con->prepare("INSERT INTO transaction_items (transaction_id, product_id, products_pk, quantity, size, price) VALUES (?, ?, ?, ?, ?, ?)");
            // transaction_toppings no longer stores product_id or transaction_id
            $toppingInsert = $con->prepare("INSERT INTO transaction_toppings (transaction_item_id, topping_id, quantity, unit_price, sugar_level) VALUES (?, ?, ?, ?, ?)");

            // helper: find topping by name (if not numeric id), and insert if missing
            $findTopping = $con->prepare("SELECT topping_id FROM toppings WHERE name = ? LIMIT 1");
            $insertTopping = $con->prepare("INSERT INTO toppings (name, price, created_at) VALUES (?, ?, NOW())");

            // Insert items and their toppings (if any)
            $findActiveProductPk = $con->prepare("SELECT products_pk FROM products WHERE product_id = ? ORDER BY created_at DESC LIMIT 1");
            // Prepared statement to decrement stock and auto-inactivate when quantity reaches 0
            $decrementStock = $con->prepare("UPDATE products
                SET quantity = CASE WHEN quantity IS NULL THEN NULL ELSE GREATEST(quantity - ?, 0) END,
                    status = CASE WHEN quantity IS NOT NULL AND (quantity - ?) <= 0 THEN 0 ELSE status END,
                    updated_at = NOW()
                WHERE products_pk = ?");
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
                // Authoritative base price from DB for the given size/label
                $base_from_db = 0.00;
                if ($product_id) {
                    $ptype = $this->get_product_data_type($product_id) ?: '';
                    if ($ptype === 'pastries') {
                        $base_from_db = $this->get_pastry_variant_price_for_active($product_id, $size);
                    } else {
                        $base_from_db = $this->get_size_price_for_active($product_id, $size);
                    }
                }
                $price_to_store = $base_from_db + $computed_toppings_sum;

                // Resolve active products_pk
                $products_pk_val = null;
                if ($product_id) {
                    $findActiveProductPk->execute([$product_id]);
                    $pkRow = $findActiveProductPk->fetch(PDO::FETCH_ASSOC);
                    if ($pkRow && isset($pkRow['products_pk'])) {
                        $products_pk_val = (int)$pkRow['products_pk'];
                    }
                }

                $itemInsert->execute([$transaction_id, $product_id, $products_pk_val, $quantity, $size, $price_to_store]);
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

                        if (isset($topping['topping_id']) && is_numeric($topping['topping_id']) && intval($topping['topping_id']) > 0) {
                            $topping_id = intval($topping['topping_id']);
                        } elseif ($t_name !== '') {
                            $findTopping->execute([$t_name]);
                            $found = $findTopping->fetch(PDO::FETCH_ASSOC);
                            if ($found && isset($found['topping_id'])) {
                                $topping_id = intval($found['topping_id']);
                            } else {
                                $insertTopping->execute([$t_name, $t_price]);
                                $topping_id = $con->lastInsertId();
                            }
                        } else {
                            throw new Exception("Invalid topping data for product_id {$product_id}");
                        }

                        // insert topping record including sugar_level
                        $toppingInsert->execute([$transaction_item_id, $topping_id, $t_qty, $t_price, $item_sugar]);
                    }
                }

                // Decrement stock if the product has a finite quantity
                if ($products_pk_val) {
                    try {
                        $decrementStock->execute([$quantity, $quantity, $products_pk_val]);
                    } catch (Exception $e) {
                        error_log('Stock decrement failed for products_pk ' . $products_pk_val . ': ' . $e->getMessage());
                    }
                }
            }

            // Insert pickup details if provided
            if ($method === 'pickup' && $pickupInfo) {
                $special = isset($pickupInfo['special']) ? $pickupInfo['special'] : '';
                $pickup_location = isset($pickupInfo['name']) ? ($pickupInfo['name'] . (isset($pickupInfo['phone']) ? " ({$pickupInfo['phone']})" : "")) : '';
                $con->prepare("INSERT INTO pickup_detail (transaction_id, pickup_location, pickup_time, special_instructions) VALUES (?, ?, ?, ?)")
                    ->execute([$transaction_id, $pickup_location, $pickupInfo['time'], $special]);
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
        $sql = "SELECT topping_id, name, price, CASE WHEN COALESCE(status,1)=1 THEN 'active' ELSE 'inactive' END AS status, created_at, updated_at FROM toppings ORDER BY topping_id DESC";
        $stmt = $con->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetch_active_toppings()
    {
        $con = $this->opencon();
        $sql = "SELECT topping_id, name, price FROM toppings WHERE COALESCE(status,1) = 1 ORDER BY topping_id ASC";
        $stmt = $con->prepare($sql);
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
        $stmt = $con->prepare("UPDATE toppings SET name = ?, price = ?, updated_at = NOW() WHERE topping_id = ?");
        return $stmt->execute([trim($name), number_format(floatval($price), 2, '.', ''), intval($id)]);
    }

    public function update_topping_status($id, $status)
    {
        $con = $this->opencon();
        $st = ($status === 'active') ? 1 : 0;
        $stmt = $con->prepare("UPDATE toppings SET status = ?, updated_at = NOW() WHERE topping_id = ?");
        return $stmt->execute([$st, intval($id)]);
    }

    /** Replace allowed data_types for a topping (empty array means globally available). */
    public function set_topping_allowed_types(int $topping_id, array $types): bool
    {
        $con = $this->opencon();
        try {
            $con->beginTransaction();
            $con->prepare("DELETE FROM topping_allowed_types WHERE topping_id = ?")->execute([$topping_id]);
            if (!empty($types)) {
                $ins = $con->prepare("INSERT INTO topping_allowed_types (topping_id, data_type) VALUES (?, ?)");
                foreach ($types as $t) {
                    $t = strtolower(trim($t));
                    if ($t === '') continue;
                    $ins->execute([$topping_id, $t]);
                }
            }
            $con->commit();
            return true;
        } catch (Throwable $e) {
            if ($con->inTransaction()) $con->rollBack();
            return false;
        }
    }

    /** Replace allowed categories for a topping (empty array means globally available). */
    public function set_topping_allowed_categories(int $topping_id, array $categoryIds): bool
    {
        $con = $this->opencon();
        try {
            $con->beginTransaction();
            $con->prepare("DELETE FROM topping_allowed_categories WHERE topping_id = ?")->execute([$topping_id]);
            if (!empty($categoryIds)) {
                $ins = $con->prepare("INSERT INTO topping_allowed_categories (topping_id, category_id) VALUES (?, ?)");
                foreach ($categoryIds as $cid) {
                    $cid = (int)$cid;
                    if ($cid <= 0) continue;
                    $ins->execute([$topping_id, $cid]);
                }
            }
            $con->commit();
            return true;
        } catch (Throwable $e) {
            if ($con->inTransaction()) $con->rollBack();
            return false;
        }
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
                JOIN products p ON ti.product_id = p.product_id
                JOIN transaction t ON ti.transaction_id = t.transac_id
    WHERE p.status = 'active' AND t.status != 'cancelled'
                  AND p.name != '__placeholder__'
                  AND (LOWER(p.category_id) LIKE ? OR LOWER(p.name) LIKE ? OR LOWER(p.product_id) LIKE ?)
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
                $sql = "SELECT product_id, name, image, description, 0 as sales_count
        FROM products
                                WHERE status = 'active' AND name != '__placeholder__'
                                    AND (LOWER(category_id) LIKE ? OR LOWER(name) LIKE ? OR LOWER(product_id) LIKE ?)
                                ORDER BY product_id DESC
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
                JOIN products p ON ti.product_id = p.product_id
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



    private function getFcmAccessToken(): ?string
    {
        static $cached = null, $exp = 0;
        if ($cached && time() < $exp - 60) return $cached;

        $saPath = '/home/u778762049/domains/cupsandcuddles.online/secure/service_account.json';
        if (!is_file($saPath)) {
            error_log('FCM: service account missing');
            return null;
        }
        $sa = json_decode(file_get_contents($saPath), true);
        if (empty($sa['client_email']) || empty($sa['private_key'])) {
            error_log('FCM: invalid service account JSON');
            return null;
        }

        $now = time();
        $b64 = fn($d) => rtrim(strtr(base64_encode(is_string($d) ? $d : json_encode($d)), '+/', '-_'), '=');
        $header = $b64(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = $b64([
            'iss'   => $sa['client_email'],
            'sub'   => $sa['client_email'],
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging'
        ]);

        if (!openssl_sign("$header.$claims", $sig, $sa['private_key'], 'sha256WithRSAEncryption')) {
            error_log('FCM: openssl_sign failed');
            return null;
        }
        $jwt = "$header.$claims." . $b64($sig);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code !== 200) {
            error_log("FCM: token fetch failed http=$code err=$err body=$resp");
            return null;
        }
        $json = json_decode($resp, true);
        if (empty($json['access_token'])) {
            error_log('FCM: token missing in response');
            return null;
        }
        $cached = $json['access_token'];
        $exp    = $now + (int)($json['expires_in'] ?? 3600);
        return $cached;
    }

    private function sendDirectFcm(string $title, string $body, array $data = []): void
    {
        $tokens = $this->getAllAdminFcmTokens();
        if (!$tokens) {
            error_log('FCM: no tokens to send');
            return;
        }
        $access = $this->getFcmAccessToken();
        if (!$access) {
            error_log('FCM: cannot send (no access token)');
            return;
        }

        $merged = array_merge([
            'title'        => $title,
            'body'         => $body,
            'click_action' => '/admin/',
            'icon'         => '/img/kape.png',
            'image'        => '/img/logo.png',
            'sent_at'      => (string)time()
        ], $data);

        $endpoint = "https://fcm.googleapis.com/v1/projects/coffeeshop-8ce2a/messages:send";

        foreach ($tokens as $t) {
            $payload = [
                'message' => [
                    'token' => $t,
                    'data'  => $merged
                ]
            ];

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer $access",
                    "Content-Type: application/json"
                ],
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($err || $code >= 300) {
                error_log("FCM: send fail http=$code err=$err prefix=" . substr($t, 0, 18) . " resp=$resp");
            } else {
                error_log("FCM: sent http=200 prefix=" . substr($t, 0, 18));
            }
        }
    }


    public function pushAdminNotification(string $title, string $body, ?string $reference = null, array $data = []): bool
    {
        // Merge reference into data payload
        if ($reference) {
            $data['reference'] = $reference;
        }
        if (!isset($data['click_action'])) {
            $data['click_action'] = 'https://cupsandcuddles.online/admin/admin.php';
        }

        // Prefer Kreait if installed
        if (class_exists(\Kreait\Firebase\Factory::class)) {
            try {
                $saPath = getenv('FCM_SERVICE_ACCOUNT');
                if (!$saPath || !is_file($saPath)) {
                    $fallback = __DIR__ . '/../config/firebase-service-account.json';
                    if (is_file($fallback)) {
                        $saPath = $fallback;
                    }
                }
                if ($saPath && is_file($saPath)) {
                    $factory = (new \Kreait\Firebase\Factory())->withServiceAccount($saPath);
                    $messaging = $factory->createMessaging();
                    $tokens = $this->getAllAdminFcmTokens();
                    if (!$tokens) {
                        return false;
                    }
                    $notification = \Kreait\Firebase\Messaging\Notification::create($title, $body);
                    $messages = [];
                    foreach ($tokens as $t) {
                        $messages[] = \Kreait\Firebase\Messaging\CloudMessage::withTarget('token', $t)
                            ->withNotification($notification)
                            ->withData($data);
                    }
                    $messaging->sendAll($messages);
                    return true;
                }
            } catch (\Throwable $e) {
                error_log('Kreait FCM fallback to raw: ' . $e->getMessage());
            }
        }

        // Fallback to internal direct FCM v1 sender
        $this->sendDirectFcm($title, $body, $data);
        return true;
    }


    public function fetch_recent_products_by_data_type($data_type, $limit = 3)
    {
        $con = $this->opencon();
        $limit = intval($limit) > 0 ? intval($limit) : 3;
    $sql = "SELECT product_id, name, image, description, data_type, 0 as sales_count
        FROM products
        WHERE status = 'active' AND LOWER(data_type) = ? AND name != '__placeholder__' AND effective_to IS NULL
        ORDER BY product_id DESC
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

    // Option C: store multiple device tokens as JSON array inside fcm_token (TEXT) column
    public function saveAdminFcmToken(int $admin_id, string $token): bool
    {
        $pdo = $this->opencon();
        $token = trim($token);
        if ($token === '') return false;
        // Fetch existing raw value
        $stmt = $pdo->prepare("SELECT fcm_token FROM admin_users WHERE admin_id = ? LIMIT 1");
        $stmt->execute([$admin_id]);
        $raw = $stmt->fetch(PDO::FETCH_COLUMN);
        $list = [];
        if ($raw) {
            if ($raw[0] === '[') { // JSON array
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $list = $decoded;
            } else {
                // Legacy single token value; promote to list
                $list = [$raw];
            }
        }
        // Prepend new token if not already present
        if (!in_array($token, $list, true)) {
            array_unshift($list, $token);
        } else {
            // Move existing token to front (most recent)
            $list = array_values(array_filter($list, fn($t) => $t !== $token));
            array_unshift($list, $token);
        }
        // Cap list size to avoid unbounded growth (keep most recent 15)
        if (count($list) > 15) $list = array_slice($list, 0, 15);
        $json = json_encode($list, JSON_UNESCAPED_SLASHES);
        $upd = $pdo->prepare("UPDATE admin_users SET fcm_token = ?, fcm_token_updated_at = NOW() WHERE admin_id = ?");
        return $upd->execute([$json, $admin_id]);
    }

    public function getAdminFcmTokens(int $admin_id): array
    {
        $pdo = $this->opencon();
        $stmt = $pdo->prepare("SELECT fcm_token FROM admin_users WHERE admin_id = ? LIMIT 1");
        $stmt->execute([$admin_id]);
        $raw = $stmt->fetch(PDO::FETCH_COLUMN);
        if (!$raw) return [];
        if ($raw[0] === '[') {
            $arr = json_decode($raw, true);
            return is_array($arr) ? $arr : [];
        }
        return [$raw]; // legacy single value
    }

    public function pruneInvalidAdminToken(int $admin_id, string $token): void
    {
        $pdo = $this->opencon();
        $stmt = $pdo->prepare("SELECT fcm_token FROM admin_users WHERE admin_id = ? LIMIT 1");
        $stmt->execute([$admin_id]);
        $raw = $stmt->fetch(PDO::FETCH_COLUMN);
        if (!$raw) return;
        $token = trim($token);
        if ($token === '') return;
        $list = [];
        if ($raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $list = $decoded;
            else return;
            $new = array_values(array_filter($list, fn($t) => $t !== $token));
            if (count($new) === count($list)) return; // nothing removed
            $json = $new ? json_encode($new, JSON_UNESCAPED_SLASHES) : null;
            $upd = $pdo->prepare("UPDATE admin_users SET fcm_token = ?, fcm_token_updated_at = NOW() WHERE admin_id = ?");
            $upd->execute([$json, $admin_id]);
        } else {
            // Single token case
            if ($raw === $token) {
                $clr = $pdo->prepare("UPDATE admin_users SET fcm_token = NULL WHERE admin_id = ?");
                $clr->execute([$admin_id]);
            }
        }
    }


    public function sendOrderNotification(string $ref): void
    {
        $this->sendDirectFcm(
            'New Order',
            "Ref $ref",
            ['reference' => $ref]
        );
    }
}
