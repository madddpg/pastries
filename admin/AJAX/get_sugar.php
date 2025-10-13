<?php
header('Content-Type: application/json');

// Return sugar options that match your DB enum values.
$sugarOptions = [
    ['key' => 'less sugar', 'label' => 'Less sugar'],
    ['key' => 'regular', 'label' => 'Regular'],
    ['key' => 'more sweet', 'label' => 'More Sweet']
];

echo json_encode([
    'success' => true,
    'options' => $sugarOptions
], JSON_UNESCAPED_UNICODE);

exit;
?>