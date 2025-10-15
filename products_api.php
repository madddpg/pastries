<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

class Database {
    private $host = "mysql.hostinger.com";
    private $user = "u778762049_cupsandcuddles";
    private $password = "CupS@1234";
    private $db = "u778762049_ordering";
    private $conn;

    public function opencon(): PDO {
        if ($this->conn === null) {
            $dsn = "mysql:host={$this->host};dbname={$this->db};charset=utf8mb4";
            $this->conn = new PDO($dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return $this->conn;
    }
}

try {
    // 1) Connect to database using your Database class
    $db  = new Database();
    $pdo = $db->opencon();

    // 2) Read and validate inputs
    $allowedTypes = ['hot', 'cold', 'pastries'];
    $type   = isset($_GET['data_type']) ? strtolower(trim((string)$_GET['data_type'])) : null;
    if ($type !== null && $type !== '' && !in_array($type, $allowedTypes, true)) {
        respond(['success' => false, 'message' => 'Invalid data_type. Use hot, cold, or pastries.'], 400);
    }

    $status = isset($_GET['status']) ? trim((string)$_GET['status']) : 'active';
    $q      = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = max(1, min(100, (int)($_GET['per_page'] ?? 50)));
    $offset   = ($page - 1) * $perPage;

    $orderBy  = 'created_at';
    $orderDir = 'DESC';
    if (isset($_GET['order_by'])) {
        $ob = strtolower((string)$_GET['order_by']);
        if (in_array($ob, ['created_at', 'name'], true)) {
            $orderBy = $ob;
        }
    }
    if (isset($_GET['order_dir']) && strtolower((string)$_GET['order_dir']) === 'asc') {
        $orderDir = 'ASC';
    }

    // 3) Build SQL with filters
    $sql    = "SELECT products_pk, product_id, name, data_type, description, image, created_at, status, category_id
               FROM products WHERE 1=1";
    $params = [];

    if ($type !== null && $type !== '') {
        $sql .= " AND data_type = :type";
        $params[':type'] = $type;
    }
    if ($status !== '') {
        $sql .= " AND status = :status";
        $params[':status'] = $status;
    }
    if ($q !== '') {
        $sql .= " AND (name LIKE :q OR description LIKE :q)";
        $params[':q'] = "%{$q}%";
    }

    // 4) Count total
    $sqlCount = "SELECT COUNT(*) FROM ({$sql}) t";
    $stc = $pdo->prepare($sqlCount);
    foreach ($params as $k => $v) {
        $stc->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stc->execute();
    $total = (int) $stc->fetchColumn();

    // 5) Fetch page results
    $sql .= " ORDER BY {$orderBy} {$orderDir} LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // 6) Normalize image URLs to absolute paths
    $base = 'https://cupsandcuddles.online';
    foreach ($rows as &$r) {
        if (!empty($r['image']) && !preg_match('#^https?://#i', $r['image'])) {
            $img = ltrim((string)$r['image'], '/');
            if (str_starts_with($img, 'img/')) {
                $r['image'] = "{$base}/{$img}";
            } elseif (str_starts_with($img, '/img/')) {
                $r['image'] = "{$base}{$img}";
            } else {
                $r['image'] = "{$base}/img/" . rawurlencode($img);
            }
        }
    }
    unset($r);

    // 7) Respond
    respond([
        'success'   => true,
        'page'      => $page,
        'per_page'  => $perPage,
        'total'     => $total,
        'products'  => $rows,
    ]);
} catch (Throwable $e) {
    respond(['success' => false, 'message' => $e->getMessage()], 500);
}
