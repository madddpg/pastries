<?php
header('Content-Type: application/json');

// Example menu data
$menu = [
    ['name' => 'Signature Coffee', 'category' => 'premium'],
    ['name' => 'Specialty Coffee', 'category' => 'specialty'],
    ['name' => 'Milk Based', 'category' => 'milk'],
    ['name' => 'Chocolate Overload', 'category' => 'chocolate'],
    ['name' => 'Matcha Series', 'category' => 'matcha'],
    ['name' => 'All Time Fav', 'category' => 'alltime'],
    ['name' => 'Croissant', 'category' => 'pastries'],
    // ... more items ...
];

$type = $_GET['type'] ?? '';

if ($type === 'hot') {
    $filtered = array_filter($menu, fn($item) => $item['category'] === 'premium');
} elseif ($type === 'cold') {
    $filtered = array_filter($menu, fn($item) =>
        in_array($item['category'], ['premium', 'specialty', 'milk', 'chocolate', 'matcha', 'alltime'])
    );
} elseif ($type === 'pastries') {
    $filtered = array_filter($menu, fn($item) => $item['category'] === 'pastries');
} else {
    $filtered = [];
}

echo json_encode(array_values($filtered));