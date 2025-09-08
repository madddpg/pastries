<?php
require_once __DIR__ . '/database/db_connect.php';
$db = new Database();
$con = $db->opencon();
$stmt = $con->query("SELECT id,title,image,active,created_at FROM promos ORDER BY id DESC LIMIT 20");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: text/plain; charset=utf-8');
foreach ($rows as $r) {
    echo "ID: {$r['id']}\nTitle: {$r['title']}\nImage: {$r['image']}\nActive: {$r['active']}\nPath exists: " . (file_exists(__DIR__ . '/../' . ltrim($r['image'],'/')) ? 'yes' : 'no') . "\n---\n";
}
?>